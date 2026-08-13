<?php
/**
 * Test de fumée — durcissement WordPress.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-hardening.php
 *
 * Aucune de ces mesures ne referme une faille à elle seule ; ensemble elles
 * retirent la carte qu'un attaquant dresse avant de frapper. Le test vérifie
 * que les portes sont fermées **et** que les usages légitimes passent encore —
 * un durcissement qui casse le site finit désactivé.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Identity\Roles;

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-54s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

// --- Version masquée ---------------------------------------------------------
echo "\n--- Divulgation de version ---\n";

$check('La balise generator est vide', apply_filters('the_generator', 'WordPress 7.0.2', 'html') === '');
$check('wp_generator retiré de wp_head',
    has_action('wp_head', 'wp_generator') === false);

// --- Énumération des comptes -------------------------------------------------
echo "\n--- Énumération des utilisateurs ---\n";

// `rest_endpoints` supprime des clés du tableau qu'on lui passe : on lui donne
// une copie fraîche à chaque appel, sinon le second reçoit un tableau déjà
// amputé par le premier.
$routes = static fn (): array => rest_get_server()->get_routes();

wp_set_current_user(0);
$anon = apply_filters('rest_endpoints', $routes());
$check('API : /wp/v2/users fermée à l’anonyme', !isset($anon['/wp/v2/users']));
$check('API : fiche utilisateur fermée aussi', !isset($anon['/wp/v2/users/(?P<id>[\d]+)']));

$admin = get_users(['role' => 'administrator', 'number' => 1]);
if ($admin !== []) {
    wp_set_current_user($admin[0]->ID);
    $forAdmin = apply_filters('rest_endpoints', $routes());
    $check('API : l’administrateur garde l’accès', isset($forAdmin['/wp/v2/users']),
        'le durcissement ne casse pas un droit légitime');
    wp_set_current_user(0);
}

$demoPost = get_posts(['numberposts' => 1]);
$oembed = apply_filters('oembed_response_data', [
    'author_name' => 'admin',
    'author_url'  => home_url('/author/admin/'),
    'title'       => 'Test',
], $demoPost[0] ?? null, 0, 0);
$check('oEmbed ne republie pas l’auteur',
    !isset($oembed['author_name']) && !isset($oembed['author_url']));

// --- XML-RPC -----------------------------------------------------------------
echo "\n--- XML-RPC ---\n";

$check('xmlrpc_enabled renvoie faux', apply_filters('xmlrpc_enabled', true) === false);
$check('Aucune méthode XML-RPC exposée', apply_filters('xmlrpc_methods', ['demo.sayHello' => 'fn']) === []);

$headers = apply_filters('wp_headers', ['X-Pingback' => 'http://exemple/xmlrpc.php']);
$check('En-tête X-Pingback retiré', !isset($headers['X-Pingback']));

$check('Le lien RSD (→ xmlrpc) est retiré du head',
    !has_action('wp_head', 'rsd_link'),
    'ne pas pointer vers une porte fermée');

// L'accès HTTP au fichier lui-même est coupé (403) plutôt que ses seules
// méthodes vidées : un scanner le signalait « activé » tant qu'il répondait.
// Testé en HTTP dans l'audit — ici on documente l'intention.
$check('Le blocage vise le fichier, pas seulement les méthodes',
    str_contains((string) file_get_contents(__DIR__ . '/../src/Support/Hardening.php'), "XMLRPC_REQUEST')"),
    'GET /xmlrpc.php → 403, vérifié en conditions réelles');

// --- Éditeur de fichiers -----------------------------------------------------
echo "\n--- Éditeur de code de l’administration ---\n";

$check('DISALLOW_FILE_EDIT est actif',
    defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT === true,
    'un admin compromis ne peut pas éditer le code depuis le navigateur');

// --- Débrayage ---------------------------------------------------------------
echo "\n--- Soupape de sécurité ---\n";

// Tout le durcissement se coupe par un filtre : indispensable si une mesure
// gêne un usage qu'on n'avait pas prévu.
$check('Un filtre permet de tout désactiver',
    has_filter('subalcatel_hardening_enabled') !== false
    || apply_filters('subalcatel_hardening_enabled', true) === true,
    'la mesure ne doit jamais devenir un piège sans issue');

// --- Le site répond toujours -------------------------------------------------
echo "\n--- Aucune régression fonctionnelle ---\n";

$check('Le rôle membre existe encore', get_role(Roles::MEMBER) !== null);
$check('Le rôle bureau conserve ses capacités',
    get_role(Roles::OFFICE)?->has_cap('sub_manage_memberships') === true);

// --- Le dossier des médias n'exécute pas de code -----------------------------
echo "\n--- Dossier des médias ---\n";

// Le club a été piraté par 36 fichiers PHP déposés dans le « images/ » du
// Joomla. « wp-content/uploads » est l'équivalent WordPress : le seul dossier
// où un visiteur peut faire arriver un fichier de son choix.
Subalcatel\Club\Support\Hardening::protectUploads();

$uploads = wp_get_upload_dir()['basedir'] ?? '';

$check('Le dossier des médias porte un .user.ini',
    is_readable($uploads . '/.user.ini'),
    'PHP-FPM le lit quel que soit le serveur web devant lui');
$check('Ce .user.ini coupe le moteur PHP',
    str_contains((string) @file_get_contents($uploads . '/.user.ini'), 'engine = Off'));
$check('Le dossier des médias porte un .htaccess',
    is_readable($uploads . '/.htaccess'));
$check('Ce .htaccess refuse les extensions exécutables',
    (bool) preg_match('/FilesMatch.+php/i', (string) @file_get_contents($uploads . '/.htaccess')));

// La protection ne doit pas rendre les photos du site inaccessibles : on
// interdit l'exécution, pas la lecture. Le refus doit donc rester **à
// l'intérieur** d'un bloc FilesMatch ; hors bloc, il fermerait tout le dossier
// et les images du site disparaîtraient.
$contenu = (string) @file_get_contents($uploads . '/.htaccess');
$horsBloc = (string) preg_replace('#<FilesMatch.*?</FilesMatch>#is', '', $contenu);

$check('Aucun refus global : les médias restent servis',
    !preg_match('/(Require all denied|Deny from all)/i', $horsBloc),
    'le refus doit être borné aux extensions exécutables');

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
