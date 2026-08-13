<?php
/**
 * Test de fumée des adhérents mineurs.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-minors.php
 *
 * Ce qui compte : le représentant légal reçoit les rappels, la majorité bascule
 * toute seule, et les données parentales ne survivent pas à cette bascule.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Documents\DocumentTypes;
use Subalcatel\Club\Identity\LegalGuardian;
use Subalcatel\Club\Identity\ProfileFields;
use Subalcatel\Club\Notifications\DailyDigest;
use Subalcatel\Club\Notifications\EmailTemplates;
use Subalcatel\Club\Notifications\Mailer;

EmailTemplates::seed();

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-54s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$GLOBALS['sub_sent_mails'] = [];
add_filter('pre_wp_mail', static function ($null, array $atts) {
    $GLOBALS['sub_sent_mails'][] = $atts;

    return true;
}, 10, 2);

$makeUser = static function (string $birthDate, string $firstName): int {
    $id = wp_insert_user([
        'user_login' => 'demo_' . wp_generate_password(8, false),
        'user_email' => wp_generate_password(8, false) . '@subalcatel.test',
        'user_pass'  => wp_generate_password(),
        'first_name' => $firstName,
        'role'       => 'sub_member',
    ]);

    update_user_meta($id, 'sub_birth_date', $birthDate);

    return $id;
};

$minor  = $makeUser(gmdate('Y-m-d', strtotime('-15 years')), 'Léa');
$adult  = $makeUser(gmdate('Y-m-d', strtotime('-30 years')), 'Marc');
$almost = $makeUser(gmdate('Y-m-d', strtotime('-18 years')), 'Noé');

// --- Détection ---------------------------------------------------------------
echo "\n--- Qui est mineur ---\n";

$check('Adolescent de 15 ans détecté', LegalGuardian::isMinor($minor), '15 ans');
$check('Adulte non concerné', !LegalGuardian::isMinor($adult));
$check('Le jour des 18 ans, majeur', !LegalGuardian::isMinor($almost), 'bascule le jour même');
$check('Âge calculé', LegalGuardian::ageOf($minor) === 15, (string) LegalGuardian::ageOf($minor));

$noBirthDate = $makeUser('', 'Inconnu');
delete_user_meta($noBirthDate, 'sub_birth_date');
$check('Sans date de naissance, présumé majeur', !LegalGuardian::isMinor($noBirthDate),
    'mieux vaut ne pas bloquer un adulte à tort');

// --- Champs du profil ------------------------------------------------------------
echo "\n--- Champs de profil ---\n";

$minorFields = ProfileFields::forUser($minor);
$adultFields = ProfileFields::forUser($adult);

$check('Le mineur se voit demander un représentant', isset($minorFields['guardian_email']));
$check('L’adulte non', !isset($adultFields['guardian_email']),
    count($adultFields) . ' champs contre ' . count($minorFields));

// --- Dossier incomplet -------------------------------------------------------------
echo "\n--- Dossier du mineur ---\n";

$check('Mineur sans représentant signalé', LegalGuardian::isIncomplete($minor),
    'à régulariser avant la première sortie');
$check('Aucun représentant retourné', LegalGuardian::of($minor) === null);

update_user_meta($minor, 'sub_guardian_name', 'Claire Riou');
update_user_meta($minor, 'sub_guardian_email', 'claire@subalcatel.test');
update_user_meta($minor, 'sub_guardian_phone', '06 55 44 33 22');
update_user_meta($minor, 'sub_guardian_relation', 'mere');

$guardian = LegalGuardian::of($minor);
$check('Représentant reconnu', $guardian !== null && $guardian['name'] === 'Claire Riou');
$check('Dossier complet', !LegalGuardian::isIncomplete($minor));
$check('Un adulte n’a jamais de représentant', LegalGuardian::of($adult) === null);

// --- Copie des messages --------------------------------------------------------------
echo "\n--- Le représentant reçoit les rappels ---\n";

$before = count($GLOBALS['sub_sent_mails']);
Mailer::toUser(EmailTemplates::DOCUMENT_REMINDER, $minor, [
    'document'     => 'certificat médical',
    'fin_validite' => '30 septembre 2026',
    'jours'        => '30',
]);
$sent = array_slice($GLOBALS['sub_sent_mails'], $before);

$check('Deux messages partis', count($sent) === 2, count($sent) . ' envoi(s)');

$recipients = array_column($sent, 'to');
$check('Le mineur est destinataire', in_array(get_userdata($minor)->user_email, $recipients, true));
$check('Le représentant aussi', in_array('claire@subalcatel.test', $recipients, true));

$toGuardian = array_values(array_filter($sent, static fn (array $m): bool => $m['to'] === 'claire@subalcatel.test'))[0];
$check('Le message nomme l’enfant', str_contains($toGuardian['message'], 'Léa'), 'contexte explicite');
$check('Le message nomme le représentant', str_contains($toGuardian['message'], 'Claire Riou'));

// Un modèle non marqué ne part qu'au membre.
$before = count($GLOBALS['sub_sent_mails']);
Mailer::toUser(EmailTemplates::DOCUMENT_VALIDATED, $minor, ['document' => 'certificat médical']);
$check('Modèle non marqué : pas de copie', count($GLOBALS['sub_sent_mails']) - $before === 1);

// L'adulte ne déclenche jamais de copie.
$before = count($GLOBALS['sub_sent_mails']);
Mailer::toUser(EmailTemplates::DOCUMENT_REMINDER, $adult, ['document' => 'certificat médical']);
$check('Aucune copie pour un adulte', count($GLOBALS['sub_sent_mails']) - $before === 1);

// --- Autorisation parentale -------------------------------------------------------------
echo "\n--- Autorisation parentale ---\n";

$consentId = DocumentTypes::create([
    'label'         => 'Autorisation parentale',
    'is_required'   => 1,
    'required_when' => DocumentTypes::REQUIRED_MINOR,
    'blocks_dives'  => 1,
    'has_validity'  => 0,
]);

$consent = DocumentTypes::find('autorisation-parentale');
$check('Exigée du mineur', DocumentTypes::isRequiredFor($consent, $minor));
$check('Pas de l’adulte', !DocumentTypes::isRequiredFor($consent, $adult));

DocumentTypes::remove($consentId);

// --- Passage à la majorité -----------------------------------------------------------------
echo "\n--- Passage à la majorité ---\n";

$birthday = $makeUser(gmdate('Y-m-d', strtotime('-18 years')), 'Jules');
update_user_meta($birthday, 'sub_guardian_name', 'Paul Martin');
update_user_meta($birthday, 'sub_guardian_email', 'paul@subalcatel.test');

$detected = LegalGuardian::newlyOfAge();
$check('Anniversaire détecté', in_array($birthday, $detected, true), count($detected) . ' compte(s)');
$check('Un mineur n’est pas détecté', !in_array($minor, $detected, true));

$result = DailyDigest::run();
$check('Bascule opérée par la tâche quotidienne', $result['came_of_age'] >= 1,
    $result['came_of_age'] . ' compte(s)');

$check('Coordonnées parentales effacées',
    get_user_meta($birthday, 'sub_guardian_email', true) === '',
    'plus rien ne justifie de les conserver');
$check('Date de majorité conservée',
    get_user_meta($birthday, 'sub_came_of_age_on', true) !== '', 'trace de la bascule');

// --- Nettoyage --------------------------------------------------------------------------------
global $wpdb;
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ([$minor, $adult, $almost, $noBirthDate, $birthday] as $id) {
    sub_test_clean_documents($id);
    $wpdb->delete("{$wpdb->prefix}sub_notification_log", ['recipient_id' => $id]);
    wp_delete_user($id);
}

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
