<?php
/**
 * Réimporte le contenu des articles dont les images base64 ont été cassées par
 * le nettoyage. On repart de la source d'origine, jamais du contenu déjà en
 * base : celui-ci a perdu le préfixe `data:` et n'est plus récupérable.
 */

use Subalcatel\Club\Import\ArticleImporter;
use Subalcatel\Club\Import\EmbeddedImages;
use Subalcatel\Club\Import\Report;
use Subalcatel\Club\Import\Sanitizer;

global $wpdb;

require_once __DIR__ . '/bootstrap.php';

$dryRun = sub_import_is_dry_run($args ?? []);
$source = sub_import_source();
$report = new Report();
$images = new EmbeddedImages($report);

// Articles portant la trace du préfixe `data:` retiré.
$casses = $wpdb->get_results(
    "SELECT p.ID, m.meta_value AS legacy_id, p.post_title
     FROM {$wpdb->posts} p
     JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '" . ArticleImporter::JOOMLA_ID_META . "'
     WHERE p.post_content LIKE '%src=\"image/%;base64,%'",
    ARRAY_A
) ?: [];

printf("%s\n", $dryRun ? '=== SIMULATION ===' : '=== CORRECTION ===');
printf("Articles à corriger : %d\n\n", count($casses));

foreach ($casses as $article) {
    $legacyId = (int) $article['legacy_id'];
    $row = $source->row(
        'SELECT title, introtext, `fulltext` FROM ' . $source->table('content') . ' WHERE id = %d',
        [$legacyId]
    );

    if ($row === null) {
        printf("  #%d : source introuvable\n", $legacyId);
        continue;
    }

    $title = Sanitizer::text($row['title'] ?? '', 190);
    $body  = trim((string) ($row['introtext'] ?? '') . "\n\n" . (string) ($row['fulltext'] ?? ''));

    $avant     = strlen($body);
    $extracted = $images->extract($body, $title, $dryRun);
    $safe      = Sanitizer::html($extracted['html']);

    printf(
        "  #%-5d %-44s %6d Ko → %5d Ko  (%d image(s))\n",
        $legacyId,
        mb_substr($title, 0, 44),
        (int) ($avant / 1024),
        (int) (strlen($safe['html']) / 1024),
        $extracted['imported']
    );

    if (!$dryRun) {
        wp_update_post([
            'ID'           => (int) $article['ID'],
            'post_content' => $safe['html'],
        ]);
    }
}

printf("\nMédias : %d\n", $report->countAdded('medias'));
foreach ($report->addedIn('medias') as $m) {
    printf("  %s — %s\n", $m['id'], $m['detail']);
}
foreach ($report->warnings() as $w) {
    printf("  ! %s\n", $w);
}
