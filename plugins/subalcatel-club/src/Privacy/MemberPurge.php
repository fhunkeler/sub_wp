<?php

declare(strict_types=1);

namespace Subalcatel\Club\Privacy;

use Subalcatel\Club\Communication\CustomGroups;
use Subalcatel\Club\Communication\Subscriptions;
use Subalcatel\Club\Database\Schema;
use Subalcatel\Club\Documents\DocumentStorage;
use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Support\Audit;

/**
 * Ce qu'un compte laisse derrière lui.
 *
 * Deux chemins mènent au départ d'un membre, et ils ne peuvent pas diverger.
 * Le premier est la demande d'effacement ([PersonalData::erase]) : le compte
 * reste, on le vide. Le second est *Comptes → Supprimer*, l'écran natif de
 * WordPress — le geste le plus naturel pour le bureau, et le seul qui ne
 * connaissait aucune table du plugin. Il laissait des inscriptions sans
 * inscrit, des documents dont le fichier survivait sur le stockage, et des
 * dossiers rattachés à un compte disparu.
 *
 * D'où cette classe : les mêmes règles, appelées des deux côtés.
 *
 * La règle tient en une phrase — **on efface ce qui est personnel, on détache
 * ce que le club doit garder.** Un dossier d'adhésion est une pièce comptable
 * conservée dix ans ; le nombre de participants d'une sortie passée appartient
 * à l'histoire du club. Ni l'un ni l'autre ne doit continuer à désigner
 * quelqu'un : `user_id` passe à NULL.
 *
 * À NULL, et pas laissé tel quel : MariaDB recalcule son compteur
 * d'auto-incrément au démarrage, un compte créé plus tard peut donc hériter du
 * numéro d'un compte supprimé. Il hériterait avec lui de ses adhésions, de ses
 * sorties — et du droit de modifier ses événements.
 */
final class MemberPurge
{
    public static function register(): void
    {
        // `deleted_user` et non `delete_user` : on agit une fois la suppression
        // acquise, jamais sur une opération qui peut encore échouer.
        add_action('deleted_user', [self::class, 'forgetUser']);
    }

    /**
     * Tout le nettoyage d'un compte qui vient d'être supprimé.
     *
     * L'ordre compte : les places des sorties à venir se rendent tant que les
     * inscriptions désignent encore leur inscrit.
     */
    public static function forgetUser(int $userId): void
    {
        $documents    = self::documents($userId);
        $freed        = self::releaseUpcomingRegistrations($userId);
        $past         = self::detachRegistrations($userId);
        $applications = self::detachApplications($userId);
        $events       = self::detachOrganizedEvents($userId);
        $messages     = self::detachMessages($userId);
        $diveLevels   = self::forgetDiveLevelHistory($userId);

        self::forgetCommunication($userId);

        Audit::log('privacy.account_purged', 'user', $userId, [
            'documents'     => $documents,
            'places_freed'  => $freed,
            'registrations' => $past,
            'applications'  => $applications,
            'events'        => $events,
            'messages'      => $messages,
            'dive_levels'   => $diveLevels,
        ]);
    }

    /**
     * Documents personnels : les lignes, les fichiers, le journal d'accès.
     *
     * Supprimer la ligne sans supprimer le fichier serait le pire des deux
     * mondes : plus personne ne saurait qu'un certificat médical dort sur le
     * stockage, et il y dormirait quand même.
     *
     * @return int nombre de documents supprimés
     */
    public static function documents(int $userId): int
    {
        global $wpdb;

        $documents = $wpdb->get_results($wpdb->prepare(
            "SELECT id, file_path FROM {$wpdb->prefix}sub_member_documents WHERE user_id = %d",
            $userId
        ), ARRAY_A) ?: [];

        if ($documents === []) {
            return 0;
        }

        foreach ($documents as $document) {
            if ((string) $document['file_path'] !== '') {
                DocumentStorage::delete((string) $document['file_path']);
            }
        }

        $wpdb->delete("{$wpdb->prefix}sub_member_documents", ['user_id' => $userId]);

        // Le journal de consultation suit le document : sans lui, il ne
        // documente plus rien.
        $wpdb->query(
            "DELETE l FROM {$wpdb->prefix}sub_document_access_log l
             LEFT JOIN {$wpdb->prefix}sub_member_documents d ON d.id = l.document_id
             WHERE d.id IS NULL"
        );

        return count($documents);
    }

    /**
     * Rend les places réservées sur les sorties à venir.
     *
     * Une inscription ne survit pas à son inscrit : la place repart au premier
     * de la liste d'attente, exactement comme pour une désinscription
     * ordinaire. C'est précisément ce que la suppression native laissait de
     * côté — un participant sans nom, une place bloquée, un compteur faux.
     *
     * @return int nombre de places rendues
     */
    public static function releaseUpcomingRegistrations(int $userId): int
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.id, r.status, r.event_id
             FROM {$wpdb->prefix}sub_event_registrations r
             INNER JOIN {$wpdb->prefix}sub_events e ON e.id = r.event_id
             WHERE r.user_id = %d AND e.starts_at >= %s",
            $userId,
            current_time('mysql')
        ), ARRAY_A) ?: [];

        if ($rows === []) {
            return 0;
        }

        $events = new EventService();

        foreach ($rows as $row) {
            $wpdb->delete("{$wpdb->prefix}sub_event_registrations", ['id' => (int) $row['id']]);

            // La liste d'attente n'avance que si une place confirmée se libère.
            if ($row['status'] === 'confirmed') {
                $events->promoteNextInLine((int) $row['event_id']);
            }
        }

        return count($rows);
    }

    /**
     * Les sorties passées restent comptées, sans plus désigner personne.
     *
     * @return int nombre d'inscriptions détachées
     */
    public static function detachRegistrations(int $userId): int
    {
        global $wpdb;

        return (int) $wpdb->update(
            "{$wpdb->prefix}sub_event_registrations",
            ['user_id' => null, 'details' => null],
            ['user_id' => $userId]
        );
    }

    /**
     * Efface les réponses individuelles, garde le lien.
     *
     * La variante de [PersonalData::erase], où le compte survit à l'effacement :
     * détacher l'inscription empêcherait le membre de retrouver ses propres
     * sorties dans son espace.
     *
     * @return int nombre d'inscriptions concernées
     */
    public static function forgetRegistrationAnswers(int $userId): int
    {
        global $wpdb;

        return (int) $wpdb->update(
            "{$wpdb->prefix}sub_event_registrations",
            ['details' => null],
            ['user_id' => $userId]
        );
    }

    /**
     * Dossiers d'adhésion et règlements : conservés, détachés.
     *
     * Ce sont des pièces comptables, soumises à dix ans de conservation. Elles
     * gardent leur référence, leurs montants et leurs dates ; elles perdent le
     * lien vers un compte qui n'existe plus. [PersonalData::erase] les laisse
     * intactes — là, le compte subsiste, et c'est ce lien qui les rend
     * probantes.
     *
     * @return int nombre de dossiers détachés
     */
    public static function detachApplications(int $userId): int
    {
        global $wpdb;

        $detached = (int) $wpdb->update(
            "{$wpdb->prefix}sub_applications",
            ['user_id' => null],
            ['user_id' => $userId]
        );

        $wpdb->update("{$wpdb->prefix}sub_payments", ['user_id' => null], ['user_id' => $userId]);

        return $detached;
    }

    /**
     * Événements organisés : le club les garde, l'organisateur s'efface.
     *
     * `organizer_id` n'est pas décoratif — il ouvre le droit de modifier
     * l'événement. Un identifiant réattribué donnerait ce droit à quelqu'un qui
     * n'a rien demandé.
     *
     * Une sortie à venir se retrouve donc sans organisateur. C'est visible dans
     * l'écran des événements, et c'est au bureau d'y remettre quelqu'un : un
     * nettoyage automatique ne peut pas décider qui prend la suite.
     *
     * @return int nombre d'événements détachés
     */
    public static function detachOrganizedEvents(int $userId): int
    {
        global $wpdb;

        return (int) $wpdb->update(
            "{$wpdb->prefix}sub_events",
            ['organizer_id' => null],
            ['organizer_id' => $userId]
        );
    }

    /**
     * Journal des envois : la trace reste, l'adresse part.
     *
     * Savoir qu'un rappel est parti pour telle sortie garde son sens après le
     * départ de son destinataire. Garder son adresse, non.
     *
     * @return int nombre de messages détachés
     */
    public static function detachMessages(int $userId): int
    {
        global $wpdb;

        return (int) $wpdb->update(
            "{$wpdb->prefix}sub_notification_log",
            ['recipient_id' => null, 'recipient_email' => ''],
            ['recipient_id' => $userId]
        );
    }

    /**
     * Historique des brevets : personnel, sans obligation de conservation.
     *
     * @return int nombre de lignes supprimées
     */
    public static function forgetDiveLevelHistory(int $userId): int
    {
        global $wpdb;

        return (int) $wpdb->delete("{$wpdb->prefix}sub_dive_level_history", ['user_id' => $userId]);
    }

    /**
     * Solde ce que les suppressions d'avant ont laissé derrière elles.
     *
     * Appelée par la migration, une fois les colonnes rendues détachables.
     * Jusqu'ici, supprimer un compte depuis l'écran natif ne touchait à aucune
     * table du plugin : restaient des inscriptions sans inscrit — un
     * participant sans nom, une place bloquée sur une sortie à venir, un
     * compteur faux — et des dossiers rattachés à un identifiant qu'un futur
     * compte pourrait reprendre.
     *
     * Une différence avec le nettoyage courant : la liste d'attente n'est pas
     * rejouée. Promouvoir quelqu'un des mois après coup ferait arriver un
     * courriel incompréhensible ; la place est rendue, le bureau la donne.
     */
    public static function repairOrphans(): void
    {
        global $wpdb;

        // 1. Documents : le fichier compte autant que la ligne, donc on passe
        //    par le même chemin que pour un départ ordinaire.
        $owners = $wpdb->get_col(
            "SELECT DISTINCT d.user_id FROM {$wpdb->prefix}sub_member_documents d
             LEFT JOIN {$wpdb->users} u ON u.ID = d.user_id
             WHERE u.ID IS NULL"
        ) ?: [];

        foreach ($owners as $owner) {
            self::documents((int) $owner);
        }

        // 2. Les places réservées sur des sorties encore à venir.
        $wpdb->query(
            "DELETE r FROM {$wpdb->prefix}sub_event_registrations r
             INNER JOIN {$wpdb->prefix}sub_events e ON e.id = r.event_id
             LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
             WHERE r.user_id IS NOT NULL AND u.ID IS NULL AND e.starts_at >= NOW()"
        );

        // 3. Les sorties passées : la ligne reste, les réponses partent avec
        //    l'inscrit. Les deux colonnes d'un coup, tant qu'on sait encore
        //    quelles lignes sont concernées.
        $wpdb->query(
            "UPDATE {$wpdb->prefix}sub_event_registrations r
             LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
             SET r.user_id = NULL, r.details = NULL
             WHERE r.user_id IS NOT NULL AND r.user_id <> 0 AND u.ID IS NULL"
        );

        // Même raison ici : une fois `recipient_id` vidé, plus rien ne
        // distinguerait ces lignes d'un envoi légitime à une adresse hors
        // compte — un représentant légal, par exemple —, dont l'adresse doit
        // rester.
        $wpdb->query(
            "UPDATE {$wpdb->prefix}sub_notification_log l
             LEFT JOIN {$wpdb->users} u ON u.ID = l.recipient_id
             SET l.recipient_id = NULL, l.recipient_email = ''
             WHERE l.recipient_id IS NOT NULL AND l.recipient_id <> 0 AND u.ID IS NULL"
        );

        // 4. Le reste de ce qui se conserve : dossiers, règlements, événements
        //    organisés. La liste vient du schéma, pour qu'une colonne ajoutée
        //    là-bas soit soldée ici sans qu'on y pense.
        foreach (Schema::subjectColumns() as $table => $column) {
            $name = $wpdb->prefix . 'sub_' . $table;

            $wpdb->query(
                "UPDATE `{$name}` t
                 LEFT JOIN {$wpdb->users} u ON u.ID = t.`{$column}`
                 SET t.`{$column}` = NULL
                 WHERE t.`{$column}` IS NOT NULL AND t.`{$column}` <> 0 AND u.ID IS NULL"
            );
        }

        // 5. Ce qui ne se conserve pas : groupes de diffusion et brevets.
        $wpdb->query(
            "DELETE m FROM {$wpdb->prefix}sub_mailing_group_members m
             LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id
             WHERE u.ID IS NULL"
        );

        $wpdb->query(
            "DELETE h FROM {$wpdb->prefix}sub_dive_level_history h
             LEFT JOIN {$wpdb->users} u ON u.ID = h.user_id
             WHERE u.ID IS NULL"
        );
    }

    /**
     * Préférences de diffusion et appartenance aux groupes du bureau.
     *
     * Sur un compte supprimé, WordPress a déjà emporté les métas : ces appels
     * ne trouvent rien, et c'est très bien — une seule logique de nettoyage
     * vaut mieux que deux presque identiques.
     */
    public static function forgetCommunication(int $userId): void
    {
        foreach ([
            Subscriptions::META_STATUS,
            Subscriptions::META_DATE,
            Subscriptions::META_SOURCE,
            Subscriptions::META_TOKEN,
            Subscriptions::META_ANNOUNCEMENTS,
        ] as $meta) {
            delete_user_meta($userId, $meta);
        }

        CustomGroups::forgetUser($userId);
    }
}
