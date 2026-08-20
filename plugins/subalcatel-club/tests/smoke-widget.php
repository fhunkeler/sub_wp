<?php
/**
 * Test de fumée du bloc « Club » sur le tableau de bord de WordPress.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-widget.php
 *
 * Trois choses peuvent casser sans se voir :
 *
 *  - le bloc s'affiche pour qui n'a rien à y faire — un adhérent ordinaire n'a
 *    pas à savoir combien de dossiers attendent le trésorier ;
 *  - le compteur ment au-delà du plafond des listes, et un retard de trente
 *    dossiers s'annonce comme dix ;
 *  - la pastille du titre compte ce qui ne se traite pas, et ne retombe jamais
 *    à zéro — auquel cas plus personne ne la lit.
 */

require_once __DIR__ . '/helpers.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/dashboard.php';

// `add_meta_box` refuse sans écran courant, et WP-CLI n'en a pas : sans cette
// ligne, le test conclurait à tort que le bloc ne s'enregistre pour personne.
set_current_screen('dashboard');

use Subalcatel\Club\Admin\DashboardScreen;
use Subalcatel\Club\Admin\DashboardWidget;
use Subalcatel\Club\Identity\Roles;
use Subalcatel\Club\Membership\ApplicationService;

global $wpdb, $wp_meta_boxes;

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-58s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$suffix = wp_generate_password(6, false);

$makeUser = static function (string $prefix, string $role) use ($suffix): int {
    return (int) wp_insert_user([
        'user_login' => $prefix . '_' . $suffix,
        'user_email' => $prefix . '_' . $suffix . '@subalcatel.test',
        'user_pass'  => wp_generate_password(),
        'role'       => $role,
    ]);
};

$officeId = $makeUser('widget_office', Roles::OFFICE);
$memberId = $makeUser('widget_member', Roles::MEMBER);

/** Le bloc, tel que WordPress l'aurait enregistré pour cette personne. */
$registerFor = static function (int $userId) use (&$wp_meta_boxes): array {
    wp_set_current_user($userId);
    $wp_meta_boxes['dashboard']['normal']['core'] = ['dashboard_activity' => ['title' => 'Activité']];

    DashboardWidget::add();

    return $wp_meta_boxes['dashboard']['normal']['core'];
};

// --- 1. Qui voit le bloc -----------------------------------------------------

$forMember = $registerFor($memberId);

$check('Un adhérent ordinaire n’a pas le bloc',
    !isset($forMember['subalcatel_club_overview']),
    'le tableau de bord de WordPress est aussi le sien');

$forOffice = $registerFor($officeId);

$check('Le bureau a le bloc', isset($forOffice['subalcatel_club_overview']));
$check('Le bloc est en tête de colonne',
    array_key_first($forOffice) === 'subalcatel_club_overview',
    'sous « Activité », il passerait sous la ligne de flottaison');

// --- 2. Ce que le bloc annonce ----------------------------------------------

// Une campagne ouverte et plus de dossiers que le plafond des listes : c'est le
// cas où le compteur doit avouer qu'il ne sait pas compter au-delà.
$wpdb->insert("{$wpdb->prefix}sub_campaigns", [
    'title'       => 'widget-' . $suffix,
    'slug'        => 'widget-' . $suffix,
    'opens_on'    => gmdate('Y-m-d', strtotime('-10 days')),
    'closes_on'   => gmdate('Y-m-d', strtotime('+80 days')),
    'valid_from'  => gmdate('Y-m-d', strtotime('-10 days')),
    'valid_until' => gmdate('Y-m-d', strtotime('+80 days')),
    'status'      => 'draft',
]);
$campaignId = (int) $wpdb->insert_id;

$overflow = DashboardScreen::LIST_LIMIT + 2;

for ($i = 0; $i < $overflow; $i++) {
    $wpdb->insert("{$wpdb->prefix}sub_applications", [
        'reference'    => 'WIDGET-' . wp_generate_password(10, false),
        'user_id'      => $memberId,
        'campaign_id'  => $campaignId,
        'plan_id'      => 0,
        'status'       => ApplicationService::STATUS_PAYMENT_CONFIRMED,
        'total_amount' => 144.00,
        'submitted_at' => current_time('mysql'),
    ]);
}

// Le cache est posé par personne : on repasse par un nouveau compte pour que
// les dossiers ci-dessus soient réellement lus.
$secondOffice = $makeUser('widget_office2', Roles::OFFICE);
wp_set_current_user($secondOffice);

ob_start();
DashboardWidget::render();
$html = (string) ob_get_clean();

$check('Le bloc mène à l’écran des dossiers',
    str_contains($html, 'page=subalcatel-club-applications') || str_contains($html, 'Dossiers à valider'),
    'un compteur sans lien ne fait pas traiter la file');

$check('Au-delà du plafond, le compteur écrit « + »',
    str_contains($html, DashboardScreen::LIST_LIMIT . ' +'),
    sprintf('%d dossiers en attente, plafond à %d', $overflow, DashboardScreen::LIST_LIMIT));

$check('Le bloc renvoie vers la vue d’ensemble',
    str_contains($html, 'page=subalcatel-club"') || str_contains($html, 'Ouvrir la vue d’ensemble'));

$check('Les chiffres de fond accompagnent les files',
    str_contains($html, 'sub-widget__stats'),
    'le bureau gère les adhésions : il a droit aux compteurs');

// --- 3. La pastille du titre -------------------------------------------------

$wp_meta_boxes['dashboard']['normal']['core'] = [];
DashboardWidget::add();
$title = (string) $wp_meta_boxes['dashboard']['normal']['core']['subalcatel_club_overview']['title'];

$check('La pastille compte les dossiers en attente',
    str_contains($title, 'update-count'),
    'titre : ' . wp_strip_all_tags($title));

$check('Une file plafonnée s’annonce « + » jusque dans la pastille',
    str_contains($title, ' +<'),
    'titre : ' . wp_strip_all_tags($title));

// --- 4. Rien en attente ------------------------------------------------------

$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->prefix}sub_applications WHERE campaign_id = %d",
    $campaignId
));

$thirdOffice = $makeUser('widget_office3', Roles::OFFICE);
wp_set_current_user($thirdOffice);

ob_start();
DashboardWidget::render();
$idle = (string) ob_get_clean();

$check('Une file traitée disparaît du bloc',
    !str_contains($idle, 'Dossiers à valider'),
    'un compteur qui ne retombe pas ne se lit plus');

// L'état « rien à faire » ne se provoque pas sur une base de démonstration —
// des sorties y sont toujours programmées. On vérifie donc la phrase elle-même,
// là où elle est écrite, plutôt que de vider la base pour l'obtenir.
$check('Le bloc a une phrase pour l’absence de file',
    str_contains(
        (string) file_get_contents(dirname(__DIR__) . '/src/Admin/DashboardWidget.php'),
        'Rien n’attend le bureau'
    ),
    'un bloc vide et muet fait douter qu’il fonctionne');

// --- Nettoyage ---------------------------------------------------------------

wp_set_current_user(0);

require_once ABSPATH . 'wp-admin/includes/user.php';

$wpdb->delete("{$wpdb->prefix}sub_campaigns", ['id' => $campaignId]);

foreach ([$officeId, $memberId, $secondOffice, $thirdOffice] as $userId) {
    wp_delete_user($userId);
}

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
