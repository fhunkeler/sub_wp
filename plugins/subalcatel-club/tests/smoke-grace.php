<?php
/**
 * Test de fumée de la période de grâce.
 *
 *   docker exec sub_demo_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-grace.php
 *
 * Une adhésion expirée l'était le jour même : l'adhérent dont le
 * renouvellement attendait le bureau perdait la sortie du week-end. La grâce
 * lui rend le droit de participer, et seulement celui-là — l'adhésion reste
 * échue partout ailleurs, et l'organisation d'une sortie reste fermée.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Membership\ApplicationService;
use Subalcatel\Club\Membership\MembershipGrace;
use Subalcatel\Club\Policy\Decision;
use Subalcatel\Club\Policy\EligibilityPolicy;

global $wpdb;
$p = $wpdb->prefix . 'sub_';

$failures = 0;
$check    = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-54s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$graceBefore = get_option(MembershipGrace::OPTION, null);
delete_option(MembershipGrace::OPTION);

$policy  = new EligibilityPolicy();
$service = new ApplicationService();
$today   = current_time('Y-m-d');
$daysAgo = static fn (int $n): string => (new DateTimeImmutable($today))->modify("-{$n} days")->format('Y-m-d');

$member = wp_insert_user([
    'user_login' => 'demo_' . wp_generate_password(8, false),
    'user_pass'  => wp_generate_password(),
    'role'       => 'sub_member',
]);
$member = is_wp_error($member) ? 0 : (int) $member;
sub_test_complete_identity($member);
sub_test_make_compliant($member);

// Échue hier : le cas que la grâce sert.
update_user_meta($member, 'sub_membership_valid_until', $daysAgo(1));

// La campagne de renouvellement, seule ouverte le temps du test.
$campaignId = sub_test_pricing_campaign();
$otherOpen  = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}campaigns WHERE status = 'open'"));
foreach ($otherOpen as $other) {
    $wpdb->update("{$p}campaigns", ['status' => 'draft'], ['id' => $other]);
}
$wpdb->update("{$p}campaigns", ['status' => 'open'], ['id' => $campaignId]);

// --- Réglage par défaut ---------------------------------------------------------
echo "\n--- Réglage par défaut ---\n";

$settings = MembershipGrace::settings();
$check('30 jours par défaut', $settings['days'] === 30);
$check('Renouvellement déposé exigé par défaut', $settings['requires_renewal'] === true);

// --- Sans dossier : refus, mais motivé -------------------------------------------
echo "\n--- Sans renouvellement ---\n";

$decision = $policy->canRegisterForDive($member);
$check('Inscription refusée sans dossier', !$decision->allowed);
$check('Le motif reste « adhésion expirée »', $decision->code === Decision::MEMBERSHIP_EXPIRED);
$check('Le motif invite à déposer le renouvellement',
    str_contains($decision->reason, 'Déposez votre renouvellement'), $decision->reason);

$wpdb->update("{$p}campaigns", ['status' => 'draft'], ['id' => $campaignId]);
$check('Aucune campagne ouverte : pas d’invitation à un dossier impossible',
    !str_contains($policy->canRegisterForDive($member)->reason, 'Déposez'));
$wpdb->update("{$p}campaigns", ['status' => 'open'], ['id' => $campaignId]);

// --- Dossier déposé : la grâce s'ouvre ---------------------------------------------
echo "\n--- Renouvellement déposé ---\n";

$applicationId = $service->submit($member, $campaignId, 'plongee', [
    'origine_adhesion'       => 'exterieur',
    'assurance_individuelle' => 'loisir2',
    'pret_bloc'              => 'non',
    'pret_detendeur'         => 'non',
    'pret_gilet'             => 'non',
], 'cheque');

// `submit()` ne touche pas l'échéance : on la vérifie quand même, le test
// n'aurait plus de sens sinon.
$check('L’échéance est toujours passée',
    (string) get_user_meta($member, 'sub_membership_valid_until', true) === $daysAgo(1));

$check('Inscription à une sortie acceptée', $policy->canRegisterForDive($member)->allowed);
$check('Jusqu’au 30e jour après l’échéance',
    MembershipGrace::until($member) === (new DateTimeImmutable($daysAgo(1)))->modify('+30 days')->format('Y-m-d'));

$check('L’adhésion, elle, reste échue', !$policy->hasActiveMembership($member)->allowed,
    'exports FFESSM, listes, fiche membre');

wp_set_current_user($member);
$dashboard = do_shortcode('[subalcatel_espace_membre]');
$check('Le tableau de bord dit que le dossier est en cours',
    str_contains($dashboard, 'Renouvellement en cours de traitement'));
wp_set_current_user(0);

// --- Le délai a une fin -------------------------------------------------------------
echo "\n--- Fin du délai ---\n";

update_user_meta($member, 'sub_membership_valid_until', $daysAgo(31));
$check('Au-delà de 30 jours, refus', !$policy->canRegisterForDive($member)->allowed);

update_user_meta($member, 'sub_membership_valid_until', $daysAgo(30));
$check('Le 30e jour est encore couvert', $policy->canRegisterForDive($member)->allowed);

// --- Un dossier annulé ne compte pas ------------------------------------------------
update_user_meta($member, 'sub_membership_valid_until', $daysAgo(1));
$service->cancel($applicationId, $member);
$check('Dossier annulé : plus de grâce', !$policy->canRegisterForDive($member)->allowed);

// --- Les réglages du bureau ----------------------------------------------------------
echo "\n--- Réglages ---\n";

MembershipGrace::save(15, false);
$check('Sans condition de dossier, la grâce s’applique', $policy->canRegisterForDive($member)->allowed);

update_user_meta($member, 'sub_membership_valid_until', $daysAgo(16));
$check('… dans la limite de la durée choisie', !$policy->canRegisterForDive($member)->allowed);

MembershipGrace::save(0, false);
update_user_meta($member, 'sub_membership_valid_until', $daysAgo(1));
$decision = $policy->canRegisterForDive($member);
$check('0 jour : expirée le jour même, comme avant', !$decision->allowed);
$check('Sans invitation à un sursis qui n’existe pas',
    !str_contains($decision->reason, 'Déposez'), $decision->reason);

MembershipGrace::save(500, true);
$check('La durée est plafonnée', MembershipGrace::settings()['days'] === MembershipGrace::MAX_DAYS);

// --- Une adhésion à jour n'est pas « en grâce » --------------------------------------
update_user_meta($member, 'sub_membership_valid_until', '2099-12-31');
$check('Adhésion à jour : pas de date de grâce', MembershipGrace::until($member) === null);

// --- Nettoyage ------------------------------------------------------------------------
$wpdb->update("{$p}campaigns", ['status' => 'draft'], ['id' => $campaignId]);
foreach ($otherOpen as $other) {
    $wpdb->update("{$p}campaigns", ['status' => 'open'], ['id' => $other]);
}

sub_test_clean_documents($member);
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($member);
sub_test_drop_campaign($campaignId);

$graceBefore === null ? delete_option(MembershipGrace::OPTION) : update_option(MembershipGrace::OPTION, $graceBefore);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
