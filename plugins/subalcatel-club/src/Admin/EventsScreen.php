<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Events\RegistrationFields;
use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Notifications\EmailTemplates;
use Subalcatel\Club\Support\Audit;

/**
 * Événements : liste, création, et liste des inscrits pour le directeur de plongée.
 *
 * L'audit du Joomla a montré que c'est le module le plus utilisé du site —
 * 805 événements et 6 537 inscriptions. La liste des inscrits est l'écran le
 * plus consulté en saison : elle doit rester lisible sur un téléphone, au bord
 * de l'eau.
 */
final class EventsScreen
{
    public const SLUG         = 'subalcatel-events';
    public const SLUG_ROSTER  = 'subalcatel-event-roster';

    public static function register(): void
    {
        add_action('admin_post_sub_event_save', [self::class, 'handleSave']);
        add_action('admin_post_sub_event_delete', [self::class, 'handleDelete']);
        add_action('admin_post_sub_event_unregister', [self::class, 'handleUnregister']);
        add_action('admin_post_sub_event_message', [self::class, 'handleMessage']);
        add_action('admin_post_sub_event_announce', [self::class, 'handleAnnounce']);
    }

    /**
     * Types que l'utilisateur courant a le droit de créer.
     *
     * @return list<array<string, mixed>>
     */
    private static function creatableTypes(): array
    {
        return (new EventService())->creatableTypesFor(get_current_user_id());
    }

    /**
     * Dit ce qui manque, et pourquoi.
     *
     * Une liste déroulante qui omet silencieusement la moitié des types est un
     * piège : on cherche l'entrée « Plongée d'exploration », on ne la trouve
     * pas, et rien n'indique que c'est le niveau de plongée du compte — et non
     * un bug — qui la retire. Le cas typique est le compte d'administration du
     * site, qui a tous les droits WordPress et aucun niveau de plongée.
     */
    private static function renderMissingTypes(): void
    {
        global $wpdb;

        $userId    = get_current_user_id();
        $allowed   = array_column(self::creatableTypes(), 'slug');
        $all       = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sub_event_types ORDER BY name", ARRAY_A) ?: [];
        $missing   = [];

        foreach ($all as $type) {
            if (in_array((string) $type['slug'], $allowed, true)) {
                continue;
            }

            $missing[(string) $type['name']] = match (true) {
                !user_can($userId, (string) $type['create_capability']) => 'droit non accordé à votre rôle',
                (int) $type['requires_dive_leader'] === 1 => 'réservé aux directeurs de plongée',
                (int) $type['requires_autonomous'] === 1  => 'réservé aux plongeurs autonomes',
                default => 'condition non remplie',
            };
        }

        if ($missing === []) {
            return;
        }

        $level = \Subalcatel\Club\Identity\DiveLevels::forUser($userId);
        ?>
        <p class="description" style="margin-top:8px;">
            <strong>Types non proposés :</strong>
            <?php
            $parts = [];
            foreach ($missing as $name => $reason) {
                $parts[] = sprintf('%s (%s)', $name, $reason);
            }
            echo esc_html(implode(' · ', $parts));
            ?>
            <br>
            <?php if ($level === null) : ?>
                Votre compte n’a <strong>aucun niveau de plongée</strong> : le droit d’ouvrir une
                plongée en découle, pas du rôle WordPress. Renseignez-le sur votre profil, ou
                créez la sortie depuis le compte du directeur de plongée concerné.
            <?php else : ?>
                Votre niveau est <?php echo esc_html($level->name); ?>. Les droits « autonome » et
                « directeur de plongée » se règlent niveau par niveau dans Club&nbsp;→&nbsp;Réglages.
            <?php endif; ?>
        </p>
        <?php
    }

    public static function render(): void
    {
        AdminUi::enqueue();
        AdminUi::flash();

        global $wpdb;
        $p = $wpdb->prefix . 'sub_';

        // L'état d'annonce se lit dans le journal des envois : une colonne de
        // plus sur les événements dirait la même chose et pourrait mentir.
        $events = $wpdb->get_results($wpdb->prepare(
            "SELECT e.*, t.name AS type_name,
                    (SELECT COUNT(*) FROM {$p}event_registrations r
                      WHERE r.event_id = e.id AND r.status = 'confirmed') AS confirmed,
                    (SELECT COUNT(*) FROM {$p}event_registrations r
                      WHERE r.event_id = e.id AND r.status = 'waiting') AS waiting,
                    (SELECT MAX(n.sent_at) FROM {$wpdb->prefix}sub_notification_log n
                      WHERE n.entity_type = 'event' AND n.entity_id = e.id
                        AND n.template_code = %s AND n.status = 'sent') AS announced_at
             FROM {$p}events e
             LEFT JOIN {$p}event_types t ON t.id = e.type_id
             ORDER BY e.starts_at DESC
             LIMIT 100",
            EmailTemplates::EVENT_ANNOUNCEMENT
        ), ARRAY_A) ?: [];

        $types  = self::creatableTypes();
        $levels = DiveLevels::ordered();
        ?>
        <div class="wrap sub-admin">
            <h1>Événements</h1>

            <table class="wp-list-table widefat striped sub-cards" style="margin-top:16px;">
                <thead>
                    <tr>
                        <th>Événement</th>
                        <th style="width:170px;">Type</th>
                        <th style="width:180px;">Date</th>
                        <th style="width:130px;">Inscrits</th>
                        <th style="width:220px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($events === []) : ?>
                    <tr><td colspan="5">Aucun événement. Créez-en un ci-dessous.</td></tr>
                <?php endif; ?>

                <?php foreach ($events as $e) : ?>
                    <?php $past = $e['starts_at'] < current_time('mysql'); ?>
                    <tr<?php echo $past ? ' style="opacity:.6;"' : ''; ?>>
                        <td data-label="Événement">
                            <strong><?php echo esc_html((string) $e['title']); ?></strong>
                            <?php if (!empty($e['location'])) : ?>
                                <br><span style="color:#50575e;"><?php echo esc_html((string) $e['location']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($e['announced_at'])) : ?>
                                <br><span class="sub-tag">annoncée le
                                    <?php echo esc_html(self::frDateTime((string) $e['announced_at'])); ?></span>
                            <?php elseif (!$past) : ?>
                                <br><span style="color:#50575e;">pas encore annoncée</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Type"><?php echo esc_html((string) ($e['type_name'] ?? '—')); ?></td>
                        <td data-label="Date">
                            <?php echo esc_html(self::frDateTime((string) $e['starts_at'])); ?>
                            <?php if (!empty($e['ends_at'])) : ?>
                                <br><small>jusqu’au <?php echo esc_html(self::frDateTime((string) $e['ends_at'])); ?></small>
                            <?php endif; ?>
                        </td>
                        <td data-label="Inscrits" style="font-variant-numeric:tabular-nums;">
                            <?php
                            $capacity = (int) $e['capacity'];
                            printf(
                                '%d%s',
                                (int) $e['confirmed'],
                                $capacity > 0 ? ' / ' . $capacity : ''
                            );
                            if ((int) $e['waiting'] > 0) {
                                printf('<br><small>%d en attente</small>', (int) $e['waiting']);
                            }
                            ?>
                        </td>
                        <td data-label="Actions">
                            <a class="button button-primary"
                               href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG_ROSTER . '&event_id=' . (int) $e['id'])); ?>">
                                Liste des inscrits
                            </a>
                            <?php if (current_user_can('sub_manage_event_types')) : ?>
                                <?php AdminUi::actionButton(
                                    'sub_event_delete',
                                    ['event_id' => (int) $e['id']],
                                    'Supprimer',
                                    'button-link-delete button-link',
                                    'Supprimer cet événement et toutes ses inscriptions ?'
                                ); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($types === []) : ?>
                <div class="notice notice-info" style="margin-top:24px;">
                    <p>
                        Vous n’avez le droit de créer aucun type d’événement.
                        Les droits dépendent de votre fonction au bureau et de votre niveau de plongée.
                    </p>
                </div>
                <?php return; ?>
            <?php endif; ?>

            <h2 style="margin-top:32px;">Créer un événement</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sub-form">
                <input type="hidden" name="action" value="sub_event_save">
                <?php wp_nonce_field('sub_event_save'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Type</th>
                        <td>
                            <select name="type_slug" required>
                                <?php foreach ($types as $type) : ?>
                                    <option value="<?php echo esc_attr((string) $type['slug']); ?>">
                                        <?php echo esc_html((string) $type['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                Seuls les types que vous avez le droit de créer sont proposés.
                                L’événement copie les règles du type : elles ne changeront plus si le type évolue.
                            </p>
                            <?php self::renderMissingTypes(); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Titre</th>
                        <td><input type="text" name="title" class="regular-text" required placeholder="Exploration au Squewel"></td>
                    </tr>
                    <tr>
                        <th scope="row">Lieu</th>
                        <td><input type="text" name="location" class="regular-text" placeholder="Ploumanac’h — Pors Kamor"></td>
                    </tr>
                    <tr>
                        <th scope="row">Début</th>
                        <td><input type="datetime-local" name="starts_at" required></td>
                    </tr>
                    <tr>
                        <th scope="row">Fin</th>
                        <td>
                            <input type="datetime-local" name="ends_at">
                            <p class="description">À renseigner pour une sortie sur plusieurs jours.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Clôture des inscriptions</th>
                        <td>
                            <input type="datetime-local" name="registration_closes_at">
                            <p class="description">Par défaut, les inscriptions restent ouvertes jusqu’au départ.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Places</th>
                        <td>
                            <input type="number" name="capacity" class="small-text" min="0" value="0">
                            <p class="description">0 = pas de limite. Au-delà, les suivants passent en liste d’attente.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Niveau minimum</th>
                        <td>
                            <?php
                            // Deux listes et non des cases à cocher : le niveau
                            // est un rang, pas une case. Cocher « P1, P3, P5 »
                            // ne voulait rien dire de plus que « P1 » — mais
                            // laissait croire qu'un P2 était exclu.
                            // Un niveau sans rang de plongeur — « NAP », « P0 » —
                            // ne peut pas servir de plancher : l'exiger
                            // n'exclurait personne.
                            $divers   = array_filter($levels, static fn ($l): bool => DiveLevels::rankOf($l->term_id, DiveLevels::RANK_TEACHING) === 0
                                && DiveLevels::rankOf($l->term_id, DiveLevels::RANK_DIVER) > 0);
                            $teachers = array_filter($levels, static fn ($l): bool => DiveLevels::rankOf($l->term_id, DiveLevels::RANK_TEACHING) > 0);
                            ?>
                            <p>
                                <label>
                                    Plongeur&nbsp;:
                                    <select name="accepted_levels[]">
                                        <option value="">aucune exigence</option>
                                        <?php foreach ($divers as $level) : ?>
                                            <option value="<?php echo esc_attr($level->slug); ?>">
                                                <?php echo esc_html($level->name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </p>
                            <p>
                                <label>
                                    Encadrement&nbsp;:
                                    <select name="accepted_levels[]">
                                        <option value="">aucune exigence</option>
                                        <?php foreach ($teachers as $level) : ?>
                                            <option value="<?php echo esc_attr($level->slug); ?>">
                                                <?php echo esc_html($level->name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </p>
                            <p class="description">
                                Les niveaux supérieurs sont admis d’office : ouvrir au P2 ouvre aussi
                                aux P3, P4, P5 et à tous les brevets d’encadrement, qui les supposent.
                                Le second champ réserve la sortie aux encadrants — à ne remplir que
                                pour une sortie d’enseignement. Renseigner les deux ouvre aux uns
                                <em>ou</em> aux autres.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Description</th>
                        <td><textarea name="description" rows="4" class="large-text"></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row">Annonce</th>
                        <td>
                            <label>
                                <input type="checkbox" name="announce" value="1">
                                Prévenir par courriel les membres concernés
                            </label>

                            <p style="margin:8px 0 0;">
                                <label>
                                    Destinataires&nbsp;:
                                    <select name="audience">
                                        <?php foreach (EventService::audiences() as $key => $label) : ?>
                                            <option value="<?php echo esc_attr($key); ?>">
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </p>

                            <p style="margin:8px 0 0;">
                                <textarea name="note" rows="2" class="large-text"
                                          placeholder="Mot d’accompagnement, facultatif — « covoiturage possible depuis Lannion »"></textarea>
                            </p>

                            <p class="description">
                                Rien ne part si la case reste décochée : l’annonce est toujours
                                un geste, jamais une conséquence de l’enregistrement. Vous
                                pourrez aussi l’envoyer plus tard depuis l’écran de la sortie.
                                Les adhérents qui ont refusé les annonces sur leur profil ne
                                sont jamais comptés.
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit"><button class="button button-primary">Créer l’événement</button></p>
            </form>
        </div>
        <?php
    }

    /**
     * Liste des inscrits — l'écran du directeur de plongée.
     */
    public static function renderRoster(): void
    {
        AdminUi::enqueue();
        AdminUi::flash();

        $eventId = absint($_GET['event_id'] ?? 0);
        $service = new EventService();
        $event   = $service->find($eventId);

        if ($event === null) {
            wp_die('Événement introuvable.', 404);
        }

        $participants = $service->participants($eventId);
        $type         = $service->typeOf($eventId);
        $canManage    = current_user_can('sub_manage_event_types')
            || (int) $event['organizer_id'] === get_current_user_id();
        ?>
        <div class="wrap sub-admin">
            <h1><?php echo esc_html((string) $event['title']); ?></h1>
            <p class="description">
                <?php echo esc_html(self::frDateTime((string) $event['starts_at'])); ?>
                <?php if (!empty($event['location'])) : ?>
                    — <?php echo esc_html((string) $event['location']); ?>
                <?php endif; ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG)); ?>">← Tous les événements</a>
            </p>

            <p style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" class="button" onclick="window.print();">Imprimer la feuille d’émargement</button>
                <?php ExportsScreen::buttons('event-roster', ['event_id' => $eventId]); ?>
            </p>

            <?php if ($canManage) : ?>
                <?php self::renderAnnounceForm($service, $event, $eventId); ?>
            <?php endif; ?>

            <?php if ($canManage && $participants !== []) : ?>
                <details class="sub-card" style="margin-bottom:20px;">
                    <summary><strong>Écrire aux inscrits</strong></summary>

                    <p class="description">
                        Message envoyé maintenant, à votre initiative, et tracé dans le journal
                        des envois. Les destinataires ne se voient pas entre eux.
                    </p>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sub-form">
                        <input type="hidden" name="action" value="sub_event_message">
                        <input type="hidden" name="event_id" value="<?php echo esc_attr((string) $eventId); ?>">
                        <?php wp_nonce_field('sub_event_message_' . $eventId); ?>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">Objet</th>
                                <td>
                                    <input type="text" name="subject" class="regular-text" required
                                           placeholder="Rendez-vous 8h au parking">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Message</th>
                                <td><textarea name="message" rows="6" class="large-text" required></textarea></td>
                            </tr>
                            <tr>
                                <th scope="row">Destinataires</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="include_waiting" value="1" checked>
                                        Inclure la liste d’attente
                                    </label>
                                </td>
                            </tr>
                        </table>

                        <p class="submit"><button class="button button-primary">Envoyer</button></p>
                    </form>
                </details>
            <?php endif; ?>

            <div class="sub-scroll"><table class="wp-list-table widefat striped sub-roster sub-cards">
                <thead>
                    <tr>
                        <th style="width:30px;">#</th>
                        <th>Participant</th>
                        <th style="width:90px;">Niveau</th>
                        <th style="width:150px;">Téléphone</th>
                        <th style="width:200px;">Personne à prévenir</th>
                        <th style="width:110px;">Documents</th>
                        <th style="width:110px;">État</th>
                        <th style="width:220px;">Besoins</th>
                        <th class="sub-print-only" style="width:150px;">Émargement</th>
                        <?php if ($canManage) : ?><th style="width:110px;"></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if ($participants === []) : ?>
                    <tr><td colspan="9">Aucun inscrit pour l’instant.</td></tr>
                <?php endif; ?>

                <?php foreach ($participants as $i => $person) : ?>
                    <tr>
                        <td data-label="#"><?php echo (int) $i + 1; ?></td>
                        <td data-label="Participant">
                            <strong><?php echo esc_html((string) ($person['display_name'] ?: '—')); ?></strong><br>
                            <span style="color:#50575e;"><?php echo esc_html((string) $person['user_email']); ?></span>
                        </td>
                        <td data-label="Niveau">
                            <?php echo esc_html((string) $person['level']); ?>
                            <?php if (!empty($person['is_minor'])) : ?>
                                <br><span class="sub-tag">mineur</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Téléphone"><?php echo esc_html((string) ($person['phone'] ?: '—')); ?></td>
                        <td data-label="Personne à prévenir">
                            <?php echo esc_html((string) ($person['emergency'] ?: '—')); ?>
                            <?php if (!empty($person['guardian'])) : ?>
                                <br><small>Représentant : <?php echo esc_html((string) $person['guardian']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td data-label="Documents">
                            <?php
                            // Le DP voit la VALIDITÉ, jamais le document lui-même.
                            echo $person['medical_ok']
                                ? '<span class="sub-badge" style="background:#17795e;color:#fff;">À jour</span>'
                                : '<span class="sub-badge" style="background:#c43a22;color:#fff;">À vérifier</span>';
                            ?>
                        </td>
                        <td data-label="État">
                            <?php echo $person['status'] === 'waiting'
                                ? AdminUi::statusBadge('inactive') . '<br><small>liste d’attente</small>'
                                : AdminUi::statusBadge('active'); ?>
                        </td>
                        <td data-label="Besoins">
                            <?php
                            $answers = (array) (json_decode((string) ($person['details'] ?? ''), true) ?: []);
                            $summary = RegistrationFields::summarise($answers, $type);
                            ?>
                            <?php if ($summary === []) : ?>
                                <span style="color:#a7aaad;">—</span>
                            <?php else : ?>
                                <ul class="sub-needs">
                                    <?php foreach ($summary as $line) : ?>
                                        <li><?php echo esc_html($line); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <?php if (!empty($answers['comment'])) : ?>
                                <p class="sub-needs__comment">
                                    « <?php echo esc_html((string) $answers['comment']); ?> »
                                </p>
                            <?php endif; ?>
                        </td>
                        <td class="sub-print-only"></td>
                        <?php if ($canManage) : ?>
                            <td data-label="Action">
                                <?php AdminUi::actionButton(
                                    'sub_event_unregister',
                                    ['event_id' => $eventId, 'user_id' => (int) $person['user_id']],
                                    'Désinscrire',
                                    'button-link-delete button-link',
                                    'Retirer cette personne de la liste ?'
                                ); ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>

            <p class="description" style="margin-top:16px;">
                La colonne « Documents » indique si le certificat médical et la licence sont
                valides. Le contenu des documents n’est jamais accessible depuis cet écran.
            </p>
        </div>
        <?php
    }

    /**
     * Annoncer la sortie — l'action manuelle, disponible tant qu'elle est à venir.
     *
     * Repliée quand l'annonce est déjà partie, ouverte sinon : c'est la première
     * chose à faire d'une sortie qu'on vient de créer sans cocher la case, et
     * plus rien à faire une fois qu'elle est partie.
     *
     * @param array<string, mixed> $event
     */
    private static function renderAnnounceForm(EventService $service, array $event, int $eventId): void
    {
        if ((string) $event['starts_at'] < current_time('mysql')) {
            return; // Une sortie passée ne s'annonce plus.
        }

        $lastSent = $service->lastAnnouncedAt($eventId);
        $counts   = [];

        foreach (EventService::audiences() as $key => $label) {
            $counts[$key] = count($service->announcementRecipients($eventId, $key, get_current_user_id()));
        }
        ?>
        <details class="sub-card" style="margin-bottom:20px;" <?php echo $lastSent === null ? 'open' : ''; ?>>
            <summary><strong>Annoncer la sortie aux membres</strong></summary>

            <?php if ($lastSent !== null) : ?>
                <p class="description">
                    <strong>Déjà annoncée</strong> le <?php echo esc_html(self::frDateTime($lastSent)); ?>.
                    Un second envoi se justifie quand quelque chose a changé — une place
                    libérée, un horaire déplacé —, pas pour relancer.
                </p>
            <?php endif; ?>

            <p class="description">
                Message envoyé maintenant, à votre initiative, et tracé dans le journal
                des envois. Les destinataires ne se voient pas entre eux, et les inscrits
                n’en reçoivent pas de copie : ils ont déjà leur confirmation.
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sub-form">
                <input type="hidden" name="action" value="sub_event_announce">
                <input type="hidden" name="event_id" value="<?php echo esc_attr((string) $eventId); ?>">
                <?php wp_nonce_field('sub_event_announce_' . $eventId); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Destinataires</th>
                        <td>
                            <select name="audience">
                                <?php foreach (EventService::audiences() as $key => $label) : ?>
                                    <option value="<?php echo esc_attr($key); ?>">
                                        <?php printf(
                                            '%s — %d destinataire%s',
                                            esc_html($label),
                                            (int) $counts[$key],
                                            $counts[$key] > 1 ? 's' : ''
                                        ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                Effectifs calculés à l’instant : adhésion à jour, compte validé,
                                annonces acceptées, et niveau requis pour le premier choix.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Mot d’accompagnement</th>
                        <td>
                            <textarea name="note" rows="3" class="large-text"
                                      placeholder="Facultatif — le titre, la date, le lieu et la description sont déjà dans le message."></textarea>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button class="button button-primary"
                            onclick="return confirm('Envoyer l’annonce maintenant ?');">
                        Envoyer l’annonce
                    </button>
                </p>
            </form>
        </details>
        <?php
    }

    public static function handleSave(): void
    {
        check_admin_referer('sub_event_save');

        $data = [
            'title'                  => sanitize_text_field(wp_unslash((string) ($_POST['title'] ?? ''))),
            'location'               => sanitize_text_field(wp_unslash((string) ($_POST['location'] ?? ''))),
            'description'            => wp_kses_post(wp_unslash((string) ($_POST['description'] ?? ''))),
            'starts_at'              => self::datetime($_POST['starts_at'] ?? ''),
            'ends_at'                => self::datetime($_POST['ends_at'] ?? '') ?: null,
            'registration_closes_at' => self::datetime($_POST['registration_closes_at'] ?? '') ?: null,
            'capacity'               => absint($_POST['capacity'] ?? 0),
            // Les deux listes envoient une valeur vide quand rien n'est exigé :
            // la garder ferait un plancher fantôme dans la comparaison.
            'accepted_levels'        => array_values(array_filter(
                array_map('sanitize_key', (array) ($_POST['accepted_levels'] ?? []))
            )),
        ];

        if ($data['title'] === '' || $data['starts_at'] === '') {
            AdminUi::redirect(self::SLUG, 'Titre et date de début sont obligatoires.', true);
        }

        try {
            $service = new EventService();
            $id      = $service->create(
                sanitize_key(wp_unslash((string) ($_POST['type_slug'] ?? ''))),
                $data,
                get_current_user_id()
            );
        } catch (\RuntimeException $e) {
            AdminUi::redirect(self::SLUG, $e->getMessage(), true);
        }

        if (!isset($_POST['announce'])) {
            AdminUi::redirect(self::SLUG_ROSTER, 'Événement créé.', false, ['event_id' => $id]);
        }

        // L'événement, lui, est créé : un échec d'annonce ne doit pas le laisser
        // croire perdu. On le dit, et on renvoie vers son écran, où le bouton
        // d'annonce attend une seconde tentative.
        try {
            $result = $service->announce(
                $id,
                get_current_user_id(),
                sanitize_key(wp_unslash((string) ($_POST['audience'] ?? EventService::AUDIENCE_ELIGIBLE))),
                sanitize_textarea_field(wp_unslash((string) ($_POST['note'] ?? '')))
            );
        } catch (\RuntimeException $e) {
            AdminUi::redirect(
                self::SLUG_ROSTER,
                'Événement créé, mais l’annonce n’est pas partie : ' . $e->getMessage(),
                true,
                ['event_id' => $id]
            );
        }

        AdminUi::redirect(
            self::SLUG_ROSTER,
            'Événement créé. ' . self::announceOutcome($result),
            $result['sent'] < $result['recipients'],
            ['event_id' => $id]
        );
    }

    public static function handleAnnounce(): void
    {
        $eventId = absint($_POST['event_id'] ?? 0);
        check_admin_referer('sub_event_announce_' . $eventId);

        try {
            $result = (new EventService())->announce(
                $eventId,
                get_current_user_id(),
                sanitize_key(wp_unslash((string) ($_POST['audience'] ?? EventService::AUDIENCE_ELIGIBLE))),
                sanitize_textarea_field(wp_unslash((string) ($_POST['note'] ?? '')))
            );
        } catch (\RuntimeException $e) {
            AdminUi::redirect(self::SLUG_ROSTER, $e->getMessage(), true, ['event_id' => $eventId]);
        }

        AdminUi::redirect(
            self::SLUG_ROSTER,
            self::announceOutcome($result),
            $result['sent'] < $result['recipients'],
            ['event_id' => $eventId]
        );
    }

    /**
     * Destinataires et envois réussis, distingués.
     *
     * Un organisateur qui croit avoir prévenu le club alors que la moitié des
     * messages est restée en route ne s'en apercevrait qu'au bord de l'eau.
     *
     * @param array{recipients: int, sent: int} $result
     */
    private static function announceOutcome(array $result): string
    {
        $failed = $result['recipients'] - $result['sent'];

        return $failed === 0
            ? sprintf('Annonce envoyée à %d membre(s).', $result['sent'])
            : sprintf(
                'Annonce partielle : %d message(s) parti(s) sur %d. Vérifiez la '
                . 'configuration du courriel sortant, puis le journal des envois.',
                $result['sent'],
                $result['recipients']
            );
    }

    public static function handleDelete(): void
    {
        check_admin_referer('sub_event_delete');
        AdminUi::requireCap('sub_manage_event_types');

        global $wpdb;
        $eventId = absint($_POST['event_id'] ?? 0);

        $wpdb->delete("{$wpdb->prefix}sub_event_registrations", ['event_id' => $eventId]);
        $wpdb->delete("{$wpdb->prefix}sub_events", ['id' => $eventId]);

        Audit::log('event.deleted', 'event', $eventId);
        AdminUi::redirect(self::SLUG, 'Événement supprimé.');
    }

    public static function handleMessage(): void
    {
        $eventId = absint($_POST['event_id'] ?? 0);
        check_admin_referer('sub_event_message_' . $eventId);

        try {
            $result = (new EventService())->messageParticipants(
                $eventId,
                sanitize_text_field(wp_unslash((string) ($_POST['subject'] ?? ''))),
                sanitize_textarea_field(wp_unslash((string) ($_POST['message'] ?? ''))),
                get_current_user_id(),
                isset($_POST['include_waiting'])
            );

            if ($result['recipients'] === 0) {
                AdminUi::redirect(
                    self::SLUG_ROSTER,
                    'Aucun destinataire : personne n’est inscrit à cet événement.',
                    true,
                    ['event_id' => $eventId]
                );
            }

            $failed = $result['recipients'] - $result['sent'];

            AdminUi::redirect(
                self::SLUG_ROSTER,
                $failed === 0
                    ? sprintf('Message envoyé à %d participant(s).', $result['sent'])
                    : sprintf(
                        'Envoi partiel : %d message(s) parti(s) sur %d. Vérifiez la configuration '
                        . 'du courriel sortant, puis le journal des envois.',
                        $result['sent'],
                        $result['recipients']
                    ),
                $failed > 0,
                ['event_id' => $eventId]
            );
        } catch (\RuntimeException $e) {
            AdminUi::redirect(self::SLUG_ROSTER, $e->getMessage(), true, ['event_id' => $eventId]);
        }
    }

    public static function handleUnregister(): void
    {
        check_admin_referer('sub_event_unregister');

        $eventId = absint($_POST['event_id'] ?? 0);
        $userId  = absint($_POST['user_id'] ?? 0);

        $service = new EventService();
        $event   = $service->find($eventId);

        if ($event === null) {
            AdminUi::redirect(self::SLUG, 'Événement introuvable.', true);
        }

        // Seuls l'organisateur et les gestionnaires peuvent retirer quelqu'un.
        if (!current_user_can('sub_manage_event_types') && (int) $event['organizer_id'] !== get_current_user_id()) {
            wp_die('Accès refusé.', 403);
        }

        $service->cancel($eventId, $userId);
        AdminUi::redirect(self::SLUG_ROSTER, 'Personne désinscrite.', false, ['event_id' => $eventId]);
    }

    private static function datetime(mixed $raw): string
    {
        $value = sanitize_text_field(wp_unslash((string) $raw));

        if ($value === '') {
            return '';
        }

        $ts = strtotime($value);

        return $ts === false ? '' : date('Y-m-d H:i:s', $ts);
    }

    private static function frDateTime(string $mysqlDate): string
    {
        $ts = strtotime($mysqlDate);

        return $ts === false ? $mysqlDate : wp_date('D j M Y, H\hi', $ts);
    }
}
