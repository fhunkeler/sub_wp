<?php
/**
 * Liste les chemins relatifs (sous wp-content/uploads/) des médias restants
 * après le nettoyage d'export-production.php — un chemin par ligne, sur stdout.
 *
 * À lancer sur le clone "sub_export", après export-production.php write :
 *
 *   docker exec -e WORDPRESS_DB_NAME=sub_export sub_demo_cli \
 *     wp --allow-root eval-file wp-content/plugins/subalcatel-club/tools/list-kept-media.php
 */

global $wpdb;

if ($wpdb->dbname !== 'sub_export') {
    fwrite(STDERR, "Refus : à lancer sur sub_export uniquement.\n");
    exit(1);
}

$ids = array_map('intval', $wpdb->get_col(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment'"
));

foreach ($ids as $id) {
    $path = get_post_meta($id, '_wp_attached_file', true);
    if ($path !== '') {
        echo $path . "\n";
    }
}
