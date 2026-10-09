<?php
/**
 * Test de fumée du renouvellement prérempli.
 *
 *   docker exec sub_demo_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-renewal-prefill.php
 *
 * Un adhérent qui renouvelle retrouve ses choix de la saison passée — mais
 * seulement ceux qui ont encore un sens : la formule si elle existe toujours,
 * les réponses encore proposées, et jamais une question que le bureau a marquée
 * « propre à la saison » (niveau préparé, licence déjà prise ailleurs). Une
 * remise de licence reprise en silence d'une année sur l'autre, c'est de
 * l'argent que le club ne touche pas.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Membership\ApplicationService;

global $wpdb;
$p = $wpdb->prefix . 'sub_';

$failures = 0;
$check    = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-54s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$makeUser = static function (string $role): int {
    $id = wp_insert_user([
        'user_login' => 'demo_' . wp_generate_password(8, false),
        'user_pass'  => wp_generate_password(),
        'role'       => $role,
    ]);

    return is_wp_error($id) ? 0 : (int) $id;
};

$service   = new ApplicationService();
$member    = $makeUser('sub_member');
$newcomer  = $makeUser('sub_member');
$treasurer = $makeUser('sub_office');
sub_test_complete_identity($member);
sub_test_complete_identity($newcomer);

// --- La colonne existe ---------------------------------------------------------
$column = $wpdb->get_row("SHOW COLUMNS FROM {$p}options LIKE 'carry_over'");
$check('La colonne carry_over existe', $column !== null);
$check('Elle vaut 1 par défaut', $column !== null && (string) $column->Default === '1');

// --- La saison passée ----------------------------------------------------------
$lastSeason = sub_test_pricing_campaign();

$previousId = $service->submit($member, $lastSeason, 'plongee', [
    'origine_adhesion'          => 'nokia',
    'assurance_individuelle'    => 'loisir2',
    'moins_value_licence_nokia' => 'oui',
    'niveau_prepare'            => 'p2',
    'pret_bloc'                 => 'oui',
    'pret_detendeur'            => 'non',
    'pret_gilet'                => 'oui',
], 'helloasso');
$service->recordPayment($previousId, (float) $service->find($previousId)['total_amount'], 'helloasso', null, $treasurer);
$service->validateSecretariat($previousId, $treasurer);

// Un dossier annulé de l'autre adhérent : il n'a jamais été une adhésion.
$abandoned = $service->submit($newcomer, $lastSeason, 'plongee', [
    'origine_adhesion'       => 'exterieur',
    'assurance_individuelle' => 'aucune',
    'pret_bloc'              => 'non',
    'pret_detendeur'         => 'non',
    'pret_gilet'             => 'non',
], 'cheque');
$service->cancel($abandoned, $newcomer);

// --- La nouvelle saison, dupliquée comme le fait l'écran des campagnes ----------
$wpdb->insert("{$p}campaigns", [
    'title'         => 'Campagne suivante de test',
    'slug'          => 'campagne-suivante-' . wp_generate_password(6, false, false),
    'opens_on'      => current_time('Y-m-d'),
    'closes_on'     => '2028-12-31',
    'valid_from'    => '2027-09-15',
    'valid_until'   => '2028-12-31',
    'reminder_days' => '30',
    'status'        => 'draft',
]);
$nextSeason = (int) $wpdb->insert_id;

foreach (['plans', 'options', 'discount_rules'] as $table) {
    foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}{$table} WHERE campaign_id = %d", $lastSeason), ARRAY_A) ?: [] as $row) {
        unset($row['id']);
        $row['campaign_id'] = $nextSeason;
        $wpdb->insert("{$p}{$table}", $row);
    }
}

// Ce que pose la migration sur les questions propres à la saison.
$wpdb->query($wpdb->prepare(
    "UPDATE {$p}options SET carry_over = 0
     WHERE campaign_id = %d AND (name = 'niveau_prepare' OR name LIKE 'moins\\_value\\_licence%%')",
    $nextSeason
));

// Le bureau a retiré « Loisir 2 » de l'offre d'assurance cette année.
$assurance = json_decode((string) $wpdb->get_var($wpdb->prepare(
    "SELECT choices FROM {$p}options WHERE campaign_id = %d AND name = 'assurance_individuelle'",
    $nextSeason
)), true);
$wpdb->update(
    "{$p}options",
    ['choices' => wp_json_encode(array_values(array_filter($assurance, static fn (array $c): bool => $c['value'] !== 'loisir2')))],
    ['campaign_id' => $nextSeason, 'name' => 'assurance_individuelle']
);

// --- Ce qui est repris ---------------------------------------------------------
echo "\n--- Reprise ---\n";

$defaults = $service->renewalDefaults($member, $nextSeason);

$check('Une adhésion passée donne des valeurs par défaut', $defaults !== null);
$check('La formule est reprise', ($defaults['plan'] ?? '') === 'plongee');
$check('Le mode de règlement aussi', ($defaults['payment_method'] ?? '') === 'helloasso');
$check('L’origine est reprise', ($defaults['answers']['origine_adhesion'] ?? '') === 'nokia');
$check('Les prêts de matériel aussi',
    ($defaults['answers']['pret_bloc'] ?? '') === 'oui'
    && ($defaults['answers']['pret_gilet'] ?? '') === 'oui'
    && ($defaults['answers']['pret_detendeur'] ?? '') === 'non');

$check('Pas le niveau préparé de l’an passé', !isset($defaults['answers']['niveau_prepare']));
$check('Ni la remise de licence', !isset($defaults['answers']['moins_value_licence_nokia']),
    'une remise de -30 € reprise sans que personne ne la redemande');
$check('Une réponse retirée de l’offre n’est pas reprise',
    !isset($defaults['answers']['assurance_individuelle']));
$check('Les questions à revoir sont listées',
    in_array('Niveau préparé cette saison', $defaults['to_answer'] ?? [], true),
    implode(' | ', $defaults['to_answer'] ?? []));
$check('Une question « ajoutée d’office » n’est ni reprise ni listée',
    !isset($defaults['answers']['carte_niveau']));

$check('Un dossier annulé ne sert pas de modèle',
    $service->renewalDefaults($newcomer, $nextSeason) === null);
$check('Ni la campagne du dossier elle-même',
    $service->renewalDefaults($member, $lastSeason) === null);

// --- Le formulaire --------------------------------------------------------------
echo "\n--- Formulaire ---\n";

// Seule ouverte le temps du test : `openCampaign()` départage par date
// d'ouverture, et d'autres suites laissent des campagnes ouvertes.
$otherOpen = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}campaigns WHERE status = 'open'"));
foreach ($otherOpen as $other) {
    $wpdb->update("{$p}campaigns", ['status' => 'draft'], ['id' => $other]);
}
$wpdb->update("{$p}campaigns", ['status' => 'open'], ['id' => $nextSeason]);

wp_set_current_user($member);
$html = do_shortcode('[subalcatel_adhesion]');

$checked = static fn (string $name, string $value): bool => (bool) preg_match(
    '/name="options\[' . preg_quote($name, '/') . '\](?:\[\])?"[^>]*value="' . preg_quote($value, '/') . '"[^>]*checked'
    . '|value="' . preg_quote($value, '/') . '"[^>]*name="options\[' . preg_quote($name, '/') . '\](?:\[\])?"[^>]*checked/s',
    $html
);

$check('Le formulaire annonce la reprise', str_contains($html, 'sont repris'));
$check('Il nomme la saison d’origine', str_contains($html, 'Campagne de test'));
$check('Il liste les questions à revoir', str_contains($html, 'Niveau préparé cette saison'));
$check('Le prêt de bloc arrive coché', $checked('pret_bloc', 'oui'));
$check('Le niveau préparé n’est pas présélectionné', !$checked('niveau_prepare', 'p2'));
$check('HelloAsso arrive sélectionné', (bool) preg_match('/value="helloasso"[^>]*checked/', $html));

wp_set_current_user($newcomer);
$check('Un primo-adhérent n’a pas de message de reprise',
    !str_contains(do_shortcode('[subalcatel_adhesion]'), 'sont repris'));

// --- Nettoyage -----------------------------------------------------------------
foreach ($otherOpen as $other) {
    $wpdb->update("{$p}campaigns", ['status' => 'open'], ['id' => $other]);
}

require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ([$member, $newcomer, $treasurer] as $id) {
    wp_delete_user($id);
}

sub_test_drop_campaign($nextSeason);
sub_test_drop_campaign($lastSeason);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
