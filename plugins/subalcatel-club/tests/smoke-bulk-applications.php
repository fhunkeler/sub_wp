<?php
/**
 * Test de fumée des actions groupées sur les dossiers d'adhésion.
 *
 *   docker exec sub_demo_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-bulk-applications.php
 *
 * Vérifie qu'une sélection mêlant les étapes ne fait avancer que les dossiers
 * à l'étape visée, nomme les autres, et passe par les mêmes contrôles qu'un
 * dossier traité seul — droits et plafond d'envoi compris.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Admin\ApplicationsScreen;
use Subalcatel\Club\Membership\ApplicationService;
use Subalcatel\Club\Notifications\SendQuota;

$campaignId = sub_test_pricing_campaign();
$service    = new ApplicationService();
$failures   = 0;

$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-54s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$users = [];

$makeUser = static function (string $role) use (&$users): int {
    $id = wp_insert_user([
        'user_login' => 'demo_' . wp_generate_password(8, false),
        'user_email' => 'bulk_' . wp_generate_password(8, false) . '@example.test',
        'user_pass'  => wp_generate_password(),
        'role'       => $role,
    ]);
    $users[] = $id;

    return is_wp_error($id) ? 0 : $id;
};

$answers = [
    'origine_adhesion'       => 'nokia',
    'assurance_individuelle' => 'loisir2',
    'niveau_prepare'         => 'p2',
    'pret_bloc'              => 'oui',
    'pret_detendeur'         => 'oui',
    'pret_gilet'             => 'non',
];

$submit = static function () use ($makeUser, $service, $campaignId, $answers): int {
    $member = $makeUser('sub_member');
    sub_test_complete_identity($member);

    return $service->submit($member, $campaignId, 'plongee', $answers, 'cheque');
};

$office = $makeUser('sub_office');
$member = $makeUser('sub_member');
$status = static fn (int $id): string => (string) $service->find($id)['status'];

$a = $submit();
$b = $submit();
$c = $submit();

// --- Paiements --------------------------------------------------------------
echo "\n--- Paiements reçus en lot ---\n";

$result = ApplicationsScreen::applyBulk(ApplicationsScreen::BULK_PAYMENT, [$a, $b], $office);

$check('Deux paiements enregistrés', $result['done'] === 2, (string) $result['done']);
$check('Les deux dossiers passent à « paiement confirmé »',
    $status($a) === ApplicationService::STATUS_PAYMENT_CONFIRMED
    && $status($b) === ApplicationService::STATUS_PAYMENT_CONFIRMED);
$check('Le montant encaissé est le montant dû',
    abs($service->paidAmount($a) - (float) $service->find($a)['total_amount']) < 0.005);

// --- Validation, sélection mêlée -------------------------------------------
echo "\n--- Validation d'une sélection mêlée ---\n";

$result = ApplicationsScreen::applyBulk(ApplicationsScreen::BULK_VALIDATE, [$a, $b, $c], $office, 'Lot du jeudi');

$check('Seuls les dossiers payés sont validés', $result['done'] === 2, (string) $result['done']);
$check('Le dossier non payé est écarté et nommé',
    $result['skipped'] === [(string) $service->find($c)['reference']], implode(', ', $result['skipped']));
$check('Les dossiers validés sont actifs',
    $status($a) === ApplicationService::STATUS_ACTIVE && $status($b) === ApplicationService::STATUS_ACTIVE);
$check('Le dossier écarté n’a pas bougé', $status($c) === ApplicationService::STATUS_AWAITING_PAYMENT);

// --- Droits ----------------------------------------------------------------
echo "\n--- Droits ---\n";

$result = ApplicationsScreen::applyBulk(ApplicationsScreen::BULK_PAYMENT, [$c], $member);

$check('Sans droit trésorerie, le paiement échoue', $result['done'] === 0 && count($result['failed']) === 1,
    implode(' ; ', $result['failed']));
$check('… et le dossier reste en attente', $status($c) === ApplicationService::STATUS_AWAITING_PAYMENT);

// --- Plafond d'envoi -------------------------------------------------------
echo "\n--- Plafond d'envoi ---\n";

$quota = SendQuota::settings();
SendQuota::save(1, 1);

try {
    ApplicationsScreen::applyBulk(ApplicationsScreen::BULK_CANCEL, [$c], $office);
    $check('Un lot qui dépasse le plafond est refusé', false);
} catch (RuntimeException $e) {
    $check('Un lot qui dépasse le plafond est refusé', true, $e->getMessage());
}

$check('… sans avoir touché au dossier', $status($c) === ApplicationService::STATUS_AWAITING_PAYMENT);

SendQuota::save($quota['limit'], $quota['reserve']);

// --- Annulation ------------------------------------------------------------
echo "\n--- Annulation en lot ---\n";

$result = ApplicationsScreen::applyBulk(ApplicationsScreen::BULK_CANCEL, [$a, $c], $office, 'Doublon');

$check('Seul le dossier non activé est annulé', $result['done'] === 1, (string) $result['done']);
$check('Le dossier actif est écarté', count($result['skipped']) === 1);
$check('Statut « annulée »', $status($c) === ApplicationService::STATUS_CANCELLED);
$check('L’adhésion active reste active', $status($a) === ApplicationService::STATUS_ACTIVE);

try {
    ApplicationsScreen::applyBulk('supprimer', [$a], $office);
    $check('Une action inconnue est refusée', false);
} catch (RuntimeException $e) {
    $check('Une action inconnue est refusée', true, $e->getMessage());
}

// --- Nettoyage ---------------------------------------------------------------
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ($users as $id) {
    wp_delete_user($id);
}

sub_test_drop_campaign($campaignId);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
