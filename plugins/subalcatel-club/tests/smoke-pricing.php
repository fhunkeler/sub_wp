<?php
/**
 * Test de fumée du moteur de tarification.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-pricing.php
 *
 * Le cas décisif est la remise Nokia : le résultat doit être identique à celui
 * de la formule OSMembership d'origine, dont ce moteur se passe.
 *
 * Les scénarios tournent sur une campagne créée par le test, aux montants
 * figés : ce qui est vérifié ici est le calcul, pas le tarif de la saison. La
 * grille publique, elle, est bien contrôlée sur la campagne du club — c'est
 * précisément son affaire de lire les vrais montants.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Membership\CampaignRepository;
use Subalcatel\Club\Membership\DemoSeeder;
use Subalcatel\Club\Membership\PricingEngine;

$campaignId = sub_test_pricing_campaign();
$repo       = new CampaignRepository();
$engine     = new PricingEngine();

$options  = $repo->options($campaignId);
$rules    = $repo->discountRules($campaignId);
$failures = 0;

$scenario = static function (
    string $title,
    string $planSlug,
    array $answers,
    float $expected
) use ($repo, $engine, $campaignId, $options, $rules, &$failures): void {
    $plan  = $repo->planBySlug($campaignId, $planSlug);
    $quote = $engine->calculate($plan, $answers, $options, $rules);
    $ok    = abs($quote->total() - $expected) < 0.005;
    $failures += $ok ? 0 : 1;

    printf("\n%s %s\n", $ok ? '[ OK ]' : '[FAIL]', $title);
    echo $quote->toText();

    if (!$ok) {
        printf("       attendu : %s €\n", number_format($expected, 2, ',', ' '));
    }
};

// ---------------------------------------------------------------------------
// 1. Extérieur débutant : plan seul, aucune option payante.
// ---------------------------------------------------------------------------
$scenario(
    'Extérieur débutant — plan Plongée nu',
    'plongee',
    [
        'origine_adhesion'       => 'exterieur',
        'jeune'                  => 'non',
        'assurance_individuelle' => 'aucune',
        'niveau_prepare'         => 'aucun',
    ],
    210.00
);

// ---------------------------------------------------------------------------
// 2. Le cas décisif — adhérent Nokia préparant un P2, avec bloc et détendeur.
//
//    Formule OSMembership d'origine :
//      -58.00 - [PRET_BLOC]*14/36 - [PRET_DETENDEUR]*0.40 - [PRET_GILET]*0.40
//      = -58 - 14 - 36 - 0 = -108.00
//
//    210 (plan) + 29 (assurance) + 16 (carte) + 36 (bloc) + 90 (détendeur)
//      = 381,00, puis -108,00 → 273,00 €
// ---------------------------------------------------------------------------
$scenario(
    'Nokia, prépare le P2, prêt bloc + détendeur',
    'plongee',
    [
        'origine_adhesion'       => 'nokia',
        'jeune'                  => 'non',
        'assurance_individuelle' => 'loisir2',
        'niveau_prepare'         => 'p2',
        'carte_niveau'           => 'oui',
        'pret_bloc'              => 'oui',
        'pret_detendeur'         => 'oui',
        'pret_gilet'             => 'non',
    ],
    273.00
);

// ---------------------------------------------------------------------------
// 3. Une remise ne s'applique qu'aux options réellement souscrites.
//    Même adhérent Nokia, sans aucun prêt : seul le forfait de -58 € s'applique.
// ---------------------------------------------------------------------------
$scenario(
    'Nokia sans prêt — seul le forfait s’applique',
    'plongee',
    [
        'origine_adhesion'       => 'nokia',
        'jeune'                  => 'non',
        'assurance_individuelle' => 'aucune',
        'niveau_prepare'         => 'aucun',
    ],
    152.00
);

// ---------------------------------------------------------------------------
// 4. Visibilité conditionnelle : « prêt ordinateur » n'existe pas pour un
//    adhérent qui ne prépare aucun niveau. Une réponse forgée est ignorée.
// ---------------------------------------------------------------------------
$scenario(
    'Option masquée non facturée, même si la réponse est forgée',
    'plongee',
    [
        'origine_adhesion'       => 'exterieur',
        'jeune'                  => 'non',
        'assurance_individuelle' => 'aucune',
        'niveau_prepare'         => 'aucun',
        'pret_ordinateur'        => 'oui',   // masquée : ne doit rien facturer
        'carte_niveau'           => 'oui',   // masquée aussi
    ],
    210.00
);

// ---------------------------------------------------------------------------
// 5. NAP Nokia avec piscine : 120 + 60 = 180, remise -23 - 24 = -47 → 133,00 €
// ---------------------------------------------------------------------------
$scenario(
    'Nokia, nage avec palmes et créneau piscine',
    'nap',
    [
        'origine_adhesion'       => 'nokia',
        'jeune'                  => 'non',
        'assurance_individuelle' => 'aucune',
        'piscine'                => 'oui',
    ],
    133.00
);

// ---------------------------------------------------------------------------
// 6. Une option réservée au plan NAP n'est pas facturée sur le plan Plongée.
// ---------------------------------------------------------------------------
$scenario(
    'Option réservée au plan NAP ignorée sur le plan Plongée',
    'plongee',
    [
        'origine_adhesion'       => 'exterieur',
        'jeune'                  => 'non',
        'assurance_individuelle' => 'aucune',
        'niveau_prepare'         => 'aucun',
        'piscine'                => 'oui',   // réservée à NAP
    ],
    210.00
);

// ---------------------------------------------------------------------------
// 7. Jeune avec licence déjà détenue : la moins-value est masquée pour un
//    jeune, elle ne doit pas s'appliquer. 210 + 18 = 228,00 €
// ---------------------------------------------------------------------------
$scenario(
    'Jeune — la moins-value licence reste masquée',
    'plongee',
    [
        'origine_adhesion'       => 'exterieur',
        'jeune'                  => 'oui',
        'assurance_individuelle' => 'aucune',
        'niveau_prepare'         => 'aucun',
        'moins_value_licence'    => 'oui',   // masquée pour un jeune
    ],
    228.00
);

// ---------------------------------------------------------------------------
// 8. Grille publique : elle lit la campagne configurée, sans recopie.
//
//    La campagne de test a fait son office ; on la retire avant d'interroger
//    la page publique, qui doit présenter celle du club.
// ---------------------------------------------------------------------------
sub_test_drop_campaign($campaignId);
DemoSeeder::run();

echo "\n--- Grille tarifaire publique ---\n";

$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-56s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$html = do_shortcode('[subalcatel_tarifs]');

$check('La grille est rendue', str_contains($html, 'sub-pricing'));

$repository = new \Subalcatel\Club\Membership\CampaignRepository();
$shown      = $repository->campaignToShow();

$check('Une campagne est présentée', $shown !== null, $shown['campaign']['title'] ?? '');
$check('La campagne ouverte est retenue', $shown['is_open'] === true);

// Le point de la manœuvre : les montants viennent de la base, pas d'une
// recopie. On les compare un à un.
foreach ($repository->plans((int) $shown['campaign']['id']) as $plan) {
    $formatted = number_format($plan->basePrice, 2, ',', ' ') . ' €';

    $check(
        sprintf('Le tarif de « %s » vient de la base', $plan->title),
        str_contains($html, $formatted),
        $formatted
    );
}

$options = $repository->options((int) $shown['campaign']['id']);
$missing = [];

foreach ($options as $option) {
    foreach ($option->choices as $choice) {
        $amount = (float) ($choice['amount'] ?? 0);

        if ($amount > 0 && !str_contains($html, number_format($amount, 2, ',', ' ') . ' €')) {
            $missing[] = $option->label;
        }
    }
}

$check('Chaque option payante est affichée', $missing === [],
    $missing === [] ? count($options) . ' option(s) parcourue(s)' : implode(', ', array_unique($missing)));

// Une question administrative n'est pas un tarif.
$check('Les questions sans montant sont écartées',
    !str_contains($html, 'Origine de l’adhésion</th>') && !str_contains($html, 'Niveau préparé</th>'));

// Décision du club : la page présente les formules et les options, pas les
// remises. Elles dépendent de la situation de chacun et restent appliquées
// automatiquement dans le formulaire d'adhésion.
$check('Aucune remise n’est détaillée',
    !str_contains($html, 'sub-pricing__discounts') && !str_contains($html, 'Les remises'),
    'elles dépendent de la situation de chacun');

foreach ($repository->discountRules((int) $shown['campaign']['id']) as $rule) {
    $check(
        sprintf('« %s » n’apparaît pas', $rule->label),
        !str_contains($html, $rule->label)
    );
}

$check('Une déduction se distingue d’un coût',
    str_contains($html, 'sub-pricing__amount--credit'),
    'la moins-value licence est négative');

$check('Le tableau peut défiler seul', str_contains($html, 'sub-scroll'),
    'sinon il déborde de la page sur téléphone');

// Entre deux campagnes, la page doit rester lisible.
global $wpdb;
$campaignId = (int) $shown['campaign']['id'];
$wpdb->update("{$wpdb->prefix}sub_campaigns", ['status' => 'closed'], ['id' => $campaignId]);

$closed = do_shortcode('[subalcatel_tarifs]');

$check('Campagne fermée : la grille reste affichée',
    str_contains($closed, 'sub-pricing__plans'),
    'une page vide neuf mois par an ne renseigne personne');
$check('Et le dit clairement',
    str_contains($closed, 'Les inscriptions ne sont pas ouvertes'));

$wpdb->update("{$wpdb->prefix}sub_campaigns", ['status' => 'open'], ['id' => $campaignId]);

printf("\n%s\n", $failures === 0 ? '✓ Tous les scénarios passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
