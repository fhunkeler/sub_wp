<?php
/**
 * Test de fumée des notes de version.
 *
 *   docker exec sub_demo_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-updater.php
 *
 * « Afficher les détails de la version » menait à wordpress.org, où cette
 * extension n'est pas publiée : la fenêtre s'ouvrait sur une erreur. Ce que
 * vérifie cette suite est donc simple — le dépôt répond à la place, et son
 * Markdown arrive en HTML propre.
 *
 * Aucun appel réseau : la réponse de GitHub est jouée par `pre_http_request`.
 * Un test qui dépend de la disponibilité d'une API tierce finit rouge un jour
 * où le code n'y est pour rien.
 */

require_once __DIR__ . '/helpers.php';
require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

use Subalcatel\Club\Setup\ReleaseNotes;
use Subalcatel\Club\Setup\Updater;

$failures = 0;

$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-56s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

// --- Conversion du Markdown --------------------------------------------------
echo "\n--- Notes de version en HTML ---\n";

$html = ReleaseNotes::toHtml(
    "## What's Changed\n"
    . "* Un correctif by @quelquun in https://github.com/fhunkeler/sub_wp/pull/14\n"
    . "* Un autre, avec du `code` et du **gras**\n"
    . "\n"
    . "**Full Changelog**: https://github.com/fhunkeler/sub_wp/compare/a...b"
);

$check('Le titre descend sous celui de la fenêtre', str_contains($html, '<h3>'),
    'deux h2 côte à côte se lisent comme deux pages');
$check('Les puces deviennent une liste', str_contains($html, '<ul><li>'));
$check('Les adresses nues deviennent des liens',
    str_contains($html, '<a href="https://github.com/fhunkeler/sub_wp/pull/14">'));
$check('Le gras et le code sont rendus',
    str_contains($html, '<strong>gras</strong>') && str_contains($html, '<code>code</code>'));
$check('Le dernier paragraphe survit à la ligne vide', str_contains($html, '<p><strong>Full Changelog</strong>'));

// Ce texte est rédigé sur GitHub et s'affiche dans l'administration du site :
// il n'a aucune raison d'y apporter du balisage.
$injection = ReleaseNotes::toHtml('* <script>alert(1)</script> et <img src=x onerror=1>');

$check('Le balisage des notes est échappé',
    !str_contains($injection, '<script>') && !str_contains($injection, '<img'),
    'les notes viennent d’un dépôt, pas du site');

$check('Une release sans notes ne casse rien', ReleaseNotes::toHtml('') === '');

// --- Réponse servie à la place de wordpress.org ------------------------------
echo "\n--- Fiche de l’extension ---\n";

$payload = static function (string $balise, string $archive, string $corps): array {
    return [
        'tag_name'     => $balise,
        'draft'        => false,
        'prerelease'   => false,
        'html_url'     => 'https://github.com/fhunkeler/sub_wp/releases/tag/' . $balise,
        'published_at' => '2026-09-10T08:07:10Z',
        'body'         => $corps,
        'assets'       => [
            ['name' => $archive, 'browser_download_url' => 'https://example.test/' . $archive],
        ],
    ];
};

$jeu = [
    $payload('plugin-9.9.9', 'subalcatel-club-9.9.9.zip', "## Nouveautés\n* Une ligne de note"),
    $payload('theme-9.9.9', 'subalcatel-9.9.9.zip', "## Nouveautés du thème\n* Une autre ligne"),
];

$servir = static function ($court, $args, $url) use ($jeu) {
    if (!str_contains((string) $url, 'api.github.com')) {
        return $court;
    }

    return [
        'headers'  => [],
        'body'     => (string) wp_json_encode($jeu),
        'response' => ['code' => 200, 'message' => 'OK'],
        'cookies'  => [],
        'filename' => null,
    ];
};

add_filter('pre_http_request', $servir, 10, 3);
delete_site_transient('subalcatel_releases');

$fiche = plugins_api('plugin_information', ['slug' => 'subalcatel-club']);

$check('La fiche ne part plus chercher wordpress.org', !is_wp_error($fiche),
    is_wp_error($fiche) ? wp_strip_all_tags($fiche->get_error_message()) : 'servie par le dépôt');

if (!is_wp_error($fiche)) {
    $check('Elle porte la version publiée', $fiche->version === '9.9.9', $fiche->version);
    $check('Elle porte les notes', str_contains((string) ($fiche->sections['changelog'] ?? ''), 'Une ligne de note'));
    $check('Pas de renvoi vers wordpress.org', !empty($fiche->external),
        'l’extension n’y est pas publiée');
}

// Le filtre se déclenche pour toute fiche demandée : celle d'une autre
// extension doit lui échapper intacte.
$check('Une autre extension n’est pas détournée',
    Updater::ficheExtension(false, 'plugin_information', (object) ['slug' => 'akismet']) === false);

$check('Une autre demande n’est pas détournée',
    Updater::ficheExtension(false, 'query_plugins', (object) ['slug' => 'subalcatel-club']) === false);

// --- Le thème, qui n'a pas d'équivalent de `plugins_api` ---------------------
echo "\n--- Notes du thème ---\n";

$offre = Updater::offreTheme(false, ['Version' => '1.0.0'], 'subalcatel');

$check('Une mise à jour du thème est offerte', is_array($offre), $offre['version'] ?? '');
$check('Ses détails ne pointent plus vers GitHub',
    is_array($offre) && !str_contains((string) $offre['url'], 'github.com'),
    'WordPress encadre cette adresse, et GitHub refuse de l’être');
$check('Ils pointent vers la page servie par le club',
    is_array($offre) && str_contains((string) $offre['url'], 'admin-post.php'));

// --- Nettoyage ----------------------------------------------------------------
remove_filter('pre_http_request', $servir, 10);
delete_site_transient('subalcatel_releases');

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
