<?php
/**
 * Test de fumée — sécurité des dépôts de fichiers.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-upload-security.php
 *
 * Le hack qui a coûté le site Joomla est passé par un fichier déposé. Ce test
 * rejoue les techniques connues et vérifie qu'aucune ne franchit le portail :
 * ni exécutable pur, ni exécutable déguisé, ni **polyglotte** — un vrai PDF qui
 * cache du PHP après son en-tête.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Content\DocumentLibrary;
use Subalcatel\Club\Documents\DocumentService;
use Subalcatel\Club\Documents\Storage\LocalAdapter;
use Subalcatel\Club\Documents\UploadGuard;

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-52s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$file = static function (string $name, string $bytes): array {
    $tmp = wp_tempnam($name);
    file_put_contents($tmp, $bytes);

    return ['tmp_name' => $tmp, 'name' => $name, 'type' => 'application/pdf', 'size' => strlen($bytes), 'error' => 0];
};

$rejected = static function (callable $upload): bool {
    try {
        $upload();

        return false;
    } catch (\Throwable) {
        return true;
    }
};

// --- Le garde, isolé ---------------------------------------------------------
echo "\n--- UploadGuard : signatures de code ---\n";

$vecteurs = [
    'PHP long'            => "%PDF-1.4\n<?php system(\$_GET['c']); ?>",
    'PHP court <?='       => "%PDF-1.4\n<?= `id` ?>",
    'PHP avec octet nul'  => "%PDF-1.4\n<\0?php echo 1;",
    'balise ASP <%'       => "GIF89a<% eval(x) %>",
    'shebang shell'       => "#!/bin/sh\nrm -rf /",
    'script language=php' => "<script language=\"php\">system('id');</script>",
];

foreach ($vecteurs as $label => $bytes) {
    $tmp = wp_tempnam('v');
    file_put_contents($tmp, $bytes);

    $blocked = false;
    try {
        UploadGuard::rejectExecutable($tmp);
    } catch (\RuntimeException) {
        $blocked = true;
    }
    @unlink($tmp);

    $check(sprintf('Rejeté : %s', $label), $blocked);
}

$legit = wp_tempnam('ok');
file_put_contents($legit, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
$passes = true;
try {
    UploadGuard::rejectExecutable($legit);
} catch (\RuntimeException) {
    $passes = false;
}
@unlink($legit);
$check('Un vrai PDF passe', $passes, 'le garde ne doit pas être un mur');

// --- Le cas qui manquait : un document réaliste ------------------------------
//
// Les PDF ci-dessus font soixante-dix octets. Un vrai certificat est un scan ou
// une photo — un mégaoctet de données compressées, à forte entropie. C'est là
// qu'une signature de deux octets comme `<%` apparaît par hasard : seize fois
// par mégaoctet en moyenne. Le garde a refusé des certificats parfaitement sains
// pendant que ce test restait au vert, faute d'un fichier de taille crédible.
mt_srand(2026);
$blocs = [];

for ($i = 0; $i < 1024; $i++) {
    $mots = [];

    for ($j = 0; $j < 512; $j++) {
        $mots[] = mt_rand(0, 0xFFFF);
    }

    $blocs[] = pack('v*', ...$mots);
}

$bruit = implode('', $blocs);
$scan  = "%PDF-1.4\n" . $bruit . "\n%%EOF";

$check('Le bruit du test contient bien « <% »', str_contains($scan, '<%'),
    'sans quoi ce test ne prouverait rien');

$sain   = wp_tempnam('scan.pdf');
file_put_contents($sain, $scan);
$accepte = true;
try {
    UploadGuard::rejectExecutable($sain);
} catch (\RuntimeException) {
    $accepte = false;
}
@unlink($sain);
$check('Un scan d’un mégaoctet est accepté', $accepte,
    'la signature s’y trouve par hasard, pas par malveillance');

// Le même bruit, mais avec une vraie charge enfouie dedans : elle doit sortir.
$piege = wp_tempnam('piege.pdf');
file_put_contents(
    $piege,
    "%PDF-1.4\n" . substr($bruit, 0, 4096) . "<?php system(\$_GET['c']); ?>" . substr($bruit, 4096)
);
$attrape = false;
try {
    UploadGuard::rejectExecutable($piege);
} catch (\RuntimeException) {
    $attrape = true;
}
@unlink($piege);
$check('Une charge PHP enfouie dans ce bruit est rattrapée', $attrape,
    'le bruit ne doit pas servir de camouflage');

// --- Documents du club (non chiffrés, le cas le plus exposé) ------------------
echo "\n--- Dépôt d’un document du club ---\n";

$library = new DocumentLibrary();
$docId   = wp_insert_post([
    'post_type'   => 'sub_club_doc',
    'post_title'  => 'Cible pentest',
    'post_status' => 'publish',
]);

$check('Polyglotte PDF+PHP refusé',
    $rejected(static fn () => $library->attach($docId, $file('rapport.pdf', "%PDF-1.4\n<?php system(\$_GET['c']); ?>\n%%EOF"), 1)),
    'un PDF valide contenant du PHP');

$check('Double extension .pdf.php refusée',
    $rejected(static fn () => $library->attach($docId, $file('facture.pdf.php', '<?php echo 1;'), 1)));

$check('Un vrai PDF est accepté',
    !$rejected(static fn () => $library->attach($docId, $file('statuts.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF"), 1)));

$storedKey = (string) get_post_meta($docId, \Subalcatel\Club\Content\ClubDocuments::META_KEY, true);
$check('Le fichier accepté ne contient pas de PHP', $storedKey !== ''
    && !str_contains(\Subalcatel\Club\Documents\DocumentStorage::read($storedKey, false), '<?php'));

// --- Certificat médical ------------------------------------------------------
echo "\n--- Dépôt d’un certificat médical ---\n";

$service = new DocumentService();
$member  = wp_insert_user([
    'user_login' => 'demo_' . wp_generate_password(8, false),
    'user_email' => wp_generate_password(8, false) . '@subalcatel.test',
    'user_pass'  => wp_generate_password(),
    'role'       => 'sub_member',
]);

$check('Polyglotte refusé comme certificat',
    $rejected(static fn () => $service->upload($member, 'certificat-medical', $file('cert.pdf', "%PDF-1.4\n<?php echo 1; ?>"), '2026-01-01', $member)));

$check('Un certificat PDF légitime est accepté',
    !$rejected(static fn () => $service->upload($member, 'certificat-medical', sub_test_upload('cert.pdf'), '2026-01-01', $member)));

// --- Barrière d’exécution du dossier de stockage -----------------------------
echo "\n--- Protection du dossier de stockage ---\n";

$dir = (new LocalAdapter())->describe();
// describe() renvoie un texte ; on récupère le chemin réel par réflexion douce.
$base = wp_upload_dir()['basedir'] . '/subalcatel-private';

$check('Le .htaccess coupe le moteur PHP',
    is_file($base . '/.htaccess') && str_contains((string) file_get_contents($base . '/.htaccess'), 'engine off'));
$check('Un .user.ini désactive PHP quel que soit le serveur',
    is_file($base . '/.user.ini') && str_contains((string) file_get_contents($base . '/.user.ini'), 'engine = Off'),
    'survit à un passage d’Apache à nginx');
$check('Le dossier est hors de la racine servie… ou verrouillé',
    is_file($base . '/.htaccess') && is_file($base . '/index.php'));

// --- Scan des documents déjà stockés (angle mort de la migration) ------------
echo "\n--- Contrôle d’intégrité du stock existant ---\n";

// Un vrai document ne doit pas être signalé.
$cleanId = wp_insert_post([
    'post_type'   => 'sub_club_doc',
    'post_title'  => 'Document sain',
    'post_status' => 'publish',
]);
$library->attach($cleanId, $file('propre.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF"), 1);

$suspectsAvant = \Subalcatel\Club\Documents\UploadGuard::scanStored();
$check('Un document sain n’est pas signalé',
    !in_array($cleanId, array_column($suspectsAvant, 'id'), true),
    count($suspectsAvant) . ' suspect(s) au total');

// On injecte un polyglotte EN CONTOURNANT l'interface — comme le ferait un
// import de migration, qui écrit directement sans passer par le garde.
$malId = wp_insert_post([
    'post_type'   => 'sub_club_doc',
    'post_title'  => 'Importé du Joomla compromis',
    'post_status' => 'publish',
]);
$rawKey = \Subalcatel\Club\Documents\DocumentStorage::store(
    $file('rapport.pdf', "%PDF-1.4\n<?php system(\$_GET['c']); ?>"),
    false,
    'club'
);
update_post_meta($malId, \Subalcatel\Club\Content\ClubDocuments::META_KEY, $rawKey);

$suspects = \Subalcatel\Club\Documents\UploadGuard::scanStored();
$check('Le scan rattrape un polyglotte importé hors interface',
    in_array($rawKey, array_column($suspects, 'key'), true),
    'c’est l’angle mort d’une migration en masse');
$check('Le scan ne supprime rien lui-même',
    get_post($malId) !== null && \Subalcatel\Club\Documents\DocumentStorage::exists($rawKey),
    'le tri des faux positifs reste humain');

// Nettoyage de ces deux documents de test
\Subalcatel\Club\Documents\DocumentStorage::delete($rawKey);
wp_delete_post($malId, true);
$library->purgeFiles($cleanId);
wp_delete_post($cleanId, true);

// --- Nettoyage ---------------------------------------------------------------
require_once ABSPATH . 'wp-admin/includes/user.php';

$library->purgeFiles($docId);
wp_delete_post($docId, true);
sub_test_clean_documents($member);
wp_delete_user($member);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
