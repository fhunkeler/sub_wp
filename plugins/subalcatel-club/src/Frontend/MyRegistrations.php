<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Events\RegistrationFields;

/**
 * Mes inscriptions : shortcode [subalcatel_mes_inscriptions].
 *
 * À venir d'abord, passées ensuite. Ce qu'on vient chercher ici, c'est ce
 * qu'on a déclaré : « avais-je demandé un bloc du club ? »
 */
final class MyRegistrations
{
    public static function register(): void
    {
        add_shortcode('subalcatel_mes_inscriptions', [self::class, 'render']);
    }

    public static function render(): string
    {
        if (!is_user_logged_in()) {
            return sprintf(
                '<div class="sub-notice"><strong>Espace réservé aux membres</strong><p><a href="%s">Se connecter</a></p></div>',
                esc_url(wp_login_url(get_permalink()))
            );
        }

        wp_enqueue_style(
            'subalcatel-membership',
            \Subalcatel\Club\PLUGIN_URL . 'assets/css/membership.css',
            [],
            \Subalcatel\Club\VERSION
        );

        global $wpdb;
        $userId = get_current_user_id();
        $now    = current_time('mysql');

        $upcoming = self::query($userId, "e.starts_at >= '{$now}'", 'ASC');
        $past     = self::query($userId, "e.starts_at < '{$now}'", 'DESC', 20);

        ob_start();

        foreach (['sub_done' => 'success', 'sub_error' => 'error'] as $key => $class) {
            if (isset($_GET[$key])) {
                printf(
                    '<div class="sub-notice sub-notice--%s"><p>%s</p></div>',
                    esc_attr($class),
                    esc_html(sanitize_text_field(wp_unslash((string) $_GET[$key])))
                );
            }
        }

        echo '<div class="sub-registrations">';

        if ($upcoming === [] && $past === []) {
            echo '<div class="sub-notice"><strong>Aucune inscription</strong>'
                . '<p>Vous ne vous êtes encore inscrit à aucune sortie.';

            if (Pages::exists(Pages::AGENDA)) {
                printf(' <a href="%s">Voir l’agenda</a>.', esc_url(Pages::url(Pages::AGENDA)));
            }

            echo '</p></div></div>';

            return (string) ob_get_clean();
        }

        if ($upcoming !== []) {
            self::renderSection('À venir', $upcoming, true);
        }

        if ($past !== []) {
            self::renderSection('Sorties passées', $past, false);
        }

        printf(
            '<p class="sub-help"><a href="%s">Ajouter mes sorties à mon agenda</a> '
            . '(Google Agenda, Apple Calendrier, Outlook) — la liste se met à jour toute seule.</p>',
            esc_url(\Subalcatel\Club\Events\IcalFeed::subscribeUrl(
                $userId,
                \Subalcatel\Club\Events\IcalFeed::FEED_REGISTRATIONS
            ))
        );

        echo '</div>';

        return (string) ob_get_clean();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function query(int $userId, string $where, string $direction, int $limit = 50): array
    {
        global $wpdb;

        // `$where` est construit ici uniquement, jamais depuis une entrée
        // utilisateur ; les valeurs variables restent préparées.
        return $wpdb->get_results($wpdb->prepare(
            "SELECT r.status AS my_status, r.details, r.registered_at,
                    e.id, e.title, e.location, e.starts_at, e.type_id
             FROM {$wpdb->prefix}sub_event_registrations r
             INNER JOIN {$wpdb->prefix}sub_events e ON e.id = r.event_id
             WHERE r.user_id = %d AND r.status IN ('confirmed','waiting') AND {$where}
             ORDER BY e.starts_at {$direction} LIMIT %d",
            $userId,
            $limit
        ), ARRAY_A) ?: [];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function renderSection(string $title, array $rows, bool $canCancel): void
    {
        $service = new EventService();
        ?>
        <section class="sub-block">
            <h2 class="sub-block__title"><?php echo esc_html($title); ?></h2>

            <?php foreach ($rows as $row) : ?>
                <?php
                $eventId = (int) $row['id'];
                $answers = (array) (json_decode((string) $row['details'], true) ?: []);
                $summary = RegistrationFields::summarise($answers, $service->typeOf($eventId));
                ?>
                <article class="sub-registration">
                    <header class="sub-registration__head">
                        <div>
                            <h3 class="sub-registration__title"><?php echo esc_html((string) $row['title']); ?></h3>
                            <p class="sub-registration__meta">
                                <?php echo esc_html(MemberDashboard::frDateTime((string) $row['starts_at'])); ?>
                                <?php if (!empty($row['location'])) : ?>
                                    — <?php echo esc_html((string) $row['location']); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <span class="sub-pill <?php echo $row['my_status'] === 'waiting' ? 'sub-pill--waiting' : 'sub-pill--ok'; ?>">
                            <?php echo $row['my_status'] === 'waiting' ? 'Liste d’attente' : 'Inscrit'; ?>
                        </span>
                    </header>

                    <?php if ($summary !== []) : ?>
                        <ul class="sub-registration__answers">
                            <?php foreach ($summary as $line) : ?>
                                <li><?php echo esc_html($line); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if (!empty($answers['comment'])) : ?>
                        <p class="sub-registration__comment">
                            « <?php echo esc_html((string) $answers['comment']); ?> »
                        </p>
                    <?php endif; ?>

                    <?php if ($canCancel) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="sub_event_cancel">
                            <input type="hidden" name="event_id" value="<?php echo esc_attr((string) $eventId); ?>">
                            <?php wp_nonce_field('sub_event_cancel_' . $eventId); ?>
                            <button class="sub-button sub-button--ghost sub-button--small">Me désinscrire</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>
        <?php
    }
}
