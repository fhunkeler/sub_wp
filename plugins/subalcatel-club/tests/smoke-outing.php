<?php
/**
 * Créer une sortie depuis l'espace membre.
 *
 * Deux questions, et une seule réponse attendue pour chacune :
 *   - le droit d'ouvrir une sortie suit-il le niveau de plongée, sans qu'on
 *     touche au compte ?
 *   - ce droit s'arrête-t-il exactement là où il doit — pas de formation pour
 *     un autonome, rien du tout pour une adhésion périmée, et surtout aucune
 *     porte ouverte vers l'administration du site ?
 *
 * Lancement :
 *   wp eval-file wp-content/plugins/subalcatel-club/tests/smoke-outing.php
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Events\EventTypeSeeder;
use Subalcatel\Club\Frontend\OutingForm;
use Subalcatel\Club\Identity\DerivedCapabilities;
use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Identity\Roles;

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-58s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

add_filter('pre_wp_mail', '__return_true');

DiveLevels::seed();
EventTypeSeeder::run();

$created = [];

$makeDiver = static function (string $levelSlug, bool $upToDate = true) use (&$created): int {
    $id = wp_insert_user([
        'user_login' => 'demo_' . wp_generate_password(8, false),
        'user_email' => wp_generate_password(8, false) . '@subalcatel.test',
        'user_pass'  => wp_generate_password(),
        'role'       => Roles::MEMBER,
    ]);

    $term = get_term_by('slug', $levelSlug, DiveLevels::TAXONOMY);
    update_user_meta($id, 'sub_dive_level_id', $term->term_id);

    if ($upToDate) {
        sub_test_make_compliant($id);
    }

    $created[] = $id;

    return $id;
};

$p5      = $makeDiver('p5');          // directeur de plongée
$p3      = $makeDiver('p3');          // autonome
$p1      = $makeDiver('p1');          // ni l'un ni l'autre
$expired = $makeDiver('p3', false);   // autonome, adhésion périmée

$service = new EventService();

// --- Le droit suit le niveau -------------------------------------------------
echo "\n--- Droits déduits du niveau ---\n";

$slugs = static function (array $types): array {
    return array_map(static fn (array $t): string => (string) $t['slug'], $types);
};

$check('Un autonome peut ouvrir une exploration',
    in_array('plongee-exploration', $slugs($service->creatableTypesFor($p3)), true),
    'aucune capacité ajoutée à la main sur le compte');

$check('Sans pouvoir ouvrir une formation',
    !in_array('plongee-formation', $slugs($service->creatableTypesFor($p3)), true));

$check('Un directeur de plongée peut ouvrir une formation',
    in_array('plongee-formation', $slugs($service->creatableTypesFor($p5)), true));

$check('Un P1 ne peut rien ouvrir',
    $service->creatableTypesFor($p1) === [],
    'son niveau ne porte aucun drapeau');

$check('Une adhésion périmée ferme le droit',
    $service->creatableTypesFor($expired) === [],
    'le droit revient au renouvellement, sans intervention du bureau');

// Le point sensible : un droit déduit ne doit jamais entrouvrir l'administration.
$check('Aucune porte vers le menu Club',
    !user_can($p5, 'sub_manage_memberships') && !user_can($p5, 'sub_validate_account'),
    'un DP n’est pas pour autant au bureau');

// --- Le niveau change, le droit suit ----------------------------------------
echo "\n--- Un passage de niveau ---\n";

$p2 = get_term_by('slug', 'p2', DiveLevels::TAXONOMY);
$p3Term = get_term_by('slug', 'p3', DiveLevels::TAXONOMY);

update_user_meta($p3, 'sub_dive_level_id', $p2->term_id);
DerivedCapabilities::forget();

$check('Rétrogradé en P2, il ne peut plus ouvrir de sortie',
    $service->creatableTypesFor($p3) === []);

update_user_meta($p3, 'sub_dive_level_id', $p3Term->term_id);
DerivedCapabilities::forget();

$check('Revenu P3, il le peut de nouveau',
    in_array('plongee-exploration', $slugs($service->creatableTypesFor($p3)), true),
    'aucune capacité à repositionner sur le compte');

// --- Le formulaire de l'espace membre ---------------------------------------
echo "\n--- Formulaire [subalcatel_creer_sortie] ---\n";

wp_set_current_user($p3);
$html = OutingForm::render();

$check('Le formulaire s’affiche pour un autonome',
    str_contains($html, 'name="type_slug"') && str_contains($html, 'sub_outing_create'));
$check('Il ne propose que ce que le service acceptera',
    str_contains($html, 'plongee-exploration') && !str_contains($html, 'plongee-formation'));
$check('Il est protégé par un jeton',
    str_contains($html, '_wpnonce'));

wp_set_current_user($p1);
$refusal = OutingForm::render();

$check('Un P1 lit un motif, pas un refus sec',
    str_contains($refusal, 'niveau') && !str_contains($refusal, 'name="type_slug"'),
    'le motif est ce qui évite l’appel au président');

wp_set_current_user($expired);
$check('L’adhésion périmée est nommée comme telle',
    str_contains(OutingForm::render(), 'adhésion'));

wp_set_current_user(0);
$check('Un visiteur est invité à se connecter',
    str_contains(OutingForm::render(), 'Se connecter'));

// --- Cohérence des dates -----------------------------------------------------
echo "\n--- Dates incohérentes ---\n";

$base = [
    'title'     => 'Sortie de contrôle',
    'starts_at' => gmdate('Y-m-d H:i:s', time() + 7 * 86400),
];

try {
    $service->create('plongee-exploration', $base + [
        'ends_at' => gmdate('Y-m-d H:i:s', time() + 6 * 86400),
    ], $p3);
    $check('Une fin avant le début est refusée', false);
} catch (RuntimeException $e) {
    $check('Une fin avant le début est refusée', true, $e->getMessage());
}

try {
    $service->create('plongee-exploration', $base + [
        'registration_closes_at' => gmdate('Y-m-d H:i:s', time() + 8 * 86400),
    ], $p3);
    $check('Une clôture après le départ est refusée', false);
} catch (RuntimeException $e) {
    $check('Une clôture après le départ est refusée', true, $e->getMessage());
}

$eventId = $service->create('plongee-exploration', $base + [
    'location' => 'Trégastel',
    'capacity' => 4,
], $p3);

$check('La sortie créée porte son organisateur',
    (int) $service->find($eventId)['organizer_id'] === $p3,
    'c’est ce qui lui donne la liste des inscrits et le droit de leur écrire');

// --- Nettoyage ---------------------------------------------------------------

global $wpdb;
$wpdb->delete("{$wpdb->prefix}sub_event_registrations", ['event_id' => $eventId]);
$wpdb->delete("{$wpdb->prefix}sub_events", ['id' => $eventId]);

require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ($created as $id) {
    wp_delete_user($id);
}

echo $failures === 0
    ? "\n✓ Tous les contrôles passent.\n"
    : "\n✗ {$failures} échec(s).\n";
