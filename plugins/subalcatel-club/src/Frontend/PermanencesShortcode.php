<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use Subalcatel\Club\Support\OpeningHours;

/**
 * Horaires de permanence du local : raccourci [subalcatel_permanences].
 *
 * Lit les créneaux réglés dans l'administration (Réglages du club >
 * Permanences) plutôt que d'afficher un texte recopié à la main dans le
 * gabarit du thème — même logique que [PricingTable] pour les tarifs.
 */
final class PermanencesShortcode
{
    public static function register(): void
    {
        add_shortcode('subalcatel_permanences', [self::class, 'render']);
    }

    public static function render(): string
    {
        $rows = OpeningHours::all();

        if ($rows === []) {
            return 'Permanence : à définir.';
        }

        $lines = array_map(static function (array $row): string {
            $text = trim($row['day'] . ' ' . $row['time']);

            if ($row['note'] !== '') {
                $text .= ', ' . $row['note'];
            }

            return esc_html($text);
        }, $rows);

        return 'Permanence : ' . implode('<br>', $lines) . '.';
    }
}
