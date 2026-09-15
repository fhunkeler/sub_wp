<?php
/**
 * Test de fumée — magasin de réglages de sécurité.
 *
 *   docker exec sub_prod_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-security-settings.php
 *
 * Vérifie que les valeurs saisies sont bornées (un réglage à la main ne doit pas
 * pouvoir désarmer la protection), que la liste d'IP de confiance ne retient que
 * des entrées valides, et que l'appartenance à un bloc CIDR est correctement
 * calculée.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Support\SecuritySettings;

// Table rase : ces tests écrivent l'option, on ne veut pas partir d'un état hérité.
delete_option('subalcatel_security');

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-56s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

// --- Défauts = comportement d'avant ------------------------------------------
echo "\n--- Défauts ---\n";

$d = SecuritySettings::defaults();
$check('Longueur par défaut : 12', $d['password_min_length'] === 12);
$check('Seuil par compte par défaut : 8', $d['throttle_max_attempts'] === 8);
$check('Seuil par IP par défaut : 30', $d['throttle_max_ip_attempts'] === 30);
$check('Fenêtre par défaut : 15 min → 900 s', SecuritySettings::windowSeconds() === 900);

// --- Bornage à l'enregistrement ----------------------------------------------
echo "\n--- Bornage ---\n";

SecuritySettings::save([
    'password_min_length'      => 2,    // sous le plancher
    'throttle_max_attempts'    => 1,    // sous le plancher
    'throttle_max_ip_attempts' => 5,    // < seuil par compte après bornage
    'throttle_window_minutes'  => 99999,
]);
$check('Longueur bornée au plancher (8)', SecuritySettings::passwordMinLength() === 8);
$check('Seuil par compte borné au plancher (3)', SecuritySettings::maxAttempts() === 3);
$check('Seuil par IP relevé au niveau du seuil par compte',
    SecuritySettings::maxIpAttempts() >= SecuritySettings::maxAttempts(),
    'un seuil IP plus bas rendrait le compteur fin inutile');
$check('Fenêtre bornée au plafond (1440 min)', SecuritySettings::windowSeconds() === 1440 * 60);

SecuritySettings::save(['password_min_length' => 999]);
$check('Longueur bornée au plafond (64)', SecuritySettings::passwordMinLength() === 64);

// --- Cases à cocher ----------------------------------------------------------
echo "\n--- Interrupteurs ---\n";

SecuritySettings::save(['password_similarity' => '1', 'password_breach_check' => '', 'throttle_enabled' => '1']);
$check('Similarité activée quand cochée', SecuritySettings::similarityEnabled() === true);
$check('Contrôle de fuite désactivé quand décoché', SecuritySettings::breachCheckEnabled() === false);
$check('Ralentisseur activé quand coché', SecuritySettings::throttleEnabled() === true);

// --- Liste d'IP de confiance -------------------------------------------------
echo "\n--- Liste d’IP de confiance ---\n";

SecuritySettings::save([
    'throttle_ip_allowlist' => "203.0.113.10\n192.168.1.0/24\n2001:db8::/32\npas-une-ip\n10.0.0.5/99\n",
]);
$list = SecuritySettings::ipAllowlist();
$check('Les entrées valides sont retenues (IP, CIDR v4, CIDR v6)',
    in_array('203.0.113.10', $list, true)
    && in_array('192.168.1.0/24', $list, true)
    && in_array('2001:db8::/32', $list, true));
$check('Les entrées invalides sont écartées',
    !in_array('pas-une-ip', $list, true) && !in_array('10.0.0.5/99', $list, true),
    'mieux vaut ignorer une faute de frappe que verrouiller dessus');

// --- Appartenance ------------------------------------------------------------
echo "\n--- Correspondance d’IP ---\n";

$check('IP exacte reconnue', SecuritySettings::isIpExempt('203.0.113.10'));
$check('IP dans le bloc CIDR v4 reconnue', SecuritySettings::isIpExempt('192.168.1.42'));
$check('IP hors du bloc CIDR v4 rejetée', !SecuritySettings::isIpExempt('192.168.2.42'));
$check('IP dans le bloc CIDR v6 reconnue', SecuritySettings::isIpExempt('2001:db8::1'));
$check('IP quelconque non listée rejetée', !SecuritySettings::isIpExempt('8.8.8.8'));

// --- Nettoyage ---------------------------------------------------------------
delete_option('subalcatel_security');

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
