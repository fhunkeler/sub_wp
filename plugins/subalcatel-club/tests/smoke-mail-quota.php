<?php
/**
 * Test de fumée : le plafond d'envoi quotidien.
 *
 *   docker exec sub_demo_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-mail-quota.php
 *
 * Le 30/09/2026, une annonce de sortie partie à soixante-quatre membres en deux
 * minutes a fait bloquer pour spam la boîte d'envoi du club. Le site passe
 * depuis par un service transactionnel, dont l'offre gratuite plafonne le
 * nombre de messages par jour. Ce qui doit tenir :
 *
 *  - un envoi groupé qui dépasserait le plafond est refusé **en entier**, avant
 *    le premier message ;
 *  - un rappel automatique en excès est reporté, et repart le lendemain ;
 *  - l'annonce d'ouverture de campagne, déclenchée sans que personne ne puisse
 *    réessayer, est mise en attente puis reprise par l'entretien quotidien ;
 *  - un message individuel part toujours.
 *
 * Les envois sont interceptés — rien ne sort réellement.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Admin\CampaignsScreen;
use Subalcatel\Club\Notifications\DailyDigest;
use Subalcatel\Club\Notifications\EmailTemplates;
use Subalcatel\Club\Notifications\Mailer;
use Subalcatel\Club\Notifications\QuotaExceeded;
use Subalcatel\Club\Notifications\SendQuota;

global $wpdb;

EmailTemplates::seed();

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-60s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$GLOBALS['sub_sent_mails'] = [];
add_filter('pre_wp_mail', static function ($null, array $atts) {
    $GLOBALS['sub_sent_mails'][] = $atts;

    return true;
}, 10, 2);

$mailsTo = static function (string $email): int {
    return count(array_filter(
        $GLOBALS['sub_sent_mails'],
        static fn (array $mail): bool => in_array($email, (array) $mail['to'], true)
            || $mail['to'] === $email
    ));
};

$makeUser = static function (string $firstName): int {
    return (int) wp_insert_user([
        'user_login' => 'quota_' . wp_generate_password(8, false),
        'user_email' => 'quota-' . strtolower(wp_generate_password(8, false)) . '@subalcatel.test',
        'user_pass'  => wp_generate_password(),
        'first_name' => $firstName,
        'role'       => 'sub_member',
    ]);
};

// L'état réel de la démo est rendu à la fin.
$savedSettings = get_option(SendQuota::OPTION_SETTINGS, null);
$savedCounter  = get_option(SendQuota::OPTION_COUNTER, null);
$savedPending  = get_option(CampaignsScreen::OPTION_PENDING_NOTICES, null);
$startedAt     = current_time('mysql');

$setSent = static function (int $count): void {
    update_option(SendQuota::OPTION_COUNTER, ['day' => current_time('Y-m-d'), 'count' => $count], false);
};

// =============================================================================
// 1. Le compteur
// =============================================================================
echo "\n--- Compteur ---\n";

delete_option(SendQuota::OPTION_SETTINGS);
$check('plafond par défaut : 300, dont 50 en réserve',
    SendQuota::settings() === ['limit' => 300, 'reserve' => 50]);

update_option(SendQuota::OPTION_COUNTER, ['day' => '2000-01-01', 'count' => 280], false);
$check('le compteur de la veille ne compte plus', SendQuota::sentToday() === 0);

$setSent(10);
SendQuota::record(['to' => ['a@subalcatel.test'], 'headers' => []]);
SendQuota::record(['to' => ['b@subalcatel.test'], 'headers' => ['Bcc: c@subalcatel.test, d@subalcatel.test']]);
$check('un message compte autant que ses destinataires', SendQuota::sentToday() === 14,
    SendQuota::sentToday() . ' au compteur');

$check('envois groupés restants = plafond − réserve − envoyés',
    SendQuota::bulkRemaining() === 300 - 50 - 14, (string) SendQuota::bulkRemaining());

SendQuota::save(0, 50);
$check('plafond 0 : aucune limite', SendQuota::bulkRemaining() === null && SendQuota::allowsBulk(10000));

// =============================================================================
// 2. Envoi groupé : tout ou rien
// =============================================================================
echo "\n--- Envoi groupé ---\n";

$members = [$makeUser('Ana'), $makeUser('Bertrand'), $makeUser('Chloé')];
$emails  = array_map(static fn (int $id): string => get_userdata($id)->user_email, $members);
$eventId = 900000 + random_int(1, 99999);

SendQuota::save(300, 50);
$setSent(248);   // 2 envois groupés possibles, 3 destinataires

$refused = null;
try {
    Mailer::toUsers(EmailTemplates::EVENT_ANNOUNCEMENT, $members, ['titre' => 'Sortie test'], [
        'entity_type' => 'event',
        'entity_id'   => $eventId,
    ]);
} catch (QuotaExceeded $e) {
    $refused = $e->getMessage();
}

$check('trois destinataires, deux places : refusé', $refused !== null, (string) $refused);
$check('… avant le premier message', array_sum(array_map($mailsTo, $emails)) === 0);
$check('… sans rien écrire au journal', (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sub_notification_log WHERE entity_type = 'event' AND entity_id = %d",
    $eventId
)) === 0);
$check('le refus est une RuntimeException, que les écrans affichent déjà',
    is_subclass_of(QuotaExceeded::class, RuntimeException::class));

$setSent(247);   // 3 places
$sent = Mailer::toUsers(EmailTemplates::EVENT_ANNOUNCEMENT, $members, ['titre' => 'Sortie test'], [
    'entity_type' => 'event',
    'entity_id'   => $eventId,
]);
$check('trois places pour trois destinataires : envoyé', $sent === 3, "{$sent} envoyé(s)");

// =============================================================================
// 3. Message individuel : jamais freiné
// =============================================================================
echo "\n--- Message individuel ---\n";

$setSent(299);
$before = $mailsTo($emails[0]);
Mailer::toUser(EmailTemplates::ACCOUNT_APPROVED, $members[0]);
$check('un message individuel part, même dans la réserve', $mailsTo($emails[0]) === $before + 1);

// =============================================================================
// 4. Rappel automatique : reporté, puis repris
// =============================================================================
echo "\n--- Rappel reporté ---\n";

$campaignId = $wpdb->insert("{$wpdb->prefix}sub_campaigns", [
    'slug'          => 'quota-' . wp_generate_password(6, false),
    'title'         => 'Campagne plafond',
    'status'        => 'draft',
    'reminder_days' => '30',
]) ? (int) $wpdb->insert_id : 0;

$reminded = $members[1];
$date     = '2026-06-01';
$wpdb->insert("{$wpdb->prefix}sub_applications", [
    'user_id'     => $reminded,
    'campaign_id' => $campaignId,
    'status'      => 'active',
    'reference'   => 'QUOTA-' . wp_generate_password(6, false),
    'valid_until' => '2026-06-20',
    'created_at'  => '2025-09-01',
]);

$setSent(250);   // plus aucune place pour les envois groupés
$before = $mailsTo($emails[1]);
$r = DailyDigest::run($date);
$check('plafond atteint : le rappel ne part pas', $mailsTo($emails[1]) === $before);
$check('… il est compté comme reporté', $r['deferred'] >= 1, $r['deferred'] . ' reporté(s)');
$check('… et inscrit « reporté » au journal', (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sub_notification_log
     WHERE recipient_id = %d AND template_code = %s AND status = 'deferred'",
    $reminded,
    EmailTemplates::MEMBERSHIP_EXPIRING
)) === 1);

$setSent(0);     // le lendemain
DailyDigest::run('2026-06-02');
$check('le lendemain, le rappel part', $mailsTo($emails[1]) === $before + 1);
DailyDigest::run('2026-06-03');
$check('… une seule fois', $mailsTo($emails[1]) === $before + 1);

// =============================================================================
// 5. Ouverture de campagne : mise en attente, puis reprise
// =============================================================================
echo "\n--- Annonce d'ouverture de campagne ---\n";

$wpdb->update("{$wpdb->prefix}sub_campaigns", ['status' => 'open'], ['id' => $campaignId]);
delete_option(CampaignsScreen::OPTION_PENDING_NOTICES);

$setSent(250);
$check('pas de place : l\'annonce est mise en attente',
    CampaignsScreen::notifyCampaignOpened($campaignId) === 'postponed');
$check('… et inscrite pour l\'entretien quotidien',
    in_array($campaignId, (array) get_option(CampaignsScreen::OPTION_PENDING_NOTICES, []), true));

$before = $mailsTo($emails[1]);
$setSent(0);
$check('l\'entretien quotidien la reprend', CampaignsScreen::retryPendingNotices() === 1);
$check('… le membre actif la reçoit', $mailsTo($emails[1]) === $before + 1);
$check('… et elle sort de la file', get_option(CampaignsScreen::OPTION_PENDING_NOTICES, null) === null);
$check('une seconde reprise ne renvoie rien', CampaignsScreen::retryPendingNotices() === 0);

// Une campagne refermée entre-temps n'est plus annoncée.
update_option(CampaignsScreen::OPTION_PENDING_NOTICES, [$campaignId], false);
$wpdb->update("{$wpdb->prefix}sub_campaigns", ['status' => 'closed'], ['id' => $campaignId]);
$check('campagne refermée : retirée de la file sans envoi',
    CampaignsScreen::retryPendingNotices() === 0
    && get_option(CampaignsScreen::OPTION_PENDING_NOTICES, null) === null);

// =============================================================================
// Ménage
// =============================================================================
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->prefix}sub_notification_log
     WHERE (entity_type = 'event' AND entity_id = %d) OR (entity_type = 'campaign' AND entity_id = %d)",
    $eventId,
    $campaignId
));
// Les rappels de la démo, reportés le temps de la section 4.
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->prefix}sub_notification_log WHERE status = 'deferred' AND sent_at >= %s",
    $startedAt
));
$wpdb->delete("{$wpdb->prefix}sub_applications", ['campaign_id' => $campaignId]);
$wpdb->delete("{$wpdb->prefix}sub_campaigns", ['id' => $campaignId]);

require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ($members as $id) {
    $wpdb->delete("{$wpdb->prefix}sub_notification_log", ['recipient_id' => $id]);
    wp_delete_user($id);
}

foreach ([
    SendQuota::OPTION_SETTINGS              => $savedSettings,
    SendQuota::OPTION_COUNTER               => $savedCounter,
    CampaignsScreen::OPTION_PENDING_NOTICES => $savedPending,
] as $option => $value) {
    $value === null ? delete_option($option) : update_option($option, $value, false);
}

printf("\n%s\n", $failures === 0 ? 'Tout est au vert.' : $failures . ' échec(s).');
exit($failures === 0 ? 0 : 1);
