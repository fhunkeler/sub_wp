<?php

declare(strict_types=1);

namespace Subalcatel\Club\Import;

/**
 * Assainissement des données venant du Joomla compromis.
 *
 * L'audit du dump n'a relevé aucune injection dans les 92 articles. Ce n'est
 * pas une raison pour importer tel quel : l'audit constate un état à une date,
 * le filtre garantit une propriété. On applique donc une liste blanche stricte,
 * et on **signale** ce qui a été retiré plutôt que de le retirer en silence —
 * une reprise muette est une reprise qu'on ne peut pas relire.
 */
final class Sanitizer
{
    /**
     * Balises admises dans un article repris.
     *
     * Volontairement plus étroite que `wp_kses_post` : ni `<script>`, ni
     * `<iframe>`, ni `<object>`, ni `<form>`, ni attribut `style`. Le contenu
     * éditorial d'un club de plongée n'en a pas besoin, et chacune de ces
     * balises est un vecteur connu.
     *
     * @return array<string, array<string, bool>>
     */
    public static function allowedTags(): array
    {
        $link  = ['href' => true, 'title' => true, 'target' => true, 'rel' => true];
        $plain = [];

        return [
            'p'          => ['class' => true],
            'br'         => $plain,
            'hr'         => $plain,
            'strong'     => $plain,
            'b'          => $plain,
            'em'         => $plain,
            'i'          => $plain,
            'u'          => $plain,
            'sub'        => $plain,
            'sup'        => $plain,
            'blockquote' => ['cite' => true],
            'h1'         => $plain,
            'h2'         => $plain,
            'h3'         => $plain,
            'h4'         => $plain,
            'h5'         => $plain,
            'h6'         => $plain,
            'ul'         => $plain,
            'ol'         => ['start' => true],
            'li'         => $plain,
            'dl'         => $plain,
            'dt'         => $plain,
            'dd'         => $plain,
            'a'          => $link,
            // `srcset`, `sizes` et `class` sont indispensables : c'est par eux
            // que le navigateur choisit une variante adaptée à l'écran, et par
            // `wp-image-{id}` que WordPress relie l'image à sa médiathèque.
            'img'        => [
                'src' => true, 'alt' => true, 'title' => true,
                'width' => true, 'height' => true, 'class' => true,
                'srcset' => true, 'sizes' => true,
                'loading' => true, 'decoding' => true, 'fetchpriority' => true,
            ],
            'table'      => ['class' => true],
            'thead'      => $plain,
            'tbody'      => $plain,
            'tfoot'      => $plain,
            'tr'         => $plain,
            'th'         => ['colspan' => true, 'rowspan' => true, 'scope' => true],
            'td'         => ['colspan' => true, 'rowspan' => true],
            'caption'    => $plain,
            'figure'     => $plain,
            'figcaption' => $plain,
            'code'       => $plain,
            'pre'        => $plain,
            'span'       => $plain,
            'div'        => ['class' => true],
        ];
    }

    /**
     * Nettoie un contenu HTML et décrit ce qui a été retiré.
     *
     * @return array{html: string, removed: list<string>}
     */
    public static function html(string $raw): array
    {
        $removed = [];

        foreach ([
            'script'          => '/<\s*script\b/i',
            'iframe'          => '/<\s*iframe\b/i',
            'object/embed'    => '/<\s*(object|embed|applet)\b/i',
            'formulaire'      => '/<\s*form\b/i',
            'php'             => '/<\?(php|=)/i',
            'protocole js'    => '/javascript\s*:/i',
            'protocole data'  => '/data\s*:(?!image\/(png|jpe?g|gif|webp))/i',
            'gestionnaire on' => '/\son[a-z]+\s*=/i',
            'balise base'     => '/<\s*base\b/i',
        ] as $label => $pattern) {
            if (preg_match($pattern, $raw)) {
                $removed[] = $label;
            }
        }

        // wp_kses fait le travail réel ; la détection ci-dessus sert à rendre
        // compte, pas à filtrer.
        $clean = wp_kses($raw, self::allowedTags());

        // Joomla insère des séparateurs de pagination qui n'ont pas d'équivalent.
        $clean = (string) preg_replace('/\{[a-z0-9_]+\s*[^}]*\}/i', '', $clean);

        return ['html' => trim($clean), 'removed' => $removed];
    }

    /**
     * Texte court : ni balise, ni caractère de contrôle.
     */
    public static function text(?string $raw, int $maxLength = 190): string
    {
        $clean = sanitize_text_field((string) $raw);
        $clean = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $clean);

        return mb_substr(trim($clean), 0, $maxLength);
    }

    /**
     * Adresse e-mail valide, ou chaîne vide.
     */
    public static function email(?string $raw): string
    {
        $clean = sanitize_email(trim((string) $raw));

        return is_email($clean) ? strtolower($clean) : '';
    }

    /**
     * Numéro de téléphone : chiffres, espaces et séparateurs usuels seulement.
     */
    public static function phone(?string $raw): string
    {
        $clean = (string) preg_replace('/[^0-9+().\s-]/', '', (string) $raw);
        $clean = (string) preg_replace('/\s+/', ' ', $clean);

        return mb_substr(trim($clean), 0, 30);
    }

    /**
     * Date `Y-m-d` valide, ou null.
     *
     * Joomla stocke des `0000-00-00` et des dates au format français ; les deux
     * doivent devenir null plutôt qu'une date fausse.
     */
    public static function date(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
            return null;
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $raw);
            if ($date instanceof \DateTimeImmutable) {
                $year = (int) $date->format('Y');
                // Garde-fou : une naissance en 1901 ou en 2159 est une saisie
                // erronée, pas une donnée.
                if ($year >= 1900 && $year <= (int) gmdate('Y') + 10) {
                    return $date->format('Y-m-d');
                }
            }
        }

        return null;
    }

    /**
     * Identifiant de connexion acceptable pour WordPress.
     *
     * WordPress est plus strict que Joomla sur les caractères admis ; un
     * identifiant vidé par le nettoyage doit être remplacé, jamais laissé vide.
     */
    public static function login(string $raw, string $fallback): string
    {
        $clean = sanitize_user($raw, true);
        $clean = mb_substr(trim($clean), 0, 60);

        return $clean !== '' ? $clean : $fallback;
    }
}
