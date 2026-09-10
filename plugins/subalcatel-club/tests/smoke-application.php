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
use Subalcatel\Club\Membership\IncompleteApplication;
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

// La carte de niveau n'est plus une réponse : elle s'ajoute d'elle-même dès
// qu'un niveau est préparé, et le total doit rester le même qu'avant.
$answers = [
    'origine_adhesion'       => 'nokia',
    'assurance_individuelle' => 'loisir2',
    'niveau_prepare'         => 'p2',
    'pret_bloc'              => 'oui',
    'pret_detendeur'         => 'oui',
    'pret_gilet'             => 'non',
];

// Un dossier sans état civil ne produit pas de licence : il est refusé avant
// même d'être chiffré.
try {
    $service->submit($member, $campaignId, 'plongee', $answers, 'cheque');
    $check('Refus si l’état civil est incomplet', false);
} catch (IncompleteApplication $e) {
    $check('Refus si l’état civil est incomplet', true, $e->getMessage());
}

sub_test_complete_identity($member);

// Un mode de règlement que le club n'accepte pas n'entre pas non plus.
try {
    $service->submit($member, $campaignId, 'plongee', $answers, 'especes');
    $check('Refus des espèces', false);
} catch (RuntimeException $e) {
    $check('Refus des espèces', true, $e->getMessage());
}

$applicationId = $service->submit($member, $campaignId, 'plongee', $answers, 'cheque');
$application   = $service->find($applicationId);

$check('Dossier créé', $applicationId > 0, $application['reference']);
$check('Montant figé à 273,00 €', abs((float) $application['total_amount'] - 273.00) < 0.005, $application['total_amount'] . ' €');
$check('La carte de niveau est facturée sans avoir été demandée',
    in_array('carte_niveau', array_column($service->lines($applicationId), 'source_name'), true));
$check('Le mode de règlement choisi est conservé',
    $application['payment_method'] === 'cheque', (string) $application['payment_method']);
$check('Statut « en attente de paiement »', $application['status'] === ApplicationService::STATUS_AWAITING_PAYMENT);
$check('Lignes figées enregistrées', count($service->lines($applicationId)) === 6);

// Une réponse obligatoire manquante doit bloquer, et dire laquelle.
try {
    $service->submit($member, $campaignId, 'plongee', ['origine_adhesion' => 'nokia'], 'cheque');
    $check('Refus si réponse obligatoire manquante', false);
} catch (IncompleteApplication $e) {
    $check('Refus si réponse obligatoire manquante',
        array_key_exists('pret_bloc', $e->fields), $e->getMessage());
}

// Le bloc de l'encadrant ne coûte rien, mais ouvre le droit d'emprunt.
$encadrant = $makeUser('sub_member');
sub_test_complete_identity($encadrant);

$encadrantId = $service->submit($encadrant, $campaignId, 'plongee', [
    'origine_adhesion'       => 'exterieur',
    'assurance_individuelle' => 'aucune',
    'niveau_prepare'         => 'aucun',
    'pret_bloc'              => 'encadrant',
    'pret_detendeur'         => 'non',
    'pret_gilet'             => 'non',
], 'helloasso');

$service->recordPayment($encadrantId, (float) $service->find($encadrantId)['total_amount'], 'helloasso', null, $treasurer);
$service->validateSecretariat($encadrantId, $treasurer);

$check('Le bloc de l’encadrant ne se facture pas',
    abs((float) $service->find($encadrantId)['total_amount'] - 210.00) < 0.005,
    $service->find($encadrantId)['total_amount'] . ' €');
$check('… mais ouvre quand même le droit d’emprunt',
    in_array('bloc', (array) get_user_meta($encadrant, 'sub_lending_rights', true), true));

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
foreach ([$member, $treasurer, $intruder, $encadrant] as $id) {
    wp_delete_user($id);
}

// Le dossier de test part avec sa campagne : il n'a rien à faire dans les
// statistiques du club.
sub_test_drop_campaign($campaignId);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
