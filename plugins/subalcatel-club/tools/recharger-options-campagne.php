<?php
/**
 * Réécrit les options d'une campagne à partir de la configuration du code.
 *
 *   wp eval-file recharger-options-campagne.php               → simulation
 *   wp eval-file recharger-options-campagne.php write         → écriture
 *   wp eval-file recharger-options-campagne.php write 3       → sur la campagne 3
 *
 * `DemoSeeder::run()` ne touche jamais à une campagne existante — c'est ce qui
 * lui permet de tourner dans les tests sans écraser la base. Quand le bureau
 * change ses règles alors que la campagne est déjà en base, il faut donc les y
 * porter explicitement : c'est ce que fait cet outil.
 *
 * Il efface les options et les remises de la campagne visée, puis les recrée.
 * Les dossiers déjà déposés ne bougent pas : leurs lignes sont figées, et c'est
 * précisément pour cela qu'elles le sont. En revanche, toute retouche faite
 * depuis « Campagnes → Options » disparaît — d'où la simulation par défaut.
 */

use Subalcatel\Club\Membership\CampaignRepository;
use Subalcatel\Club\Membership\DemoSeeder;

// Pas de `bootstrap.php` ici : celui-ci ouvre la base Joomla, dont cet outil
// n'a que faire. L'extension est déjà chargée par `wp eval-file`.
global $wpdb;
$p = $wpdb->prefix . 'sub_';

$arguments  = $args ?? [];
$dryRun     = !in_array('write', $arguments, true);
$campaignId = 0;

foreach ($arguments as $argument) {
    if (ctype_digit((string) $argument)) {
        $campaignId = (int) $argument;
    }
}

if ($campaignId === 0) {
    $campaignId = (int) $wpdb->get_var(
        $wpdb->prepare("SELECT id FROM {$p}campaigns WHERE slug = %s", DemoSeeder::CAMPAIGN_SLUG)
    );
}

if ($campaignId === 0) {
    echo "Aucune campagne trouvée. Précisez son identifiant en argument.\n";

    return;
}

$repository = new CampaignRepository();
$avant      = $repository->options($campaignId);

printf("%s\n", $dryRun ? '=== SIMULATION ===' : '=== ÉCRITURE ===');
printf("Campagne %d — %d option(s) actuellement en base :\n\n", $campaignId, count($avant));

foreach ($avant as $option) {
    printf("  - %-24s %s\n", $option->name, $option->label);
}

if ($dryRun) {
    echo "\nRelancer avec « write » pour réécrire ces options.\n";

    return;
}

DemoSeeder::resetOptions($campaignId);

$apres = $repository->options($campaignId);

printf("\n%d option(s) après réécriture :\n\n", count($apres));

foreach ($apres as $option) {
    printf("  - %-24s %s\n", $option->name, $option->label);
}

$disparues = array_diff(
    array_map(static fn ($o): string => $o->name, $avant),
    array_map(static fn ($o): string => $o->name, $apres)
);

if ($disparues !== []) {
    printf("\nRetirées : %s\n", implode(', ', $disparues));
}

echo "\nTerminé.\n";
