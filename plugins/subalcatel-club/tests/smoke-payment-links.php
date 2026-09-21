<?php
/**
 * Test de fumée — liens de paiement, rattachés à leur campagne.
 *
 *   docker exec sub_prod_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-payment-links.php
 *
 * Vérifie qu'une campagne porte ses propres adresses HelloAsso, que deux
 * campagnes qui se chevauchent n'encaissent pas au même endroit, qu'une adresse
 * qui n'est pas du web est écartée plutôt que retenue, et que la consigne
 * affichée à l'adhérent porte le lien de SA campagne — en texte pour le
 * courriel, en lien cliquable pour l'écran.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Database\Schema;
use Subalcatel\Club\Membership\CampaignRepository;
use Subalcatel\Club\Membership\PaymentMethods;

global $wpdb;
$p = $wpdb->prefix . 'sub_';

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-60s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

// Deux campagnes à soi, jetées à la fin : les suites ne se marchent pas dessus.
$makeCampaign = static function (string $suffix, string $from, string $until) use ($wpdb, $p): int {
    $wpdb->insert("{$p}campaigns", [
        'title'       => 'Campagne de test ' . $suffix,
        'slug'        => 'test-liens-' . $suffix,
        'opens_on'    => $from,
        'closes_on'   => $until,
        'valid_from'  => $from,
        'valid_until' => $until,
        'status'      => 'draft',
    ]);

    return (int) $wpdb->insert_id;
};

$saison1 = $makeCampaign('saison-1', '2026-09-15', '2027-12-31');
$saison2 = $makeCampaign('saison-2', '2027-09-15', '2028-12-31');

$repo = new CampaignRepository();

// --- Une campagne neuve n'a pas de lien ---------------------------------------
echo "\n--- Campagne neuve ---\n";

$links = $repo->paymentLinks($saison1);
$check('Aucun lien tant que le bureau n’a rien saisi', array_filter($links) === []);
$check('Tous les modes offerts sont présents, à vide',
    array_keys($links) === array_keys(PaymentMethods::offered()),
    implode(', ', array_keys($links)));
$check('Un mode inconnu ne rend pas de lien', $repo->paymentLink($saison1, 'virement') === '');

// --- Ce que le bureau enregistre ----------------------------------------------
echo "\n--- Enregistrement ---\n";

$adhesion = 'https://www.helloasso.com/associations/asac-tregor-subalcatel/adhesions/adhesion-2026-2027';
$boutique = 'https://www.helloasso.com/associations/asac-tregor-subalcatel/boutiques/ce-orange-saison-2026-2027';

$result = PaymentMethods::sanitizeLinks([
    'helloasso' => '  ' . $adhesion . '  ',
    'cheque'    => '',
    'ce_orange' => $boutique,
]);
$repo->savePaymentLinks($saison1, $result['links']);

$check('Aucun refus sur une saisie valide', $result['rejected'] === []);
$check('Espaces autour de l’adresse retirés',
    $repo->paymentLink($saison1, 'helloasso') === $adhesion,
    $repo->paymentLink($saison1, 'helloasso'));
$check('Boutique CE Orange enregistrée',
    $repo->paymentLink($saison1, 'ce_orange') === $boutique);
$check('Le chèque reste sans lien', $repo->paymentLink($saison1, 'cheque') === '');

// --- Le lien suit la campagne, pas le site -------------------------------------
echo "\n--- Une campagne, ses adresses ---\n";

$check('La saison suivante n’hérite de rien',
    array_filter($repo->paymentLinks($saison2)) === [],
    'deux campagnes qui se chevauchent n’encaissent pas au même endroit');

$suivante = 'https://www.helloasso.com/associations/asac-tregor-subalcatel/adhesions/adhesion-2027-2028';
$repo->savePaymentLinks($saison2, PaymentMethods::sanitizeLinks(['helloasso' => $suivante])['links']);

$check('Chaque campagne garde la sienne',
    $repo->paymentLink($saison1, 'helloasso') === $adhesion
        && $repo->paymentLink($saison2, 'helloasso') === $suivante);
$repo->savePaymentLinks($saison2, PaymentMethods::sanitizeLinks([])['links']);
$check('Vider une case efface l’adresse pour de bon',
    $repo->paymentLink($saison2, 'helloasso') === '',
    $repo->paymentLink($saison2, 'helloasso'));

// --- Adresses refusées ---------------------------------------------------------
echo "\n--- Adresses refusées ---\n";

$result = PaymentMethods::sanitizeLinks([
    'helloasso' => 'javascript:alert(1)',
    'ce_orange' => 'helloasso.com/sans-schema',
]);
$check('Un `javascript:` est écarté', $result['links']['helloasso'] === '');
$check('Une adresse sans schéma est écartée', $result['links']['ce_orange'] === '');
$check('Les deux refus sont signalés au bureau',
    count($result['rejected']) === 2,
    implode(', ', $result['rejected']));

// --- Ce que voit l'adhérent ----------------------------------------------------
echo "\n--- Consignes ---\n";

$text = PaymentMethods::instructions('helloasso', $adhesion);
$check('Courriel : l’adresse est lisible telle quelle', str_contains($text, $adhesion));
$check('Courriel : pas de balise HTML', !str_contains($text, '<a '));

$html = PaymentMethods::instructionsHtml('ce_orange', $boutique);
$check('Écran : lien cliquable', str_contains($html, '<a class="sub-payment-link" href="' . $boutique . '"'));
$check('Écran : le lien dit ce qu’on paie', str_contains($html, 'carte de niveau'));
// Une seule balise ouvrante et une seule fermante : la consigne elle-même passe
// par `esc_html`, et rien d'autre que le lien n'est du HTML.
$check('Écran : la consigne entière, et le lien pour seule balise',
    str_contains($html, 'comité d’entreprise Orange') && substr_count($html, '<') === 2,
    $html);
$check('Sans lien, la consigne reste seule',
    !str_contains(PaymentMethods::instructionsHtml('cheque'), '<a '));

// --- Reprise de la version 0.24 ------------------------------------------------
echo "\n--- Reprise de l’ancien réglage global ---\n";

// La 0.24 tenait ces adresses dans une option unique. Ce que le bureau y avait
// saisi doit revenir sur la campagne ouverte — sinon la mise à jour ferait
// disparaître le lien sans que personne ne s'en aperçoive avant un adhérent.
$ouverte = $makeCampaign('ouverte', '2026-09-15', '2027-12-31');
$wpdb->update("{$p}campaigns", ['status' => 'open'], ['id' => $ouverte]);

update_option('subalcatel_payment_links', ['helloasso' => $adhesion, 'ce_orange' => '']);
delete_option('subalcatel_club_db_version');
Schema::migrate();

$check('L’adresse saisie en 0.24 revient sur la campagne ouverte',
    $repo->paymentLink($ouverte, 'helloasso') === $adhesion,
    $repo->paymentLink($ouverte, 'helloasso'));
$check('L’option globale est retirée',
    get_option('subalcatel_payment_links', null) === null,
    'aucun écran ne doit pouvoir la relire et la croire en vigueur');

$wpdb->delete("{$p}campaigns", ['id' => $ouverte]);

// --- Nettoyage -----------------------------------------------------------------
foreach ([$saison1, $saison2] as $id) {
    $wpdb->delete("{$p}campaigns", ['id' => $id]);
}

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
