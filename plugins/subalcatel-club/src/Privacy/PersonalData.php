<?php

declare(strict_types=1);

namespace Subalcatel\Club\Privacy;

use Subalcatel\Club\Communication\Subscriptions;
use Subalcatel\Club\Documents\DocumentService;
use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Identity\ProfileFields;
use Subalcatel\Club\Support\Audit;

/**
 * Droits d'accès et d'effacement, branchés sur les outils natifs de WordPress.
 *
 * Le cadre existe déjà — demande, confirmation par courriel, écrans
 * d'administration : le plugin déclare seulement ce qu'il détient. Réécrire ce
 * flux serait long et moins sûr que celui du cœur.
 *
 * La difficulté n'est pas technique, elle est juridique : **tout ne peut pas
 * être effacé**. Une adhésion payée est une pièce comptable, conservée dix ans.
 * Elle est donc anonymisée, pas supprimée — le montant et la date restent, le
 * nom disparaît.
 */
final class PersonalData
{
    /**
     * Méta-capacités que WordPress remappe vers `manage_options`.
     */
    private const WP_PRIVACY_CAPS = [
        'export_others_personal_data',
        'erase_others_personal_data',
        'manage_privacy_options',
    ];

    public static function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'registerExporters']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'registerErasers']);
        add_filter('map_meta_cap', [self::class, 'mapPrivacyCaps'], 10, 3);
        add_filter('user_has_cap', [self::class, 'grantErasureContext'], 10, 2);
        add_action('admin_init', [self::class, 'addPolicyContent']);
    }

    /**
     * Ouvre les écrans RGPD au bureau.
     *
     * WordPress remappe ces trois capacités vers `manage_options` : les
     * accorder au rôle ne suffit pas, il faut intercepter le remappage. Sans
     * cela, seul un administrateur technique pourrait traiter une demande —
     * exactement ce qu'on cherche à éviter dans une association bénévole.
     *
     * @param list<string> $caps
     * @return list<string>
     */
    public static function mapPrivacyCaps(array $caps, string $cap, int $userId): array
    {
        if (!in_array($cap, self::WP_PRIVACY_CAPS, true)) {
            return $caps;
        }

        // `sub_handle_privacy_requests` est une capacité primitive : ce filtre
        // la laisse passer au premier test, donc pas de récursion.
        return user_can($userId, 'sub_handle_privacy_requests')
            ? ['sub_handle_privacy_requests']
            : $caps;
    }

    /**
     * Accorde `delete_users` — et seulement sur l'écran d'effacement.
     *
     * WordPress exige cette capacité en plus de `erase_others_personal_data`.
     * L'accorder au rôle laisserait un membre du bureau supprimer n'importe quel
     * compte du site, administrateur compris : hors de proportion avec ce qu'on
     * cherche à permettre. On la fait donc apparaître uniquement pendant les
     * requêtes qui en ont besoin, et elle reste absente de l'écran Comptes.
     *
     * @param array<string, bool> $allcaps
     * @param list<string>        $caps    capacités requises par le test en cours
     * @return array<string, bool>
     */
    public static function grantErasureContext(array $allcaps, array $caps): array
    {
        if (empty($allcaps['sub_handle_privacy_requests'])) {
            return $allcaps;
        }

        if (!in_array('delete_users', $caps, true) || !self::isErasureRequest()) {
            return $allcaps;
        }

        $allcaps['delete_users'] = true;

        return $allcaps;
    }

    private static function isErasureRequest(): bool
    {
        global $pagenow;

        if ($pagenow === 'erase-personal-data.php') {
            return true;
        }

        // L'écran lance l'effacement par petites tranches, en AJAX : sans ce
        // second cas, le bouton tournerait dans le vide.
        return wp_doing_ajax()
            && ($_REQUEST['action'] ?? '') === 'wp-privacy-erase-personal-data';
    }

    /**
     * @param array<string, array<string, mixed>> $exporters
     * @return array<string, array<string, mixed>>
     */
    public static function registerExporters(array $exporters): array
    {
        $exporters['subalcatel-club'] = [
            'exporter_friendly_name' => 'Club Sub Alcatel',
            'callback'               => [self::class, 'export'],
        ];

        return $exporters;
    }

    /**
     * @param array<string, array<string, mixed>> $erasers
     * @return array<string, array<string, mixed>>
     */
    public static function registerErasers(array $erasers): array
    {
        $erasers['subalcatel-club'] = [
            'eraser_friendly_name' => 'Club Sub Alcatel',
            'callback'             => [self::class, 'erase'],
        ];

        return $erasers;
    }

    /**
     * Tout ce que le club détient sur cette personne.
     *
     * @return array{data: list<array<string, mixed>>, done: bool}
     */
    public static function export(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);

        if (!$user) {
            return ['data' => [], 'done' => true];
        }

        $userId = $user->ID;

        $groups = array_merge(
            self::exportProfile($userId),
            self::exportMemberships($userId),
            self::exportDocuments($userId),
            self::exportRegistrations($userId),
            self::exportMessages($userId),
            self::exportCommunication($userId),
        );

        Audit::log('privacy.exported', 'user', $userId, ['groups' => count($groups)]);

        return ['data' => $groups, 'done' => true];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function exportProfile(int $userId): array
    {
        $items = [];

        foreach (ProfileFields::forUser($userId) as $name => $field) {
            $value = ProfileFields::get($userId, $name);

            if ($value === '') {
                continue;
            }

            if ($field['type'] === 'dive_level') {
                $term  = get_term((int) $value, DiveLevels::TAXONOMY);
                $value = $term instanceof \WP_Term ? $term->name : $value;
            }

            $items[] = ['name' => (string) $field['label'], 'value' => $value];
        }

        if ($items === []) {
            return [];
        }

        return [[
            'group_id'    => 'sub-profile',
            'group_label' => 'Profil de membre',
            'item_id'     => 'sub-profile',
            'data'        => $items,
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function exportMemberships(int $userId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, p.title AS plan_title FROM {$wpdb->prefix}sub_applications a
             LEFT JOIN {$wpdb->prefix}sub_plans p ON p.id = a.plan_id
             WHERE a.user_id = %d ORDER BY a.created_at DESC",
            $userId
        ), ARRAY_A) ?: [];

        $groups = [];

        foreach ($rows as $row) {
            $groups[] = [
                'group_id'    => 'sub-memberships',
                'group_label' => 'Adhésions',
                'item_id'     => 'sub-membership-' . $row['id'],
                'data'        => [
                    ['name' => 'Référence', 'value' => (string) $row['reference']],
                    ['name' => 'Formule', 'value' => (string) $row['plan_title']],
                    ['name' => 'Montant', 'value' => number_format((float) $row['total_amount'], 2, ',', ' ') . ' €'],
                    ['name' => 'Période', 'value' => sprintf('du %s au %s', $row['valid_from'], $row['valid_until'])],
                    ['name' => 'État', 'value' => (string) $row['status']],
                ],
            ];
        }

        return $groups;
    }

    /**
     * Documents : les métadonnées, jamais le fichier.
     *
     * Un export automatique qui embarquerait un certificat médical circulerait
     * par courriel, sans chiffrement ni contrôle. Le membre télécharge ses
     * documents depuis son espace, lui seul, à travers un accès vérifié.
     *
     * @return list<array<string, mixed>>
     */
    private static function exportDocuments(int $userId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT d.*, t.label FROM {$wpdb->prefix}sub_member_documents d
             LEFT JOIN {$wpdb->prefix}sub_document_types t ON t.slug = d.type_slug
             WHERE d.user_id = %d ORDER BY d.uploaded_at DESC",
            $userId
        ), ARRAY_A) ?: [];

        $groups = [];

        foreach ($rows as $row) {
            $groups[] = [
                'group_id'    => 'sub-documents',
                'group_label' => 'Documents déposés',
                'item_id'     => 'sub-document-' . $row['id'],
                'data'        => [
                    ['name' => 'Type', 'value' => (string) $row['label']],
                    ['name' => 'Déposé le', 'value' => (string) $row['uploaded_at']],
                    ['name' => 'Valable jusqu’au', 'value' => (string) ($row['valid_until'] ?: '—')],
                    ['name' => 'État', 'value' => (string) $row['status']],
                    [
                        'name'  => 'Fichier',
                        'value' => $row['status'] === DocumentService::STATUS_PURGED
                            ? 'Supprimé à l’échéance de conservation'
                            : 'Téléchargeable depuis votre espace membre',
                    ],
                ],
            ];
        }

        return $groups;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function exportRegistrations(int $userId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, e.title, e.starts_at, e.type_id
             FROM {$wpdb->prefix}sub_event_registrations r
             INNER JOIN {$wpdb->prefix}sub_events e ON e.id = r.event_id
             WHERE r.user_id = %d ORDER BY e.starts_at DESC",
            $userId
        ), ARRAY_A) ?: [];

        $groups = [];

        foreach ($rows as $row) {
            $data = [
                ['name' => 'Événement', 'value' => (string) $row['title']],
                ['name' => 'Date', 'value' => (string) $row['starts_at']],
                ['name' => 'État', 'value' => (string) $row['status']],
            ];

            foreach ((array) (json_decode((string) $row['details'], true) ?: []) as $key => $value) {
                $data[] = ['name' => 'Réponse : ' . $key, 'value' => (string) $value];
            }

            $groups[] = [
                'group_id'    => 'sub-registrations',
                'group_label' => 'Inscriptions aux sorties',
                'item_id'     => 'sub-registration-' . $row['id'],
                'data'        => $data,
            ];
        }

        return $groups;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function exportMessages(int $userId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sub_notification_log
             WHERE recipient_id = %d ORDER BY sent_at DESC LIMIT 200",
            $userId
        ), ARRAY_A) ?: [];

        $groups = [];

        foreach ($rows as $row) {
            $groups[] = [
                'group_id'    => 'sub-messages',
                'group_label' => 'Courriels reçus',
                'item_id'     => 'sub-message-' . $row['id'],
                'data'        => [
                    ['name' => 'Envoyé le', 'value' => (string) $row['sent_at']],
                    ['name' => 'Objet', 'value' => (string) $row['subject']],
                    ['name' => 'Adresse', 'value' => (string) $row['recipient_email']],
                ],
            ];
        }

        return $groups;
    }

    /**
     * Abonnement à la lettre d'information et appartenance aux groupes.
     *
     * La date de consentement fait partie de l'export : c'est la réponse à
     * « depuis quand recevez-vous ces messages, et pourquoi ? ».
     *
     * @return list<array<string, mixed>>
     */
    private static function exportCommunication(int $userId): array
    {
        global $wpdb;

        $state = Subscriptions::stateOf($userId);

        $items = [[
            'name'  => 'Lettre d’information',
            'value' => match ($state['status']) {
                'yes'   => 'Abonné',
                'no'    => 'Désabonné',
                default => 'Aucun choix exprimé',
            },
        ]];

        $items[] = [
            'name'  => 'Annonces de sortie',
            'value' => match ($state['announcements']) {
                'no'    => 'Refusées',
                'yes'   => 'Acceptées',
                default => 'Acceptées (choix non modifié)',
            },
        ];

        if ($state['date'] !== '') {
            $items[] = ['name' => 'Dernière modification de ce choix', 'value' => $state['date']];
        }

        if ($state['source'] !== '') {
            $items[] = ['name' => 'Origine du consentement', 'value' => $state['source']];
        }

        $groups = $wpdb->get_col($wpdb->prepare(
            "SELECT g.label FROM {$wpdb->prefix}sub_mailing_group_members m
             INNER JOIN {$wpdb->prefix}sub_mailing_groups g ON g.id = m.group_id
             WHERE m.user_id = %d ORDER BY g.label",
            $userId
        )) ?: [];

        if ($groups !== []) {
            $items[] = ['name' => 'Groupes de diffusion', 'value' => implode(', ', $groups)];
        }

        return [[
            'group_id'    => 'sub-communication',
            'group_label' => 'Communication',
            'item_id'     => 'sub-communication',
            'data'        => $items,
        ]];
    }

    /**
     * Efface ce qui peut l'être, anonymise ce qui doit être conservé.
     *
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    public static function erase(string $email, int $page = 1): array
    {
        global $wpdb;

        $user = get_user_by('email', $email);

        if (!$user) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }

        $userId   = $user->ID;
        $messages = [];
        $removed  = false;
        $retained = false;

        // Filet de sécurité : le bureau peut lancer cet effacement depuis les
        // écrans natifs de WordPress, où rien ne l'avertit qu'une sortie est
        // réservée ou qu'une adhésion court encore. On refuse alors d'agir et
        // on dit quoi solder d'abord.
        $blockers = AccountDeletion::blockers($userId);

        if ($blockers !== []) {
            foreach ($blockers as $blocker) {
                $messages[] = sprintf(
                    'Effacement suspendu — %s : %s',
                    $blocker['label'],
                    // Le bureau lit la situation d'un autre : la formulation à
                    // la troisième personne, jamais celle destinée au membre.
                    $blocker['office'] ?? $blocker['detail']
                );
            }

            return [
                'items_removed'  => false,
                'items_retained' => true,
                'messages'       => $messages,
                'done'           => true,
            ];
        }

        // 1. Documents : fichiers et lignes supprimés sans réserve. Un
        //    certificat médical n'a aucune raison de survivre au départ.
        $documents = MemberPurge::documents($userId);

        if ($documents > 0) {
            $removed    = true;
            $messages[] = sprintf('%d document(s) personnel(s) supprimé(s), fichiers compris.', $documents);
        }

        // 2. Profil : toutes les métas du plugin.
        foreach (array_keys(ProfileFields::all()) as $field) {
            delete_user_meta($userId, ProfileFields::metaKey($field));
        }

        delete_user_meta($userId, 'sub_membership_valid_until');
        delete_user_meta($userId, 'sub_lending_rights');
        $removed = true;

        // 2 bis. Communication : préférences, jeton de désabonnement et
        //        appartenance aux groupes constitués par le bureau.
        MemberPurge::forgetCommunication($userId);

        // 3. Inscriptions : les réponses individuelles partent, la ligne reste.
        //    Le nombre de participants d'une sortie passée appartient à
        //    l'histoire du club, pas à la personne. Le compte, lui, subsiste :
        //    l'inscription continue de le désigner, sans quoi le membre ne
        //    retrouverait plus ses propres sorties.
        $registrations = MemberPurge::forgetRegistrationAnswers($userId);

        if ($registrations > 0) {
            $retained   = true;
            $messages[] = sprintf(
                '%d inscription(s) conservée(s) sans leurs réponses : elles comptent dans '
                . 'l’historique des sorties du club.',
                $registrations
            );
        }

        // 4. Adhésions et paiements : pièces comptables, conservées dix ans.
        //    On ne peut ni les effacer ni les anonymiser dans la foulée —
        //    le lien avec la personne est justement ce qui les rend probantes.
        $applications = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sub_applications WHERE user_id = %d",
            $userId
        ));

        if ($applications > 0) {
            $retained   = true;
            $messages[] = sprintf(
                '%d adhésion(s) et leurs règlements sont conservés : ce sont des pièces '
                . 'comptables, soumises à une obligation légale de conservation. Elles seront '
                . 'anonymisées à l’expiration de ce délai.',
                $applications
            );
        }

        Audit::log('privacy.erased', 'user', $userId, [
            'documents'     => $documents,
            'registrations' => $registrations,
            'applications'  => $applications,
        ]);

        return [
            'items_removed'  => $removed,
            'items_retained' => $retained,
            'messages'       => $messages,
            'done'           => true,
        ];
    }

    /**
     * Contenu suggéré pour la politique de confidentialité.
     *
     * WordPress l'affiche dans l'écran d'édition de la page, où le bureau le
     * reprend et l'adapte. Mieux vaut un texte imparfait qu'il ajuste qu'une
     * page vide qu'il n'écrira jamais.
     */
    public static function addPolicyContent(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        wp_add_privacy_policy_content('Club Sub Alcatel', wp_kses_post(self::policyText()));
    }

    /**
     * Le texte lui-même, séparé de son enregistrement : il se relit et se
     * vérifie sans avoir à simuler un écran d'administration.
     */
    public static function policyText(): string
    {
        return '<h3>Données collectées par le club</h3>'
            . '<p>Le site conserve les informations nécessaires à la vie associative : '
            . 'identité et coordonnées, niveau de plongée et qualifications, adhésions et '
            . 'règlements, inscriptions aux sorties, et les documents que vous déposez.</p>'

            . '<h3>Certificat médical</h3>'
            . '<p>Le certificat médical est une donnée de santé. Il est <strong>chiffré</strong> '
            . 'sur nos serveurs, consultable uniquement par les membres du bureau habilités, et '
            . 'chaque consultation est journalisée. Il est automatiquement supprimé passé son '
            . 'échéance de conservation ; seule subsiste la trace de sa vérification.</p>'
            . '<p>Les encadrants ne voient jamais le document : uniquement un indicateur de '
            . 'validité, nécessaire à la sécurité des plongées.</p>'

            . '<h3>Adhérents mineurs</h3>'
            . '<p>Pour un adhérent mineur, les coordonnées du représentant légal sont conservées '
            . 'et reçoivent copie des rappels. Elles sont effacées automatiquement à sa majorité.</p>'

            . '<h3>Durées de conservation</h3>'
            . '<ul>'
            . '<li>Profil et coordonnées : tant que le compte existe.</li>'
            . '<li>Documents personnels : selon la règle propre à chaque type, affichée lors du dépôt.</li>'
            . '<li>Adhésions et règlements : dix ans, obligation comptable.</li>'
            . '<li>Inscriptions aux sorties : conservées sans les réponses individuelles.</li>'
            . '</ul>'

            . '<h3>Vos droits</h3>'
            . '<p>Vous pouvez demander l’accès à vos données ou leur effacement depuis votre '
            . 'espace membre, ou en écrivant au bureau. Certaines pièces comptables ne peuvent '
            . 'être supprimées avant l’expiration du délai légal : elles sont alors conservées '
            . 'sans usage autre que la tenue des comptes.</p>';
    }
}
