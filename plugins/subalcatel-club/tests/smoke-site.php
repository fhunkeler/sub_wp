<?php
/**
 * Test de fumée de l'arborescence du site et des accès.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-site.php
 *
 * Deux propriétés à tenir, et elles se contredisent volontiers : l'installation
 * doit être **rejouable** — sans doublon, sans écraser ce qu'un bénévole a
 * rédigé — et les accès doivent être **fermés par défaut**.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Content\Visibility;
use Subalcatel\Club\Frontend\MenuVisibility;
use Subalcatel\Club\Frontend\Pages;
use Subalcatel\Club\Setup\SiteBuilder;
use Subalcatel\Club\Setup\SiteMap;

global $wpdb;

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-58s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$makeUser = static function (string $role, ?string $validUntil): int {
    $id = wp_insert_user([
        'user_login' => 'demo_' . wp_generate_password(8, false),
        'user_email' => wp_generate_password(8, false) . '@subalcatel.test',
        'user_pass'  => wp_generate_password(),
        'role'       => $role,
    ]);

    if ($validUntil !== null) {
        update_user_meta($id, 'sub_membership_valid_until', $validUntil);
    }

    return $id;
};

$member  = $makeUser('sub_member', '2027-12-31');
$lapsed  = $makeUser('sub_member', null);
$office  = $makeUser('sub_office', '2027-12-31');

// --- Installation ------------------------------------------------------------
echo "\n--- Installation de l’arborescence ---\n";

$first = SiteBuilder::run();
$check('Installation effectuée', $first['created'] + $first['updated'] === count(SiteMap::pages()),
    sprintf('%d créée(s), %d mise(s) à jour', $first['created'], $first['updated']));

$second = SiteBuilder::run();
$check('Rejouable sans doublon', $second['created'] === 0,
    'un second clic ne doit rien créer');

$keys = $wpdb->get_col(
    "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '" . Pages::KEY_META . "'"
);
$check('Aucune clé en double', count($keys) === count(array_unique($keys)),
    count($keys) . ' page(s) marquée(s)');

/**
 * Nombre d'entrées réellement en base pour un menu.
 *
 * On compte les enregistrements, pas ce que renvoie `wp_get_nav_menu_items()` :
 * cette fonction filtre selon la personne qui regarde, et masquerait justement
 * les entrées surnuméraires de l'espace membre.
 */
$menuCount = static function (string $slug) use ($wpdb): int {
    $menu = wp_get_nav_menu_object($slug);

    if (!$menu) {
        return 0;
    }

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
         JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
         WHERE tt.taxonomy = 'nav_menu' AND tt.term_id = %d",
        $menu->term_id
    ));
};

// Une page peut figurer dans deux menus — les documents du club y sont, une
// fois au menu principal et une fois dans l'espace membre. Le décompte suit la
// même lecture que le constructeur, faute de quoi il annoncerait un doublon là
// où le plan demande bien deux entrées.
$menuPlanned = static fn (string $slug): int => count(array_filter(
    SiteMap::pages(),
    static fn (array $p): bool => in_array($slug, SiteMap::menusOf($p), true)
));

// Les menus sont reconstruits à chaque installation. Une entrée déjà posée doit
// être retrouvée par la page qu'elle vise, donc réutilisée. La recherche a
// longtemps échoué pour l'espace membre : le menu doublait de taille à chaque
// clic sur « Installer », sans que rien ne se voie — le thème du club n'affiche
// aucun menu classique. D'où ce contrôle, qui compte les lignes en base.
$menusStables = static function (string $moment) use ($check, $menuCount, $menuPlanned): void {
    foreach ([SiteMap::MENU_MAIN, SiteMap::MENU_MEMBER, SiteMap::MENU_LEGAL] as $slug) {
        $found = $menuCount($slug);

        $check(
            sprintf('Menu « %s » sans doublon (%s)', $slug, $moment),
            $found === $menuPlanned($slug),
            sprintf('%d entrée(s) pour %d page(s) au plan', $found, $menuPlanned($slug))
        );
    }
};

$menusStables('après 2 installations');

/** Une entrée de ce menu vise-t-elle cette page ? */
$menuHasPage = static function (string $slug, int $pageId) use ($wpdb): bool {
    $menu = wp_get_nav_menu_object($slug);

    if (!$menu) {
        return false;
    }

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
         JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
         JOIN {$wpdb->postmeta} pm ON pm.post_id = tr.object_id
         WHERE tt.taxonomy = 'nav_menu' AND tt.term_id = %d
           AND pm.meta_key = '_menu_item_object_id' AND pm.meta_value = %d",
        $menu->term_id,
        $pageId
    )) > 0;
};

$docsId = Pages::id(Pages::CLUB_DOCUMENTS);

$check('Documents du club : entrée au menu principal',
    $menuHasPage(SiteMap::MENU_MAIN, $docsId),
    'sinon la page publique n’est atteignable qu’en connaissant son adresse');
$check('Documents du club : entrée dans l’espace membre',
    $menuHasPage(SiteMap::MENU_MEMBER, $docsId));

// --- Hiérarchie --------------------------------------------------------------
echo "\n--- Hiérarchie ---\n";

$profileId = Pages::id(Pages::PROFILE);
$areaId    = Pages::id(Pages::MEMBER_AREA);

$check('L’espace membre existe', $areaId > 0);
$check('Le profil est son enfant', get_post($profileId)->post_parent === $areaId);
$check('L’adresse reflète la hiérarchie',
    str_contains(Pages::url(Pages::PROFILE), '/espace-membre/profil/'),
    str_replace(home_url(), '', Pages::url(Pages::PROFILE)));

$check('La page d’accueil est celle du site',
    (int) get_option('page_on_front') === Pages::id(Pages::HOME));
$check('Les articles ont leur page', (int) get_option('page_for_posts') === Pages::id(Pages::NEWS));

// --- Contenu rédigé, jamais écrasé -------------------------------------------
echo "\n--- Protection du contenu rédigé ---\n";

$clubId = Pages::id('le-club');
wp_update_post(['ID' => $clubId, 'post_content' => 'Texte écrit par le bureau un dimanche soir.']);

$third = SiteBuilder::run();

$check('Une page retouchée est conservée', $third['preserved'] >= 1,
    $third['preserved'] . ' page(s) préservée(s)');
$check('Son texte est intact',
    get_post($clubId)->post_content === 'Texte écrit par le bureau un dimanche soir.');
$check('Le motif est expliqué', $third['messages'] !== [], $third['messages'][0] ?? '');

// --- Reprise d’une page créée à la main --------------------------------------
echo "\n--- Reprise d’une page existante ---\n";

$legacyId = wp_insert_post([
    'post_type'   => 'page',
    'post_status' => 'publish',
    'post_title'  => 'Ancienne page annuaire',
    'post_name'   => 'ancien-chemin-test',
    'post_content' => 'Contenu historique.',
]);

$before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish'");
SiteBuilder::run();
$after = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish'");

$check('Aucune page créée à la volée', $after === $before, "{$before} → {$after}");
wp_delete_post($legacyId, true);

// --- Visibilité --------------------------------------------------------------
echo "\n--- Qui voit quoi ---\n";

$public    = Pages::id('le-club');
$connected = Pages::id(Pages::MEMBER_AREA);

$reserved = wp_insert_post([
    'post_type' => 'page', 'post_title' => 'Page réservée', 'post_status' => 'publish',
]);
update_post_meta($reserved, Visibility::META, Visibility::MEMBERS_ONLY);

$check('Page publique : visiteur', Visibility::mayRead($public, 0));
$check('Page publique : adhérent', Visibility::mayRead($public, $member));

$check('Connexion requise : visiteur refusé', !Visibility::mayRead($connected, 0));
$check('Connexion requise : adhérent à jour', Visibility::mayRead($connected, $member));
$check('Connexion requise : adhésion expirée acceptée', Visibility::mayRead($connected, $lapsed),
    'un dossier en cours doit rester consultable');

$check('Réservée : visiteur refusé', !Visibility::mayRead($reserved, 0));
$check('Réservée : adhésion expirée refusée', !Visibility::mayRead($reserved, $lapsed));
$check('Réservée : adhérent à jour', Visibility::mayRead($reserved, $member));
$check('Réservée : le bureau passe', Visibility::mayRead($reserved, $office));

// --- Filtrage de la navigation -----------------------------------------------
echo "\n--- Entrées de navigation ---\n";

// Un bloc `navigation-link` sans libellé ne rend rien : sans lui, le test
// confondrait « retiré par le filtre » et « vide faute d'attributs ».
$linkTo = static fn (int $postId): array => [
    'blockName' => 'core/navigation-link',
    'attrs'     => [
        'url'   => str_replace(home_url(), '', (string) get_permalink($postId)),
        'label' => get_the_title($postId),
    ],
];

// On passe par `render_block()`, le vrai chemin de rendu. Une version
// précédente appelait le filtre à la main : elle validait le callback sans
// vérifier qu'il était branché au bon endroit, et n'a pas vu que le bloc
// Navigation rend ses enfants par une voie que `pre_render_block` n'emprunte
// jamais. Un menu réservé s'affichait à tout le monde, test au vert.
$render = static function (array $block): string {
    return render_block($block + ['innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
};

$estAffiche = static fn (array $block): bool => trim($render($block)) !== '';

wp_set_current_user(0);
MenuVisibility::forget();
$check('Visiteur : entrée publique conservée', $estAffiche($linkTo($public)));
$check('Visiteur : entrée réservée retirée', !$estAffiche($linkTo($reserved)),
    'l’entrée n’est pas envoyée au navigateur, pas seulement masquée');
$check('Visiteur : entrée « connexion requise » retirée',
    !$estAffiche($linkTo($connected)));

wp_set_current_user($member);
MenuVisibility::forget();
$check('Adhérent : la même entrée réservée réapparaît', $estAffiche($linkTo($reserved)));
$check('Et l’espace membre aussi', $estAffiche($linkTo($connected)));

wp_set_current_user($lapsed);
MenuVisibility::forget();
$check('Adhésion expirée : espace membre conservé', $estAffiche($linkTo($connected)));
$check('Mais pas la page réservée', !$estAffiche($linkTo($reserved)));

wp_set_current_user(0);
MenuVisibility::forget();

$check('Une adresse externe passe',
    $estAffiche(['blockName' => 'core/navigation-link',
        'attrs' => ['url' => 'https://ffessm.fr/', 'label' => 'FFESSM']]),
    'ce filtre retire, il n’autorise pas');

$paragraphe = ['blockName' => 'core/paragraph', 'attrs' => [],
    'innerHTML' => '<p>Texte</p>', 'innerContent' => ['<p>Texte</p>']];
$check('Un autre bloc n’est pas touché', str_contains(render_block($paragraphe), 'Texte'));

// Le sous-menu emporte ses enfants : c'est lui qui les contient.
$sousMenu = [
    'blockName'    => 'core/navigation-submenu',
    'attrs'        => ['url' => str_replace(home_url(), '', (string) get_permalink($connected)), 'label' => 'Mon espace'],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
];
$check('Un sous-menu réservé disparaît entièrement', !$estAffiche($sousMenu),
    'ses entrées sont imbriquées dans son propre rendu');

// C'est précisément ce qui rendait les documents du club injoignables : leur
// lien n'existait qu'à l'intérieur du sous-menu « Mon espace ». La page, elle,
// est publique — le filtre doit la laisser passer partout ailleurs.
wp_set_current_user(0);
MenuVisibility::forget();
$check('Visiteur : les documents du club restent affichés', $estAffiche($linkTo($docsId)),
    'la page liste les documents publics sans exiger de compte');

wp_set_current_user($member);
MenuVisibility::forget();
$check('Adhérent : la même entrée, depuis son espace', $estAffiche($linkTo($docsId)));
wp_set_current_user(0);
MenuVisibility::forget();

// --- Plan du site ------------------------------------------------------------
echo "\n--- Plan du site ---\n";

wp_set_current_user(0);
$visitorMap = do_shortcode('[subalcatel_plan_du_site]');

wp_set_current_user($member);
$memberMap = do_shortcode('[subalcatel_plan_du_site]');

$check('Le visiteur voit moins d’entrées',
    substr_count($visitorMap, '<li>') < substr_count($memberMap, '<li>'),
    substr_count($visitorMap, '<li>') . ' contre ' . substr_count($memberMap, '<li>'));
$check('L’espace membre est absent pour le visiteur',
    !str_contains($visitorMap, '/espace-membre/profil/'));
$check('Il est présent pour l’adhérent', str_contains($memberMap, '/espace-membre/profil/'));

wp_set_current_user(0);

// --- Classes de corps --------------------------------------------------------
echo "\n--- Signalement au thème ---\n";

$check('Visiteur signalé', in_array('sub-visitor', MenuVisibility::bodyClass([]), true));

wp_set_current_user($lapsed);
$lapsedClasses = MenuVisibility::bodyClass([]);
$check('Connecté sans adhésion : connecté mais pas adhérent',
    in_array('sub-connected', $lapsedClasses, true) && !in_array('sub-member', $lapsedClasses, true));

wp_set_current_user($office);
$check('Bureau signalé', in_array('sub-office', MenuVisibility::bodyClass([]), true));

wp_set_current_user(0);

// --- Nettoyage ---------------------------------------------------------------
require_once ABSPATH . 'wp-admin/includes/user.php';

wp_delete_post($reserved, true);
wp_update_post(['ID' => $clubId, 'post_content' => '']);
delete_post_meta($clubId, '_sub_page_hash');
SiteBuilder::run();

$menusStables('après 5 installations');

foreach ([$member, $lapsed, $office] as $id) {
    wp_delete_user($id);
}

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
