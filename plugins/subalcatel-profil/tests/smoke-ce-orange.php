<?php
/**
 * Test de fumée — le profil de Sub Alcatel : règlement par le CE Orange.
 *
 *   docker exec sub_demo_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-profil/tests/smoke-ce-orange.php
 *
 * Le partage entre tiers payeur et adhérent est vérifié dans le cœur
 * (`smoke-third-party-share`). Ici, ce que Sub Alcatel y met : le mode
 * « CE Orange », ses textes — mot pour mot ceux d'avant la sortie du cœur —
 * et la mise à jour du profil par le même dépôt que l'extension.
 */

require_once dirname(__DIR__, 2) . '/subalcatel-club/tests/helpers.php';

use Subalcatel\Club\Membership\CampaignRepository;
use Subalcatel\Club\Membership\Frontend\QuoteEndpoint;
use Subalcatel\Club\Membership\PaymentMethods;
use Subalcatel\Club\Membership\ThirdPartyShare;
use Subalcatel\Club\Setup\Updater;
use Subalcatel\Profil\CeOrange;
use Subalcatel\Profil\Profil;

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-60s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

// --- Branchement ---------------------------------------------------------------
echo "\n--- Branchement ---\n";

// Le profil nomme les filtres en toutes lettres pour s'installer avant le
// cœur qui les lit : ces noms doivent rester ceux du cœur.
$check('Filtre des modes de règlement : même nom que le cœur',
    Profil::PAYMENT_METHODS_FILTER === PaymentMethods::FILTER);
$check('Filtre de la mise à jour : même nom que le cœur', Profil::UPDATER_FILTER === Updater::PLUGINS_FILTER);

// --- Le mode « CE Orange » ----------------------------------------------------
echo "\n--- Mode de règlement ---\n";

$check('Proposé au dépôt, après HelloAsso et le chèque',
    array_keys(PaymentMethods::offered()) === ['helloasso', 'cheque', CeOrange::METHOD],
    implode(', ', array_keys(PaymentMethods::offered())));
$check('Libellé', PaymentMethods::label(CeOrange::METHOD) === 'CE Orange');
$check('Une adresse de boutique par campagne',
    (PaymentMethods::linkFields()[CeOrange::METHOD]['label'] ?? '') === 'HelloAsso — boutique CE Orange');
$check('Consigne après dépôt',
    PaymentMethods::instructions(CeOrange::METHOD) === 'Votre règlement passe par le comité d’entreprise Orange. '
        . 'Le bureau confirmera la réception auprès du CE.');
$check('Lien vers la boutique',
    str_contains(PaymentMethods::instructionsHtml(CeOrange::METHOD, 'https://example.org/boutique'),
        'Boutique CE Orange — carte de niveau et assurance'));

// --- Prise en charge par le CE -------------------------------------------------
echo "\n--- Prise en charge ---\n";

$ce = ThirdPartyShare::forMethod(CeOrange::METHOD);
$check('Le CE Orange est un tiers payeur', $ce !== null);
$check('Carte de niveau et assurance restent à l’adhérent',
    $ce->memberOptions === ['carte_niveau', 'assurance_individuelle']);
$check('La phrase d’avant, mot pour mot',
    $ce->sentence(['payer' => 124.0, 'member' => 46.0])
        === 'Le CE Orange prend en charge 124,00 €. Reste à votre charge : 46,00 € '
        . '(carte de niveau et assurance), à régler dans la boutique CE Orange.',
    $ce->sentence(['payer' => 124.0, 'member' => 46.0]));
$check('Rien à régler : on le dit',
    $ce->sentence(['payer' => 124.0, 'member' => 0.0])
        === 'Le CE Orange prend en charge 124,00 €. Rien ne reste à votre charge.');
$check('L’intitulé et l’aide du récapitulatif, mot pour mot',
    $ce->payerLine() === 'Pris en charge par le CE Orange'
        && $ce->memberHelp() === 'Carte de niveau et assurance, à régler dans la boutique CE Orange '
            . 'une fois le dossier déposé.');

$campaignId = sub_test_pricing_campaign();
$request    = new WP_REST_Request('POST', '/subalcatel/v1/quote');
$request->set_param('campaign_id', $campaignId);
$request->set_param('plan', 'plongee');
$request->set_param('options', [
    'origine_adhesion'       => 'ce_orange',
    'assurance_individuelle' => 'loisir2',
    'niveau_prepare'         => 'p1',
    'pret_bloc'              => 'oui',
]);
$request->set_param('payment_method', CeOrange::METHOD);
$share = QuoteEndpoint::handle($request)->get_data()['share'];
$check('Récapitulatif en direct : part du CE et de l’adhérent',
    ($share['payer_display'] ?? '') === '246,00 €' && ($share['member_display'] ?? '') === '45,00 €',
    ($share['payer_display'] ?? '?') . ' / ' . ($share['member_display'] ?? '?'));
sub_test_drop_campaign($campaignId);

// --- Mise à jour ---------------------------------------------------------------
echo "\n--- Mise à jour ---\n";

$suivies = apply_filters(Updater::PLUGINS_FILTER, []);
$check('Le profil se déclare à la mise à jour',
    ($suivies['subalcatel-profil/subalcatel-profil.php']['prefixe'] ?? '') === 'profil-'
        && ($suivies['subalcatel-profil/subalcatel-profil.php']['archive'] ?? '') === 'subalcatel-profil');
$entete = get_file_data(dirname(__DIR__) . '/subalcatel-profil.php', ['UpdateURI' => 'Update URI', 'Version' => 'Version']);
$check('… et confie sa mise à jour au dépôt du club',
    $entete['UpdateURI'] === 'https://github.com/' . Updater::DEPOT);
$check('Version de l’en-tête et constante d’accord', $entete['Version'] === \Subalcatel\Profil\VERSION);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
