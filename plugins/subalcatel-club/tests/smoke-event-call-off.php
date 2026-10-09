<?php
/**
 * Test de fumée — annuler une sortie et prévenir ses inscrits.
 *
 *   docker exec sub_demo_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-event-call-off.php
 *
 * Jusqu'ici, la seule façon de faire disparaître une sortie était de la
 * supprimer : les inscriptions partaient avec elle, et personne n'était
 * prévenu. Ce qui doit tenir :
 *
 *   - tous les inscrits — liste d'attente comprise — reçoivent le motif, et
 *     ceux qui s'étaient déjà désinscrits ne reçoivent rien ;
 *   - la sortie reste en base, fermée aux inscriptions et sortie de l'agenda ;
 *   - seuls l'organisateur et le bureau peuvent l'annuler, une fois, avant
 *     le départ, et jamais sans motif.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Events\EventTypeSeeder;
use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Identity\Roles;
use Subalcatel\Club\Notifications\EmailTemplates;

global $wpdb;

DiveLevels::seed();
EventTypeSeeder::run();
EmailTemplates::seed();

$mails = [];
add_filter('pre_wp_mail', static function ($null, array $atts) use (&$mails) {
    $mails[] = $atts;
    return true;
}, 10, 2);

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-58s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$member = static function (string $name, string $level): int {
    $id = wp_insert_user([
        'user_login'   => 'demo_' . wp_generate_password(8, false),
        'user_email'   => strtolower(wp_generate_password(8, false)) . '@subalcatel.test',
        'user_pass'    => wp_generate_password(),
        'display_name' => $name,
        'role'         => Roles::MEMBER,
    ]);

    $term = get_term_by('slug', $level, DiveLevels::TAXONOMY);
    update_user_meta($id, 'sub_dive_level_id', $term->term_id);
    sub_test_make_compliant($id);

    return $id;
};

$fails = static function (callable $fn): string {
    try {
        $fn();
    } catch (\RuntimeException $e) {
        return $e->getMessage();
    }

    return '';
};

$mailsTo = static function (int $userId) use (&$mails): array {
    $email = get_userdata($userId)->user_email;

    return array_values(array_filter($mails, static fn (array $m): bool => in_array($email, (array) $m['to'], true)
        || $m['to'] === $email));
};

$dp      = $member('Sophie Cariou', 'p5');
$lea     = $member('Léa Vidal', 'p3');
$yann    = $member('Yann Le Guen', 'p3');      // liste d'attente
$parti   = $member('Anne Partie', 'p3');       // désinscrite avant l'annulation
$curieux = $member('Marc Curieux', 'p3');      // ni organisateur, ni bureau

$service = new EventService();

$eventId = $service->create('plongee-exploration', [
    'title'     => 'Sortie annulée — contrôle',
    'starts_at' => gmdate('Y-m-d H:i:s', time() + 7 * 86400),
    'location'  => 'Les Sept-Îles',
    'capacity'  => 1,
], $dp);

$service->register($eventId, $lea);
$service->register($eventId, $parti); // en attente, puis se désinscrit
$service->cancel($eventId, $parti);
$service->register($eventId, $yann);  // en attente

$check('Mise en place : une confirmée, un en attente',
    $service->registrationStatus($eventId, $lea) === 'confirmed'
    && $service->registrationStatus($eventId, $yann) === 'waiting');

// --- Qui peut annuler, et à quelles conditions -------------------------------
echo "\n--- Garde-fous ---\n";

$mails = [];

$check('Un membre quelconque ne peut pas annuler',
    $fails(fn () => $service->callOff($eventId, 'Météo', $curieux)) !== '');
$check('Pas d’annulation sans motif',
    $fails(fn () => $service->callOff($eventId, "  \n ", $dp)) !== '');
$check('Ces refus n’ont rien envoyé ni rien changé',
    $mails === [] && $service->find($eventId)['status'] === 'published');

$past = $service->create('plongee-exploration', [
    'title'     => 'Sortie déjà partie',
    'starts_at' => gmdate('Y-m-d H:i:s', time() + 3600),
], $dp);
$wpdb->update("{$wpdb->prefix}sub_events", ['starts_at' => current_time('mysql')], ['id' => $past]);
sleep(1);

$check('Une sortie commencée ne s’annule plus',
    $fails(fn () => $service->callOff($past, 'Trop tard', $dp)) !== '');

// --- L'annulation -------------------------------------------------------------
echo "\n--- Annulation par l’organisateur ---\n";

$mails  = [];
$result = $service->callOff($eventId, 'Houle annoncée à 2,5 m.', $dp);
$event  = $service->find($eventId);

$check('Deux destinataires, deux envois', $result === ['recipients' => 2, 'sent' => 2, 'queued' => 0],
    wp_json_encode($result));
$check('La confirmée est prévenue', count($mailsTo($lea)) === 1);
$check('La liste d’attente aussi', count($mailsTo($yann)) === 1);
$check('La personne déjà désinscrite ne reçoit rien', $mailsTo($parti) === []);

$body = (string) ($mailsTo($lea)[0]['message'] ?? '');
$subj = (string) ($mailsTo($lea)[0]['subject'] ?? '');
$check('Le courriel donne le motif', str_contains($body, 'Houle annoncée à 2,5 m.'));
$check('Et nomme la sortie dans l’objet', str_contains($subj, 'Sortie annulée — contrôle'), $subj);

$check('La sortie reste en base, marquée annulée',
    $event !== null && $event['status'] === 'cancelled' && !empty($event['cancelled_at']));
$check('Avec son motif', $event['cancel_reason'] === 'Houle annoncée à 2,5 m.');
$check('Les inscriptions sont levées',
    $service->registrationStatus($eventId, $lea) === null
    && $service->registrationStatus($eventId, $yann) === null);

$notified = array_column($service->calledOffParticipants($eventId), 'display_name');
sort($notified);
$check('La liste des prévenus distingue les désinscrits d’avant',
    $notified === ['Léa Vidal', 'Yann Le Guen'], implode(', ', $notified));

$check('Plus personne ne peut s’y inscrire',
    !$service->checkEligibility($eventId, $curieux)->allowed);
$check('Elle sort de l’agenda',
    !in_array($eventId, array_map('intval', array_column($service->upcoming(50, $curieux), 'id')), true));
$check('Une seconde annulation est refusée',
    $fails(fn () => $service->callOff($eventId, 'Encore', $dp)) !== '');

$audit = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sub_audit_log WHERE action = 'event.called_off' AND entity_id = %d",
    $eventId
));
$check('L’annulation est au journal d’audit', $audit === 1);

// --- Le bureau peut annuler la sortie d'un autre -------------------------------
echo "\n--- Annulation par le bureau ---\n";

$autre = $service->create('plongee-exploration', [
    'title'     => 'Sortie sans inscrit',
    'starts_at' => gmdate('Y-m-d H:i:s', time() + 9 * 86400),
], $dp);

get_userdata($curieux)->add_cap('sub_communicate_event_participants');
$mails  = [];
$result = $service->callOff($autre, 'Bateau en panne.', $curieux);

$check('Le droit du bureau suffit, même sans être organisateur',
    $service->find($autre)['status'] === 'cancelled');
$check('Sans inscrit, aucun message ne part', $result === ['recipients' => 0, 'sent' => 0, 'queued' => 0] && $mails === []);

// --- Nettoyage ---------------------------------------------------------------

require_once ABSPATH . 'wp-admin/includes/user.php';

foreach ([$eventId, $past, $autre] as $id) {
    $wpdb->delete("{$wpdb->prefix}sub_event_registrations", ['event_id' => $id]);
    $wpdb->delete("{$wpdb->prefix}sub_events", ['id' => $id]);
}

foreach ([$dp, $lea, $yann, $parti, $curieux] as $id) {
    sub_test_clean_documents($id);
    wp_delete_user($id);
}

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
