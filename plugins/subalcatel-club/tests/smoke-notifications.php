<?php
/**
 * Test de fumée des notifications.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-notifications.php
 *
 * Ce qui compte ici : le bon message part au bon moment, une seule fois, et
 * laisse une trace. Les envois sont interceptés — rien ne sort réellement.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Events\EventTypeSeeder;
use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Membership\ApplicationService;
use Subalcatel\Club\Membership\DemoSeeder;
use Subalcatel\Club\Notifications\DailyDigest;
use Subalcatel\Club\Notifications\EmailTemplates;
use Subalcatel\Club\Notifications\Mailer;

EmailTemplates::seed();
EventTypeSeeder::run();

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-54s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

// Interception : on capture ce qui serait envoyé, sans rien émettre.
$GLOBALS['sub_sent_mails'] = [];
add_filter('pre_wp_mail', static function ($null, array $atts) {
    $GLOBALS['sub_sent_mails'][] = $atts;

    return true;
}, 10, 2);

$lastMail = static function (): ?array {
    $mails = $GLOBALS['sub_sent_mails'];

    return $mails === [] ? null : end($mails);
};

$makeUser = static function (string $role, string $firstName = 'Camille'): int {
    $id = wp_insert_user([
        'user_login' => 'demo_' . wp_generate_password(8, false),
        'user_email' => wp_generate_password(8, false) . '@subalcatel.test',
        'user_pass'  => wp_generate_password(),
        'first_name' => $firstName,
        'role'       => $role,
    ]);

    return is_wp_error($id) ? 0 : $id;
};

// --- Rendu des modèles ---------------------------------------------------------
echo "\n--- Modèles ---\n";

// Compté depuis la source : un nombre écrit en dur ici casse le test à chaque
// modèle ajouté, sans rien apprendre sur ce qui compte — que tous soient posés.
$expected = count(EmailTemplates::defaults());

$check(
    'Tous les modèles sont installés',
    count(EmailTemplates::all()) === $expected,
    count(EmailTemplates::all()) . ' sur ' . $expected
);

$missing = array_diff(
    array_column(EmailTemplates::defaults(), 'code'),
    array_column(EmailTemplates::all(), 'code')
);

$check('Aucun modèle manquant', $missing === [], implode(', ', $missing));

$rendered = Mailer::render('Bonjour {prenom}, votre {document}.', ['prenom' => 'Camille', 'document' => 'certificat']);
$check('Variables remplacées', $rendered === 'Bonjour Camille, votre certificat.', $rendered);

$unknown = Mailer::render('Montant : {montant}.', []);
$check('Variable inconnue laissée visible', str_contains($unknown, '{montant}'), $unknown);

// --- Adhésion ------------------------------------------------------------------
echo "\n--- Cycle d’adhésion ---\n";

$campaignId = DemoSeeder::run();
$member     = $makeUser('sub_member');
$treasurer  = $makeUser('sub_office', 'Jean');
$service    = new ApplicationService();

$applicationId = $service->submit($member, $campaignId, 'plongee', [
    'origine_adhesion'       => 'exterieur',
    'jeune'                  => 'non',
    'assurance_individuelle' => 'aucune',
    'niveau_prepare'         => 'aucun',
]);

$mail = $lastMail();
$check('Accusé de réception envoyé', $mail !== null && str_contains($mail['subject'], 'dossier d’adhésion'), $mail['subject'] ?? '');
$check('Montant présent dans le corps', $mail !== null && str_contains($mail['message'], '210,00 €'));
$check('Prénom personnalisé', $mail !== null && str_contains($mail['message'], 'Camille'));

$service->recordPayment($applicationId, 210.00, 'cheque', null, $treasurer);
$check('Confirmation de paiement envoyée', str_contains($lastMail()['subject'] ?? '', 'Paiement reçu'));

$service->validateSecretariat($applicationId, $treasurer);
$mail = $lastMail();
$check('Confirmation d’activation envoyée', str_contains($mail['subject'] ?? '', 'adhésion est active'));
$check('Date de fin annoncée', str_contains($mail['message'] ?? '', '31 décembre 2027'), 'échéance lisible');

// --- Événements -----------------------------------------------------------------
echo "\n--- Événements ---\n";

$dp = $makeUser('sub_office', 'Sophie');
get_userdata($dp)->add_cap('sub_create_exploration_event');
get_userdata($dp)->add_cap('sub_communicate_event_participants');
update_user_meta($dp, 'sub_dive_level_id', get_term_by('slug', 'p5', DiveLevels::TAXONOMY)->term_id);

$events  = new EventService();
$eventId = $events->create('plongee-exploration', [
    'title'     => 'Sortie test notifications',
    'location'  => 'Pors Kamor',
    'starts_at' => gmdate('Y-m-d H:i:s', time() + 10 * 86400),
    'capacity'  => 1,
], $dp);

sub_test_make_compliant($member);
update_user_meta($member, 'sub_dive_level_id', get_term_by('slug', 'p3', DiveLevels::TAXONOMY)->term_id);

$events->register($eventId, $member);
$mail = $lastMail();
$check('Inscription confirmée par courriel', str_contains($mail['subject'] ?? '', 'Inscription confirmée'));
$check('Lieu rappelé', str_contains($mail['message'] ?? '', 'Pors Kamor'));

$second = $makeUser('sub_member', 'Paul');
sub_test_make_compliant($second);
update_user_meta($second, 'sub_dive_level_id', get_term_by('slug', 'p3', DiveLevels::TAXONOMY)->term_id);

$events->register($eventId, $second);
$check('Liste d’attente annoncée', str_contains($lastMail()['subject'] ?? '', 'Liste d’attente'));

$events->cancel($eventId, $member);
$subjects = array_column($GLOBALS['sub_sent_mails'], 'subject');
$check('Promotion annoncée au suivant', in_array(
    array_values(array_filter($subjects, static fn ($s) => str_contains($s, 'place s’est libérée')))[0] ?? '',
    $subjects,
    true
));

// --- Message aux inscrits ---------------------------------------------------------
echo "\n--- Message de l’organisateur ---\n";

$before = count($GLOBALS['sub_sent_mails']);
$result = $events->messageParticipants($eventId, 'Rendez-vous 8h', 'Départ du parking à 8h précises.', $dp);

$check('Message envoyé aux inscrits', $result['sent'] >= 1,
    "{$result['sent']} envoi(s) sur {$result['recipients']} destinataire(s)");
$check('Destinataires et envois distingués', $result['recipients'] >= $result['sent']);
$mail = $lastMail();
$check('Objet repris', str_contains($mail['subject'] ?? '', 'Rendez-vous 8h'));
$check('Expéditeur nommé', str_contains($mail['message'] ?? '', 'Sophie'));

try {
    $events->messageParticipants($eventId, '', '', $dp);
    $check('Message vide refusé', false);
} catch (RuntimeException $e) {
    $check('Message vide refusé', true, $e->getMessage());
}

$intruder = $makeUser('sub_member');
try {
    $events->messageParticipants($eventId, 'Coucou', 'Message pirate', $intruder);
    $check('Un membre ne peut pas écrire aux inscrits', false);
} catch (RuntimeException $e) {
    $check('Un membre ne peut pas écrire aux inscrits', true, $e->getMessage());
}

// --- Annonce d’une sortie ---------------------------------------------------------
echo "\n--- Annonce aux membres concernés ---\n";

global $wpdb;

// Une sortie réservée aux P3 : c'est le filtre de niveau qu'on veut voir jouer.
$announcedId = $events->create('plongee-exploration', [
    'title'           => 'Sortie test annonce',
    'location'        => 'Trégastel',
    'starts_at'       => gmdate('Y-m-d H:i:s', time() + 20 * 86400),
    'capacity'        => 0,
    'accepted_levels' => ['p3'],
], $dp);

$makeMember = static function (string $levelSlug, string $firstName) use ($makeUser): int {
    $id = $makeUser('sub_member', $firstName);
    sub_test_make_compliant($id);
    update_user_meta($id, 'sub_dive_level_id', get_term_by('slug', $levelSlug, DiveLevels::TAXONOMY)->term_id);

    return $id;
};

$concerned = $makeMember('p3', 'Anne');    // au niveau, non inscrit  → destinataire
$muted     = $makeMember('p3', 'Bruno');   // au niveau, mais a refusé les annonces
$tooLow    = $makeMember('p1', 'Chloé');   // adhérent à jour, niveau insuffisant
$enrolled  = $makeMember('p3', 'Denis');   // au niveau, déjà inscrit

update_user_meta($muted, \Subalcatel\Club\Communication\Subscriptions::META_ANNOUNCEMENTS, 'no');
$events->register($announcedId, $enrolled);

$targets = $events->announcementRecipients($announcedId, EventService::AUDIENCE_ELIGIBLE, $dp);

$check('Le membre au niveau est destinataire', in_array($concerned, $targets, true));
$check('Le refus d’annonces est respecté', !in_array($muted, $targets, true));
$check('Le niveau insuffisant est écarté', !in_array($tooLow, $targets, true));
$check('L’inscrit n’est pas réannoncé', !in_array($enrolled, $targets, true));
$check('L’organisateur ne s’écrit pas à lui-même', !in_array($dp, $targets, true));

$wide = $events->announcementRecipients($announcedId, EventService::AUDIENCE_MEMBERS, $dp);
$check('Le public élargi inclut les autres niveaux', in_array($tooLow, $wide, true));
$check('Le refus vaut aussi pour le public élargi', !in_array($muted, $wide, true));

$check('Rien n’est parti tant qu’on n’a pas demandé', $events->lastAnnouncedAt($announcedId) === null);

// Repère posé avant l'envoi : filtrer sur le titre de la sortie ramasserait
// aussi la confirmation d'inscription de Denis, dont l'objet le contient.
$before = count($GLOBALS['sub_sent_mails']);

$result = $events->announce($announcedId, $dp, EventService::AUDIENCE_ELIGIBLE, 'Covoiturage possible depuis Lannion.');
$check('Annonce envoyée', $result['sent'] === $result['recipients'] && $result['sent'] >= 1,
    "{$result['sent']} envoi(s) sur {$result['recipients']} destinataire(s)");

$announceMails = array_slice($GLOBALS['sub_sent_mails'], $before);
$body = $announceMails[0]['message'] ?? '';

$check('Objet reconnaissable', str_contains($announceMails[0]['subject'] ?? '', 'Nouvelle sortie'));
$check('Lieu et niveau annoncés', str_contains($body, 'Trégastel') && str_contains($body, 'P3'), 'niveau requis lisible');
$check('Mot de l’organisateur repris', str_contains($body, 'Covoiturage possible depuis Lannion.'));
$check('Aucune variable non remplacée', !str_contains($body, '{'), $body === '' ? 'corps vide' : 'corps complet');
$check('Sortie du refus d’annonces', !in_array(
    get_userdata($muted)->user_email,
    array_column($announceMails, 'to'),
    true
));
$check('Date d’annonce mémorisée', $events->lastAnnouncedAt($announcedId) !== null);

try {
    $events->announce($announcedId, $intruder);
    $check('Un membre ordinaire n’annonce pas', false);
} catch (RuntimeException $e) {
    $check('Un membre ordinaire n’annonce pas', true, $e->getMessage());
}

$wpdb->update("{$wpdb->prefix}sub_events",
    ['starts_at' => gmdate('Y-m-d H:i:s', time() - 86400)],
    ['id' => $announcedId]
);

try {
    $events->announce($announcedId, $dp);
    $check('Une sortie passée ne s’annonce pas', false);
} catch (RuntimeException $e) {
    $check('Une sortie passée ne s’annonce pas', true, $e->getMessage());
}

// --- Rappels quotidiens -------------------------------------------------------------
echo "\n--- Rappels automatiques ---\n";

global $wpdb;

// L'adhésion expire dans 30 jours : le rappel de la campagne doit partir.
$in30 = gmdate('Y-m-d', strtotime('+30 days'));
$wpdb->update("{$wpdb->prefix}sub_applications", ['valid_until' => $in30], ['id' => $applicationId]);

$before = count($GLOBALS['sub_sent_mails']);
$result = DailyDigest::run();
$check('Rappel d’adhésion envoyé', $result['membership_reminders'] >= 1, json_encode($result));
$check('Message de renouvellement', str_contains($lastMail()['subject'] ?? '', 'adhésion se termine'));

// Deuxième passage le même jour : rien ne doit repartir.
$before = count($GLOBALS['sub_sent_mails']);
$again  = DailyDigest::run();
$check('Pas de doublon au second passage', count($GLOBALS['sub_sent_mails']) === $before,
    $again['membership_reminders'] . ' envoi(s)');

// --- Journal ------------------------------------------------------------------------
echo "\n--- Journal des envois ---\n";

$logged = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sub_notification_log WHERE recipient_id = %d",
    $member
));
$check('Envois journalisés', $logged >= 4, "{$logged} entrées pour ce membre");

$targeted = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sub_notification_log WHERE channel = 'targeted'"
);
$check('Canal ciblé distingué', $targeted >= 1, "{$targeted} message(s) ciblé(s)");

$traced = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sub_notification_log WHERE sender_id = %d",
    $dp
));
$check('Expéditeur tracé', $traced >= 1, "{$traced} envoi(s) attribués");

// --- Nettoyage ---------------------------------------------------------------------------
foreach ([$eventId, $announcedId] as $id) {
    $wpdb->delete("{$wpdb->prefix}sub_event_registrations", ['event_id' => $id]);
    $wpdb->delete("{$wpdb->prefix}sub_events", ['id' => $id]);
}

require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ([$member, $second, $treasurer, $dp, $intruder, $concerned, $muted, $tooLow, $enrolled] as $id) {
    sub_test_clean_documents($id);
    $wpdb->delete("{$wpdb->prefix}sub_notification_log", ['recipient_id' => $id]);
    wp_delete_user($id);
}
$wpdb->delete("{$wpdb->prefix}sub_applications", ['id' => $applicationId]);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
