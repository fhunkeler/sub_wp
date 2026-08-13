<?php
/**
 * Test de fumée des statistiques annuelles.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-stats.php
 *
 * Ces figures agrègent *toute* la base — l'effectif à jour, l'ensemble des
 * règlements. Un test qui poserait des chiffres absolus casserait au premier
 * adhérent ajouté ailleurs. On mesure donc des **écarts** : on relève l'état
 * avant, on injecte des cas connus, et on vérifie le déplacement.
 *
 * Les deux pièges du calcul, qu'on éprouve nommément :
 *
 *  - le renouvellement croise deux campagnes compte par compte ; un dossier
 *    annulé ou en brouillon n'est pas un renouvellement ;
 *  - la participation ne compte que les sorties *passées* — une inscription à
 *    une sortie de septembre est une intention, pas une participation.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Admin\AnnualCharts;
use Subalcatel\Club\Admin\StatisticsScreen;
use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Identity\Roles;
use Subalcatel\Club\Membership\ApplicationService;

global $wpdb;

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-58s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$suffix = wp_generate_password(6, false);
$today  = current_time('Y-m-d');
$day    = static fn (int $offset): string => gmdate('Y-m-d', (int) strtotime($today . " {$offset} days"));

// Les recettes et le renouvellement portent sur « la dernière campagne » :
// tant que d'autres campagnes existent, le test n'est pas déterministe.
$saved = $wpdb->get_results("SELECT id, status FROM {$wpdb->prefix}sub_campaigns", ARRAY_A) ?: [];
$wpdb->query("UPDATE {$wpdb->prefix}sub_campaigns SET status = 'draft'");

$restore = static function () use ($wpdb, $saved): void {
    foreach ($saved as $row) {
        $wpdb->update(
            "{$wpdb->prefix}sub_campaigns",
            ['status' => (string) $row['status']],
            ['id' => (int) $row['id']]
        );
    }
};

// --- 0. États vides ----------------------------------------------------------

$check('Sans deuxième campagne, le renouvellement s’abstient',
    AnnualCharts::renewal()['previous'] === null,
    'il se mesure d’une campagne à la suivante, pas dans l’absolu');
$check('Sans campagne, les recettes ne plantent pas',
    AnnualCharts::revenue()['campaign'] === null);

$beforeLevels        = AnnualCharts::diveLevels();
$beforeAges          = AnnualCharts::ageBands();
$beforeParticipation = AnnualCharts::participation();
$beforeDelay         = AnnualCharts::paymentDelay();

// --- 1. Deux campagnes et leurs dossiers -------------------------------------

$makeCampaign = static function (string $slug, int $opens, int $closes, string $status) use ($wpdb, $day): int {
    $wpdb->insert("{$wpdb->prefix}sub_campaigns", [
        'title'       => $slug,
        'slug'        => $slug,
        'opens_on'    => $day($opens),
        'closes_on'   => $day($closes),
        'valid_from'  => $day($opens),
        'valid_until' => $day($closes),
        'status'      => $status,
    ]);

    return (int) $wpdb->insert_id;
};

$previousId = $makeCampaign('stats-prev-' . $suffix, -400, -320, 'closed');
$currentId  = $makeCampaign('stats-curr-' . $suffix, -40, 40, 'open');

$members = [];
$levels  = [];

foreach (DiveLevels::ordered() as $term) {
    $levels[$term->slug] = (int) $term->term_id;
}

$makeMember = static function (string $birthDate, ?string $levelSlug, bool $upToDate)
    use ($wpdb, &$members, $levels): int {
    $userId = (int) wp_insert_user([
        'user_login' => 'stats_' . wp_generate_password(8, false),
        'user_email' => wp_generate_password(8, false) . '@subalcatel.test',
        'user_pass'  => wp_generate_password(),
        'role'       => Roles::MEMBER,
    ]);

    $members[] = $userId;

    if ($birthDate !== '') {
        update_user_meta($userId, 'sub_birth_date', $birthDate);
    }

    if ($levelSlug !== null && isset($levels[$levelSlug])) {
        update_user_meta($userId, 'sub_dive_level_id', (string) $levels[$levelSlug]);
    }

    if ($upToDate) {
        update_user_meta($userId, 'sub_membership_valid_until', gmdate('Y-m-d', strtotime('+6 months')));
    }

    return $userId;
};

$applications = [];

$makeApplication = static function (int $campaignId, int $userId, string $status, int $submittedOffset)
    use ($wpdb, $day, &$applications): int {
    $wpdb->insert("{$wpdb->prefix}sub_applications", [
        'reference'    => 'STATS-' . wp_generate_password(10, false),
        'user_id'      => $userId,
        'campaign_id'  => $campaignId,
        'plan_id'      => 0,
        'status'       => $status,
        'total_amount' => 144.00,
        'submitted_at' => $day($submittedOffset) . ' 09:00:00',
    ]);

    $id = (int) $wpdb->insert_id;
    $applications[] = $id;

    return $id;
};

// Trois fidèles, deux perdus, deux nouveaux. Les deux « perdus » restent à jour
// d'adhésion pour montrer que renouvellement et effectif ne se confondent pas.
$loyal = [
    $makeMember('1990-05-12', 'p2', true),
    $makeMember('1985-03-08', 'p3', true),
    $makeMember('2010-09-14', 'p1', true),
];
// Deux anniversaires calculés, pas écrits : l'un a exactement 50 ans
// aujourd'hui, l'autre exactement 60. C'est la borne haute des tranches qu'on
// éprouve — écrire « 1975 » ferait passer le test cette année et échouer la
// suivante.
$birthdayOf = static fn (int $years): string => gmdate('Y-m-d', (int) strtotime($today . " -{$years} years"));

$lost = [
    $makeMember($birthdayOf(50), 'p4', true),
    $makeMember($birthdayOf(60), 'p5', true),
];
$newcomers = [$makeMember('', 'p2', true), $makeMember('1995-07-30', null, true)];
$lapsed    = $makeMember('1980-02-02', 'p2', false);

foreach (array_merge($loyal, $lost) as $userId) {
    $makeApplication($previousId, $userId, ApplicationService::STATUS_ACTIVE, -390);
}

$makeApplication($previousId, $lapsed, ApplicationService::STATUS_CANCELLED, -390);

foreach (array_merge($loyal, $newcomers) as $userId) {
    $makeApplication($currentId, $userId, ApplicationService::STATUS_ACTIVE, -30);
}

// Un dossier annulé cette saison : ni renouvellement, ni recette.
$makeApplication($currentId, $lost[0], ApplicationService::STATUS_CANCELLED, -30);

$renewal = AnnualCharts::renewal();

$check('La base est l’effectif de la saison passée', $renewal['base'] === 5,
    'cinq dossiers reçus, l’annulé exclu — base : ' . $renewal['base']);
$check('Les renouvellements sont croisés compte par compte', $renewal['renewed'] === 3);
$check('Un dossier annulé n’est pas un renouvellement', $renewal['lost'] === 2,
    'sinon le club se croirait fidèle alors qu’il a perdu deux adhérents');
$check('Les nouveaux sont distingués des revenants', $renewal['newcomers'] === 2);

// --- 2. Niveaux, âges, participation -----------------------------------------

$levelsAfter = AnnualCharts::diveLevels();
$agesAfter   = AnnualCharts::ageBands();

$check('L’effectif de référence est celui à jour d’adhésion',
    $levelsAfter['total'] - $beforeLevels['total'] === 7,
    'sept adhérents à jour ajoutés, le dormant exclu');

$countOf = static function (array $data, string $label): int {
    foreach ($data['rows'] as $row) {
        if ($row['label'] === $label) {
            return (int) $row['count'];
        }
    }

    return 0;
};

$p2Term = get_term($levels['p2'] ?? 0, DiveLevels::TAXONOMY);
$p2Name = $p2Term instanceof WP_Term ? $p2Term->name : 'P2';

$check('Les niveaux sont comptés sur cet effectif',
    $countOf($levelsAfter, $p2Name) - $countOf($beforeLevels, $p2Name) === 2,
    'deux P2 à jour ; le troisième n’a plus d’adhésion');
$check('Un niveau non renseigné est dit, pas masqué',
    $countOf($levelsAfter, 'Niveau non renseigné') - $countOf($beforeLevels, 'Niveau non renseigné') === 1);

$check('Un mineur tombe dans la bonne tranche',
    $countOf($agesAfter, 'Moins de 18 ans') - $countOf($beforeAges, 'Moins de 18 ans') === 1);
$check('Le jour de ses 50 ans, on entre dans la tranche',
    $countOf($agesAfter, '50 – 59 ans') - $countOf($beforeAges, '50 – 59 ans') === 1);
$check('Le jour de ses 60 ans, on en sort',
    $countOf($agesAfter, '60 – 69 ans') - $countOf($beforeAges, '60 – 69 ans') === 1,
    'la borne haute est exclue : sinon les deux tranches se recouvrent');
$check('Une fiche sans date de naissance est signalée',
    $agesAfter['unknown'] - $beforeAges['unknown'] === 1,
    'elle sort de la répartition, elle ne disparaît pas');

$events = [];

$makeEvent = static function (int $daysFromNow, array $participants) use ($wpdb, &$events): int {
    $wpdb->insert("{$wpdb->prefix}sub_events", [
        'type_id'   => 0,
        'title'     => 'Sortie stats ' . wp_generate_password(6, false),
        'slug'      => 'stats-' . wp_generate_password(8, false),
        'starts_at' => gmdate('Y-m-d H:i:s', (int) strtotime(current_time('mysql') . " {$daysFromNow} days")),
        'capacity'  => 20,
        'status'    => 'published',
    ]);

    $eventId  = (int) $wpdb->insert_id;
    $events[] = $eventId;

    foreach ($participants as $userId) {
        $wpdb->insert("{$wpdb->prefix}sub_event_registrations", [
            'event_id' => $eventId,
            'user_id'  => $userId,
            'status'   => 'confirmed',
        ]);
    }

    return $eventId;
};

$makeEvent(-30, [$loyal[0], $loyal[1]]);
$makeEvent(-10, [$loyal[0]]);
// Sortie à venir : elle ne doit déplacer personne de tranche.
$makeEvent(+10, [$loyal[1], $loyal[2]]);
// Sortie d'il y a deux ans : hors de la fenêtre de douze mois.
$makeEvent(-800, [$loyal[2]]);

$participation = AnnualCharts::participation();

$check('Deux sorties passées placent dans « 1 à 2 »',
    $countOf($participation, '1 à 2 sorties') - $countOf($beforeParticipation, '1 à 2 sorties') === 2,
    'un adhérent à deux sorties, un autre à une seule');
$check('Une sortie à venir n’est pas une participation',
    $countOf($participation, '3 à 5 sorties') === $countOf($beforeParticipation, '3 à 5 sorties'),
    'sinon s’inscrire suffirait à être compté comme actif');
$check('Une sortie d’il y a deux ans est hors fenêtre',
    $countOf($participation, 'Aucune sortie') - $countOf($beforeParticipation, 'Aucune sortie') === 5,
    'cinq des sept adhérents ajoutés n’ont rien fait sur douze mois');

// --- 3. Recettes et délais ---------------------------------------------------

$lines = [
    ['plan', 'Adhésion Plongée', 144.00],
    ['option', 'Prêt d’un bloc', 36.00],
    ['option', 'Prêt d’un détendeur', 24.00],
    ['discount', 'Remise Nokia', -58.00],
];

foreach ($applications as $applicationId) {
    $status = (string) $wpdb->get_var($wpdb->prepare(
        "SELECT status FROM {$wpdb->prefix}sub_applications WHERE id = %d",
        $applicationId
    ));

    foreach ($lines as [$type, $label, $amount]) {
        $wpdb->insert("{$wpdb->prefix}sub_application_lines", [
            'application_id' => $applicationId,
            'line_type'      => $type,
            'label'          => $label,
            'amount'         => $amount,
        ]);
    }
}

$revenue = AnnualCharts::revenue();
$amountOf = static function (array $data, string $label): float {
    foreach ($data['rows'] as $row) {
        if ($row['label'] === $label) {
            return (float) $row['amount'];
        }
    }

    return 0.0;
};

$check('Les recettes portent sur la dernière campagne',
    $revenue['campaign'] === 'stats-curr-' . $suffix && $revenue['files'] === 5,
    'cinq dossiers reçus sur six, l’annulé exclu — ' . $revenue['files']);
$check('Les formules sont sommées', abs($amountOf($revenue, 'Formules') - 720.00) < 0.01);
$check('Les options aussi', abs($amountOf($revenue, 'Options') - 300.00) < 0.01);
$check('Les remises restent négatives', abs($amountOf($revenue, 'Remises') + 290.00) < 0.01,
    'une remise comptée en positif gonflerait la recette de deux fois son montant');
$check('Le total est la somme signée', abs($revenue['total'] - 730.00) < 0.01);

$options = AnnualCharts::optionRevenue();

$check('Les options sont classées par poids',
    $options['rows'] !== [] && $options['rows'][0]['label'] === 'Prêt d’un bloc',
    'le classement est ce qui fait simplifier le formulaire');
$check('Chaque option porte son nombre de souscriptions',
    ($options['rows'][0]['count'] ?? 0) === 5);

$payments = [];

$makePayment = static function (int $applicationId, int $delayDays) use ($wpdb, $day, &$payments): void {
    $submittedAt = (string) $wpdb->get_var($wpdb->prepare(
        "SELECT submitted_at FROM {$wpdb->prefix}sub_applications WHERE id = %d",
        $applicationId
    ));

    $wpdb->insert("{$wpdb->prefix}sub_payments", [
        'application_id' => $applicationId,
        'amount'         => 144.00,
        'method'         => 'cheque',
        'status'         => 'received',
        'received_on'    => gmdate('Y-m-d', (int) strtotime($submittedAt . " {$delayDays} days")),
    ]);

    $payments[] = (int) $wpdb->insert_id;
};

$makePayment($applications[0], 3);
$makePayment($applications[1], 45);
// Règlement enregistré avant le dépôt : saisie rétroactive, ramené à zéro.
$makePayment($applications[2], -4);

$delay = AnnualCharts::paymentDelay();

$check('Chaque règlement tombe dans sa tranche',
    $countOf($delay, 'Moins d’une semaine') - $countOf($beforeDelay, 'Moins d’une semaine') === 2,
    'trois jours, et un délai négatif ramené à zéro');
$check('Un délai d’un mois et demi est vu comme tel',
    $countOf($delay, '1 à 2 mois') - $countOf($beforeDelay, '1 à 2 mois') === 1);
$check('La médiane existe dès le premier règlement', $delay['median'] !== null);

// --- 4. Rendu et droits ------------------------------------------------------

$officeId = (int) wp_insert_user([
    'user_login' => 'stats_office_' . $suffix,
    'user_email' => 'stats_office_' . $suffix . '@subalcatel.test',
    'user_pass'  => wp_generate_password(),
    'role'       => Roles::OFFICE,
]);

wp_set_current_user($officeId);

ob_start();
StatisticsScreen::renderClub();
StatisticsScreen::renderFinances();
$html = (string) ob_get_clean();

foreach ([
    'Renouvellement',
    'Niveaux de plongée',
    'Tranches d’âge',
    'Participation aux sorties',
    'Recettes par nature',
    'Ce que pèsent les options',
    'Délai d’encaissement',
] as $title) {
    $check('Le bureau voit « ' . $title . ' »', str_contains($html, $title));
}

$check('Les montants sont formatés en euros', str_contains($html, '720,00 €'));
$check('Aucun script tiers', !str_contains($html, '<script'));

// Le secrétariat n'a pas les finances, la trésorerie n'a pas les personnes :
// c'est l'onglet qui disparaît, pas seulement son contenu.
$treasurerId = (int) wp_insert_user([
    'user_login' => 'stats_treasury_' . $suffix,
    'user_email' => 'stats_treasury_' . $suffix . '@subalcatel.test',
    'user_pass'  => wp_generate_password(),
    'role'       => Roles::MEMBER,
]);

$treasurer = new WP_User($treasurerId);
$treasurer->add_cap('sub_export_payments');
wp_set_current_user($treasurerId);

ob_start();
StatisticsScreen::render();
$treasuryHtml = (string) ob_get_clean();

$check('La trésorerie voit les recettes', str_contains($treasuryHtml, 'Recettes par nature'));
$check('La trésorerie ne voit pas la pyramide des âges',
    !str_contains($treasuryHtml, 'Tranches d’âge'),
    'un onglet sans capacité n’est pas affiché, pas même vide');

wp_set_current_user(0);

// --- Nettoyage ---------------------------------------------------------------

require_once ABSPATH . 'wp-admin/includes/user.php';

foreach ($events as $eventId) {
    $wpdb->delete("{$wpdb->prefix}sub_event_registrations", ['event_id' => $eventId]);
    $wpdb->delete("{$wpdb->prefix}sub_events", ['id' => $eventId]);
}

foreach ($applications as $applicationId) {
    $wpdb->delete("{$wpdb->prefix}sub_application_lines", ['application_id' => $applicationId]);
    $wpdb->delete("{$wpdb->prefix}sub_payments", ['application_id' => $applicationId]);
    $wpdb->delete("{$wpdb->prefix}sub_applications", ['id' => $applicationId]);
}

$wpdb->delete("{$wpdb->prefix}sub_campaigns", ['id' => $currentId]);
$wpdb->delete("{$wpdb->prefix}sub_campaigns", ['id' => $previousId]);

foreach (array_merge($members, [$officeId, $treasurerId]) as $userId) {
    wp_delete_user($userId);
}

$restore();

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
