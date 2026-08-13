<?php

declare(strict_types=1);

namespace Subalcatel\Club\Exports;

/**
 * Écriture CSV pour Excel en français.
 *
 * Deux détails font toute la différence, et coûtent une demi-journée à chaque
 * fois qu'on les oublie :
 *
 * - **La marque d'ordre des octets** (BOM) en tête, sans laquelle Excel lit le
 *   fichier en latin-1 et affiche « Ploumanac'h » en « PloumanacÂ¬h ».
 * - **Le point-virgule** comme séparateur : en configuration française, une
 *   virgule laisse tout le contenu dans une seule colonne.
 */
final class CsvWriter
{
    private const BOM       = "\xEF\xBB\xBF";
    private const SEPARATOR = ';';

    /**
     * @param list<string> $columns
     * @param list<list<string|int|float>> $rows
     */
    public static function render(array $columns, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fwrite($handle, self::BOM);
        fputcsv($handle, $columns, self::SEPARATOR, '"', '\\');

        foreach ($rows as $row) {
            fputcsv($handle, array_map([self::class, 'sanitize'], $row), self::SEPARATOR, '"', '\\');
        }

        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        return $contents;
    }

    /**
     * Neutralise les cellules interprétées comme des formules.
     *
     * Une valeur commençant par « = », « + », « - » ou « @ » est exécutée par
     * le tableur à l'ouverture. Un nom de membre malveillant deviendrait donc
     * une commande. On préfixe d'une apostrophe : le tableur affiche le texte
     * et n'exécute rien.
     */
    private static function sanitize(string|int|float $value): string
    {
        $text = (string) $value;

        if ($text !== '' && str_contains("=+-@\t\r", $text[0])) {
            return "'" . $text;
        }

        return $text;
    }
}
