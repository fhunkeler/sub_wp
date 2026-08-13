<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Events\IcalFeed;

/**
 * Calendrier mensuel : shortcode [subalcatel_calendrier].
 *
 * La vue liste de l'agenda répond à « qu'est-ce qui vient ? ». Celle-ci répond
 * à « suis-je libre ce week-end ? » — une question qu'on ne peut pas poser à
 * une liste.
 *
 * Le mois se navigue sans JavaScript : chaque flèche est un lien. Un
 * calendrier qui ne fonctionne pas quand un script échoue n'est pas un
 * calendrier, c'est une décoration.
 */
final class CalendarShortcode
{
    public static function register(): void
    {
        add_shortcode('subalcatel_calendrier', [self::class, 'render']);
    }

    public static function render(): string
    {
        wp_enqueue_style(
            'subalcatel-membership',
            \Subalcatel\Club\PLUGIN_URL . 'assets/css/membership.css',
            [],
            \Subalcatel\Club\VERSION
        );

        $month  = self::requestedMonth();
        $events = self::eventsOfMonth($month);
        $userId = get_current_user_id();

        ob_start();
        ?>
        <div class="sub-calendar">
            <?php self::renderHeader($month); ?>
            <?php self::renderGrid($month, $events, $userId); ?>
            <?php self::renderList($month, $events); ?>
            <?php self::renderSubscription($userId); ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Mois affiché, ramené au premier jour à minuit.
     *
     * Une valeur fantaisiste dans l'URL ramène au mois courant plutôt que de
     * produire une erreur : ce paramètre est public et sera trituré.
     */
    private static function requestedMonth(): \DateTimeImmutable
    {
        $requested = isset($_GET['mois']) ? sanitize_text_field(wp_unslash($_GET['mois'])) : '';
        $parsed    = \DateTimeImmutable::createFromFormat('!Y-m-d', $requested . '-01', wp_timezone());

        if ($parsed === false || !preg_match('/^\d{4}-\d{2}$/', $requested)) {
            $parsed = new \DateTimeImmutable(current_time('Y-m-01'), wp_timezone());
        }

        return $parsed;
    }

    /**
     * Événements du mois, groupés par jour.
     *
     * @return array<string, list<array<string, mixed>>> clé = Y-m-d
     */
    private static function eventsOfMonth(\DateTimeImmutable $month): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sub_events
             WHERE status = 'published' AND starts_at >= %s AND starts_at < %s
             ORDER BY starts_at ASC",
            $month->format('Y-m-d 00:00:00'),
            $month->modify('first day of next month')->format('Y-m-d 00:00:00')
        ), ARRAY_A) ?: [];

        $byDay = [];

        foreach ($rows as $row) {
            $byDay[substr((string) $row['starts_at'], 0, 10)][] = $row;
        }

        return $byDay;
    }

    private static function renderHeader(\DateTimeImmutable $month): void
    {
        $previous = $month->modify('-1 month');
        $next     = $month->modify('+1 month');
        $link     = static fn (\DateTimeImmutable $m): string
            => esc_url(add_query_arg('mois', $m->format('Y-m'), get_permalink() ?: home_url('/')));
        ?>
        <div class="sub-calendar__head">
            <a class="sub-calendar__nav" rel="prev" href="<?php echo $link($previous); ?>"
               aria-label="Mois précédent">←</a>

            <h2 class="sub-calendar__month">
                <?php echo esc_html(ucfirst(wp_date('F Y', $month->getTimestamp()))); ?>
            </h2>

            <a class="sub-calendar__nav" rel="next" href="<?php echo $link($next); ?>"
               aria-label="Mois suivant">→</a>
        </div>
        <?php
    }

    /**
     * Grille du mois, masquée sur petit écran au profit de la liste.
     *
     * Sept colonnes à 375 px donnent des cases de 50 px : illisibles. Plutôt
     * que de compresser, on change de représentation.
     *
     * @param array<string, list<array<string, mixed>>> $events
     */
    private static function renderGrid(\DateTimeImmutable $month, array $events, int $userId): void
    {
        // La semaine commence lundi. `w` compte à partir de dimanche, d'où le
        // décalage : sans lui, tout le mois glisse d'un jour.
        $firstWeekday = ((int) $month->format('w') + 6) % 7;
        $daysInMonth  = (int) $month->format('t');
        $today        = current_time('Y-m-d');
        ?>
        <table class="sub-calendar__grid">
            <caption class="screen-reader-text">
                Calendrier des sorties du club pour <?php echo esc_html(wp_date('F Y', $month->getTimestamp())); ?>
            </caption>
            <thead>
                <tr>
                    <?php foreach (['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'] as $label) : ?>
                        <th scope="col"><?php echo esc_html($label); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php
            $cell = 0;
            echo '<tr>';

            for ($i = 0; $i < $firstWeekday; $i++, $cell++) {
                echo '<td class="sub-calendar__day sub-calendar__day--empty"></td>';
            }

            for ($day = 1; $day <= $daysInMonth; $day++, $cell++) {
                if ($cell % 7 === 0 && $cell > 0) {
                    echo '</tr><tr>';
                }

                $date    = $month->format('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
                $ofDay   = $events[$date] ?? [];
                $classes = 'sub-calendar__day'
                    . ($date === $today ? ' sub-calendar__day--today' : '')
                    . ($ofDay !== [] ? ' sub-calendar__day--has-events' : '');
                ?>
                <td class="<?php echo esc_attr($classes); ?>">
                    <span class="sub-calendar__number"><?php echo (int) $day; ?></span>
                    <?php foreach ($ofDay as $event) : ?>
                        <a class="sub-calendar__event"
                           href="<?php echo esc_url(self::eventLink((int) $event['id'])); ?>"
                           title="<?php echo esc_attr(sprintf(
                               '%s — %s',
                               substr((string) $event['starts_at'], 11, 5),
                               (string) $event['title']
                           )); ?>">
                            <span class="sub-calendar__time">
                                <?php echo esc_html(substr((string) $event['starts_at'], 11, 5)); ?>
                            </span>
                            <?php echo esc_html((string) $event['title']); ?>
                        </a>
                    <?php endforeach; ?>
                </td>
                <?php
            }

            while ($cell % 7 !== 0) {
                echo '<td class="sub-calendar__day sub-calendar__day--empty"></td>';
                $cell++;
            }

            echo '</tr>';
            ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Liste du mois — la représentation retenue sur téléphone.
     *
     * @param array<string, list<array<string, mixed>>> $events
     */
    private static function renderList(\DateTimeImmutable $month, array $events): void
    {
        ?>
        <div class="sub-calendar__list">
            <?php if ($events === []) : ?>
                <p class="sub-calendar__empty">Aucune sortie prévue ce mois-ci.</p>
            <?php endif; ?>

            <?php foreach ($events as $date => $ofDay) : ?>
                <?php $timestamp = strtotime($date); ?>
                <div class="sub-calendar__row">
                    <div class="sub-calendar__date">
                        <span class="sub-calendar__weekday"><?php echo esc_html(wp_date('D', $timestamp)); ?></span>
                        <span class="sub-calendar__daynum"><?php echo esc_html(wp_date('j', $timestamp)); ?></span>
                    </div>
                    <ul class="sub-calendar__entries">
                        <?php foreach ($ofDay as $event) : ?>
                            <li>
                                <a href="<?php echo esc_url(self::eventLink((int) $event['id'])); ?>">
                                    <strong><?php echo esc_html(substr((string) $event['starts_at'], 11, 5)); ?></strong>
                                    <?php echo esc_html((string) $event['title']); ?>
                                </a>
                                <?php if (!empty($event['location'])) : ?>
                                    <span class="sub-calendar__place">
                                        — <?php echo esc_html((string) $event['location']); ?>
                                    </span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private static function renderSubscription(int $userId): void
    {
        if ($userId === 0) {
            return;
        }
        ?>
        <div class="sub-calendar__subscribe">
            <h3>Suivre l’agenda depuis votre téléphone</h3>
            <p class="sub-help">
                Ajoutez l’agenda du club à Google Agenda, Apple Calendrier ou Outlook :
                les nouvelles sorties y apparaissent toutes seules.
            </p>
            <p>
                <a class="sub-button sub-button--small"
                   href="<?php echo esc_url(IcalFeed::subscribeUrl($userId, IcalFeed::FEED_CLUB)); ?>">
                    S’abonner à l’agenda du club
                </a>
                <a class="sub-button sub-button--ghost sub-button--small"
                   href="<?php echo esc_url(IcalFeed::subscribeUrl($userId, IcalFeed::FEED_REGISTRATIONS)); ?>">
                    S’abonner à mes inscriptions
                </a>
            </p>
            <p class="sub-help">
                Ces adresses vous sont personnelles : ne les partagez pas.
                <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(
                    'action',
                    IcalFeed::ACTION_RESET,
                    admin_url('admin-post.php')
                ), IcalFeed::ACTION_RESET . '_' . $userId)); ?>">Générer de nouvelles adresses</a>
                si vous pensez qu’elles ont circulé.
            </p>
        </div>
        <?php
    }

    private static function eventLink(int $eventId): string
    {
        return Pages::url(Pages::AGENDA) . '#sub-event-' . $eventId;
    }
}
