<?php
/**
 * Reprend les images des articles depuis la zone de transit.
 *
 *   wp eval-file import-medias.php          → simulation
 *   wp eval-file import-medias.php write    → écriture
 */

use Subalcatel\Club\Import\ArticleImporter;
use Subalcatel\Club\Import\LegacyMedia;
use Subalcatel\Club\Import\Report;

global $wpdb;

require_once __DIR__ . '/bootstrap.php';

$dryRun  = sub_import_is_dry_run($args ?? []);
$report  = new Report();
$medias  = new LegacyMedia($report, sub_import_staging());

$articles = $wpdb->get_results(
    "SELECT p.ID, p.post_title, p.post_content
     FROM {$wpdb->posts} p
     JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '" . ArticleImporter::JOOMLA_ID_META . "'
     WHERE p.post_content LIKE '%<img%'",
    ARRAY_A
) ?: [];

printf("%s\n", $dryRun ? '=== SIMULATION ===' : '=== ÉCRITURE ===');
printf("Articles portant des images : %d\n\n", count($articles));

$totalIn = $totalMissing = $modifies = 0;

foreach ($articles as $article) {
    $result = $medias->rewrite((string) $article['post_content'], (string) $article['post_title'], $dryRun);

    $totalIn      += $result['imported'];
    $totalMissing += $result['missing'];

    if ($result['html'] === $article['post_content']) {
        continue;
    }

    $modifies++;

    if (!$dryRun) {
        wp_update_post(['ID' => (int) $article['ID'], 'post_content' => $result['html']]);
    }
}

printf("Articles modifiés : %d\n", $modifies);
printf("Images reprises   : %d\n", $totalIn);
printf("Images absentes   : %d\n", $totalMissing);
printf("Médias créés      : %d\n", $report->countAdded('medias'));

$warnings = $report->warnings();
printf("\nAvertissements : %d\n", count($warnings));
foreach (array_slice($warnings, 0, 8) as $w) {
    printf("  %s\n", $w);
}
