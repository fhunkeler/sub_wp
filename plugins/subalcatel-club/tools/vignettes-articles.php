<?php
/**
 * Donne aux articles repris l'image mise en avant qui leur manque.
 *
 *   wp eval-file vignettes-articles.php          → simulation
 *   wp eval-file vignettes-articles.php write    → écriture
 *
 * À lancer après `import-medias.php` : la vignette est choisie parmi les médias
 * déjà repris, jamais parmi les fichiers de la zone de transit.
 *
 * L'outil est rejouable. Il ne touche pas aux articles qui ont déjà une image
 * mise en avant : si le bureau en a choisi une à la main, elle vaut mieux que
 * celle qu'on devinerait.
 */

use Subalcatel\Club\Import\ArticleThumbnails;

global $wpdb;

require_once __DIR__ . '/bootstrap.php';

$dryRun     = sub_import_is_dry_run($args ?? []);
$thumbnails = new ArticleThumbnails();

$articles = $wpdb->get_results(
    "SELECT p.ID, p.post_title, p.post_content
     FROM {$wpdb->posts} p
     LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_thumbnail_id'
     WHERE p.post_type = 'post'
       AND p.post_status IN ('publish', 'draft', 'pending', 'private')
       AND m.post_id IS NULL
     ORDER BY p.post_date DESC",
    ARRAY_A
) ?: [];

printf("%s\n", $dryRun ? '=== SIMULATION ===' : '=== ÉCRITURE ===');
printf("Articles sans image mise en avant : %d\n\n", count($articles));

$posees = $sans = 0;

foreach ($articles as $article) {
    $id = (int) $article['ID'];
    $attachmentId = $thumbnails->choose((string) $article['post_content']);

    if ($attachmentId === 0) {
        $sans++;
        printf("  #%-5d %-46s aucune image exploitable\n", $id, mb_substr((string) $article['post_title'], 0, 46));
        continue;
    }

    $meta = wp_get_attachment_metadata($attachmentId);
    $posees++;

    printf(
        "  #%-5d %-46s → média %d (%d×%d)\n",
        $id,
        mb_substr((string) $article['post_title'], 0, 46),
        $attachmentId,
        (int) ($meta['width'] ?? 0),
        (int) ($meta['height'] ?? 0)
    );

    if (!$dryRun) {
        set_post_thumbnail($id, $attachmentId);
    }
}

printf("\nVignettes posées   : %d\n", $posees);
printf("Sans illustration  : %d — la carte affiche le repli du thème\n", $sans);
