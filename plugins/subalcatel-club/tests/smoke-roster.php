<?php
/**
 * Test de fumée — la liste des inscrits côté espace membre.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-roster.php
 *
 * L'écran [OutingRoster] sort du wp-admin ce qui n'y était accessible qu'au
 * bureau. Trois questions, et une seule réponse acceptable pour chacune :
 *
 *   - l'organisateur retrouve-t-il, au bord de l'eau, ce dont il a besoin —
 *     niveau, téléphone, contact d'urgence, représentant d'un mineur, validité
 *     des documents, feuille d'émargement ?
 *   - la page reste-t-elle fermée à qui n'est pas l'organisateur, sachant
 *     qu'une page publique se visite avec n'importe quel identifiant dans
 *     l'URL ?
 *   - le contenu d'un document médical reste-t-il hors de portée, comme dans
 *     l'écran d'administration ?
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Events\EventTypeSeeder;
use Subalcatel\Club\Frontend\MemberDashboard;
use Subalcatel\Club\Frontend\OutingRoster;
use Subalcatel\Club\Frontend\Pages;
use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Identity\Roles;
use Subalcatel\Club\Setup\SiteMap;

global $wpdb;

DiveLevels::seed();
EventTypeSeeder::run();
add_filter('pre_wp_mail', '__return_true');

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-58s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$member = static function (string $name, string $level, bool $upToDate = true): int {
    $id = wp_insert_user([
        'user_login'   => 'demo_' . wp_generate_password(8, false),
        'user_email'   => wp_generate_password(8, false) . '@subalcatel.test',
        'user_pass'    => wp_generate_password(),
        'display_name' => $name,
        'role'         => Roles::MEMBER,
    ]);

    $term = get_term_by('slug', $level, DiveLevels::TAXONOMY);
    update_user_meta($id, 'sub_dive_level_id', $term->term_id);
    update_user_meta($id, 'sub_phone', '06 12 34 56 78');
    update_user_meta($id, 'sub_emergency_contact', 'Yann Le Guen 0296000000');

    if ($upToDate) {
        sub_test_make_compliant($id);
    }

    return $id;
};

$dp      = $member('Sophie Cariou', 'p5');
$lea     = $member('Léa Vidal', 'p3');
$noe     = $member('Noé Tanguy', 'p1');       // mineur, certificat périmé entre-temps
$curieux = $member('Marc Curieux', 'p2');      // ni organisateur, ni inscrit
$autreDp = $member('Yves Autre', 'p3');        // organisateur, mais d'une autre sortie

// Un mineur : le représentant légal doit remonter dans la liste du DP.
update_user_meta($noe, 'sub_birth_date', gmdate('Y-m-d', strtotime('-15 years')));
update_user_meta($noe, 'sub_guardian_name', 'Claire Tanguy');
update_user_meta($noe, 'sub_guardian_email', 'claire.tanguy@subalcatel.test');
update_user_meta($noe, 'sub_guardian_phone', '0612000000');

$service = new EventService();

$eventId = $service->create('plongee-exploration', [
    'title'           => 'Sortie de contrôle — émargement',
    'starts_at'       => gmdate('Y-m-d H:i:s', time() + 7 * 86400),
    'location'        => 'Ploumanac’h — Pors Kamor',
    'capacity'        => 1, // force la liste d'attente au second inscrit
    'accepted_levels' => [],
], $dp);

// Une sortie qui n'est pas la sienne : c'est elle qui doit rester fermée.
$autre = $service->create('plongee-exploration', [
    'title'     => 'Sortie d’un autre organisateur',
    'starts_at' => gmdate('Y-m-d H:i:s', time() + 8 * 86400),
], $autreDp);

$service->register($eventId, $lea, ['comment' => 'Allergie iode — réservé au bureau']);
$service->register($eventId, $noe, []);

// Le certificat de Noé tombe entre son inscription et le départ. C'est le cas
// qui justifie la colonne « Documents » : au bord de l'eau, le DP ne relit pas
// les dossiers, il regarde une pastille.
sub_test_clean_documents($noe);

$render = static function (int $viewerId, int $eventId = 0): string {
    wp_set_current_user($viewerId);
    $_GET = $eventId > 0 ? ['sortie' => (string) $eventId] : [];

    $html  = OutingRoster::render();
    $_GET  = [];

    return $html;
};

// --- Le shortcode est bien branché -------------------------------------------
echo "\n--- Câblage ---\n";

$check('Le shortcode [subalcatel_mes_sorties_organisees] existe',
    shortcode_exists('subalcatel_mes_sorties_organisees'));

$keys = array_column(SiteMap::pages(), 'key');
$check('La page figure au plan du site', in_array(Pages::MY_OUTINGS, $keys, true));

$page = array_values(array_filter(
    SiteMap::pages(),
    static fn (array $p): bool => $p['key'] === Pages::MY_OUTINGS
))[0] ?? [];

$check('Elle porte le shortcode et se range sous l’agenda',
    str_contains((string) ($page['content'] ?? ''), '[subalcatel_mes_sorties_organisees]')
    && ($page['parent'] ?? '') === Pages::AGENDA);
$check('Elle est réservée aux membres connectés',
    ($page['visibility'] ?? '') === \Subalcatel\Club\Content\Visibility::CONNECTED);
$check('Et volontairement hors menu', !isset($page['menu']),
    'elle ne concerne que les organisateurs');

// --- La liste des sorties organisées -----------------------------------------
echo "\n--- Liste de mes sorties ---\n";

$index = $render($dp);

$check('L’organisateur voit sa sortie',
    str_contains($index, 'Sortie de contrôle — émargement'));
$check('Avec le compte des inscrits',
    str_contains($index, '1 inscrit') && str_contains($index, '1 en attente'),
    'capacité 1 : le second passe en liste d’attente');
$check('Et le lien vers la liste des inscrits',
    str_contains($index, 'sortie=' . $eventId));

$check('Il ne voit pas les sorties des autres',
    !str_contains($index, 'Sortie d’un autre organisateur'));

$check('Un membre sans sortie lit un motif, pas un tableau vide',
    str_contains($render($lea), 'Aucune sortie à votre nom'));

wp_set_current_user(0);
$check('Un visiteur est invité à se connecter',
    str_contains(OutingRoster::render(), 'Se connecter'));

// --- Ce que l'organisateur trouve dans la liste -------------------------------
echo "\n--- Contenu de la liste des inscrits ---\n";

$roster = $render($dp, $eventId);

$check('L’annonce aux membres est proposée',
    str_contains($roster, 'Annoncer la sortie aux membres'),
    'l’action manuelle, à côté du message aux inscrits');
$check('Avec l’effectif annoncé avant le clic',
    str_contains($roster, 'Envoyer à '),
    'on doit savoir à combien de personnes on écrit');

foreach ([
    'le nom de l’inscrit'      => 'Léa Vidal',
    'son niveau'               => 'P3',
    'son téléphone'            => '06 12 34 56 78',
    'la personne à prévenir'   => 'Yann Le Guen',
    'le représentant légal'    => 'Claire Tanguy',
    'la liste d’attente'       => 'Liste d’attente',
    'le commentaire au bureau' => 'Allergie iode',
] as $label => $needle) {
    $check(sprintf('La liste porte %s', $label), str_contains($roster, $needle));
}

$check('Le mineur est signalé comme tel', str_contains($roster, 'mineur'),
    'un mineur en sortie engage le club différemment');
$check('Le téléphone s’appelle d’un doigt', str_contains($roster, 'href="tel:'),
    'lu sur un téléphone, au bord de l’eau');

$check('La validité des documents est affichée dans les deux sens',
    str_contains($roster, 'À jour') && str_contains($roster, 'À vérifier'),
    'Léa est en règle, Noé n’a rien déposé');

$check('La feuille d’émargement est prête à l’impression',
    str_contains($roster, 'sub-roster-sheet')
    && str_contains($roster, 'sub-roster__sign')
    && str_contains($roster, 'window.print()'));

$check('Les commandes ne s’impriment pas', str_contains($roster, 'sub-noprint'),
    'la feuille papier ne porte que la liste');

// --- Le document lui-même n'est jamais exposé --------------------------------
echo "\n--- Étanchéité des documents ---\n";

$paths = $wpdb->get_col($wpdb->prepare(
    "SELECT file_path FROM {$wpdb->prefix}sub_member_documents WHERE user_id = %d",
    $lea
));

$leak = false;
foreach ($paths as $path) {
    $leak = $leak
        || str_contains($roster, (string) $path)
        || str_contains($roster, basename((string) $path));
}

$check('Aucun chemin de document dans la page', !$leak && $paths !== [],
    sprintf('%d document(s) déposé(s), 0 exposé', count($paths)));
$check('Aucun lien de téléchargement', !str_contains($roster, 'sub_document'),
    'l’organisateur lit une validité, jamais un certificat');

// --- La page reste fermée à qui n'est pas l'organisateur ---------------------
echo "\n--- Garde de propriété ---\n";

$intrus = $render($curieux, $eventId);

$check('Un tiers ne lit pas la liste', str_contains($intrus, 'Sortie introuvable'));
$check('Et rien ne filtre au passage',
    !str_contains($intrus, 'Léa Vidal')
    && !str_contains($intrus, '06 12 34 56 78')
    && !str_contains($intrus, 'Yann Le Guen'));
$check('Le motif ne dit pas si la sortie existe',
    str_contains($intrus, 'n’existe pas, ou vous n’en êtes pas l’organisateur'),
    'distinguer les deux cartographierait les identifiants');

$check('Un inscrit n’est pas pour autant organisateur',
    str_contains($render($lea, $eventId), 'Sortie introuvable'));

// --- Écrire aux inscrits ------------------------------------------------------
echo "\n--- Message aux inscrits ---\n";

$check('Le formulaire d’envoi est proposé à l’organisateur',
    str_contains($roster, 'sub_outing_message'));
$check('Il est protégé par un jeton', str_contains($roster, '_wpnonce'));

$result = $service->messageParticipants($eventId, 'Rendez-vous 8h', 'Au parking.', $dp, true);
$check('L’organisateur atteint ses deux inscrits', $result['recipients'] === 2,
    'liste d’attente comprise');

$sansAttente = $service->messageParticipants($eventId, 'Rendez-vous 8h', 'Au parking.', $dp, false);
$check('La liste d’attente peut être exclue', $sansAttente['recipients'] === 1);

try {
    $service->messageParticipants($eventId, 'Objet', 'Message', $curieux, true);
    $check('Un tiers ne peut pas écrire aux inscrits', false);
} catch (RuntimeException $e) {
    $check('Un tiers ne peut pas écrire aux inscrits', true, $e->getMessage());
}

// --- Désinscrire quelqu'un ----------------------------------------------------
echo "\n--- Désinscription par l’organisateur ---\n";

$check('Le bouton de désinscription est présent',
    str_contains($roster, 'sub_outing_unregister'));

// Le jeton porte l'identifiant de la sortie : celui d'une sortie ne vaut pas
// pour une autre, faute de quoi un organisateur pourrait vider la liste du
// voisin avec son propre jeton.
wp_set_current_user($dp);
$nonce = wp_create_nonce('sub_outing_unregister_' . $eventId);
$check('Le jeton est propre à la sortie',
    wp_verify_nonce($nonce, 'sub_outing_unregister_' . $eventId) !== false
    && wp_verify_nonce($nonce, 'sub_outing_unregister_' . $autre) === false);

$service->cancel($eventId, $lea);
$apres = $render($dp, $eventId);

$check('La personne désinscrite quitte la liste', !str_contains($apres, 'Léa Vidal'));
$check('Et la place libérée profite à la liste d’attente',
    str_contains($apres, 'Noé Tanguy') && !str_contains($apres, 'Liste d’attente'),
    'la promotion est le travail du service, pas de l’écran');

// --- Le raccourci du tableau de bord -----------------------------------------
echo "\n--- Raccourci depuis l’espace membre ---\n";

$check('L’organisateur est reconnu comme tel', OutingRoster::organisesAnything($dp));
$check('Un membre qui n’a rien ouvert ne l’est pas', !OutingRoster::organisesAnything($lea),
    'le raccourci n’apparaît que pour qui a une liste à consulter');

if (Pages::exists(Pages::MY_OUTINGS)) {
    wp_set_current_user($dp);
    $check('Le tableau de bord porte le lien',
        str_contains(MemberDashboard::render(), Pages::url(Pages::MY_OUTINGS)));

    wp_set_current_user($lea);
    $check('Et le cache à qui n’organise rien',
        !str_contains(MemberDashboard::render(), Pages::url(Pages::MY_OUTINGS)));
} else {
    echo "     (page non installée : réinstallez le plan du site pour vérifier le lien)\n";
}

// --- Nettoyage ---------------------------------------------------------------

wp_set_current_user(0);
require_once ABSPATH . 'wp-admin/includes/user.php';

foreach ([$eventId, $autre] as $id) {
    $wpdb->delete("{$wpdb->prefix}sub_event_registrations", ['event_id' => $id]);
    $wpdb->delete("{$wpdb->prefix}sub_events", ['id' => $id]);
}

foreach ([$dp, $lea, $noe, $curieux, $autreDp] as $id) {
    sub_test_clean_documents($id);
    wp_delete_user($id);
}

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
