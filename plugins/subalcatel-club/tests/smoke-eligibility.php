<?php
/**
 * Test de fumée d'EligibilityPolicy.
 *
 * À lancer avec :
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-eligibility.php
 *
 * Provisoire : sera remplacé par des tests PHPUnit une fois le module
 * Adhésions en place. En attendant, il vérifie que les règles se comportent
 * comme annoncé et que les motifs de refus sont exploitables.
 *
 * Note : pas de `declare(strict_types=1)` ici — `wp eval-file` évalue le
 * fichier, et la déclaration doit être la première instruction d'un script.
 * Le code du plugin, lui, est bien en types stricts.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Policy\EligibilityPolicy;

$policy   = new EligibilityPolicy();
$failures = 0;

$check = static function (string $label, bool $actual, bool $expected, string $reason = '') use (&$failures): void {
    $ok = $actual === $expected;
    $failures += $ok ? 0 : 1;
    printf(
        "%s  %-52s %s\n",
        $ok ? ' OK ' : 'FAIL',
        $label,
        $reason !== '' ? "→ {$reason}" : ''
    );
};

// --- Jeu d'essai : un plongeur autonome à jour --------------------------------
$userId = wp_insert_user([
    'user_login' => 'demo_plongeur_' . wp_generate_password(6, false),
    'user_pass'  => wp_generate_password(),
    'role'       => 'sub_member',
]);

if (is_wp_error($userId)) {
    exit("Impossible de créer l'utilisateur de test : " . $userId->get_error_message() . "\n");
}

$p3 = get_term_by('slug', 'p3', DiveLevels::TAXONOMY);
update_user_meta($userId, 'sub_dive_level_id', $p3->term_id);
sub_test_make_compliant($userId);

echo "\n--- Plongeur P3, adhésion et documents à jour ---\n";

$d = $policy->canRegisterForDive($userId, ['p3', 'p4', 'p5']);
$check('Inscription à une plongée niveau P3+', $d->allowed, true, $d->reason);

$check('Reconnu plongeur autonome', $policy->isAutonomousDiver($userId), true);
$check('Non reconnu directeur de plongée', $policy->isDiveLeader($userId), false);

$d = $policy->meetsDiveLevel($userId, ['p5', 'e3']);
$check('Refus si niveau P5 exigé', $d->allowed, false, $d->reason);

// --- Le niveau est un rang, pas une étiquette --------------------------------
//
// Ces contrôles manquaient, et la règle comparait une appartenance à une liste.
// Deux conséquences, toutes deux fausses au regard du Code du sport : un P5 se
// voyait refuser une plongée ouverte au P2 — alors qu'il exerce tout ce
// qu'exerce un P2 — et un « P5/E2 » était refusé partout, son libellé ne
// figurant dans aucune liste bien qu'il soit P5.
echo "\n--- Hiérarchie des niveaux et doubles niveaux ---\n";

$niveauDe = static function (int $user, string $slug): void {
    $term = get_term_by('slug', $slug, DiveLevels::TAXONOMY);
    update_user_meta($user, 'sub_dive_level_id', $term->term_id);
};

$cas = [
    // [niveau du membre, niveaux exigés, admis ?, libellé]
    ['p5-e2', ['p1', 'p3', 'p5'], true,  'Un P5/E2 sur une plongée P1/P3/P5'],
    ['p5',    ['p2'],             true,  'Un P5 sur une plongée ouverte au P2'],
    ['p2',    ['p3'],             false, 'Un P2 sur une plongée P3 : refusé'],
    ['p3',    ['p3'],             true,  'Pile au niveau demandé'],
    ['e3',    ['p4'],             true,  'Un E3 est de fait P5'],
    ['p4-e2', ['p3'],             true,  'Un P4/E2 vaut son rang de plongeur'],
    ['p5',    ['p2-e1'],          false, 'Sortie d’encadrement : un P5 sans brevet E'],
    ['p2-e1', ['p2-e1'],          true,  'Sortie d’encadrement : un E1'],
    ['e4',    ['p2-e1'],          true,  'Sortie d’encadrement : un E4 dépasse E1'],
];

foreach ($cas as [$slug, $exiges, $admis, $libelle]) {
    $niveauDe($userId, $slug);
    $d = $policy->meetsDiveLevel($userId, $exiges);
    $check($libelle, $d->allowed, $admis, $d->reason);
}

// Le message ne doit citer que le plancher : annoncer « P1, P3, P5 » à un P0
// laisse croire qu'il lui manque trois brevets.
$niveauDe($userId, 'p0');
$d = $policy->meetsDiveLevel($userId, ['p1', 'p3', 'p5']);
$check('Le refus ne cite que le niveau plancher',
    str_contains($d->reason, 'P1') && !str_contains($d->reason, 'P3'),
    true, $d->reason);

$niveauDe($userId, 'p3');

// --- Certificat médical expiré -------------------------------------------------
echo "\n--- Le certificat médical expire ---\n";
global $wpdb;
$wpdb->query($wpdb->prepare(
    "UPDATE {$wpdb->prefix}sub_member_documents SET valid_until = '2026-01-10'
     WHERE user_id = %d AND type_slug = 'certificat-medical'",
    $userId
));

$d = $policy->canRegisterForDive($userId, ['p3']);
$check('Inscription bloquée', $d->allowed, false, $d->reason);

// --- Adhésion expirée : elle prime sur le reste ---------------------------------
echo "\n--- L'adhésion expire aussi ---\n";
update_user_meta($userId, 'sub_membership_valid_until', '2026-06-30');

$d = $policy->canRegisterForDive($userId, ['p3']);
$check('Motif = adhésion, pas certificat', str_contains($d->reason, 'adhésion'), true, $d->reason);

// --- Droit d'emprunt ------------------------------------------------------------
echo "\n--- Droits d'emprunt ---\n";
update_user_meta($userId, 'sub_membership_valid_until', '2027-12-31');
update_user_meta($userId, 'sub_lending_rights', ['detendeur', 'gilet']);

$d = $policy->hasLendingRight($userId, 'detendeur');
$check('Détendeur autorisé (option souscrite)', $d->allowed, true, $d->reason);

$d = $policy->hasLendingRight($userId, 'bloc');
$check('Bloc refusé (option non souscrite)', $d->allowed, false, $d->reason);

// --- Nettoyage ------------------------------------------------------------------
sub_test_clean_documents($userId);
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($userId);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
