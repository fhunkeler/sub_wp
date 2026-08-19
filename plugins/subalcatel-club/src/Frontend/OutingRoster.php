<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Events\RegistrationFields;

/**
 * Mes sorties organisées : shortcode [subalcatel_mes_sorties_organisees].
 *
 * Le pendant de [OutingForm]. Un directeur de plongée qui a ouvert une sortie
 * depuis l'espace membre doit pouvoir l'exploiter au même endroit : savoir qui
 * vient, avec quel niveau, à quel numéro le joindre, qui prévenir en cas de
 * pépin, et repartir avec une feuille d'émargement dans la poche. Jusqu'ici
 * tout cela n'existait que dans `Club → Événements → Inscrits`, c'est-à-dire
 * dans un écran qu'un encadrant bénévole n'ouvre jamais.
 *
 * Deux vues, une seule page : la liste de ses sorties, puis le détail de l'une
 * d'elles (`?sortie=ID`).
 *
 * Aucune règle n'est réécrite : la liste des inscrits, l'envoi du message et la
 * désinscription passent par [EventService], qui porte déjà les contrôles de
 * droits. Ce fichier est une IHM — plus une garde de propriété, parce qu'une
 * page publique se visite avec n'importe quel identifiant dans l'URL.
 *
 * Ce qui n'y figure pas, volontairement : le contenu des documents. Comme dans
 * l'écran d'administration, l'organisateur lit une VALIDITÉ — « à jour », « à
 * vérifier » — jamais le certificat médical lui-même.
 */
final class OutingRoster
{
    /** Paramètre d'URL désignant la sortie ouverte. */
    private const ARG = 'sortie';

    public static function register(): void
    {
        add_shortcode('subalcatel_mes_sorties_organisees', [self::class, 'render']);
        add_action('admin_post_sub_outing_message', [self::class, 'handleMessage']);
        add_action('admin_post_sub_outing_announce', [self::class, 'handleAnnounce']);
        add_action('admin_post_sub_outing_unregister', [self::class, 'handleUnregister']);
    }

    public static function render(): string
    {
        if (!is_user_logged_in()) {
            return sprintf(
                '<div class="sub-notice"><strong>Espace réservé aux membres</strong>'
                . '<p><a href="%s">Se connecter</a></p></div>',
                esc_url(wp_login_url(get_permalink()))
            );
        }

        wp_enqueue_style(
            'subalcatel-membership',
            \Subalcatel\Club\PLUGIN_URL . 'assets/css/membership.css',
            [],
            \Subalcatel\Club\VERSION
        );

        $userId  = get_current_user_id();
        $eventId = isset($_GET[self::ARG]) ? absint($_GET[self::ARG]) : 0;

        ob_start();

        echo '<div class="sub-organised">';

        echo Notice::fromQuery('sub-noprint'); // déjà échappé

        if ($eventId > 0) {
            self::renderRoster($eventId, $userId);
        } else {
            self::renderIndex($userId);
        }

        echo '</div>';

        return (string) ob_get_clean();
    }

    // ------------------------------------------------------------ Liste

    private static function renderIndex(int $userId): void
    {
        $upcoming = self::organisedBy($userId, true);
        $past     = self::organisedBy($userId, false);

        if ($upcoming === [] && $past === []) {
            echo '<div class="sub-notice"><strong>Aucune sortie à votre nom</strong>'
                . '<p>Vous n’avez encore organisé aucune sortie.';

            if (Pages::exists(Pages::NEW_OUTING)) {
                printf(
                    ' <a href="%s">En proposer une</a>.',
                    esc_url(Pages::url(Pages::NEW_OUTING))
                );
            }

            echo '</p></div>';

            return;
        }

        if ($upcoming !== []) {
            self::renderIndexSection('À venir', $upcoming);
        }

        if ($past !== []) {
            self::renderIndexSection('Sorties passées', $past);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function renderIndexSection(string $title, array $rows): void
    {
        ?>
        <section class="sub-block">
            <h2 class="sub-block__title"><?php echo esc_html($title); ?></h2>

            <ul class="sub-list">
                <?php foreach ($rows as $row) : ?>
                    <?php
                    $capacity = (int) $row['capacity'];
                    $waiting  = (int) $row['waiting'];
                    ?>
                    <li class="sub-list__item">
                        <span class="sub-list__main">
                            <strong><?php echo esc_html((string) $row['title']); ?></strong><br>
                            <?php echo esc_html(MemberDashboard::frDateTime((string) $row['starts_at'])); ?>
                            <?php if (!empty($row['location'])) : ?>
                                — <?php echo esc_html((string) $row['location']); ?>
                            <?php endif; ?>
                            <br>
                            <span class="sub-organised__count">
                                <?php
                                $confirmed = (int) $row['confirmed'];

                                echo esc_html(sprintf(
                                    '%d inscrit%s%s%s',
                                    $confirmed,
                                    $confirmed > 1 ? 's' : '',
                                    $capacity > 0 ? sprintf(' sur %d places', $capacity) : '',
                                    $waiting > 0 ? sprintf(', %d en attente', $waiting) : ''
                                ));
                                ?>
                            </span>
                        </span>
                        <a class="sub-button sub-button--small"
                           href="<?php echo esc_url(self::rosterUrl((int) $row['id'])); ?>">
                            Liste des inscrits
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php
    }

    // ------------------------------------------------------------ Détail

    private static function renderRoster(int $eventId, int $userId): void
    {
        $service = new EventService();
        $event   = $service->find($eventId);

        // Une seule formulation pour « elle n'existe pas » et « elle n'est pas à
        // vous » : distinguer les deux dirait à un curieux quels identifiants
        // correspondent à une vraie sortie.
        if ($event === null || (int) $event['organizer_id'] !== $userId) {
            printf(
                '<div class="sub-notice sub-notice--error"><strong>Sortie introuvable</strong>'
                . '<p>Cette sortie n’existe pas, ou vous n’en êtes pas l’organisateur. '
                . '<a href="%s">Revenir à mes sorties</a>.</p></div>',
                esc_url(self::indexUrl())
            );

            return;
        }

        $participants = $service->participants($eventId);
        $type         = $service->typeOf($eventId);
        ?>
        <p class="sub-organised__back sub-noprint">
            <a href="<?php echo esc_url(self::indexUrl()); ?>">← Mes sorties organisées</a>
        </p>

        <p class="sub-organised__actions sub-noprint">
            <button type="button" class="sub-button sub-button--small" onclick="window.print();">
                Imprimer la feuille d’émargement
            </button>
        </p>

        <?php self::renderAnnounceForm($service, $event, $eventId); ?>

        <?php if ($participants !== []) : ?>
            <?php self::renderMessageForm($eventId); ?>
        <?php endif; ?>

        <div class="sub-roster-sheet">
            <h2 class="sub-roster__title"><?php echo esc_html((string) $event['title']); ?></h2>
            <p class="sub-roster__meta">
                <?php echo esc_html(MemberDashboard::frDateTime((string) $event['starts_at'])); ?>
                <?php if (!empty($event['location'])) : ?>
                    — <?php echo esc_html((string) $event['location']); ?>
                <?php endif; ?>
            </p>

            <?php if ($participants === []) : ?>
                <p class="sub-roster__empty">Personne n’est inscrit pour l’instant.</p>
            <?php else : ?>
                <table class="sub-roster">
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Participant</th>
                            <th scope="col">Niveau</th>
                            <th scope="col">Téléphone</th>
                            <th scope="col">Personne à prévenir</th>
                            <th scope="col">Documents</th>
                            <th scope="col">État</th>
                            <th scope="col">Besoins</th>
                            <th scope="col" class="sub-roster__sign">Émargement</th>
                            <th scope="col" class="sub-noprint"><span class="screen-reader-text">Action</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($participants as $i => $person) : ?>
                            <?php self::renderParticipantRow($eventId, $i + 1, $person, $type); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p class="sub-roster__legal">
                La colonne « Documents » dit si le certificat médical et la licence sont
                valides. Leur contenu n’est accessible ni depuis cette page, ni depuis
                aucune autre : seul le bureau y a accès.
            </p>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed>      $person
     * @param array<string, mixed>|null $type
     */
    private static function renderParticipantRow(int $eventId, int $rank, array $person, ?array $type): void
    {
        $answers = (array) (json_decode((string) ($person['details'] ?? ''), true) ?: []);
        $summary = RegistrationFields::summarise($answers, $type);
        $phone   = trim((string) $person['phone']);
        ?>
        <tr>
            <td data-label="#"><?php echo (int) $rank; ?></td>
            <td data-label="Participant">
                <strong><?php echo esc_html((string) ($person['display_name'] ?: '—')); ?></strong><br>
                <span class="sub-roster__mail"><?php echo esc_html((string) $person['user_email']); ?></span>
            </td>
            <td data-label="Niveau">
                <?php echo esc_html((string) $person['level']); ?>
                <?php if (!empty($person['is_minor'])) : ?>
                    <br><span class="sub-roster__minor">mineur</span>
                <?php endif; ?>
            </td>
            <td data-label="Téléphone">
                <?php if ($phone === '') : ?>
                    —
                <?php else : ?>
                    <?php // Lu sur un téléphone, au bord de l'eau : le numéro s'appelle. ?>
                    <a href="<?php echo esc_url('tel:' . preg_replace('/[^0-9+]/', '', $phone)); ?>">
                        <?php echo esc_html($phone); ?>
                    </a>
                <?php endif; ?>
            </td>
            <td data-label="Personne à prévenir">
                <?php echo esc_html((string) ($person['emergency'] ?: '—')); ?>
                <?php if (!empty($person['guardian'])) : ?>
                    <br><small>Représentant légal : <?php echo esc_html((string) $person['guardian']); ?></small>
                <?php endif; ?>
            </td>
            <td data-label="Documents">
                <?php // La VALIDITÉ, jamais le document. ?>
                <span class="sub-roster__doc <?php echo $person['medical_ok']
                    ? 'sub-roster__doc--ok'
                    : 'sub-roster__doc--ko'; ?>">
                    <?php echo $person['medical_ok'] ? 'À jour' : 'À vérifier'; ?>
                </span>
            </td>
            <td data-label="État">
                <span class="sub-pill <?php echo $person['status'] === 'waiting'
                    ? 'sub-pill--waiting'
                    : 'sub-pill--ok'; ?>">
                    <?php echo $person['status'] === 'waiting' ? 'Liste d’attente' : 'Inscrit'; ?>
                </span>
            </td>
            <td data-label="Besoins">
                <?php if ($summary === []) : ?>
                    —
                <?php else : ?>
                    <ul class="sub-roster__needs">
                        <?php foreach ($summary as $line) : ?>
                            <li><?php echo esc_html($line); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if (!empty($answers['comment'])) : ?>
                    <p class="sub-roster__comment">« <?php echo esc_html((string) $answers['comment']); ?> »</p>
                <?php endif; ?>
            </td>
            <td class="sub-roster__sign"></td>
            <td data-label="Action" class="sub-noprint">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                      onsubmit="return confirm('Retirer cette personne de la liste ?');">
                    <input type="hidden" name="action" value="sub_outing_unregister">
                    <input type="hidden" name="event_id" value="<?php echo esc_attr((string) $eventId); ?>">
                    <input type="hidden" name="user_id" value="<?php echo esc_attr((string) (int) $person['user_id']); ?>">
                    <?php wp_nonce_field('sub_outing_unregister_' . $eventId); ?>
                    <button class="sub-button sub-button--ghost sub-button--small">Désinscrire</button>
                </form>
            </td>
        </tr>
        <?php
    }

    /**
     * Écrire aux inscrits.
     *
     * Replié : ce qu'on vient chercher ici, c'est d'abord la liste. Le message
     * part à l'initiative de l'organisateur, jamais tout seul, et il est tracé —
     * `EventService::messageParticipants` s'en charge, y compris du contrôle de
     * droit, qu'on ne redit pas ici.
     */
    private static function renderMessageForm(int $eventId): void
    {
        ?>
        <details class="sub-organised__message sub-noprint">
            <summary>Écrire aux inscrits</summary>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  class="sub-outing">
                <input type="hidden" name="action" value="sub_outing_message">
                <input type="hidden" name="event_id" value="<?php echo esc_attr((string) $eventId); ?>">
                <?php wp_nonce_field('sub_outing_message_' . $eventId); ?>

                <p class="sub-input">
                    <label for="sub_roster_subject">Objet</label>
                    <input type="text" id="sub_roster_subject" name="subject" required
                           maxlength="150" placeholder="Rendez-vous 8h au parking">
                </p>
                <p class="sub-input">
                    <label for="sub_roster_message">Message</label>
                    <textarea id="sub_roster_message" name="message" rows="6" required></textarea>
                </p>
                <p class="sub-input">
                    <label class="sub-organised__check" for="sub_roster_waiting">
                        <input type="checkbox" id="sub_roster_waiting" name="include_waiting" value="1" checked>
                        Inclure la liste d’attente
                    </label>
                </p>

                <p class="sub-outing__submit"><button class="sub-button">Envoyer</button></p>

                <p class="sub-help">
                    Les destinataires ne se voient pas entre eux, et l’envoi est tracé dans
                    le journal du site.
                </p>
            </form>
        </details>
        <?php
    }

    /**
     * Annoncer la sortie aux membres qu'elle concerne.
     *
     * Le pendant de la case cochée à la publication, pour les deux cas qui font
     * la vie réelle d'une sortie : on a oublié de cocher, ou une place s'est
     * libérée trois jours avant. L'effectif est calculé et affiché avant
     * l'envoi — écrire à cent trente personnes ne doit pas être un geste
     * distrait.
     *
     * @param array<string, mixed> $event
     */
    private static function renderAnnounceForm(EventService $service, array $event, int $eventId): void
    {
        if ((string) $event['starts_at'] < current_time('mysql')) {
            return; // Une sortie passée ne s'annonce plus.
        }

        $count    = count($service->announcementRecipients($eventId, EventService::AUDIENCE_ELIGIBLE, get_current_user_id()));
        $lastSent = $service->lastAnnouncedAt($eventId);
        ?>
        <details class="sub-organised__message sub-noprint" <?php echo $lastSent === null ? 'open' : ''; ?>>
            <summary>Annoncer la sortie aux membres</summary>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  class="sub-outing"
                  onsubmit="return confirm('Envoyer l’annonce maintenant ?');">
                <input type="hidden" name="action" value="sub_outing_announce">
                <input type="hidden" name="event_id" value="<?php echo esc_attr((string) $eventId); ?>">
                <?php wp_nonce_field('sub_outing_announce_' . $eventId); ?>

                <?php if ($lastSent !== null) : ?>
                    <p class="sub-notice sub-notice--info">
                        Déjà annoncée le <?php echo esc_html(MemberDashboard::frDateTime($lastSent)); ?>.
                        Un second envoi se justifie quand quelque chose a changé — une place
                        libérée, un horaire déplacé —, pas pour relancer.
                    </p>
                <?php endif; ?>

                <p class="sub-input">
                    <label for="sub_announce_note">Mot d’accompagnement</label>
                    <textarea id="sub_announce_note" name="note" rows="3"
                              placeholder="Facultatif — le titre, la date, le lieu et la description sont déjà dans le message."></textarea>
                </p>

                <p class="sub-outing__submit">
                    <button class="sub-button" <?php disabled($count === 0); ?>>
                        <?php printf(
                            'Envoyer à %d membre%s',
                            (int) $count,
                            $count > 1 ? 's' : ''
                        ); ?>
                    </button>
                </p>

                <p class="sub-help">
                    L’annonce part aux adhérents à jour dont le niveau permet l’inscription,
                    sauf ceux qui l’ont refusée sur leur profil. Les personnes déjà inscrites
                    n’en reçoivent pas : elles ont leur confirmation. L’envoi est tracé dans
                    le journal du site.
                </p>
            </form>
        </details>
        <?php
    }

    // ------------------------------------------------------------ Actions

    public static function handleAnnounce(): void
    {
        $eventId = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;

        if (!is_user_logged_in() || !check_admin_referer('sub_outing_announce_' . $eventId)) {
            wp_die('Requête non autorisée.', 403);
        }

        try {
            // Le droit d'annoncer est vérifié par le service : organisateur de
            // la sortie, ou porteur de la capacité du bureau.
            $result = (new EventService())->announce(
                $eventId,
                get_current_user_id(),
                EventService::AUDIENCE_ELIGIBLE,
                sanitize_textarea_field(wp_unslash((string) ($_POST['note'] ?? '')))
            );
        } catch (\RuntimeException $e) {
            self::back($eventId, $e->getMessage(), true);
        }

        $failed = $result['recipients'] - $result['sent'];

        self::back(
            $eventId,
            $failed === 0
                ? sprintf('Annonce envoyée à %d membre(s).', $result['sent'])
                : sprintf(
                    'Envoi partiel : %d annonce(s) partie(s) sur %d. Prévenez le bureau.',
                    $result['sent'],
                    $result['recipients']
                ),
            $failed > 0
        );
    }

    public static function handleMessage(): void
    {
        $eventId = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;

        if (!is_user_logged_in() || !check_admin_referer('sub_outing_message_' . $eventId)) {
            wp_die('Requête non autorisée.', 403);
        }

        try {
            // Le droit d'écrire aux inscrits est vérifié par le service, qui le
            // porte déjà pour l'écran d'administration.
            $result = (new EventService())->messageParticipants(
                $eventId,
                sanitize_text_field(wp_unslash((string) ($_POST['subject'] ?? ''))),
                sanitize_textarea_field(wp_unslash((string) ($_POST['message'] ?? ''))),
                get_current_user_id(),
                isset($_POST['include_waiting'])
            );

            if ($result['recipients'] === 0) {
                self::back($eventId, 'Aucun destinataire : personne n’est inscrit à cette sortie.', true);
            }

            $failed = $result['recipients'] - $result['sent'];

            // Destinataires et envois réussis sont distingués : un organisateur
            // qui croit avoir prévenu son groupe alors que rien n'est parti ne
            // le découvrirait qu'au bord de l'eau.
            self::back(
                $eventId,
                $failed === 0
                    ? sprintf('Message envoyé à %d participant(s).', $result['sent'])
                    : sprintf(
                        'Envoi partiel : %d message(s) parti(s) sur %d. Prévenez le bureau, '
                        . 'et joignez les absents autrement.',
                        $result['sent'],
                        $result['recipients']
                    ),
                $failed > 0
            );
        } catch (\RuntimeException $e) {
            self::back($eventId, $e->getMessage(), true);
        }
    }

    public static function handleUnregister(): void
    {
        $eventId = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;

        if (!is_user_logged_in() || !check_admin_referer('sub_outing_unregister_' . $eventId)) {
            wp_die('Requête non autorisée.', 403);
        }

        $service = new EventService();
        $event   = $service->find($eventId);

        // Retirer quelqu'un d'une liste est une décision d'organisateur. Depuis
        // le site public, on n'accepte que celle-là : les gestionnaires ont
        // l'écran d'administration, qui trace la même action.
        if ($event === null || (int) $event['organizer_id'] !== get_current_user_id()) {
            wp_die('Accès refusé.', 403);
        }

        $userId = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;

        if ($userId === 0) {
            self::back($eventId, 'Participant introuvable.', true);
        }

        $service->cancel($eventId, $userId);
        self::back($eventId, 'Personne désinscrite. Elle en est informée par courriel.');
    }

    // ------------------------------------------------------------ Outils

    /**
     * Sorties dont ce membre est l'organisateur.
     *
     * @return list<array<string, mixed>>
     */
    private static function organisedBy(int $userId, bool $upcoming, int $limit = 30): array
    {
        global $wpdb;

        $p          = $wpdb->prefix . 'sub_';
        $comparison = $upcoming ? '>=' : '<';
        $direction  = $upcoming ? 'ASC' : 'DESC';

        // Les fragments interpolés ci-dessus sont choisis ici, jamais reçus :
        // les valeurs variables, elles, restent préparées.
        return $wpdb->get_results($wpdb->prepare(
            "SELECT e.id, e.title, e.location, e.starts_at, e.capacity,
                    (SELECT COUNT(*) FROM {$p}event_registrations r
                      WHERE r.event_id = e.id AND r.status = 'confirmed') AS confirmed,
                    (SELECT COUNT(*) FROM {$p}event_registrations r
                      WHERE r.event_id = e.id AND r.status = 'waiting') AS waiting
             FROM {$p}events e
             WHERE e.organizer_id = %d AND e.starts_at {$comparison} %s
             ORDER BY e.starts_at {$direction}
             LIMIT %d",
            $userId,
            current_time('mysql'),
            $limit
        ), ARRAY_A) ?: [];
    }

    /**
     * Ce membre organise-t-il quelque chose ?
     *
     * Sert au tableau de bord : le raccourci n'a de sens que pour qui a une
     * liste à consulter. Compte aussi les sorties passées — une feuille
     * d'émargement se relit après coup, et un membre dont le niveau a changé
     * garde ce qu'il a déjà ouvert.
     */
    public static function organisesAnything(int $userId): bool
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sub_events WHERE organizer_id = %d",
            $userId
        )) > 0;
    }

    private static function indexUrl(): string
    {
        return Pages::url(Pages::MY_OUTINGS) ?: home_url('/');
    }

    private static function rosterUrl(int $eventId): string
    {
        return add_query_arg(self::ARG, $eventId, self::indexUrl());
    }

    /**
     * Retour à la page, sur la bonne sortie, avec le message du jour.
     */
    private static function back(int $eventId, string $message, bool $isError = false): void
    {
        $base = wp_get_referer() ?: self::indexUrl();

        wp_safe_redirect(add_query_arg(
            [
                self::ARG                            => $eventId,
                $isError ? 'sub_error' : 'sub_done' => rawurlencode($message),
            ],
            remove_query_arg(['sub_done', 'sub_error'], $base)
        ));
        exit;
    }
}
