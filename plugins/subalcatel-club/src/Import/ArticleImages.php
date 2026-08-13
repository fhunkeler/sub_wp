<?php

declare(strict_types=1);

namespace Subalcatel\Club\Import;

/**
 * Remise en forme des images d'articles repris.
 *
 * Le HTML de Joomla décrivait des fichiers qui n'existent plus : ses balises
 * portent des `width`/`height` figés, hérités de vignettes d'un autre site, et
 * aucune des classes que WordPress attend.
 *
 * Deux conséquences, invisibles à la relecture du code mais bien réelles à
 * l'écran :
 *
 * 1. **Aucun `srcset`.** WordPress ne relie une image à sa médiathèque que par
 *    la classe `wp-image-{id}`. Sans elle, il ne propose aucune variante, et le
 *    navigateur télécharge le fichier d'origine — jusqu'à 2 900 px de large pour
 *    une colonne de 760 px, sur le forfait mobile de l'adhérent.
 * 2. **Des dimensions fausses.** 17 balises annonçaient une taille qui ne
 *    correspond plus au fichier, ce qui décale la mise en page pendant le
 *    chargement.
 *
 * On régénère donc le balisage depuis la médiathèque, seule source qui connaît
 * les dimensions réelles et les tailles disponibles.
 */
final class ArticleImages
{
    /**
     * Taille de base servie dans `src`.
     *
     * `large` (1024 px) couvre la colonne de lecture de 760 px, y compris sur
     * écran dense ; les autres tailles restent offertes par `srcset`, et le
     * navigateur choisit — il est mieux placé que nous pour le faire.
     */
    private const BASE_SIZE = 'large';

    /**
     * Réécrit les images d'un contenu.
     *
     * @return array{html: string, fixed: int, unknown: int}
     */
    public function normalise(string $html): array
    {
        $fixed   = 0;
        $unknown = 0;

        $result = (string) preg_replace_callback(
            '#<img\b[^>]*>#i',
            function (array $m) use (&$fixed, &$unknown): string {
                $tag = $m[0];

                if (!preg_match('#\bsrc\s*=\s*(["\'])(.*?)\1#is', $tag, $src)) {
                    return '';
                }

                $attachmentId = self::resolveAttachment($src[2]);

                if ($attachmentId === 0) {
                    // Média inconnu : on ne peut pas régénérer, mais on retire au
                    // moins les dimensions périmées qui décalent la mise en page.
                    $unknown++;

                    return (string) preg_replace('#\s(width|height)\s*=\s*(["\']).*?\2#i', '', $tag);
                }

                $alt = '';
                if (preg_match('#\balt\s*=\s*(["\'])(.*?)\1#is', $tag, $found)) {
                    $alt = html_entity_decode($found[2], ENT_QUOTES, 'UTF-8');
                }

                // À défaut d'alternative textuelle, celle de la médiathèque —
                // et si elle manque aussi, mieux vaut un alt vide qu'un nom de
                // fichier : un lecteur d'écran n'a que faire de « IMG_2043 ».
                if ($alt === '') {
                    $alt = (string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true);
                }

                $markup = wp_get_attachment_image(
                    $attachmentId,
                    self::BASE_SIZE,
                    false,
                    ['alt' => $alt, 'class' => 'wp-image-' . $attachmentId]
                );

                if ($markup === '') {
                    $unknown++;

                    return $tag;
                }

                $fixed++;

                return $markup;
            },
            $html
        );

        return ['html' => $result, 'fixed' => $fixed, 'unknown' => $unknown];
    }

    /**
     * Média correspondant à une adresse, ou 0.
     *
     * `attachment_url_to_postid()` ne suffit pas pour les grandes images :
     * au-delà de 2 560 px, WordPress conserve l'original mais enregistre le
     * fichier **redimensionné** (`-scaled`) comme fichier de référence. L'URL
     * inscrite dans l'article pointe alors vers un fichier que la médiathèque
     * ne reconnaît plus comme le sien.
     *
     * Ce sont précisément les photos les plus lourdes — celles pour lesquelles
     * le `srcset` compte le plus. Les laisser non résolues revenait à servir un
     * fichier de 2 900 px dans une colonne de 760.
     */
    private static function resolveAttachment(string $url): int
    {
        $id = attachment_url_to_postid($url);

        if ($id > 0) {
            return $id;
        }

        // Variante « -scaled » du même fichier.
        $scaled = (string) preg_replace('/(\.[A-Za-z0-9]+)$/', '-scaled$1', $url);

        if ($scaled !== $url) {
            $id = attachment_url_to_postid($scaled);

            if ($id > 0) {
                return $id;
            }
        }

        // Dernier recours : le nom de fichier, qui porte une empreinte unique
        // depuis la reprise et ne risque donc pas de désigner deux médias.
        global $wpdb;

        $base = pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);

        if ($base === '') {
            return 0;
        }

        $found = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s
                 ORDER BY post_id LIMIT 1",
                '%' . $wpdb->esc_like($base) . '%'
            )
        );

        return $found === null ? 0 : (int) $found;
    }
}
