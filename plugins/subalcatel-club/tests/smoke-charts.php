<?php
/**
 * Test de fumée des courbes du tableau de bord.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-charts.php
 *
 * Ce qui casse sur un graphique ne se voit pas : une courbe fausse s'affiche
 * aussi bien qu'une courbe juste. On vérifie donc les chiffres avant le rendu,
 * et surtout les deux pièges du calcul :
 *
 *  - l'alignement par *jour de campagne*, sans quoi comparer deux saisons qui
 *    n'ouvrent pas le même jour n'a aucun sens ;
 *  - les états vides, qui doivent produire un cadre explicatif et non une
 *    division par zéro.
 *
 * Les données sont posées directement en base : ces fonctions lisent des
 * tables, pas des services, et les faire passer par tout le parcours métier
 * n'éprouverait rien de plus ici.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Admin\DashboardCharts;
use Subalcatel\Club\Documents\DocumentService;
use Subalcatel\Club\Documents\DocumentTypes;
use Subalcatel\Club\Membership\ApplicationService;

global $wpdb;

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-58s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$today  = current_time('Y-m-d');
$day    = static fn (int $offset): string => gmdate('Y-m-d', (int) strtotime($today . " {$offset} days"));
$suffix = wp_generate_password(6, false);

// Les courbes lisent « la campagne à montrer » : tant que d'autres campagnes
// sont ouvertes, le test n'est pas déterministe. On les met en brouillon le
// temps de la suite, et on les rétablit à la fin — y compris en cas d'échec.
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

// --- 1. États vides ----------------------------------------------------------

$empty = DashboardCharts::campaignCurve();

$check('Sans campagne, la courbe ne plante pas', $empty['campaign'] === null && $empty['points'] === []);
$check('Sans campagne, les étapes sont vides', DashboardCharts::applicationStages()['stages'] === []);

wp_set_current_user(0);
$officeId = wp_insert_user([
    'user_login' => 'chart_office_' . $suffix,
    'user_email' => 'chart_office_' . $suffix . '@subalcatel.test',
    'user_pass'  => wp_generate_password(),
    'role'       => \Subalcatel\Club\Identity\Roles::OFFICE,
]);
wp_set_current_user((int) $officeId);

ob_start();
DashboardCharts::render();
$blank = (string) ob_get_clean();

$check('L’état vide explique ce qui s’affichera',
    str_contains($blank, 'Aucune campagne n’est encore ouverte'),
    'un cadre absent ne dit pas au bureau que la courbe existe');

// --- 2. Courbe de campagne et comparaison N-1 --------------------------------

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

$previousId = $makeCampaign('chart-prev-' . $suffix, -400, -320, 'closed');
$currentId  = $makeCampaign('chart-curr-' . $suffix, -40, 40, 'open');

$makeApplication = static function (int $campaignId, string $status, string $submittedAt) use ($wpdb): void {
    $wpdb->insert("{$wpdb->prefix}sub_applications", [
        'reference'    => 'CHART-' . wp_generate_password(10, false),
        'user_id'      => null,
        'campaign_id'  => $campaignId,
        'plan_id'      => 0,
        'status'       => $status,
        'total_amount' => 144.00,
        'submitted_at' => $submittedAt . ' 09:00:00',
        // Volontairement le même jour pour tous : c'est le cas de la reprise
        // Joomla, où l'import a tout activé d'un coup. Si la courbe s'appuyait
        // sur `activated_at`, elle serait un mur vertical.
        'activated_at' => current_time('mysql'),
    ]);
};

foreach ([-35, -30, -30, -10] as $offset) {
    $makeApplication($currentId, ApplicationService::STATUS_ACTIVE, $day($offset));
}

$makeApplication($currentId, ApplicationService::STATUS_DRAFT, $day(-20));
$makeApplication($currentId, ApplicationService::STATUS_CANCELLED, $day(-20));
$makeApplication($currentId, ApplicationService::STATUS_AWAITING_PAYMENT, $day(-5));

// Saison passée : deux dossiers avant le 40e jour, un après.
$makeApplication($previousId, ApplicationService::STATUS_ACTIVE, $day(-397));
$makeApplication($previousId, ApplicationService::STATUS_ACTIVE, $day(-390));
$makeApplication($previousId, ApplicationService::STATUS_ACTIVE, $day(-350));

$curve = DashboardCharts::campaignCurve();

$check('La campagne ouverte est celle qui est suivie',
    $curve['campaign'] !== null && (int) $curve['campaign']['id'] === $currentId);
$check('L’étendue de la campagne est exacte', $curve['span'] === 80, 'jours : ' . $curve['span']);
$check('Le jour courant est le 40e', $curve['today'] === 40);
$check('Le cumul ignore brouillons, refus et annulations',
    $curve['total'] === 5,
    '4 actifs + 1 en attente de règlement, cumul = ' . $curve['total']);
$monotonic = true;

foreach ($curve['points'] as $offset => $value) {
    if ($offset > 0 && $value < $curve['points'][$offset - 1]) {
        $monotonic = false;
    }
}

$check('Le cumul ne redescend jamais', $monotonic && count($curve['points']) === 81);
// Un dossier déposé au 30e jour compte ce jour-là, pas le lendemain : c'est la
// borne qui fait qu'une comparaison à la veille d'une clôture reste juste.
$check('Le cumul de la veille vaut 3', ($curve['points'][29] ?? -1) === 3);
$check('Le dossier du 30e jour y est compté', ($curve['points'][30] ?? -1) === 4);

$check('La saison précédente est trouvée',
    $curve['previous'] !== null && $curve['previous']['total'] === 3);
$check('La comparaison se fait au même jour de campagne',
    $curve['previous']['at_today'] === 2,
    'au 40e jour de la saison passée : ' . ($curve['previous']['at_today'] ?? -1));

// --- 3. Répartition des dossiers ---------------------------------------------

$stages = DashboardCharts::applicationStages();
$byLabel = [];

foreach ($stages['stages'] as $stage) {
    $byLabel[$stage['label']] = $stage['count'];
}

$check('Les cinq étapes sont représentées', count($stages['stages']) === 5);
$check('Les brouillons sont comptés à part', ($byLabel['Commencés, jamais envoyés'] ?? -1) === 1);
$check('Les adhésions actives sont comptées', ($byLabel['Adhésions actives'] ?? -1) === 4);
$check('Une étape vide reste affichée', ($byLabel['Envoyés'] ?? -1) === 0,
    'une étape absente se lirait comme une étape supprimée');

// --- 4. Échéancier des certificats -------------------------------------------

$before = DashboardCharts::certificateSchedule();

$holders = [];

$makeCertificate = static function (string $validUntil) use ($wpdb, &$holders, $suffix): void {
    $userId = wp_insert_user([
        'user_login' => 'chart_' . wp_generate_password(8, false),
        'user_email' => wp_generate_password(8, false) . '@subalcatel.test',
        'user_pass'  => wp_generate_password(),
        'role'       => \Subalcatel\Club\Identity\Roles::MEMBER,
    ]);

    $holders[] = (int) $userId;

    $wpdb->insert("{$wpdb->prefix}sub_member_documents", [
        'user_id'       => (int) $userId,
        'type_slug'     => DocumentTypes::MEDICAL,
        'file_path'     => 'chart-test-' . $suffix . '.pdf',
        'original_name' => 'certificat.pdf',
        'mime_type'     => 'application/pdf',
        'file_size'     => 1024,
        'valid_until'   => $validUntil,
        'status'        => DocumentService::STATUS_VALID,
    ]);
};

$inThreeMonths = gmdate('Y-m-15', (int) strtotime(current_time('Y-m-01') . ' +3 months'));
$makeCertificate($inThreeMonths);
$makeCertificate($inThreeMonths);
$makeCertificate($day(-1));

$after = DashboardCharts::certificateSchedule();

$check('L’échéancier couvre douze mois', count($after['months']) === 12);
$check('Les échéances tombent dans le bon mois',
    $after['months'][3]['count'] - $before['months'][3]['count'] === 2,
    $after['months'][3]['label'] . ' : +' . ($after['months'][3]['count'] - $before['months'][3]['count']));
$check('Les certificats déjà échus sont signalés à part',
    $after['overdue'] - $before['overdue'] === 1,
    'ils sortent de l’échéancier mais restent bloquants');
$check('Un certificat échu ce mois-ci n’est pas compté deux fois',
    $after['months'][0]['count'] === $before['months'][0]['count'],
    'dans sa colonne ET dans les échus, il serait relancé deux fois');

// --- 5. Remplissage des sorties ----------------------------------------------

$wpdb->insert("{$wpdb->prefix}sub_events", [
    'type_id'    => 0,
    'title'      => 'Sortie test ' . $suffix,
    'slug'       => 'sortie-test-' . $suffix,
    'starts_at'  => gmdate('Y-m-d H:i:s', (int) strtotime(current_time('mysql') . ' +1 hour')),
    'capacity'   => 2,
    'status'     => 'published',
]);

$eventId = (int) $wpdb->insert_id;

foreach (['confirmed', 'confirmed', 'waiting'] as $index => $status) {
    $wpdb->insert("{$wpdb->prefix}sub_event_registrations", [
        'event_id' => $eventId,
        'user_id'  => 900000 + $index,
        'status'   => $status,
    ]);
}

$outings = DashboardCharts::outingFill();
$mine    = null;

foreach ($outings as $outing) {
    if ($outing['title'] === 'Sortie test ' . $suffix) {
        $mine = $outing;
    }
}

$check('La sortie à venir est reprise', $mine !== null);
$check('Inscrits et liste d’attente sont distingués',
    $mine !== null && $mine['confirmed'] === 2 && $mine['waiting'] === 1,
    'une sortie complète avec trois demandes n’est pas une sortie à trois inscrits');
$check('La capacité est celle de l’événement', $mine !== null && $mine['capacity'] === 2);

// --- 6. Rendu et droits ------------------------------------------------------

ob_start();
DashboardCharts::render();
$officeHtml = (string) ob_get_clean();

foreach ([
    'Adhésions reçues depuis l’ouverture',
    'Certificats médicaux arrivant à échéance',
    'Remplissage des prochaines sorties',
    'Où en sont les dossiers',
] as $title) {
    $check('Le bureau voit « ' . $title . ' »', str_contains($officeHtml, $title));
}

$check('La courbe est un SVG, sans script tiers',
    str_contains($officeHtml, '<svg') && !str_contains($officeHtml, '<script'));
$check('Les barres partagent leurs colonnes',
    str_contains($officeHtml, '<table class="sub-chart-bars">'),
    'en lignes indépendantes, une valeur plus longue décale sa propre piste');
$check('La courbe porte une description accessible',
    str_contains($officeHtml, 'role="img"') && str_contains($officeHtml, 'aria-label'));
$check('La comparaison est écrite en toutes lettres',
    str_contains($officeHtml, 'au même') && str_contains($officeHtml, '+3'),
    '5 dossiers contre 2 : la phrase porte l’information, pas la couleur');

$memberId = wp_insert_user([
    'user_login' => 'chart_member_' . $suffix,
    'user_email' => 'chart_member_' . $suffix . '@subalcatel.test',
    'user_pass'  => wp_generate_password(),
    'role'       => \Subalcatel\Club\Identity\Roles::MEMBER,
]);

wp_set_current_user((int) $memberId);

ob_start();
DashboardCharts::render();
$memberHtml = (string) ob_get_clean();

$check('Un adhérent ne voit pas la courbe des adhésions',
    !str_contains($memberHtml, 'Adhésions reçues depuis l’ouverture'));
$check('Un adhérent ne voit pas l’échéancier médical',
    !str_contains($memberHtml, 'Certificats médicaux arrivant à échéance'),
    'la validité des documents est une capacité, pas un détail d’affichage');

wp_set_current_user(0);

// --- Nettoyage ---------------------------------------------------------------

require_once ABSPATH . 'wp-admin/includes/user.php';

$wpdb->delete("{$wpdb->prefix}sub_event_registrations", ['event_id' => $eventId]);
$wpdb->delete("{$wpdb->prefix}sub_events", ['id' => $eventId]);
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->prefix}sub_applications WHERE campaign_id IN (%d, %d)",
    $currentId,
    $previousId
));
$wpdb->delete("{$wpdb->prefix}sub_campaigns", ['id' => $currentId]);
$wpdb->delete("{$wpdb->prefix}sub_campaigns", ['id' => $previousId]);

foreach ($holders as $holderId) {
    $wpdb->delete("{$wpdb->prefix}sub_member_documents", ['user_id' => $holderId]);
    wp_delete_user($holderId);
}

wp_delete_user((int) $officeId);
wp_delete_user((int) $memberId);

$restore();

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
