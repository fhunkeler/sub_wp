<?php
/**
 * Test de fumée des sorties prévues, en tête de l'espace membre.
 *
 *   docker exec sub_demo_cli wp eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-dashboard-outings.php
 *
 * L'espace membre ne proposait que les sorties où le membre était déjà
 * admissible, et seulement après la rubrique « À faire ». Un certificat
 * expiré, une adhésion à renouveler, et l'écran ne montrait plus aucune
 * sortie : le membre concluait qu'il n'y en avait pas. Le bouton « S’inscrire »
 * renvoyait en outre vers l'agenda, où il fallait retrouver la sortie.
 *
 * Vérifié ici : les sorties passent avant le reste, chacune porte son
 * formulaire d'inscription, et celui qui ne peut pas s'inscrire lit pourquoi
 * au lieu de ne rien voir.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Events\EventTypeSeeder;
use Subalcatel\Club\Frontend\MemberDashboard;
use Subalcatel\Club\Identity\DiveLevels;

EventTypeSeeder::run();
add_filter('pre_wp_mail', '__return_true');

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-56s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$member = static function (string $level, bool $upToDate): int {
    $id = wp_insert_user([
        'user_login' => 'dash_' . wp_generate_password(8, false),
        'user_pass'  => wp_generate_password(),
        'first_name' => 'Test',
        'role'       => 'sub_member',
    ]);
    $term = get_term_by('slug', $level, DiveLevels::TAXONOMY);
    update_user_meta($id, 'sub_dive_level_id', $term->term_id);

    if ($upToDate) {
        update_user_meta($id, 'sub_membership_valid_until', '2027-12-31');
        sub_test_make_compliant($id);
    }

    return $id;
};

$aJour    = $member('p3', true);
$enRetard = $member('p3', false);
$dp       = $member('p5', true);
get_userdata($dp)->add_cap('sub_create_exploration_event');

$service = new EventService();
$titre   = 'Sortie tableau de bord ' . wp_generate_password(5, false);
$eventId = $service->create('plongee-exploration', [
    'title'           => $titre,
    'starts_at'       => gmdate('Y-m-d H:i:s', time() + 3 * 86400),
    'location'        => 'Trégastel',
    'capacity'        => 8,
    'accepted_levels' => ['p1', 'p2', 'p3', 'p5'],
], $dp);

$vue = static function (int $userId): string {
    wp_set_current_user($userId);

    return MemberDashboard::render();
};

// --- Membre à jour -----------------------------------------------------------
echo "\n--- Membre à jour ---\n";

$html = $vue($aJour);
$check('La sortie paraît dans l’espace', str_contains($html, esc_html($titre)));
$check('Les sorties passent avant « À faire » / « à jour »',
    strpos($html, 'Sorties prévues') !== false
    && strpos($html, 'Sorties prévues') < strpos($html, 'sub-card-ok')
    && strpos($html, 'Sorties prévues') < (strpos($html, 'sub-actions') ?: PHP_INT_MAX));
$check('Le formulaire d’inscription est sur place',
    str_contains($html, 'name="action" value="sub_event_register"')
    && str_contains($html, 'name="event_id" value="' . $eventId . '"'));

$service->register($eventId, $aJour);
$html = $vue($aJour);
$check('Une fois inscrit, il le lit', str_contains($html, 'Vous êtes inscrit.'));
$check('… et peut se désinscrire', str_contains($html, 'value="sub_event_cancel"'));

// --- Membre qui n'est pas à jour --------------------------------------------
echo "\n--- Membre qui n’est pas à jour ---\n";

$html = $vue($enRetard);
$check('La sortie paraît quand même', str_contains($html, esc_html($titre)));
$check('… avec le motif du refus', str_contains($html, 'Inscription impossible.'));
$check('… et sans formulaire d’inscription',
    !str_contains($html, 'name="event_id" value="' . $eventId . '"'));

// --- Ménage ------------------------------------------------------------------
global $wpdb;
$wpdb->delete("{$wpdb->prefix}sub_event_registrations", ['event_id' => $eventId]);
$wpdb->delete("{$wpdb->prefix}sub_events", ['id' => $eventId]);

require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ([$aJour, $enRetard, $dp] as $id) {
    sub_test_clean_documents($id);
    wp_delete_user($id);
}

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
