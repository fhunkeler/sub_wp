<?php
/**
 * Test de fumée du journal des connexions du bureau.
 *
 *   docker exec sub_demo_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-login-journal.php
 *
 * Ce qui doit tenir :
 *
 *  - seuls les comptes à droits sensibles sont suivis ;
 *  - leur titulaire est prévenu quand son mot de passe sert depuis un appareil
 *    jamais vu — et seulement dans ce cas : ni à la première connexion, ni
 *    quand seule l'adresse de la box a changé ;
 *  - les échecs visant un compte se comptent, par identifiant ou par adresse ;
 *  - les traces de connexion disparaissent au bout de douze mois, et elles
 *    seules.
 *
 * Les envois sont interceptés — rien ne sort réellement.
 */

use Subalcatel\Club\Admin\LoginJournalScreen;
use Subalcatel\Club\Identity\Roles;
use Subalcatel\Club\Notifications\EmailTemplates;
use Subalcatel\Club\Support\LoginJournal;

global $wpdb;

EmailTemplates::seed();

$failures = 0;
$check    = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-60s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$mails = [];
add_filter('pre_wp_mail', static function ($null, array $atts) use (&$mails) {
    $mails[] = $atts;

    return true;
}, 10, 2);

$makeUser = static function (string $role): WP_User {
    $login = 'journal_' . strtolower(wp_generate_password(8, false));
    $id    = wp_insert_user([
        'user_login' => $login,
        'user_email' => $login . '@subalcatel.test',
        'user_pass'  => wp_generate_password(),
        'first_name' => 'Gwen',
        'role'       => $role,
    ]);

    return get_userdata((int) $id);
};

$firefox = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0';
$iphone  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
$linux   = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

$login = static function (WP_User $user, string $ip, string $agent): void {
    $_SERVER['REMOTE_ADDR']     = $ip;
    $_SERVER['HTTP_USER_AGENT'] = $agent;
    do_action('wp_login', $user->user_login, $user);
};

$alertsTo = static function (WP_User $user) use (&$mails): array {
    return array_values(array_filter($mails, static fn (array $m): bool
        => in_array($user->user_email, (array) $m['to'], true) || $m['to'] === $user->user_email));
};

$office = $makeUser(Roles::OFFICE);
$member = $makeUser(Roles::MEMBER);

// --- Qui est suivi -----------------------------------------------------------------
echo "\n--- Comptes suivis ---\n";

$followed = array_map(static fn (array $row): int => $row['user']->ID, LoginJournal::accounts());
$check('Un compte du bureau est suivi', in_array($office->ID, $followed, true));
$check('Un simple membre ne l’est pas', !in_array($member->ID, $followed, true));

// --- L'alerte ---------------------------------------------------------------------
echo "\n--- Nouvel appareil ---\n";

$login($office, '203.0.113.10', $firefox);
$check('Première connexion : pas d’alerte', $alertsTo($office) === []);

$login($office, '203.0.113.10', $firefox);
$check('Même appareil : pas d’alerte', $alertsTo($office) === []);

$login($office, '198.51.100.7', $firefox);
$check('Nouvelle adresse, même navigateur : pas d’alerte', $alertsTo($office) === [],
    'la box a changé d’adresse');

$login($office, '192.0.2.44', $iphone);
$alerts = $alertsTo($office);
$check('Adresse et appareil inconnus : alerte', count($alerts) === 1);
$check('… qui donne l’adresse IP', str_contains((string) ($alerts[0]['message'] ?? ''), '192.0.2.44'));
$check('… et l’appareil, lisible', str_contains((string) ($alerts[0]['message'] ?? ''), 'Safari · iOS'));
$check('… et l’inscrit au journal d’audit', (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sub_audit_log WHERE action = 'auth.new_device' AND entity_id = %d",
    $office->ID
)) === 1);

$login($office, '192.0.2.44', $iphone);
$check('Le même appareil ensuite : plus d’alerte', count($alertsTo($office)) === 1);

$login($member, '203.0.113.10', $firefox);
$login($member, '192.0.2.99', $linux);
$check('Un membre n’est jamais alerté', $alertsTo($member) === []);

// --- Dernière connexion et échecs ---------------------------------------------------
echo "\n--- Lecture ---\n";

$last = LoginJournal::lastLogin($office->ID);
$check('Dernière connexion retrouvée', ($last['ip'] ?? '') === '192.0.2.44' && ($last['device'] ?? '') === 'Safari · iOS');

do_action('wp_login_failed', $office->user_login);
do_action('wp_login_failed', $office->user_email);
do_action('wp_login_failed', 'quelqu_un_d_autre');
$check('Échecs comptés par identifiant et par adresse', LoginJournal::failuresFor($office, 30) === 2);

$recent    = LoginJournal::recent(50);
$forOffice = array_filter($recent, static fn (array $e): bool => $e['user']?->ID === $office->ID);
$check('Les connexions récentes du compte sont listées',
    count(array_filter($forOffice, static fn (array $e): bool => $e['success'])) === 5);
$check('… échecs compris', count(array_filter($forOffice, static fn (array $e): bool => !$e['success'])) === 2);
$check('Rien des membres', array_filter($recent, static fn (array $e): bool => $e['user']?->ID === $member->ID) === []);

// --- Appareils --------------------------------------------------------------------
$check('Firefox sous Windows', LoginJournal::device($firefox) === 'Firefox · Windows');
$check('Chrome sous Linux', LoginJournal::device($linux) === 'Chrome · Linux');
$check('Chaîne vide', LoginJournal::device('') === 'inconnu');

// --- Conservation -------------------------------------------------------------------
echo "\n--- Conservation ---\n";

$wpdb->insert("{$wpdb->prefix}sub_audit_log", [
    'user_id' => $office->ID, 'action' => 'auth.login', 'entity_type' => 'auth', 'entity_id' => $office->ID,
    'ip_address' => '203.0.113.200', 'created_at' => gmdate('Y-m-d H:i:s', strtotime('-13 months')),
]);
$old = (int) $wpdb->insert_id;
$wpdb->insert("{$wpdb->prefix}sub_audit_log", [
    'user_id' => $office->ID, 'action' => 'membership.payment_recorded', 'entity_type' => 'application',
    'created_at' => gmdate('Y-m-d H:i:s', strtotime('-13 months')),
]);
$keep = (int) $wpdb->insert_id;

$check('La purge efface la connexion vieille de 13 mois', LoginJournal::purge() >= 1
    && $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sub_audit_log WHERE id = %d", $old)) === null);
$check('… mais pas une pièce de trésorerie du même âge',
    $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sub_audit_log WHERE id = %d", $keep)) !== null);
$check('… ni les connexions récentes', LoginJournal::lastLogin($office->ID) !== null);
$check('La purge est branchée sur l’entretien quotidien',
    has_action('subalcatel_daily', [LoginJournal::class, 'purge']) !== false);

// --- L'écran ---------------------------------------------------------------------
wp_set_current_user(1);
ob_start();
LoginJournalScreen::renderTab();
$html = (string) ob_get_clean();
$check('L’écran liste le compte et son appareil',
    str_contains($html, $office->user_login) && str_contains($html, 'Safari · iOS'));

// --- Ménage ----------------------------------------------------------------------
$wpdb->delete("{$wpdb->prefix}sub_audit_log", ['id' => $keep]);
foreach ([$office, $member] as $user) {
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}sub_audit_log WHERE action LIKE 'auth.%%' AND (entity_id = %d
           OR JSON_UNQUOTE(JSON_EXTRACT(details, '$.identifiant')) IN (%s, %s))",
        $user->ID,
        $user->user_login,
        $user->user_email
    ));
    $wpdb->delete("{$wpdb->prefix}sub_notification_log", ['recipient_id' => $user->ID]);
}
$wpdb->query("DELETE FROM {$wpdb->prefix}sub_audit_log WHERE action = 'auth.login_failed'
              AND JSON_UNQUOTE(JSON_EXTRACT(details, '$.identifiant')) = 'quelqu_un_d_autre'");

require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($office->ID);
wp_delete_user($member->ID);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
