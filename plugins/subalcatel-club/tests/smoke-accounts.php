<?php
/**
 * Test de fumée de la validation des comptes.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-accounts.php
 *
 * La règle tient en une phrase : **créer un compte n'est pas entrer au club**.
 * Ce qui se vérifie ici, c'est que le compte existe bien — la personne peut se
 * connecter et suivre sa demande — et qu'il ne donne accès à rien tant que le
 * bureau n'a pas tranché.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Identity\AccountApproval;
use Subalcatel\Club\Identity\Roles;
use Subalcatel\Club\Membership\ApplicationService;
use Subalcatel\Club\Membership\CampaignRepository;
use Subalcatel\Club\Membership\DemoSeeder;
use Subalcatel\Club\Notifications\EmailTemplates;
use Subalcatel\Club\Policy\Decision;
use Subalcatel\Club\Policy\EligibilityPolicy;

global $wpdb;

EmailTemplates::seed();
$campaignId = DemoSeeder::run();

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-58s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$GLOBALS['sub_sent_mails'] = [];
add_filter('pre_wp_mail', static function ($null, array $atts) {
    $GLOBALS['sub_sent_mails'][] = $atts;

    return true;
}, 10, 2);

$makeUser = static function (string $role): int {
    return (int) wp_insert_user([
        'user_login' => 'demo_' . wp_generate_password(8, false),
        'user_email' => wp_generate_password(8, false) . '@subalcatel.test',
        'user_pass'  => wp_generate_password(),
        'first_name' => 'Test',
        'role'       => $role,
    ]);
};

$office = $makeUser(Roles::OFFICE);
$policy = new EligibilityPolicy();

// --- Création ----------------------------------------------------------------
echo "\n--- Création d’un compte ---\n";

$newcomer = $makeUser(Roles::MEMBER);
AccountApproval::markPending($newcomer);

$check('Le compte existe', get_userdata($newcomer) !== false);
$check('Il est marqué en attente', AccountApproval::isPending($newcomer),
    AccountApproval::statusOf($newcomer));
$check('Son rôle est « invité »', in_array(Roles::GUEST, get_userdata($newcomer)->roles, true),
    'l’état vit dans le rôle, lisible depuis l’écran Comptes de WordPress');
$check('Il apparaît dans la file', in_array($newcomer, array_map(
    static fn (\WP_User $u): int => $u->ID,
    AccountApproval::pending()
), true), AccountApproval::pendingCount() . ' en attente');

// --- Ce qu'un compte en attente ne peut pas faire ----------------------------
echo "\n--- Ce que le compte ne permet pas encore ---\n";

$decision = $policy->hasApprovedAccount($newcomer);

$check('Le compte n’est pas validé', !$decision->allowed);
$check('Le code est explicite', $decision->code === Decision::ACCOUNT_PENDING, $decision->code);
$check('Le motif s’adresse à la personne', str_contains($decision->reason, 'Votre compte'),
    $decision->reason);
$check('Le bureau a un libellé court', $decision->shortLabel() === 'Compte à valider');

$dive = $policy->canRegisterForDive($newcomer, ['p1', 'p3']);
$check('Aucune inscription à une sortie', !$dive->allowed);
$check('Et c’est le compte qui est invoqué, pas l’adhésion',
    $dive->code === Decision::ACCOUNT_PENDING,
    'le motif le plus actionnable en premier');

try {
    (new ApplicationService())->submit($newcomer, $campaignId, 'plongee', []);
    $check('Aucune demande d’adhésion', false);
} catch (RuntimeException $e) {
    $check('Aucune demande d’adhésion', true, $e->getMessage());
}

// --- Validation ---------------------------------------------------------------
echo "\n--- Validation par le bureau ---\n";

$before = count($GLOBALS['sub_sent_mails']);
AccountApproval::approve($newcomer, $office);
$sent = array_slice($GLOBALS['sub_sent_mails'], $before);

$check('Le compte est validé', AccountApproval::statusOf($newcomer) === AccountApproval::STATUS_APPROVED);
$check('Il devient membre', in_array(Roles::MEMBER, get_userdata($newcomer)->roles, true));
$check('La personne est prévenue', count($sent) === 1 && str_contains($sent[0]['subject'], 'validé'),
    $sent[0]['subject'] ?? 'aucun envoi');
$check('La décision est tracée',
    (string) get_user_meta($newcomer, AccountApproval::META_ACTOR, true) === (string) $office);
$check('Il sort de la file', !in_array($newcomer, array_map(
    static fn (\WP_User $u): int => $u->ID,
    AccountApproval::pending()
), true));

$after = $policy->canRegisterForDive($newcomer, ['p1', 'p3']);
$check('Le blocage suivant est l’adhésion, plus le compte',
    $after->code === Decision::NO_MEMBERSHIP, $after->code);

// Une seconde validation ne doit rien renvoyer ni renotifier.
$before = count($GLOBALS['sub_sent_mails']);
AccountApproval::approve($newcomer, $office);
$check('Valider deux fois ne renotifie pas', count($GLOBALS['sub_sent_mails']) === $before);

// --- Refus --------------------------------------------------------------------
echo "\n--- Refus motivé ---\n";

$rejected = $makeUser(Roles::MEMBER);
AccountApproval::markPending($rejected);

try {
    AccountApproval::refuse($rejected, $office, '   ');
    $check('Un refus sans motif est rejeté', false);
} catch (RuntimeException $e) {
    $check('Un refus sans motif est rejeté', true, $e->getMessage());
}

$before = count($GLOBALS['sub_sent_mails']);
AccountApproval::refuse($rejected, $office, 'Doublon avec un compte existant.');
$sent = array_slice($GLOBALS['sub_sent_mails'], $before);

$check('Le compte est refusé', AccountApproval::isRefused($rejected));
$check('Le motif est conservé',
    (string) get_user_meta($rejected, AccountApproval::META_REASON, true) === 'Doublon avec un compte existant.',
    'le bureau doit pouvoir le relire si l’intéressé rappelle');
$check('Le motif part avec le message',
    $sent !== [] && str_contains($sent[0]['message'], 'Doublon avec un compte existant.'));
$check('Le compte n’est pas supprimé', get_userdata($rejected) !== false,
    'des documents peuvent y être rattachés');

$refusedDecision = $policy->hasApprovedAccount($rejected);
$check('Il reste bloqué', !$refusedDecision->allowed);
$check('Avec son propre code', $refusedDecision->code === Decision::ACCOUNT_REFUSED);

// Un refus se revient dessus.
AccountApproval::approve($rejected, $office);
$check('On peut revenir sur un refus', AccountApproval::statusOf($rejected) === AccountApproval::STATUS_APPROVED);

// --- Droits -------------------------------------------------------------------
echo "\n--- Qui peut valider ---\n";

$plainMember = $makeUser(Roles::MEMBER);
$another     = $makeUser(Roles::MEMBER);
AccountApproval::markPending($another);

$check('Le bureau détient la capacité', user_can($office, 'sub_validate_account'));
$check('Un adhérent ordinaire non', !user_can($plainMember, 'sub_validate_account'));

try {
    AccountApproval::approve($another, $plainMember);
    $check('Un adhérent ne peut pas valider', false);
} catch (RuntimeException $e) {
    $check('Un adhérent ne peut pas valider', true, $e->getMessage());
}

$check('Le compte visé reste en attente', AccountApproval::isPending($another));

// --- Les comptes existants ne sont pas bloqués --------------------------------
echo "\n--- Comptes antérieurs au circuit ---\n";

$legacy = $makeUser(Roles::MEMBER);

$check('Un compte sans marquage est considéré validé',
    $policy->hasApprovedAccount($legacy)->allowed,
    'sinon la reprise du Joomla bloquerait 330 adhérents d’un coup');

// --- Nettoyage ----------------------------------------------------------------
require_once ABSPATH . 'wp-admin/includes/user.php';

foreach ([$office, $newcomer, $rejected, $plainMember, $another, $legacy] as $id) {
    $wpdb->delete("{$wpdb->prefix}sub_notification_log", ['recipient_id' => $id]);
    $wpdb->delete("{$wpdb->prefix}sub_applications", ['user_id' => $id]);
    wp_delete_user($id);
}

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
