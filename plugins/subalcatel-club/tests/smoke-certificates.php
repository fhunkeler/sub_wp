<?php
/**
 * Test de fumée du reçu de cotisation et de l'attestation d'adhésion.
 *
 *   docker exec sub_demo_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-certificates.php
 *
 * Ces deux pièces partent chez un comité d'entreprise ou une mutuelle : elles
 * engagent le club. Ce qui est vérifié, c'est qu'elles ne disent jamais plus
 * que le registre — pas de reçu avant l'encaissement, pas d'attestation avant
 * la validation —, qu'elles ne se délivrent qu'à leur titulaire et au bureau,
 * et que le fichier produit est un PDF que les lecteurs ouvrent.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Identity\OfficePosition;
use Subalcatel\Club\Membership\ApplicationService;
use Subalcatel\Club\Membership\ClubIdentity;
use Subalcatel\Club\Membership\MembershipCertificate;
use Subalcatel\Club\Support\PdfDocument;

global $wpdb;

$campaignId = sub_test_pricing_campaign();
$service    = new ApplicationService();
$failures   = 0;

$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-54s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

// Le texte lisible d'un PDF, lignes recollées : un paragraphe se coupe où la
// largeur l'impose, et une date peut se retrouver à cheval sur deux lignes.
$textOf = static function (string $pdf): string {
    preg_match_all('/\((.*?)(?<!\\\\)\) Tj/s', $pdf, $m);

    return implode(' ', $m[1]);
};

$makeUser = static function (string $role, array $extra = []): int {
    $id = wp_insert_user(array_merge([
        'user_login' => 'demo_' . wp_generate_password(8, false),
        'user_pass'  => wp_generate_password(),
        'role'       => $role,
    ], $extra));

    return is_wp_error($id) ? 0 : (int) $id;
};

$member    = $makeUser('sub_member', ['first_name' => 'Hélène', 'last_name' => 'Le Gall']);
$president = $makeUser('sub_member', ['first_name' => 'Yves', 'last_name' => 'Kerboul']);
$treasurer = $makeUser('sub_office');
$intruder  = $makeUser('sub_member');
sub_test_complete_identity($member);

$identityBefore  = get_option(ClubIdentity::OPTION, null);
$positionsBefore = get_option(OfficePosition::OPTION, null);

ClubIdentity::save([
    'name'        => 'Sub Alcatel',
    'address'     => "Route de Villejust\n91620 Nozay",
    'affiliation' => '06 91 0000',
]);
OfficePosition::set(OfficePosition::PRESIDENT, $president);

$applicationId = $service->submit($member, $campaignId, 'plongee', [
    'origine_adhesion'       => 'exterieur',
    'assurance_individuelle' => 'loisir2',
    'pret_bloc'              => 'non',
    'pret_detendeur'         => 'non',
    'pret_gilet'             => 'non',
], 'cheque');

// --- Rien avant l'encaissement -------------------------------------------------
echo "\n--- Avant le règlement ---\n";

$check('Aucune pièce sur un dossier en attente',
    MembershipCertificate::available($service->find($applicationId)) === []);

wp_set_current_user($member);
$html = do_shortcode('[subalcatel_mon_adhesion]');
$check('« Mon adhésion » annonce le reçu à venir',
    str_contains($html, 'dès que la trésorerie aura enregistré'));
$check('Sans proposer de lien', !str_contains($html, MembershipCertificate::ACTION));

// --- Le reçu suit l'encaissement ----------------------------------------------
echo "\n--- Après le règlement ---\n";

$total = (float) $service->find($applicationId)['total_amount'];
$service->recordPayment($applicationId, $total, 'cheque', '2026-09-20', $treasurer, 'CHQ 1234');
$application = $service->find($applicationId);

$check('Reçu disponible, attestation pas encore',
    MembershipCertificate::available($application) === [MembershipCertificate::RECEIPT]);

$html = do_shortcode('[subalcatel_mon_adhesion]');
$check('Le lien du reçu apparaît dans « Mon adhésion »',
    str_contains($html, 'Reçu de cotisation (PDF)'));
$check('L’attestation est annoncée pour plus tard',
    str_contains($html, 'une fois votre dossier validé'));

$pdf = MembershipCertificate::render($application, MembershipCertificate::RECEIPT);

$check('Le fichier est un PDF', str_starts_with($pdf, '%PDF-1.4'));
$check('Il se termine proprement', str_ends_with(rtrim($pdf), '%%EOF'));

// La table des références croisées donne la position de chaque objet ; une
// position fausse d'un octet suffit à ce qu'un lecteur strict refuse le
// fichier, ou le « répare » en silence.
preg_match('/startxref\n(\d+)/', $pdf, $m);
$xrefAt = (int) ($m[1] ?? 0);
$check('« startxref » pointe sur la table', substr($pdf, $xrefAt, 4) === 'xref');

preg_match_all('/^(\d{10}) 00000 n $/m', $pdf, $offsets);
$wellPlaced = true;
foreach ($offsets[1] as $index => $offset) {
    $wellPlaced = $wellPlaced && str_starts_with(substr($pdf, (int) $offset), ($index + 1) . ' 0 obj');
}
$check('Chaque objet est à la position annoncée', $wellPlaced && count($offsets[1]) === 7);

$check('Le payeur est nommé, accents compris',
    str_contains($pdf, PdfDocument::encode(str_replace(' ', "\u{00A0}", 'Hélène LE GALL'))));
$check('Son adresse figure', str_contains($pdf, PdfDocument::encode('22300 Lannion')));
$check('L’émetteur et son affiliation aussi',
    str_contains($pdf, PdfDocument::encode('91620 Nozay'))
    && str_contains($pdf, PdfDocument::encode('06 91 0000')));

$amount = number_format($total, 2, ',', "\u{00A0}") . "\u{00A0}€";
$check('Le montant reçu est écrit', str_contains($pdf, PdfDocument::encode($amount)), $amount);
$check('Le règlement est daté', str_contains($pdf, PdfDocument::encode(str_replace(' ', "\u{00A0}", '20 septembre 2026'))));
$check('Le reçu dit qu’il n’est pas fiscal', str_contains($pdf, 'pas un re'));

// --- L'attestation suit l'activation ------------------------------------------
echo "\n--- Après la validation ---\n";

$service->validateSecretariat($applicationId, $treasurer);
$application = $service->find($applicationId);

$check('Les deux pièces sont disponibles',
    MembershipCertificate::available($application)
        === [MembershipCertificate::RECEIPT, MembershipCertificate::ATTESTATION]);

$pdf = MembershipCertificate::render($application, MembershipCertificate::ATTESTATION);
$check('L’attestation est signée par le président', str_contains($pdf, PdfDocument::encode(str_replace(' ', "\u{00A0}", 'Yves KERBOUL'))));
$check('Elle donne la date de naissance', str_contains($pdf, PdfDocument::encode(str_replace(' ', "\u{00A0}", '14 mai 1980'))));
$check('Et la période de validité', str_contains($textOf($pdf), PdfDocument::encode(
    str_replace(' ', "\u{00A0}", (string) wp_date('j F Y', strtotime((string) $application['valid_until'])))
)));

// Fonction vacante : la pièce reste délivrée, au nom du bureau.
OfficePosition::set(OfficePosition::PRESIDENT, 0);
$pdf = MembershipCertificate::render($application, MembershipCertificate::ATTESTATION);
$check('Sans président désigné, le bureau atteste', str_contains($pdf, 'Le bureau du club'));

// --- Qui peut la télécharger --------------------------------------------------
echo "\n--- Accès ---\n";

$check('Le titulaire', MembershipCertificate::mayDownload($application, $member));
$check('Le bureau', MembershipCertificate::mayDownload($application, $treasurer));
$check('Pas un autre membre', !MembershipCertificate::mayDownload($application, $intruder));
$check('Pas un visiteur', !MembershipCertificate::mayDownload($application, 0));

$check('Le nom de fichier porte la référence',
    MembershipCertificate::filename($application, MembershipCertificate::RECEIPT)
        === 'recu-cotisation-' . sanitize_file_name((string) $application['reference']) . '.pdf');

// --- Un dossier annulé ne produit rien ----------------------------------------
$wpdb->update("{$wpdb->prefix}sub_applications", ['status' => ApplicationService::STATUS_CANCELLED], ['id' => $applicationId]);
$check('Dossier annulé : aucune pièce',
    MembershipCertificate::available($service->find($applicationId)) === []);

// --- Le moteur PDF lui-même ---------------------------------------------------
echo "\n--- Mise en page ---\n";

$doc = new PdfDocument();
$check('« € » a la chasse d’un chiffre', abs($doc->width('€', 10) - $doc->width('0', 10)) < 0.01);
$check('Une lettre accentuée, celle de sa base', abs($doc->width('é', 10) - $doc->width('e', 10)) < 0.01);
$check('Les parenthèses sont échappées', (static function (): bool {
    $d = new PdfDocument();
    $d->text(0, 0, 'a (b) c\\');

    return str_contains($d->output(), '(a \\(b\\) c\\\\)');
})());

// --- Nettoyage ----------------------------------------------------------------
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ([$member, $president, $treasurer, $intruder] as $id) {
    wp_delete_user($id);
}

$identityBefore === null ? delete_option(ClubIdentity::OPTION) : update_option(ClubIdentity::OPTION, $identityBefore);
$positionsBefore === null ? delete_option(OfficePosition::OPTION) : update_option(OfficePosition::OPTION, $positionsBefore);

sub_test_drop_campaign($campaignId);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
