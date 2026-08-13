<?php

declare(strict_types=1);

namespace Subalcatel\Club\Events;

use RuntimeException;
use Subalcatel\Club\Communication\MailingLists;
use Subalcatel\Club\Communication\Subscriptions;
use Subalcatel\Club\Frontend\Pages;
use Subalcatel\Club\Policy\Decision;
use Subalcatel\Club\Policy\EligibilityPolicy;
use Subalcatel\Club\Notifications\EmailTemplates;
use Subalcatel\Club\Notifications\Mailer;
use Subalcatel\Club\Support\Audit;

/**
 * Événements du club : création, éligibilité, inscriptions.
 *
 * L'audit du Joomla a montré que c'est le module le plus utilisé du site —
 * 805 événements et 6 537 inscriptions. Il mérite donc autant de soin que les
 * adhésions, en particulier sur deux points : le motif de refus doit être
 * explicite, et deux personnes ne doivent jamais prendre la même place.
 */
final class EventService
{
    private string $prefix;

    public function __construct(
        private readonly EligibilityPolicy $policy = new EligibilityPolicy(),
    ) {
        global $wpdb;
        $this->prefix = $wpdb->prefix . 'sub_';
    }

    /**
     * Crée un événement en copiant les règles de son type.
     *
     * @param array{title: string, starts_at: string, ends_at?: ?string, location?: string,
     *              description?: string, capacity?: int, accepted_levels?: list<string>,
     *              registration_closes_at?: ?string} $data
     */
    public function create(string $typeSlug, array $data, int $organizerId): int
    {
        global $wpdb;

        $type = $this->typeBySlug($typeSlug);
        if ($type === null) {
            throw new RuntimeException('Type d’événement inconnu.');
        }

        if (!user_can($organizerId, (string) $type['create_capability'])) {
            throw new RuntimeException(
                sprintf('Vous n’avez pas le droit de créer un événement « %s ».', $type['name'])
            );
        }

        // Contraintes de niveau propres à l'encadrement : un plongeur autonome
        // encadre une exploration, pas une formation.
        if ((int) $type['requires_dive_leader'] === 1 && !$this->policy->isDiveLeader($organizerId)) {
            throw new RuntimeException('Seul un directeur de plongée peut créer ce type d’événement.');
        }

        if ((int) $type['requires_autonomous'] === 1 && !$this->policy->isAutonomousDiver($organizerId)) {
            throw new RuntimeException('Seul un plongeur autonome peut créer ce type d’événement.');
        }

        // Cohérence des dates. Une fin avant le début ou une clôture après le
        // départ ne se rattrapent pas ensuite : l'événement s'affiche déjà de
        // travers dans l'agenda et dans les exports iCal.
        if (!empty($data['ends_at']) && $data['ends_at'] < $data['starts_at']) {
            throw new RuntimeException('La fin de la sortie précède son début.');
        }

        if (!empty($data['registration_closes_at'])
            && $data['registration_closes_at'] > $data['starts_at']) {
            throw new RuntimeException('Les inscriptions ne peuvent pas se clore après le départ.');
        }

        $wpdb->insert("{$this->prefix}events", [
            'type_id'                => (int) $type['id'],
            'title'                  => $data['title'],
            'slug'                   => $this->uniqueSlug($data['title']),
            'description'            => $data['description'] ?? '',
            'location'               => $data['location'] ?? null,
            'starts_at'              => $data['starts_at'],
            'ends_at'                => $data['ends_at'] ?? null,
            'registration_closes_at' => $data['registration_closes_at'] ?? null,
            // Les règles sont copiées, pas référencées.
            'capacity'               => $data['capacity'] ?? (int) $type['default_capacity'],
            'allow_waiting_list'     => (int) $type['allow_waiting_list'],
            'requires_medical'       => (int) $type['requires_medical'],
            'requires_membership'    => (int) $type['requires_membership'],
            'accepted_levels'        => wp_json_encode($data['accepted_levels'] ?? []),
            'organizer_id'           => $organizerId,
        ]);

        $eventId = (int) $wpdb->insert_id;

        Audit::log('event.created', 'event', $eventId, ['type' => $typeSlug], $organizerId);

        return $eventId;
    }

    /**
     * Types d'événement que ce membre a le droit de créer.
     *
     * Mêmes règles que `create()`, posées ici pour que les écrans n'aient pas à
     * les redire : l'administration et l'espace membre proposent exactement ce
     * que le service acceptera. Un formulaire qui offre un choix refusé ensuite
     * est pire qu'un formulaire vide.
     *
     * @return list<array<string, mixed>>
     */
    public function creatableTypesFor(int $userId): array
    {
        global $wpdb;

        $types = $wpdb->get_results("SELECT * FROM {$this->prefix}event_types ORDER BY name", ARRAY_A) ?: [];

        return array_values(array_filter(
            $types,
            fn (array $type): bool => $this->mayCreate($type, $userId)
        ));
    }

    /**
     * @param array<string, mixed> $type
     */
    private function mayCreate(array $type, int $userId): bool
    {
        if (!user_can($userId, (string) $type['create_capability'])) {
            return false;
        }

        if ((int) $type['requires_dive_leader'] === 1 && !$this->policy->isDiveLeader($userId)) {
            return false;
        }

        if ((int) $type['requires_autonomous'] === 1 && !$this->policy->isAutonomousDiver($userId)) {
            return false;
        }

        return true;
    }

    /**
     * Le membre peut-il s'inscrire ? Renvoie toujours un motif exploitable.
     */
    public function checkEligibility(int $eventId, int $userId): Decision
    {
        $event = $this->find($eventId);

        if ($event === null) {
            return Decision::deny('Événement introuvable.');
        }

        if ($event['status'] !== 'published') {
            return Decision::deny('Cet événement n’est pas ouvert aux inscriptions.');
        }

        $closesAt = $event['registration_closes_at'] ?: $event['starts_at'];
        if ($closesAt < current_time('mysql')) {
            return Decision::deny('Les inscriptions sont closes.');
        }

        if ((int) $event['requires_membership'] === 1) {
            $decision = $this->policy->hasActiveMembership($userId);
            if (!$decision->allowed) {
                return $decision;
            }
        }

        if ((int) $event['requires_medical'] === 1) {
            $decision = $this->policy->hasValidDocuments($userId);
            if (!$decision->allowed) {
                return $decision;
            }
        }

        $levels = (array) (json_decode((string) $event['accepted_levels'], true) ?: []);
        $decision = $this->policy->meetsDiveLevel($userId, $levels);
        if (!$decision->allowed) {
            return $decision;
        }

        return Decision::allow();
    }

    /**
     * Inscrit un membre.
     *
     * Le comptage des places et l'insertion se font dans une transaction avec
     * verrou : sans cela, deux inscriptions simultanées prennent la même
     * dernière place. La contrainte UNIQUE(event_id, user_id) fait le reste.
     *
     * @param array<string, mixed> $details Réponses au formulaire de sortie.
     * @return array{status: string, position: int}
     */
    public function register(int $eventId, int $userId, array $details = []): array
    {
        global $wpdb;

        $decision = $this->checkEligibility($eventId, $userId);
        if (!$decision->allowed) {
            throw new RuntimeException($decision->reason);
        }

        $event = $this->find($eventId);

        $wpdb->query('START TRANSACTION');

        try {
            // Inscription déjà existante : on le détecte ici plutôt que de
            // laisser la contrainte UNIQUE lever une erreur SQL, qui polluerait
            // les journaux pour un cas parfaitement normal.
            $already = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$this->prefix}event_registrations
                 WHERE event_id = %d AND user_id = %d FOR UPDATE",
                $eventId,
                $userId
            ));

            if ($already !== null && $already !== 'cancelled') {
                throw new RuntimeException('Vous êtes déjà inscrit à cet événement.');
            }

            $confirmed = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->prefix}event_registrations
                 WHERE event_id = %d AND status = 'confirmed' FOR UPDATE",
                $eventId
            ));

            $capacity = (int) $event['capacity'];
            $full     = $capacity > 0 && $confirmed >= $capacity;

            if ($full && (int) $event['allow_waiting_list'] !== 1) {
                throw new RuntimeException('Événement complet, sans liste d’attente.');
            }

            $status = $full ? 'waiting' : 'confirmed';
            $data   = [
                'event_id' => $eventId,
                'user_id'  => $userId,
                'status'   => $status,
                'details'  => $details === [] ? null : wp_json_encode($details),
            ];

            // Une inscription annulée est réactivée plutôt que dupliquée.
            $written = $already === 'cancelled'
                ? $wpdb->update(
                    "{$this->prefix}event_registrations",
                    $data + ['cancelled_at' => null, 'registered_at' => current_time('mysql')],
                    ['event_id' => $eventId, 'user_id' => $userId]
                )
                : $wpdb->insert("{$this->prefix}event_registrations", $data);

            if ($written === false) {
                throw new RuntimeException('Inscription impossible, réessayez.');
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage());
        }

        $position = $status === 'waiting' ? $this->waitingPosition($eventId, $userId) : 0;

        Audit::log('event.registered', 'event', $eventId, ['status' => $status], $userId);

        Mailer::toUser(
            $status === 'confirmed' ? EmailTemplates::EVENT_REGISTERED : EmailTemplates::EVENT_WAITING,
            $userId,
            self::eventVariables($event) + ['position' => (string) $position],
            ['entity_type' => 'event', 'entity_id' => $eventId]
        );

        return ['status' => $status, 'position' => $position];
    }

    /**
     * Désinscription, libre jusqu'au départ (décision du club).
     *
     * La place libérée promeut le premier en liste d'attente, sauf trop près du
     * départ : promouvoir quelqu'un qui ne lira pas son message à temps crée une
     * place fantôme.
     */
    public function cancel(int $eventId, int $userId, int $promoteCutoffHours = 12): ?int
    {
        global $wpdb;

        $wpdb->update(
            "{$this->prefix}event_registrations",
            ['status' => 'cancelled', 'cancelled_at' => current_time('mysql')],
            ['event_id' => $eventId, 'user_id' => $userId]
        );

        Audit::log('event.cancelled', 'event', $eventId, [], $userId);

        $event = $this->find($eventId);

        Mailer::toUser(
            EmailTemplates::EVENT_CANCELLED,
            $userId,
            self::eventVariables($event),
            ['entity_type' => 'event', 'entity_id' => $eventId]
        );

        return $this->promoteNextInLine($eventId, $promoteCutoffHours);
    }

    /**
     * Donne la place libérée au premier de la liste d'attente.
     *
     * Séparé de `cancel()` parce qu'une place peut se libérer sans
     * désinscription : quand le compte de l'inscrit est supprimé, son
     * inscription disparaît avec lui (cf. [MemberPurge]). La place doit repartir
     * de la même façon, sinon elle reste vide pendant qu'une liste d'attente
     * patiente.
     *
     * @return int|null identifiant du membre promu, null si personne ne l'est
     */
    public function promoteNextInLine(int $eventId, int $promoteCutoffHours = 12): ?int
    {
        global $wpdb;

        $event = $this->find($eventId);

        if ($event === null) {
            return null;
        }

        $hoursLeft = (strtotime((string) $event['starts_at']) - time()) / 3600;

        if ($hoursLeft < $promoteCutoffHours) {
            return null; // Le DP décide : la place est signalée, pas attribuée.
        }

        $next = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->prefix}event_registrations
             WHERE event_id = %d AND status = 'waiting'
             ORDER BY registered_at ASC LIMIT 1",
            $eventId
        ), ARRAY_A);

        if (!$next) {
            return null;
        }

        $wpdb->update(
            "{$this->prefix}event_registrations",
            ['status' => 'confirmed'],
            ['id' => (int) $next['id']]
        );

        Audit::log('event.promoted', 'event', $eventId, ['user_id' => (int) $next['user_id']]);

        Mailer::toUser(
            EmailTemplates::EVENT_PROMOTED,
            (int) $next['user_id'],
            self::eventVariables($event),
            ['entity_type' => 'event', 'entity_id' => $eventId]
        );

        return (int) $next['user_id'];
    }

    /**
     * Liste **sociale**, destinée aux autres membres.
     *
     * À ne surtout pas confondre avec `participants()`, qui expose au directeur
     * de plongée le téléphone, le contact d'urgence, l'état médical et le
     * représentant légal d'un mineur. Rien de tout cela ici : un membre voit
     * ses camarades de sortie, pas leur dossier.
     *
     * Ce qui est montré — nom, niveau, statut, et la note que l'inscrit a
     * choisi de partager — sert à s'organiser entre plongeurs : choisir un
     * binôme au bon niveau, savoir qui fête un passage de brevet. Chaque champ
     * est un choix délibéré ; on n'ajoute rien « parce que c'est disponible ».
     *
     * @return list<array{name: string, level: string, status: string, note: string, conviviality: bool, is_self: bool}>
     */
    public function socialParticipants(int $eventId, int $viewerId = 0): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.user_id, r.status, r.details, u.display_name
             FROM {$this->prefix}event_registrations r
             LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
             WHERE r.event_id = %d AND r.status IN ('confirmed','waiting')
               AND r.user_id IS NOT NULL
             ORDER BY FIELD(r.status,'confirmed','waiting'), r.registered_at ASC",
            $eventId
        ), ARRAY_A) ?: [];

        $people = [];

        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];

            // Filet de sécurité. Une inscription détachée est déjà écartée par
            // la requête ; celle-ci rattrape un identifiant devenu invalide
            // autrement — restauration partielle, import repris à la main.
            // Sans elle, le compteur ment et une ligne vide s'affiche.
            if ($row['display_name'] === null) {
                continue;
            }

            $level   = \Subalcatel\Club\Identity\DiveLevels::forUser($userId);
            $details = json_decode((string) $row['details'], true);
            $name    = trim((string) $row['display_name']);

            $people[] = [
                'name'         => $name !== '' ? $name : 'Membre du club',
                'level'        => $level?->name ?? '',
                'status'       => (string) $row['status'],
                'note'         => is_array($details) ? trim((string) ($details['shared_note'] ?? '')) : '',
                'conviviality' => is_array($details) && ($details['conviviality'] ?? '') === '1',
                'is_self'      => $userId === $viewerId,
            ];
        }

        return $people;
    }

    /**
     * Liste destinée au directeur de plongée.
     *
     * Ce que le DP voit : le niveau, le téléphone, le contact d'urgence et la
     * VALIDITÉ du certificat — jamais le document lui-même.
     *
     * @return list<array<string, mixed>>
     */
    public function participants(int $eventId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, u.display_name, u.user_email
             FROM {$this->prefix}event_registrations r
             LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
             WHERE r.event_id = %d AND r.status IN ('confirmed','waiting')
               AND r.user_id IS NOT NULL
             ORDER BY FIELD(r.status,'confirmed','waiting'), r.registered_at ASC",
            $eventId
        ), ARRAY_A) ?: [];

        foreach ($rows as &$row) {
            $userId              = (int) $row['user_id'];
            $level               = \Subalcatel\Club\Identity\DiveLevels::forUser($userId);
            $row['level']        = $level?->name ?? '—';
            $row['phone']        = (string) get_user_meta($userId, 'sub_phone', true);
            $row['emergency']    = (string) get_user_meta($userId, 'sub_emergency_contact', true);
            $row['medical_ok']   = $this->policy->hasValidDocuments($userId)->allowed;

            // Un mineur en sortie engage le club différemment : le directeur de
            // plongée doit le savoir, et pouvoir joindre le représentant légal.
            $guardian            = \Subalcatel\Club\Identity\LegalGuardian::of($userId);
            $row['is_minor']     = \Subalcatel\Club\Identity\LegalGuardian::isMinor($userId);
            $row['guardian']     = $guardian === null
                ? ''
                : trim($guardian['name'] . ' — ' . $guardian['phone']);
        }

        return $rows;
    }

    /**
     * Message de l'organisateur aux inscrits.
     *
     * Toujours déclenché par une action explicite, jamais automatiquement, et
     * tracé : événement, expéditeur, objet, destinataires. Sans trace, personne
     * ne sait si l'information est partie.
     *
     * @return array{recipients: int, sent: int}
     */
    /**
     * Statut d'inscription d'un membre, ou null s'il n'est pas inscrit.
     *
     * Une inscription annulée compte pour absente : c'est bien ce que le membre
     * a voulu dire en se désinscrivant.
     */
    public function registrationStatus(int $eventId, int $userId): ?string
    {
        global $wpdb;

        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$this->prefix}event_registrations
             WHERE event_id = %d AND user_id = %d",
            $eventId,
            $userId
        ));

        return ($status === null || $status === 'cancelled') ? null : (string) $status;
    }

    /**
     * Un inscrit écrit au directeur de plongée.
     *
     * Le sens inverse de `messageParticipants`, et il manquait : une fois
     * inscrit, on a toujours une question — un horaire, un covoiturage, une
     * contrainte à signaler. Faute de ce chemin, elle partait par SMS à qui
     * avait le numéro, ou nulle part.
     *
     * L'adresse de l'organisateur n'est jamais montrée : le message part du
     * site, avec l'inscrit en adresse de réponse. Répondre suffit, et personne
     * n'a eu à publier son courriel.
     */
    public function messageOrganizer(int $eventId, string $message, int $actorId): bool
    {
        $event = $this->find($eventId);

        if ($event === null) {
            throw new RuntimeException('Événement introuvable.');
        }

        $organizerId = (int) ($event['organizer_id'] ?? 0);

        if ($organizerId === 0) {
            throw new RuntimeException('Cette sortie n’a pas d’organisateur désigné.');
        }

        // Réservé aux inscrits : sans cette condition, le formulaire devient
        // un moyen d'écrire à n'importe quel encadrant depuis n'importe quel
        // compte, sortie après sortie.
        if ($this->registrationStatus($eventId, $actorId) === null) {
            throw new RuntimeException('Seuls les inscrits peuvent écrire au directeur de plongée.');
        }

        if (trim($message) === '') {
            throw new RuntimeException('Le message est vide.');
        }

        $sender = get_userdata($actorId);

        if (!$sender instanceof \WP_User) {
            throw new RuntimeException('Expéditeur introuvable.');
        }

        $sent = Mailer::toUser(
            EmailTemplates::EVENT_TO_ORGANIZER,
            $organizerId,
            self::eventVariables($event) + [
                'message'            => $message,
                'expediteur'         => $sender->display_name,
                'adresse_expediteur' => $sender->user_email,
            ],
            ['entity_type' => 'event', 'entity_id' => $eventId, 'sender_id' => $actorId],
            [sprintf('Reply-To: %s <%s>', $sender->display_name, $sender->user_email)]
        );

        Audit::log('event.organizer_contacted', 'event', $eventId, ['sent' => $sent], $actorId);

        return $sent;
    }

    public function messageParticipants(
        int $eventId,
        string $subject,
        string $message,
        int $actorId,
        bool $includeWaiting = true,
    ): array {
        $event = $this->find($eventId);

        if ($event === null) {
            throw new RuntimeException('Événement introuvable.');
        }

        if (!user_can($actorId, 'sub_communicate_event_participants')
            && (int) $event['organizer_id'] !== $actorId) {
            throw new RuntimeException('Vous n’avez pas le droit d’écrire aux inscrits de cet événement.');
        }

        if (trim($subject) === '' || trim($message) === '') {
            throw new RuntimeException('L’objet et le message sont obligatoires.');
        }

        $sender     = get_userdata($actorId);
        $recipients = [];

        foreach ($this->participants($eventId) as $person) {
            if (!$includeWaiting && $person['status'] === 'waiting') {
                continue;
            }

            $recipients[] = (int) $person['user_id'];
        }

        $sent = Mailer::toUsers(
            EmailTemplates::EVENT_MESSAGE,
            $recipients,
            self::eventVariables($event) + [
                'objet'      => $subject,
                'message'    => $message,
                'expediteur' => $sender?->display_name ?? '',
            ],
            ['entity_type' => 'event', 'entity_id' => $eventId, 'sender_id' => $actorId]
        );

        Audit::log('event.message_sent', 'event', $eventId, [
            'subject'    => $subject,
            'recipients' => count($recipients),
            'sent'       => $sent,
        ], $actorId);

        // On distingue les destinataires des envois réussis : un directeur de
        // plongée qui croit avoir prévenu son groupe alors que rien n'est parti
        // découvrirait le problème au bord de l'eau.
        return ['recipients' => count($recipients), 'sent' => $sent];
    }

    // ---------------------------------------------------------------- Annonce

    /** Les membres dont le niveau permet l'inscription. */
    public const AUDIENCE_ELIGIBLE = 'eligible';

    /** Tous les adhérents à jour, quel que soit leur niveau. */
    public const AUDIENCE_MEMBERS = 'members';

    /**
     * Publics annonçables, pour les listes déroulantes.
     *
     * @return array<string, string>
     */
    public static function audiences(): array
    {
        return [
            self::AUDIENCE_ELIGIBLE => 'Les membres qui peuvent s’inscrire',
            self::AUDIENCE_MEMBERS  => 'Tous les adhérents à jour',
        ];
    }

    /**
     * Qui recevra l'annonce de cet événement.
     *
     * Publique parce que l'écran affiche l'effectif avant l'envoi : un
     * organisateur doit savoir s'il écrit à trois personnes ou à cent trente
     * avant de cliquer, pas après.
     *
     * Quatre filtres, et le motif de chacun :
     *
     * - **adhésion à jour** — la liste part de `MailingLists::ACTIVE`, déjà
     *   calculée ailleurs. Annoncer une sortie à qui ne peut pas s'inscrire
     *   faute d'adhésion, c'est écrire à un ancien.
     * - **compte validé** — un compte en attente n'est pas encore entré au club.
     * - **annonces acceptées** — le membre a pu dire stop sur son profil.
     * - **niveau** (public « éligible » seulement) — la règle exacte de
     *   l'inscription, empruntée à `EligibilityPolicy` et non réécrite.
     *
     * Ce qui n'est **pas** filtré, volontairement : la validité des documents.
     * Un certificat périmé se renouvelle en une semaine, et c'est précisément
     * l'annonce d'une sortie qui pousse à le faire. L'exclure reviendrait à
     * garder le silence avec ceux qu'il faut relancer.
     *
     * Les inscrits sont écartés : ils ont déjà leur confirmation, et
     * `messageParticipants` est le chemin pour leur écrire.
     *
     * @return list<int>
     */
    public function announcementRecipients(
        int $eventId,
        string $audience = self::AUDIENCE_ELIGIBLE,
        int $excludeUserId = 0,
    ): array {
        global $wpdb;

        $event = $this->find($eventId);

        if ($event === null) {
            return [];
        }

        $levels = (array) (json_decode((string) $event['accepted_levels'], true) ?: []);

        $registered = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$this->prefix}event_registrations
             WHERE event_id = %d AND status IN ('confirmed','waiting') AND user_id IS NOT NULL",
            $eventId
        )) ?: []);

        $recipients = [];

        foreach (MailingLists::members(MailingLists::ACTIVE) as $userId) {
            if ($userId === $excludeUserId || in_array($userId, $registered, true)) {
                continue;
            }

            if (!$this->policy->hasApprovedAccount($userId)->allowed) {
                continue;
            }

            if (!Subscriptions::wantsEventAnnouncements($userId)) {
                continue;
            }

            if ($audience === self::AUDIENCE_ELIGIBLE
                && !$this->policy->meetsDiveLevel($userId, $levels)->allowed) {
                continue;
            }

            $user = get_userdata($userId);

            if (!$user instanceof \WP_User || !is_email($user->user_email)) {
                continue;
            }

            $recipients[] = $userId;
        }

        return array_values(array_unique($recipients));
    }

    /**
     * Annonce l'événement aux membres qu'il concerne.
     *
     * Jamais automatique : appelé par une case cochée à l'enregistrement, ou par
     * le bouton de l'écran de la sortie. La distinction n'est pas cosmétique —
     * un envoi déclenché par la seule création produirait, un jour de
     * planification, dix messages en dix minutes à cent trente personnes.
     *
     * @param string $note Mot de l'organisateur, facultatif.
     * @return array{recipients: int, sent: int}
     */
    public function announce(
        int $eventId,
        int $actorId,
        string $audience = self::AUDIENCE_ELIGIBLE,
        string $note = '',
    ): array {
        $event = $this->find($eventId);

        if ($event === null) {
            throw new RuntimeException('Événement introuvable.');
        }

        // Même règle que pour écrire aux inscrits : l'organisateur de la sortie,
        // ou celui à qui le bureau a donné le droit.
        if (!user_can($actorId, 'sub_communicate_event_participants')
            && (int) $event['organizer_id'] !== $actorId) {
            throw new RuntimeException('Vous n’avez pas le droit d’annoncer cet événement.');
        }

        if (!array_key_exists($audience, self::audiences())) {
            throw new RuntimeException('Public d’annonce inconnu.');
        }

        // Annoncer une sortie déjà partie n'informe personne et discrédite les
        // suivantes. Le cas se produit à la saisie rétroactive d'un compte rendu.
        if ((string) $event['starts_at'] < current_time('mysql')) {
            throw new RuntimeException('Cet événement est passé : il n’y a plus rien à annoncer.');
        }

        $recipients = $this->announcementRecipients($eventId, $audience, $actorId);

        if ($recipients === []) {
            throw new RuntimeException(
                'Aucun destinataire : personne n’est à la fois adhérent à jour, '
                . 'au niveau requis et non déjà inscrit.'
            );
        }

        $organizer = get_userdata((int) $event['organizer_id']);

        $sent = Mailer::toUsers(
            EmailTemplates::EVENT_ANNOUNCEMENT,
            $recipients,
            self::eventVariables($event) + [
                'places'       => self::placesLabel($event, $this->confirmedCount($eventId)),
                'niveau'       => $this->policy->requirementLabel(
                    (array) (json_decode((string) $event['accepted_levels'], true) ?: [])
                ),
                'description'  => wp_strip_all_tags((string) ($event['description'] ?? '')),
                'mot'          => $note,
                'organisateur' => $organizer?->display_name ?? (string) get_bloginfo('name'),
                'lien'         => self::agendaUrl($eventId),
            ],
            ['entity_type' => 'event', 'entity_id' => $eventId, 'sender_id' => $actorId]
        );

        Audit::log('event.announced', 'event', $eventId, [
            'audience'   => $audience,
            'recipients' => count($recipients),
            'sent'       => $sent,
        ], $actorId);

        return ['recipients' => count($recipients), 'sent' => $sent];
    }

    /**
     * Date de la dernière annonce partie pour cet événement, si elle existe.
     */
    public function lastAnnouncedAt(int $eventId): ?string
    {
        return Mailer::lastSentAt(EmailTemplates::EVENT_ANNOUNCEMENT, 'event', $eventId);
    }

    /**
     * « 4 places restantes », « 12 places », « places illimitées ».
     *
     * @param array<string, mixed> $event
     */
    private static function placesLabel(array $event, int $confirmed): string
    {
        $capacity = (int) $event['capacity'];

        if ($capacity <= 0) {
            return 'sans limite';
        }

        $left = max(0, $capacity - $confirmed);

        return $left === 0
            ? sprintf('%d, complet — inscription en liste d’attente', $capacity)
            : sprintf('%d restante%s sur %d', $left, $left > 1 ? 's' : '', $capacity);
    }

    /**
     * Lien vers la sortie dans l'agenda de l'espace membre.
     *
     * L'agenda n'a pas de page par événement : chaque bloc porte une ancre, et
     * c'est elle qu'on vise. Si la page n'existe pas — installation partielle —
     * on renvoie l'accueil plutôt qu'un lien mort.
     */
    private static function agendaUrl(int $eventId): string
    {
        $agenda = Pages::url(Pages::AGENDA);

        return $agenda === ''
            ? home_url('/')
            : $agenda . '#sub-event-' . $eventId;
    }

    /**
     * @param array<string, mixed>|null $event
     * @return array<string, string>
     */
    private static function eventVariables(?array $event): array
    {
        if ($event === null) {
            return ['evenement' => '', 'date' => '', 'lieu' => ''];
        }

        $ts = strtotime((string) $event['starts_at']);

        return [
            'evenement' => (string) $event['title'],
            'date'      => $ts === false ? '' : wp_date('l j F Y à H\hi', $ts),
            'lieu'      => (string) ($event['location'] ?: '—'),
        ];
    }

    public function confirmedCount(int $eventId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->prefix}event_registrations
             WHERE event_id = %d AND status = 'confirmed'",
            $eventId
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $eventId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->prefix}events WHERE id = %d", $eventId),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function upcoming(int $limit = 20): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->prefix}events
             WHERE status = 'published' AND starts_at >= %s
             ORDER BY starts_at ASC LIMIT %d",
            current_time('mysql'),
            $limit
        ), ARRAY_A) ?: [];
    }

    /**
     * Type d'un événement, avec ses règles de formulaire.
     *
     * @return array<string, mixed>|null
     */
    public function typeOf(int $eventId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT t.* FROM {$this->prefix}event_types t
             INNER JOIN {$this->prefix}events e ON e.type_id = t.id
             WHERE e.id = %d",
            $eventId
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function typeBySlug(string $slug): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->prefix}event_types WHERE slug = %s", $slug),
            ARRAY_A
        );

        return $row ?: null;
    }

    private function waitingPosition(int $eventId, int $userId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->prefix}event_registrations
             WHERE event_id = %d AND status = 'waiting'
               AND registered_at <= (
                   SELECT registered_at FROM {$this->prefix}event_registrations
                   WHERE event_id = %d AND user_id = %d
               )",
            $eventId,
            $eventId,
            $userId
        ));
    }

    private function uniqueSlug(string $title): string
    {
        global $wpdb;

        $base = sanitize_title($title);
        $slug = $base;
        $i    = 2;

        while ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->prefix}events WHERE slug = %s", $slug))) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
