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
// suite de tarification :
//   210 (plan) + 29 (assurance) + 16 (carte) + 36 (bloc) + 90 (détendeur)
//     - 108 (remise Nokia) = 273,00 €
//
// La moins-value licence est postée, et écartée : le tarif Nokia couvre déjà la
// licence. C'est l'exclusion de l'option qui le dit, et elle vaut aussi ici —
// un dossier déposé ne facture pas ce que le formulaire n'a pas proposé.
sub_test_complete_identity($diver);

$diveApplicationId = $bureauService->submit($diver, $bureauCampaignId, 'plongee', [
    'origine_adhesion'       => 'nokia',
    'assurance_individuelle' => 'loisir2',
    'moins_value_licence'    => 'oui',
    'niveau_prepare'         => 'p2',
    'pret_bloc'              => 'oui',
    'pret_detendeur'         => 'oui',
    'pret_gilet'             => 'non',
], 'helloasso');
$bureauService->recordPayment($diveApplicationId, 273.00, 'helloasso', '2026-09-22', $office);
$bureauService->validateSecretariat($diveApplicationId, $office);

// Dossier NAP, extérieur, avec piscine — payé mais pas encore validé : teste
// le repli de la date sur la soumission, et l'inclusion d'un dossier « paiement
// confirmé », pas seulement « actif ».
//   120 (plan) + 60 (piscine) - 49 (licence déjà détenue) = 131,00 €,
//   aucune remise (origine extérieure). C'est ce dossier qui couvre la colonne
//   « moins-value licence » : sur le dossier Nokia, l'option n'est pas proposée.
sub_test_complete_identity($napMember);

$napApplicationId = $bureauService->submit($napMember, $bureauCampaignId, 'nap', [
    'origine_adhesion'       => 'exterieur',
    'assurance_individuelle' => 'aucune',
    'moins_value_licence'    => 'oui',
    'piscine'                => 'oui',
], 'cheque');
$bureauService->recordPayment($napApplicationId, 131.00, 'cheque', '2026-09-11', $office);

// Un troisième dossier, refusé : ne doit jamais figurer dans l'export.
$refusedMember = $makeUser('sub_member');
sub_test_complete_identity($refusedMember);

$refusedApplicationId = $bureauService->submit($refusedMember, $bureauCampaignId, 'plongee', [
    'origine_adhesion'       => 'exterieur',
    'assurance_individuelle' => 'aucune',
    'niveau_prepare'         => 'aucun',
    'pret_bloc'              => 'non',
    'pret_detendeur'         => 'non',
    'pret_gilet'             => 'non',
], 'ce_orange');
$bureauService->refuse($refusedApplicationId, $office, 'Dossier incomplet');

$bureau = ExportRegistry::find('membership-detail');
$check('Export « détail des adhésions » disponible', $bureau !== null);
// Les colonnes de l'ancien site, à l'identique : le bureau y branche des
// tableaux qui désignent leurs colonnes par leur rang. L'ordre EST la donnée —
// d'où la comparaison stricte, et non une simple présence.
$check('46 colonnes, dans l’ordre de l’extrait de l’ancien site',
    $bureau->columns() === [
        'id', 'category', 'plan', 'user_id', 'username', 'first_name', 'last_name',
        'address', 'zip', 'city', 'phone', 'osm_telephone_professionnel',
        'osm_telephone_mobile', 'osm_Date_de_naissance', 'birthcity', 'birthdepartment',
        'birthcountry', 'email', 'osm_Courriel_Secondaire', 'osm_Piscine', 'osm_Jeune',
        'osm_Origine_Adhesion', 'osm_carte_niveau', 'osm_Niveau_prepare', 'osm_Pret_Bloc',
        'osm_Pret_Detendeur', 'osm_Pret_Gilet', 'osm_Assurance_Individuelle',
        'osm_Moins_Value_Licence_FFESSM_Adulte', 'osm_Paiement', 'Paiement_messages_CB',
        'Paiement_messages_Cheque', 'comment', 'created_date', 'payment_date',
        'from_date', 'to_date', 'published', 'amount', 'tax_amount', 'discount_amount',
        'gross_amount', 'payment_method', 'transaction_id', 'membership_id',
        'invoice_number',
    ]);

$check('Chaque ligne a autant de cellules que de colonnes',
    array_reduce(
        $bureau->rows(['campaign_id' => $bureauCampaignId]),
        static fn (bool $ok, array $row): bool => $ok && count($row) === 46,
        true
    ));

$saisons = \Subalcatel\Club\Exports\MembershipDetailExport::campaigns();
$check('La saison de test figure dans le choix des saisons',
    array_key_exists($bureauCampaignId, $saisons), count($saisons) . ' saison(s)');

$bureauRows = $bureau->rows(['campaign_id' => $bureauCampaignId]);
$check('Seuls les deux dossiers non refusés sortent', count($bureauRows) === 2, count($bureauRows) . ' ligne(s)');

$byUsername = [];
foreach ($bureauRows as $row) {
    $byUsername[$row[4]] = $row;
}

$dive = $byUsername['pcabon_test'] ?? null;
$nap  = $byUsername['mrenard_test'] ?? null;

$check('Le dossier Plongée figure dans l’export', $dive !== null);
$check('Le dossier NAP figure dans l’export', $nap !== null);


if ($dive !== null) {
    $check('category constante, comme dans l’ancien fichier',
        $dive[1] === 'Adhésion Sub Alcatel', $dive[1]);
    $check('Type Adhésion', $dive[2] === 'Plongée', $dive[2]);
    $check('Prénom', $dive[5] === 'Patrick', $dive[5]);
    $check('Nom', $dive[6] === 'Cabon', $dive[6]);
    $check('Adresse postale', $dive[7] === '3 rue des Ancres', $dive[7]);
    $check('Code postal', $dive[8] === '22300', $dive[8]);
    $check('Date de naissance au format de l’ancien extrait',
        $dive[13] === '1980-05-14', $dive[13]);
    $check('Ville de naissance', $dive[14] === 'Lannion', $dive[14]);
    $check('Courriel', $dive[17] === 'pcabon.test@subalcatel.test', $dive[17]);
    $check('Piscine vide en Plongée', $dive[19] === '', "'{$dive[19]}'");
    // Le tarif jeune a disparu en septembre 2026 : la colonne reste, vide, pour
    // que les suivantes gardent leur rang.
    $check('osm_Jeune vide, colonne conservée', $dive[20] === '', "'{$dive[20]}'");
    $check('Origine adhésion', $dive[21] === 'Nokia', $dive[21]);
    $check('Carte niveau', $dive[22] === 'Oui', $dive[22]);
    $check('Niveau préparé', $dive[23] === 'P2', $dive[23]);
    $check('Bloc', $dive[24] === 'Oui', $dive[24]);
    $check('Détendeur', $dive[25] === 'Oui', $dive[25]);
    $check('Stab', $dive[26] === 'Non', $dive[26]);
    $check('Assurance', $dive[27] === 'Loisir 2', $dive[27]);
    // Postée par le dossier, écartée par l'exclusion : le tarif Nokia couvre
    // déjà la licence, et la déduire ici rendait 20 € de trop.
    $check('Moins-value Licence écartée pour un Nokia', $dive[28] === '', "'{$dive[28]}'");
    $check('Mode de règlement en clair', $dive[29] === 'HelloAsso', $dive[29]);
    $check('Date de règlement', $dive[34] === '22/09/2026', $dive[34]);
    $check('published à 1, comme l’ancien extrait', (int) $dive[37] === 1, (string) $dive[37]);
    $check('Montant', abs((float) $dive[38] - 273.00) < 0.005, (string) $dive[38]);
    $check('tax_amount à 0.00', $dive[39] === '0.00', (string) $dive[39]);
    // La remise Nokia vaut -108 € dans les lignes figées ; l'ancien fichier
    // l'écrivait sans signe.
    $check('Remise en valeur absolue', abs((float) $dive[40] - 108.00) < 0.005, (string) $dive[40]);
    $check('gross_amount = amount', abs((float) $dive[41] - 273.00) < 0.005, (string) $dive[41]);
    $check('payment_method en code technique', $dive[42] === 'helloasso', $dive[42]);
    $check('invoice_number = référence du dossier',
        str_starts_with((string) $dive[45], 'ADH-'), (string) $dive[45]);
}

if ($nap !== null) {
    $check('Type Adhésion NAP', $nap[2] === 'Nage Avec Palmes', $nap[2]);
    $check('Piscine', $nap[19] === 'Oui', $nap[19]);
    $check('Origine adhésion extérieure', $nap[21] === 'Extérieur / Autre', $nap[21]);
    $check('Bloc jamais demandé sur ce dossier', $nap[24] === '', "'{$nap[24]}'");
    $check('Assurance « Aucune »', $nap[27] === 'Aucune', $nap[27]);
    $check('Moins-value Licence retenue hors Nokia',
        $nap[28] === 'Oui', $nap[28]);
    $check('Type paiement chèque', $nap[29] === 'Chèque', $nap[29]);
    $check('Aucune remise Nokia', abs((float) $nap[40]) < 0.005, (string) $nap[40]);
    $check('Montant', abs((float) $nap[38] - 131.00) < 0.005, (string) $nap[38]);
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
