<?php
/**
 * Test de fumée du thème : gabarits, fil d'Ariane, patterns, aperçu public.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-theme.php
 *
 * Le thème et l'extension se répondent : les gabarits sur mesure ne servent que
 * si les pages leur sont affectées, et les patterns ne servent que si quelqu'un
 * les pose. C'est cette jonction que l'on vérifie ici — chacun pris isolément
 * paraissait complet.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Frontend\Pages;
use Subalcatel\Club\Setup\SiteBuilder;
use Subalcatel\Club\Setup\SiteMap;

// `wp_login_form()` reconstruit l'URL courante et lit HTTP_HOST, absent en
// ligne de commande. On le pose : c'est l'environnement qui manque, pas le code.
$_SERVER['HTTP_HOST'] ??= (string) wp_parse_url(home_url(), PHP_URL_HOST);
$_SERVER['REQUEST_URI'] ??= '/';

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-58s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

SiteBuilder::run();

// --- Le thème est-il bien celui du club ? ------------------------------------
echo "\n--- Thème actif ---\n";

$theme = wp_get_theme();
$check('Thème Sub Alcatel actif', $theme->get_stylesheet() === 'subalcatel', $theme->get('Name'));
$check('Thème de blocs', wp_is_block_theme());

// --- Gabarits sur mesure -----------------------------------------------------
echo "\n--- Gabarits sur mesure ---\n";

$declared = [];

$themeJson = json_decode(
    (string) file_get_contents(get_theme_file_path('theme.json')),
    true
) ?: [];

foreach ($themeJson['customTemplates'] ?? [] as $template) {
    $declared[] = (string) $template['name'];
}

$check('Gabarits déclarés dans theme.json', count($declared) >= 9, implode(', ', $declared));

$missing = [];

foreach ($declared as $name) {
    if (!file_exists(get_theme_file_path("templates/{$name}.html"))) {
        $missing[] = $name;
    }
}

$check('Chaque gabarit déclaré a son fichier', $missing === [],
    $missing === [] ? '' : 'manquants : ' . implode(', ', $missing));

// L'inverse compte autant : un gabarit livré mais jamais affecté est du code
// mort, et c'était le cas de tous avant cette étape.
$assigned = [];

foreach (SiteMap::pages() as $page) {
    if (isset($page['template'])) {
        $assigned[(string) $page['template']] = true;
    }
}

$unused = array_values(array_diff($declared, array_keys($assigned)));

// `page-pleine-largeur` reste volontairement libre : c'est une option offerte
// au bureau pour ses propres pages, pas un gabarit dédié.
$check('Les gabarits livrés sont utilisés', $unused === ['page-pleine-largeur'],
    $unused === [] ? 'tous affectés' : 'non affectés : ' . implode(', ', $unused));

foreach (['nous-rejoindre/tarifs' => 'page-tarifs', 'le-club/equipe' => 'page-equipe',
          'agenda' => 'page-agenda', 'contact' => 'page-contact'] as $key => $expected) {
    $id = Pages::id($key);
    $check(
        sprintf('« %s » utilise %s', $key, $expected),
        get_post_meta($id, '_wp_page_template', true) === $expected,
        (string) get_post_meta($id, '_wp_page_template', true)
    );
}

// --- Patterns ----------------------------------------------------------------
echo "\n--- Patterns du thème ---\n";

$registry = \WP_Block_Patterns_Registry::get_instance();

foreach ([
    'subalcatel/grille-tarifs',
    'subalcatel/trombinoscope',
    'subalcatel/carte-formation',
    'subalcatel/hero-accueil',
    'subalcatel/encart-contact',
] as $slug) {
    $check(sprintf('Pattern « %s » enregistré', $slug), $registry->is_registered($slug));
}

$posed = 0;

foreach (SiteMap::pages() as $page) {
    $posed += substr_count((string) ($page['content'] ?? ''), 'wp:pattern');
}

$check('Des patterns sont posés sur les pages', $posed >= 2, $posed . ' occurrence(s)');

// La grille de tarifs fait exception : elle est lue depuis la campagne
// configurée, pas recopiée d'un pattern. Un pattern y remettrait des montants
// figés que personne ne penserait à corriger l'année suivante.
$pricing = '';

foreach (SiteMap::pages() as $page) {
    if ($page['key'] === Pages::PRICING) {
        $pricing = (string) ($page['content'] ?? '');
    }
}

$check('La page des tarifs lit la campagne', str_contains($pricing, '[subalcatel_tarifs]'));
$check('Elle n’utilise pas le pattern à montants figés',
    !str_contains($pricing, 'grille-tarifs'),
    'le pattern reste disponible dans l’éditeur pour un usage ponctuel');

// --- Fil d’Ariane ------------------------------------------------------------
echo "\n--- Fil d’Ariane ---\n";

$check('Bloc enregistré',
    \WP_Block_Type_Registry::get_instance()->is_registered('subalcatel/fil-ariane'));

$templates = glob(get_theme_file_path('templates/*.html')) ?: [];
$withTrail = 0;
$hardcoded = [];

foreach ($templates as $file) {
    $html = (string) file_get_contents($file);

    if (str_contains($html, 'subalcatel/fil-ariane')) {
        $withTrail++;
    }

    // Un chemin écrit en dur devient faux dès qu'une page change de parent.
    if (preg_match('/<p class="sub-fil-ariane">\s*<a/', $html)) {
        $hardcoded[] = basename($file);
    }
}

$check('Le fil d’Ariane est posé dans les gabarits', $withTrail >= 10, $withTrail . ' gabarit(s)');
$check('Aucun chemin écrit en dur', $hardcoded === [],
    $hardcoded === [] ? '' : implode(', ', $hardcoded));

// Rendu réel, sur une page profondément imbriquée. L'espace membre exige une
// connexion : sans elle, les ancêtres sont — à juste titre — masqués.
global $wp_query, $post;

$member = wp_insert_user([
    'user_login' => 'demo_' . wp_generate_password(8, false),
    'user_email' => wp_generate_password(8, false) . '@subalcatel.test',
    'user_pass'  => wp_generate_password(),
    'role'       => 'sub_member',
]);
update_user_meta($member, 'sub_membership_valid_until', '2027-12-31');

$deep = Pages::id(Pages::MY_DOCUMENTS);
$post = get_post($deep);
setup_postdata($post);
$wp_query = new WP_Query(['page_id' => $deep]);
$wp_query->the_post();

wp_set_current_user($member);
$trail = subalcatel_render_breadcrumb();

$check('Le fil suit la hiérarchie réelle',
    substr_count($trail, '›') === 3,
    trim(wp_strip_all_tags(str_replace('›', ' > ', $trail))));
$check('La page courante n’est pas un lien', str_contains($trail, 'aria-current="page"'));

// Le même fil, vu par un visiteur : les ancêtres réservés disparaissent plutôt
// que de proposer des liens vers des portes fermées.
wp_set_current_user(0);
$visitorTrail = subalcatel_render_breadcrumb();

$check('Un visiteur ne voit pas les ancêtres réservés',
    substr_count($visitorTrail, '›') === 1,
    trim(wp_strip_all_tags(str_replace('›', ' > ', $visitorTrail))));

require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($member);

wp_reset_postdata();
$wp_query = new WP_Query(['page_id' => Pages::id(Pages::HOME)]);
$wp_query->the_post();
$check('Aucun fil sur la page d’accueil', subalcatel_render_breadcrumb() === '',
    'un fil qui ne contient que « Accueil » n’apprend rien');
wp_reset_postdata();

// --- Aperçu public des sorties -----------------------------------------------
echo "\n--- Prochaines sorties sur l’accueil ---\n";

$frontPage = (string) file_get_contents(get_theme_file_path('templates/front-page.html'));
$check('L’accueil appelle l’aperçu',
    str_contains($frontPage, 'subalcatel_prochaines_sorties'));

wp_set_current_user(0);
$teaser = do_shortcode('[subalcatel_prochaines_sorties limite="3"]');

$check('Il s’affiche sans connexion', str_contains($teaser, 'sub-upcoming'),
    'un club qui ne montre rien de sa vie n’attire personne');
$check('Aucun formulaire d’inscription', !str_contains($teaser, '<form'),
    'montrer n’est pas inscrire');
$check('Aucune place exposée', !str_contains($teaser, 'place(s)'));
$check('Il invite à se connecter', str_contains($teaser, 'Se connecter pour s'));

$check('La limite est respectée', substr_count($teaser, 'sub-upcoming__item') <= 3,
    substr_count($teaser, 'sub-upcoming__item') . ' entrée(s)');
$check('Une limite absurde est ramenée à une borne',
    substr_count(do_shortcode('[subalcatel_prochaines_sorties limite="999"]'), 'sub-upcoming__item') <= 12);

// --- Connexion aux couleurs du club ------------------------------------------
echo "\n--- Page de connexion ---\n";

$check('La page existe', Pages::exists(Pages::LOGIN),
    str_replace(home_url(), '', Pages::url(Pages::LOGIN)));
$check('Elle utilise le gabarit dédié',
    get_post_meta(Pages::id(Pages::LOGIN), '_wp_page_template', true) === 'page-connexion');

wp_set_current_user(0);
$form = do_shortcode('[subalcatel_connexion]');

$check('Le formulaire est rendu', str_contains($form, 'name="log"') && str_contains($form, 'name="pwd"'));
$check('Le mot de passe oublié est proposé', str_contains($form, 'Mot de passe oublié'));
$check('La provenance est marquée', str_contains($form, 'sub_login_page'),
    'c’est elle qui ramène un échec sur cette page plutôt que sur l’écran natif');

$check('Le thème pointe vers cette page',
    str_contains(subalcatel_login_url('/espace-membre/'), '/connexion/'));

// Une adresse externe passée en `redirect_to` ne doit pas servir de tremplin.
$_GET['redirect_to'] = 'https://exemple-malveillant.test/';
$external = do_shortcode('[subalcatel_connexion]');
unset($_GET['redirect_to']);

$check('Une redirection externe est ignorée',
    !str_contains($external, 'exemple-malveillant'),
    'un lien de connexion n’est pas un tremplin');

// --- Parties de gabarit ------------------------------------------------------
echo "\n--- Parties de gabarit ---\n";

foreach (['header', 'footer'] as $part) {
    $check(sprintf('Partie « %s » présente', $part),
        file_exists(get_theme_file_path("parts/{$part}.html")));
}

// --- Espace membre dans le menu principal ------------------------------------
echo "\n--- Menu de l’espace membre ---\n";

$header = (string) file_get_contents(get_theme_file_path('parts/header.html'));

$check('Plus de seconde barre de navigation',
    !file_exists(get_theme_file_path('parts/header-membre.html')),
    'six entrées qui défilaient hors écran sur téléphone');

$stillReferenced = [];

foreach (glob(get_theme_file_path('templates/*.html')) ?: [] as $file) {
    if (str_contains((string) file_get_contents($file), 'header-membre')) {
        $stillReferenced[] = basename($file);
    }
}

$check('Aucun gabarit ne la réclame', $stillReferenced === [], implode(', ', $stillReferenced));

$check('« Mon espace » est dans le menu principal',
    str_contains($header, '"label":"Mon espace"'));

foreach ([
    '/espace-membre/agenda/',
    '/espace-membre/inscriptions/',
    '/espace-membre/adhesion/',
    '/espace-membre/documents/',
    '/espace-membre/profil/',
] as $target) {
    $check(sprintf('Le sous-menu mène à %s', $target), str_contains($header, $target));
}

// Le point qui avait échappé : le filtre doit être branché sur `render_block`.
// Le bloc Navigation rend ses enfants par une voie que `pre_render_block`
// n'emprunte pas — l'entrée réservée s'affichait alors à tout le monde.
$espaceId = Pages::id(Pages::MEMBER_AREA);
$entree   = [
    'blockName'    => 'core/navigation-submenu',
    'attrs'        => ['url' => '/espace-membre/', 'label' => 'Mon espace'],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
];

wp_set_current_user(0);
\Subalcatel\Club\Frontend\MenuVisibility::forget();
$check('Un visiteur ne voit pas « Mon espace »', trim(render_block($entree)) === '',
    'vérifié par le rendu réel, pas par un appel direct au filtre');

$membre = wp_insert_user([
    'user_login' => 'demo_' . wp_generate_password(8, false),
    'user_email' => wp_generate_password(8, false) . '@subalcatel.test',
    'user_pass'  => wp_generate_password(),
    'role'       => 'sub_member',
]);

wp_set_current_user($membre);
\Subalcatel\Club\Frontend\MenuVisibility::forget();
$check('Un membre le voit', trim(render_block($entree)) !== '');

wp_set_current_user(0);
\Subalcatel\Club\Frontend\MenuVisibility::forget();
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($membre);

$css = (string) file_get_contents(get_theme_file_path('assets/css/site.css'));
$check('Les styles de la barre sont retirés', !str_contains($css, 'sub-barre-membre'),
    'du code mort dans une feuille de style finit par revenir');

$memberBar = $header;


$check('Le menu ne pointe que des pages existantes',
    !str_contains($memberBar, '/annuaire/'),
    'l’annuaire n’est pas développé : un lien mort vaut moins qu’un lien absent');

// --- Vignettes des cartes d'article ------------------------------------------
echo "\n--- Vignettes des cartes ---\n";

// Six gabarits affichent des cartes d'article. Le repli du thème et la taille
// d'image ne s'appliquent qu'aux blocs qui le demandent : un gabarit oublié se
// remarque à l'œil des mois plus tard, sur une seule page de catégorie.
$gabaritsCartes = ['front-page', 'home', 'index', 'archive', 'search', 'single'];
$sansMarque     = [];
$sansTaille     = [];

foreach ($gabaritsCartes as $nom) {
    $html = (string) file_get_contents(get_theme_file_path("templates/{$nom}.html"));

    // Les blocs de carte se reconnaissent à leur hauteur fixe ; celui de
    // `single` en tête d'article n'en a pas et n'est pas concerné.
    if (!preg_match_all('#<!-- wp:post-featured-image (\{[^}]*"height"[^}]*\}) /-->#', $html, $blocs)) {
        continue;
    }

    foreach ($blocs[1] as $attributs) {
        if (!str_contains($attributs, '"className":"sub-vignette"')) {
            $sansMarque[] = $nom;
        }

        if (!str_contains($attributs, '"sizeSlug":"subalcatel-carte"')) {
            $sansTaille[] = $nom;
        }
    }
}

$check('Toutes les cartes demandent le repli', $sansMarque === [],
    $sansMarque === [] ? '' : 'sans marque : ' . implode(', ', $sansMarque));
$check('Toutes les cartes demandent la taille « carte »', $sansTaille === [],
    $sansTaille === []
        ? 'sinon le navigateur télécharge l’original — jusqu’à 2 560 px pour 370'
        : 'pleine taille : ' . implode(', ', $sansTaille));

$check('La taille « carte » est enregistrée',
    has_image_size('subalcatel-carte'),
    'le gabarit la réclame : sans elle, WordPress sert l’original');

// Le repli lui-même, vérifié par le rendu réel. Un article sans image doit
// produire un bloc de la même hauteur que ses voisins, et rien à lire.
$sansPhoto = wp_insert_post([
    'post_title'   => 'Article sans photo ' . wp_generate_password(6, false),
    'post_content' => '<p>Une permanence, une date, aucune image.</p>',
    'post_status'  => 'publish',
]);

$carte = [
    'blockName'    => 'core/post-featured-image',
    'attrs'        => [
        'isLink'    => true,
        'sizeSlug'  => 'subalcatel-carte',
        'height'    => '200px',
        'className' => 'sub-vignette',
    ],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
];

// Le contexte de boucle est posé à la main : c'est `core/post-template` qui le
// fournit en page, et c'est de lui que le repli tire l'article à illustrer.
$rendu = (new WP_Block($carte, ['postId' => $sansPhoto, 'postType' => 'post']))->render();

$check('Un article sans photo reçoit un repli',
    str_contains($rendu, 'sub-vignette--repli'),
    'sinon la carte commence par sa catégorie et rompt la grille');
$check('Le repli garde la hauteur des vignettes voisines',
    str_contains($rendu, 'height:200px'));
$check('Le repli ne parle pas aux lecteurs d’écran',
    str_contains($rendu, 'aria-hidden="true"') && str_contains($rendu, 'tabindex="-1"'),
    'un aplat décoratif n’a rien à annoncer, et le titre porte déjà le lien');

wp_delete_post($sansPhoto, true);

// --- Logo et icône du site ---------------------------------------------------
echo "\n--- Logo du club ---\n";

foreach ([
    'logo.png',
    'logo-complet.png',
    'logo-empreinte.png',
    'favicon-32.png',
    'favicon-180.png',
    'favicon-192.png',
    'favicon-270.png',
    'favicon-512.png',
] as $fichier) {
    $check(sprintf('assets/img/%s présent', $fichier),
        file_exists(get_theme_file_path("assets/img/{$fichier}")));
}

// Le bloc « Logo du site » ne rend rien sans pièce jointe : c'est inc/logo.php
// qui pose celui du thème. Sans lui, l'en-tête affiche le titre tout seul.
$logo = (new WP_Block([
    'blockName'    => 'core/site-logo',
    'attrs'        => ['width' => 40],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
]))->render();

$check('Un logo s’affiche sans téléversement',
    str_contains($logo, 'assets/img/logo.png') || has_custom_logo(),
    'sinon l’en-tête montre le titre sans sa marque');
$check('Il porte un texte alternatif',
    (bool) preg_match('/<img[^>]+alt="[^"]+"/', $logo),
    'le lien qui l’enveloppe n’aurait aucun nom accessible');
$check('Il respecte la largeur demandée par le bloc',
    str_contains($logo, 'width="40"'));

$check('L’icône du site est fournie par le thème',
    str_contains(get_site_icon_url(192), 'favicon-192.png') || (int) get_option('site_icon') !== 0,
    'sans elle, /favicon.ico renvoie au « W » de wordpress.org');

// --- Contrastes de la palette ------------------------------------------------
//
// La palette est relevée sur le logo du club (voir design-arborescence.md §2.1).
// Une teinte ajustée « à l'œil » pour mieux coller au dessin peut passer sous le
// seuil sans que rien ne le signale : c'est arrivé à la baseline de l'en-tête,
// tombée de 4,57 à 3,73:1 lors de la reprise. D'où ce contrôle, qui rejoue les
// paires réellement employées.
//
// Seuils WCAG 2.1 AA : 4,5:1 pour le texte courant, 3:1 pour le grand texte
// (≥ 24 px, ou ≥ 18,66 px en gras) et pour les éléments non textuels (1.4.11).
echo "\n--- Contrastes (WCAG 2.1 AA) ---\n";

$luminance = static function (string $hex): float {
    $hex = ltrim($hex, '#');
    $canal = static function (int $v): float {
        $v /= 255;
        return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * $canal((int) hexdec(substr($hex, 0, 2)))
         + 0.7152 * $canal((int) hexdec(substr($hex, 2, 2)))
         + 0.0722 * $canal((int) hexdec(substr($hex, 4, 2)));
};

$ratio = static function (string $a, string $b) use ($luminance): float {
    $la = $luminance($a);
    $lb = $luminance($b);

    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
};

// Les couleurs sont lues dans theme.json, pas recopiées : une valeur changée
// là-bas doit faire échouer ce test, pas passer inaperçue.
$palette = [];

foreach ($themeJson['settings']['color']['palette'] ?? [] as $couleur) {
    $palette[(string) $couleur['slug']] = (string) $couleur['color'];
}

$check('La palette est lue depuis theme.json', count($palette) >= 14, count($palette) . ' couleurs');

// Teintes claires qui n'existent que sur fond sombre : hors palette de
// l'éditeur, où elles seraient posées sur du blanc. Voir site.css §3 et §12.
$horsPalette = [
    'nav'      => '#CFDDEC',
    'baseline' => '#64BBDC',
    'pied'     => '#B0C4DA',
];

$paires = [
    // libellé,                          avant-plan,             arrière-plan,          seuil
    ['Texte courant sur blanc',          $palette['abysse'],     '#FFFFFF',             4.5],
    ['Liens sur blanc',                  $palette['lien'],       '#FFFFFF',             4.5],
    ['Texte secondaire sur blanc',       $palette['ardoise'],    '#FFFFFF',             4.5],
    ['Titres de section sur blanc',      $palette['profond'],    '#FFFFFF',             3.0],
    ['Texte courant sur écume',          $palette['abysse'],     $palette['ecume'],     4.5],
    ['Liens sur écume',                  $palette['lien'],       $palette['ecume'],     4.5],
    ['Texte secondaire sur écume',       $palette['ardoise'],    $palette['ecume'],     4.5],
    ['Titre du site sur l’en-tête',      $palette['blanc'],      $palette['abysse'],    4.5],
    ['Navigation sur l’en-tête',         $horsPalette['nav'],    $palette['abysse'],    4.5],
    ['Baseline sur l’en-tête',           $horsPalette['baseline'], $palette['abysse'],  4.5],
    ['Pied de page',                     $horsPalette['pied'],   $palette['abysse'],    4.5],
    ['Surtitre sur fond sombre',         $palette['sable'],      $palette['abysse'],    4.5],
    ['Bouton d’action principale',       $palette['blanc'],      $palette['corail-fonce'], 4.5],
    ['Badge « en attente »',             $palette['abysse'],     $palette['sable'],     4.5],
    ['Badge « validé »',                 $palette['blanc'],      $palette['algue'],     4.5],
    ['Badge « refusé »',                 $palette['blanc'],      $palette['alerte'],    4.5],
    ['Champ obligatoire, texte',         $palette['alerte'],     $palette['blanc'],     4.5],
    ['Contour de focus sur blanc',       $palette['lagon'],      '#FFFFFF',             3.0],
    ['Contour de focus sur écume',       $palette['lagon'],      $palette['ecume'],     3.0],
    ['Contour de focus sur sombre',      $palette['sable'],      $palette['abysse'],    3.0],
    ['Bordure de champ sur blanc',       $themeJson['settings']['custom']['bordure']['champ'], '#FFFFFF', 3.0],
    ['Onglet actif (indicateur)',        $palette['corail-fonce'], '#FFFFFF',           3.0],
];

foreach ($paires as [$libelle, $avant, $arriere, $seuil]) {
    $mesure = $ratio($avant, $arriere);
    $check(
        $libelle,
        $mesure >= $seuil,
        sprintf('%s sur %s — %.2f:1 (seuil %.1f)', $avant, $arriere, $mesure, $seuil)
    );
}

// Le bloc « Club » du tableau de bord de WordPress et les écrans
// d'administration ne passent pas par theme.json : leurs teintes sont écrites
// dans admin.css, hors de portée du contrôle ci-dessus. Elles y sont donc
// reprises à la main — c'est du texte blanc sur pastille, et l'ambre y était
// tombé à 4,27:1.
$adminCss = [
    ['Pastille « à traiter » (bloc du tableau de bord)', '#FFFFFF', '#2271B1', 4.5],
    ['Pastille « alerte » (bloc du tableau de bord)',    '#FFFFFF', '#B82A1E', 4.5],
    ['Pastille « à surveiller » (bloc du tableau de bord)', '#FFFFFF', '#A66000', 4.5],
    ['Attente en ambre, sur blanc',                      '#A66000', '#FFFFFF', 4.5],
    ['Compteur neutre du bloc',                          '#1D2327', '#F0F0F1', 4.5],
];

foreach ($adminCss as [$libelle, $avant, $arriere, $seuil]) {
    $mesure = $ratio($avant, $arriere);
    $check(
        $libelle,
        $mesure >= $seuil,
        sprintf('%s sur %s — %.2f:1 (seuil %.1f)', $avant, $arriere, $mesure, $seuil)
    );
}

$check('L’ambre d’admin.css est bien celui qui a été vérifié',
    str_contains((string) file_get_contents(\Subalcatel\Club\PLUGIN_DIR . 'assets/css/admin.css'), '#a66000')
    && !str_contains((string) file_get_contents(\Subalcatel\Club\PLUGIN_DIR . 'assets/css/admin.css'), '#b26900'),
    'la valeur du test doit suivre celle de la feuille, pas s’en écarter');

// Deux teintes de décor, jamais de lecture : si l'une d'elles atteint 4,5:1 sur
// blanc, c'est qu'elle a été assombrie et qu'elle peut redevenir une couleur de
// texte. Le contraire — les employer en texte — est le vrai piège, et c'est ce
// que la charte interdit.
foreach (['corail', 'lagon'] as $decor) {
    $check(
        sprintf('« %s » reste une couleur de décor', $decor),
        $ratio($palette[$decor], '#FFFFFF') < 4.5,
        sprintf('%.2f:1 sur blanc — à réserver aux surfaces et aux bordures', $ratio($palette[$decor], '#FFFFFF'))
    );
}

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
