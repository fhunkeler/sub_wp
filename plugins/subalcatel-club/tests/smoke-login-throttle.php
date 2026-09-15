<?php
/**
 * Test de fumée — ralentisseur de connexion.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-login-throttle.php
 *
 * WordPress ne limite pas les tentatives de mot de passe. Ce test vérifie que
 * le ralentisseur bloque au bon seuil, épargne les tiers, et se relâche après
 * un succès — sans jamais enfermer quelqu'un définitivement.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Support\LoginThrottle;

global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%sub_login%'");

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-52s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$isLocked = static function (mixed $result): bool {
    return $result instanceof \WP_Error && $result->get_error_code() === 'sub_locked_out';
};

$attempt = static function (string $user, string $pass, mixed $incoming = null): mixed {
    return apply_filters('authenticate', $incoming, $user, $pass);
};

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

// --- Montée jusqu'au seuil ---------------------------------------------------
echo "\n--- Comptage des échecs ---\n";

for ($i = 1; $i <= 7; $i++) {
    do_action('wp_login_failed', 'jean.dupont');
}

$check('Sept échecs ne verrouillent pas encore', !$isLocked($attempt('jean.dupont', 'x')),
    'la marge d’erreur d’un vrai membre est préservée');

do_action('wp_login_failed', 'jean.dupont'); // 8e

$check('Le huitième échec verrouille', $isLocked($attempt('jean.dupont', 'x')));

// --- Le verrou tient contre le bon mot de passe ------------------------------
echo "\n--- Solidité du verrou ---\n";

$realUser = new \WP_User(1);
$check('Même le bon mot de passe est refusé pendant le verrou',
    $isLocked($attempt('jean.dupont', 'bon', $realUser)),
    'sinon la force brute réussit dès qu’elle trouve');

// --- Cloisonnement -----------------------------------------------------------
echo "\n--- Cloisonnement ---\n";

$_SERVER['REMOTE_ADDR'] = '198.51.100.55';
$check('Une autre origine n’est pas punie', !$isLocked($attempt('jean.dupont', 'x')),
    'bloquer sur le seul identifiant permettrait de verrouiller le compte d’un tiers');

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$check('Un autre identifiant depuis la même IP passe', !$isLocked($attempt('marie.martin', 'x')),
    'le couple IP+identifiant, pas l’IP seule');

// --- Relâchement après succès ------------------------------------------------
echo "\n--- Réarmement ---\n";

do_action('wp_login', 'jean.dupont', $realUser);

$check('Un login réussi remet le compteur à zéro',
    !$isLocked($attempt('jean.dupont', 'x')),
    'on ne reste pas enfermé après s’être enfin souvenu du mot de passe');

// --- Une saisie vide n'est pas une tentative ---------------------------------
echo "\n--- Cas limites ---\n";

$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%sub_login%'");
$attempt('', '');
$check('Le formulaire vide ne déclenche pas le verrou',
    !$isLocked($attempt('', '')),
    'ouvrir la page de connexion n’est pas une tentative');
$check('Et n’a rien enregistré',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%sub_login%'") === 0);

// --- Débrayage ---------------------------------------------------------------
$check('Le ralentisseur est désactivable par filtre',
    apply_filters('subalcatel_login_throttle_enabled', true) === true,
    'récupérable si un réglage se révèle trop strict');

// --- Balayage (password spraying) --------------------------------------------
// Un seul mot de passe essayé sur beaucoup de comptes : chaque couple
// IP+identifiant reste sous 8, mais l'IP accumule. C'est le second compteur qui
// doit fermer la porte. Section auto-contenue, placée en fin de suite : on part
// d'un état propre (cache d'objets compris) sur une IP jamais vue plus haut.
echo "\n--- Balayage par IP ---\n";

$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%sub_login%'");
wp_cache_flush();
$_SERVER['REMOTE_ADDR'] = '203.0.113.200';

for ($i = 0; $i < 29; $i++) {
    do_action('wp_login_failed', "membre.n{$i}");
}

$check('29 échecs sur des comptes distincts ne bloquent pas encore l’IP',
    !$isLocked($attempt('membre.neuf', 'x')),
    'un vrai réseau partagé doit garder de la marge');

do_action('wp_login_failed', 'membre.n29'); // 30e depuis cette IP

$check('Le 30e échec depuis l’IP bloque un identifiant jamais essayé',
    $isLocked($attempt('parfait.inconnu', 'x')),
    'le balayage échappe au compteur IP+identifiant, pas au compteur IP');

$_SERVER['REMOTE_ADDR'] = '198.51.100.77';
$check('Une IP voisine reste libre',
    !$isLocked($attempt('parfait.inconnu', 'x')),
    'on ferme l’origine qui balaie, pas tout Internet');

// --- IP de confiance ---------------------------------------------------------
// Origine déclarée de confiance (local du club, VPN…) : jamais ralentie, même
// sous un déluge d'échecs. On injecte la liste par filtre, sans écrire l'option.
echo "\n--- IP de confiance ---\n";

$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%sub_login%'");
wp_cache_flush();

$exemption = static fn (): array => ['throttle_ip_allowlist' => ['203.0.113.222', '10.0.0.0/8']];
add_filter('pre_option_subalcatel_security', $exemption);

$_SERVER['REMOTE_ADDR'] = '203.0.113.222';
for ($i = 0; $i < 40; $i++) {
    do_action('wp_login_failed', "cible.n{$i}");
}
$check('Une IP exacte de confiance n’est jamais verrouillée',
    !$isLocked($attempt('cible.n0', 'x')),
    '40 échecs, et pourtant libre');

$_SERVER['REMOTE_ADDR'] = '10.5.6.7'; // dans 10.0.0.0/8
for ($i = 0; $i < 40; $i++) {
    do_action('wp_login_failed', "cible.c{$i}");
}
$check('Une IP dans un bloc CIDR de confiance n’est jamais verrouillée',
    !$isLocked($attempt('cible.c0', 'x')));

remove_filter('pre_option_subalcatel_security', $exemption);

// --- Nettoyage ---------------------------------------------------------------
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%sub_login%'");

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
