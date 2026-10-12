<?php
/**
 * Test de fumée des filtres de la liste des dossiers d'adhésion.
 *
 *   docker exec sub_demo_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-application-filters.php
 *
 * La liste mêlait toutes les saisons, plafonnée à cent lignes : dès la
 * deuxième année, les dossiers en attente de la campagne en cours se perdaient
 * derrière ceux, actifs, de la précédente — et le plafond finissait par en
 * cacher. Ce qui se vérifie ici : chaque filtre isole ce qu'il annonce, et les
 * compteurs portent sur la campagne, pas sur les lignes affichées.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Admin\ApplicationsScreen;

global $wpdb;
$p = $wpdb->prefix . 'sub_';

$failures = 0;

$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-54s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$campagne = static function (string $slug, string $from) use ($wpdb, $p): int {
    $wpdb->insert("{$p}campaigns", [
        'title'       => 'Filtres ' . $slug,
        'slug'        => 'filtres-' . $slug,
        'opens_on'    => $from,
        'closes_on'   => $from,
        'valid_from'  => $from,
        'valid_until' => $from,
        'status'      => 'closed',
    ]);

    return (int) $wpdb->insert_id;
};

$dossier = static function (int $campaignId, string $status, string $reference) use ($wpdb, $p): void {
    $wpdb->insert("{$p}applications", [
        'reference'    => $reference,
        'user_id'      => null,
        'campaign_id'  => $campaignId,
        'plan_id'      => 0,
        'status'       => $status,
        'total_amount' => 10,
    ]);
};

$refs = static fn (array $rows): array => array_map(static fn (array $r): string => (string) $r['reference'], $rows);

$ancienne = $campagne('ancienne', '2001-09-01');
$recente  = $campagne('recente', '2002-09-01');

$dossier($ancienne, 'active', 'FILTRE-A1');
$dossier($ancienne, 'awaiting_payment', 'FILTRE-A2');
$dossier($recente, 'active', 'FILTRE-R1');
$dossier($recente, 'awaiting_payment', 'FILTRE-R2');
$dossier($recente, 'payment_confirmed', 'FILTRE-R3');
$dossier($recente, 'cancelled', 'FILTRE-R4');

echo "\n--- Filtre par campagne ---\n";
$rows = $refs(ApplicationsScreen::applications(['campaign' => $recente, 'state' => '', 'search' => 'FILTRE-']));
sort($rows);
$check('Seuls les dossiers de la campagne', $rows === ['FILTRE-R1', 'FILTRE-R2', 'FILTRE-R3', 'FILTRE-R4'], implode(',', $rows));

$rows = $refs(ApplicationsScreen::applications(['campaign' => 0, 'state' => '', 'search' => 'FILTRE-']));
$check('« Toutes » rend les deux campagnes', count($rows) === 6, (string) count($rows));

echo "\n--- Filtre par état ---\n";
$rows = $refs(ApplicationsScreen::applications(['campaign' => $recente, 'state' => ApplicationsScreen::PENDING, 'search' => 'FILTRE-']));
sort($rows);
$check('« À traiter » : paiement et validation', $rows === ['FILTRE-R2', 'FILTRE-R3'], implode(',', $rows));

$rows = $refs(ApplicationsScreen::applications(['campaign' => 0, 'state' => 'cancelled', 'search' => 'FILTRE-']));
$check('Un état précis', $rows === ['FILTRE-R4'], implode(',', $rows));

echo "\n--- Recherche ---\n";
$rows = $refs(ApplicationsScreen::applications(['campaign' => 0, 'state' => '', 'search' => 'FILTRE-A2']));
$check('Par référence', $rows === ['FILTRE-A2'], implode(',', $rows));

$rows = ApplicationsScreen::applications(['campaign' => 0, 'state' => '', 'search' => "FILTRE-%' OR 1=1 -- "]);
$check('Une recherche hostile ne rend rien', $rows === [], (string) count($rows));

echo "\n--- Compteurs et liste des campagnes ---\n";
$counts = ApplicationsScreen::stateCounts($recente);
$check('Compteurs limités à la campagne', ($counts['awaiting_payment'] ?? 0) === 1 && ($counts['active'] ?? 0) === 1);

$liste = [];
foreach (ApplicationsScreen::campaigns() as $c) {
    $liste[$c['id']] = $c;
}
$check('La campagne ancienne a 1 dossier à traiter', ($liste[$ancienne]['pending'] ?? -1) === 1);
$check('La récente en a 2, sur 4', ($liste[$recente]['pending'] ?? -1) === 2 && $liste[$recente]['total'] === 4);
$ids = array_keys($liste);
$check('La plus récente vient avant', array_search($recente, $ids, true) < array_search($ancienne, $ids, true));

echo "\n--- Filtres lus dans l'URL ---\n";
$_GET = ['campagne' => 'toutes', 'etat' => 'n-importe-quoi'];
$f    = ApplicationsScreen::currentFilters();
$check('« toutes » et un état inconnu ignoré', $f['campaign'] === 0 && $f['state'] === '');

$_GET = ['campagne' => (string) $ancienne, 'etat' => ApplicationsScreen::PENDING];
$f    = ApplicationsScreen::currentFilters();
$check('Campagne et état repris', $f['campaign'] === $ancienne && $f['state'] === ApplicationsScreen::PENDING);
$_GET = [];

// --- Nettoyage ----------------------------------------------------------------
sub_test_drop_campaign($ancienne);
sub_test_drop_campaign($recente);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
