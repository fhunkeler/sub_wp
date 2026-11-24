<?php
/**
 * Test de fumée — part d'un tiers payeur et part de l'adhérent.
 *
 *   docker exec sub_demo_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-third-party-share.php
 *
 * Réglé par un tiers payeur (un CE…), un dossier garde son total — ce que le
 * club encaisse — mais dit en plus ce que le tiers prend en charge et ce qui
 * reste à l'adhérent : ici, la carte de niveau et l'assurance.
 *
 * Le mode de règlement est déclaré par le test lui-même, par le filtre que
 * les profils de club utilisent : le cœur se vérifie sans aucun profil. Le CE
 * Orange de Sub Alcatel a son propre test, dans `subalcatel-profil`.
 *
 * Vérifie aussi la confirmation affichée juste après le dépôt, qui ne
 * paraissait plus depuis que le dossier remplace le formulaire.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Membership\Frontend\QuoteEndpoint;
use Subalcatel\Club\Membership\ApplicationService;
use Subalcatel\Club\Membership\CampaignRepository;
use Subalcatel\Club\Membership\PaymentMethods;
use Subalcatel\Club\Membership\PricingEngine;
use Subalcatel\Club\Membership\ThirdPartyShare;

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-60s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};
$same = static fn (float $a, float $b): bool => abs($a - $b) < 0.005;

const SUB_TEST_METHOD = 'tiers_test';

$declare = static fn (array $modes): array => $modes + [
    SUB_TEST_METHOD => [
        'label'       => 'CE du test',
        'sentence'    => 'Votre règlement passe par le CE du test.',
        'third_party' => [
            'payer'          => 'Le CE du test',
            'by'             => 'par le CE du test',
            'member_options' => ['carte_niveau', 'assurance_individuelle'],
            'member_label'   => 'carte de niveau et assurance',
            'member_where'   => 'dans la boutique du test',
        ],
    ],
];
add_filter(PaymentMethods::FILTER, $declare);

// --- Déclaration ---------------------------------------------------------------
echo "\n--- Déclaration ---\n";

$tiers = ThirdPartyShare::forMethod(SUB_TEST_METHOD);
$check('Le mode déclaré par filtre est proposé', PaymentMethods::isOffered(SUB_TEST_METHOD));
$check('… avec son tiers payeur', $tiers !== null && $tiers->payer === 'Le CE du test');
$check('Un mode sans tiers payeur n’en a pas', ThirdPartyShare::forMethod('helloasso') === null
    && ThirdPartyShare::forMethod('cheque') === null && ThirdPartyShare::forMethod('inconnu') === null);
$check('L’intitulé de la ligne du tiers', $tiers->payerLine() === 'Pris en charge par le CE du test');
$check('L’aide sur la part de l’adhérent',
    $tiers->memberHelp() === 'Carte de niveau et assurance, à régler dans la boutique du test une fois le dossier déposé.',
    $tiers->memberHelp());

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
$share = $tiers->fromQuote($quote);

$check('Le total ne bouge pas', $same($quote->total(), 291.0), (string) $quote->total());
$check('Carte et assurance restent à l’adhérent', $same($share['member'], 45.0), (string) $share['member']);
$check('Le tiers prend tout le reste, prêt compris', $same($share['payer'], 246.0), (string) $share['payer']);
$check('Les deux parts refont le total', $same($share['payer'] + $share['member'], $quote->total()));

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

$viaCe = $ask(SUB_TEST_METHOD);
$check('Par le tiers : la répartition est renvoyée', is_array($viaCe['share']));
$check('… avec les montants affichables',
    ($viaCe['share']['member_display'] ?? '') === '45,00 €' && ($viaCe['share']['payer_display'] ?? '') === '246,00 €',
    ($viaCe['share']['member_display'] ?? '?') . ' / ' . ($viaCe['share']['payer_display'] ?? '?'));
$check('… et les intitulés du tiers',
    ($viaCe['share']['payer_label'] ?? '') === 'Pris en charge par le CE du test'
        && str_contains((string) ($viaCe['share']['help'] ?? ''), 'boutique du test'));
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
$fromRows = $tiers->fromStoredLines(170.0, $stored);
$check('Lignes figées : la part de l’adhérent', $same($fromRows['member'], 46.0), (string) $fromRows['member']);
$check('Lignes figées : la remise réduit la part du tiers', $same($fromRows['payer'], 124.0), (string) $fromRows['payer']);

$noExtra = $tiers->fromStoredLines(124.0, array_slice($stored, 0, 1));
$check('Ni carte ni assurance : le tiers prend tout', $same($noExtra['payer'], 124.0) && $same($noExtra['member'], 0.0));

// Remises plus fortes que la formule : le total est ramené à 0, la part de
// l'adhérent ne peut pas le dépasser.
$capped = $tiers->fromStoredLines(10.0, [
    ['line_type' => 'option', 'source_name' => 'assurance_individuelle', 'amount' => '25.00'],
]);
$check('La part de l’adhérent ne dépasse pas le total',
    $same($capped['member'], 10.0) && $same($capped['payer'], 0.0));

// --- Ce qu'on dit à l'adhérent -------------------------------------------------
echo "\n--- Phrase ---\n";

$sentence = $tiers->sentence($fromRows);
$check('La phrase donne les deux montants',
    str_contains($sentence, '124,00 €') && str_contains($sentence, '46,00 €'), $sentence);
$check('… et où régler le reste', str_contains($sentence, 'à régler dans la boutique du test'), $sentence);
$check('Rien à régler : on le dit',
    str_contains($tiers->sentence($noExtra), 'Rien ne reste à votre charge'));

// --- Juste après le dépôt -----------------------------------------------------
echo "\n--- Retour de dépôt ---\n";

global $wpdb;
$p = $wpdb->prefix . 'sub_';

// Seule la campagne de test est ouverte le temps du contrôle : le formulaire
// prend LA campagne ouverte, et d'autres suites en laissent.
$otherOpen = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}campaigns WHERE status = 'open'"));
foreach ($otherOpen as $other) {
    $wpdb->update("{$p}campaigns", ['status' => 'draft'], ['id' => $other]);
}
$wpdb->update("{$p}campaigns", ['status' => 'open'], ['id' => $campaignId]);

$member = (int) wp_insert_user([
    'user_login' => 'demo_' . wp_generate_password(8, false),
    'user_pass'  => wp_generate_password(),
    'role'       => 'sub_member',
]);
sub_test_complete_identity($member);

$applicationId = (new ApplicationService())->submit($member, $campaignId, 'plongee', $answers + [
    'pret_detendeur' => 'non',
    'pret_gilet'     => 'non',
], SUB_TEST_METHOD);
$reference = (string) ((new ApplicationService())->find($applicationId)['reference'] ?? '');

wp_set_current_user($member);

$_GET['sub_application'] = (string) $applicationId;
$back = do_shortcode('[subalcatel_adhesion]');

$check('Après le dépôt : la confirmation s’affiche',
    str_contains($back, 'Dossier ' . $reference . ' enregistré'), $reference);
$check('… avec le montant et le mode', str_contains($back, '291,00 €') && str_contains($back, 'CE du test'));
$check('… la répartition', str_contains($back, 'Reste à votre charge : 45,00 €'));
$check('… et les consignes de règlement', str_contains($back, 'passe par le CE du test'));
$check('Pas « vous avez déjà un dossier » à ce moment-là', !str_contains($back, 'déjà un dossier'));

$_GET['sub_application'] = '999999999';
$check('Un autre numéro : le récapitulatif ordinaire',
    str_contains(do_shortcode('[subalcatel_adhesion]'), 'déjà un dossier'));

unset($_GET['sub_application']);
$later = do_shortcode('[subalcatel_adhesion]');
$check('Revenu plus tard : le récapitulatif ordinaire', str_contains($later, 'déjà un dossier')
    && !str_contains($later, 'enregistré</strong>'));
$check('… qui garde la répartition', str_contains($later, 'Reste à votre charge : 45,00 €'));

wp_set_current_user(0);
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($member);
foreach ($otherOpen as $other) {
    $wpdb->update("{$p}campaigns", ['status' => 'open'], ['id' => $other]);
}

sub_test_drop_campaign($campaignId);
remove_filter(PaymentMethods::FILTER, $declare);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
