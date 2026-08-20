<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

/**
 * Le bloc « Club » sur le tableau de bord de WordPress.
 *
 * Le bureau se connecte et arrive sur le tableau de bord de WordPress, pas sur
 * celui du club : sans rappel à cet endroit, une file d'attente peut rester
 * deux semaines sans que personne l'ouvre. Ce bloc n'est donc pas un second
 * tableau de bord — il ne reprend ni les listes ni les courbes. Il dit combien,
 * et il emmène là où l'on agit.
 *
 * Les compteurs viennent de {@see DashboardScreen::blocks()} : une seule
 * définition de « ce qui attend quelqu'un », déjà filtrée par capacité, deux
 * affichages. Un trésorier n'y voit donc pas les pièces à vérifier, et un
 * adhérent ordinaire n'y voit rien du tout — le bloc ne s'enregistre pas.
 */
final class DashboardWidget
{
    private const ID = 'subalcatel_club_overview';

    /** Les tons qui appellent une décision, par opposition à ce qui s'observe. */
    private const ACTIONABLE = ['action', 'alert'];

    /**
     * Les blocs non vides, calculés une fois par personne.
     *
     * Le titre affiche un compteur et le corps la liste : sans ce cache, les
     * mêmes six requêtes partiraient deux fois par chargement du tableau de
     * bord. La clé est l'identifiant du compte, parce que les blocs dépendent
     * des capacités : une requête web ne sert qu'une personne, mais WP-CLI en
     * enchaîne plusieurs, et le cache mentirait dès la seconde.
     *
     * @var array<int, list<array<string, mixed>>>
     */
    private static array $blocks = [];

    public static function register(): void
    {
        add_action('wp_dashboard_setup', [self::class, 'add']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function add(): void
    {
        if (!ClubMenu::hasAnyClubCapability()) {
            return;
        }

        wp_add_dashboard_widget(
            self::ID,
            'Club' . self::bubble(),
            [self::class, 'render']
        );

        self::moveToTop();
    }

    public static function enqueue(string $hook): void
    {
        if ($hook !== 'index.php' || !ClubMenu::hasAnyClubCapability()) {
            return;
        }

        wp_enqueue_style(
            'subalcatel-admin',
            \Subalcatel\Club\PLUGIN_URL . 'assets/css/admin.css',
            [],
            \Subalcatel\Club\VERSION
        );
    }

    public static function render(): void
    {
        $blocks = self::blocks();

        echo '<div class="sub-widget">';

        if ($blocks === []) {
            echo '<p class="sub-widget__idle">Rien n’attend le bureau aujourd’hui.</p>';
        } else {
            echo '<ul class="sub-widget__rows">';

            foreach ($blocks as $block) {
                self::renderRow($block);
            }

            echo '</ul>';
        }

        self::renderStats();

        printf(
            '<p class="sub-widget__more"><a href="%s">Ouvrir la vue d’ensemble du club</a> →</p>',
            esc_url(admin_url('admin.php?page=' . ClubMenu::SLUG))
        );

        echo '</div>';
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function renderRow(array $block): void
    {
        $count = count((array) $block['items']);

        printf(
            '<li class="sub-widget__row sub-widget__row--%s">'
                . '<a href="%s"><span class="sub-widget__count">%s</span>'
                . '<span class="sub-widget__label">%s</span></a></li>',
            esc_attr((string) $block['tone']),
            esc_url((string) $block['url']),
            esc_html(self::countLabel($count)),
            esc_html((string) $block['title'])
        );
    }

    /**
     * Les listes du tableau de bord sont plafonnées : au plafond, le nombre
     * exact n'est pas connu. « 10 + » le dit, là où « 10 » laisserait croire
     * qu'un retard de trente dossiers en compte dix.
     */
    private static function countLabel(int $count): string
    {
        return $count >= DashboardScreen::LIST_LIMIT
            ? DashboardScreen::LIST_LIMIT . ' +'
            : (string) $count;
    }

    /**
     * La pastille du titre : ce qui attend une décision.
     *
     * Les sorties à venir et les adhésions qui expirent en sont exclues : elles
     * se surveillent, elles ne se traitent pas. Une pastille qui ne descend
     * jamais à zéro cesse d'être lue.
     */
    private static function bubble(): string
    {
        $count  = 0;
        $capped = false;

        foreach (self::blocks() as $block) {
            if (!in_array((string) $block['tone'], self::ACTIONABLE, true)) {
                continue;
            }

            $items   = count((array) $block['items']);
            $count  += $items;
            $capped  = $capped || $items >= DashboardScreen::LIST_LIMIT;
        }

        return AdminUi::countBubble($count, $capped ? $count . ' +' : '');
    }

    private static function renderStats(): void
    {
        if (!current_user_can('sub_manage_memberships')) {
            return;
        }

        echo '<ul class="sub-widget__stats">';

        foreach (DashboardScreen::stats() as $label => $value) {
            printf(
                '<li><strong>%d</strong><span>%s</span></li>',
                (int) $value,
                esc_html((string) $label)
            );
        }

        echo '</ul>';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function blocks(): array
    {
        return self::$blocks[get_current_user_id()] ??= array_values(array_filter(
            DashboardScreen::blocks(),
            static fn (array $block): bool => $block['items'] !== []
        ));
    }

    /**
     * Placer le bloc en tête de colonne.
     *
     * WordPress ajoute les blocs des extensions après les siens : le nôtre
     * arriverait sous « Activité », donc sous la ligne de flottaison. On ne
     * touche ici qu'à l'ordre par défaut — l'ordre qu'une personne s'est fait
     * en déplaçant ses blocs est enregistré sur son compte et s'applique après.
     */
    private static function moveToTop(): void
    {
        global $wp_meta_boxes;

        $column = $wp_meta_boxes['dashboard']['normal']['core'] ?? [];

        if (!isset($column[self::ID])) {
            return;
        }

        $ours = [self::ID => $column[self::ID]];
        unset($column[self::ID]);

        $wp_meta_boxes['dashboard']['normal']['core'] = $ours + $column;
    }
}
