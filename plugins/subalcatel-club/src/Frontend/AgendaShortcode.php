<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Events\RegistrationFields;

/**
 * Agenda du club : shortcode [subalcatel_agenda].
 *
 * Le point important de cet écran : quand un membre n'est pas éligible, il voit
 * LE MOTIF. Une inscription refusée sans explication produit un appel
 * téléphonique au président ; avec le motif, elle produit une régularisation.
 */
final class AgendaShortcode
{
    public static function register(): void
    {
        add_shortcode('subalcatel_agenda', [self::class, 'render']);
        add_action('admin_post_sub_event_register', [self::class, 'handleRegister']);
        add_action('admin_post_sub_event_cancel', [self::class, 'handleCancel']);
        add_action('admin_post_sub_event_contact_organizer', [self::class, 'handleContactOrganizer']);
    }

    public static function render(): string
    {
        if (!is_user_logged_in()) {
            return '<div class="sub-notice"><strong>Agenda réservé aux membres</strong>'
                . sprintf('<p><a href="%s">Se connecter</a></p></div>', esc_url(wp_login_url(get_permalink())));
        }

        wp_enqueue_style(
            'subalcatel-membership',
            \Subalcatel\Club\PLUGIN_URL . 'assets/css/membership.css',
            [],
            \Subalcatel\Club\VERSION
        );

        wp_enqueue_script(
            'subalcatel-signup',
            \Subalcatel\Club\PLUGIN_URL . 'assets/js/signup.js',
            [],
            \Subalcatel\Club\VERSION,
            true
        );

        $service = new EventService();
        $events  = $service->upcoming();
        $userId  = get_current_user_id();

        ob_start();

        echo Notice::fromQuery(); // déjà échappé

        if ($events === []) {
            echo '<div class="sub-notice"><strong>Aucune sortie programmée</strong>'
                . '<p>Revenez bientôt, le calendrier se remplit vite en saison.</p></div>';

            return (string) ob_get_clean();
        }

        echo '<div class="sub-agenda">';

        foreach ($events as $event) {
            $eventId    = (int) $event['id'];
            $decision   = $service->checkEligibility($eventId, $userId);
            $confirmed  = $service->confirmedCount($eventId);
            $capacity   = (int) $event['capacity'];
            $registered = self::registrationOf($eventId, $userId);
            ?>
            <?php // L'ancre permet au calendrier mensuel de pointer la sortie. ?>
            <article class="sub-event" id="sub-event-<?php echo esc_attr((string) $eventId); ?>">
                <header class="sub-event__head">
                    <h3 class="sub-event__title"><?php echo esc_html((string) $event['title']); ?></h3>
                    <p class="sub-event__meta">
                        <?php echo esc_html(self::frDateTime((string) $event['starts_at'])); ?>
                        <?php if (!empty($event['location'])) : ?>
                            — <?php echo esc_html((string) $event['location']); ?>
                        <?php endif; ?>
                    </p>
                </header>

                <p class="sub-event__places">
                    <?php if ($capacity > 0) : ?>
                        <?php echo esc_html(sprintf('%d place(s) sur %d', $confirmed, $capacity)); ?>
                    <?php else : ?>
                        Places non limitées
                    <?php endif; ?>
                </p>

                <?php if ($registered !== null && $registered !== 'cancelled') : ?>
                    <p class="sub-event__status sub-event__status--<?php echo esc_attr($registered); ?>">
                        <?php echo $registered === 'confirmed'
                            ? 'Vous êtes inscrit.'
                            : 'Vous êtes en liste d’attente.'; ?>
                    </p>
                    <?php self::renderAction('sub_event_cancel', $eventId, 'Me désinscrire', 'sub-button--ghost'); ?>
                    <?php self::renderOrganizerForm($eventId, (int) ($event['organizer_id'] ?? 0)); ?>

                <?php elseif ($decision->allowed) : ?>
                    <?php
                    $label = $capacity > 0 && $confirmed >= $capacity
                        ? 'Rejoindre la liste d’attente'
                        : 'M’inscrire';
                    $fields = RegistrationFields::forType($service->typeOf($eventId));

                    if ($fields === []) {
                        self::renderAction('sub_event_register', $eventId, $label);
                    } else {
                        self::renderRegistrationForm($eventId, $label, $fields, $userId);
                    }
                    ?>

                <?php else : ?>
                    <p class="sub-event__blocked">
                        <strong>Inscription impossible.</strong>
                        <?php echo esc_html($decision->reason); ?>
                    </p>
                <?php endif; ?>

                <?php self::renderParticipants($service, $eventId, $userId); ?>
            </article>
            <?php
        }

        echo '</div>';

        return (string) ob_get_clean();
    }

    /**
     * Formulaire d'inscription à une sortie.
     *
     * Replié par défaut : le membre voit d'abord l'événement, pas dix questions.
     *
     * @param array<string, array<string, mixed>> $fields
     */
    private static function renderRegistrationForm(int $eventId, string $label, array $fields, int $userId): void
    {
        ?>
        <details class="sub-event__signup">
            <summary class="sub-button"><?php echo esc_html($label); ?></summary>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  class="sub-signup">
                <input type="hidden" name="action" value="sub_event_register">
                <input type="hidden" name="event_id" value="<?php echo esc_attr((string) $eventId); ?>">
                <?php wp_nonce_field('sub_event_register_' . $eventId); ?>

                <?php foreach (RegistrationFields::groups() as $group => $groupLabel) : ?>
                    <?php
                    $groupFields = array_filter(
                        $fields,
                        static fn (array $f): bool => $f['group'] === $group
                    );

                    if ($groupFields === []) {
                        continue;
                    }
                    ?>
                    <fieldset class="sub-signup__group">
                        <legend><?php echo esc_html($groupLabel); ?></legend>
                        <?php foreach ($groupFields as $name => $field) : ?>
                            <?php self::renderRegistrationField($name, $field, $userId); ?>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endforeach; ?>

                <button type="submit" class="sub-button">Confirmer mon inscription</button>
            </form>
        </details>
        <?php
    }

    /**
     * Écrire au directeur de plongée, une fois inscrit.
     *
     * Son adresse n'apparaît nulle part : le message part du site et porte
     * l'inscrit en adresse de réponse. C'est ce qui permet d'ouvrir ce canal
     * sans publier le courriel d'un bénévole sur une page que lit tout le club.
     */
    private static function renderOrganizerForm(int $eventId, int $organizerId): void
    {
        if ($organizerId === 0) {
            return;
        }

        $organizer = get_userdata($organizerId);
        ?>
        <details class="sub-event__contact">
            <summary class="sub-button sub-button--ghost sub-button--small">
                Une question au directeur de plongée
            </summary>

            <?php // `sub-signup` porte la mise en forme des formulaires de sortie. ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  class="sub-signup">
                <input type="hidden" name="action" value="sub_event_contact_organizer">
                <input type="hidden" name="event_id" value="<?php echo esc_attr((string) $eventId); ?>">
                <?php wp_nonce_field('sub_event_contact_organizer_' . $eventId); ?>

                <p class="sub-input">
                    <label for="sub-dp-<?php echo esc_attr((string) $eventId); ?>">
                        Votre message<?php if ($organizer) : ?>
                            à <?php echo esc_html($organizer->display_name); ?>
                        <?php endif; ?>
                    </label>
                    <textarea id="sub-dp-<?php echo esc_attr((string) $eventId); ?>"
                              name="message" rows="4" required></textarea>
                    <small class="sub-input__help">
                        Il vous répondra par courriel : votre adresse est jointe automatiquement.
                    </small>
                </p>

                <button type="submit" class="sub-button sub-button--small">Envoyer</button>
            </form>
        </details>
        <?php
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function renderRegistrationField(string $name, array $field, int $userId): void
    {
        $id      = 'sub-reg-' . $name;
        $value   = RegistrationFields::defaultValue($userId, $name, $field);
        $depends = isset($field['depends_on'])
            ? sprintf(
                ' data-depends-on="%s" data-depends-value="%s" hidden',
                esc_attr((string) $field['depends_on']),
                esc_attr((string) ($field['depends_value'] ?? ''))
            )
            : '';
        ?>
        <p class="sub-input"<?php echo $depends; // phpcs:ignore ?>>
            <?php if ($field['type'] !== 'boolean') : ?>
                <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html((string) $field['label']); ?></label>
            <?php endif; ?>

            <?php switch ($field['type']) {
                case 'boolean': ?>
                    <label class="sub-signup__check" for="<?php echo esc_attr($id); ?>">
                        <input type="checkbox" id="<?php echo esc_attr($id); ?>"
                               name="<?php echo esc_attr($name); ?>" value="1"
                               data-toggle="<?php echo esc_attr($name); ?>">
                        <?php echo esc_html((string) $field['label']); ?>
                    </label>
                    <?php break;

                case 'select': ?>
                    <select id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>"
                            data-toggle="<?php echo esc_attr($name); ?>">
                        <option value="">— au choix —</option>
                        <?php foreach (RegistrationFields::optionsFor($name, $field, $userId) as $optValue => $optLabel) : ?>
                            <option value="<?php echo esc_attr((string) $optValue); ?>"
                                    <?php selected($value, (string) $optValue); ?>>
                                <?php echo esc_html((string) $optLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php break;

                case 'number': ?>
                    <span class="sub-signup__number">
                        <input type="number" id="<?php echo esc_attr($id); ?>" min="0" max="99"
                               name="<?php echo esc_attr($name); ?>"
                               value="<?php echo esc_attr($value); ?>">
                        <?php if (isset($field['unit'])) : ?>
                            <span><?php echo esc_html((string) $field['unit']); ?></span>
                        <?php endif; ?>
                    </span>
                    <?php break;

                case 'textarea': ?>
                    <textarea id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>"
                              rows="3"><?php echo esc_textarea($value); ?></textarea>
                    <?php break;

                default: ?>
                    <input type="text" id="<?php echo esc_attr($id); ?>"
                           name="<?php echo esc_attr($name); ?>"
                           value="<?php echo esc_attr($value); ?>">
            <?php } ?>

            <?php if (!empty($field['help'])) : ?>
                <small class="sub-input__help"><?php echo esc_html((string) $field['help']); ?></small>
            <?php endif; ?>
        </p>
        <?php
    }

    /**
     * Liste des participants, pour s'organiser entre plongeurs.
     *
     * Repliée dans un `<details>` : on la déroule pour choisir un binôme ou
     * voir qui vient, elle n'encombre pas la carte de la sortie. Ne montre que
     * nom, niveau, statut et le mot que chacun a choisi de partager — jamais les
     * données du dossier, qui restent au directeur de plongée.
     */
    private static function renderParticipants(EventService $service, int $eventId, int $userId): void
    {
        // Réservé aux adhérents à jour, sur décision du club. Un compte en
        // attente de validation ou une adhésion expirée voit le nombre de
        // places, jamais la liste nominative : afficher qui plonge est une
        // donnée personnelle, partagée entre membres, pas au-delà.
        if (!\Subalcatel\Club\Content\ClubDocuments::isActiveMember($userId)) {
            return;
        }

        $people = $service->socialParticipants($eventId, $userId);

        if ($people === []) {
            return;
        }

        $confirmed = array_values(array_filter($people, static fn (array $p): bool => $p['status'] === 'confirmed'));
        $waiting   = array_values(array_filter($people, static fn (array $p): bool => $p['status'] === 'waiting'));
        $festive   = count(array_filter($people, static fn (array $p): bool => $p['conviviality']));
        ?>
        <details class="sub-event__people">
            <summary>
                <?php echo esc_html(sprintf(
                    'Participants (%d inscrit%s%s)',
                    count($confirmed),
                    count($confirmed) > 1 ? 's' : '',
                    $waiting === [] ? '' : sprintf(', %d en attente', count($waiting))
                )); ?>
                <?php if ($festive > 0) : ?>
                    <?php // Repérable d'un coup d'œil : y a-t-il un pot en vue ? ?>
                    <span class="sub-people__festive-count" title="<?php echo esc_attr(sprintf(
                        '%d participant(s) partant(s) pour un moment convivial', $festive
                    )); ?>">🍻 <?php echo (int) $festive; ?></span>
                <?php endif; ?>
            </summary>

            <ul class="sub-people">
                <?php foreach (array_merge($confirmed, $waiting) as $p) : ?>
                    <li class="sub-people__item <?php echo $p['is_self'] ? 'sub-people__item--self' : ''; ?>">
                        <span class="sub-people__name">
                            <?php echo esc_html($p['name']); ?>
                            <?php if ($p['is_self']) : ?><span class="sub-people__you">(vous)</span><?php endif; ?>
                        </span>
                        <?php if ($p['level'] !== '') : ?>
                            <span class="sub-people__level"><?php echo esc_html($p['level']); ?></span>
                        <?php endif; ?>
                        <?php if ($p['status'] === 'waiting') : ?>
                            <span class="sub-people__wait">liste d’attente</span>
                        <?php endif; ?>
                        <?php if ($p['conviviality']) : ?>
                            <span class="sub-people__festive" title="Partant pour un moment convivial">🍻 partant</span>
                        <?php endif; ?>
                        <?php if ($p['note'] !== '') : ?>
                            <span class="sub-people__note">« <?php echo esc_html($p['note']); ?> »</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
        <?php
    }

    private static function renderAction(string $action, int $eventId, string $label, string $extraClass = ''): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <input type="hidden" name="event_id" value="<?php echo esc_attr((string) $eventId); ?>">
            <?php wp_nonce_field($action . '_' . $eventId); ?>
            <button class="sub-button <?php echo esc_attr($extraClass); ?>">
                <?php echo esc_html($label); ?>
            </button>
        </form>
        <?php
    }

    public static function handleRegister(): void
    {
        $eventId = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;

        if (!is_user_logged_in() || !check_admin_referer('sub_event_register_' . $eventId)) {
            wp_die('Requête non autorisée.', 403);
        }

        try {
            $service = new EventService();
            $userId  = get_current_user_id();
            $answers = RegistrationFields::sanitize($_POST, $service->typeOf($eventId), $userId);

            $result = $service->register($eventId, $userId, $answers);

            self::back($result['status'] === 'confirmed'
                ? 'Inscription confirmée.'
                : sprintf('Vous êtes en liste d’attente, position %d.', $result['position']));
        } catch (\RuntimeException $e) {
            self::back($e->getMessage(), true);
        }
    }

    public static function handleCancel(): void
    {
        $eventId = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;

        if (!is_user_logged_in() || !check_admin_referer('sub_event_cancel_' . $eventId)) {
            wp_die('Requête non autorisée.', 403);
        }

        (new EventService())->cancel($eventId, get_current_user_id());
        self::back('Vous êtes désinscrit.');
    }

    public static function handleContactOrganizer(): void
    {
        $eventId = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;

        if (!is_user_logged_in() || !check_admin_referer('sub_event_contact_organizer_' . $eventId)) {
            wp_die('Requête non autorisée.', 403);
        }

        try {
            $sent = (new EventService())->messageOrganizer(
                $eventId,
                sanitize_textarea_field(wp_unslash((string) ($_POST['message'] ?? ''))),
                get_current_user_id()
            );

            // Un « message envoyé » affiché alors que rien n'est parti est pire
            // que l'échec lui-même : le membre attendrait une réponse.
            self::back(
                $sent
                    ? 'Message envoyé au directeur de plongée. Il vous répondra par courriel.'
                    : 'Le message n’a pas pu partir. Prévenez le bureau.',
                !$sent
            );
        } catch (\RuntimeException $e) {
            self::back($e->getMessage(), true);
        }
    }

    private static function back(string $message, bool $isError = false): void
    {
        wp_safe_redirect(add_query_arg(
            [$isError ? 'sub_error' : 'sub_done' => rawurlencode($message)],
            wp_get_referer() ?: home_url('/')
        ));
        exit;
    }

    private static function registrationOf(int $eventId, int $userId): ?string
    {
        global $wpdb;

        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}sub_event_registrations
             WHERE event_id = %d AND user_id = %d",
            $eventId,
            $userId
        ));

        return $status === null ? null : (string) $status;
    }

    private static function frDateTime(string $mysqlDate): string
    {
        $ts = strtotime($mysqlDate);

        return $ts === false ? $mysqlDate : wp_date('l j F Y à H\hi', $ts);
    }
}
