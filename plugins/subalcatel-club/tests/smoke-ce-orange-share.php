<?php
/**
 * Test de fumée — part du CE Orange et part de l'adhérent.
 *
 *   docker exec sub_demo_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-ce-orange-share.php
 *
 * Réglé par le CE Orange, un dossier garde son total — ce que le club
 * encaisse — mais dit en plus ce que le CE prend en charge et ce qui reste à
 * l'adhérent : la carte de niveau et l'assurance, réglées dans la boutique CE.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Frontend\QuoteEndpoint;
use Subalcatel\Club\Membership\CampaignRepository;
use Subalcatel\Club\Membership\CeOrangeShare;
use Subalcatel\Club\Membership\PricingEngine;

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-60s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};
$same = static fn (float $a, float $b): bool => abs($a - $b) < 0.005;

$campaignId = sub_test_pricing_campaign();
$repo       = new CampaignRepository();
$plan       = $repo->planBySlug($campaignId, 'plongee');

$answers = [
    'origine_adhesion'       => 'ce_orange',
    'assurance_individuelle' => 'loisir2',  // 29 €
    'niveau_prepare'         => 'p1',       // carte de niveau : 16 €
    'pret_bloc'              => 'oui',      // 36 €, pris par le CE
];

// --- Devis en cours de saisie --------------------------------------------------
echo "\n--- Devis ---\n";

$quote = (new PricingEngine())->calculate($plan, $answers, $repo->options($campaignId), $repo->discountRules($campaignId));
$share = CeOrangeShare::fromQuote($quote);

$check('Le total ne bouge pas', $same($quote->total(), 291.0), (string) $quote->total());
$check('Carte et assurance restent à l’adhérent', $same($share['member'], 45.0), (string) $share['member']);
$check('Le CE prend tout le reste, prêt compris', $same($share['ce'], 246.0), (string) $share['ce']);
$check('Les deux parts refont le total', $same($share['ce'] + $share['member'], $quote->total()));

// --- Route du récapitulatif en direct -----------------------------------------
echo "\n--- Récapitulatif en direct ---\n";

$ask = static function (string $method) use ($campaignId, $answers): array {
    $request = new WP_REST_Request('POST', '/subalcatel/v1/quote');
    $request->set_param('campaign_id', $campaignId);
    $request->set_param('plan', 'plongee');
    $request->set_param('options', $answers);
    $request->set_param('payment_method', $method);

    return QuoteEndpoint::handle($request)->get_data();
};

$viaCe = $ask('ce_orange');
$check('Par le CE : la répartition est renvoyée', is_array($viaCe['share']));
$check('… avec les montants affichables',
    ($viaCe['share']['member_display'] ?? '') === '45,00 €' && ($viaCe['share']['ce_display'] ?? '') === '246,00 €',
    ($viaCe['share']['member_display'] ?? '?') . ' / ' . ($viaCe['share']['ce_display'] ?? '?'));
$check('… et le même total qu’ailleurs', $same((float) $viaCe['total'], 291.0));

$viaHelloAsso = $ask('helloasso');
$check('Par HelloAsso : pas de répartition', $viaHelloAsso['share'] === null);
$check('Sans mode choisi : pas de répartition', $ask('')['share'] === null);

// --- Dossier déposé, d'après ses lignes figées --------------------------------
echo "\n--- Dossier déposé ---\n";

$stored = [
    ['line_type' => 'plan',     'source_name' => 'plongee',                'amount' => '147.00'],
    ['line_type' => 'option',   'source_name' => 'assurance_individuelle', 'amount' => '30.00'],
    ['line_type' => 'option',   'source_name' => 'carte_niveau',           'amount' => '16.00'],
    ['line_type' => 'discount', 'source_name' => 'origine_adhesion',       'amount' => '-23.00'],
];
$fromRows = CeOrangeShare::fromStoredLines(170.0, $stored);
$check('Lignes figées : la part de l’adhérent', $same($fromRows['member'], 46.0), (string) $fromRows['member']);
$check('Lignes figées : la remise réduit la part du CE', $same($fromRows['ce'], 124.0), (string) $fromRows['ce']);

$noExtra = CeOrangeShare::fromStoredLines(124.0, array_slice($stored, 0, 1));
$check('Ni carte ni assurance : le CE prend tout', $same($noExtra['ce'], 124.0) && $same($noExtra['member'], 0.0));

// Remises plus fortes que la formule : le total est ramené à 0, la part de
// l'adhérent ne peut pas le dépasser.
$capped = CeOrangeShare::fromStoredLines(10.0, [
    ['line_type' => 'option', 'source_name' => 'assurance_individuelle', 'amount' => '25.00'],
]);
$check('La part de l’adhérent ne dépasse pas le total',
    $same($capped['member'], 10.0) && $same($capped['ce'], 0.0));

// --- Ce qu'on dit à l'adhérent -------------------------------------------------
echo "\n--- Phrase ---\n";

$sentence = CeOrangeShare::sentence($fromRows);
$check('La phrase donne les deux montants',
    str_contains($sentence, '124,00 €') && str_contains($sentence, '46,00 €'), $sentence);
$check('… et où régler le reste', str_contains($sentence, 'boutique CE Orange'));
$check('Rien à régler : on le dit',
    str_contains(CeOrangeShare::sentence($noExtra), 'Rien ne reste à votre charge'));

sub_test_drop_campaign($campaignId);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
