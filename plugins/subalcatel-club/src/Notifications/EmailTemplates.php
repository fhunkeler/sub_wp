<?php

declare(strict_types=1);

namespace Subalcatel\Club\Notifications;

/**
 * Modèles d'e-mails.
 *
 * Trois canaux, à ne pas confondre — c'est l'erreur la plus fréquente, et elle
 * a des conséquences juridiques comme pratiques :
 *
 * - `transactional` : déclenché par une action du membre ou par le calendrier.
 *   Base légale : exécution de l'adhésion. Pas de désabonnement, sinon le
 *   membre raterait ses convocations.
 * - `targeted` : envoyé par une personne autorisée à une liste construite par
 *   le contexte (les inscrits d'une sortie). Tracé.
 * - `newsletter` : information générale, soumise au consentement, avec
 *   désabonnement obligatoire. Traitée par l'extension de newsletter, pas ici.
 */
final class EmailTemplates
{
    public const CHANNEL_TRANSACTIONAL = 'transactional';
    public const CHANNEL_TARGETED      = 'targeted';

    // Adhésion
    // Comptes
    public const ACCOUNT_PENDING  = 'account.pending';
    public const ACCOUNT_APPROVED = 'account.approved';
    public const ACCOUNT_REFUSED  = 'account.refused';

    public const MEMBERSHIP_SUBMITTED = 'membership.submitted';
    public const MEMBERSHIP_PAID      = 'membership.paid';
    public const MEMBERSHIP_ACTIVATED = 'membership.activated';
    public const MEMBERSHIP_REFUSED   = 'membership.refused';
    public const MEMBERSHIP_EXPIRING  = 'membership.expiring';

    // Documents
    public const DOCUMENT_REMINDER  = 'document.reminder';
    public const DOCUMENT_VALIDATED = 'document.validated';
    public const DOCUMENT_REJECTED  = 'document.rejected';
    public const DOCUMENT_PURGED    = 'document.purged';

    // Événements
    public const EVENT_REGISTERED = 'event.registered';
    public const EVENT_WAITING    = 'event.waiting';
    public const EVENT_PROMOTED   = 'event.promoted';
    public const EVENT_CANCELLED  = 'event.cancelled';
    public const EVENT_MESSAGE      = 'event.message';
    public const EVENT_TO_ORGANIZER = 'event.to_organizer';
    public const EVENT_ANNOUNCEMENT = 'event.announcement';

    /**
     * Variables communes à tous les modèles.
     *
     * @return array<string, string>
     */
    public static function commonVariables(): array
    {
        return [
            'prenom' => 'Prénom du destinataire',
            'nom'    => 'Nom du destinataire',
            'club'   => 'Nom du club',
            'site'   => 'Adresse du site',
        ];
    }

    /**
     * Modèles installés au démarrage.
     *
     * Les textes sont volontairement sobres et complets : un membre doit
     * comprendre ce qui se passe et ce qu'on attend de lui sans rappeler
     * le président.
     *
     * @return list<array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            [
                'code'        => self::ACCOUNT_PENDING,
                'label'       => 'Nouveau compte à valider',
                'description' => 'Envoyé aux personnes habilitées à valider, dès qu’un compte est créé. '
                    . 'Sans lui, une demande peut attendre des semaines : personne ne consulte '
                    . 'spontanément une file vide.',
                'channel'     => self::CHANNEL_TARGETED,
                'subject'     => '[{club}] Nouveau compte à valider : {nom}',
                'body'        => "Bonjour,\n\n"
                    . "{nom} ({email}) vient de créer un compte sur le site du club.\n\n"
                    . "Le compte reste sans effet tant qu’il n’est pas validé : la personne ne "
                    . "peut ni adhérer ni s’inscrire à une sortie.\n\n"
                    . "Traiter la demande : {lien}\n\n"
                    . "— {club}",
                'variables'   => [
                    'nom'   => 'Nom du demandeur',
                    'email' => 'Son adresse',
                    'lien'  => 'Lien vers la file d’attente',
                ],
            ],
            [
                'code'        => self::ACCOUNT_APPROVED,
                'label'       => 'Compte validé',
                'description' => 'Envoyé quand le bureau valide un nouveau compte.',
                'subject'     => '[{club}] Votre compte est validé',
                'body'        => "Bonjour {prenom},\n\n"
                    . "Le bureau a validé votre compte : vous pouvez désormais constituer "
                    . "votre dossier d’adhésion et vous inscrire aux sorties.\n\n"
                    . "Rendez-vous dans votre espace membre pour commencer.\n\n"
                    . "— {club}",
                'variables'   => [],
            ],
            [
                'code'        => self::ACCOUNT_REFUSED,
                'label'       => 'Compte refusé',
                'description' => 'Envoyé quand le bureau refuse un nouveau compte. Le motif est repris tel quel.',
                'subject'     => '[{club}] Votre demande de compte',
                'body'        => "Bonjour {prenom},\n\n"
                    . "Le bureau n’a pas donné suite à votre demande de compte.\n\n"
                    . "Motif : {motif}\n\n"
                    . "Si cette décision vous paraît résulter d’un malentendu, répondez à ce "
                    . "message : nous la réexaminerons.\n\n"
                    . "— {club}",
                'variables'   => ['motif' => 'Motif du refus, saisi par le bureau'],
            ],
            [
                'code'        => self::MEMBERSHIP_SUBMITTED,
                'label'       => 'Dossier d’adhésion reçu',
                'description' => 'Envoyé au membre dès qu’il soumet son dossier.',
                'subject'     => '[{club}] Votre dossier d’adhésion {reference}',
                'body'        => "Bonjour {prenom},\n\n"
                    . "Nous avons bien reçu votre dossier d’adhésion {reference}.\n\n"
                    . "Montant à régler : {montant}.\n"
                    . "Formule : {formule}.\n\n"
                    . "Le règlement se fait par chèque ou via HelloAsso. Votre adhésion sera "
                    . "active dès que le bureau aura confirmé le paiement et vérifié vos pièces.\n\n"
                    . "— {club}",
                'variables'   => ['reference' => 'Référence du dossier', 'montant' => 'Montant total', 'formule' => 'Formule choisie'],
            ],
            [
                'code'        => self::MEMBERSHIP_PAID,
                'label'       => 'Paiement enregistré',
                'description' => 'Envoyé quand la trésorerie confirme la réception du règlement.',
                'subject'     => '[{club}] Paiement reçu pour {reference}',
                'body'        => "Bonjour {prenom},\n\n"
                    . "Votre règlement de {montant} a bien été enregistré ({mode}).\n\n"
                    . "Il reste au secrétariat à vérifier vos pièces : vous recevrez alors "
                    . "la confirmation de votre adhésion.\n\n"
                    . "— {club}",
                'variables'   => ['reference' => 'Référence du dossier', 'montant' => 'Montant réglé', 'mode' => 'Mode de paiement'],
            ],
            [
                'code'        => self::MEMBERSHIP_ACTIVATED,
                'label'       => 'Adhésion active',
                'description' => 'Envoyé quand le secrétariat valide le dossier.',
                'subject'     => '[{club}] Votre adhésion est active',
                'body'        => "Bonjour {prenom},\n\n"
                    . "Votre adhésion est validée. Elle court jusqu’au {fin_validite}.\n\n"
                    . "Vous pouvez désormais vous inscrire aux sorties depuis votre espace membre.\n"
                    . "Pensez à y déposer votre certificat médical s’il n’est pas encore à jour : "
                    . "il conditionne l’inscription aux plongées.\n\n"
                    . "Bonnes bulles,\n{club}",
                'variables'   => ['fin_validite' => 'Date de fin d’adhésion'],
            ],
            [
                'code'        => self::MEMBERSHIP_REFUSED,
                'label'       => 'Dossier refusé',
                'description' => 'Envoyé si le secrétariat refuse le dossier. Le motif est obligatoire.',
                'subject'     => '[{club}] Votre dossier {reference} nécessite une correction',
                'body'        => "Bonjour {prenom},\n\n"
                    . "Votre dossier n’a pas pu être validé pour la raison suivante :\n\n"
                    . "{motif}\n\n"
                    . "N’hésitez pas à nous contacter si quelque chose n’est pas clair.\n\n"
                    . "— {club}",
                'variables'   => ['reference' => 'Référence du dossier', 'motif' => 'Motif du refus'],
            ],
            [
                'code'        => self::MEMBERSHIP_EXPIRING,
                'copy_guardian' => 1,
                'label'       => 'Adhésion à renouveler',
                'description' => 'Rappel automatique avant la fin de l’adhésion, selon les délais de la campagne.',
                'subject'     => '[{club}] Votre adhésion se termine le {fin_validite}',
                'body'        => "Bonjour {prenom},\n\n"
                    . "Votre adhésion arrive à échéance le {fin_validite}, dans {jours} jours.\n\n"
                    . "Passé cette date, vous ne pourrez plus vous inscrire aux sorties. "
                    . "Le renouvellement se fait depuis votre espace membre.\n\n"
                    . "— {club}",
                'variables'   => ['fin_validite' => 'Date de fin', 'jours' => 'Jours restants'],
            ],
            [
                'code'        => self::DOCUMENT_REMINDER,
                'copy_guardian' => 1,
                'label'       => 'Document à renouveler',
                'description' => 'Rappel avant expiration d’un document, selon les délais réglés sur son type.',
                'subject'     => '[{club}] {document} à renouveler',
                'body'        => "Bonjour {prenom},\n\n"
                    . "Votre {document} arrive à échéance le {fin_validite}, dans {jours} jours.\n\n"
                    . "Passé cette date, vous ne pourrez plus vous inscrire aux plongées tant "
                    . "qu’un nouveau document n’aura pas été déposé.\n\n"
                    . "Vous pouvez le déposer depuis votre espace membre.\n\n"
                    . "— {club}",
                'variables'   => ['document' => 'Nom du document', 'fin_validite' => 'Date de fin', 'jours' => 'Jours restants'],
            ],
            [
                'code'        => self::DOCUMENT_VALIDATED,
                'label'       => 'Document validé',
                'description' => 'Envoyé quand le bureau accepte un document déposé.',
                'subject'     => '[{club}] Votre {document} est validé',
                'body'        => "Bonjour {prenom},\n\n"
                    . "Votre {document} a été vérifié et accepté. Il est valable jusqu’au {fin_validite}.\n\n"
                    . "— {club}",
                'variables'   => ['document' => 'Nom du document', 'fin_validite' => 'Date de fin'],
            ],
            [
                'code'        => self::DOCUMENT_REJECTED,
                'copy_guardian' => 1,
                'label'       => 'Document refusé',
                'description' => 'Envoyé si le bureau refuse un document. Le motif est obligatoire.',
                'subject'     => '[{club}] Votre {document} doit être redéposé',
                'body'        => "Bonjour {prenom},\n\n"
                    . "Votre {document} n’a pas pu être accepté :\n\n"
                    . "{motif}\n\n"
                    . "Merci d’en déposer un nouveau depuis votre espace membre.\n\n"
                    . "— {club}",
                'variables'   => ['document' => 'Nom du document', 'motif' => 'Motif du refus'],
            ],
            [
                'code'        => self::DOCUMENT_PURGED,
                'copy_guardian' => 1,
                'label'       => 'Document supprimé',
                'description' => 'Prévient le membre que son document arrive au terme de sa conservation.',
                'subject'     => '[{club}] Votre {document} sera supprimé le {date_purge}',
                'body'        => "Bonjour {prenom},\n\n"
                    . "Votre {document}, expiré depuis le {fin_validite}, sera définitivement "
                    . "supprimé de nos serveurs le {date_purge}.\n\n"
                    . "Si vous souhaitez en garder une copie, téléchargez-le depuis votre espace "
                    . "membre avant cette date.\n\n"
                    . "— {club}",
                'variables'   => ['document' => 'Nom du document', 'fin_validite' => 'Date d’expiration', 'date_purge' => 'Date de suppression'],
            ],
            [
                'code'        => self::EVENT_REGISTERED,
                'copy_guardian' => 1,
                'label'       => 'Inscription confirmée',
                'description' => 'Envoyé au membre dès son inscription à un événement.',
                'subject'     => '[{club}] Inscription confirmée — {evenement}',
                'body'        => "Bonjour {prenom},\n\n"
                    . "Votre inscription à « {evenement} » est confirmée.\n\n"
                    . "Date : {date}\n"
                    . "Lieu : {lieu}\n\n"
                    . "Vous pouvez vous désinscrire depuis votre espace membre si vous êtes empêché — "
                    . "cela libère une place pour quelqu’un d’autre.\n\n"
                    . "— {club}",
                'variables'   => ['evenement' => 'Titre', 'date' => 'Date et heure', 'lieu' => 'Lieu'],
            ],
            [
                'code'        => self::EVENT_WAITING,
                'label'       => 'Inscription en liste d’attente',
                'description' => 'Envoyé quand l’événement est complet.',
                'subject'     => '[{club}] Liste d’attente — {evenement}',
                'body'        => "Bonjour {prenom},\n\n"
                    . "« {evenement} » est complet. Vous êtes inscrit en liste d’attente, "
                    . "en position {position}.\n\n"
                    . "Vous serez prévenu automatiquement si une place se libère.\n\n"
                    . "— {club}",
                'variables'   => ['evenement' => 'Titre', 'position' => 'Position dans la file'],
            ],
            [
                'code'        => self::EVENT_PROMOTED,
                'label'       => 'Place libérée',
                'description' => 'Envoyé au premier de la liste d’attente quand une place se libère.',
                'subject'     => '[{club}] Une place s’est libérée — {evenement}',
                'body'        => "Bonjour {prenom},\n\n"
                    . "Une place s’est libérée : vous êtes maintenant inscrit à « {evenement} ».\n\n"
                    . "Date : {date}\n"
                    . "Lieu : {lieu}\n\n"
                    . "Si vous ne pouvez finalement pas venir, désinscrivez-vous rapidement "
                    . "pour laisser la place à quelqu’un d’autre.\n\n"
                    . "— {club}",
                'variables'   => ['evenement' => 'Titre', 'date' => 'Date et heure', 'lieu' => 'Lieu'],
            ],
            [
                'code'        => self::EVENT_CANCELLED,
                'label'       => 'Désinscription enregistrée',
                'description' => 'Confirme au membre sa désinscription.',
                'subject'     => '[{club}] Désinscription — {evenement}',
                'body'        => "Bonjour {prenom},\n\n"
                    . "Votre désinscription de « {evenement} » est enregistrée.\n\n"
                    . "— {club}",
                'variables'   => ['evenement' => 'Titre'],
            ],
            [
                'code'        => self::EVENT_MESSAGE,
                'label'       => 'Message aux inscrits',
                'description' => 'Message écrit par l’organisateur et envoyé aux participants.',
                'channel'     => self::CHANNEL_TARGETED,
                'subject'     => '[{club}] {evenement} — {objet}',
                'body'        => "Bonjour {prenom},\n\n"
                    . "{message}\n\n"
                    . "— {expediteur}, pour {club}",
                'variables'   => ['evenement' => 'Titre', 'objet' => 'Objet du message', 'message' => 'Corps du message', 'expediteur' => 'Nom de l’expéditeur'],
            ],
            [
                'code'        => self::EVENT_ANNOUNCEMENT,
                'label'       => 'Annonce d’une sortie',
                'description' => 'Annonce d’un événement aux membres qu’il concerne. '
                    . 'Ne part jamais seul : l’organisateur coche la case à l’enregistrement, '
                    . 'ou déclenche l’envoi depuis l’écran de la sortie.',
                'channel'     => self::CHANNEL_TARGETED,
                'subject'     => '[{club}] Nouvelle sortie : {evenement} — {date}',
                'body'        => "Bonjour {prenom},\n\n"
                    . "{organisateur} ouvre une sortie à laquelle vous pouvez vous inscrire.\n\n"
                    . "{evenement}\n"
                    . "Date : {date}\n"
                    . "Lieu : {lieu}\n"
                    . "Places : {places}\n"
                    . "Niveau : {niveau}\n\n"
                    . "{description}\n\n"
                    . "{mot}\n\n"
                    . "S’inscrire : {lien}\n\n"
                    . "— {club}\n\n"
                    . "Vous recevez ce message parce que cette sortie correspond à votre "
                    . "niveau. Pour ne plus recevoir d’annonces de sortie, décochez la case "
                    . "correspondante sur votre profil ; vos convocations, elles, continueront "
                    . "de vous parvenir.",
                'variables'   => [
                    'evenement'    => 'Titre',
                    'date'         => 'Date et heure',
                    'lieu'         => 'Lieu',
                    'places'       => 'Places disponibles',
                    'niveau'       => 'Niveau requis',
                    'description'  => 'Description saisie à la création',
                    'mot'          => 'Mot de l’organisateur, facultatif',
                    'organisateur' => 'Nom de l’organisateur',
                    'lien'         => 'Lien vers la sortie dans l’agenda',
                ],
            ],
            [
                'code'        => self::EVENT_TO_ORGANIZER,
                'label'       => 'Question au directeur de plongée',
                'description' => 'Message écrit par un inscrit et adressé à l’organisateur de la sortie. '
                    . 'L’adresse de l’inscrit est mise en réponse : il suffit de répondre au courriel.',
                'channel'     => self::CHANNEL_TRANSACTIONAL,
                'subject'     => '[{club}] {evenement} — question de {expediteur}',
                'body'        => "Bonjour {prenom},\n\n"
                    . "{expediteur}, inscrit à « {evenement} », vous écrit :\n\n"
                    . "{message}\n\n"
                    . "Répondez directement à ce courriel : il partira vers {adresse_expediteur}.\n\n"
                    . "— {club}",
                'variables'   => [
                    'evenement'         => 'Titre de la sortie',
                    'message'           => 'Corps du message',
                    'expediteur'        => 'Nom de l’inscrit',
                    'adresse_expediteur' => 'Courriel de l’inscrit',
                ],
            ],
        ];
    }

    /**
     * Version du jeu de modèles.
     *
     * Même raison que pour les rôles : `seed()` ne tourne qu'à l'activation, et
     * une mise à jour par copie de fichiers ne la rejoue pas. Un modèle ajouté
     * après coup n'existerait alors dans aucune installation en service — et un
     * modèle absent ne provoque pas d'erreur, il fait taire l'envoi. À
     * incrémenter dès qu'un modèle est ajouté à `defaults()`.
     */
    private const VERSION        = 2;
    private const VERSION_OPTION = 'subalcatel_club_templates_version';

    public static function seedIfNeeded(): void
    {
        if ((int) get_option(self::VERSION_OPTION, 0) >= self::VERSION) {
            return;
        }

        self::seed();
        update_option(self::VERSION_OPTION, self::VERSION);
    }

    /**
     * Insère les modèles manquants.
     *
     * Les modèles existants ne sont jamais réécrits : le bureau a pu les
     * retoucher, et une mise à jour ne doit pas effacer son travail.
     */
    public static function seed(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sub_email_templates';

        foreach (self::defaults() as $template) {
            $exists = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$table} WHERE code = %s", $template['code'])
            );

            if ($exists) {
                continue;
            }

            $wpdb->insert($table, [
                'code'        => $template['code'],
                'label'       => $template['label'],
                'description' => $template['description'],
                'channel'       => $template['channel'] ?? self::CHANNEL_TRANSACTIONAL,
                'copy_guardian' => $template['copy_guardian'] ?? 0,
                'subject'     => $template['subject'],
                'body'        => $template['body'],
                'variables'   => wp_json_encode($template['variables'] ?? []),
            ]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $code): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sub_email_templates WHERE code = %s",
            $code
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}sub_email_templates ORDER BY code",
            ARRAY_A
        ) ?: [];
    }
}
