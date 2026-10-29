<?php
/**
 * Test de fumée — messages de la page de connexion du club.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-login-form.php
 *
 * Un échec doit revenir sur notre page avec le bon motif : un blocage du
 * ralentisseur n'est pas un mot de passe incorrect, et le confondre pousse la
 * personne à réessayer, donc à prolonger son blocage.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Frontend\LoginForm;

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-52s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

// wp_safe_redirect() est suivi d'un exit : on intercepte l'adresse avant.
$capture = static function (): string {
    $stop = static function (string $location): never {
        throw new \RuntimeException($location);
    };
    add_filter('wp_redirect', $stop, 1);

    try {
        LoginForm::onFailure(...func_get_args());
        return '';
    } catch (\RuntimeException $e) {
        return $e->getMessage();
    } finally {
        remove_filter('wp_redirect', $stop, 1);
    }
};

$source = home_url('/connexion/');

// --- Motif de l'échec --------------------------------------------------------
echo "\n--- Retour sur la page du club ---\n";

$_POST['sub_login_page'] = $source;

$check('Mauvais mot de passe → connexion=echec',
    str_contains($capture('jean.dupont', new \WP_Error('incorrect_password', 'x')), 'connexion=echec'));

$check('Blocage du ralentisseur → connexion=bloque',
    str_contains($capture('jean.dupont', new \WP_Error('sub_locked_out', 'x')), 'connexion=bloque'));

$check('Sans erreur transmise → connexion=echec',
    str_contains($capture('jean.dupont'), 'connexion=echec'));

unset($_POST['sub_login_page']);
$check('Hors de notre page → aucune redirection', $capture('jean.dupont') === '',
    'l’écran natif garde la main');

// --- Textes affichés ---------------------------------------------------------
echo "\n--- Messages ---\n";

wp_set_current_user(0);
$_SERVER['HTTP_HOST'] ??= 'localhost'; // wp_login_form() le lit, absent en CLI

$html = LoginForm::render();
$check('L’encart de première connexion est affiché', str_contains($html, 'Première connexion'));

$_GET['connexion'] = 'echec';
$html = LoginForm::render();
$check('L’échec évoque le mot de passe rempli par le navigateur',
    str_contains($html, 'navigateur'));

$_GET['connexion'] = 'bloque';
$html = LoginForm::render();
$check('Le blocage annonce un délai en minutes',
    (bool) preg_match('/Réessayez dans \d+ minutes/', $html));
$check('Le blocage ne dit pas « incorrect »', !str_contains($html, 'incorrect'));

unset($_GET['connexion']);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
