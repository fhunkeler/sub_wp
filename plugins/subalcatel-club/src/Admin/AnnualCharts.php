<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Membership\ApplicationService;

/**
 * Les statistiques de fond — celles qu'on lit avant une assemblée générale,
 * un dossier de subvention ou un budget, pas tous les matins.
 *
 * Elles vivent hors du tableau de bord pour cette raison. Le tableau de bord
 * répond à « qu'est-ce qui attend quelqu'un aujourd'hui ? » ; ces figures-ci
 * répondent à « comment va le club ? », question qui ne se pose pas à la même
 * fréquence et n'appelle aucune action dans la minute.
 *
 * Deux familles, séparées par les droits : ce qui décrit les personnes
 * (renouvellement, niveaux, âges, participation) et ce qui décrit l'argent
 * (recettes, options, délais d'encaissement). Le trésorier a rarement besoin
 * de la pyramide des âges, et le secrétariat n'a pas à voir les recettes.
 *
 * **Population de référence** : sauf mention contraire, « adhérents à jour »
 * désigne les comptes dont `sub_membership_valid_until` n'est pas dépassée.
 * C'est la même définition que le compteur du tableau de bord — deux
 * définitions concurrentes du mot « adhérent » rendraient les écrans
 * incomparables.
 */
final class AnnualCharts
{
    /**
     * Statuts qui ne comptent pas comme une adhésion reçue.
     *
     * Un brouillon n'a jamais été envoyé, un refus et une annulation n'ont pas
     * abouti : les compter gonflerait les effectifs comme les recettes.
     */
    private const NOT_RECEIVED = [
        ApplicationService::STATUS_DRAFT,
        ApplicationService::STATUS_REFUSED,
        ApplicationService::STATUS_CANCELLED,
    ];

    /** Tranches d'âge, en années : borne haute exclue. */
    private const AGE_BANDS = [
        ['Moins de 18 ans', 0, 18],
        ['18 – 29 ans', 18, 30],
        ['30 – 39 ans', 30, 40],
        ['40 – 49 ans', 40, 50],
        ['50 – 59 ans', 50, 60],
        ['60 – 69 ans', 60, 70],
        ['70 ans et plus', 70, 200],
    ];

    /** Tranches de participation, en sorties sur douze mois. */
    private const OUTING_BANDS = [
        ['Aucune sortie', 0, 0],
        ['1 à 2 sorties', 1, 2],
        ['3 à 5 sorties', 3, 5],
        ['6 à 10 sorties', 6, 10],
        ['Plus de 10 sorties', 11, PHP_INT_MAX],
    ];

    /** Tranches de délai d'encaissement, en jours. */
    private const DELAY_BANDS = [
        ['Moins d’une semaine', 0, 7],
        ['1 à 2 semaines', 8, 15],
        ['2 à 4 semaines', 16, 30],
        ['1 à 2 mois', 31, 60],
        ['Plus de deux mois', 61, PHP_INT_MAX],
    ];

    /** Options détaillées dans le graphique des recettes. */
    private const TOP_OPTIONS = 8;

    // --- Vie du club -------------------------------------------------------

    /**
     * Taux de renouvellement d'une campagne à la suivante.
     *
     * L'indicateur de santé d'un club, et celui que personne ne calcule à la
     * main : il demande de croiser deux campagnes compte par compte. Un club
     * qui recrute autant qu'il perd affiche des effectifs stables et se croit
     * en bonne santé — le renouvellement montre le renouvellement réel de
     * l'eau dans la baignoire.
     *
     * @return array{previous: ?string, current: ?string, base: int,
     *               renewed: int, lost: int, newcomers: int}
     */
    public static function renewal(): array
    {
        global $wpdb;

        $campaigns = $wpdb->get_results(
            "SELECT id, title FROM {$wpdb->prefix}sub_campaigns
             WHERE status <> 'draft' ORDER BY valid_from DESC LIMIT 2",
            ARRAY_A
        ) ?: [];

        if (count($campaigns) < 2) {
            return [
                'previous'  => null,
                'current'   => null,
                'base'      => 0,
                'renewed'   => 0,
                'lost'      => 0,
                'newcomers' => 0,
            ];
        }

        [$current, $previous] = $campaigns;

        $base    = self::membersOf((int) $previous['id']);
        $now     = self::membersOf((int) $current['id']);
        $renewed = self::renewedBetween((int) $previous['id'], (int) $current['id']);

        return [
            'previous'  => (string) $previous['title'],
            'current'   => (string) $current['title'],
            'base'      => $base,
            'renewed'   => $renewed,
            'lost'      => max(0, $base - $renewed),
            'newcomers' => max(0, $now - $renewed),
        ];
    }

    private static function membersOf(int $campaignId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}sub_applications
             WHERE campaign_id = %d AND user_id IS NOT NULL AND status NOT IN (%s, %s, %s)",
            $campaignId,
            ...self::NOT_RECEIVED
        ));
    }

    private static function renewedBetween(int $previousId, int $currentId): int
    {
        global $wpdb;

        $args = array_merge(
            [$currentId],
            self::NOT_RECEIVED,
            [$previousId],
            self::NOT_RECEIVED
        );

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.user_id)
             FROM {$wpdb->prefix}sub_applications p
             JOIN {$wpdb->prefix}sub_applications c
               ON c.user_id = p.user_id AND c.campaign_id = %d AND c.status NOT IN (%s, %s, %s)
             WHERE p.campaign_id = %d AND p.user_id IS NOT NULL AND p.status NOT IN (%s, %s, %s)",
            ...$args
        ));
    }

    /**
     * Répartition des adhérents à jour par niveau de plongée.
     *
     * Sert à une chose précise : vérifier que le club a de quoi encadrer sa
     * saison. Une majorité de P1 face à deux encadrants ne se voit dans aucun
     * compteur, mais saute aux yeux ici.
     *
     * @return array{rows: list<array{label: string, count: int}>, total: int}
     */
    public static function diveLevels(): array
    {
        $members = self::activeMemberIds();

        if ($members === []) {
            return ['rows' => [], 'total' => 0];
        }

        $counts = self::metaCounts($members, 'sub_dive_level_id');
        $rows   = [];
        $placed = 0;

        // L'ordre est celui des rangs — jamais l'alphabétique, qui met E4 avant
        // P1. `DiveLevels::ordered()` porte déjà cette règle.
        foreach (DiveLevels::ordered() as $term) {
            $count = (int) ($counts[(string) $term->term_id] ?? 0);

            if ($count === 0) {
                continue;
            }

            $placed += $count;
            $rows[]  = ['label' => $term->name, 'count' => $count];
        }

        $missing = count($members) - $placed;

        if ($missing > 0) {
            $rows[] = ['label' => 'Niveau non renseigné', 'count' => $missing];
        }

        return ['rows' => $rows, 'total' => count($members)];
    }

    /**
     * Répartition par tranche d'âge des adhérents à jour.
     *
     * Deux usages concrets : la part des jeunes, que réclament les dossiers de
     * subvention, et le vieillissement de l'effectif, qui ne se remarque que
     * sur plusieurs saisons.
     *
     * @return array{rows: list<array{label: string, count: int}>, total: int, unknown: int}
     */
    public static function ageBands(): array
    {
        $members = self::activeMemberIds();

        if ($members === []) {
            return ['rows' => [], 'total' => 0, 'unknown' => 0];
        }

        $bands = array_fill(0, count(self::AGE_BANDS), 0);
        $today = current_time('Y-m-d');
        $dates = self::metaValues($members, 'sub_birth_date');

        // Un compte sans date de naissance n'a pas d'âge inconnu par accident :
        // la fiche est incomplète. Les deux cas — méta absente et date
        // illisible — se comptent ensemble, et se disent.
        $unknown = count($members) - count($dates);

        foreach ($dates as $birthDate) {
            $age = self::ageOn($birthDate, $today);

            if ($age === null) {
                $unknown++;
                continue;
            }

            foreach (self::AGE_BANDS as $index => [, $from, $to]) {
                if ($age >= $from && $age < $to) {
                    $bands[$index]++;
                    break;
                }
            }
        }

        $rows = [];

        foreach (self::AGE_BANDS as $index => [$label]) {
            $rows[] = ['label' => $label, 'count' => $bands[$index]];
        }

        return ['rows' => $rows, 'total' => count($members), 'unknown' => max(0, $unknown)];
    }

    private static function ageOn(string $birthDate, string $onDate): ?int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate) !== 1) {
            return null;
        }

        $birth = strtotime($birthDate);
        $on    = strtotime($onDate);

        if ($birth === false || $on === false || $birth > $on) {
            return null;
        }

        return (int) ((new \DateTimeImmutable($birthDate))
            ->diff(new \DateTimeImmutable($onDate))->y);
    }

    /**
     * Combien de sorties par adhérent sur douze mois.
     *
     * La tranche « aucune sortie » est celle qui compte : elle chiffre les
     * adhérents qui paient sans jamais venir. Aucun autre écran ne les montre,
     * puisqu'ils n'apparaissent sur aucune liste d'inscrits.
     *
     * @return array{rows: list<array{label: string, count: int}>, total: int, since: string}
     */
    public static function participation(): array
    {
        global $wpdb;

        $members = self::activeMemberIds();
        $since   = gmdate('Y-m-d H:i:s', (int) strtotime(current_time('mysql') . ' -12 months'));

        if ($members === []) {
            return ['rows' => [], 'total' => 0, 'since' => $since];
        }

        // Seules les sorties *passées* comptent : une inscription à une sortie
        // de septembre n'est pas une participation, c'est une intention.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.user_id, COUNT(*) AS n
             FROM {$wpdb->prefix}sub_event_registrations r
             JOIN {$wpdb->prefix}sub_events e ON e.id = r.event_id
             WHERE r.status = 'confirmed' AND r.user_id IS NOT NULL
               AND e.starts_at BETWEEN %s AND %s
             GROUP BY r.user_id",
            $since,
            current_time('mysql')
        ), ARRAY_A) ?: [];

        $perMember = [];

        foreach ($rows as $row) {
            $perMember[(int) $row['user_id']] = (int) $row['n'];
        }

        $bands = array_fill(0, count(self::OUTING_BANDS), 0);

        foreach ($members as $memberId) {
            $outings = $perMember[$memberId] ?? 0;

            foreach (self::OUTING_BANDS as $index => [, $from, $to]) {
                if ($outings >= $from && $outings <= $to) {
                    $bands[$index]++;
                    break;
                }
            }
        }

        $result = [];

        foreach (self::OUTING_BANDS as $index => [$label]) {
            $result[] = ['label' => $label, 'count' => $bands[$index]];
        }

        return ['rows' => $result, 'total' => count($members), 'since' => $since];
    }

    // --- Argent ------------------------------------------------------------

    /**
     * Ce que pèsent formules, options et remises sur une campagne.
     *
     * Les lignes figées du dossier, pas les tarifs du catalogue : c'est ce qui
     * a réellement été facturé, remises comprises. Le total d'une campagne
     * close est le chiffre qu'attend le rapport financier.
     *
     * @return array{campaign: ?string, rows: list<array{label: string, amount: float}>,
     *               total: float, files: int}
     */
    public static function revenue(): array
    {
        $campaign = self::latestCampaign();

        if ($campaign === null) {
            return ['campaign' => null, 'rows' => [], 'total' => 0.0, 'files' => 0];
        }

        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.line_type, SUM(l.amount) AS total
             FROM {$wpdb->prefix}sub_application_lines l
             JOIN {$wpdb->prefix}sub_applications a ON a.id = l.application_id
             WHERE a.campaign_id = %d AND a.status NOT IN (%s, %s, %s)
             GROUP BY l.line_type",
            (int) $campaign['id'],
            ...self::NOT_RECEIVED
        ), ARRAY_A) ?: [];

        $labels  = ['plan' => 'Formules', 'option' => 'Options', 'discount' => 'Remises'];
        $amounts = [];

        foreach ($rows as $row) {
            $amounts[(string) $row['line_type']] = (float) $row['total'];
        }

        $result = [];

        foreach ($labels as $type => $label) {
            $result[] = ['label' => $label, 'amount' => round($amounts[$type] ?? 0.0, 2)];
        }

        $files = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sub_applications
             WHERE campaign_id = %d AND status NOT IN (%s, %s, %s)",
            (int) $campaign['id'],
            ...self::NOT_RECEIVED
        ));

        return [
            'campaign' => (string) $campaign['title'],
            'rows'     => $result,
            'total'    => round(array_sum(array_column($result, 'amount')), 2),
            'files'    => $files,
        ];
    }

    /**
     * Les options qui rapportent, et celles que personne ne prend.
     *
     * Une option souscrite trois fois dans l'année ne justifie pas la question
     * posée à trois cents personnes : ce graphique est celui qui fait
     * simplifier le formulaire d'adhésion.
     *
     * @return array{campaign: ?string, rows: list<array{label: string, amount: float, count: int}>}
     */
    public static function optionRevenue(): array
    {
        $campaign = self::latestCampaign();

        if ($campaign === null) {
            return ['campaign' => null, 'rows' => []];
        }

        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.label, SUM(l.amount) AS total, COUNT(*) AS n
             FROM {$wpdb->prefix}sub_application_lines l
             JOIN {$wpdb->prefix}sub_applications a ON a.id = l.application_id
             WHERE a.campaign_id = %d AND l.line_type = 'option'
               AND a.status NOT IN (%s, %s, %s)
             GROUP BY l.label ORDER BY total DESC LIMIT %d",
            ...array_merge(
                [(int) $campaign['id']],
                self::NOT_RECEIVED,
                [self::TOP_OPTIONS]
            )
        ), ARRAY_A) ?: [];

        return [
            'campaign' => (string) $campaign['title'],
            'rows'     => array_map(static fn (array $r): array => [
                'label'  => (string) $r['label'],
                'amount' => round((float) $r['total'], 2),
                'count'  => (int) $r['n'],
            ], $rows),
        ];
    }

    /**
     * Combien de jours s'écoulent entre le dépôt d'un dossier et son
     * encaissement.
     *
     * Le chèque qui dort trois mois dans un tiroir ne se voit nulle part : le
     * dossier est réglé, le trésorier a fait son travail, et pourtant la
     * trésorerie du club porte l'attente. La médiane vaut mieux que la moyenne,
     * qu'un seul dossier oublié suffirait à déplacer.
     *
     * @return array{rows: list<array{label: string, count: int}>, median: ?int, total: int}
     */
    public static function paymentDelay(): array
    {
        global $wpdb;

        $delays = array_map('intval', $wpdb->get_col(
            "SELECT DATEDIFF(p.received_on, DATE(a.submitted_at))
             FROM {$wpdb->prefix}sub_payments p
             JOIN {$wpdb->prefix}sub_applications a ON a.id = p.application_id
             WHERE p.status = 'received' AND p.received_on IS NOT NULL
               AND a.submitted_at IS NOT NULL"
        ) ?: []);

        if ($delays === []) {
            return ['rows' => [], 'median' => null, 'total' => 0];
        }

        // Un règlement enregistré avant le dépôt existe — saisie rétroactive,
        // chèque remis en main propre le jour même. On le ramène à zéro avant
        // tout calcul, pour que la médiane et les tranches parlent de la même
        // série.
        $delays = array_map(static fn (int $days): int => max(0, $days), $delays);
        $bands  = array_fill(0, count(self::DELAY_BANDS), 0);

        foreach ($delays as $delay) {
            foreach (self::DELAY_BANDS as $index => [, $from, $to]) {
                if ($delay >= $from && $delay <= $to) {
                    $bands[$index]++;
                    break;
                }
            }
        }

        sort($delays);
        $middle = (int) floor((count($delays) - 1) / 2);

        $rows = [];

        foreach (self::DELAY_BANDS as $index => [$label]) {
            $rows[] = ['label' => $label, 'count' => $bands[$index]];
        }

        return [
            'rows'   => $rows,
            'median' => $delays[$middle],
            'total'  => count($delays),
        ];
    }

    // --- Briques communes --------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    private static function latestCampaign(): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            "SELECT id, title FROM {$wpdb->prefix}sub_campaigns
             WHERE status <> 'draft' ORDER BY valid_from DESC LIMIT 1",
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Comptes dont l'adhésion court encore.
     *
     * @return list<int>
     */
    private static function activeMemberIds(): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$wpdb->usermeta}
             WHERE meta_key = 'sub_membership_valid_until' AND meta_value >= %s",
            current_time('Y-m-d')
        )) ?: []);
    }

    /**
     * Effectifs par valeur d'une méta, pour un jeu de comptes.
     *
     * @param list<int> $userIds
     * @return array<string, int>
     */
    private static function metaCounts(array $userIds, string $metaKey): array
    {
        global $wpdb;

        if ($userIds === []) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_value, COUNT(*) AS n FROM {$wpdb->usermeta}
             WHERE meta_key = %s AND meta_value <> ''
               AND user_id IN (" . self::placeholders($userIds) . ")
             GROUP BY meta_value",
            $metaKey,
            ...$userIds
        ), ARRAY_A) ?: [];

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['meta_value']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * @param list<int> $userIds
     * @return list<string>
     */
    private static function metaValues(array $userIds, string $metaKey): array
    {
        global $wpdb;

        if ($userIds === []) {
            return [];
        }

        return array_map('strval', $wpdb->get_col($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->usermeta}
             WHERE meta_key = %s AND meta_value <> ''
               AND user_id IN (" . self::placeholders($userIds) . ")",
            $metaKey,
            ...$userIds
        )) ?: []);
    }

    /**
     * Une liste de `%d` de la longueur voulue.
     *
     * Interpoler des entiers serait sans danger ici — ils sortent d'`intval` —
     * mais la règle du projet est qu'aucune requête ne se construit par
     * concaténation de valeurs. Les marqueurs, eux, se concatènent.
     *
     * @param list<int> $values
     */
    private static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '%d'));
    }
}
