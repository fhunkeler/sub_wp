<?php
/**
 * Test de fumée du module Événements.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-events.php
 *
 * Couvre ce qui casse en production : droits de création, motifs de refus,
 * capacité, liste d'attente et promotion.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Events\EventTypeSeeder;
use Subalcatel\Club\Identity\DiveLevels;

EventTypeSeeder::run();

$service  = new EventService();
$failures = 0;

$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-52s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$makeDiver = static function (string $levelSlug, bool $upToDate = true): int {
    $id = wp_insert_user([
        'user_login' => 'demo_' . wp_generate_password(8, false),
        'user_pass'  => wp_generate_password(),
        'role'       => 'sub_member',
    ]);

    $level = get_term_by('slug', $levelSlug, DiveLevels::TAXONOMY);
    update_user_meta($id, 'sub_dive_level_id', $level->term_id);

    if ($upToDate) {
        sub_test_make_compliant($id);
    }

    return $id;
};

$p5      = $makeDiver('p5');      // directeur de plongée
$p3      = $makeDiver('p3');      // autonome
$p1      = $makeDiver('p1');      // ni l'un ni l'autre
$expired = $makeDiver('p3', false);

foreach ([$p5, $p3, $p1] as $id) {
    $u = get_userdata($id);
    $u->add_cap('sub_create_exploration_event');
    $u->add_cap('sub_create_training_event');
}

$created = ['starts_at' => gmdate('Y-m-d H:i:s', time() + 7 * 86400)];

// --- Droits de création ------------------------------------------------------
echo "\n--- Qui peut créer quoi ---\n";

$eventId = $service->create('plongee-exploration', $created + [
    'title'           => 'Exploration au Squewel',
    'location'        => 'Ploumanac’h',
    'capacity'        => 2,
    'accepted_levels' => ['p1', 'p3', 'p5'],
], $p3);
$check('Un P3 crée une exploration', $eventId > 0);

try {
    $service->create('plongee-formation', $created + ['title' => 'Formation N2'], $p3);
    $check('Un P3 ne crée pas de formation', false);
} catch (RuntimeException $e) {
    $check('Un P3 ne crée pas de formation', true, $e->getMessage());
}

$formationId = $service->create('plongee-formation', $created + [
    'title'    => 'Formation N2 — fosse',
    'capacity' => 1,
], $p5);
$check('Un P5 crée une formation', $formationId > 0);

try {
    $service->create('plongee-exploration', $created + ['title' => 'Sortie interdite'], $p1);
    $check('Un P1 ne crée pas d’exploration', false);
} catch (RuntimeException $e) {
    $check('Un P1 ne crée pas d’exploration', true, $e->getMessage());
}

// --- Éligibilité --------------------------------------------------------------
echo "\n--- Éligibilité à l’inscription ---\n";

$d = $service->checkEligibility($eventId, $p1);
$check('P1 éligible (niveau accepté)', $d->allowed, $d->reason);

$d = $service->checkEligibility($eventId, $expired);
$check('Adhésion expirée refusée', !$d->allowed, $d->reason);

$d = $service->checkEligibility($formationId, $p1);
$check('Formation : niveau non listé accepté', $d->allowed, $d->reason ?: 'aucune contrainte de niveau');

// --- Capacité et liste d’attente ----------------------------------------------
echo "\n--- Capacité (2 places) et liste d’attente ---\n";

$r1 = $service->register($eventId, $p1);
$check('1re inscription confirmée', $r1['status'] === 'confirmed');

$r2 = $service->register($eventId, $p5);
$check('2e inscription confirmée', $r2['status'] === 'confirmed');

$r3 = $service->register($eventId, $p3);
$check('3e inscription en liste d’attente', $r3['status'] === 'waiting', 'position ' . $r3['position']);

try {
    $service->register($eventId, $p1);
    $check('Double inscription refusée', false);
} catch (RuntimeException $e) {
    $check('Double inscription refusée', true, $e->getMessage());
}

$check('2 places confirmées occupées', $service->confirmedCount($eventId) === 2);

// --- Désinscription et promotion ------------------------------------------------
echo "\n--- Désinscription et promotion ---\n";

$promoted = $service->cancel($eventId, $p1);
$check('Le premier en attente est promu', $promoted === $p3, 'utilisateur ' . (string) $promoted);
$check('Toujours 2 places occupées', $service->confirmedCount($eventId) === 2);

// --- Liste du directeur de plongée ------------------------------------------------
echo "\n--- Liste du directeur de plongée ---\n";

update_user_meta($p5, 'sub_emergency_contact', 'Marie D. — 06 12 34 56 78');
$participants = $service->participants($eventId);

$check('Liste des participants renseignée', count($participants) === 2, count($participants) . ' inscrits');

$first = $participants[0];
$check('Niveau affiché', $first['level'] !== '—', $first['level']);
$check('Validité du certificat exposée', array_key_exists('medical_ok', $first), $first['medical_ok'] ? 'valide' : 'invalide');
$check('Aucun document exposé', !array_key_exists('medical_file', $first));

// --- Nettoyage --------------------------------------------------------------------
// Les événements créés par le test sont supprimés : sans cela, ils
// apparaîtraient dans l'agenda de démonstration.
global $wpdb;
foreach ([$eventId, $formationId] as $id) {
    $wpdb->delete("{$wpdb->prefix}sub_event_registrations", ['event_id' => $id]);
    $wpdb->delete("{$wpdb->prefix}sub_events", ['id' => $id]);
}

require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ([$p5, $p3, $p1, $expired] as $id) {
    sub_test_clean_documents($id);
    wp_delete_user($id);
}

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
