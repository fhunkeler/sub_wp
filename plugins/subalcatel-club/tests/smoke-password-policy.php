<?php
/**
 * Test de fumée — politique de mot de passe.
 *
 *   docker exec sub_prod_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-password-policy.php
 *
 * Vérifie qu'un mot de passe trop proche de l'identité est refusé (le cas
 * « login = mot de passe »), que les écrans natifs de réinitialisation et de
 * profil appliquent bien la règle, et que le contrôle de fuite est débrayable.
 *
 * Le contrôle « Have I Been Pwned » sort sur le réseau : on le désactive par
 * filtre pour que la suite soit déterministe et ne dépende pas d'Internet.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Support\PasswordPolicy;

add_filter('subalcatel_password_breach_check_enabled', '__return_false');

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-58s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$identity = [
    'login' => 'mathieu',
    'email' => 'm.langlais@orange.fr',
    'first' => 'Mathieu',
    'last'  => 'Langlais',
];

// --- Similarité --------------------------------------------------------------
echo "\n--- Proximité avec l’identité ---\n";

$check('« login = mot de passe » est refusé',
    PasswordPolicy::tooSimilar('mathieu', $identity),
    'le cas qui a motivé tout ceci');
$check('Casse ignorée (« Mathieu »)', PasswordPolicy::tooSimilar('Mathieu', $identity));
$check('Le mot de passe contenant l’identifiant est refusé',
    PasswordPolicy::tooSimilar('mathieu2024', $identity));
$check('La partie locale de l’e-mail est refusée',
    PasswordPolicy::tooSimilar('m.langlais', $identity));
$check('Le nom de famille est refusé',
    PasswordPolicy::tooSimilar('langlais!', $identity));
$check('Un mot de passe sans rapport passe',
    !PasswordPolicy::tooSimilar('corail-abysse-92', $identity),
    'la règle ne doit pas mordre sur un bon mot de passe');
$check('Un fragment court (< 4) ne piège pas',
    !PasswordPolicy::tooSimilar('corail-abysse-92', ['first' => 'Ana']),
    '« Ana » apparaîtrait dans trop de mots de passe');

// --- rejectionReason (façade utilisée par les formulaires) -------------------
echo "\n--- Verdict global ---\n";

$check('rejectionReason renvoie un message pour « login = mot de passe »',
    PasswordPolicy::rejectionReason('mathieu', $identity) !== null);
$check('rejectionReason laisse passer un bon mot de passe',
    PasswordPolicy::rejectionReason('corail-abysse-92', $identity) === null);

// --- Écran natif de réinitialisation -----------------------------------------
echo "\n--- Hook validate_password_reset ---\n";

$user = new WP_User();
$user->user_login = 'mathieu';
$user->user_email = 'm.langlais@orange.fr';

$_POST['pass1'] = 'mathieu';
$errors = new WP_Error();
PasswordPolicy::onCoreReset($errors, $user);
$check('Réinitialiser vers « login = mot de passe » est bloqué',
    $errors->has_errors(),
    'c’est l’écran par lequel les comptes repris ont été initialisés');

$_POST['pass1'] = 'corail-abysse-92';
$errors = new WP_Error();
PasswordPolicy::onCoreReset($errors, $user);
$check('Réinitialiser vers un bon mot de passe passe', !$errors->has_errors());

$_POST['pass1'] = 'court';
$errors = new WP_Error();
PasswordPolicy::onCoreReset($errors, $user);
$check('Un mot de passe trop court est refusé à la réinitialisation',
    $errors->has_errors(),
    'WordPress ne l’imposait pas de lui-même sur cet écran');

// --- Réglages depuis l'administration ----------------------------------------
// La longueur et l'activation de la similarité se lisent dans SecuritySettings ;
// on les pilote ici par filtre, sans écrire l'option.
echo "\n--- Pilotage par les réglages ---\n";

$settings = static fn (array $v): callable => static fn (): array => $v;

$len20 = $settings(['password_min_length' => 20, 'password_similarity' => true]);
add_filter('pre_option_subalcatel_security', $len20);
$_POST['pass1'] = 'corail-abysse-92'; // 16 caractères : bon, mais trop court pour 20
$errors = new WP_Error();
PasswordPolicy::onCoreReset($errors, $user);
$check('La longueur minimale suit le réglage (20 exigés)', $errors->has_errors());
remove_filter('pre_option_subalcatel_security', $len20);

$noSim = $settings(['password_similarity' => false, 'password_min_length' => 12]);
add_filter('pre_option_subalcatel_security', $noSim);
$check('Similarité désactivée : « login = mot de passe » n’est plus refusé',
    PasswordPolicy::rejectionReason('mathieu', $identity) === null,
    'le bureau peut assouplir la règle s’il le décide');
remove_filter('pre_option_subalcatel_security', $noSim);

// --- Débrayage du contrôle de fuite ------------------------------------------
echo "\n--- Débrayage ---\n";

$check('Le contrôle de fuite est désactivable par filtre',
    PasswordPolicy::isBreached('password') === false,
    'filtre à false : aucune sortie réseau, aucun blocage sur ce motif');

// --- Nettoyage ---------------------------------------------------------------
unset($_POST['pass1']);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
