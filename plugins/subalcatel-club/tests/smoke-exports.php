<?php
/**
 * Test de fumée des exports.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-exports.php
 *
 * Ce qui compte : les droits filtrent, le CSV s'ouvre dans Excel français,
 * aucune formule ne s'exécute, et aucun fichier ne sort.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Exports\CsvWriter;
use Subalcatel\Club\Exports\ExportRegistry;
use Subalcatel\Club\Exports\Members;
use Subalcatel\Club\Exports\XlsxWriter;
use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Membership\ApplicationService;

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-54s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$makeUser = static function (string $role): int {
    $id = wp_insert_user([
        'user_login' => 'demo_' . wp_generate_password(8, false),
        'user_email' => wp_generate_password(8, false) . '@subalcatel.test',
        'user_pass'  => wp_generate_password(),
        'first_name' => 'Camille',
        'last_name'  => 'Riou',
        'role'       => $role,
    ]);

    return is_wp_error($id) ? 0 : $id;
};

// --- Format CSV ------------------------------------------------------------------
echo "\n--- Format CSV ---\n";

$csv = CsvWriter::render(['Nom', 'Ville'], [['Riou', 'Ploumanac’h']]);

$check('Marque d’ordre des octets présente', str_starts_with($csv, "\xEF\xBB\xBF"), 'Excel lira l’UTF-8');
$check('Séparateur point-virgule', str_contains($csv, 'Nom;Ville'));
$check('Accents préservés', str_contains($csv, 'Ploumanac’h'));

// Une cellule commençant par « = » est exécutée par le tableur à l'ouverture.
$injection = CsvWriter::render(['Nom'], [['=1+1'], ['+SUM(A1)'], ['@cmd'], ['-2']]);
$check('Formule neutralisée', str_contains($injection, "'=1+1"), 'préfixée par une apostrophe');
$check('Toutes les amorces couvertes',
    substr_count($injection, "'") >= 4, '= + @ - traités');

// --- Format Excel ------------------------------------------------------------------
echo "\n--- Format Excel ---\n";

if (!class_exists(ZipArchive::class)) {
    $check('Extension zip disponible', false, 'export Excel indisponible sur ce serveur');
} else {
    $path = XlsxWriter::render(['Nom', 'Montant'], [['Riou', 210.5], ['Le Clec’h', 120]], 'Adhérents');

    $check('Fichier produit', is_file($path) && filesize($path) > 0, number_format((float) filesize($path)) . ' octets');

    $zip = new ZipArchive();
    $zip->open($path);

    $check('Structure Office valide',
        $zip->locateName('[Content_Types].xml') !== false
        && $zip->locateName('xl/workbook.xml') !== false
        && $zip->locateName('xl/worksheets/sheet1.xml') !== false,
        $zip->numFiles . ' entrées');

    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $check('Texte échappé', str_contains($sheet, 'Le Clec&#8217;h') || str_contains($sheet, 'Le Clec’h'));
    $check('Nombre écrit comme nombre', str_contains($sheet, '<v>210.5</v>'), 'sommable dans le tableur');

    $zip->close();
    @unlink($path);
}

// --- Droits -------------------------------------------------------------------------
echo "\n--- Filtrage par les droits ---\n";

// Des membres simples auxquels on n'ajoute QUE la capacité testée : le rôle
// « bureau » les reçoit toutes par défaut, ce qui ne dirait rien du filtrage.
$secretary = $makeUser('sub_member');
$treasurer = $makeUser('sub_member');
$plain     = $makeUser('sub_member');

get_userdata($secretary)->add_cap('sub_export_members');
get_userdata($treasurer)->add_cap('sub_export_payments');

$forSecretary = array_map(static fn ($e) => $e->key(), ExportRegistry::availableTo($secretary));
$forTreasurer = array_map(static fn ($e) => $e->key(), ExportRegistry::availableTo($treasurer));
$forPlain     = ExportRegistry::availableTo($plain);

$check('Le secrétariat voit la liste des adhérents', in_array('members', $forSecretary, true));
$check('Le secrétariat ne voit pas les règlements', !in_array('payments', $forSecretary, true),
    implode(', ', $forSecretary));
$check('La trésorerie voit les règlements', in_array('payments', $forTreasurer, true));
$check('La trésorerie ne voit pas l’affiliation FFESSM', !in_array('ffessm', $forTreasurer, true));
$check('Un membre simple ne voit aucun export', $forPlain === [], count($forPlain) . ' export(s)');

// --- Contenu -------------------------------------------------------------------------
echo "\n--- Contenu des exports ---\n";

sub_test_make_compliant($secretary);
update_user_meta($secretary, 'sub_dive_level_id', get_term_by('slug', 'p3', DiveLevels::TAXONOMY)->term_id);
update_user_meta($secretary, 'sub_mobile', '06 11 22 33 44');
update_user_meta($secretary, 'sub_emergency_contact', 'Marie D.');

$members = ExportRegistry::find('members');
$rows    = $members->rows();
$check('Liste des adhérents non vide', $rows !== [], count($rows) . ' ligne(s)');

// Filtrage sur l'adresse, seule valeur unique : plusieurs comptes de
// démonstration portent le même prénom.
$email = get_userdata($secretary)->user_email;
$found = array_values(array_filter($rows, static fn (array $r): bool => $r[2] === $email));
$check('Colonnes cohérentes', $found !== [] && count($found[0]) === count($members->columns()));
$check('Téléphone présent', $found !== [] && $found[0][3] === '06 11 22 33 44', $found[0][3] ?? '');

// La règle absolue : un export est une liste, jamais une archive.
$serialised = wp_json_encode($rows);
$check('Aucun chemin de fichier exporté',
    !str_contains((string) $serialised, '.enc') && !str_contains((string) $serialised, 'uploads'));

$missing = ExportRegistry::find('missing-documents');
$check('Export « documents manquants » disponible', $missing !== null);
$check('Colonnes utiles au rappel', in_array('Situation', $missing->columns(), true));

// --- Détail des adhésions (bureau) --------------------------------------------------
echo "\n--- Détail des adhésions (bureau) ---\n";

// Demandé par le président : une ligne par dossier de la campagne, avec les
// options souscrites, la remise Nokia et le paiement — ce qu'aucun des deux
// exports ci-dessus ne rassemble.
$bureauCampaignId = sub_test_pricing_campaign();
$bureauService    = new ApplicationService();
$office           = $makeUser('sub_office');

$diver = wp_insert_user([
    'user_login' => 'pcabon_test',
    'user_email' => 'pcabon.test@subalcatel.test',
    'user_pass'  => wp_generate_password(),
    'first_name' => 'Patrick',
    'last_name'  => 'Cabon',
    'role'       => 'sub_member',
]);
$diver = is_wp_error($diver) ? 0 : $diver;
update_user_meta($diver, 'sub_licence_number', 'A-22-000123');
update_user_meta($diver, 'sub_asac_card', 'A-22-9999');

$napMember = wp_insert_user([
    'user_login' => 'mrenard_test',
    'user_email' => 'mrenard.test@subalcatel.test',
    'user_pass'  => wp_generate_password(),
    'first_name' => 'Marie',
    'last_name'  => 'Renard',
    'role'       => 'sub_member',
]);
$napMember = is_wp_error($napMember) ? 0 : $napMember;

// Dossier Plongée, Nokia, avec bloc et détendeur — le même scénario que la
// suite de tarification, complété par la moins-value licence :
//   210 (plan) + 29 (assurance) + 16 (carte) + 36 (bloc) + 90 (détendeur)
//     - 49 (moins-value licence) - 108 (remise Nokia) = 224,00 €
$diveApplicationId = $bureauService->submit($diver, $bureauCampaignId, 'plongee', [
    'origine_adhesion'       => 'nokia',
    'jeune'                  => 'non',
    'assurance_individuelle' => 'loisir2',
    'moins_value_licence'    => 'oui',
    'niveau_prepare'         => 'p2',
    'carte_niveau'           => 'oui',
    'pret_bloc'              => 'oui',
    'pret_detendeur'         => 'oui',
    'pret_gilet'             => 'non',
]);
$bureauService->recordPayment($diveApplicationId, 224.00, 'helloasso', '2026-09-22', $office);
$bureauService->validateSecretariat($diveApplicationId, $office);

// Dossier NAP, extérieur, avec piscine — payé mais pas encore validé : teste
// le repli de la date sur la soumission, et l'inclusion d'un dossier « paiement
// confirmé », pas seulement « actif ».
//   120 (plan) + 60 (piscine) = 180,00 €, aucune remise (origine extérieure)
$napApplicationId = $bureauService->submit($napMember, $bureauCampaignId, 'nap', [
    'origine_adhesion'       => 'exterieur',
    'jeune'                  => 'non',
    'assurance_individuelle' => 'aucune',
    'piscine'                => 'oui',
]);
$bureauService->recordPayment($napApplicationId, 180.00, 'cheque', '2026-09-11', $office);

// Un troisième dossier, refusé : ne doit jamais figurer dans l'export.
$refusedMember = $makeUser('sub_member');
$refusedApplicationId = $bureauService->submit($refusedMember, $bureauCampaignId, 'plongee', [
    'origine_adhesion'       => 'exterieur',
    'jeune'                  => 'non',
    'assurance_individuelle' => 'aucune',
    'niveau_prepare'         => 'aucun',
]);
$bureauService->refuse($refusedApplicationId, $office, 'Dossier incomplet');

$bureau = ExportRegistry::find('membership-detail');
$check('Export « détail des adhésions » disponible', $bureau !== null);
$check('19 colonnes, dans l’ordre demandé par le bureau',
    $bureau->columns() === [
        'Licence FFESSM', 'Date d’adhésion', 'Username', 'Prénom', 'Nom', 'N° carte ASAC',
        'Type Adhésion', 'Origine adhésion', 'Moins-value Licence', 'Moins-value Nokia',
        'Assurance', 'Piscine', 'Bloc', 'Stab', 'Détendeur', 'Carte niveau',
        'Supp inscription tardive', 'Type paiement', 'Montant cotisation site',
    ]);

$saisons = \Subalcatel\Club\Exports\MembershipDetailExport::campaigns();
$check('La saison de test figure dans le choix des saisons',
    array_key_exists($bureauCampaignId, $saisons), count($saisons) . ' saison(s)');

$bureauRows = $bureau->rows(['campaign_id' => $bureauCampaignId]);
$check('Seuls les deux dossiers non refusés sortent', count($bureauRows) === 2, count($bureauRows) . ' ligne(s)');

$byUsername = [];
foreach ($bureauRows as $row) {
    $byUsername[$row[2]] = $row;
}

$dive = $byUsername['pcabon_test'] ?? null;
$nap  = $byUsername['mrenard_test'] ?? null;

$check('Le dossier Plongée figure dans l’export', $dive !== null);
$check('Le dossier NAP figure dans l’export', $nap !== null);

$today = Members::frDate(current_time('Y-m-d'));

if ($dive !== null) {
    $check('Licence FFESSM', $dive[0] === 'A-22-000123', $dive[0]);
    $check('Date d’adhésion = date de dépôt du dossier', $dive[1] === $today, $dive[1]);
    $check('N° carte ASAC', $dive[5] === 'A-22-9999', $dive[5]);
    $check('Type Adhésion', $dive[6] === 'Plongée', $dive[6]);
    $check('Origine adhésion', $dive[7] === 'Nokia', $dive[7]);
    $check('Moins-value Licence', abs((float) $dive[8] - (-49.00)) < 0.005, (string) $dive[8]);
    $check('Moins-value Nokia', abs((float) $dive[9] - (-108.00)) < 0.005, (string) $dive[9]);
    $check('Assurance', $dive[10] === 'Loisir 2', $dive[10]);
    $check('Piscine vide en Plongée', $dive[11] === '', "'{$dive[11]}'");
    $check('Bloc', $dive[12] === 'Oui', $dive[12]);
    $check('Stab', $dive[13] === 'Non', $dive[13]);
    $check('Détendeur', $dive[14] === 'Oui', $dive[14]);
    $check('Carte niveau', $dive[15] === 'Oui', $dive[15]);
    $check('Type paiement', $dive[17] === 'HelloAsso', $dive[17]);
    $check('Montant cotisation site', abs((float) $dive[18] - 224.00) < 0.005, (string) $dive[18]);
}

if ($nap !== null) {
    $check('Date d’adhésion = soumission (pas encore validé)', $nap[1] === $today, $nap[1]);
    $check('Type Adhésion NAP', $nap[6] === 'Nage Avec Palmes', $nap[6]);
    $check('Origine adhésion extérieure', $nap[7] === 'Extérieur / Autre', $nap[7]);
    $check('Aucune remise Nokia', (float) $nap[9] === 0.0, (string) $nap[9]);
    $check('Assurance « Aucune »', $nap[10] === 'Aucune', $nap[10]);
    $check('Piscine', $nap[11] === 'Oui', $nap[11]);
    $check('Bloc jamais demandé sur ce dossier', $nap[12] === '', "'{$nap[12]}'");
    $check('Type paiement chèque', $nap[17] === 'Chèque', $nap[17]);
    $check('Montant cotisation site', abs((float) $nap[18] - 180.00) < 0.005, (string) $nap[18]);
}

$check('Le secrétariat voit aussi le détail des adhésions',
    in_array('membership-detail', $forSecretary, true));

sub_test_drop_campaign($bureauCampaignId);
foreach ([$diver, $napMember, $office, $refusedMember] as $bureauUserId) {
    if ($bureauUserId) {
        wp_delete_user($bureauUserId);
    }
}

// --- Journalisation ---------------------------------------------------------------------
echo "\n--- Traçabilité ---\n";

$check('Données personnelles signalées', $members->containsPersonalData(), 'export journalisé');

$export = ExportRegistry::find('inexistant');
$check('Export inconnu introuvable', $export === null);

// --- Nettoyage -----------------------------------------------------------------------------
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ([$secretary, $treasurer, $plain] as $id) {
    sub_test_clean_documents($id);
    wp_delete_user($id);
}

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
