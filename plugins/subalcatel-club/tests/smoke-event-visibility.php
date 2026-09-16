<?php
/**
 * Test de fumée de la diffusion ciblée des événements.
 *
 *   docker exec sub_demo_cli wp eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-event-visibility.php
 *
 * Deux choses se vérifient ici, et elles vont ensemble.
 *
 * D'abord, qui voit quoi. Annoncer une réunion du bureau à cent trente
 * adhérents, ou une plongée technique à qui n'a pas le niveau d'y venir, ce
 * n'est pas informer : au bout de quelques envois, plus personne ne lit
 * l'agenda. Le sens du défaut compte autant que la règle — une sortie qui
 * n'exige aucun niveau s'adresse à tous, faute de quoi oublier de cocher
 * reviendrait à n'annoncer à personne.
 *
 * Ensuite, qui encadre. Le compte d'administration a tous les droits WordPress
 * et aucun niveau de plongée : il ne pouvait ouvrir aucune plongée, et rien ne
 * le lui permettait. Il le peut désormais en désignant un directeur de plongée,
 * et c'est sur la personne désignée que porte l'exigence de niveau — jamais sur
 * celle qui saisit.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Events\EventTypeSeeder;
use Subalcatel\Club\Identity\DiveLevels;

EventTypeSeeder::run();

$service  = new EventService();
$failures = 0;

$check = static function (string $label, bool $ok, string $note = ''): void {
    global $failures;
    $failures += $ok ? 0 : 1;
    printf("%s  %-54s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$makeDiver = static function (string $levelSlug, string $role = 'sub_member'): int {
    $id = wp_insert_user([
        'user_login' => 'vis_' . wp_generate_password(8, false),
        'user_pass'  => wp_generate_password(),
        'role'       => $role,
    ]);

    $level = get_term_by('slug', $levelSlug, DiveLevels::TAXONOMY);
    update_user_meta($id, 'sub_dive_level_id', $level->term_id);
    sub_test_make_compliant($id);

    return $id;
};

$p5     = $makeDiver('p5');                  // directeur de plongée
$p3     = $makeDiver('p3');                  // autonome
$p1     = $makeDiver('p1');                  // ni l'un ni l'autre
$office = $makeDiver('p3', 'sub_office');    // membre du bureau

// --- Qui voit quoi -----------------------------------------------------------
echo "\n--- Qui voit quoi ---\n";

$vue = static fn (string $visibility, array $levels, int $userId): bool
    => $service->mayView([
        'visibility'      => $visibility,
        'accepted_levels' => wp_json_encode($levels),
        'organizer_id'    => 0,
        'dive_leader_id'  => 0,
    ], $userId);

$check('L’assemblée générale s’adresse à tous', $vue('members', [], $p1));
$check('La réunion du bureau reste au bureau', $vue('office', [], $office));
$check('… et n’atteint pas les autres membres', !$vue('office', [], $p1));
$check('Une sortie P3 atteint un P3', $vue('levels', ['p3'], $p3));
$check('… et pas un P1', !$vue('levels', ['p3'], $p1));
$check('Une sortie sans niveau s’adresse à tous', $vue('levels', [], $p1));
$check('Un visiteur non connecté ne voit rien', !$vue('members', [], 0));

// L'organisateur garde sa sortie sous les yeux : sans cela, le secrétariat
// perdrait de vue ce qu'il vient d'ouvrir.
$check('L’organisateur voit sa propre sortie', $service->mayView([
    'visibility' => 'office', 'accepted_levels' => '[]',
    'organizer_id' => $p1, 'dive_leader_id' => 0,
], $p1));

$check('Le directeur de plongée voit la sienne', $service->mayView([
    'visibility' => 'office', 'accepted_levels' => '[]',
    'organizer_id' => 0, 'dive_leader_id' => $p1,
], $p1));

// Une base migrée depuis une version sans la colonne rend une chaîne vide :
// elle doit valoir « tout le monde », jamais « personne ».
$check('Une visibilité inconnue ouvre à tous', $vue('', [], $p1));
$check('… et se normalise', EventService::normalizeVisibility('n’importe quoi') === 'members');

// --- Qui encadre -------------------------------------------------------------
echo "\n--- Encadrement délégué ---\n";

$check('Le bureau peut désigner quelqu’un', EventService::mayDelegate($office));
$check('Un membre ordinaire ne le peut pas', !EventService::mayDelegate($p1));

// Le cas qui bloquait : tous les droits WordPress, aucun niveau de plongée.
// C'est le compte d'administration du site, celui depuis lequel le club ouvre
// ses sorties. Le rôle « Membre du bureau » n'a pas, lui, les droits de créer
// une plongée — c'est un réglage distinct, et il n'a pas changé.
$sansNiveau = wp_insert_user([
    'user_login' => 'vis_' . wp_generate_password(8, false),
    'user_pass'  => wp_generate_password(),
    'role'       => 'administrator',
]);

$creables = array_column($service->creatableTypesFor($sansNiveau), 'slug');
$check('Un gestionnaire sans niveau peut ouvrir une plongée',
    in_array('plongee-exploration', $creables, true),
    implode(', ', $creables));

$demain  = ['starts_at' => gmdate('Y-m-d H:i:s', time() + 7 * 86400)];
$eventId = $service->create('plongee-formation', $demain + [
    'title'          => 'Formation désignée ' . wp_generate_password(5, false),
    'dive_leader_id' => $p5,
], $sansNiveau);

$event = $service->find($eventId);
$check('La plongée retient la personne désignée', (int) $event['dive_leader_id'] === $p5);
$check('… et copie la visibilité de son type', $event['visibility'] === 'levels', (string) $event['visibility']);

// L'exigence de niveau n'a pas disparu : elle a changé de cible.
try {
    $service->create('plongee-formation', $demain + [
        'title'          => 'Refus attendu',
        'dive_leader_id' => $p1,
    ], $sansNiveau);
    $check('Une personne désignée sans le niveau est refusée', false);
} catch (RuntimeException $e) {
    $check('Une personne désignée sans le niveau est refusée', true, $e->getMessage());
}

// Et personne ne se désigne un remplaçant sans en avoir le droit.
try {
    $service->create('plongee-exploration', $demain + [
        'title'          => 'Délégation refusée',
        'dive_leader_id' => $p5,
    ], $p3);
    $check('Un membre ordinaire ne délègue pas', false);
} catch (RuntimeException $e) {
    $check('Un membre ordinaire ne délègue pas', true, $e->getMessage());
}

// --- Le formulaire ne demande pas ce qui n'a pas de sens ---------------------
echo "\n--- Niveau demandé, ou non ---\n";

$check('Une plongée est reconnue comme telle',
    EventTypeSeeder::isDivingType($service->typeBySlug('plongee-exploration')));
$check('Une assemblée générale ne l’est pas',
    !EventTypeSeeder::isDivingType($service->typeBySlug('assemblee-generale')));
$check('Une réunion du bureau non plus',
    !EventTypeSeeder::isDivingType($service->typeBySlug('reunion-bureau')));

// --- Ménage ------------------------------------------------------------------
global $wpdb;
foreach ($wpdb->get_col($wpdb->prepare(
    "SELECT id FROM {$wpdb->prefix}sub_events WHERE organizer_id = %d",
    $sansNiveau
)) as $id) {
    $wpdb->delete("{$wpdb->prefix}sub_event_registrations", ['event_id' => (int) $id]);
    $wpdb->delete("{$wpdb->prefix}sub_events", ['id' => (int) $id]);
}

require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ([$p5, $p3, $p1, $office, $sansNiveau] as $id) {
    sub_test_clean_documents($id);
    wp_delete_user($id);
}

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
