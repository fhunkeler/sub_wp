<?php
/**
 * Test de fumée de la correction d'un dossier d'adhésion.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-amendment.php
 *
 * Le cas réel est celui-ci : l'adhérent oublie de déclarer le niveau qu'il
 * prépare, et la carte de niveau — due d'office dès qu'il y en a un — n'est pas
 * facturée. Le bureau corrigeait jusqu'ici le montant à la main, ce qui laissait
 * des lignes qui ne le totalisaient plus.
 *
 * On vérifie donc que la correction refait le calcul entier, remplace les lignes
 * figées, et s'arrête là où elle doit s'arrêter : pas sans droit, pas après
 * activation, et jamais en retirant une réponse obligatoire.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Membership\ApplicationService;
use Subalcatel\Club\Membership\IncompleteApplication;
use Subalcatel\Club\Notifications\EmailTemplates;

$campaignId = sub_test_pricing_campaign();
$service    = new ApplicationService();
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

$member   = $makeUser('sub_member');
$bureau   = $makeUser('sub_office');
$intruder = $makeUser('sub_member');

sub_test_complete_identity($member);

$total = static fn (int $id): float => (float) $service->find($id)['total_amount'];
$sources = static fn (int $id): array => array_column($service->lines($id), 'source_name');

// --- Le dossier tel que l'adhérent l'a déposé --------------------------------
echo "\n--- Dossier déposé, niveau préparé oublié ---\n";

$answers = [
    'origine_adhesion'       => 'nokia',
    'assurance_individuelle' => 'loisir2',
    'niveau_prepare'         => 'aucun',
    'pret_bloc'              => 'oui',
    'pret_detendeur'         => 'oui',
    'pret_gilet'             => 'non',
];

$applicationId = $service->submit($member, $campaignId, 'plongee', $answers, 'cheque');

$check('Dossier déposé à 257,00 €', abs($total($applicationId) - 257.00) < 0.005, $total($applicationId) . ' €');
$check('La carte de niveau n’est pas facturée', !in_array('carte_niveau', $sources($applicationId), true));

// --- Qui peut corriger --------------------------------------------------------
echo "\n--- Droits ---\n";

try {
    $service->amend($applicationId, $intruder, 'plongee', $answers, 'cheque');
    $check('Un membre ne peut pas corriger un dossier', false);
} catch (RuntimeException $e) {
    $check('Un membre ne peut pas corriger un dossier', true, $e->getMessage());
}

// --- La correction ------------------------------------------------------------
echo "\n--- Correction par le bureau ---\n";

$corrected = $service->amend(
    $applicationId,
    $bureau,
    'plongee',
    ['niveau_prepare' => 'p2'] + $answers,
    'cheque',
    'Niveau préparé oublié.'
);

$check('Nouveau total 273,00 €', abs($corrected - 273.00) < 0.005, $corrected . ' €');
$check('Le montant du dossier a suivi', abs($total($applicationId) - 273.00) < 0.005);
$check('La carte de niveau est désormais facturée',
    in_array('carte_niveau', $sources($applicationId), true));
$check('Les lignes sont remplacées, pas ajoutées', count($service->lines($applicationId)) === 6,
    count($service->lines($applicationId)) . ' lignes');
$check('Les réponses archivées ont suivi',
    ($service->answers($applicationId)['niveau_prepare'] ?? '') === 'p2');

// Une correction ne doit pas pouvoir produire un dossier que le formulaire
// d'adhésion aurait refusé.
try {
    $sansBloc = $answers;
    unset($sansBloc['pret_bloc']);
    $service->amend($applicationId, $bureau, 'plongee', $sansBloc, 'cheque');
    $check('Une réponse obligatoire ne peut pas être retirée', false);
} catch (IncompleteApplication $e) {
    $check('Une réponse obligatoire ne peut pas être retirée',
        array_key_exists('pret_bloc', $e->fields), $e->getMessage());
}

$check('Le dossier n’a pas bougé après un refus', abs($total($applicationId) - 273.00) < 0.005);

// --- Correction après encaissement -------------------------------------------
echo "\n--- Correction après encaissement ---\n";

$service->recordPayment($applicationId, 273.00, 'cheque', null, $bureau, 'CHQ-0042');

$apresPaiement = $service->amend(
    $applicationId,
    $bureau,
    'plongee',
    ['pret_detendeur' => 'non'] + ['niveau_prepare' => 'p2'] + $answers,
    'cheque',
    'Détendeur finalement non emprunté.'
);

$check('Le montant dû retombe à 219,00 €', abs($apresPaiement - 219.00) < 0.005, $apresPaiement . ' €');
$check('Le règlement encaissé n’est pas touché',
    abs($service->paidAmount($applicationId) - 273.00) < 0.005,
    $service->paidAmount($applicationId) . ' €');
$check('L’écart est visible : 54,00 € à rembourser',
    abs(($service->paidAmount($applicationId) - $apresPaiement) - 54.00) < 0.005);
$check('Le dossier reste au stade « paiement confirmé »',
    $service->find($applicationId)['status'] === ApplicationService::STATUS_PAYMENT_CONFIRMED);

// --- Après activation ---------------------------------------------------------
echo "\n--- Après activation ---\n";

$service->validateSecretariat($applicationId, $bureau, 'Pièces conformes.');

try {
    $service->amend($applicationId, $bureau, 'plongee', $answers, 'cheque');
    $check('Un dossier actif ne se corrige plus', false);
} catch (RuntimeException $e) {
    $check('Un dossier actif ne se corrige plus', true, $e->getMessage());
}

// --- Traçabilité --------------------------------------------------------------
echo "\n--- Traçabilité ---\n";

global $wpdb;

$corrections = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sub_validations
     WHERE application_id = %d AND step = 'correction'",
    $applicationId
));
$check('Les deux corrections sont tracées', $corrections === 2, "{$corrections} enregistrement(s)");

$motif = (string) $wpdb->get_var($wpdb->prepare(
    "SELECT comment FROM {$wpdb->prefix}sub_validations
     WHERE application_id = %d AND step = 'correction' ORDER BY id ASC LIMIT 1",
    $applicationId
));
$check('Le motif est conservé', $motif === 'Niveau préparé oublié.', $motif);

$audits = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sub_audit_log
     WHERE entity_type = 'application' AND entity_id = %d AND action = 'membership.amended'",
    $applicationId
));
$check('Journal d’audit alimenté', $audits === 2, "{$audits} entrée(s)");

// Le modèle de courriel doit exister dans les installations déjà en service :
// un modèle absent ne lève pas d'erreur, il fait taire l'envoi.
EmailTemplates::seedIfNeeded();
$check('Le modèle « dossier corrigé » est installé',
    EmailTemplates::find(EmailTemplates::MEMBERSHIP_AMENDED) !== null);

// --- Nettoyage ----------------------------------------------------------------
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ([$member, $bureau, $intruder] as $id) {
    wp_delete_user($id);
}

sub_test_drop_campaign($campaignId);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
