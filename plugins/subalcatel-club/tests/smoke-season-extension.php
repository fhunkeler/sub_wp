<?php
/**
 * Test de fumée : la saison 2025-2026 va jusqu'au 31/12/2026.
 *
 *   docker exec sub_demo_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-season-extension.php
 *
 * Le point à prouver. Une adhésion court jusqu'au 31/12 de l'année suivante
 * pour qu'un renouvellement en attente ne coupe rien. La saison 2025-2026,
 * dernière de l'ancien cycle, s'arrêtait le 30/09 : la migration 16 la
 * prolonge, sur la campagne, ses dossiers et le compte — et ne touche à
 * aucune autre date.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Database\Schema;
use Subalcatel\Club\Identity\DerivedCapabilities;
use Subalcatel\Club\Policy\EligibilityPolicy;

global $wpdb;
$p = $wpdb->prefix . 'sub_';

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-56s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$wpdb->insert("{$p}campaigns", [
    'title'       => 'Saison de test 2025-2026',
    'slug'        => 'smoke-saison-2025-2026',
    'opens_on'    => '2025-09-01',
    'closes_on'   => '2026-06-30',
    'valid_from'  => '2025-09-01',
    'valid_until' => '2026-09-30',
    'status'      => 'closed',
]);
$campaignId = (int) $wpdb->insert_id;

$login   = 'smoke_saison_' . wp_generate_password(6, false);
$userId  = (int) wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => "{$login}@example.test"]);
$otherId = (int) wp_insert_user(['user_login' => "{$login}_b", 'user_pass' => wp_generate_password(), 'user_email' => "{$login}_b@example.test"]);

$wpdb->insert("{$p}applications", [
    'reference'   => 'SMOKE-' . $userId,
    'user_id'     => $userId,
    'campaign_id' => $campaignId,
    'plan_id'     => 0,
    'status'      => 'active',
    'valid_from'  => '2025-09-01',
    'valid_until' => '2026-09-30',
]);
$applicationId = (int) $wpdb->insert_id;

update_user_meta($userId, 'sub_membership_valid_until', '2026-09-30');
// Une date posée à la main ne relève pas de la migration.
update_user_meta($otherId, 'sub_membership_valid_until', '2026-10-15');

$policy = new EligibilityPolicy();
$check('avant : adhésion expirée le 01/10', !$policy->hasActiveMembership($userId, '2026-10-01')->allowed);

update_option('subalcatel_club_db_version', 15, false);
Schema::migrate();
DerivedCapabilities::forget();
clean_user_cache($userId);

$campaignUntil    = (string) $wpdb->get_var($wpdb->prepare("SELECT valid_until FROM {$p}campaigns WHERE id = %d", $campaignId));
$applicationUntil = (string) $wpdb->get_var($wpdb->prepare("SELECT valid_until FROM {$p}applications WHERE id = %d", $applicationId));

$check('campagne prolongée au 31/12/2026', $campaignUntil === '2026-12-31', $campaignUntil);
$check('dossier prolongé au 31/12/2026', $applicationUntil === '2026-12-31', $applicationUntil);
$check('compte prolongé au 31/12/2026', get_user_meta($userId, 'sub_membership_valid_until', true) === '2026-12-31');
$check('après : adhésion à jour le 01/10', $policy->hasActiveMembership($userId, '2026-10-01')->allowed);
$check('après : expirée le 01/01/2027', !$policy->hasActiveMembership($userId, '2027-01-01')->allowed);
$check('une autre date n’est pas touchée', get_user_meta($otherId, 'sub_membership_valid_until', true) === '2026-10-15');

// Rejouée depuis la version courante, la migration ne refait rien.
update_user_meta($userId, 'sub_membership_valid_until', '2026-09-30');
Schema::migrate();
clean_user_cache($userId);
$check('ne se rejoue pas une fois passée', get_user_meta($userId, 'sub_membership_valid_until', true) === '2026-09-30');

$wpdb->delete("{$p}applications", ['id' => $applicationId]);
$wpdb->delete("{$p}campaigns", ['id' => $campaignId]);
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($userId);
wp_delete_user($otherId);

echo $failures === 0 ? "\nTout est vert.\n" : "\n{$failures} échec(s).\n";
exit($failures === 0 ? 0 : 1);
