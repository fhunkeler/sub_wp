<?php
/**
 * Produit le SQL qui aligne les comptes repris sur le site cible.
 *
 * À lancer sur le clone « sub_export », après export-production.php write et
 * AVANT le renommage éventuel du préfixe — WP-CLI lit la base par `wp_`, alors
 * que le SQL produit doit nommer les tables du site cible :
 *
 *   docker exec -e WORDPRESS_DB_NAME=sub_export sub_demo_cli \
 *     wp --allow-root eval-file .../tools/export-user-sync.php wor304_
 *
 * (préfixe cible en argument, « wp_ » par défaut)
 *
 * Pourquoi ce fichier existe
 * --------------------------
 * `dump-users-merge.sql` est en `INSERT IGNORE`. C'est ce qui protège le compte
 * administrateur du site cible, et c'est aussi ce qui rend ce dump **incapable
 * de modifier quoi que ce soit**. Sur un site déjà peuplé par un export
 * précédent, il ne met pas à jour un rôle, n'ajoute pas une méta nouvelle — le
 * dump porte les `umeta_id`, qui entrent en collision — et ne propage aucun
 * effacement. Un export qui retire des droits bureau, marque des comptes
 * techniques et efface leurs données personnelles n'arriverait donc jamais :
 * l'import réussirait, et rien n'aurait changé.
 *
 * D'où cette resynchronisation. Pour chaque compte repris, désigné par son
 * identifiant de connexion (les ID peuvent diverger d'un import à l'autre) :
 * on retire les métas du plugin et le rôle, puis on repose ceux du clone.
 * L'état du site cible converge, quel qu'il fût.
 *
 * Le garde-fou : un compte que le site cible aurait promu administrateur
 * WordPress est laissé **entièrement** intact — ni rôle, ni métas. On ne
 * rétrograde pas un administrateur dans son dos au détour d'un import.
 */

global $wpdb;

if ($wpdb->dbname !== 'sub_export') {
    fwrite(STDERR, "Refus : à lancer sur sub_export uniquement.\n");
    exit(1);
}

$prefix = ($args[0] ?? '') !== '' ? (string) $args[0] : 'wp_';

$capabilitiesKey = $prefix . 'capabilities';
$userLevelKey    = $prefix . 'user_level';

$rows = $wpdb->get_results(
    "SELECT u.user_login, m.meta_key, m.meta_value
       FROM {$wpdb->usermeta} m
       JOIN {$wpdb->users} u ON u.ID = m.user_id
      WHERE m.meta_key LIKE 'sub\\_%'
         OR m.meta_key LIKE '\\_sub\\_%'
         OR m.meta_key = '{$wpdb->prefix}capabilities'
         OR m.meta_key = '{$wpdb->prefix}user_level'
      ORDER BY u.user_login, m.meta_key",
    ARRAY_A
) ?: [];

/** @var array<string, list<array{key: string, value: string}>> $byLogin */
$byLogin = [];
foreach ($rows as $row) {
    // Le préfixe du clone est toujours `wp_` ; celui de la cible peut différer.
    $key = match ($row['meta_key']) {
        $wpdb->prefix . 'capabilities' => $capabilitiesKey,
        $wpdb->prefix . 'user_level'   => $userLevelKey,
        default                        => $row['meta_key'],
    };

    $byLogin[$row['user_login']][] = ['key' => $key, 'value' => (string) $row['meta_value']];
}

$quote = static fn (string $value): string => "'" . esc_sql($value) . "'";

echo "-- Aligne les comptes repris sur l'état de cet export.\n";
echo "-- À importer APRÈS dump-users-merge.sql : celui-ci ajoute les comptes\n";
echo "-- manquants, celui-là corrige ceux qui existaient déjà — INSERT IGNORE\n";
echo "-- ne met jamais à jour une ligne existante, n'ajoute pas une méta\n";
echo "-- nouvelle (les umeta_id entrent en collision) et ne propage aucun\n";
echo "-- effacement.\n";
echo "-- Un compte promu administrateur WordPress sur le site cible est laissé\n";
echo "-- entièrement intact : ni rôle, ni métas.\n";
printf("-- %d compte(s), préfixe de table : %s\n\n", count($byLogin), $prefix);

foreach ($byLogin as $login => $metas) {
    printf("-- %s\n", $login);
    printf(
        "SET @uid := (SELECT ID FROM `%susers` WHERE user_login = %s LIMIT 1);\n",
        $prefix,
        $quote((string) $login)
    );
    printf(
        "SET @keep := (SELECT COUNT(*) FROM `%susermeta` WHERE user_id = @uid "
        . "AND meta_key = %s AND meta_value LIKE '%%administrator%%');\n",
        $prefix,
        $quote($capabilitiesKey)
    );
    printf(
        "DELETE FROM `%susermeta` WHERE @uid IS NOT NULL AND @keep = 0 AND user_id = @uid\n"
        . "  AND (meta_key LIKE 'sub\\_%%' OR meta_key LIKE '\\_sub\\_%%' OR meta_key IN (%s, %s));\n",
        $prefix,
        $quote($capabilitiesKey),
        $quote($userLevelKey)
    );

    foreach ($metas as $meta) {
        printf(
            "INSERT INTO `%susermeta` (user_id, meta_key, meta_value)\n"
            . "  SELECT @uid, %s, %s FROM DUAL WHERE @uid IS NOT NULL AND @keep = 0;\n",
            $prefix,
            $quote($meta['key']),
            $quote($meta['value'])
        );
    }

    echo "\n";
}
