<?php

declare(strict_types=1);

namespace Subalcatel\Club\Setup;

use Subalcatel\Club\Content\Visibility;
use Subalcatel\Club\Frontend\Pages;
use Subalcatel\Club\Support\Audit;

/**
 * Installe l'arborescence du site : pages, hiérarchie, visibilité, menus.
 *
 * Rejouable sans dégât. Deux mécanismes le garantissent :
 *
 * 1. Chaque page porte une **clé fonctionnelle** en méta. C'est elle qui sert à
 *    la retrouver, pas son titre ni son adresse — le bureau peut renommer et
 *    déplacer sans provoquer de doublon à la réinstallation.
 * 2. Le contenu installé est **empreinté**. À la réinstallation, une page dont
 *    le texte a été modifié depuis n'est pas écrasée : on ne réécrit que ce que
 *    personne n'a touché. Perdre une page rédigée un dimanche soir par un
 *    bénévole parce qu'on a recliqué sur un bouton serait impardonnable.
 */
final class SiteBuilder
{
    private const HASH_META = '_sub_page_hash';

    /**
     * @return array{created: int, updated: int, preserved: int, menus: int, messages: list<string>}
     */
    public static function run(): array
    {
        $created = $updated = $preserved = 0;
        $messages = [];
        $ids      = [];

        // Première passe : garantir que chaque page existe, et rien de plus.
        // Les liens entre pages ne peuvent être écrits qu'une fois toutes les
        // adresses connues.
        foreach (SiteMap::pages() as $page) {
            [$id, $isNew] = self::ensureExists($page, $ids);

            $ids[$page['key']] = $id;

            if ($isNew) {
                $created++;
            }
        }

        // Seconde passe : contenu, hiérarchie, visibilité.
        foreach (SiteMap::pages() as $page) {
            $status = self::fill($page, $ids);

            if ($status === 'preserved') {
                $preserved++;
                $messages[] = sprintf('« %s » conservée : son contenu a été modifié.', $page['title']);
            } elseif ($status === 'updated') {
                $updated++;
            }
        }

        // Une page qui vient d'être créée est aussi remplie : on ne la compte
        // pas deux fois.
        $updated = max(0, $updated - $created);

        $menus = self::buildMenus($ids);

        Pages::forget();
        Audit::log('site.built', 'site', null, [
            'created'   => $created,
            'updated'   => $updated,
            'preserved' => $preserved,
        ]);

        return [
            'created'   => $created,
            'updated'   => $updated,
            'preserved' => $preserved,
            'menus'     => $menus,
            'messages'  => $messages,
        ];
    }

    /**
     * Crée la page si elle n'existe pas encore, sans y écrire de contenu.
     *
     * @param array<string, mixed> $page
     * @param array<string, int>   $ids
     * @return array{0: int, 1: bool} identifiant, et vrai si elle vient d'être créée
     */
    private static function ensureExists(array $page, array $ids): array
    {
        $existing = self::findExisting($page);

        if ($existing !== null) {
            return [$existing->ID, false];
        }

        $id = wp_insert_post([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => (string) $page['title'],
            'post_name'   => self::slugOf((string) $page['key']),
        ]);

        if (is_wp_error($id) || $id === 0) {
            return [0, false];
        }

        update_post_meta((int) $id, Pages::KEY_META, (string) $page['key']);

        return [(int) $id, true];
    }

    /**
     * Écrit contenu, hiérarchie et visibilité.
     *
     * @param array<string, mixed> $page
     * @param array<string, int>   $ids
     * @return string `updated` ou `preserved`
     */
    private static function fill(array $page, array $ids): string
    {
        $id = $ids[$page['key']] ?? 0;

        if ($id === 0) {
            return 'failed';
        }

        $post     = get_post($id);
        $parentId = isset($page['parent']) ? ($ids[$page['parent']] ?? 0) : 0;

        // Les liens sont résolus **avant** l'enregistrement. Les laisser sous
        // forme de jetons dans un `href` les ferait disparaître : kses prend
        // « %%URL: » pour un protocole inconnu et le supprime sans rien dire.
        $content = self::resolveLinks((string) ($page['content'] ?? ''), $ids);

        $installedHash = (string) get_post_meta($id, self::HASH_META, true);
        $untouched     = $installedHash === '' || $installedHash === md5((string) $post->post_content);

        $update = [
            'ID'          => $id,
            'post_parent' => $parentId,
            'post_name'   => self::slugOf((string) $page['key']),
        ];

        // Une page reprise peut être restée en brouillon — c'est le cas de la
        // page de confidentialité que WordPress crée à l'installation. Une page
        // du plan est une page publiée : sans cela, son adresse reste
        // « /?page_id=3 » et le lien du pied de page ne mène nulle part d'utile.
        if ($post->post_status !== 'publish') {
            $update['post_status'] = 'publish';
        }

        if ($untouched) {
            $update['post_content'] = $content;
            $update['post_title']   = (string) $page['title'];
        }

        wp_update_post($update);
        self::applyRoles($id, $page);

        if (!$untouched) {
            return 'preserved';
        }

        // L'empreinte est prise sur le contenu **tel qu'enregistré** :
        // WordPress assainit à la volée, et comparer à ce qu'on croyait avoir
        // écrit signalerait une modification à chaque passage.
        $saved = get_post($id);
        update_post_meta($id, self::HASH_META, md5((string) $saved->post_content));

        return 'updated';
    }

    /**
     * Remplace les jetons `%%URL:clé%%` par les adresses réelles.
     *
     * @param array<string, int> $ids
     */
    private static function resolveLinks(string $content, array $ids): string
    {
        if (!str_contains($content, '%%URL:')) {
            return $content;
        }

        return preg_replace_callback(
            '/%%URL:([^%]+)%%/',
            static function (array $matches) use ($ids): string {
                $target = $ids[$matches[1]] ?? 0;

                return $target === 0 ? '#' : (string) get_permalink($target);
            },
            $content
        ) ?? $content;
    }

    /**
     * Visibilité, page d'accueil, page des articles, page de confidentialité.
     *
     * @param array<string, mixed> $page
     */
    private static function applyRoles(int $id, array $page): void
    {
        update_post_meta(
            $id,
            Visibility::META,
            (string) ($page['visibility'] ?? Visibility::PUBLIC_ACCESS)
        );

        // Gabarit sur mesure du thème. Sans cette affectation, les gabarits
        // livrés — tarifs, trombinoscope, agenda — existent sans jamais servir :
        // toutes les pages tombent sur le gabarit générique.
        $template = (string) ($page['template'] ?? '');

        if ($template !== '') {
            update_post_meta($id, '_wp_page_template', $template);
        } else {
            delete_post_meta($id, '_wp_page_template');
        }

        if (!empty($page['front_page'])) {
            update_option('show_on_front', 'page');
            update_option('page_on_front', $id);
        }

        if (!empty($page['blog_page'])) {
            update_option('page_for_posts', $id);
        }

        if (!empty($page['privacy'])) {
            update_option('wp_page_for_privacy_policy', $id);
        }
    }

    /**
     * Retrouve la page correspondant à une entrée du plan.
     *
     * Trois pistes, dans cet ordre : la clé posée lors d'une installation
     * précédente, le chemin cible, puis les **anciens chemins** déclarés dans le
     * plan. Sans cette troisième piste, un site où le bureau avait créé
     * « /mon-profil/ » à la main se retrouve avec deux pages du même nom — l'une
     * dans le menu, l'autre pas, et personne ne comprend laquelle éditer.
     *
     * @param array<string, mixed> $page
     */
    private static function findExisting(array $page): ?\WP_Post
    {
        $key = (string) $page['key'];

        $found = get_posts([
            'post_type'      => 'page',
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => 1,
            'meta_key'       => Pages::KEY_META,
            'meta_value'     => $key,
            Visibility::BYPASS => true,
        ]);

        if ($found !== []) {
            return $found[0];
        }

        foreach (array_merge([$key], (array) ($page['legacy'] ?? [])) as $path) {
            $candidate = get_page_by_path((string) $path);

            // Une page déjà rattachée à une autre clé n'est pas reprise :
            // ce serait voler la page d'une entrée voisine.
            if ($candidate instanceof \WP_Post
                && get_post_meta($candidate->ID, Pages::KEY_META, true) === '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Dernier segment du chemin : c'est lui qui sert d'identifiant d'URL,
     * la hiérarchie étant portée par le parent.
     */
    private static function slugOf(string $key): string
    {
        $parts = explode('/', $key);

        return (string) end($parts);
    }

    /**
     * Construit les trois menus et les affecte aux emplacements du thème.
     *
     * @param array<string, int> $ids
     */
    private static function buildMenus(array $ids): int
    {
        $menus = [
            SiteMap::MENU_MAIN   => 'Menu principal',
            SiteMap::MENU_MEMBER => 'Espace membre',
            SiteMap::MENU_LEGAL  => 'Pied de page',
        ];

        $built = 0;

        foreach ($menus as $slug => $name) {
            $pages = array_values(array_filter(
                SiteMap::pages(),
                static fn (array $p): bool => in_array($slug, SiteMap::menusOf($p), true)
            ));

            if ($pages === []) {
                continue;
            }

            self::syncMenu($slug, $name, $pages, $ids);
            $built++;
        }

        self::assignLocations($menus);

        return $built;
    }

    /**
     * @param list<array<string, mixed>> $pages
     * @param array<string, int>         $ids
     */
    private static function syncMenu(string $slug, string $name, array $pages, array $ids): void
    {
        $menu = wp_get_nav_menu_object($slug);

        if (!$menu) {
            $menuId = wp_create_nav_menu($name);

            if (is_wp_error($menuId)) {
                return;
            }

            wp_update_term($menuId, 'nav_menu', ['slug' => $slug]);
            $menu = wp_get_nav_menu_object($menuId);
        }

        $menuId = (int) $menu->term_id;

        // Les entrées déjà présentes sont repérées par leur page cible : un
        // deuxième passage ne doit pas doubler le menu.
        [$known, $duplicates] = self::existingItems($menuId);

        // Réparation des bases déjà gonflées par le défaut décrit ci-dessus :
        // une page ne garde qu'une entrée, la plus ancienne.
        foreach ($duplicates as $duplicateId) {
            wp_delete_post($duplicateId, true);
        }

        $position = 0;
        $parents  = [];

        foreach ($pages as $page) {
            $pageId = $ids[$page['key']] ?? 0;

            if ($pageId === 0) {
                continue;
            }

            $parentKey  = $page['parent'] ?? null;
            $parentItem = $parentKey !== null ? ($parents[$parentKey] ?? 0) : 0;

            $itemId = wp_update_nav_menu_item($menuId, $known[$pageId] ?? 0, [
                'menu-item-title'     => (string) ($page['menu_label'] ?? $page['title']),
                'menu-item-object'    => 'page',
                'menu-item-object-id' => $pageId,
                'menu-item-type'      => 'post_type',
                'menu-item-status'    => 'publish',
                'menu-item-parent-id' => $parentItem,
                'menu-item-position'  => ++$position,
            ]);

            if (!is_wp_error($itemId)) {
                $parents[$page['key']] = (int) $itemId;
            }
        }
    }

    /**
     * Entrées de menu déjà en base, indexées par la page qu'elles visent.
     *
     * On interroge les enregistrements directement plutôt que d'appeler
     * `wp_get_nav_menu_items()`. Cette fonction-là est une **API d'affichage** :
     * elle passe son résultat au filtre `wp_get_nav_menu_items`, où
     * [MenuVisibility::filterClassicItems] retire les entrées que la personne
     * qui regarde n'a pas le droit d'ouvrir. Hors navigateur — en ligne de
     * commande, en tâche planifiée — personne ne regarde : `get_current_user_id()`
     * vaut 0, tout l'espace membre est jugé illisible, et la liste revient vide.
     * Le constructeur ne retrouvait alors aucune entrée à réutiliser et
     * reconstruisait le menu entier à chaque passage. Le menu principal, lui,
     * ne pointe que des pages publiques : le défaut ne s'y voyait pas.
     *
     * @return array{0: array<int, int>, 1: list<int>} entrée retenue par page,
     *                                                 puis doublons à supprimer
     */
    private static function existingItems(int $menuId): array
    {
        $items = get_posts([
            'post_type'      => 'nav_menu_item',
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'tax_query'      => [[
                'taxonomy' => 'nav_menu',
                'field'    => 'term_id',
                'terms'    => $menuId,
            ]],
            Visibility::BYPASS => true,
        ]);

        $known      = [];
        $duplicates = [];

        foreach ($items as $item) {
            // Les métas que lit `wp_setup_nav_menu_item()` pour bâtir les
            // propriétés `type`, `object` et `object_id` de chaque entrée.
            if (get_post_meta($item->ID, '_menu_item_type', true) !== 'post_type'
                || get_post_meta($item->ID, '_menu_item_object', true) !== 'page') {
                continue;
            }

            $pageId = (int) get_post_meta($item->ID, '_menu_item_object_id', true);

            if ($pageId === 0) {
                continue;
            }

            // Les entrées sont parcourues par identifiant croissant : la
            // première rencontrée est la plus ancienne, c'est elle qu'on garde.
            if (isset($known[$pageId])) {
                $duplicates[] = (int) $item->ID;

                continue;
            }

            $known[$pageId] = (int) $item->ID;
        }

        return [$known, $duplicates];
    }

    /**
     * @param array<string, string> $menus
     */
    private static function assignLocations(array $menus): void
    {
        $registered = get_registered_nav_menus();

        if ($registered === []) {
            return;
        }

        $locations = get_nav_menu_locations() ?: [];

        // Le thème nomme ses emplacements comme il veut. On rattache le menu
        // principal au premier emplacement déclaré, et le pied de page à celui
        // dont le nom l'évoque — faute de convention partagée entre thèmes.
        $main   = wp_get_nav_menu_object(SiteMap::MENU_MAIN);
        $legal  = wp_get_nav_menu_object(SiteMap::MENU_LEGAL);
        $first  = array_key_first($registered);

        foreach (array_keys($registered) as $location) {
            $isFooter = str_contains(strtolower($location), 'footer')
                || str_contains(strtolower($location), 'pied');

            if ($isFooter && $legal) {
                $locations[$location] = (int) $legal->term_id;
            } elseif ($location === $first && $main) {
                $locations[$location] = (int) $main->term_id;
            }
        }

        set_theme_mod('nav_menu_locations', $locations);
    }
}
