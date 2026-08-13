<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use Subalcatel\Club\Content\Visibility;

/**
 * Plan du site : shortcode [subalcatel_plan_du_site].
 *
 * Utile au référencement et à l'accessibilité — et c'est souvent par là qu'un
 * visiteur perdu retrouve ce qu'il cherche.
 *
 * Comme le menu, il n'affiche que ce que la personne peut ouvrir : un plan du
 * site qui énumère des pages inaccessibles est une liste de portes fermées.
 */
final class SiteMapShortcode
{
    public static function register(): void
    {
        add_shortcode('subalcatel_plan_du_site', [self::class, 'render']);
    }

    public static function render(): string
    {
        $pages = get_pages([
            'sort_column' => 'menu_order,post_title',
            'post_status' => 'publish',
        ]) ?: [];

        $byParent = [];

        foreach ($pages as $page) {
            if (!Visibility::mayRead($page->ID)) {
                continue;
            }

            $byParent[(int) $page->post_parent][] = $page;
        }

        ob_start();
        echo '<nav class="sub-sitemap" aria-label="Plan du site">';
        self::renderBranch($byParent, 0);
        echo '</nav>';

        return (string) ob_get_clean();
    }

    /**
     * @param array<int, list<\WP_Post>> $byParent
     */
    private static function renderBranch(array $byParent, int $parentId): void
    {
        if (!isset($byParent[$parentId])) {
            return;
        }

        echo '<ul>';

        foreach ($byParent[$parentId] as $page) {
            printf(
                '<li><a href="%s">%s</a>',
                esc_url((string) get_permalink($page)),
                esc_html((string) $page->post_title)
            );

            // Une page dont le parent est masqué n'apparaît pas non plus : elle
            // n'est atteignable que depuis cette branche.
            self::renderBranch($byParent, (int) $page->ID);

            echo '</li>';
        }

        echo '</ul>';
    }
}
