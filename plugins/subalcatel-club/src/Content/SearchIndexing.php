<?php

declare(strict_types=1);

namespace Subalcatel\Club\Content;

use Subalcatel\Club\Frontend\Pages;
use Subalcatel\Club\Setup\SiteMap;

/**
 * Pages publiques tenues hors des moteurs de recherche.
 *
 * Connexion, création de compte, cookies, plan du site : des pages utiles au
 * visiteur déjà sur le site, sans intérêt pour qui cherche un club de plongée.
 * Les laisser indexées, c'est voir « Connexion – Subalcatel » occuper une ligne
 * de résultat à la place de « Tarifs » ou « Nous rejoindre ».
 *
 * Deux endroits, comme pour les contenus réservés ([Visibility]) : la balise
 * `noindex` sur la page, et le retrait du sitemap XML. Une page à la fois
 * proposée par le sitemap et refusée par sa balise apparaît en anomalie dans
 * Search Console.
 *
 * `follow` est conservé : le plan du site n'est fait que de liens vers les
 * pages qu'on veut, elles, voir indexées.
 */
final class SearchIndexing
{
    public static function register(): void
    {
        add_filter('wp_robots', [self::class, 'robots']);
        add_filter('wp_sitemaps_posts_query_args', [self::class, 'sitemapArgs'], 10, 2);
    }

    /**
     * @param array<string, mixed> $robots
     * @return array<string, mixed>
     */
    public static function robots(array $robots): array
    {
        if (is_page() && in_array(get_queried_object_id(), self::pageIds(), true)) {
            $robots['noindex'] = true;
        }

        return $robots;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function sitemapArgs(array $args, string $postType): array
    {
        if ($postType === 'page') {
            $args['post__not_in'] = array_merge((array) ($args['post__not_in'] ?? []), self::pageIds());
        }

        return $args;
    }

    /**
     * Identifiants des pages déclarées `noindex` dans le plan, celles qui
     * existent sur ce site.
     *
     * @return list<int>
     */
    public static function pageIds(): array
    {
        $ids = [];

        foreach (SiteMap::pages() as $page) {
            if (!empty($page['noindex'])) {
                $ids[] = Pages::id((string) $page['key']);
            }
        }

        return array_values(array_filter($ids));
    }
}
