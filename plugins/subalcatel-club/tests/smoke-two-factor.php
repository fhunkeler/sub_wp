<?php
/**
 * Test de fumée — double authentification.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-two-factor.php
 *
 * Deux risques symétriques, et ce test surveille les deux : laisser un compte
 * qui voit les certificats médicaux sans second facteur, et enfermer dehors un
 * bureau qui n'a pas les moyens d'en activer un.
 *
 * L'extension `two-factor` n'a pas besoin d'être installée : ses deux points de
 * contact sont derrière des filtres, que ce test pilote.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Identity\Roles;
use Subalcatel\Club\Support\TwoFactorGate;

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-56s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$makeUser = static function (string $role): int {
    return (int) wp_insert_user([
        'user_login' => 'demo_' . wp_generate_password(8, false),
        'user_email' => wp_generate_password(8, false) . '@subalcatel.test',
        'user_pass'  => wp_generate_password(),
        'first_name' => 'Test',
        'role'       => $role,
    ]);
};

$office = $makeUser(Roles::OFFICE);
$member = $makeUser(Roles::MEMBER);

$officeUser = get_user_by('id', $office);
$memberUser = get_user_by('id', $member);

// Les deux états de l'extension sont joués par filtre, jamais lus du site :
// le test doit donner le même verdict qu'elle soit installée ici ou non.
$extensionPresente = static fn (): bool => true;
$extensionAbsente  = static fn (): bool => false;
$facteurActif      = static fn (): bool => true;

// --- Le périmètre ------------------------------------------------------------
echo "\n--- Qui est concerné ---\n";

$check('Un membre du bureau est concerné', TwoFactorGate::estConcerne($officeUser),
    'il consulte les certificats médicaux et exporte l’annuaire');

$check('Un simple membre ne l’est pas', !TwoFactorGate::estConcerne($memberUser),
    'la règle ne doit pas déborder sur les 133 adhérents');

$admin = get_user_by('id', 1);
$check('L’administrateur est concerné', $admin && TwoFactorGate::estConcerne($admin));

// --- L'exigence --------------------------------------------------------------
echo "\n--- L’exigence ---\n";

add_filter('subalcatel_two_factor_available', $extensionAbsente);
$check('Extension absente : personne n’est bloqué', !TwoFactorGate::manque($officeUser),
    'sinon l’administration se ferme sans recours');
remove_filter('subalcatel_two_factor_available', $extensionAbsente);

add_filter('subalcatel_two_factor_available', $extensionPresente);

$check('Extension présente, facteur absent : le bureau est bloqué',
    TwoFactorGate::manque($officeUser));

$check('Un simple membre n’est jamais bloqué', !TwoFactorGate::manque($memberUser));

add_filter('subalcatel_two_factor_user_enabled', $facteurActif);
$check('Second facteur activé : le bureau circule', !TwoFactorGate::manque($officeUser));
remove_filter('subalcatel_two_factor_user_enabled', $facteurActif);

// --- La porte de secours -----------------------------------------------------
echo "\n--- La porte de secours ---\n";

$coupe = static fn (): bool => false;
add_filter('subalcatel_two_factor_required', $coupe);
$check('Règle désarmée dans les réglages : plus personne n’est bloqué',
    !TwoFactorGate::manque($officeUser),
    'le bureau doit pouvoir se déverrouiller lui-même');
remove_filter('subalcatel_two_factor_required', $coupe);

wp_set_current_user($office);
$GLOBALS['pagenow'] = 'profile.php';

// Si la barrière se refermait ici, `wp_safe_redirect` + `exit` couperaient le
// test net : l'absence du résumé final serait l'échec.
TwoFactorGate::barriere();
$check('L’écran de profil reste ouvert au compte bloqué', true,
    'c’est là, et seulement là, qu’on active son second facteur');

$GLOBALS['pagenow'] = 'index.php';

// --- Mots de passe d'application ---------------------------------------------
echo "\n--- Contournements ---\n";

$check('Mot de passe d’application refusé au bureau',
    !TwoFactorGate::motsDePasseApplication(true, $officeUser),
    'il ouvre l’API sans second facteur');

$check('Mot de passe d’application laissé aux autres comptes',
    TwoFactorGate::motsDePasseApplication(true, $memberUser));

// --- L'état affiché au bureau ------------------------------------------------
echo "\n--- Liste des comptes concernés ---\n";

$ids = array_map(static fn (array $l): int => $l['user']->ID, TwoFactorGate::comptesConcernes());

$check('Le bureau figure dans la liste', in_array($office, $ids, true));
$check('Le membre n’y figure pas', !in_array($member, $ids, true));

remove_filter('subalcatel_two_factor_available', $extensionPresente);

// --- Nettoyage ---------------------------------------------------------------
wp_set_current_user(0);
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($office);
wp_delete_user($member);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
