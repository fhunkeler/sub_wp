<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

use Subalcatel\Club\Documents\DocumentService;
use Subalcatel\Club\Documents\DocumentTypes;
use Subalcatel\Club\Membership\ApplicationService;
use Subalcatel\Club\Membership\CampaignRepository;

/**
 * Courbes du tableau de bord.
 *
 * Quatre graphiques, pas un de plus. À l'échelle du club — 133 comptes, 86
 * adhésions — une courbe ne vaut que si sa *forme* dit quelque chose qu'un
 * compteur ne dit pas. Chacune de celles-ci répond à une question datée :
 *
 * 1. sommes-nous en retard sur la campagne ? (comparaison au même jour de N-1)
 * 2. quand tombe la prochaine vague de certificats ? (12 mois glissants)
 * 3. quelle sortie faut-il relancer ou dédoubler ? (inscrits / capacité)
 * 4. où s'accumulent les dossiers ? (répartition par étape)
 *
 * Aucune dépendance JavaScript : des barres en CSS et un `<svg>` inline. Une
 * librairie de graphiques pèserait plus lourd que le reste de l'extension pour
 * quatre figures qui ne bougent pas.
 *
 * **Chaque courbe sait s'afficher vide.** Le club démarre avec une seule
 * campagne réelle en base : la comparaison N-1 n'aura de données qu'à la
 * campagne suivante. Un cadre qui explique ce qu'il montrera vaut mieux qu'un
 * cadre absent — sinon personne ne saura que la comparaison existe le jour où
 * elle devient possible.
 */
final class DashboardCharts
{
    /** Profondeur de l'échéancier des certificats, en mois. */
    private const MONTHS = 12;

    /** Sorties affichées dans le graphique de remplissage. */
    private const EVENTS = 8;

    /** Points maximum tracés sur la courbe : au-delà, le SVG grossit pour rien. */
    private const CURVE_SAMPLES = 150;

    /** Étapes du dossier, dans l'ordre du parcours. */
    private const STAGES = [
        ApplicationService::STATUS_DRAFT             => ['Commencés, jamais envoyés', '#c9d6dd', '#041e30'],
        ApplicationService::STATUS_SUBMITTED         => ['Envoyés', '#6c7781', '#fff'],
        ApplicationService::STATUS_AWAITING_PAYMENT  => ['En attente de règlement', '#f2c14e', '#041e30'],
        ApplicationService::STATUS_PAYMENT_CONFIRMED => ['Réglés, à valider', '#0b4f71', '#fff'],
        ApplicationService::STATUS_ACTIVE            => ['Adhésions actives', '#17795e', '#fff'],
    ];

    public static function render(): void
    {
        $charts = [];

        if (current_user_can('sub_manage_memberships')) {
            $charts[] = self::renderCampaignCurve(...);
        }

        // Le remplissage des sorties suit la même règle que le bloc « Prochaines
        // sorties » : visible de tout le bureau, parce que relancer une sortie
        // creuse n'est pas réservé au secrétariat.
        $charts[] = self::renderOutingFill(...);

        if (current_user_can('sub_view_medical_validity')) {
            $charts[] = self::renderCertificateSchedule(...);
        }

        if (current_user_can('sub_manage_memberships')) {
            $charts[] = self::renderApplicationStages(...);
        }

        if ($charts === []) {
            return;
        }

        echo '<h2>Tendances</h2><div class="sub-charts">';

        foreach ($charts as $chart) {
            $chart();
        }

        echo '</div>';
    }

    // --- 1. Courbe cumulée de la campagne ---------------------------------

    /**
     * Cumul des dossiers reçus, jour par jour, depuis l'ouverture.
     *
     * L'axe des abscisses est le *jour de campagne*, pas la date : c'est ce qui
     * rend deux saisons comparables quand elles n'ouvrent pas le même jour.
     *
     * On date les dossiers sur `submitted_at`, jamais sur `activated_at` : la
     * reprise du Joomla a activé les 86 adhésions le même jour, celui de
     * l'import. Sur `activated_at`, la courbe de 2025-2026 serait un mur
     * vertical ; sur `submitted_at`, elle garde le rythme réel des dépôts.
     *
     * @return array{campaign: ?array<string, mixed>, span: int, today: int,
     *               points: list<int>, total: int,
     *               previous: ?array{title: string, points: list<int>, at_today: int, total: int}}
     */
    public static function campaignCurve(): array
    {
        $shown = (new CampaignRepository())->campaignToShow();

        if ($shown === null) {
            return [
                'campaign' => null,
                'span'     => 0,
                'today'    => 0,
                'points'   => [],
                'total'    => 0,
                'previous' => null,
            ];
        }

        $campaign = $shown['campaign'];
        // Une clôture antérieure à l'ouverture est une saisie fautive, pas une
        // exception : on ne veut pas qu'elle fasse tomber le tableau de bord.
        $span   = max(0, self::daysBetween((string) $campaign['opens_on'], (string) $campaign['closes_on']));
        $today  = self::daysBetween((string) $campaign['opens_on'], current_time('Y-m-d'));
        $points = self::cumulative((int) $campaign['id'], (string) $campaign['opens_on'], $span);

        $previous     = self::previousCampaign($campaign);
        $previousData = null;

        if ($previous !== null) {
            $previousSpan   = max(0, self::daysBetween((string) $previous['opens_on'], (string) $previous['closes_on']));
            $previousPoints = self::cumulative((int) $previous['id'], (string) $previous['opens_on'], $previousSpan);

            $previousData = [
                'title'    => (string) $previous['title'],
                'points'   => $previousPoints,
                // La valeur qui compte : où en était la saison passée au même
                // jour de campagne. C'est elle qui dit « en retard » ou non.
                'at_today' => $previousPoints[min($today, $previousSpan)] ?? 0,
                'total'    => $previousPoints === [] ? 0 : (int) end($previousPoints),
            ];
        }

        return [
            'campaign' => $campaign,
            'span'     => $span,
            'today'    => $today,
            'points'   => $points,
            'total'    => $points[min(max($today, 0), $span)] ?? 0,
            'previous' => $previousData,
        ];
    }

    /**
     * Campagne précédant celle-ci, quel que soit son statut hors brouillon.
     *
     * @param array<string, mixed> $campaign
     * @return array<string, mixed>|null
     */
    private static function previousCampaign(array $campaign): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sub_campaigns
             WHERE status <> 'draft' AND valid_from < %s
             ORDER BY valid_from DESC LIMIT 1",
            (string) $campaign['valid_from']
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * Cumul par jour de campagne, de 0 à `$span` inclus.
     *
     * Les brouillons, refus et annulations sont exclus : la courbe mesure la
     * demande *reçue*, pas les intentions ni les échecs.
     *
     * @return list<int>
     */
    private static function cumulative(int $campaignId, string $opensOn, int $span): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATEDIFF(DATE(COALESCE(submitted_at, created_at)), %s) AS day_offset,
                    COUNT(*) AS n
             FROM {$wpdb->prefix}sub_applications
             WHERE campaign_id = %d AND status NOT IN (%s, %s, %s)
             GROUP BY day_offset ORDER BY day_offset ASC",
            $opensOn,
            $campaignId,
            ApplicationService::STATUS_DRAFT,
            ApplicationService::STATUS_REFUSED,
            ApplicationService::STATUS_CANCELLED
        ), ARRAY_A) ?: [];

        $daily = array_fill(0, $span + 1, 0);

        foreach ($rows as $row) {
            // Un dossier déposé avant l'ouverture ou après la clôture existe :
            // saisie rétroactive, dérogation, reprise de données. On le range
            // au bord plutôt que de le perdre.
            $offset = max(0, min($span, (int) $row['day_offset']));
            $daily[$offset] += (int) $row['n'];
        }

        $cumulative = [];
        $running    = 0;

        foreach ($daily as $count) {
            $running     += $count;
            $cumulative[] = $running;
        }

        return $cumulative;
    }

    private static function daysBetween(string $from, string $to): int
    {
        $a = strtotime($from);
        $b = strtotime($to);

        if ($a === false || $b === false) {
            return 0;
        }

        return (int) round(($b - $a) / DAY_IN_SECONDS);
    }

    private static function renderCampaignCurve(): void
    {
        $data = self::campaignCurve();

        ChartUi::open('Adhésions reçues depuis l’ouverture', $data['campaign'] === null
            ? ''
            : (string) $data['campaign']['title']);

        if ($data['campaign'] === null) {
            ChartUi::emptyState('Aucune campagne n’est encore ouverte. Cette courbe suivra le rythme des dépôts jour après jour.');
            ChartUi::close();

            return;
        }

        if ($data['today'] < 0) {
            ChartUi::emptyState(sprintf(
                'La campagne ouvre le %s. La courbe démarrera ce jour-là.',
                AdminUi::frDate((string) $data['campaign']['opens_on'])
            ));
            ChartUi::close();

            return;
        }

        self::curveSvg($data);
        self::curveSummary($data);
        ChartUi::close();
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function curveSvg(array $data): void
    {
        $width  = 640;
        $height = 190;
        $left   = 38;
        $right  = 10;
        $top    = 12;
        $bottom = 26;

        $span     = max(1, (int) $data['span']);
        $today    = max(0, min($span, (int) $data['today']));
        $previous = $data['previous'];

        $ceiling = max(
            1,
            (int) ($data['points'][$span] ?? 0),
            $previous === null ? 0 : (int) $previous['total']
        );
        // Un plafond arrondi vers le haut donne des graduations lisibles.
        $step    = max(1, (int) ceil($ceiling / 4 / 5) * 5);
        $ceiling = $step * 4;

        $x = static fn (int $day): float => $left + ($width - $left - $right) * ($day / $span);
        $y = static fn (int $value): float => $height - $bottom
            - ($height - $top - $bottom) * ($value / $ceiling);

        $current = (int) ($data['points'][$today] ?? 0);

        $label = sprintf('%d adhésions reçues au jour %d de la campagne', $current, $today);
        ?>
        <svg class="sub-chart-svg" viewBox="0 0 <?php echo $width; ?> <?php echo $height; ?>"
             role="img" aria-label="<?php echo esc_attr($label); ?>">
            <?php for ($tick = 0; $tick <= $ceiling; $tick += $step) : ?>
                <line x1="<?php echo $left; ?>" y1="<?php echo round($y($tick), 1); ?>"
                      x2="<?php echo $width - $right; ?>" y2="<?php echo round($y($tick), 1); ?>"
                      stroke="#e5e5e5" stroke-width="1" />
                <text x="<?php echo $left - 6; ?>" y="<?php echo round($y($tick) + 4, 1); ?>"
                      text-anchor="end" font-size="11" fill="#646970"><?php echo $tick; ?></text>
            <?php endfor; ?>

            <?php foreach (self::axisTicks($data, $span) as $offset => $caption) : ?>
                <?php // Les repères des extrémités sont ancrés vers l'intérieur, sinon ils débordent du cadre. ?>
                <text x="<?php echo round($x((int) $offset), 1); ?>" y="<?php echo $height - 8; ?>"
                      text-anchor="<?php echo match ((int) $offset) {
                          0       => 'start',
                          $span   => 'end',
                          default => 'middle',
                      }; ?>" font-size="11" fill="#646970">
                    <?php echo esc_html($caption); ?>
                </text>
            <?php endforeach; ?>

            <?php if ($previous !== null) : ?>
                <polyline fill="none" stroke="#8c8f94" stroke-width="1.5" stroke-dasharray="4 3"
                          points="<?php echo esc_attr(self::polyline($previous['points'], $span, $x, $y)); ?>" />
            <?php endif; ?>

            <line x1="<?php echo round($x($today), 1); ?>" y1="<?php echo $top; ?>"
                  x2="<?php echo round($x($today), 1); ?>" y2="<?php echo $height - $bottom; ?>"
                  stroke="#c43a22" stroke-width="1" stroke-dasharray="2 3" />

            <polyline fill="none" stroke="#2271b1" stroke-width="2.5"
                      points="<?php echo esc_attr(self::polyline(
                          array_slice($data['points'], 0, $today + 1),
                          $span,
                          $x,
                          $y
                      )); ?>" />

            <circle cx="<?php echo round($x($today), 1); ?>"
                    cy="<?php echo round($y($current), 1); ?>"
                    r="4" fill="#2271b1" />
        </svg>
        <?php
    }

    /**
     * Cinq repères de date sous l'axe, plutôt qu'un numéro de jour illisible.
     *
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    private static function axisTicks(array $data, int $span): array
    {
        $opens  = (string) $data['campaign']['opens_on'];
        $ticks  = [];

        foreach ([0, 0.25, 0.5, 0.75, 1] as $ratio) {
            $offset = (int) round($span * $ratio);
            $date   = strtotime($opens . ' +' . $offset . ' days');

            if ($date !== false) {
                $ticks[$offset] = wp_date('j M', $date);
            }
        }

        return $ticks;
    }

    /**
     * @param list<int> $points
     */
    private static function polyline(array $points, int $span, callable $x, callable $y): string
    {
        if ($points === []) {
            return '';
        }

        $last  = count($points) - 1;
        $every = max(1, (int) ceil(count($points) / self::CURVE_SAMPLES));
        $out   = [];

        foreach ($points as $offset => $value) {
            // Une saison plus longue que celle en cours sort du cadre : on la
            // coupe au bord au lieu d'empiler ses derniers jours dessus, ce qui
            // dessinerait une falaise verticale qui n'existe pas.
            if ($offset > $span) {
                break;
            }

            if ($offset % $every !== 0 && $offset !== $last) {
                continue;
            }

            $out[] = round($x($offset), 1) . ',' . round($y($value), 1);
        }

        return implode(' ', $out);
    }

    /**
     * La phrase que le bureau lit réellement — la courbe ne fait que l'illustrer.
     *
     * @param array<string, mixed> $data
     */
    private static function curveSummary(array $data): void
    {
        $today    = max(0, min((int) $data['span'], (int) $data['today']));
        $total    = (int) ($data['points'][$today] ?? 0);
        $previous = $data['previous'];

        $received = sprintf('<strong>%d dossier%s</strong> reçu%s à ce jour', $total, ...array_fill(0, 2, $total > 1 ? 's' : ''));

        if ($previous === null) {
            printf(
                '<p class="sub-chart-note">%s. La comparaison avec la saison précédente s’affichera
                 dès qu’une deuxième campagne aura été menée sur ce site.</p>',
                $received
            );

            return;
        }

        $delta = $total - (int) $previous['at_today'];

        printf(
            '<p class="sub-chart-note">%s, contre %d au même jour de « %s » — %s.</p>',
            $received,
            (int) $previous['at_today'],
            esc_html((string) $previous['title']),
            $delta === 0
                ? 'à égalité'
                : sprintf(
                    '<span class="sub-delta sub-delta--%s">%s%d</span>',
                    $delta < 0 ? 'down' : 'up',
                    $delta > 0 ? '+' : '',
                    $delta
                )
        );
    }

    // --- 2. Échéancier des certificats médicaux ---------------------------

    /**
     * Certificats arrivant à échéance, mois par mois, sur un an.
     *
     * Le compteur « certificats manquants » du bas de page dit le passé. Cet
     * échéancier dit qu'en octobre trente-quatre certificats tombent le même
     * mois, et qu'il faut donc relancer en septembre.
     *
     * @return array{months: list<array{label: string, count: int}>, overdue: int, total: int}
     */
    public static function certificateSchedule(): array
    {
        global $wpdb;

        $firstMonth = current_time('Y-m-01');
        $lastDay    = gmdate('Y-m-t', (int) strtotime($firstMonth . ' +' . (self::MONTHS - 1) . ' months'));

        // L'échéancier commence aujourd'hui, pas au 1er du mois : un certificat
        // échu le 3 août est déjà en retard, et il doit compter dans cette
        // ligne-là seulement. Compté aussi dans la colonne d'août, il ferait
        // croire à une échéance à venir et serait relancé deux fois.
        $firstDay = current_time('Y-m-d');

        // `%%Y` : wpdb::prepare consomme les `%` — un `%Y` nu de DATE_FORMAT
        // serait pris pour un marqueur de substitution.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(valid_until, '%%Y-%%m') AS ym, COUNT(DISTINCT user_id) AS n
             FROM {$wpdb->prefix}sub_member_documents
             WHERE type_slug = %s AND status = %s AND purged_at IS NULL
               AND valid_until BETWEEN %s AND %s
             GROUP BY ym",
            DocumentTypes::MEDICAL,
            DocumentService::STATUS_VALID,
            $firstDay,
            $lastDay
        ), ARRAY_A) ?: [];

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['ym']] = (int) $row['n'];
        }

        $months = [];
        $total  = 0;

        for ($i = 0; $i < self::MONTHS; $i++) {
            $timestamp = (int) strtotime($firstMonth . ' +' . $i . ' months');
            $key       = gmdate('Y-m', $timestamp);
            $count     = $counts[$key] ?? 0;
            $total    += $count;

            $months[] = [
                'label' => wp_date('M', $timestamp),
                'count' => $count,
            ];
        }

        // Déjà échus mais toujours marqués valides : la bascule en `expired`
        // est faite par une tâche planifiée, pas à l'instant près.
        $overdue = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}sub_member_documents
             WHERE type_slug = %s AND status = %s AND purged_at IS NULL AND valid_until < %s",
            DocumentTypes::MEDICAL,
            DocumentService::STATUS_VALID,
            current_time('Y-m-d')
        ));

        return ['months' => $months, 'overdue' => $overdue, 'total' => $total];
    }

    private static function renderCertificateSchedule(): void
    {
        $data = self::certificateSchedule();

        ChartUi::open('Certificats médicaux arrivant à échéance', '12 mois à venir');

        if ($data['total'] === 0 && $data['overdue'] === 0) {
            ChartUi::emptyState('Aucun certificat n’arrive à échéance dans l’année. Les mois se rempliront à mesure que les certificats seront déposés et validés.');
            ChartUi::close();

            return;
        }

        $peak = max(1, ...array_column($data['months'], 'count'));
        ?>
        <div class="sub-chart-columns">
            <?php foreach ($data['months'] as $month) : ?>
                <div class="sub-chart-column">
                    <span class="sub-chart-column__value"><?php echo (int) $month['count']; ?></span>
                    <span class="sub-chart-column__bar"
                          style="height:<?php echo round(100 * $month['count'] / $peak); ?>%"></span>
                    <span class="sub-chart-column__label"><?php echo esc_html($month['label']); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        if ($data['overdue'] > 0) {
            printf(
                '<p class="sub-chart-note sub-chart-note--alert">%d certificat%s déjà échu%s, hors de
                 l’échéancier : ces membres ne peuvent pas plonger.</p>',
                $data['overdue'],
                $data['overdue'] > 1 ? 's' : '',
                $data['overdue'] > 1 ? 's' : ''
            );
        }

        ChartUi::close();
    }

    // --- 3. Remplissage des sorties ---------------------------------------

    /**
     * Inscrits et liste d'attente des prochaines sorties, rapportés à la place.
     *
     * @return list<array{title: string, starts_at: string, capacity: int,
     *                    confirmed: int, waiting: int}>
     */
    public static function outingFill(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT e.title, e.starts_at, e.capacity,
                    SUM(CASE WHEN r.status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed,
                    SUM(CASE WHEN r.status = 'waiting' THEN 1 ELSE 0 END) AS waiting
             FROM {$wpdb->prefix}sub_events e
             LEFT JOIN {$wpdb->prefix}sub_event_registrations r ON r.event_id = e.id
             WHERE e.status = 'published' AND e.starts_at >= %s
             GROUP BY e.id ORDER BY e.starts_at ASC LIMIT %d",
            current_time('mysql'),
            self::EVENTS
        ), ARRAY_A) ?: [];

        return array_map(static fn (array $r): array => [
            'title'     => (string) $r['title'],
            'starts_at' => (string) $r['starts_at'],
            'capacity'  => (int) $r['capacity'],
            'confirmed' => (int) $r['confirmed'],
            'waiting'   => (int) $r['waiting'],
        ], $rows);
    }

    private static function renderOutingFill(): void
    {
        $outings = self::outingFill();

        ChartUi::open('Remplissage des prochaines sorties', '');

        if ($outings === []) {
            ChartUi::emptyState('Aucune sortie programmée. Chaque sortie publiée apparaîtra ici avec ses inscrits rapportés à sa capacité.');
            ChartUi::close();

            return;
        }

        // Une sortie sans capacité déclarée n'a pas de taux de remplissage : on
        // la rapporte alors à la plus fréquentée du lot, pour qu'elle garde une
        // barre comparable au lieu d'une barre pleine trompeuse.
        $busiest = max(1, ...array_column($outings, 'confirmed'));

        echo '<table class="sub-chart-bars"><tbody>';

        foreach ($outings as $outing) {
            $limited = $outing['capacity'] > 0;
            $ratio   = $limited
                ? $outing['confirmed'] / $outing['capacity']
                : $outing['confirmed'] / $busiest;

            $tone = match (true) {
                !$limited     => 'neutral',
                $ratio >= 1.0 => 'full',
                $ratio >= 0.6 => 'ok',
                default       => 'low',
            };

            // La liste d'attente rejoint la date sous le titre plutôt que la
            // colonne de droite : « 12/12 » et « 12/12 +4 en attente » n'ont
            // pas la même largeur, et cette colonne doit rester étroite pour
            // que toutes les pistes se terminent au même endroit.
            $meta = mysql2date('j M', $outing['starts_at']);

            if ($outing['waiting'] > 0) {
                $meta .= sprintf(
                    ' · <span class="sub-chart-bar__waiting">%d en attente</span>',
                    $outing['waiting']
                );
            }
            ?>
            <tr>
                <th scope="row">
                    <span class="sub-chart-bar__label"><?php echo esc_html($outing['title']); ?></span>
                    <span class="sub-chart-bar__meta"><?php echo wp_kses_post($meta); ?></span>
                </th>
                <td>
                    <span class="sub-chart-bar__track">
                        <?php if ($outing['confirmed'] > 0) : ?>
                            <span class="sub-chart-bar__fill sub-chart-bar__fill--<?php echo esc_attr($tone); ?>"
                                  style="width:<?php echo round(min(100, 100 * $ratio)); ?>%"></span>
                        <?php endif; ?>
                    </span>
                </td>
                <td class="sub-chart-bar__value">
                    <?php
                    echo $limited
                        ? esc_html(sprintf('%d/%d', $outing['confirmed'], $outing['capacity']))
                        : esc_html(sprintf('%d inscrits', $outing['confirmed']));
                    ?>
                </td>
            </tr>
            <?php
        }

        echo '</tbody></table>';
        ChartUi::close();
    }

    // --- 4. Répartition des dossiers par étape ----------------------------

    /**
     * Où sont les dossiers de la campagne en cours.
     *
     * Ce n'est pas un entonnoir — les statuts s'excluent, un dossier n'est
     * qu'à un endroit. C'est justement ce qui rend la figure utile : trois
     * dossiers à valider mais quarante règlements en attente désignent le
     * trésorier, pas le secrétariat.
     *
     * @return array{campaign: ?string, stages: list<array{label: string, count: int, color: string, ink: string}>}
     */
    public static function applicationStages(): array
    {
        $shown = (new CampaignRepository())->campaignToShow();

        if ($shown === null) {
            return ['campaign' => null, 'stages' => []];
        }

        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) AS n FROM {$wpdb->prefix}sub_applications
             WHERE campaign_id = %d GROUP BY status",
            (int) $shown['campaign']['id']
        ), ARRAY_A) ?: [];

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        $stages = [];

        foreach (self::STAGES as $status => [$label, $color, $ink]) {
            $stages[] = [
                'label' => $label,
                'count' => $counts[$status] ?? 0,
                'color' => $color,
                'ink'   => $ink,
            ];
        }

        return ['campaign' => (string) $shown['campaign']['title'], 'stages' => $stages];
    }

    private static function renderApplicationStages(): void
    {
        $data = self::applicationStages();

        ChartUi::open('Où en sont les dossiers', $data['campaign'] ?? '');

        if ($data['stages'] === []) {
            ChartUi::emptyState('Aucune campagne en base. Cette répartition montrera l’étape à laquelle les dossiers s’accumulent.');
            ChartUi::close();

            return;
        }

        $peak  = max(1, ...array_column($data['stages'], 'count'));
        $total = array_sum(array_column($data['stages'], 'count'));

        echo '<table class="sub-chart-bars"><tbody>';

        foreach ($data['stages'] as $stage) {
            ?>
            <tr>
                <th scope="row">
                    <span class="sub-chart-bar__label"><?php echo esc_html($stage['label']); ?></span>
                </th>
                <td>
                    <span class="sub-chart-bar__track">
                        <?php if ($stage['count'] > 0) : ?>
                            <span class="sub-chart-bar__fill"
                                  style="width:<?php echo round(100 * $stage['count'] / $peak); ?>%;
                                         background:<?php echo esc_attr($stage['color']); ?>"></span>
                        <?php endif; ?>
                    </span>
                </td>
                <td class="sub-chart-bar__value"><?php echo (int) $stage['count']; ?></td>
            </tr>
            <?php
        }

        echo '</tbody></table>';

        if ($total === 0) {
            ChartUi::emptyState('Aucun dossier déposé sur cette campagne pour l’instant.');
        }

        ChartUi::close();
    }
}
