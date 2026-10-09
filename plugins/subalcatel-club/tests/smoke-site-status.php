<?php
/**
 * Test de fumée de la page « État du site ».
 *
 *   docker exec sub_demo_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-site-status.php
 *
 * La page remplace un diagnostic par export SQL : elle doit donc voir les
 * pannes qu'on y a réellement cherchées — permaliens restés sur « Simple »
 * après un import, en-tête figé en base par l'éditeur de site, entretien
 * quotidien qui ne passe plus — et son rapport doit pouvoir partir dans un
 * courriel sans rien livrer de personnel.
 */

use Subalcatel\Club\Admin\SiteStatusScreen;
use Subalcatel\Club\Database\Schema;
use Subalcatel\Club\Support\Audit;
use Subalcatel\Club\Support\SiteStatus;

global $wpdb;

$failures = 0;
$check    = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-58s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$find = static function (array $checks, string $group, string $label): ?array {
    foreach ($checks[$group] ?? [] as $item) {
        if (str_starts_with($item['label'], $label)) {
            return $item;
        }
    }

    return null;
};

$savedPermalinks = (string) get_option('permalink_structure', '');

// --- Les rubriques --------------------------------------------------------------
echo "\n--- Rubriques ---\n";

$checks = SiteStatus::checks();

$check('Sept rubriques', count($checks) === 7, implode(', ', array_keys($checks)));
$check('Chaque ligne a un état connu', array_reduce(
    array_merge(...array_values($checks)),
    static fn (bool $ok, array $item): bool => $ok && in_array($item['status'], ['ok', 'warn', 'bad', 'info'], true),
    true
));

$plugin = $find($checks, 'Versions', 'Extension du club');
$check('La version de l’extension est lue', $plugin !== null && str_starts_with($plugin['value'], \Subalcatel\Club\VERSION));

$schema = $find($checks, 'Versions', 'Schéma de la base');
$check('Le schéma est à jour après installation',
    $schema !== null && $schema['status'] === SiteStatus::OK
    && Schema::installedVersion() === Schema::expectedVersion());

// --- Permaliens --------------------------------------------------------------
echo "\n--- Permaliens ---\n";

update_option('permalink_structure', '/%postname%/');
$check('« Nom de l’article » : correct',
    $find(SiteStatus::checks(), 'Adresses', 'Permaliens')['status'] === SiteStatus::OK);

update_option('permalink_structure', '');
$item = $find(SiteStatus::checks(), 'Adresses', 'Permaliens');
$check('« Simple » : à corriger', $item['status'] === SiteStatus::BAD, $item['value']);
$check('… avec la marche à suivre', str_contains($item['help'], 'Réglages → Permaliens'));

update_option('permalink_structure', $savedPermalinks);

// --- Entretien quotidien --------------------------------------------------------
echo "\n--- Entretien quotidien ---\n";

$savedDaily = $wpdb->get_results(
    "SELECT * FROM {$wpdb->prefix}sub_audit_log WHERE action = 'notifications.daily'",
    ARRAY_A
);
$wpdb->delete("{$wpdb->prefix}sub_audit_log", ['action' => 'notifications.daily']);

$check('Jamais passé : à corriger',
    $find(SiteStatus::checks(), 'Tâches planifiées', 'Dernier entretien')['status'] === SiteStatus::BAD);

Audit::log('notifications.daily', 'system', null, ['test' => 1]);
$check('Passé à l’instant : correct',
    $find(SiteStatus::checks(), 'Tâches planifiées', 'Dernier entretien')['status'] === SiteStatus::OK);

$wpdb->query("UPDATE {$wpdb->prefix}sub_audit_log SET created_at = NOW() - INTERVAL 3 DAY WHERE action = 'notifications.daily'");
$item = $find(SiteStatus::checks(), 'Tâches planifiées', 'Dernier entretien');
$check('Trois jours sans passage : à corriger', $item['status'] === SiteStatus::BAD, $item['value']);

$wpdb->delete("{$wpdb->prefix}sub_audit_log", ['action' => 'notifications.daily']);
foreach ($savedDaily as $row) {
    $wpdb->insert("{$wpdb->prefix}sub_audit_log", $row);
}

// --- Surcharges de l'éditeur de site ------------------------------------------
echo "\n--- Éditeur de site ---\n";

$check('Aucune surcharge sur une installation neuve',
    SiteStatus::siteEditorOverrides()[0]['value'] === 'aucune');

// Ce que fait l'éditeur quand on modifie l'en-tête : une partie de modèle en
// base, rattachée au thème actif.
$partId = wp_insert_post([
    'post_type'    => 'wp_template_part',
    'post_status'  => 'publish',
    'post_name'    => 'header',
    'post_title'   => 'En-tête',
    'post_content' => '<!-- wp:paragraph --><p>Ancien en-tête</p><!-- /wp:paragraph -->',
    'tax_input'    => ['wp_theme' => [get_stylesheet()], 'wp_template_part_area' => ['header']],
]);
wp_set_object_terms($partId, get_stylesheet(), 'wp_theme');

$overrides = SiteStatus::siteEditorOverrides();
$header    = $overrides[0] ?? [];
$check('L’en-tête figé en base est repéré', str_contains((string) ($header['label'] ?? ''), 'En-tête'));
$check('… comme masquant le fichier du thème',
    ($header['status'] ?? '') === SiteStatus::WARN && str_contains((string) $header['value'], 'masque'));
$check('… avec la marche à suivre', str_contains((string) ($header['help'] ?? ''), 'Réinitialiser'));

wp_delete_post($partId, true);

// --- Le rapport ------------------------------------------------------------------
echo "\n--- Rapport ---\n";

// Un compte au nom reconnaissable, connecté pendant le rapport : rien de lui
// ne doit s'y retrouver.
$login  = 'etat_' . strtolower(wp_generate_password(10, false));
$person = wp_insert_user([
    'user_login' => $login,
    'user_email' => $login . '@subalcatel.test',
    'user_pass'  => wp_generate_password(),
    'role'       => 'administrator',
]);
wp_set_current_user((int) $person);
$report = SiteStatus::report(SiteStatus::checks());

$check('Le rapport reprend chaque rubrique', str_contains($report, '== Versions') && str_contains($report, '== Courriel'));
$check('Aucune adresse électronique',
    !preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $report));
$check('Ni le nom d’un compte', !str_contains($report, $login));
$check('Ni la clé des documents', !defined('SUBALCATEL_DOC_KEY')
    || !str_contains($report, base64_encode((string) constant('SUBALCATEL_DOC_KEY'))));

// --- L'écran ---------------------------------------------------------------------
ob_start();
SiteStatusScreen::renderTab();
$html = (string) ob_get_clean();
$check('L’écran s’affiche pour un administrateur', str_contains($html, 'Rapport à transmettre'));

require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user((int) $person);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
