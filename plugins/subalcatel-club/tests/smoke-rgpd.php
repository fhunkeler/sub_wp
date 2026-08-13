<?php
/**
 * Test de fumée du RGPD.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-rgpd.php
 *
 * Deux choses à prouver, opposées l'une à l'autre : que l'export rend bien tout
 * ce que le club détient, et que l'effacement s'arrête là où une obligation
 * commence. Un plugin qui efface trop est aussi fautif qu'un plugin qui refuse.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Documents\DocumentService;
use Subalcatel\Club\Documents\DocumentStorage;
use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Events\EventTypeSeeder;
use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Membership\ApplicationService;
use Subalcatel\Club\Membership\DemoSeeder;
use Subalcatel\Club\Notifications\EmailTemplates;
use Subalcatel\Club\Notifications\Mailer;
use Subalcatel\Club\Privacy\AccountDeletion;
use Subalcatel\Club\Privacy\PersonalData;

global $wpdb;

EventTypeSeeder::run();
EmailTemplates::seed();

$campaignId = DemoSeeder::run();
$failures   = 0;

$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-56s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

add_filter('pre_wp_mail', '__return_true');

$makeMember = static function (string $firstName): int {
    $id = wp_insert_user([
        'user_login' => 'demo_' . wp_generate_password(8, false),
        'user_email' => wp_generate_password(8, false) . '@subalcatel.test',
        'user_pass'  => wp_generate_password(),
        'first_name' => $firstName,
        'role'       => 'sub_member',
    ]);

    update_user_meta($id, 'sub_birth_date', '1988-04-12');
    update_user_meta($id, 'sub_mobile', '06 11 22 33 44');
    update_user_meta($id, 'sub_city', 'Lannion');

    $level = get_term_by('slug', 'p3', DiveLevels::TAXONOMY);
    update_user_meta($id, 'sub_dive_level_id', $level->term_id);

    return $id;
};

$member = $makeMember('Camille');
$email  = get_userdata($member)->user_email;

sub_test_make_compliant($member);

$applications = new ApplicationService();
$applicationId = $applications->submit($member, $campaignId, 'plongee', [
    'origine_adhesion'       => 'exterieur',
    'jeune'                  => 'non',
    'assurance_individuelle' => 'loisir1',
    'niveau_prepare'         => 'aucun',
    'carte_niveau'           => 'non',
    'pret_bloc'              => 'non',
    'pret_detendeur'         => 'non',
    'pret_gilet'             => 'non',
]);

Mailer::toUser(EmailTemplates::DOCUMENT_VALIDATED, $member, ['document' => 'certificat médical']);

// --- Export ------------------------------------------------------------------
echo "\n--- Droit d’accès ---\n";

$export = PersonalData::export($email);
$groups = [];

foreach ($export['data'] as $group) {
    $groups[$group['group_id']][] = $group;
}

$check('Export terminé en une passe', $export['done'] === true, count($export['data']) . ' groupe(s)');
$check('Profil exporté', isset($groups['sub-profile']));
$check('Adhésion exportée', isset($groups['sub-memberships']));
$check('Documents exportés', isset($groups['sub-documents']));
$check('Courriels exportés', isset($groups['sub-messages']));

// Sans JSON_UNESCAPED_UNICODE, les apostrophes typographiques deviennent
// \u2019 et aucune recherche de sous-chaîne ne retrouve ses petits.
$flat = (string) wp_json_encode($export['data'], JSON_UNESCAPED_UNICODE);

$check('Le niveau est en clair, pas en identifiant', str_contains($flat, 'P3'),
    'un export lisible par la personne, pas par la base');
$check('Le numéro de mobile est présent', str_contains($flat, '06 11 22 33 44'));
$check('Aucun contenu de fichier dans l’export', !str_contains($flat, '%PDF'),
    'un export est une liste, pas une archive');
$check('Le document renvoie vers l’espace membre',
    str_contains($flat, 'Téléchargeable depuis votre espace membre'));

$check('Une adresse inconnue ne rend rien', PersonalData::export('personne@nulle-part.test')['data'] === []);

// --- Obligations en cours ----------------------------------------------------
echo "\n--- Ce qui empêche l’effacement ---\n";

$treasurer = wp_insert_user([
    'user_login' => 'demo_' . wp_generate_password(8, false),
    'user_email' => wp_generate_password(8, false) . '@subalcatel.test',
    'user_pass'  => wp_generate_password(),
    'role'       => 'sub_office',
]);

$applications->recordPayment($applicationId, 210.00, 'cheque', current_time('Y-m-d'), $treasurer);
$applications->validateSecretariat($applicationId, $treasurer);

$blockers = AccountDeletion::blockers($member);
$labels   = array_column($blockers, 'label');

$check('Adhésion en cours détectée', in_array('Adhésion en cours', $labels, true),
    count($blockers) . ' obligation(s)');

$events   = new EventService();
$organiser = $makeMember('Yann');
get_userdata($organiser)->add_cap('sub_create_exploration_event');
sub_test_make_compliant($organiser);

$eventId = $events->create('plongee-exploration', [
    'title'           => 'Sortie de contrôle RGPD',
    'starts_at'       => gmdate('Y-m-d H:i:s', time() + 14 * 86400),
    'location'        => 'Trébeurden',
    'capacity'        => 6,
    'accepted_levels' => ['p1', 'p3', 'p5'],
], $organiser);

$events->register($eventId, $member);

$labels = array_column(AccountDeletion::blockers($member), 'label');
$check('Inscription à venir détectée', in_array('Inscription(s) à venir', $labels, true));

// L'effaceur doit refuser aussi : le bureau peut le lancer depuis les écrans
// natifs de WordPress, qui ne connaissent rien de ces obligations.
$refused = PersonalData::erase($email);

$check('Effacement refusé tant qu’une obligation court', $refused['items_removed'] === false);
$check('Refus motivé', $refused['items_retained'] === true && $refused['messages'] !== [],
    $refused['messages'][0] ?? '');

$notice = implode(' ', $refused['messages']);
$check('Le bureau lit une formulation à la troisième personne',
    !str_contains($notice, 'Votre adhésion'),
    'le bureau regarde la situation de quelqu’un d’autre');

// --- Levée des obligations ---------------------------------------------------
echo "\n--- Une fois les obligations soldées ---\n";

$events->cancel($eventId, $member);
$wpdb->update(
    "{$wpdb->prefix}sub_applications",
    ['valid_until' => gmdate('Y-m-d', strtotime('-1 day'))],
    ['id' => $applicationId]
);

$check('Plus aucune obligation', AccountDeletion::blockers($member) === []);

// --- Effacement --------------------------------------------------------------
echo "\n--- Droit à l’effacement ---\n";

$filePath = (string) $wpdb->get_var($wpdb->prepare(
    "SELECT file_path FROM {$wpdb->prefix}sub_member_documents WHERE user_id = %d LIMIT 1",
    $member
));

$check('Le fichier existe avant effacement', DocumentStorage::exists($filePath), $filePath);

$erased = PersonalData::erase($email);

$check('Effacement effectué', $erased['items_removed'] === true);
$check('Le fichier a disparu du stockage', !DocumentStorage::exists($filePath),
    'un certificat médical ne survit pas au départ');
$check('Plus aucun document en base', (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sub_member_documents WHERE user_id = %d",
    $member
)) === 0);
$check('Profil effacé', get_user_meta($member, 'sub_mobile', true) === '');
$check('Ville effacée', get_user_meta($member, 'sub_city', true) === '');

// --- Ce qui reste, et pourquoi -----------------------------------------------
echo "\n--- Ce que le club conserve ---\n";

$check('Conservation signalée', $erased['items_retained'] === true);

$notice = implode(' ', $erased['messages']);
$check('L’adhésion est annoncée comme conservée', str_contains($notice, 'pièces comptables'),
    'obligation légale de dix ans');

$check('L’adhésion est toujours en base', (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sub_applications WHERE user_id = %d",
    $member
)) === 1);

$registration = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}sub_event_registrations WHERE user_id = %d LIMIT 1",
    $member
), ARRAY_A);

$check('L’inscription reste comptée', $registration !== null,
    'l’historique des sorties appartient au club');
$check('Ses réponses individuelles sont effacées',
    $registration !== null && ($registration['details'] === null || $registration['details'] === ''));

// --- Politique de confidentialité --------------------------------------------
echo "\n--- Politique de confidentialité ---\n";

$policy = PersonalData::policyText();

$check('Texte proposé au bureau', $policy !== '', mb_strlen($policy) . ' caractères');
$check('Le certificat médical y est traité', str_contains($policy, 'donnée de santé'));
$check('Les mineurs y sont traités', str_contains($policy, 'représentant légal'));
$check('Les durées de conservation y figurent', str_contains($policy, 'dix ans'));

// --- Branchement sur WordPress -----------------------------------------------
echo "\n--- Outils natifs de WordPress ---\n";

$exporters = apply_filters('wp_privacy_personal_data_exporters', []);
$erasers   = apply_filters('wp_privacy_personal_data_erasers', []);

$check('Exporteur déclaré', isset($exporters['subalcatel-club']));
$check('Effaceur déclaré', isset($erasers['subalcatel-club']));
$check('Exporteur appelable', isset($exporters['subalcatel-club'])
    && is_callable($exporters['subalcatel-club']['callback']));

// --- Qui peut traiter une demande --------------------------------------------
echo "\n--- Droits sur les écrans RGPD ---\n";

$check('Le bureau accède à l’export', user_can($treasurer, 'export_others_personal_data'));
$check('Le bureau accède à l’effacement', user_can($treasurer, 'erase_others_personal_data'));
$check('Sans devenir administrateur', !user_can($treasurer, 'manage_options'),
    'la capacité RGPD n’ouvre pas les réglages du site');
$check('Un simple membre n’y accède pas', !user_can($member, 'export_others_personal_data'));

// --- Nettoyage ---------------------------------------------------------------
require_once ABSPATH . 'wp-admin/includes/user.php';

foreach ([$member, $organiser, $treasurer] as $id) {
    sub_test_clean_documents($id);
    $wpdb->delete("{$wpdb->prefix}sub_notification_log", ['recipient_id' => $id]);
    $wpdb->delete("{$wpdb->prefix}sub_event_registrations", ['user_id' => $id]);
    $wpdb->delete("{$wpdb->prefix}sub_applications", ['user_id' => $id]);
    wp_delete_user($id);
}

$wpdb->delete("{$wpdb->prefix}sub_events", ['id' => $eventId]);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
