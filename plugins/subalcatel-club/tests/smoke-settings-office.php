<?php
/**
 * Test de fumée — onglet « Fonctions du bureau » des Réglages.
 *
 *   docker exec sub_demo_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-settings-office.php
 *
 * Le point à vérifier n'est pas que le formulaire s'affiche — c'est qu'il
 * propose bien n'importe quel adhérent comme candidat, pas seulement les
 * comptes qui portent le rôle « Membre du bureau ». C'est tout le sujet du
 * registre : la fonction ne vit plus sur le compte de son titulaire.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Admin\SettingsScreen;
use Subalcatel\Club\Identity\OfficePosition;
use Subalcatel\Club\Identity\Roles;

delete_option(OfficePosition::OPTION);

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-58s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$makeUser = static function (string $role, string $firstName = 'Test'): int {
    return (int) wp_insert_user([
        'user_login' => 'demo_' . wp_generate_password(8, false),
        'user_email' => wp_generate_password(8, false) . '@subalcatel.test',
        'user_pass'  => wp_generate_password(),
        'first_name' => $firstName,
        'role'       => $role,
    ]);
};

// Un adhérent ordinaire, sans aucun rôle du bureau : c'est précisément le cas
// que le registre doit couvrir.
$member = $makeUser(Roles::MEMBER, 'Solenn');
OfficePosition::set(OfficePosition::TRESORIER, $member);

$html = (static function (): string {
    ob_start();
    SettingsScreen::renderOfficePositions();

    return (string) ob_get_clean();
})();

echo "\n--- Rendu de l’onglet ---\n";

$check('Les quatre fonctions apparaissent',
    str_contains($html, 'Président') && str_contains($html, 'Trésorier')
    && str_contains($html, 'Secrétaire') && str_contains($html, 'Webmaster'));

$check('Un simple adhérent figure parmi les candidats — pas seulement le bureau',
    str_contains($html, esc_html(get_userdata($member)->display_name)));

$check('Sa désignation comme trésorier est présélectionnée', preg_match(
    '/value="' . $member . '"[^>]*selected/',
    $html
) === 1);

$check('Le formulaire pointe vers le bon traitement',
    str_contains($html, 'value="sub_office_positions_save"'));

// --- Nettoyage -----------------------------------------------------------------
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($member);
delete_option(OfficePosition::OPTION);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
