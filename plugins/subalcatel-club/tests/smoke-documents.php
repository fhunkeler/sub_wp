<?php
/**
 * Test de fumée du module Documents.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-documents.php
 *
 * Priorité aux points où une erreur coûte cher : les fichiers exécutables, le
 * chiffrement, les droits d'accès et la purge.
 */

use Subalcatel\Club\Documents\DocumentService;
use Subalcatel\Club\Documents\DocumentStorage;
use Subalcatel\Club\Documents\DocumentTypes;
use Subalcatel\Club\Policy\EligibilityPolicy;

DocumentTypes::seed();

$service  = new DocumentService();
$policy   = new EligibilityPolicy();
$failures = 0;

$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-52s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$makeUser = static function (string $role, ?string $birthDate = null): int {
    $id = wp_insert_user([
        'user_login' => 'demo_' . wp_generate_password(8, false),
        'user_pass'  => wp_generate_password(),
        'role'       => $role,
    ]);

    if ($birthDate !== null) {
        update_user_meta($id, 'sub_birth_date', $birthDate);
    }

    return $id;
};

/** Fabrique un fichier temporaire et le présente comme un téléversement. */
$fakeUpload = static function (string $name, string $contents): array {
    $tmp = wp_tempnam($name);
    file_put_contents($tmp, $contents);

    return [
        'tmp_name' => $tmp,
        'name'     => $name,
        'type'     => 'application/pdf',
        'size'     => strlen($contents),
        'error'    => UPLOAD_ERR_OK,
    ];
};

$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";

$member  = $makeUser('sub_member');
$office  = $makeUser('sub_office');
$outsider = $makeUser('sub_member');
$minor   = $makeUser('sub_member', gmdate('Y-m-d', strtotime('-15 years')));

get_userdata($office)->add_cap('sub_validate_member_document');
get_userdata($office)->add_cap('sub_view_medical_certificate');

// --- Contrôles de fichier ----------------------------------------------------
echo "\n--- Ce qui ne doit jamais entrer ---\n";

foreach ([
    ['shell.php', '<?php system($_GET["c"]); ?>', 'Un .php est refusé'],
    ['photo.svg', '<svg onload="alert(1)"></svg>', 'Un .svg est refusé'],
] as [$name, $body, $label]) {
    try {
        $service->upload($member, DocumentTypes::MEDICAL, $fakeUpload($name, $body), null, $member);
        $check($label, false);
    } catch (RuntimeException $e) {
        $check($label, true, $e->getMessage());
    }
}

// Un script déguisé en PDF : l'extension ment, le contenu doit trahir.
try {
    $service->upload(
        $member,
        DocumentTypes::MEDICAL,
        $fakeUpload('certificat.pdf', '<?php system($_GET["c"]); ?>'),
        null,
        $member
    );
    $check('Un script déguisé en .pdf est refusé', false);
} catch (RuntimeException $e) {
    $check('Un script déguisé en .pdf est refusé', true, $e->getMessage());
}

// --- Dépôt et chiffrement -----------------------------------------------------
echo "\n--- Dépôt du certificat médical ---\n";

$docId = $service->upload(
    $member,
    DocumentTypes::MEDICAL,
    $fakeUpload('certificat.pdf', $pdf),
    gmdate('Y-m-d'),
    $member
);
$document = $service->find($docId);

$check('Document créé', $docId > 0);
$check('En attente de validation', $document['status'] === DocumentService::STATUS_PENDING);
$check('Marqué comme chiffré', (int) $document['is_encrypted'] === 1);
$check('Validité calculée à 12 mois', $document['valid_until'] === gmdate('Y-m-d', strtotime('+12 months')));
$check('Purge planifiée 30 jours après', $document['purge_on'] === gmdate('Y-m-d', strtotime('+12 months +30 days')));

// Le contenu tel qu'il repose sur le support, sans passer par le déchiffrement.
$raw = DocumentStorage::adapter()->get((string) $document['file_path']);
$check('Le fichier stocké est illisible en clair', !str_contains($raw, '%PDF'));
$check('Nom de fichier non devinable', !str_contains((string) $document['file_path'], 'certificat'));

// --- Droits d'accès -------------------------------------------------------------
echo "\n--- Qui peut ouvrir le document ---\n";

$own = $service->download($docId, $member);
$check('Le membre lit son propre document', str_contains($own['body'], '%PDF'), 'déchiffré');

$byOffice = $service->download($docId, $office);
$check('Le bureau habilité y accède', str_contains($byOffice['body'], '%PDF'));

try {
    $service->download($docId, $outsider);
    $check('Un autre membre est refusé', false);
} catch (RuntimeException $e) {
    $check('Un autre membre est refusé', true, $e->getMessage());
}

global $wpdb;
$accessLog = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sub_document_access_log WHERE document_id = %d",
    $docId
));
$check('Consultations journalisées', $accessLog === 2, "{$accessLog} accès tracés");

// --- Éligibilité -----------------------------------------------------------------
echo "\n--- Effet sur l’éligibilité ---\n";

update_user_meta($member, 'sub_membership_valid_until', '2027-12-31');

$d = $policy->hasValidDocuments($member);
$check('Certificat déposé → plus de blocage', $d->allowed, $d->reason);

// --- Validation par le bureau -------------------------------------------------------
echo "\n--- Validation ---\n";

try {
    $service->review($docId, true, $outsider);
    $check('Un membre ne valide pas un document', false);
} catch (RuntimeException $e) {
    $check('Un membre ne valide pas un document', true, $e->getMessage());
}

$service->review($docId, true, $office);
$check('Le bureau valide', $service->find($docId)['status'] === DocumentService::STATUS_VALID);

// --- Mineurs -------------------------------------------------------------------------
echo "\n--- Adhérents mineurs ---\n";

$check('Mineur détecté', DocumentTypes::isMinor($minor));
$check('Majeur non concerné', !DocumentTypes::isMinor($member));

// Le seul type installé est le certificat médical : le bureau crée les autres.
$consentId = DocumentTypes::create([
    'label'         => 'Autorisation parentale',
    'is_required'   => 1,
    'required_when' => DocumentTypes::REQUIRED_MINOR,
    'blocks_dives'  => 1,
    'has_validity'  => 0,
]);
$check('Type créé depuis zéro', $consentId > 0, 'autorisation parentale');

$consent = DocumentTypes::find('autorisation-parentale');
$check('Autorisation exigée du mineur', DocumentTypes::isRequiredFor($consent, $minor));
$check('Autorisation non exigée du majeur', !DocumentTypes::isRequiredFor($consent, $member));

$status = $service->statusFor($minor);
$check('Le mineur doit fournir l’autorisation', in_array('Autorisation parentale', $status['missing'], true));

$check('Type inutilisé supprimable', DocumentTypes::remove($consentId) === '');
$check('Type bien retiré', DocumentTypes::find('autorisation-parentale') === null);

// --- Stockage configurable ---------------------------------------------------------------
echo "\n--- Stockage ---\n";

$test = DocumentStorage::test();
$check('Le stockage courant répond', $test['ok'], $test['message']);
$check('Trois emplacements proposés', count(DocumentStorage::drivers()) === 3,
    implode(', ', array_keys(DocumentStorage::drivers())));

$bad = DocumentStorage::test(['driver' => 's3', 'endpoint' => '', 'bucket' => '']);
$check('Configuration S3 incomplète détectée', !$bad['ok'], $bad['message']);

// --- Expiration et purge ---------------------------------------------------------------
echo "\n--- Expiration et purge ---\n";

$wpdb->update("{$wpdb->prefix}sub_member_documents", [
    'valid_until' => gmdate('Y-m-d', strtotime('-40 days')),
    'purge_on'    => gmdate('Y-m-d', strtotime('-10 days')),
], ['id' => $docId]);

$d = $policy->hasValidDocuments($member);
$check('Certificat expiré → refus daté', !$d->allowed, $d->reason);

$purged = $service->purgeDue();
$after  = $service->find($docId);

$check('Fichier purgé', $purged >= 1, "{$purged} fichier(s)");
$check('Statut « purgé »', $after['status'] === DocumentService::STATUS_PURGED);
$check('Chemin vidé', $after['file_path'] === '');
$check('Trace de vérification conservée', !empty($after['verified_at']), 'vérifié le ' . $after['verified_at']);

// --- Nettoyage ---------------------------------------------------------------------------
foreach ($wpdb->get_col($wpdb->prepare(
    "SELECT file_path FROM {$wpdb->prefix}sub_member_documents WHERE user_id IN (%d,%d,%d,%d) AND file_path <> ''",
    $member, $office, $outsider, $minor
)) as $path) {
    DocumentStorage::delete($path);
}

$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->prefix}sub_member_documents WHERE user_id IN (%d,%d,%d,%d)",
    $member, $office, $outsider, $minor
));

require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ([$member, $office, $outsider, $minor] as $id) {
    wp_delete_user($id);
}

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
