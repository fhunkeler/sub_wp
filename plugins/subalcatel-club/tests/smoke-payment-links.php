<?php
/**
 * Test de fumée — liens de paiement en ligne.
 *
 *   docker exec sub_prod_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-payment-links.php
 *
 * Vérifie que les adresses HelloAsso de la saison servent de valeurs de départ,
 * que le bureau peut les remplacer ou les vider, qu'une adresse qui n'est pas
 * du web est écartée plutôt que retenue, et que la consigne affichée à
 * l'adhérent porte bien le lien — en texte pour le courriel, en lien cliquable
 * pour l'écran.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Membership\PaymentMethods;

// Table rase : ces tests écrivent l'option, on ne part pas d'un état hérité.
delete_option('subalcatel_payment_links');

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-60s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

// --- Valeurs de départ --------------------------------------------------------
echo "\n--- Valeurs de départ ---\n";

$links = PaymentMethods::links();
$check(
    'Adhésion : campagne HelloAsso 2026-2027',
    str_contains($links['helloasso'], '/adhesions/adhesion-2026-2027'),
    $links['helloasso']
);
$check(
    'CE Orange : boutique HelloAsso de la saison',
    str_contains($links['ce_orange'], '/boutiques/ce-orange-saison-2026-2027'),
    $links['ce_orange']
);
$check('Chèque : aucun paiement en ligne', $links['cheque'] === '', $links['cheque']);
$check('Un mode inconnu ne rend pas de lien', PaymentMethods::link('virement') === '');

// --- Ce que voit l'adhérent ---------------------------------------------------
echo "\n--- Consignes ---\n";

$text = PaymentMethods::instructions('helloasso');
$check('Courriel : l’adresse est lisible telle quelle', str_contains($text, 'https://'), $text);
$check('Courriel : pas de balise HTML', !str_contains($text, '<a '), $text);

$html = PaymentMethods::instructionsHtml('ce_orange');
$check('Écran : lien cliquable', str_contains($html, '<a class="sub-payment-link" href="https://'));
$check('Écran : le lien dit ce qu’on paie', str_contains($html, 'carte de niveau'));
// Une seule balise ouvrante et une seule fermante : la consigne elle-même
// passe par `esc_html`, et rien d'autre que le lien n'est du HTML.
$check(
    'Écran : la consigne entière, et le lien pour seule balise',
    str_contains($html, 'comité d’entreprise Orange') && substr_count($html, '<') === 2,
    $html
);
$check(
    'Chèque : consigne sans lien',
    !str_contains(PaymentMethods::instructionsHtml('cheque'), '<a '),
);

// --- Ce que le bureau enregistre ----------------------------------------------
echo "\n--- Enregistrement ---\n";

$result = PaymentMethods::saveLinks([
    'helloasso' => '  https://www.helloasso.com/associations/asac-tregor-subalcatel/adhesions/adhesion-2027-2028  ',
    'cheque'    => '',
    'ce_orange' => '',
]);
$check('Espaces autour de l’adresse retirés',
    PaymentMethods::link('helloasso') === 'https://www.helloasso.com/associations/asac-tregor-subalcatel/adhesions/adhesion-2027-2028',
    PaymentMethods::link('helloasso'));
$check('Une case vidée ne retombe pas sur la valeur de départ',
    PaymentMethods::link('ce_orange') === '',
    PaymentMethods::link('ce_orange'));
$check('Aucun refus sur une saisie valide', $result['rejected'] === []);

$result = PaymentMethods::saveLinks([
    'helloasso' => 'javascript:alert(1)',
    'ce_orange' => 'helloasso.com/sans-schema',
]);
$check('Un `javascript:` est écarté', PaymentMethods::link('helloasso') === '');
$check('Une adresse sans schéma est écartée', PaymentMethods::link('ce_orange') === '');
$check('Les deux refus sont signalés au bureau',
    count($result['rejected']) === 2,
    implode(', ', $result['rejected']));

// --- Nettoyage ----------------------------------------------------------------
delete_option('subalcatel_payment_links');

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
