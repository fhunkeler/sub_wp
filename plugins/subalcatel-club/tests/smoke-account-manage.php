<?php
/**
 * Test de fumée de la gestion des comptes par le bureau.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-account-manage.php
 *
 * C'est l'écran par lequel on prend un site : courriel, rôle, mot de passe. Ce
 * qui se vérifie ici n'est donc pas tant que la modification marche — elle
 * marche — que la liste de ce qu'elle refuse. Un seul de ces refus qui saute,
 * et un membre du bureau devient administrateur, ou s'approprie le compte de
 * l'administrateur technique en trois clics.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Admin\MembersScreen;
use Subalcatel\Club\Identity\AccountFields;
use Subalcatel\Club\Identity\PasswordChange;
use Subalcatel\Club\Identity\Roles;

global $wpdb;

Roles::install();

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-58s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$makeUser = static function (string $role): int {
    return (int) wp_insert_user([
        'user_login' => 'demo_' . wp_generate_password(8, false),
        'user_email' => wp_generate_password(8, false) . '@subalcatel.test',
        'user_pass'  => wp_generate_password(),
        'first_name' => 'Test',
        'last_name'  => 'Compte',
        'role'       => $role,
    ]);
};

$office  = $makeUser(Roles::OFFICE);
$member  = $makeUser(Roles::MEMBER);
$other   = $makeUser(Roles::MEMBER);
$admin   = $makeUser('administrator');
$office2 = $makeUser(Roles::OFFICE);

// --- Le jeu de rôles ---------------------------------------------------------
echo "\n--- Rôles attribuables ---\n";

$check('« administrator » n’est pas attribuable',
    !array_key_exists('administrator', Roles::assignable()),
    'promouvoir un administrateur reste un geste de wp-admin');

$check('Les trois rôles du club le sont',
    array_keys(Roles::assignable()) === [Roles::GUEST, Roles::MEMBER, Roles::OFFICE]);

$check('Le bureau détient la capacité', user_can($office, AccountFields::CAPABILITY));
$check('Un adhérent ne la détient pas', !user_can($member, AccountFields::CAPABILITY));

$check('Un compte de club est reconnu', Roles::isClubAccount($member));
$check('Un administrateur n’est pas un compte de club', !Roles::isClubAccount($admin),
    'un compte technique ne se modifie pas depuis le site');

// --- Ce qui est permis -------------------------------------------------------
echo "\n--- Modifications légitimes ---\n";

$newMail = 'nouvelle-' . wp_generate_password(6, false) . '@subalcatel.test';
$result  = AccountFields::apply($member, $office, [
    'first_name' => 'Camille',
    'last_name'  => 'Marée',
    'user_email' => $newMail,
]);

$check('Le bureau change le courriel', $result['ok'], $result['message']);
$check('Le courriel est bien enregistré', get_userdata($member)->user_email === $newMail);
$check('Le nom affiché suit', get_userdata($member)->display_name === 'Camille Marée');

$result = AccountFields::apply($member, $office, ['role' => Roles::OFFICE]);
$check('Le bureau promeut un membre au bureau', $result['ok'], $result['message']);
$check('Le rôle est appliqué', in_array(Roles::OFFICE, get_userdata($member)->roles, true));
$check('Et la capacité suit', user_can($member, AccountFields::CAPABILITY));

$result = AccountFields::apply($member, $office, ['role' => Roles::MEMBER]);
$check('Et le retire', $result['ok'] && in_array(Roles::MEMBER, get_userdata($member)->roles, true));

$result = AccountFields::apply($member, $office, [
    'user_email' => get_userdata($member)->user_email,
]);
$check('Un envoi sans changement ne casse rien', $result['ok'] && $result['changed'] === []);

// --- Ce qui est refusé -------------------------------------------------------
echo "\n--- Refus ---\n";

$result = AccountFields::apply($member, $other, ['first_name' => 'Pirate']);
$check('Un adhérent ne modifie personne', !$result['ok'], $result['message']);
$check('Rien n’a bougé', get_userdata($member)->first_name === 'Camille');

$result = AccountFields::apply($member, $office, ['role' => 'administrator']);
$check('« administrator » est refusé', !$result['ok'], $result['message']);
$check('Le membre n’est pas administrateur', !user_can($member, 'manage_options'));

$result = AccountFields::apply($member, $office, ['role' => 'editor']);
$check('Un rôle WordPress quelconque est refusé', !$result['ok'], $result['message']);

$result = AccountFields::apply($office, $office, ['role' => Roles::MEMBER]);
$check('On ne change pas son propre rôle', !$result['ok'], $result['message']);
$check('Le bureau reste au bureau', user_can($office, AccountFields::CAPABILITY));

$result = AccountFields::apply($admin, $office, [
    'user_email' => 'pirate@subalcatel.test',
]);
$check('Le compte administrateur est intouchable', !$result['ok'], $result['message']);
$check('Son courriel est intact', get_userdata($admin)->user_email !== 'pirate@subalcatel.test',
    'c’est le chemin d’attaque : changer l’adresse, puis « mot de passe oublié »');

$result = AccountFields::apply($other, $office, [
    'user_email' => get_userdata($member)->user_email,
]);
$check('Un courriel déjà pris est refusé', !$result['ok'], $result['message']);

$result = AccountFields::apply($other, $office, ['user_email' => 'pas-une-adresse']);
$check('Une adresse invalide est refusée', !$result['ok'], $result['message']);

$result = AccountFields::apply(999999, $office, ['first_name' => 'Fantôme']);
$check('Un compte inexistant est refusé', !$result['ok'], $result['message']);

// --- Le lien de réinitialisation ---------------------------------------------
echo "\n--- Réinitialisation du mot de passe ---\n";

$GLOBALS['sub_sent_mails'] = [];
add_filter('pre_wp_mail', static function ($null, array $atts) {
    $GLOBALS['sub_sent_mails'][] = $atts;

    return true;
}, 10, 2);

$before = get_userdata($member)->user_pass;
$result = AccountFields::sendResetLink($member, $office);

$check('Le bureau envoie un lien', $result['ok'], $result['message']);
$check('Le courriel part vers la personne',
    isset($GLOBALS['sub_sent_mails'][0])
        && (string) $GLOBALS['sub_sent_mails'][0]['to'] === get_userdata($member)->user_email);

$key = $wpdb->get_var($wpdb->prepare(
    "SELECT user_activation_key FROM {$wpdb->users} WHERE ID = %d",
    $member
));

$check('Une clé de réinitialisation est posée', (string) $key !== '');
$check('Le mot de passe n’a pas été changé pour autant',
    get_userdata($member)->user_pass === $before,
    'le bureau ouvre la porte, il ne choisit pas la serrure');

$result = AccountFields::sendResetLink($admin, $office);
$check('Pas de lien pour un compte administrateur', !$result['ok'], $result['message']);

$result = AccountFields::sendResetLink($member, $other);
$check('Pas de lien à la demande d’un adhérent', !$result['ok'], $result['message']);

// --- Le geste est tracé ------------------------------------------------------
echo "\n--- Journal ---\n";

$roleChanges = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sub_audit_log
     WHERE action = 'account.role_changed' AND entity_id = %d",
    $member
));

$check('Les changements de rôle sont journalisés', $roleChanges >= 2,
    "{$roleChanges} entrée(s)");

// --- L'écran lui-même --------------------------------------------------------
echo "\n--- Fiche membre (wp-admin) ---\n";

/** Rend la fiche d'un membre telle que la verrait la personne connectée. */
$fiche = static function (int $userId): string {
    $_GET['page']    = MembersScreen::SLUG;
    $_GET['user_id'] = (string) $userId;

    ob_start();
    MembersScreen::render();
    $html = (string) ob_get_clean();

    unset($_GET['page'], $_GET['user_id']);

    return $html;
};

wp_set_current_user($office2);

$html = $fiche($other);
$check('La fiche porte le panneau Compte', str_contains($html, 'value="sub_member_account"'));
$check('Le courriel y est modifiable', str_contains($html, 'name="user_email"'));
$check('Le rôle aussi', str_contains($html, 'name="role"'));
$check('Le lien de réinitialisation y est',
    str_contains($html, 'value="sub_member_reset"'),
    'jamais un champ « nouveau mot de passe » pour autrui');
$check('Aucun champ « mot de passe » pour autrui', !str_contains($html, 'type="password"'));
$check('L’identifiant n’est pas modifiable', !str_contains($html, 'name="user_login"'));
$check('Le profil de plongée reste sur la même page',
    str_contains($html, 'value="sub_member_save"'),
    'un seul endroit pour modifier un utilisateur');

// Ce que le script des onglets exige du HTML. Il remonte de la barre d'onglets
// à son formulaire : encore faut-il qu'elle soit dans un formulaire, et qu'il y
// ait autant de panneaux que d'onglets — sans quoi `setupTabs` renonce en
// silence et la fiche se déplie d'un bloc. C'est exactement ce qui est arrivé
// en ajoutant le panneau « Compte » devant.
$tabs   = substr_count($html, 'class="nav-tab sub-tabs__tab"');
$panels = substr_count($html, 'class="sub-panel"');

$check('Autant de panneaux que d’onglets', $tabs > 1 && $tabs === $panels,
    "{$tabs} onglet(s), {$panels} panneau(x)");

$avantBarre = substr($html, 0, (int) strpos($html, 'nav-tab-wrapper sub-tabs'));
$check('La barre d’onglets est bien dans un formulaire',
    strrpos($avantBarre, '<form') > (int) strrpos($avantBarre, '</form>'),
    'le script remonte de la barre à son formulaire');

$html = $fiche($admin);
$check('La fiche d’un administrateur n’offre aucun champ de compte',
    str_contains($html, 'administration technique')
        && !str_contains($html, 'value="sub_member_account"'));

$html = $fiche($office2);
$check('Sur sa propre fiche, le rôle est en lecture seule', !str_contains($html, 'name="role"'));

// Un adhérent n'atteint pas cet écran : `render()` s'arrête sur `wp_die`, donc
// on vérifie la porte plutôt que la page.
$check('Un adhérent n’a pas la capacité de la fiche',
    !user_can($other, 'sub_manage_memberships') && !user_can($other, AccountFields::CAPABILITY));

// Un membre du bureau sans la capacité « comptes » tient la fiche, mais pas le
// panneau : c'est tout l'intérêt d'avoir séparé les deux droits.
get_role(Roles::OFFICE)->remove_cap(AccountFields::CAPABILITY);
wp_cache_delete($office2, 'users');
wp_cache_delete($office2, 'user_meta');
wp_set_current_user(0);
wp_set_current_user($office2);

$html = $fiche($other);
$check('Sans la capacité « comptes », le panneau disparaît',
    !str_contains($html, 'value="sub_member_account"'));
$check('Mais la fiche de plongée reste', str_contains($html, 'value="sub_member_save"'));

Roles::install();

// --- Le panneau mot de passe -------------------------------------------------
echo "\n--- Mot de passe du membre ---\n";

$panel = PasswordChange::renderPanel($office2);

$check('Le mot de passe actuel est demandé', str_contains($panel, 'name="current_password"'),
    'être connecté ne prouve pas être la bonne personne');
$check('La confirmation aussi', str_contains($panel, 'name="new_password_confirm"'));
$check('La longueur minimale est imposée côté navigateur',
    str_contains($panel, 'minlength="' . PasswordChange::MIN_LENGTH . '"'));

wp_set_current_user(0);

// --- Nettoyage ----------------------------------------------------------------
require_once ABSPATH . 'wp-admin/includes/user.php';

foreach ([$office, $member, $other, $admin, $office2] as $id) {
    $wpdb->delete("{$wpdb->prefix}sub_audit_log", ['entity_id' => $id, 'entity_type' => 'user']);
    wp_delete_user($id);
}

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
