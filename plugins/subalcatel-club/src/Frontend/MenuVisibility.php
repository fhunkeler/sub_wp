<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use Subalcatel\Club\Content\Visibility;
use Subalcatel\Club\Identity\Roles;

/**
 * Les navigations s'adaptent à qui regarde.
 *
 * Un menu qui affiche « Mon profil » à un visiteur déconnecté lui promet une
 * page qu'il ne verra pas. Chaque entrée est donc soumise au même contrôle que
 * la page elle-même — un seul point de décision, [Visibility::mayRead].
 *
 * Le thème du club est un **thème de blocs** : sa navigation n'est pas un menu
 * WordPress classique mais des blocs `navigation-link` écrits dans le gabarit.
 * Le filtre porte donc sur le rendu des blocs, seul endroit où ces entrées
 * existent. Les deux mécanismes sont couverts, pour qu'un changement de thème
 * ne rouvre pas silencieusement des liens.
 *
 * **Ce filtrage est du confort, pas de la sécurité.** Ce qui protège, c'est le
 * contrôle à l'ouverture de la page : masquer un lien n'empêche personne de
 * taper l'adresse.
 */
final class MenuVisibility
{
    /**
     * Blocs de navigation portant une adresse.
     *
     * @var list<string>
     */
    private const LINK_BLOCKS = ['core/navigation-link', 'core/navigation-submenu'];

    /** @var array<string, bool> */
    private static array $readable = [];

    public static function register(): void
    {
        // `render_block`, pas `pre_render_block` : le bloc Navigation rend ses
        // enfants par `WP_Block::render()`, qui ne déclenche jamais le second.
        // Le filtre paraissait correct et ne voyait aucune entrée de menu.
        add_filter('render_block', [self::class, 'hideBlockLink'], 10, 2);
        add_filter('wp_get_nav_menu_items', [self::class, 'filterClassicItems'], 10, 3);
        add_filter('body_class', [self::class, 'bodyClass']);
    }

    /**
     * Retire une entrée de navigation inaccessible du HTML produit.
     *
     * On vide le rendu plutôt que de masquer en CSS : l'entrée n'est pas
     * envoyée au navigateur. Un affichage caché révélerait la structure du site
     * et survivrait au cache de pages.
     *
     * @param string               $content rendu du bloc
     * @param array<string, mixed> $block
     */
    public static function hideBlockLink(string $content, array $block): string
    {
        if (!in_array((string) ($block['blockName'] ?? ''), self::LINK_BLOCKS, true)) {
            return $content;
        }

        $url = (string) ($block['attrs']['url'] ?? '');

        if ($url === '') {
            return $content;
        }

        // Une entrée de sous-menu masquée emporte ses enfants : ils sont
        // imbriqués dans son propre rendu.
        return self::isReadable($url) ? $content : '';
    }

    /**
     * Même contrôle pour les menus classiques, si le thème en utilise.
     *
     * @param array<int, \WP_Post> $items
     * @return array<int, \WP_Post>
     */
    public static function filterClassicItems(array $items, mixed $menu, mixed $args): array
    {
        if (is_admin()) {
            return $items;
        }

        $removed = [];

        foreach ($items as $key => $item) {
            $pageId = (int) $item->object_id;
            $hidden = $item->object === 'page' && $pageId > 0 && !Visibility::mayRead($pageId);

            // Une entrée dont le parent est tombé tombe avec lui : garder un
            // sous-menu orphelin le remonterait au premier niveau.
            if ($hidden || isset($removed[(int) $item->menu_item_parent])) {
                $removed[(int) $item->ID] = true;
                unset($items[$key]);
            }
        }

        return array_values($items);
    }

    /**
     * Classes utiles au thème pour adapter l'affichage.
     *
     * @param array<int, string> $classes
     * @return array<int, string>
     */
    public static function bodyClass(array $classes): array
    {
        if (!is_user_logged_in()) {
            $classes[] = 'sub-visitor';

            return $classes;
        }

        $classes[] = 'sub-connected';

        if (\Subalcatel\Club\Content\ClubDocuments::isActiveMember(get_current_user_id())) {
            $classes[] = 'sub-member';
        }

        foreach (array_keys(Roles::CAPABILITIES) as $capability) {
            if (current_user_can($capability)) {
                $classes[] = 'sub-office';
                break;
            }
        }

        return $classes;
    }

    /**
     * L'adresse mène-t-elle à une page que cette personne peut ouvrir ?
     *
     * Une adresse externe, ou qui ne correspond à aucune page, passe : ce
     * filtre retire ce qui est refusé, il n'autorise rien. Le résultat est mis
     * en cache — une navigation appelle ce contrôle une fois par entrée et par
     * page affichée.
     */
    private static function isReadable(string $url): bool
    {
        if (isset(self::$readable[$url])) {
            return self::$readable[$url];
        }

        $postId = self::resolvePost($url);

        return self::$readable[$url] = $postId === 0 || Visibility::mayRead($postId);
    }

    /**
     * Page visée par une adresse de menu, ou 0.
     *
     * `url_to_postid()` ne suffit pas : il **ne résout rien** tant que les
     * permaliens sont en réglage « simple » (`?p=123`), qui est celui d'une
     * installation neuve. Le filtre laissait alors passer toutes les entrées, et
     * un visiteur voyait le menu « Mon espace » au complet. Le défaut ne se
     * voyait pas en développement, où les jolis permaliens étaient déjà actifs.
     *
     * On retombe donc sur le chemin, qui reste valable quelle que soit la
     * structure choisie par l'hébergeur.
     */
    private static function resolvePost(string $url): int
    {
        $absolute = str_starts_with($url, 'http') ? $url : home_url($url);

        $postId = url_to_postid($absolute);

        if ($postId > 0) {
            return $postId;
        }

        // Adresse de la forme ?page_id=12 ou ?p=12.
        parse_str((string) parse_url($absolute, PHP_URL_QUERY), $query);

        foreach (['page_id', 'p'] as $key) {
            if (isset($query[$key]) && (int) $query[$key] > 0) {
                return (int) $query[$key];
            }
        }

        $path = (string) parse_url($absolute, PHP_URL_PATH);
        $home = (string) parse_url(home_url('/'), PHP_URL_PATH);

        // Le site peut vivre dans un sous-dossier : on retire sa base.
        if ($home !== '' && $home !== '/' && str_starts_with($path, $home)) {
            $path = substr($path, strlen($home));
        }

        $path = trim($path, '/');

        if ($path === '') {
            return 0;
        }

        $page = get_page_by_path($path);

        return $page instanceof \WP_Post ? $page->ID : 0;
    }

    /**
     * Vide le cache.
     *
     * Sans objet en production — une requête sert un seul utilisateur. Utile
     * aux tests, qui changent d'identité dans le même processus.
     */
    public static function forget(): void
    {
        self::$readable = [];
    }
}
