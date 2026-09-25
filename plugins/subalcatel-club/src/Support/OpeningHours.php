<?php

declare(strict_types=1);

namespace Subalcatel\Club\Support;

/**
 * Horaires de permanence du local, modifiables depuis l'administration.
 *
 * Affichés sur la page Contact via le raccourci [subalcatel_permanences] — voir
 * [\Subalcatel\Club\Frontend\PermanencesShortcode]. Le bureau change ces
 * créneaux au fil des saisons ; ils n'ont donc plus leur place en dur dans le
 * gabarit du thème.
 */
final class OpeningHours
{
    private const OPTION = 'subalcatel_permanences';

    /**
     * @return list<array{day: string, time: string, note: string}>
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);

        if (!is_array($stored)) {
            return [];
        }

        $rows = [];

        foreach ($stored as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[] = [
                'day'  => (string) ($row['day'] ?? ''),
                'time' => (string) ($row['time'] ?? ''),
                'note' => (string) ($row['note'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Nettoie une saisie de formulaire avant enregistrement. Une ligne sans
     * jour ni horaire n'est qu'une ligne vide du formulaire — laissée pour
     * ajouter un créneau plus tard — et n'est donc pas enregistrée.
     *
     * @param list<mixed> $days
     * @param list<mixed> $times
     * @param list<mixed> $notes
     */
    public static function save(array $days, array $times, array $notes): void
    {
        $rows = [];

        foreach ($days as $index => $day) {
            $day  = sanitize_text_field(wp_unslash((string) $day));
            $time = sanitize_text_field(wp_unslash((string) ($times[$index] ?? '')));
            $note = sanitize_text_field(wp_unslash((string) ($notes[$index] ?? '')));

            if ($day === '' && $time === '') {
                continue;
            }

            $rows[] = ['day' => $day, 'time' => $time, 'note' => $note];
        }

        update_option(self::OPTION, $rows);
    }
}
