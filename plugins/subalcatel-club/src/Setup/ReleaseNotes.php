<?php

declare(strict_types=1);

namespace Subalcatel\Club\Setup;

/**
 * Notes de version d'une release, rendues en HTML.
 *
 * GitHub écrit ses notes en Markdown ; l'écran de WordPress attend du HTML.
 * Entre les deux, ce convertisseur — délibérément partiel. Il couvre ce que
 * contiennent réellement les notes du dépôt : un titre de section, une liste à
 * puces, du gras, du code, et des adresses. Le reste passe en texte.
 *
 * Un convertisseur Markdown complet serait une bibliothèque de plus à suivre,
 * pour afficher trois paragraphes dans une fenêtre modale. Ce qui n'est pas
 * reconnu s'affiche tel quel : illisible n'est pas le mot, imparfait suffit.
 *
 * Tout est échappé avant d'être balisé. Les notes viennent d'un dépôt tiers —
 * même le sien : une release se rédige depuis l'interface de GitHub, et ce
 * texte finit dans l'administration du site.
 */
final class ReleaseNotes
{
    public static function toHtml(string $markdown): string
    {
        $lignes      = preg_split('/\R/', trim($markdown)) ?: [];
        $html        = '';
        $liste       = null;
        $paragraphe  = [];

        $fermerParagraphe = static function () use (&$html, &$paragraphe): void {
            if ($paragraphe !== []) {
                $html .= '<p>' . implode('<br>', $paragraphe) . '</p>';
                $paragraphe = [];
            }
        };

        $fermerListe = static function () use (&$html, &$liste): void {
            if ($liste !== null) {
                $html .= '</' . $liste . '>';
                $liste = null;
            }
        };

        foreach ($lignes as $ligne) {
            $ligne = rtrim($ligne);

            if (trim($ligne) === '') {
                $fermerParagraphe();
                $fermerListe();

                continue;
            }

            // Titres. On descend d'un cran : la fenêtre pose déjà son propre
            // titre en h2, et deux h2 côte à côte se lisent comme deux pages.
            if (preg_match('/^(#{1,6})\s+(.*)$/', $ligne, $trouve) === 1) {
                $fermerParagraphe();
                $fermerListe();

                $niveau = min(6, max(3, strlen($trouve[1]) + 1));
                $html  .= sprintf('<h%d>%s</h%d>', $niveau, self::inline($trouve[2]), $niveau);

                continue;
            }

            // Puces et numéros.
            if (preg_match('/^\s*(?:[*+-]|\d+\.)\s+(.*)$/', $ligne, $trouve) === 1) {
                $attendu = preg_match('/^\s*\d+\./', $ligne) === 1 ? 'ol' : 'ul';

                $fermerParagraphe();

                if ($liste !== $attendu) {
                    $fermerListe();
                    $html .= '<' . $attendu . '>';
                    $liste = $attendu;
                }

                $html .= '<li>' . self::inline($trouve[1]) . '</li>';

                continue;
            }

            $fermerListe();
            $paragraphe[] = self::inline($ligne);
        }

        $fermerParagraphe();
        $fermerListe();

        return $html;
    }

    /**
     * Balisage à l'intérieur d'une ligne, sur du texte déjà échappé.
     */
    private static function inline(string $texte): string
    {
        $texte = esc_html($texte);

        $texte = (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $texte);
        $texte = (string) preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $texte);

        // Les deux formes d'adresse en une seule passe : sinon la seconde
        // repasserait sur les liens que la première vient d'écrire.
        return (string) preg_replace_callback(
            '#\[([^\]]+)\]\((https?://[^\s)]+)\)|(https?://[^\s<]+)#',
            static function (array $trouve): string {
                $nue = ($trouve[3] ?? '') !== '';
                $url = $nue ? $trouve[3] : $trouve[2];

                // Une adresse en fin de phrase emporterait la ponctuation.
                $url = rtrim($url, '.,;:)');

                return sprintf(
                    '<a href="%s">%s</a>',
                    esc_url(html_entity_decode($url, ENT_QUOTES, 'UTF-8')),
                    $nue ? $url : $trouve[1]
                );
            },
            $texte
        );
    }
}
