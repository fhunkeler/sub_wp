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
