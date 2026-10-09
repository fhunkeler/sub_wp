<?php

declare(strict_types=1);

namespace Subalcatel\Club\Support;

/**
 * Un PDF d'une page A4, en texte et en traits.
 *
 * Le reçu et l'attestation d'adhésion n'ont besoin que de cela : du texte en
 * Helvetica, quelques filets, un bandeau. Une bibliothèque complète (TCPDF,
 * Dompdf) pèse plusieurs mégaoctets et suppose Composer — que l'extension
 * s'interdit pour rester installable par simple copie du dossier. Le format
 * PDF, lui, se contente d'une centaine de lignes quand on reste sur les polices
 * de base, que tout lecteur embarque.
 *
 * Contrepartie assumée : le texte passe en Windows-1252 (WinAnsiEncoding). Les
 * accents français, « », €, — et ’ y sont ; un caractère qui n'y est pas est
 * translittéré, au pire remplacé par « ? ». Pour un nom d'adhérent ou un
 * libellé de formule, c'est suffisant.
 *
 * Les coordonnées sont en points, origine en HAUT à gauche — l'inverse du PDF,
 * mais c'est ainsi qu'on pense une mise en page.
 */
final class PdfDocument
{
    public const WIDTH  = 595.28;
    public const HEIGHT = 841.89;

    /**
     * Chasses Helvetica, en millièmes de corps, de l'espace (32) au tilde (126).
     * Elles servent à aligner à droite et à couper les lignes ; une lettre
     * accentuée prend la chasse de sa lettre de base, ce qui est exact pour
     * Helvetica.
     */
    private const WIDTHS_REGULAR = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];

    private const WIDTHS_BOLD = [
        278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
        975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
        333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
        611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584,
    ];

    /**
     * Les quelques caractères hors ASCII dont la chasse ne se déduit pas d'une
     * lettre de base, en Windows-1252.
     */
    private const WIDTHS_EXTRA = [
        0x80 => 556,  // €
        0x85 => 1000, // …
        0x92 => 222,  // ’
        0x96 => 556,  // –
        0x97 => 1000, // —
        0xA0 => 278,  // espace insécable
        0xAB => 556,  // «
        0xB0 => 400,  // °
        0xB7 => 278,  // ·
        0xBB => 556,  // »
    ];

    /**
     * Lettres accentuées de Windows-1252 (0xC0 à 0xFF) et leur lettre de base,
     * octet pour octet. Une table plutôt que `iconv(…//TRANSLIT)`, dont le
     * résultat dépend de la locale du serveur — « ? » sous la locale C.
     */
    private const ACCENTED = "\xC0\xC1\xC2\xC3\xC4\xC5\xC6\xC7\xC8\xC9\xCA\xCB\xCC\xCD\xCE\xCF"
        . "\xD0\xD1\xD2\xD3\xD4\xD5\xD6\xD7\xD8\xD9\xDA\xDB\xDC\xDD\xDE\xDF"
        . "\xE0\xE1\xE2\xE3\xE4\xE5\xE6\xE7\xE8\xE9\xEA\xEB\xEC\xED\xEE\xEF"
        . "\xF0\xF1\xF2\xF3\xF4\xF5\xF6\xF7\xF8\xF9\xFA\xFB\xFC\xFD\xFE\xFF";

    private const BASES = 'AAAAAAACEEEEIIII'
        . 'DNOOOOOxOUUUUYPs'
        . 'aaaaaaaceeeeiiii'
        . 'dnooooo-ouuuuypy';

    /** @var list<string> */
    private array $ops = [];

    private string $title = '';

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    /**
     * Une ligne de texte.
     *
     * @param 'left'|'right'|'center' $align `$x` est le bord gauche, droit ou
     *                                       le milieu du texte selon le cas.
     * @param array{0: float, 1: float, 2: float} $rgb
     */
    public function text(
        float $x,
        float $y,
        string $text,
        float $size = 10,
        bool $bold = false,
        string $align = 'left',
        array $rgb = [0, 0, 0],
    ): void {
        $encoded = self::encode($text);

        if ($align !== 'left') {
            $width = self::measure($encoded, $size, $bold);
            $x    -= $align === 'right' ? $width : $width / 2;
        }

        $this->ops[] = sprintf(
            "BT %s rg /%s %s Tf %s %s Td (%s) Tj ET",
            self::rgb($rgb),
            $bold ? 'F2' : 'F1',
            self::num($size),
            self::num($x),
            self::num(self::HEIGHT - $y),
            self::escape($encoded)
        );
    }

    /**
     * Un paragraphe coupé à la largeur donnée. Rend l'ordonnée sous la
     * dernière ligne, pour enchaîner.
     */
    public function paragraph(
        float $x,
        float $y,
        float $width,
        string $text,
        float $size = 10,
        bool $bold = false,
        float $leading = 1.45,
    ): float {
        foreach (preg_split('/\R/u', $text) ?: [] as $raw) {
            $line = '';

            foreach (preg_split('/ +/u', trim($raw)) ?: [] as $word) {
                $candidate = $line === '' ? $word : $line . ' ' . $word;

                if ($line !== '' && self::measure(self::encode($candidate), $size, $bold) > $width) {
                    $this->text($x, $y, $line, $size, $bold);
                    $y   += $size * $leading;
                    $line = $word;
                } else {
                    $line = $candidate;
                }
            }

            $this->text($x, $y, $line, $size, $bold);
            $y += $size * $leading;
        }

        return $y;
    }

    /**
     * @param array{0: float, 1: float, 2: float} $rgb
     */
    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, array $rgb = [0.7, 0.7, 0.7]): void
    {
        $this->ops[] = sprintf(
            '%s RG %s w %s %s m %s %s l S',
            self::rgb($rgb),
            self::num($width),
            self::num($x1),
            self::num(self::HEIGHT - $y1),
            self::num($x2),
            self::num(self::HEIGHT - $y2)
        );
    }

    /**
     * @param array{0: float, 1: float, 2: float} $rgb
     */
    public function fillRect(float $x, float $y, float $width, float $height, array $rgb): void
    {
        $this->ops[] = sprintf(
            '%s rg %s %s %s %s re f',
            self::rgb($rgb),
            self::num($x),
            self::num(self::HEIGHT - $y - $height),
            self::num($width),
            self::num($height)
        );
    }

    /**
     * Largeur d'un texte, en points.
     */
    public function width(string $text, float $size = 10, bool $bold = false): float
    {
        return self::measure(self::encode($text), $size, $bold);
    }

    /**
     * Le fichier complet.
     */
    public function output(): string
    {
        $content = implode("\n", $this->ops);

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] '
                . '/Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
                self::num(self::WIDTH),
                self::num(self::HEIGHT)
            ),
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            6 => sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($content), $content),
            7 => sprintf(
                '<< /Title (%s) /Producer (Sub Alcatel) /CreationDate (D:%s) >>',
                self::escape(self::encode($this->title)),
                gmdate('YmdHis') . 'Z'
            ),
        ];

        $pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", count($objects) + 1);

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R /Info 7 0 R >>\nstartxref\n%d\n%%%%EOF\n",
            count($objects) + 1,
            $xref
        );

        return $pdf;
    }

    /**
     * UTF-8 vers Windows-1252.
     *
     * Les espaces fines insécables que produisent `wp_date()` et la
     * typographie française n'existent pas en Windows-1252 : elles deviennent
     * des insécables ordinaires, plutôt que des points d'interrogation.
     */
    public static function encode(string $text): string
    {
        $text = str_replace(["\u{202F}", "\u{2009}"], "\u{00A0}", $text);
        $out  = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);

        if ($out === false) {
            $out = (string) mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        }

        return $out;
    }

    private static function measure(string $encoded, float $size, bool $bold): float
    {
        $table = $bold ? self::WIDTHS_BOLD : self::WIDTHS_REGULAR;
        $total = 0;

        foreach (str_split($encoded) as $char) {
            $code = ord($char);

            if (isset(self::WIDTHS_EXTRA[$code])) {
                $total += self::WIDTHS_EXTRA[$code];
                continue;
            }

            if ($code >= 0xC0) {
                // Lettre accentuée : la chasse de sa lettre de base.
                $code = ord(strtr($char, self::ACCENTED, self::BASES));
            }

            $total += $table[$code - 32] ?? 556;
        }

        return $total * $size / 1000;
    }

    private static function escape(string $text): string
    {
        return strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }

    /**
     * @param array{0: float, 1: float, 2: float} $rgb
     */
    private static function rgb(array $rgb): string
    {
        return implode(' ', array_map([self::class, 'num'], $rgb));
    }

    private static function num(float|int $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') ?: '0';
    }
}
