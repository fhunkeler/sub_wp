<?php
/**
 * Test de fumée — liste sociale des participants.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-participants.php
 *
 * Un membre voit avec qui il plonge — nom, niveau, statut, et le mot que chacun
 * partage. Ce qui compte, et ce que ce test garde : la vue sociale ne laisse
 * JAMAIS filtrer ce qui est réservé au directeur de plongée (téléphone, contact
 * d'urgence, état médical, représentant d'un mineur), et elle reste fermée à
 * qui n'est pas à jour de cotisation.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Content\ClubDocuments;
use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Events\EventTypeSeeder;
use Subalcatel\Club\Events\RegistrationFields;
use Subalcatel\Club\Identity\DiveLevels;

global $wpdb;
EventTypeSeeder::run();
add_filter('pre_wp_mail', '__return_true');

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-56s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$member = static function (string $name, string $level, bool $upToDate = true) use ($wpdb): int {
    $id = wp_insert_user([
        'user_login'   => 'demo_' . wp_generate_password(8, false),
        'user_email'   => wp_generate_password(8, false) . '@subalcatel.test',
        'user_pass'    => wp_generate_password(),
        'display_name' => $name,
        'role'         => 'sub_member',
    ]);
    $term = get_term_by('slug', $level, DiveLevels::TAXONOMY);
    update_user_meta($id, 'sub_dive_level_id', $term->term_id);
    update_user_meta($id, 'sub_phone', '06 12 34 56 78');
    update_user_meta($id, 'sub_emergency_contact', 'Contact urgence 0900000000');

    if ($upToDate) {
        update_user_meta($id, 'sub_membership_valid_until', '2027-12-31');
        sub_test_make_compliant($id);
    }

    return $id;
};

$lea = $member('Léa Vidal', 'p3');
$tom = $member('Tom Riou', 'p2');
$dp  = $member('Sophie Cariou', 'p5');
get_userdata($dp)->add_cap('sub_create_exploration_event');

$service = new EventService();
$eventId = $service->create('plongee-exploration', [
    'title'           => 'Sortie test participants',
    'starts_at'       => gmdate('Y-m-d H:i:s', time() + 7 * 86400),
    'location'        => 'Ploumanac’h',
    'capacity'        => 1, // force la liste d'attente au 2e
    'accepted_levels' => ['p1', 'p2', 'p3', 'p5'],
], $dp);

$service->register($eventId, $lea, [
    'shared_note'  => 'Je fête mon N2, tournée après !',
    'conviviality' => '1',
    'comment'      => 'Allergie iode — réservé au bureau',
]);
$service->register($eventId, $tom, ['shared_note' => 'Cherche un binôme autonome']);

// --- Ce que la vue sociale montre --------------------------------------------
echo "\n--- Contenu de la vue sociale ---\n";

$people = $service->socialParticipants($eventId, $tom);

$check('Les deux inscrits apparaissent', count($people) === 2, count($people) . ' personne(s)');
$check('Le niveau est affiché', $people[0]['level'] !== '', 'utile pour choisir un binôme');
$check('La note partagée est reprise',
    in_array('Je fête mon N2, tournée après !', array_column($people, 'note'), true));
$check('Le premier inscrit est confirmé, le second en attente',
    $people[0]['status'] === 'confirmed' && $people[1]['status'] === 'waiting',
    'capacité 1 : le second passe en liste d’attente');
$check('Le lecteur se reconnaît',
    array_values(array_filter($people, static fn (array $p): bool => $p['is_self']))[0]['name'] === 'Tom Riou');

$leaRow = array_values(array_filter($people, static fn (array $p): bool => $p['name'] === 'Léa Vidal'))[0];
$tomRow = array_values(array_filter($people, static fn (array $p): bool => $p['name'] === 'Tom Riou'))[0];
$check('Le moment convivial est signalé pour qui l’a coché', $leaRow['conviviality'] === true);
$check('Et pas pour qui ne l’a pas coché', $tomRow['conviviality'] === false,
    'la case n’est pas cochée par défaut');

// --- Ce que la vue sociale ne montre JAMAIS ----------------------------------
echo "\n--- Étanchéité vis-à-vis des données du DP ---\n";

$exposed = wp_json_encode($service->socialParticipants($eventId, $tom));

foreach ([
    'téléphone'            => '06 12 34 56 78',
    'contact d’urgence'    => 'Contact urgence',
    'commentaire privé'    => 'Allergie iode',
    'adresse e-mail'       => '@subalcatel.test',
] as $label => $secret) {
    $check(sprintf('La vue sociale ne fuit pas : %s', $label), !str_contains($exposed, $secret));
}

// Le DP, lui, garde tout — c'est son rôle.
$dpView = wp_json_encode($service->participants($eventId));
$check('Le directeur de plongée garde le téléphone', str_contains($dpView, '06 12 34 56 78'),
    'la vue DP et la vue sociale sont deux choses distinctes');
$check('Et le contact d’urgence', str_contains($dpView, 'Contact urgence'));

// --- Réservé aux adhérents à jour --------------------------------------------
echo "\n--- Qui a le droit de voir la liste ---\n";

$upToDate = $member('Membre À Jour', 'p2');
$lapsed   = $member('Adhésion Expirée', 'p1', false);

$check('Un adhérent à jour peut voir la liste', ClubDocuments::isActiveMember($upToDate));
$check('Une adhésion expirée ne le peut pas', !ClubDocuments::isActiveMember($lapsed),
    'la carte s’affiche avec le nombre de places, jamais les noms');
$check('Un visiteur non connecté non plus', !ClubDocuments::isActiveMember(0));

// --- Le champ « note partagée » est bien distinct du commentaire -------------
echo "\n--- Séparation note partagée / commentaire privé ---\n";

$fields = RegistrationFields::all();
$check('Le champ « note partagée » existe', isset($fields['shared_note']));
$check('Il est marqué comme partagé', !empty($fields['shared_note']['shared']));
$check('Le commentaire n’est pas marqué partagé', empty($fields['comment']['shared'] ?? false),
    'il reste réservé au bureau');
$check('La note partagée fait partie des champs d’une plongée',
    in_array('shared_note', RegistrationFields::divingDefaults(), true));

$check('La case « moment convivial » existe et est partagée',
    isset($fields['conviviality']) && !empty($fields['conviviality']['shared'])
    && $fields['conviviality']['type'] === 'boolean');
$check('Elle fait partie des champs d’une plongée',
    in_array('conviviality', RegistrationFields::divingDefaults(), true));
$check('Le commentaire libre partagé est un vrai champ de saisie',
    ($fields['shared_note']['type'] ?? '') === 'textarea',
    'texte libre, pas une case');

// --- Nettoyage ---------------------------------------------------------------
require_once ABSPATH . 'wp-admin/includes/user.php';
$wpdb->delete("{$wpdb->prefix}sub_event_registrations", ['event_id' => $eventId]);
$wpdb->delete("{$wpdb->prefix}sub_events", ['id' => $eventId]);
foreach ([$lea, $tom, $dp, $upToDate, $lapsed] as $id) {
    sub_test_clean_documents($id);
    wp_delete_user($id);
}

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
