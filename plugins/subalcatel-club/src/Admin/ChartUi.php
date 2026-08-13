<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

/**
 * Briques d'affichage des graphiques.
 *
 * Ce que `AdminUi` est aux écrans, celle-ci l'est aux figures : une carte, des
 * barres, un état vide, une phrase de lecture. Rien de métier — les données
 * arrivent calculées.
 *
 * Elle existe parce que le tableau de bord et l'écran des statistiques
 * dessinent les mêmes objets. Deux jeux de barres divergeraient au premier
 * ajustement, et l'alignement des pistes — qui a déjà demandé un correctif —
 * serait à refaire deux fois.
 */
final class ChartUi
{
    public static function open(string $title, string $subtitle = ''): void
    {
        printf(
            '<section class="sub-chart"><h3>%s%s</h3>',
            esc_html($title),
            $subtitle === '' ? '' : sprintf('<span class="sub-chart__sub">%s</span>', esc_html($subtitle))
        );
    }

    public static function close(): void
    {
        echo '</section>';
    }

    public static function emptyState(string $message): void
    {
        printf('<p class="sub-chart-empty">%s</p>', esc_html($message));
    }

    /**
     * Phrase de lecture sous la figure.
     *
     * C'est elle que le bureau retient ; le dessin ne fait que la situer. Le
     * balisage admis est celui de `wp_kses_post` — de l'emphase, pas de la
     * mise en page.
     */
    public static function note(string $html, bool $alert = false): void
    {
        printf(
            '<p class="sub-chart-note%s">%s</p>',
            $alert ? ' sub-chart-note--alert' : '',
            wp_kses_post($html)
        );
    }

    /**
     * Barres horizontales.
     *
     * Un tableau, et non des lignes en grille : sur des lignes indépendantes,
     * chaque ligne calcule ses propres colonnes et une valeur plus longue que
     * les autres décale sa propre piste. Les colonnes d'un tableau sont
     * communes à toutes les lignes par construction.
     *
     * `ratio` est déjà rapporté à son échelle par l'appelant : lui seul sait si
     * une barre se compare à une capacité, à un effectif ou à la plus grande
     * valeur du lot.
     *
     * @param list<array{label: string, meta?: string, value: string,
     *                   ratio: float, tone?: string, color?: string}> $rows
     */
    public static function bars(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        echo '<table class="sub-chart-bars"><tbody>';

        foreach ($rows as $row) {
            $tone  = (string) ($row['tone'] ?? '');
            $color = (string) ($row['color'] ?? '');
            $width = (int) round(100 * max(0.0, min(1.0, $row['ratio'])));
            ?>
            <tr>
                <th scope="row">
                    <span class="sub-chart-bar__label"><?php echo esc_html($row['label']); ?></span>
                    <?php if (($row['meta'] ?? '') !== '') : ?>
                        <span class="sub-chart-bar__meta"><?php echo wp_kses_post((string) $row['meta']); ?></span>
                    <?php endif; ?>
                </th>
                <td>
                    <span class="sub-chart-bar__track">
                        <?php if ($width > 0) : ?>
                            <span class="sub-chart-bar__fill<?php echo $tone === '' ? '' : ' sub-chart-bar__fill--' . esc_attr($tone); ?>"
                                  style="width:<?php echo $width; ?>%<?php
                                    echo $color === '' ? '' : ';background:' . esc_attr($color);
                                  ?>"></span>
                        <?php endif; ?>
                    </span>
                </td>
                <td class="sub-chart-bar__value"><?php echo esc_html($row['value']); ?></td>
            </tr>
            <?php
        }

        echo '</tbody></table>';
    }

    /**
     * Colonnes verticales — une série courte et ordonnée dans le temps.
     *
     * @param list<array{label: string, count: int}> $columns
     */
    public static function columns(array $columns): void
    {
        if ($columns === []) {
            return;
        }

        $peak = max(1, ...array_column($columns, 'count'));
        ?>
        <div class="sub-chart-columns">
            <?php foreach ($columns as $column) : ?>
                <div class="sub-chart-column">
                    <span class="sub-chart-column__value"><?php echo (int) $column['count']; ?></span>
                    <span class="sub-chart-column__bar"
                          style="height:<?php echo round(100 * $column['count'] / $peak); ?>%"></span>
                    <span class="sub-chart-column__label"><?php echo esc_html($column['label']); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Plus grande valeur d'une colonne, jamais nulle.
     *
     * `max()` sur un tableau vide lève une erreur, et diviser par le pic est le
     * geste de tous les appelants : autant qu'il soit fait au même endroit.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function peak(array $rows, string $key = 'count'): float
    {
        $values = array_map(
            static fn (array $row): float => (float) abs((float) ($row[$key] ?? 0)),
            $rows
        );

        return $values === [] ? 1.0 : max(1.0, max($values));
    }
}
