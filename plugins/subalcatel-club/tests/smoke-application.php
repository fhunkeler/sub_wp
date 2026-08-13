<?php
/**
 * Test de fumée du cycle de vie d'un dossier d'adhésion.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-application.php
 *
 * Vérifie le parcours complet — soumission, paiement, validation — et surtout
 * que les droits sont refusés à qui ne les a pas.
 *
 * Le dossier est déposé sur une campagne créée par le test : le montant figé
 * qu'on y vérifie doit dépendre du calcul, pas du tarif que le bureau aura
 * saisi pour la saison en cours.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Membership\ApplicationService;
use Subalcatel\Club\Policy\EligibilityPolicy;

$campaignId = sub_test_pricing_campaign();
$service    = new ApplicationService();
$policy     = new EligibilityPolicy();
$failures   = 0;

$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-54s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$makeUser = static function (string $role): int {
    $id = wp_insert_user([
        'user_login' => 'demo_' . wp_generate_password(8, false),
        'user_pass'  => wp_generate_password(),
        'role'       => $role,
    ]);

    return is_wp_error($id) ? 0 : $id;
};

$member    = $makeUser('sub_member');
$treasurer = $makeUser('sub_office');
$intruder  = $makeUser('sub_member');

// --- Soumission -------------------------------------------------------------
echo "\n--- Soumission du dossier ---\n";

$answers = [
    'origine_adhesion'       => 'nokia',
    'jeune'                  => 'non',
    'assurance_individuelle' => 'loisir2',
    'niveau_prepare'         => 'p2',
    'carte_niveau'           => 'oui',
    'pret_bloc'              => 'oui',
    'pret_detendeur'         => 'oui',
    'pret_gilet'             => 'non',
];

$applicationId = $service->submit($member, $campaignId, 'plongee', $answers);
$application   = $service->find($applicationId);

$check('Dossier créé', $applicationId > 0, $application['reference']);
$check('Montant figé à 273,00 €', abs((float) $application['total_amount'] - 273.00) < 0.005, $application['total_amount'] . ' €');
$check('Statut « en attente de paiement »', $application['status'] === ApplicationService::STATUS_AWAITING_PAYMENT);
$check('Lignes figées enregistrées', count($service->lines($applicationId)) === 6);

// Une réponse obligatoire manquante doit bloquer.
try {
    $service->submit($member, $campaignId, 'plongee', ['jeune' => 'non']);
    $check('Refus si réponse obligatoire manquante', false);
} catch (RuntimeException $e) {
    $check('Refus si réponse obligatoire manquante', true, $e->getMessage());
}

// --- Contrôles de droits ----------------------------------------------------
echo "\n--- Contrôles de droits ---\n";

try {
    $service->recordPayment($applicationId, 273.00, 'cheque', null, $intruder);
    $check('Un membre ne peut pas enregistrer un paiement', false);
} catch (RuntimeException $e) {
    $check('Un membre ne peut pas enregistrer un paiement', true, $e->getMessage());
}

try {
    $service->validateSecretariat($applicationId, $treasurer);
    $check('Validation refusée avant paiement confirmé', false);
} catch (RuntimeException $e) {
    $check('Validation refusée avant paiement confirmé', true, $e->getMessage());
}

// --- Paiement puis validation ------------------------------------------------
echo "\n--- Paiement et validation ---\n";

$service->recordPayment($applicationId, 273.00, 'helloasso', '2026-09-20', $treasurer, 'HA-2026-0042');
$check('Paiement confirmé', $service->find($applicationId)['status'] === ApplicationService::STATUS_PAYMENT_CONFIRMED);

$service->validateSecretariat($applicationId, $treasurer, 'Pièces conformes.');
$application = $service->find($applicationId);
$check('Dossier actif', $application['status'] === ApplicationService::STATUS_ACTIVE);
$check('Date d’activation renseignée', !empty($application['activated_at']));

// --- Effets sur le compte ----------------------------------------------------
echo "\n--- Effets sur le compte du membre ---\n";

$d = $policy->hasActiveMembership($member);
$check('Adhésion reconnue active', $d->allowed, $d->reason);

$rights = (array) get_user_meta($member, 'sub_lending_rights', true);
sort($rights);
$check('Droits d’emprunt ouverts : bloc + détendeur', $rights === ['bloc', 'detendeur'], implode(', ', $rights));

$d = $policy->hasLendingRight($member, 'detendeur');
$check('Emprunt détendeur autorisé', $d->allowed, $d->reason);

$d = $policy->hasLendingRight($member, 'gilet');
$check('Emprunt gilet refusé (option non prise)', !$d->allowed, $d->reason);

// --- Traçabilité --------------------------------------------------------------
echo "\n--- Traçabilité ---\n";

global $wpdb;
$validations = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sub_validations WHERE application_id = %d",
    $applicationId
));
$check('3 étapes tracées', $validations === 3, "{$validations} enregistrements");

$audits = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sub_audit_log WHERE entity_id = %d AND entity_type = 'application'",
    $applicationId
));
$check('Journal d’audit alimenté', $audits >= 3, "{$audits} entrées");

// --- Nettoyage ----------------------------------------------------------------
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ([$member, $treasurer, $intruder] as $id) {
    wp_delete_user($id);
}

// Le dossier de test part avec sa campagne : il n'a rien à faire dans les
// statistiques du club.
sub_test_drop_campaign($campaignId);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
