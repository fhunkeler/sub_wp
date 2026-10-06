<?php
/**
 * Test de fumée de l'écran « Mon adhésion ».
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-membership-view.php
 *
 * Ce que l'adhérent lit sur son propre dossier engage le club autant qu'un
 * courriel du bureau. Le point vérifié ici : « Adhésions précédentes » ne
 * recense que des adhésions. Un dossier annulé ou refusé porte pourtant une
 * date de fin de validité — celle de sa campagne, recopiée au dépôt —, et il
 * se glissait dans la liste comme une saison réglée.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Membership\ApplicationService;

global $wpdb;

$campaignId = sub_test_pricing_campaign();
$service    = new ApplicationService();
$failures   = 0;

$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-54s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$member = wp_insert_user([
    'user_login' => 'demo_' . wp_generate_password(8, false),
    'user_pass'  => wp_generate_password(),
    'role'       => 'sub_member',
]);
$member = is_wp_error($member) ? 0 : (int) $member;
sub_test_complete_identity($member);

$answers = [
    'origine_adhesion'       => 'exterieur',
    'assurance_individuelle' => 'loisir2',
    'pret_bloc'              => 'non',
    'pret_detendeur'         => 'non',
    'pret_gilet'             => 'non',
];

// Le premier dossier est une erreur de saisie : l'adhérent l'annule et en
// dépose un second, exactement le parcours que le formulaire lui recommande.
$abandonne = $service->submit($member, $campaignId, 'plongee', $answers, 'cheque');
$service->cancel($abandonne, $member);

// Deux dépôts dans la même seconde : sans cet écart, « le plus récent » se
// jouerait à pile ou face et le test avec lui.
$wpdb->update(
    "{$wpdb->prefix}sub_applications",
    ['created_at' => '2026-09-01 09:00:00'],
    ['id' => $abandonne]
);

$courant = $service->submit($member, $campaignId, 'plongee', $answers, 'cheque');

wp_set_current_user($member);

// --- Un dossier annulé n'est pas une adhésion passée -------------------------
echo "\n--- Historique de l'adhérent ---\n";

$html = do_shortcode('[subalcatel_mon_adhesion]');

$check('Le dossier en cours s’affiche',
    str_contains($html, (string) $service->find($courant)['reference']));
$check('Aucune « adhésion précédente » pour un dossier annulé',
    !str_contains($html, 'Adhésions précédentes'),
    'le dossier annulé sortait daté de la fin de campagne');

// Même vérification pour un refus : c'est le même écart entre le dossier et
// l'adhésion, et il se lit tout aussi mal.
$wpdb->update(
    "{$wpdb->prefix}sub_applications",
    ['status' => ApplicationService::STATUS_REFUSED],
    ['id' => $abandonne]
);

$check('Ni pour un dossier refusé',
    !str_contains(do_shortcode('[subalcatel_mon_adhesion]'), 'Adhésions précédentes'));

// --- Une vraie saison passée, elle, reste affichée ---------------------------
$wpdb->update(
    "{$wpdb->prefix}sub_applications",
    ['status' => ApplicationService::STATUS_ACTIVE],
    ['id' => $abandonne]
);

$check('Une adhésion active passée reste listée',
    str_contains(do_shortcode('[subalcatel_mon_adhesion]'), 'Adhésions précédentes'),
    'sans quoi le filtre aurait vidé l’historique');

// --- La campagne suivante se propose dès son ouverture -----------------------
// L'adhésion de la saison passée court encore quand la suivante ouvre :
// « Mon adhésion » ne montrait que le dossier actif, sans aucun lien vers le
// formulaire (retour d'un adhérent, 06/10/2026).
echo "\n--- Renouvellement ---\n";

$wpdb->update(
    "{$wpdb->prefix}sub_applications",
    ['status' => ApplicationService::STATUS_ACTIVE, 'valid_until' => '2099-12-31'],
    ['id' => $courant]
);

// Ouverte aujourd'hui : c'est elle que `openCampaign()` retient, devant toute
// campagne ouverte plus tôt sur la démo.
$wpdb->insert("{$wpdb->prefix}sub_campaigns", [
    'title'       => 'Campagne suivante de test',
    'slug'        => 'campagne-suivante-de-test-' . wp_generate_password(6, false),
    'opens_on'    => current_time('Y-m-d'),
    'closes_on'   => '2099-12-31',
    'valid_from'  => current_time('Y-m-d'),
    'valid_until' => '2099-12-31',
    'status'      => 'open',
]);
$suivante = (int) $wpdb->insert_id;

$html = do_shortcode('[subalcatel_mon_adhesion]');
$check('Une adhésion active n’empêche pas de voir la nouvelle campagne',
    str_contains($html, 'Campagne suivante de test') && str_contains($html, 'Renouveler mon adhésion'),
    'aucun chemin vers le formulaire');
$check('L’échéance de l’adhésion en cours est rappelée',
    str_contains($html, 'Votre adhésion actuelle reste valable'));

update_user_meta($member, 'sub_membership_valid_until', '2099-12-31');
$check('Rien à renouveler pour qui est déjà couvert jusqu’au bout',
    $service->renewalCampaign($member) === null);
delete_user_meta($member, 'sub_membership_valid_until');

$wpdb->insert("{$wpdb->prefix}sub_applications", [
    'reference'   => 'TEST-' . wp_generate_password(8, false),
    'user_id'     => $member,
    'campaign_id' => $suivante,
    'plan_id'     => 0,
    'status'      => ApplicationService::STATUS_SUBMITTED,
]);
$check('Plus d’invitation une fois le dossier déposé',
    $service->renewalCampaign($member) === null);

$wpdb->update("{$wpdb->prefix}sub_campaigns", ['status' => 'closed'], ['id' => $suivante]);
sub_test_drop_campaign($suivante);

// --- Nettoyage ----------------------------------------------------------------
wp_set_current_user(0);
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($member);
sub_test_drop_campaign($campaignId);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
