<?php
/**
 * Test de fumée de la file d'attente des envois groupés.
 *
 *   docker exec sub_demo_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-mail-queue.php
 *
 * Ce qui doit tenir :
 *
 *  - un envoi d'un bloc qui ne tient pas aujourd'hui attend **en entier**, puis
 *    part en entier — jamais la moitié du club servie avant l'autre ;
 *  - sauf s'il ne tiendra jamais en une journée : il part alors par tranches,
 *    plutôt que de rester bloqué pour toujours ;
 *  - un envoi fractionnable envoie ce que le jour permet, et garde le reste ;
 *  - un envoi lié à une sortie est abandonné quand la sortie commence ;
 *  - l'annulation d'une sortie n'est plus refusée faute de quota ;
 *  - le bureau peut retirer un envoi de la file.
 *
 * Les envois sont interceptés — rien ne sort réellement.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Events\EventTypeSeeder;
use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Identity\Roles;
use Subalcatel\Club\Notifications\EmailTemplates;
use Subalcatel\Club\Notifications\MailQueue;
use Subalcatel\Club\Notifications\Mailer;
use Subalcatel\Club\Notifications\SendQuota;

global $wpdb;

DiveLevels::seed();
EventTypeSeeder::run();
EmailTemplates::seed();

$failures = 0;
$check    = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-60s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$GLOBALS['sub_sent_mails'] = [];
add_filter('pre_wp_mail', static function ($null, array $atts) {
    $GLOBALS['sub_sent_mails'][] = $atts;
    SendQuota::add(1);

    return true;
}, 10, 2);

$sentCount = static fn (): int => count($GLOBALS['sub_sent_mails']);

$makeUser = static function (string $name): int {
    $id = (int) wp_insert_user([
        'user_login'   => 'queue_' . wp_generate_password(8, false),
        'user_email'   => 'queue-' . strtolower(wp_generate_password(8, false)) . '@subalcatel.test',
        'user_pass'    => wp_generate_password(),
        'display_name' => $name,
        'first_name'   => $name,
        'role'         => Roles::MEMBER,
    ]);

    $term = get_term_by('slug', 'p3', DiveLevels::TAXONOMY);
    update_user_meta($id, 'sub_dive_level_id', $term->term_id);

    return $id;
};

$saved = [
    SendQuota::OPTION_SETTINGS     => get_option(SendQuota::OPTION_SETTINGS, null),
    SendQuota::OPTION_COUNTER      => get_option(SendQuota::OPTION_COUNTER, null),
    MailQueue::OPTION              => get_option(MailQueue::OPTION, null),
    MailQueue::OPTION_DAILY_RAN    => get_option(MailQueue::OPTION_DAILY_RAN, null),
];
delete_option(MailQueue::OPTION);

$setSent = static function (int $count): void {
    update_option(SendQuota::OPTION_COUNTER, ['day' => current_time('Y-m-d'), 'count' => $count], false);
};

$users     = array_map($makeUser, ['Ana', 'Bertrand', 'Chloé', 'David', 'Élise', 'Fanch', 'Gwen']);
$entityId  = 800000 + random_int(1, 99999);
$context   = ['entity_type' => 'event', 'entity_id' => $entityId];
$tomorrow  = (new DateTimeImmutable(current_time('mysql')))->modify('+1 day')->format('Y-m-d H:i:s');

SendQuota::save(300, 50);   // 250 envois groupés par jour

// =============================================================================
// 1. D'un bloc : attend en entier, part en entier
// =============================================================================
echo "\n--- D'un bloc ---\n";

$three = array_slice($users, 0, 3);

$setSent(248);  // 2 places pour 3 destinataires
$GLOBALS['sub_sent_mails'] = [];
$outcome = Mailer::toUsersOrQueue(EmailTemplates::EVENT_ANNOUNCEMENT, $three, ['titre' => 'Sortie file'], $context, [], [
    'atomic' => true, 'expires_at' => $tomorrow, 'label' => 'Annonce test',
]);

$check('Rien ne part tout de suite', $outcome === ['sent' => 0, 'queued' => 3] && $sentCount() === 0);
$check('Le lot est en file', MailQueue::hasJobFor(EmailTemplates::EVENT_ANNOUNCEMENT, 'event', $entityId));

$setSent(248);
$check('Toujours 2 places : le lot attend encore', MailQueue::process(true) === 0 && count(MailQueue::jobs()) === 1);

$setSent(0);    // le lendemain
$check('Le lendemain, il part en entier', MailQueue::process(true) === 3 && $sentCount() === 3);
$check('… et quitte la file', MailQueue::jobs() === []);

// =============================================================================
// 2. Plus gros qu'une journée entière : par tranches malgré tout
// =============================================================================
echo "\n--- Plus gros qu'une journée ---\n";

SendQuota::save(10, 5);     // 5 envois groupés par jour, pour 7 destinataires
$setSent(0);
$GLOBALS['sub_sent_mails'] = [];

$outcome = Mailer::toUsersOrQueue(EmailTemplates::EVENT_ANNOUNCEMENT, $users, ['titre' => 'Grosse annonce'], $context, [], [
    'atomic' => true, 'label' => 'Trop gros',
]);
$check('7 destinataires, 5 par jour : mis en file', $outcome['queued'] === 7);

$check('Il ne restera pas bloqué : 5 partent', MailQueue::process(true) === 5);
$setSent(0);
$check('Les 2 derniers le lendemain', MailQueue::process(true) === 2 && MailQueue::jobs() === []);
$check('Chacun une seule fois', $sentCount() === 7
    && count(array_unique(array_map(static fn (array $m): string => (string) (is_array($m['to']) ? $m['to'][0] : $m['to']), $GLOBALS['sub_sent_mails']))) === 7);

// =============================================================================
// 3. Fractionnable : ce que le jour permet, le reste ensuite
// =============================================================================
echo "\n--- Fractionnable ---\n";

SendQuota::save(300, 50);
$setSent(248);
$GLOBALS['sub_sent_mails'] = [];

$outcome = Mailer::toUsersOrQueue(EmailTemplates::EVENT_ANNOUNCEMENT, $three, ['titre' => 'Par tranches'], $context, [], [
    'atomic' => false,
]);
$check('2 partent tout de suite, 1 attend', $outcome === ['sent' => 2, 'queued' => 1] && $sentCount() === 2);

$setSent(0);
$check('Le dernier part au passage suivant', MailQueue::process(true) === 1 && MailQueue::jobs() === []);

// =============================================================================
// 4. Expiration : une sortie commencée n'est plus annoncée
// =============================================================================
echo "\n--- Expiration ---\n";

$setSent(250);
$GLOBALS['sub_sent_mails'] = [];
Mailer::toUsersOrQueue(EmailTemplates::EVENT_ANNOUNCEMENT, $three, ['titre' => 'Trop tard'], $context, [], [
    'atomic' => true, 'expires_at' => (new DateTimeImmutable(current_time('mysql')))->modify('-1 minute')->format('Y-m-d H:i:s'),
]);

$setSent(0);
$check('Sortie commencée : rien ne part', MailQueue::process(true) === 0 && $sentCount() === 0);
$check('… et le lot est abandonné', MailQueue::jobs() === []);
$check('… avec une trace au journal d’audit', (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sub_audit_log WHERE action = 'mail_queue.expired' AND entity_id = %d",
    $entityId
)) === 1);

// =============================================================================
// 5. Retrait par le bureau
// =============================================================================
echo "\n--- Retrait ---\n";

$setSent(250);
$jobId = MailQueue::enqueue(EmailTemplates::EVENT_ANNOUNCEMENT, $three, [], $context, [], ['label' => 'À retirer']);
$check('Retirer un lot', MailQueue::cancel($jobId) && MailQueue::jobs() === []);
$check('Le retirer deux fois ne fait rien', !MailQueue::cancel($jobId));

// =============================================================================
// 6. Priorité à l'entretien quotidien
// =============================================================================
echo "\n--- Priorité aux rappels ---\n";

update_option(MailQueue::OPTION_DAILY_RAN, current_time('Y-m-d'), false);
$check('Entretien du jour passé : la file peut partir', MailQueue::mayRunNow());

update_option(MailQueue::OPTION_DAILY_RAN, '2000-01-01', false);
$check('Sinon, seulement à partir de midi',
    MailQueue::mayRunNow() === ((int) current_time('G') >= 12), 'il est ' . current_time('H:i'));

// =============================================================================
// 7. Annulation d'une sortie : jamais refusée faute de quota
// =============================================================================
echo "\n--- Annulation d'une sortie ---\n";

$dp = $makeUser('Sophie');
foreach (array_merge([$dp], $three) as $id) {
    sub_test_make_compliant($id);
}

$service = new EventService();
$eventId = $service->create('plongee-exploration', [
    'title'     => 'Sortie annulée au plafond',
    'starts_at' => (new DateTimeImmutable(current_time('mysql')))->modify('+3 days')->format('Y-m-d H:i:s'),
    'capacity'  => 10,
], $dp);

foreach ($three as $id) {
    $service->register($eventId, $id);
}

$setSent(249);
$GLOBALS['sub_sent_mails'] = [];
$result = $service->callOff($eventId, 'Tempête annoncée.', $dp);

$check('La sortie est annulée malgré le plafond', $service->find($eventId)['status'] === 'cancelled');
$check('Les messages attendent, d’un bloc', $result['queued'] === 3 && $result['sent'] === 0 && $sentCount() === 0,
    wp_json_encode($result));

$job = array_values(MailQueue::jobs())[0] ?? [];
$check('Ils expirent au début de la sortie',
    ($job['expires_at'] ?? '') === (string) $service->find($eventId)['starts_at']);

$setSent(0);
$check('Ils partent au passage suivant', MailQueue::process(true) === 3);

// =============================================================================
// Ménage
// =============================================================================
$wpdb->delete("{$wpdb->prefix}sub_event_registrations", ['event_id' => $eventId]);
$wpdb->delete("{$wpdb->prefix}sub_events", ['id' => $eventId]);
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->prefix}sub_notification_log WHERE entity_type = 'event' AND entity_id IN (%d, %d)",
    $entityId,
    $eventId
));

require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_merge($users, [$dp]) as $id) {
    sub_test_clean_documents($id);
    $wpdb->delete("{$wpdb->prefix}sub_notification_log", ['recipient_id' => $id]);
    wp_delete_user($id);
}

foreach ($saved as $option => $value) {
    $value === null ? delete_option($option) : update_option($option, $value, false);
}

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
