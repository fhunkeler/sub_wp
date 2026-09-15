<?php
/**
 * Test de fumée — recherche paginée dans le journal d'audit.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-audit-search.php
 *
 * Vérifie qu'on peut retrouver une entrée par mot-clé (action, détails, IP),
 * la retrouver « par personne » alors que la table ne stocke qu'un identifiant
 * numérique, filtrer par catégorie, et paginer un historique long.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Admin\AdminUi;
use Subalcatel\Club\Support\Audit;

global $wpdb;
$table = $wpdb->prefix . 'sub_audit_log';

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-58s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

// Catégorie unique : isole nos lignes des vraies, et se nettoie sans risque.
$etype  = 'smoketest_' . substr((string) uniqid(), -6);
$marker = 'MARQUEUR_' . substr((string) uniqid(), -6);

// Un compte réel, pour éprouver la recherche « par personne ».
$login = 'smk_' . substr((string) uniqid(), -6);
$uid   = wp_insert_user([
    'user_login' => $login,
    'user_pass'  => wp_generate_password(20),
    'user_email' => $login . '@example.test',
]);

$_SERVER['REMOTE_ADDR'] = '203.0.113.77';

// 5 lignes anonymes portant le marqueur dans les détails.
for ($i = 0; $i < 5; $i++) {
    Audit::log('auth.login_failed', $etype, null, ['note' => $marker, 'n' => $i], 0);
}
// 1 ligne rattachée au compte, SANS marqueur : seule la résolution par identité
// permet de la retrouver.
Audit::log('auth.login', $etype, (int) $uid, ['sans' => 'marqueur'], (int) $uid);

echo "\n--- Recherche par mot-clé ---\n";

$byMarker = Audit::search(['q' => $marker, 'per_page' => 50]);
$check('Le marqueur retrouve les 5 lignes de détails', $byMarker['total'] === 5);

$byIp = Audit::search(['q' => '203.0.113.77', 'entity_type' => $etype, 'per_page' => 50]);
$check('La recherche par adresse IP fonctionne', $byIp['total'] === 6);

echo "\n--- Recherche par personne ---\n";

$byUser = Audit::search(['q' => $login, 'entity_type' => $etype, 'per_page' => 50]);
$check('L’identifiant du compte retrouve sa ligne, sans marqueur',
    $byUser['total'] === 1,
    'la table ne stocke qu’un user_id : la résolution se fait à la recherche');

echo "\n--- Filtre par catégorie ---\n";

$byType = Audit::search(['entity_type' => $etype, 'per_page' => 50]);
$check('Le filtre de catégorie isole nos 6 lignes', $byType['total'] === 6);
$check('La catégorie apparaît dans la liste des filtres',
    in_array($etype, Audit::entityTypes(), true));

echo "\n--- Pagination ---\n";

$p1 = Audit::search(['entity_type' => $etype, 'per_page' => 2, 'page' => 1]);
$check('per_page=2 sur 6 lignes → 3 pages', $p1['pages'] === 3);
$check('La page 1 rend bien 2 lignes', count($p1['rows']) === 2);

$p3 = Audit::search(['entity_type' => $etype, 'per_page' => 2, 'page' => 3]);
$check('La dernière page rend les 2 dernières', count($p3['rows']) === 2);

$pOver = Audit::search(['entity_type' => $etype, 'per_page' => 2, 'page' => 99]);
$check('Une page au-delà de la fin est ramenée à la dernière', $pOver['page'] === 3,
    'pas d’écran vide si on demande une page qui n’existe pas');

echo "\n--- Heure locale du navigateur ---\n";

// Le rendu ne peut pas tester le fuseau de qui lit — c'est le navigateur qui
// convertit. Ce qui se vérifie ici est la matière qu'on lui donne : l'instant
// en UTC, le marqueur que le script cherche, et un repli lisible sans lui.
$cell = AdminUi::localTime('2026-01-15 12:00:00');

$check('L’horodatage porte l’instant en UTC',
    str_contains($cell, 'datetime="2026-01-15T12:00:00+00:00"'),
    'c’est cette valeur qui fait foi, pas le texte affiché');
$check('Le marqueur attendu par le script est présent',
    str_contains($cell, 'data-sub-localtime'));
$check('Sans JavaScript, l’heure du site reste lisible',
    str_contains($cell, '>' . wp_date('d/m/Y H:i', strtotime('2026-01-15 12:00:00')) . '<'));
$check('Une date absente rend un tiret, pas une date de 1970',
    AdminUi::localTime('') === '—');

// --- Nettoyage ---------------------------------------------------------------
$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE entity_type = %s", $etype));
wp_delete_user((int) $uid);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
