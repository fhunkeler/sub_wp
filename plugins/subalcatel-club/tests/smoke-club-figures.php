<?php
/**
 * Test de fumée des chiffres du club : raccourci [subalcatel_chiffre].
 *
 *   docker exec sub_demo_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-club-figures.php
 *
 * La base de démo porte déjà des adhérents : on ne vérifie donc pas des
 * totaux, mais l'écart que provoquent les comptes créés ici. Chacun est
 * construit pour tomber d'un seul côté d'une frontière — à jour ou expiré,
 * technique ou non, au-dessus ou en dessous d'un seuil d'encadrement.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Frontend\ClubFigures;
use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Identity\TechnicalAccounts;

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-58s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

DiveLevels::seed();

$stats  = ['adherents', 'encadrants', 'moniteurs', 'mf2'];
$before = [];

foreach ($stats as $stat) {
    $before[$stat] = ClubFigures::count($stat);
}

$created = [];
$make = static function (string $levelSlug, string $validUntil, bool $technical = false) use (&$created): int {
    $id = (int) wp_insert_user([
        'user_login' => 'demo_' . wp_generate_password(8, false),
        'user_email' => wp_generate_password(8, false) . '@subalcatel.test',
        'user_pass'  => wp_generate_password(),
        'role'       => 'subscriber',
    ]);

    $term = get_term_by('slug', $levelSlug, DiveLevels::TAXONOMY);
    update_user_meta($id, 'sub_dive_level_id', $term->term_id);
    update_user_meta($id, 'sub_membership_valid_until', $validUntil);

    if ($technical) {
        update_user_meta($id, TechnicalAccounts::META, '1');
    }

    $created[] = $id;

    return $id;
};

$future = gmdate('Y-m-d', strtotime(current_time('Y-m-d') . ' +1 year'));
$past   = gmdate('Y-m-d', strtotime(current_time('Y-m-d') . ' -1 day'));
$today  = current_time('Y-m-d');

$make('p1', $future);            // adhérent, pas encadrant
$make('p2-e1', $today);          // échéance ce jour : encore à jour ; encadrant
$make('e3', $future);            // MF1 : encadrant et moniteur
$make('e4', $future);            // MF2 : les quatre
$make('e4', $past);              // MF2 expiré : ne compte nulle part
$make('e4', $future, true);      // compte technique : ne compte nulle part

$after = [];
foreach ($stats as $stat) {
    $after[$stat] = ClubFigures::count($stat);
}

$expected = ['adherents' => 4, 'encadrants' => 3, 'moniteurs' => 2, 'mf2' => 1];

foreach ($expected as $stat => $delta) {
    $got = $after[$stat] - $before[$stat];
    $check("« {$stat} » augmente de {$delta}", $got === $delta, "écart mesuré : {$got}");
}

// Rendu : nombre nu, arrondi vers le bas, statistique inconnue muette.
$check('le raccourci rend un nombre', preg_match('/^\d[\d\s\x{a0}\x{202f}]*$/u', do_shortcode('[subalcatel_chiffre]')) === 1);
$check('arrondi="1000" donne 0 pour un petit club', do_shortcode('[subalcatel_chiffre arrondi="1000"]') === '0');
$check('statistique inconnue : rien affiché', do_shortcode('[subalcatel_chiffre stat="inconnue"]') === '');

foreach ($created as $id) {
    wp_delete_user($id);
}

echo $failures === 0 ? "\nTout est vert.\n" : "\n{$failures} échec(s).\n";

if ($failures > 0) {
    exit(1);
}
