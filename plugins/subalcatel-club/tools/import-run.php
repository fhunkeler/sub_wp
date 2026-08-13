<?php
/**
 * Lance la reprise Joomla. Simulation par défaut ; --write pour écrire.
 *
 *   wp eval-file import-run.php            → simulation
 *   wp eval-file import-run.php write      → écriture réelle
 *   wp eval-file import-run.php write articles
 */

use Subalcatel\Club\Import\JoomlaImport;

require_once __DIR__ . '/bootstrap.php';

$argsList = $args ?? [];
$dryRun   = sub_import_is_dry_run($argsList);
$only     = array_values(array_diff($argsList, ['write']));

$report = (new JoomlaImport(sub_import_source()))->run($dryRun, $only);

printf("\n%s\n", $dryRun ? '=== SIMULATION (aucune écriture) ===' : '=== ÉCRITURE RÉELLE ===');

foreach ($report->sections() as $section) {
    printf(
        "\n%-12s  %4d repris  %4d écartés\n",
        strtoupper($section),
        $report->countAdded($section),
        $report->countSkipped($section)
    );

    $reasons = [];
    foreach ($report->skippedIn($section) as $skip) {
        $key             = preg_replace('/\d+/', 'N', $skip['reason']);
        $reasons[$key]   = ($reasons[$key] ?? 0) + 1;
    }
    foreach ($reasons as $reason => $count) {
        printf("    écarté ×%-4d %s\n", $count, $reason);
    }

    foreach (array_slice($report->addedIn($section), 0, 3) as $item) {
        printf("    ex. %s\n", $item['detail']);
    }
}

$warnings = $report->warnings();
printf("\n--- Avertissements : %d ---\n", count($warnings));
$grouped = [];
foreach ($warnings as $w) {
    $key           = preg_replace('/«.*?»/u', '«…»', preg_replace('/\d+/', 'N', $w));
    $grouped[$key] = ($grouped[$key] ?? 0) + 1;
}
foreach ($grouped as $message => $count) {
    printf("  ×%-4d %s\n", $count, $message);
}
