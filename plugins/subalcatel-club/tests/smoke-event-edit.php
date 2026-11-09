<?php
/**
 * Test de fumée : corriger une sortie ouverte, désigner son directeur de
 * plongée, et afficher son heure sans décalage.
 *
 *   docker exec sub_demo_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-event-edit.php
 *
 * Né d'un retour du bureau : des séances de piscine affichées une heure trop
 * tard, un directeur de plongée impossible à choisir pour la piscine, et un
 * titre qu'on ne corrigeait qu'en annulant la séance.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Events\EventTypeSeeder;
use Subalcatel\Club\Frontend\MemberDashboard;
use Subalcatel\Club\Identity\DiveLevels;

EventTypeSeeder::run();

$service  = new EventService();
$failures = 0;

$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-56s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$makeUser = static function (string $role, string $levelSlug = ''): int {
    $id = wp_insert_user([
        'user_login' => 'demo_' . wp_generate_password(8, false),
        'user_pass'  => wp_generate_password(),
        'role'       => $role,
    ]);

    if ($levelSlug !== '') {
        $level = get_term_by('slug', $levelSlug, DiveLevels::TAXONOMY);
        update_user_meta($id, 'sub_dive_level_id', $level->term_id);
    }

    return $id;
};

$office = $makeUser('sub_office');
get_userdata($office)->add_cap('sub_create_training_event');
$p5     = $makeUser('sub_member', 'p5');
$p3     = $makeUser('sub_member', 'p3');
$member = $makeUser('sub_member', 'p1');

foreach ([$p5, $p3] as $id) {
    sub_test_make_compliant($id);
    get_userdata($id)->add_cap('sub_create_training_event');
}

$created = ['starts_at' => gmdate('Y-m-d H:i:s', time() + 7 * 86400)];
$events  = [];

// --- Heure affichée -------------------------------------------------------------
echo "\n--- Heure affichée ---\n";

$timezone = get_option('timezone_string');
update_option('timezone_string', 'Europe/Paris');

$check(
    'Séance d’hiver à 20 h affichée à 20 h',
    str_contains(MemberDashboard::frDateTime('2026-12-08 20:00:00'), '20h00'),
    MemberDashboard::frDateTime('2026-12-08 20:00:00')
);
$check(
    'Séance d’été à 20 h affichée à 20 h',
    str_contains(MemberDashboard::frDateTime('2027-06-08 20:00:00'), '20h00'),
    MemberDashboard::frDateTime('2027-06-08 20:00:00')
);

update_option('timezone_string', $timezone);

// --- Directeur de plongée d'une séance de piscine ----------------------------------
echo "\n--- Désigner le directeur de plongée ---\n";

$poolId = $service->create('seance-piscine', $created + [
    'title'          => 'Piscine du mardi',
    'dive_leader_id' => $p5,
], $office);
$events[] = $poolId;
$check('Le bureau désigne le DP d’une séance de piscine', (int) $service->find($poolId)['dive_leader_id'] === $p5);

try {
    $events[] = $service->create('seance-piscine', $created + [
        'title'          => 'Piscine fantôme',
        'dive_leader_id' => 999999999,
    ], $office);
    $check('Un compte inexistant n’est pas désignable', false);
} catch (RuntimeException $e) {
    $check('Un compte inexistant n’est pas désignable', true, $e->getMessage());
}

// --- Corriger la sortie -------------------------------------------------------------
echo "\n--- Modifier sans annuler ---\n";

$slug = (string) $service->find($poolId)['slug'];

$service->update($poolId, ['title' => 'Piscine du mardi — fosse', 'location' => 'Lannion'], $office);
$event = $service->find($poolId);
$check('Le titre est corrigé', $event['title'] === 'Piscine du mardi — fosse');
$check('Le lieu est corrigé', $event['location'] === 'Lannion');
$check('L’adresse de la sortie ne change pas', $event['slug'] === $slug);
$check('La sortie reste ouverte', $event['status'] === 'published');

global $wpdb;
$logged = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sub_audit_log
     WHERE action = 'event.updated' AND entity_id = %d",
    $poolId
));
$check('La modification est journalisée', $logged === 1);

try {
    $service->update($poolId, ['title' => 'Piratée'], $member);
    $check('Un simple membre ne modifie pas la sortie', false);
} catch (RuntimeException $e) {
    $check('Un simple membre ne modifie pas la sortie', true, $e->getMessage());
}

$service->update($poolId, ['title' => 'Piscine du mardi — fosse', 'dive_leader_id' => $p3], $office);
$check('Le bureau change le DP après coup', (int) $service->find($poolId)['dive_leader_id'] === $p3);

$formationId = $service->create('plongee-formation', $created + ['title' => 'Formation N2'], $p5);
$events[]    = $formationId;

try {
    $service->update($formationId, ['title' => 'Formation N2', 'dive_leader_id' => $p3], $office);
    $check('Une formation refuse un DP sans le niveau', false);
} catch (RuntimeException $e) {
    $check('Une formation refuse un DP sans le niveau', true, $e->getMessage());
}

try {
    $service->update($formationId, ['title' => 'Formation N2', 'dive_leader_id' => $office], $p5);
    $check('L’organisateur ne délègue pas sans en avoir le droit', false);
} catch (RuntimeException $e) {
    $check('L’organisateur ne délègue pas sans en avoir le droit', true, $e->getMessage());
}

$service->update($formationId, ['title' => 'Formation N2 — fosse'], $p5);
$check('L’organisateur corrige son titre', $service->find($formationId)['title'] === 'Formation N2 — fosse');

// --- Nettoyage ----------------------------------------------------------------------
foreach ($events as $id) {
    $wpdb->delete("{$wpdb->prefix}sub_event_registrations", ['event_id' => $id]);
    $wpdb->delete("{$wpdb->prefix}sub_events", ['id' => $id]);
}

require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ([$office, $p5, $p3, $member] as $id) {
    sub_test_clean_documents($id);
    wp_delete_user($id);
}

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
