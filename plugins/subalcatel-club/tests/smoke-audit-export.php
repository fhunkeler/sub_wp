<?php
/**
 * Test de fumée — export du journal d'audit.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-audit-export.php
 *
 * L'export doit reprendre le filtre de l'écran, y compris la période comptée
 * en jours de Paris, sortir dans l'ordre chronologique, isoler l'identifiant
 * tenté pour l'analyse des connexions, et rester réservé au bureau.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Exports\AuditLogExport;
use Subalcatel\Club\Exports\CsvWriter;
use Subalcatel\Club\Exports\ExportRegistry;
use Subalcatel\Club\Support\Audit;

global $wpdb;
$table = $wpdb->prefix . 'sub_audit_log';

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-58s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

// Catégorie unique : isole nos lignes des vraies, et se nettoie sans risque.
$etype = 'smoketest_' . substr((string) uniqid(), -6);

$restoreTz = get_option('timezone_string');
update_option('timezone_string', 'Europe/Paris');

$_SERVER['REMOTE_ADDR'] = '203.0.113.88';

// Trois entrées datées à la main (UTC, comme la table).
$at = static function (string $utc) use ($wpdb, $table): void {
    $wpdb->query($wpdb->prepare("UPDATE {$table} SET created_at = %s WHERE id = %d", $utc, $wpdb->insert_id));
};

Audit::log('auth.login_failed', $etype, null, ['identifiant' => '=HYPERLINK("x")', 'agent' => 'Firefox'], 0);
$at('2026-10-28 12:00:00'); // 28/10 13:00 à Paris
Audit::log('auth.login_failed', $etype, null, ['identifiant' => 'phlemoigne@example.test', 'agent' => 'Edge'], 0);
$at('2026-10-28 23:30:00'); // 29/10 00:30 à Paris : déjà le 29
Audit::log('auth.login', $etype, 1, ['identifiant' => 'admin'], 1);
$at('2026-10-29 10:00:00');

$export = ExportRegistry::find('audit-log');
$check('L’export est inscrit au registre', $export instanceof AuditLogExport);

// --- Filtre ------------------------------------------------------------------
echo "\n--- Filtre de l'écran ---\n";

$all = $export->rows(['entity_type' => $etype]);
$check('La catégorie seule sort les trois entrées', count($all) === 3, count($all) . ' ligne(s)');
$check('Ordre chronologique', array_column($all, 0) === [
    '2026-10-28 13:00:00', '2026-10-29 00:30:00', '2026-10-29 11:00:00',
], implode(' | ', array_column($all, 0)));

$day29 = $export->rows(['entity_type' => $etype, 'from' => '2026-10-29', 'to' => '2026-10-29']);
$check('Le 29 compte en heure de Paris : 00:30 en fait partie', count($day29) === 2, count($day29) . ' ligne(s)');

$day28 = $export->rows(['entity_type' => $etype, 'to' => '2026-10-28']);
$check('… et n’appartient plus au 28', count($day28) === 1, count($day28) . ' ligne(s)');

$failed = $export->rows(['entity_type' => $etype, 'q' => 'login_failed']);
$check('La recherche filtre l’export comme l’écran', count($failed) === 2);

$screen = Audit::search(['entity_type' => $etype, 'from' => '2026-10-29', 'per_page' => 50]);
$check('L’écran applique la même période', $screen['total'] === 2);

$check('Une date impossible est ignorée', Audit::day('2026-02-31') === '' && Audit::day('29/10/2026') === '');

$args = AuditLogExport::argsFrom(['audit_type' => $etype, 'audit_from' => 'n’importe quoi']);
$check('Les arguments postés sont nettoyés', $args['entity_type'] === $etype && $args['from'] === '');

// --- Colonnes ----------------------------------------------------------------
echo "\n--- Colonnes ---\n";

$check('Autant de cellules que de colonnes', count($all[0]) === count($export->columns()));
$check('L’identifiant tenté a sa colonne', $all[1][7] === 'phlemoigne@example.test');
$check('Le navigateur a sa colonne', $all[1][8] === 'Edge');
$check('Les détails restent entiers', str_contains((string) $all[1][9], '"agent"'));
$check('L’auteur est nommé', $all[2][2] !== '' && $all[2][1] !== '#1', (string) $all[2][1]);

$csv = CsvWriter::render($export->columns(), $all);
$check('Un identifiant tenté en formule est neutralisé', str_contains($csv, "'=HYPERLINK"),
    'les identifiants tentés viennent de n’importe qui');

// --- Droits ------------------------------------------------------------------
echo "\n--- Droits ---\n";

$plain = wp_insert_user([
    'user_login' => 'smk_' . substr((string) uniqid(), -6),
    'user_pass'  => wp_generate_password(20),
    'user_email' => 'smk_' . substr((string) uniqid(), -6) . '@example.test',
    'role'       => 'subscriber',
]);
$check('Un membre simple ne peut pas l’exporter', !$export->isAllowed((int) $plain));
$check('Le bureau qui voit l’onglet « Journal » le peut',
    $export->capability() === 'sub_manage_event_types');
$check('Il est tracé comme un export de données personnelles', $export->containsPersonalData());

// --- Nettoyage ---------------------------------------------------------------
$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE entity_type = %s", $etype));
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user((int) $plain);
update_option('timezone_string', $restoreTz);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
