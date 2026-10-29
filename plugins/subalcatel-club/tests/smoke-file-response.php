<?php
/**
 * Test de fumée — un document privé s'ouvre dans le navigateur, sans danger.
 *
 *   docker exec sub_demo_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-file-response.php
 *
 * Le bureau consulte un certificat médical dans un onglet au lieu de le
 * télécharger. Ce qui doit tenir :
 *
 *   - un PDF ou une image part en `inline`, tout autre type en `attachment` ;
 *   - le type servi vient de l'extension, jamais de ce que l'adhérent a
 *     déclaré au dépôt (un « PDF » annoncé en text/html reste un PDF) ;
 *   - nosniff, une CSP sans script, et rien en cache ;
 *   - un nom de fichier ne peut pas injecter d'en-tête ;
 *   - les liens s'ouvrent dans un nouvel onglet, côté membre et côté bureau.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Documents\DocumentService;
use Subalcatel\Club\Documents\DocumentStorage;
use Subalcatel\Club\Documents\DocumentTypes;
use Subalcatel\Club\Frontend\DocumentsForm;
use Subalcatel\Club\Support\FileResponse;

global $wpdb;

$failures = 0;

$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-56s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

// --- Les en-têtes, type par type ---------------------------------------------------------
$pdf = FileResponse::headers('certificat.pdf', 'application/pdf', 1234);

$check('PDF : affiché dans le navigateur', str_starts_with($pdf['Content-Disposition'], 'inline;'), $pdf['Content-Disposition']);
$check('PDF : Content-Type application/pdf', $pdf['Content-Type'] === 'application/pdf');
$check('PDF : Content-Length', $pdf['Content-Length'] === '1234');
$check('PDF : nosniff', $pdf['X-Content-Type-Options'] === 'nosniff');
$check('PDF : CSP sans script', str_contains($pdf['Content-Security-Policy'], "default-src 'none'")
    && !str_contains($pdf['Content-Security-Policy'], 'script-src'), $pdf['Content-Security-Policy']);
$check('PDF : pas de cache', str_contains($pdf['Cache-Control'], 'private')
    && str_contains($pdf['Cache-Control'], 'no-store'), $pdf['Cache-Control']);

$jpg = FileResponse::headers('Scan.JPG', 'image/jpeg', 10);
$check('Image : affichée dans le navigateur', str_starts_with($jpg['Content-Disposition'], 'inline;'));
$check('Image : Content-Type image/jpeg', $jpg['Content-Type'] === 'image/jpeg');
$check('Image : CSP en bac à sable', str_contains($jpg['Content-Security-Policy'], 'sandbox')
    && str_contains($jpg['Content-Security-Policy'], "default-src 'none'"), $jpg['Content-Security-Policy']);
$check('Image : nosniff', $jpg['X-Content-Type-Options'] === 'nosniff');

$png = FileResponse::headers('carte.png', '', 10);
$check('PNG : affiché, type déduit sans déclaration', $png['Content-Type'] === 'image/png'
    && str_starts_with($png['Content-Disposition'], 'inline;'));

// --- Ce qui ne s'affiche pas -------------------------------------------------------------
$docx = FileResponse::headers(
    'compte-rendu.docx',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    10
);
$check('Word : téléchargé', str_starts_with($docx['Content-Disposition'], 'attachment;'));
$check('Word : type d’origine conservé', str_contains($docx['Content-Type'], 'wordprocessingml'));
$check('Word : CSP en bac à sable quand même', str_contains($docx['Content-Security-Policy'], 'sandbox'));

$html = FileResponse::headers('piege.html', 'text/html', 10);
$check('HTML : téléchargé', str_starts_with($html['Content-Disposition'], 'attachment;'));
$check('HTML : jamais servi en text/html', $html['Content-Type'] === 'application/octet-stream', $html['Content-Type']);

$svg = FileResponse::headers('dessin.svg', 'image/svg+xml', 10);
$check('SVG : téléchargé, en octet-stream', str_starts_with($svg['Content-Disposition'], 'attachment;')
    && $svg['Content-Type'] === 'application/octet-stream', $svg['Content-Type']);

$xhtml = FileResponse::headers('page.xhtml', 'application/xhtml+xml', 10);
$check('XHTML : jamais servi tel quel', $xhtml['Content-Type'] === 'application/octet-stream', $xhtml['Content-Type']);

// --- Le type déclaré au dépôt ne compte pas pour un PDF ------------------------------------
$liar = FileResponse::headers('certificat.pdf', 'text/html', 10);
$check('« PDF » déclaré text/html : servi en PDF', $liar['Content-Type'] === 'application/pdf', $liar['Content-Type']);

// --- Nom de fichier ----------------------------------------------------------------------
$evil = FileResponse::headers("cert\"\r\nSet-Cookie: x=1.pdf", 'application/pdf', 10);
$check('Nom : ni guillemet ni retour à la ligne', !preg_match('/[\r\n]/', $evil['Content-Disposition'])
    && substr_count($evil['Content-Disposition'], '"') === 2, $evil['Content-Disposition']);

$accent = FileResponse::headers('Certificat médical.pdf', 'application/pdf', 10);
$check('Nom : accents conservés en filename*', str_contains($accent['Content-Disposition'], "filename*=UTF-8''Certificat%20m%C3%A9dical.pdf"), $accent['Content-Disposition']);

// --- Bout en bout : un dépôt d'adhérent, relu par le bureau ------------------------------
DocumentTypes::seed();

$member = wp_insert_user([
    'user_login' => 'demo_' . wp_generate_password(8, false),
    'user_pass'  => wp_generate_password(),
    'role'       => 'sub_member',
]);
$office = wp_insert_user([
    'user_login' => 'demo_' . wp_generate_password(8, false),
    'user_pass'  => wp_generate_password(),
    'role'       => 'sub_office',
]);

$type = null;
foreach (DocumentTypes::all() as $candidate) {
    if ((int) $candidate['encrypted'] === 1) {
        $type = $candidate;
        break;
    }
}
$type ??= DocumentTypes::all()[0];

// Le navigateur de l'adhérent annonce du HTML : le dépôt passe (le contenu est
// un vrai PDF), mais la réponse ne doit pas en tenir compte.
$upload         = sub_test_upload('certificat.pdf');
$upload['type'] = 'text/html';

$service    = new DocumentService();
$documentId = $service->upload($member, (string) $type['slug'], $upload, current_time('Y-m-d'), $member);
$file       = $service->download($documentId, $office);
$served     = FileResponse::headers(sanitize_file_name($file['name']), $file['mime'], strlen($file['body']));

$check('Dépôt réel : le bureau peut le relire', $file['body'] === sub_test_pdf());
$check('Dépôt réel : servi en PDF malgré la déclaration', $served['Content-Type'] === 'application/pdf', 'déclaré ' . $file['mime']);
$check('Dépôt réel : inline', str_starts_with($served['Content-Disposition'], 'inline;'));

$outsider = wp_insert_user([
    'user_login' => 'demo_' . wp_generate_password(8, false),
    'user_pass'  => wp_generate_password(),
    'role'       => 'sub_member',
]);

$denied = false;
try {
    $service->download($documentId, $outsider);
} catch (RuntimeException $e) {
    $denied = true;
}
$check('Contrôle d’accès inchangé : un autre membre refusé', $denied);

// --- Les liens : nouvel onglet -----------------------------------------------------------
wp_set_current_user($member);
$page = DocumentsForm::render();
$check('Espace membre : lien en nouvel onglet', (bool) preg_match(
    '#<a href="[^"]*sub_document_download[^"]*"\s+target="_blank" rel="noopener">#',
    $page
));

$sources = [
    'Admin/DocumentsScreen.php'     => 'DocumentsForm::downloadUrl',
    'Admin/ClubDocumentsScreen.php' => 'DocumentDelivery::url',
    'Admin/EventsScreen.php'        => 'DocumentDelivery::url',
    'Frontend/ClubDocumentsList.php' => 'DocumentDelivery::url',
    'Frontend/EventDetails.php'     => 'DocumentDelivery::url',
];
foreach ($sources as $path => $call) {
    $source = (string) file_get_contents(dirname(__DIR__) . '/src/' . $path);
    $check(
        "Lien en nouvel onglet : {$path}",
        (bool) preg_match('#' . preg_quote($call, '#') . '\(.*?\)\); \?>"\s+target="_blank" rel="noopener">#', $source)
    );
}

// --- Les deux points de diffusion passent par FileResponse --------------------------------
foreach (['Frontend/DocumentsForm.php', 'Content/DocumentDelivery.php'] as $path) {
    $source = (string) file_get_contents(dirname(__DIR__) . '/src/' . $path);
    $check(
        "Diffusion par FileResponse : {$path}",
        str_contains($source, 'FileResponse::send(') && !str_contains($source, 'Content-Disposition: attachment')
    );
}

// --- Nettoyage ---------------------------------------------------------------------------
wp_set_current_user(0);

foreach ($wpdb->get_col($wpdb->prepare(
    "SELECT file_path FROM {$wpdb->prefix}sub_member_documents WHERE user_id = %d AND file_path <> ''",
    $member
)) as $path) {
    DocumentStorage::delete($path);
}
$wpdb->delete("{$wpdb->prefix}sub_member_documents", ['user_id' => $member]);
$wpdb->delete("{$wpdb->prefix}sub_document_access_log", ['document_id' => $documentId]);

require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ([$member, $office, $outsider] as $id) {
    wp_delete_user($id);
}

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
