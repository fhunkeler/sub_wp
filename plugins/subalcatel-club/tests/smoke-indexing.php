<?php
/**
 * Test de fumée des pages tenues hors des moteurs de recherche.
 *
 *   docker exec sub_demo_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-indexing.php
 *
 * Une page `noindex` doit l'être aux deux endroits que lit Google : la balise
 * sur la page, et son absence du sitemap XML. Les autres pages publiques ne
 * doivent rien perdre.
 */

use Subalcatel\Club\Content\SearchIndexing;
use Subalcatel\Club\Frontend\Pages;

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-58s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$hidden = [Pages::LOGIN, Pages::SIGNUP, 'cookies', 'plan-du-site'];
$shown  = [Pages::HOME, Pages::CONTACT, Pages::PRICING, 'mentions-legales'];

$robotsOf = static function (string $key): array {
    global $wp_query;

    $wp_query = new WP_Query(['page_id' => Pages::id($key)]);
    $GLOBALS['wp_the_query'] = $wp_query;

    return apply_filters('wp_robots', []);
};

echo "\n--- Balise robots ---\n";

foreach ($hidden as $key) {
    $check("noindex : {$key}", Pages::id($key) > 0 && !empty($robotsOf($key)['noindex']));
    $check("liens suivis : {$key}", empty($robotsOf($key)['nofollow']));
}

foreach ($shown as $key) {
    $check("indexée : {$key}", Pages::id($key) > 0 && empty($robotsOf($key)['noindex']));
}

echo "\n--- Sitemap XML ---\n";

$provider = wp_sitemaps_get_server()->registry->get_provider('posts');
$urls     = array_column($provider->get_url_list(1, 'page'), 'loc');

foreach ($hidden as $key) {
    $check("absente du sitemap : {$key}", !in_array(Pages::url($key), $urls, true));
}

foreach ($shown as $key) {
    $check("présente au sitemap : {$key}", in_array(Pages::url($key), $urls, true));
}

$check('Quatre pages écartées', count(SearchIndexing::pageIds()) === 4);

printf("\n%s\n", $failures === 0 ? 'Tout est vert.' : "{$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
