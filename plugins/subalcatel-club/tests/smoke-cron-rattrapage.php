<?php
/**
 * Test de fumée : une journée sans cron ne perd plus rien.
 *
 *   docker exec sub_demo_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-cron-rattrapage.php
 *
 * Le point à prouver. Quatre opérations de l'entretien quotidien visaient une
 * date **exacte** : « expire dans 30 jours » ne partait que le jour où il en
 * restait exactement 30. Or la tâche ne revient jamais en arrière, et
 * WordPress ne rejoue pas une occurrence manquée — deux jours d'arrêt ne font
 * pas deux exécutions au retour, ils en font une. Un rappel sauté était donc
 * sauté pour de bon, et le passage à la majorité laissait les coordonnées du
 * représentant légal en base pour toujours.
 *
 * Chacune couvre désormais une fenêtre. Ce qui suit simule des journées
 * manquées en passant une date à `run()`, et vérifie les deux propriétés qui
 * comptent ensemble : **rien n'est perdu**, et **rien n'est envoyé deux fois**.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Documents\DocumentTypes;
use Subalcatel\Club\Identity\LegalGuardian;
use Subalcatel\Club\Notifications\DailyDigest;
use Subalcatel\Club\Notifications\EmailTemplates;

global $wpdb;

EmailTemplates::seed();
DocumentTypes::seed();

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-56s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$GLOBALS['sub_sent_mails'] = [];
add_filter('pre_wp_mail', static function ($null, array $atts) {
    $GLOBALS['sub_sent_mails'][] = $atts;

    return true;
}, 10, 2);

$mailsOn = static function (string $needle): int {
    $n = 0;
    foreach ($GLOBALS['sub_sent_mails'] as $mail) {
        if (str_contains((string) ($mail['message'] ?? ''), $needle)) {
            $n++;
        }
    }

    return $n;
};

$makeUser = static function (string $firstName, ?string $birthDate = null): int {
    $id = (int) wp_insert_user([
        'user_login' => 'demo_' . wp_generate_password(8, false),
        'user_email' => wp_generate_password(8, false) . '@subalcatel.test',
        'user_pass'  => wp_generate_password(),
        'first_name' => $firstName,
        'role'       => 'sub_member',
    ]);

    if ($birthDate !== null) {
        update_user_meta($id, 'sub_birth_date', $birthDate);
    }

    return $id;
};

$today = new DateTimeImmutable('2026-06-01');
$at    = static fn (int $days): string => (new DateTimeImmutable('2026-06-01'))
    ->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');

// =============================================================================
// 1. Rappel d'adhésion sauté pendant cinq jours
// =============================================================================
echo "\n--- Rappel d'adhésion : cinq jours sans cron ---\n";

$campaignId = (int) $wpdb->insert("{$wpdb->prefix}sub_campaigns", [
    'slug'          => 'rattrapage-' . wp_generate_password(6, false),
    'title'         => 'Campagne de rattrapage',
    'status'        => 'open',
    'reminder_days' => '60,30',
]) ? (int) $wpdb->insert_id : 0;

$late = $makeUser('Tardive');

// Échéance à J+30 depuis le 1er juin : le rappel « J-30 » aurait dû partir ce
// jour-là. On ne lance la tâche que le 6.
$wpdb->insert("{$wpdb->prefix}sub_applications", [
    'user_id'     => $late,
    'campaign_id' => $campaignId,
    'status'      => 'active',
    'reference'   => 'RATT-' . wp_generate_password(6, false),
    'valid_until' => $at(30),
    'created_at'  => $at(-200),
]);

$before = count($GLOBALS['sub_sent_mails']);
$result = DailyDigest::run($at(5));   // cinq jours de retard
$check(
    'le rappel manqué part quand même',
    $result['membership_reminders'] === 1,
    sprintf('%d envoyé(s)', $result['membership_reminders'])
);

$body = (string) ($GLOBALS['sub_sent_mails'][$before]['message'] ?? '');
$check(
    'il annonce les jours RÉELLEMENT restants, pas l\'échéance nominale',
    str_contains($body, 'dans 25 jours'),
    str_contains($body, 'dans 30 jours') ? 'il dit encore « 30 jours »' : 'vu : ' . trim(explode("\n", $body)[2] ?? '')
);

$again = DailyDigest::run($at(6));
$check('le lendemain, il ne repart pas', $again['membership_reminders'] === 0);
$check('ni le surlendemain', DailyDigest::run($at(7))['membership_reminders'] === 0);

// =============================================================================
// 2. Les bandes ne se chevauchent pas
// =============================================================================
echo "\n--- Deux échéances (J-60, J-30) : une seule doit s'appliquer ---\n";

$soon = $makeUser('Proche');
$wpdb->insert("{$wpdb->prefix}sub_applications", [
    'user_id'     => $soon,
    'campaign_id' => $campaignId,
    'status'      => 'active',
    'reference'   => 'PROCHE-' . wp_generate_password(6, false),
    'valid_until' => $at(10),
    'created_at'  => $at(-200),
]);

$before = count($GLOBALS['sub_sent_mails']);
$r = DailyDigest::run($at(0));
$check(
    'une échéance à 10 jours reçoit UN rappel, pas deux',
    $r['membership_reminders'] === 1,
    sprintf('%d envoyé(s)', $r['membership_reminders'])
);
$check(
    'et c\'est bien « 10 jours », pas « 30 » ni « 60 »',
    str_contains((string) ($GLOBALS['sub_sent_mails'][$before]['message'] ?? ''), 'dans 10 jours')
);

// =============================================================================
// 3. Passage à la majorité manqué
// =============================================================================
echo "\n--- Majorité : l'anniversaire est passé depuis longtemps ---\n";

// 18 ans et 40 jours : la tâche n'a pas tourné le jour de l'anniversaire.
$grownUp = $makeUser('Majeur', (new DateTimeImmutable($at(0)))->modify('-18 years -40 days')->format('Y-m-d'));
foreach (['name' => 'Parent Témoin', 'email' => 'parent@subalcatel.test', 'phone' => '0600000000', 'relation' => 'tuteur'] as $k => $v) {
    update_user_meta($grownUp, 'sub_guardian_' . $k, $v);
}

// Un adulte qui n'a jamais été mineur ici : aucune coordonnée de représentant.
$plainAdult = $makeUser('Adulte', (new DateTimeImmutable($at(0)))->modify('-40 years')->format('Y-m-d'));

$r = DailyDigest::run($at(0));
$check(
    'le compte est rattrapé 40 jours après l\'anniversaire',
    $r['came_of_age'] >= 1,
    sprintf('%d bascule(s)', $r['came_of_age'])
);
$check(
    'les coordonnées du représentant légal sont effacées',
    get_user_meta($grownUp, 'sub_guardian_name', true) === ''
        && get_user_meta($grownUp, 'sub_guardian_email', true) === ''
        && get_user_meta($grownUp, 'sub_guardian_phone', true) === ''
        && get_user_meta($grownUp, 'sub_guardian_relation', true) === ''
);
$check('la bascule est datée', get_user_meta($grownUp, 'sub_came_of_age_on', true) !== '');
$check(
    'le compte n\'est pas retraité le lendemain',
    !in_array($grownUp, LegalGuardian::newlyOfAge($at(1)), true)
);
$check(
    'un adulte sans représentant légal n\'est jamais concerné',
    !in_array($plainAdult, LegalGuardian::newlyOfAge($at(0)), true)
        && get_user_meta($plainAdult, 'sub_came_of_age_on', true) === ''
);

// =============================================================================
// 4. Avertissement avant purge : sauté pendant une semaine
// =============================================================================
echo "\n--- Avant purge : l'avertissement ne doit pas se perdre ---\n";

// Purge prévue dans 10 jours. L'avertissement part à J-15, donc il aurait dû
// partir il y a cinq jours. Sans fenêtre, le membre découvrirait la
// suppression en constatant la disparition de sa seule copie.
$warned = $makeUser('Prévenu');
$wpdb->insert("{$wpdb->prefix}sub_member_documents", [
    'user_id'     => $warned,
    'type_slug'   => 'certificat-medical',
    'file_path'   => 'rattrapage/' . wp_generate_password(8, false) . '.pdf',
    'status'      => 'expired',
    'valid_until' => $at(-40),
    'purge_on'    => $at(10),
    'uploaded_at' => $at(-400),
]);
$documentId = (int) $wpdb->insert_id;

$r = DailyDigest::run($at(0));
$check(
    'l\'avertissement manqué part quand même',
    $r['purge_warnings'] === 1,
    sprintf('%d envoyé(s)', $r['purge_warnings'])
);
$check('et pas une seconde fois', DailyDigest::run($at(1))['purge_warnings'] === 0);

// Une fois la date atteinte, c'est la purge qui s'applique, pas l'avertissement.
$wpdb->update("{$wpdb->prefix}sub_member_documents", ['purge_on' => $at(2)], ['id' => $documentId]);
$r = DailyDigest::run($at(3));
$check(
    'passé la date, on purge au lieu d\'avertir',
    $r['purged'] >= 1 && $r['purge_warnings'] === 0,
    sprintf('%d purgé(s), %d averti(s)', $r['purged'], $r['purge_warnings'])
);

// =============================================================================
// Ménage
// =============================================================================
$wpdb->delete("{$wpdb->prefix}sub_applications", ['campaign_id' => $campaignId]);
$wpdb->delete("{$wpdb->prefix}sub_campaigns", ['id' => $campaignId]);

require_once ABSPATH . 'wp-admin/includes/user.php';
$wpdb->delete("{$wpdb->prefix}sub_member_documents", ['id' => $documentId]);

foreach ([$late, $soon, $grownUp, $plainAdult, $warned] as $id) {
    wp_delete_user($id);
}

printf("\n%s\n", $failures === 0 ? 'Tout est au vert.' : $failures . ' échec(s).');
exit($failures === 0 ? 0 : 1);
