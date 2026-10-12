<?php
/**
 * Liste les chemins relatifs (sous wp-content/uploads/) des médias restants
 * après le nettoyage d'export-production.php — un chemin par ligne, sur stdout.
 *
 * Chaque média compte pour plusieurs fichiers : celui de `_wp_attached_file`
 * (souvent la version `-scaled` d'une grande photo), l'original conservé par
 * WordPress (`original_image`) et toutes les tailles intermédiaires. Ce sont
 * ces dernières que les pages affichent : `src` et `srcset` nomment
 * `…-1024x537.jpg`, `…-300x157.jpg`, jamais le fichier principal. Ne copier
 * que lui, c'était livrer des articles aux images cassées — 99 fichiers
 * manquaient en production le 12/10/2026.
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

$paths = [];

foreach ($ids as $id) {
    $path = get_post_meta($id, '_wp_attached_file', true);
    if ($path === '') {
        continue;
    }
    $paths[$path] = true;

    // Les tailles et l'original sont nommés sans dossier : ils vivent à côté
    // du fichier principal.
    $dir  = dirname($path);
    $dir  = $dir === '.' ? '' : $dir . '/';
    $meta = wp_get_attachment_metadata($id);
    if (!is_array($meta)) {
        continue;
    }
    if (!empty($meta['original_image'])) {
        $paths[$dir . $meta['original_image']] = true;
    }
    foreach ($meta['sizes'] ?? [] as $size) {
        if (!empty($size['file'])) {
            $paths[$dir . $size['file']] = true;
        }
    }
}

foreach (array_keys($paths) as $path) {
    echo $path . "\n";
}
