<?php

declare(strict_types=1);

namespace Subalcatel\Club\Exports;

use RuntimeException;
use ZipArchive;

/**
 * Écriture XLSX sans bibliothèque.
 *
 * Un fichier Excel moderne est une archive ZIP contenant du XML. Pour une
 * feuille unique avec des en-têtes en gras, cela tient en quelques fichiers —
 * bien moins coûteux que d'ajouter un gestionnaire de dépendances et plusieurs
 * mégaoctets au plugin.
 *
 * Le cahier des charges du club demande explicitement Excel : le CSV suffit
 * pour retravailler les données, mais pas pour un tableau qu'on imprime ou
 * qu'on transmet tel quel.
 */
final class XlsxWriter
{
    /**
     * @param list<string> $columns
     * @param list<list<string|int|float>> $rows
     * @return string chemin du fichier temporaire produit
     */
    public static function render(array $columns, array $rows, string $sheetName = 'Export'): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException(
                'L’extension PHP « zip » est absente : l’export Excel n’est pas disponible. '
                . 'Utilisez le format CSV, ou demandez son activation à l’hébergeur.'
            );
        }

        $path = (string) tempnam(get_temp_dir(), 'sub-xlsx-');
        $zip  = new ZipArchive();

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossible de créer le fichier Excel.');
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::rootRels());
        $zip->addFromString('xl/workbook.xml', self::workbook($sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($columns, $rows));

        $zip->close();

        return $path;
    }

    /**
     * @param list<string> $columns
     * @param list<list<string|int|float>> $rows
     */
    private static function sheet(array $columns, array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        // Largeurs indicatives : sans elles, tout est illisible à l'ouverture.
        $xml .= '<cols>';
        foreach ($columns as $index => $column) {
            $width = max(12, min(40, mb_strlen($column) + 6));
            $xml  .= sprintf('<col min="%d" max="%d" width="%d" customWidth="1"/>', $index + 1, $index + 1, $width);
        }
        $xml .= '</cols><sheetData>';

        $xml .= '<row r="1">';
        foreach ($columns as $index => $column) {
            $xml .= self::cell(self::columnName($index) . '1', $column, 1);
        }
        $xml .= '</row>';

        foreach ($rows as $rowIndex => $row) {
            $number = $rowIndex + 2;
            $xml   .= sprintf('<row r="%d">', $number);

            foreach (array_values($row) as $cellIndex => $value) {
                $xml .= self::cell(self::columnName($cellIndex) . $number, $value, 0);
            }

            $xml .= '</row>';
        }

        return $xml . '</sheetData></worksheet>';
    }

    private static function cell(string $reference, string|int|float $value, int $style): string
    {
        // Les nombres sont écrits comme tels pour rester calculables ; le reste
        // en texte littéral, ce qui évite qu'un numéro de licence perde ses
        // zéros initiaux ou qu'une date soit réinterprétée.
        if (is_int($value) || is_float($value)) {
            return sprintf('<c r="%s" s="%d"><v>%s</v></c>', $reference, $style, $value);
        }

        return sprintf(
            '<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>',
            $reference,
            $style,
            self::escape((string) $value)
        );
    }

    private static function escape(string $value): string
    {
        // Les caractères de contrôle sont interdits par le format : Excel
        // refuse d'ouvrir le fichier plutôt que de les ignorer.
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * 0 → A, 25 → Z, 26 → AA.
     */
    private static function columnName(int $index): string
    {
        $name = '';

        for ($i = $index; $i >= 0; $i = intdiv($i, 26) - 1) {
            $name = chr(65 + $i % 26) . $name;
        }

        return $name;
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbook(string $sheetName): string
    {
        // Excel refuse certains caractères dans un nom d'onglet, et le limite
        // à 31 caractères.
        $name = mb_substr((string) preg_replace('#[\\\\/*?:\\[\\]]#', '-', $sheetName), 0, 31);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::escape($name) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    /**
     * Deux styles : normal, et en-tête en gras sur fond clair.
     */
    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FF0B4F71"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE8F1F5"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '</cellXfs>'
            . '</styleSheet>';
    }
}
