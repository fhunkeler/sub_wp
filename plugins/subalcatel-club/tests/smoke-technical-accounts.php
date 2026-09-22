<?php
/**
 * Test de fumée des comptes techniques.
 *
 *   docker exec sub_demo_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-technical-accounts.php
 *
 * Le point à prouver : un compte technique **n'est pas un adhérent**. Il
 * administre, il ne plonge pas, il ne désigne personne. Trois conséquences
 * qu'on vérifie ici — il sort des listes de diffusion de membres, on ne lui
 * réclame aucune donnée personnelle, et le marquer efface ce qu'il en portait.
 *
 * La quatrième est une exception : il reste dans « Bureau ». Ces comptes
 * *sont* le bureau, et les exclure là viderait la seule liste par laquelle on
 * leur écrit.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Admin\MembersScreen;
use Subalcatel\Club\Communication\MailingLists;
use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Identity\ProfileFields;
use Subalcatel\Club\Identity\Roles;
use Subalcatel\Club\Identity\TechnicalAccounts;

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-58s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$makeUser = static function (string $role, string $levelSlug): int {
    $id = wp_insert_user([
        'user_login' => 'demo_' . wp_generate_password(8, false),
        'user_email' => wp_generate_password(8, false) . '@subalcatel.test',
        'user_pass'  => wp_generate_password(),
        'first_name' => 'Test',
        'role'       => $role,
    ]);

    $term = get_term_by('slug', $levelSlug, DiveLevels::TAXONOMY);
    update_user_meta($id, 'sub_dive_level_id', $term->term_id);
    update_user_meta($id, 'sub_membership_valid_until', '2027-12-31');

    return (int) $id;
};

// Deux comptes identiques : même rôle, même niveau, même adhésion. Seul le
// marquage les distinguera — c'est ce qui rend les écarts ci-dessous
// imputables à lui seul.
$ordinaire = $makeUser(Roles::OFFICE, 'e3');
$technique = $makeUser(Roles::OFFICE, 'e3');

ProfileFields::save($technique, [
    'birth_date'        => '1970-01-01',
    'mobile'            => '0600000000',
    'emergency_contact' => 'Jean Témoin — frère',
    'emergency_phone'   => '0600000001',
], $technique);

$check(
    'avant marquage, les deux comptes sont dans « Niveau E3 »',
    in_array($ordinaire, MailingLists::members('niveau-e3'), true)
        && in_array($technique, MailingLists::members('niveau-e3'), true)
);

$check(
    'avant marquage, le profil réclame une date de naissance',
    !empty(ProfileFields::forUser($technique)['birth_date']['required'])
);

$cleared = TechnicalAccounts::mark($technique);

$check('mark() marque le compte', TechnicalAccounts::is($technique));
$check('mark() ne marque pas les autres', !TechnicalAccounts::is($ordinaire));

$check(
    'mark() efface les données de membre et le dit',
    in_array('birth_date', $cleared, true)
        && in_array('emergency_contact', $cleared, true)
        && in_array('dive_level_id', $cleared, true),
    'effacé : ' . implode(', ', $cleared)
);

$check(
    'les données personnelles sont réellement parties',
    ProfileFields::get($technique, 'birth_date') === ''
        && ProfileFields::get($technique, 'mobile') === ''
        && ProfileFields::get($technique, 'emergency_phone') === ''
        && DiveLevels::forUser($technique) === null
);

$check(
    'le profil ne réclame plus rien',
    ProfileFields::forUser($technique) === [],
    count(ProfileFields::forUser($ordinaire)) . ' champ(s) pour un compte ordinaire'
);

foreach (['niveau-e3', MailingLists::INSTRUCTOR, MailingLists::LEADER, MailingLists::ACTIVE] as $list) {
    $check(
        sprintf('« %s » ne contient plus le compte technique', $list),
        !in_array($technique, MailingLists::members($list), true)
    );
}

$check(
    '« Bureau » le garde — c\'est par là qu\'on lui écrit',
    in_array($technique, MailingLists::members(MailingLists::OFFICE), true)
);

$check(
    'le compte ordinaire n\'a pas bougé',
    in_array($ordinaire, MailingLists::members('niveau-e3'), true)
        && ProfileFields::get($ordinaire, 'birth_date') === ''
        && DiveLevels::forUser($ordinaire) !== null
);

$directory = array_map(static fn (WP_User $u): int => $u->ID, MembersScreen::directory());

$check(
    'l\'annuaire du club ignore le compte technique',
    !in_array($technique, $directory, true) && in_array($ordinaire, $directory, true)
);

TechnicalAccounts::unmark($technique);
$check('unmark() rend le compte à l\'ordinaire', !TechnicalAccounts::is($technique));

require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($ordinaire);
wp_delete_user($technique);

printf("\n%s\n", $failures === 0 ? 'Tout est au vert.' : $failures . ' échec(s).');
exit($failures === 0 ? 0 : 1);
