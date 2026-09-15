<?php
/**
 * Test de fumée du cycle de vie d'une campagne : duplication, puis suppression.
 *
 *   docker exec sub_demo_cli wp eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-campaigns.php
 *
 * La duplication est l'écran le plus utilisé du back-office — le trésorier
 * clone la saison précédente chaque septembre —, et le clic de trop ne se
 * rattrapait pas : rien ne supprimait une campagne.
 *
 * Ce qui se vérifie ici tient en une phrase : une campagne vide s'efface avec
 * tout ce qu'elle porte, une campagne qui a servi ne s'efface pas. La seconde
 * moitié compte davantage — les lignes figées d'un dossier renvoient aux tarifs
 * de sa campagne, et l'export du trésorier les relit.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Admin\CampaignsScreen;
use Subalcatel\Club\Membership\ApplicationService;

global $wpdb;
$p = $wpdb->prefix . 'sub_';

$campaignId = sub_test_pricing_campaign();
$failures   = 0;

$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-54s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$compte = static function (string $table, int $id) use ($wpdb, $p): int {
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}{$table} WHERE campaign_id = %d",
        $id
    ));
};

// --- Une campagne vide s'efface avec ce qu'elle porte -------------------------
echo "\n--- Suppression d'une campagne sans dossier ---\n";

// On duplique plutôt que de créer : c'est le geste qui produit les brouillons
// en trop, donc celui dont on veut pouvoir revenir.
$wpdb->insert("{$p}campaigns", [
    'title'       => 'Campagne à supprimer',
    'slug'        => 'campagne-a-supprimer',
    'opens_on'    => '2028-09-01',
    'closes_on'   => '2028-12-31',
    'valid_from'  => '2028-09-15',
    'valid_until' => '2029-12-31',
    'status'      => 'draft',
]);
$brouillon = (int) $wpdb->insert_id;

$wpdb->insert("{$p}plans", [
    'campaign_id' => $brouillon,
    'title'       => 'Plongée',
    'slug'        => 'plongee',
    'base_price'  => 210.00,
    'published'   => 1,
    'ordering'    => 1,
]);
$wpdb->insert("{$p}options", [
    'campaign_id' => $brouillon,
    'name'        => 'origine_adhesion',
    'label'       => 'Origine',
    'input_type'  => 'single',
    'choices'     => wp_json_encode([['value' => 'nokia', 'label' => 'Nokia', 'amount' => 0.0]]),
    'ordering'    => 10,
]);
$wpdb->insert("{$p}discount_rules", [
    'campaign_id'      => $brouillon,
    'label'            => 'Remise Nokia',
    'condition_option' => 'origine_adhesion',
    'condition_values' => wp_json_encode(['nokia']),
    'flat_amount'      => -58.00,
    'ordering'         => 10,
]);

$check('Brouillon créé avec sa formule et son option',
    $compte('plans', $brouillon) === 1 && $compte('options', $brouillon) === 1);

$titre = CampaignsScreen::delete($brouillon);

$check('Campagne supprimée', $titre === 'Campagne à supprimer', $titre);
$check('… et elle a bien disparu',
    $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}campaigns WHERE id = %d", $brouillon)) === null);
$check('Formules emportées',        $compte('plans', $brouillon) === 0);
$check('Options emportées',         $compte('options', $brouillon) === 0);
$check('Remises emportées',         $compte('discount_rules', $brouillon) === 0);

$audits = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sub_audit_log
     WHERE entity_type = 'campaign' AND entity_id = %d AND action = 'campaign.deleted'",
    $brouillon
));
$check('Suppression tracée au journal', $audits === 1, "{$audits} entrée(s)");

// --- Une campagne qui a servi ne s'efface pas ---------------------------------
echo "\n--- Refus dès le premier dossier ---\n";

$membre = wp_insert_user([
    'user_login' => 'demo_' . wp_generate_password(8, false),
    'user_pass'  => wp_generate_password(),
    'role'       => 'sub_member',
]);
$membre = is_wp_error($membre) ? 0 : $membre;
sub_test_complete_identity($membre);

$dossier = (new ApplicationService())->submit($membre, $campaignId, 'plongee', [
    'origine_adhesion'       => 'exterieur',
    'assurance_individuelle' => 'aucune',
    'niveau_prepare'         => 'aucun',
    'pret_bloc'              => 'non',
    'pret_detendeur'         => 'non',
    'pret_gilet'             => 'non',
], 'cheque');

$check('Dossier déposé sur la campagne de test', $dossier > 0);

try {
    CampaignsScreen::delete($campaignId);
    $check('Une campagne portant un dossier ne se supprime pas', false);
} catch (RuntimeException $e) {
    $check('Une campagne portant un dossier ne se supprime pas', true, $e->getMessage());
}

$check('La campagne est toujours là',
    $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}campaigns WHERE id = %d", $campaignId)) !== null);
$check('Ses formules aussi', $compte('plans', $campaignId) > 0);

// --- Une campagne qui n'existe pas --------------------------------------------
try {
    CampaignsScreen::delete(0);
    $check('Une campagne introuvable est refusée', false);
} catch (RuntimeException $e) {
    $check('Une campagne introuvable est refusée', true, $e->getMessage());
}

// --- Nettoyage ----------------------------------------------------------------
require_once ABSPATH . 'wp-admin/includes/user.php';
if ($membre) {
    wp_delete_user($membre);
}

sub_test_drop_campaign($campaignId);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
