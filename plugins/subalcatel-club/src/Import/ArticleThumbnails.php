<?php

declare(strict_types=1);

namespace Subalcatel\Club\Import;

/**
 * Choix d'une image mise en avant pour les articles repris.
 *
 * Joomla n'avait pas la notion d'image mise en avant : les photos vivaient dans
 * le corps de l'article. À la reprise, les 46 articles sont donc arrivés sans
 * `_thumbnail_id`, et les cartes d'actualité — qui réservent pourtant la place —
 * s'affichent sans vignette.
 *
 * Prendre la première image venue ne marche pas. Le HTML hérité commence
 * souvent par une pastille décorative : un bandeau « félicitations » de 120 px
 * ouvre quatre articles, une puce de 21 px en ouvre un autre. Étirée à la
 * largeur d'une carte, une pastille de 120 px donne une bouillie, et elle ne
 * dit rien de l'article.
 *
 * D'où deux seuils plutôt qu'un tri par taille :
 *
 * - **400 px** — la largeur de rendu d'une carte dans la grille à trois
 *   colonnes. Une image qui l'atteint est servie sans étirement.
 * - **240 px** — le format des vignettes héritées des fiches de sites de
 *   plongée. C'est la seule illustration que ces articles possèdent ; la
 *   refuser reviendrait à vider de leur image une dizaine de fiches. En
 *   dessous, on est dans l'icône, pas dans la photo.
 *
 * L'ordre du document est conservé à l'intérieur de chaque seuil : la première
 * image assez grande gagne, pas la plus grande. Sur « Rivière du Trieux », la
 * plus grande est une carte marine générique reprise de l'article voisin ;
 * la première est la photo du site. L'ordre de rédaction en dit plus long que
 * le nombre de pixels.
 *
 * La largeur seule ne suffit pas à écarter le décor. Plusieurs articles de
 * service ouvrent sur un bandeau — 553 × 49, 294 × 44 — qui franchit le
 * plancher par sa largeur et n'est pourtant qu'un filet. Recadré au 3/2 de la
 * carte, il n'en resterait qu'une bande illisible. On exige donc aussi une
 * forme recadrable.
 */
final class ArticleThumbnails
{
    /** Largeur de rendu d'une carte dans la grille à trois colonnes. */
    private const LARGEUR_CARTE = 400;

    /** En deçà, l'image est une icône ou une puce, pas une illustration. */
    private const LARGEUR_PLANCHER = 240;

    /**
     * Formes admises, du portrait au panoramique.
     *
     * La carte recadre en 3/2 (1,5). On tolère largement autour — un portrait
     * 2/3 comme une photo panoramique passent sans perdre leur sujet — mais
     * au-delà, le recadrage jette l'essentiel de l'image.
     */
    private const RATIO_MIN = 0.5;
    private const RATIO_MAX = 2.5;

    /**
     * Média à mettre en avant pour un contenu, ou 0 si aucun ne convient.
     *
     * On ne lit que la classe `wp-image-{id}` : c'est le lien que WordPress
     * lui-même utilise entre une balise et la médiathèque, et `ArticleImages`
     * l'a posée sur toutes les images reprises. Une balise qui ne la porte pas
     * désigne un fichier que la médiathèque ne connaît pas — on ne peut donc
     * pas en tirer de vignette.
     */
    public function choose(string $html): int
    {
        $secours = 0;

        foreach ($this->attachments($html) as $id) {
            [$largeur, $hauteur] = $this->size($id);

            if ($hauteur <= 0) {
                continue;
            }

            $ratio = $largeur / $hauteur;

            if ($ratio < self::RATIO_MIN || $ratio > self::RATIO_MAX) {
                continue;
            }

            if ($largeur >= self::LARGEUR_CARTE) {
                return $id;
            }

            if ($secours === 0 && $largeur >= self::LARGEUR_PLANCHER) {
                $secours = $id;
            }
        }

        return $secours;
    }

    /**
     * Médias cités par le contenu, dans l'ordre du document et sans doublon.
     *
     * @return list<int>
     */
    private function attachments(string $html): array
    {
        if (!preg_match_all('#\bclass="[^"]*\bwp-image-(\d+)\b#i', $html, $found)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $found[1])));
    }

    /**
     * Dimensions réelles du fichier servi, ou 0 × 0 si le média a disparu.
     *
     * On interroge la médiathèque plutôt que les attributs `width`/`height` de
     * la balise : le HTML hérité annonce des dimensions de vignettes d'un autre
     * site, qui ne correspondent plus au fichier depuis la reprise.
     *
     * @return array{0: int, 1: int}
     */
    private function size(int $attachmentId): array
    {
        $meta = wp_get_attachment_metadata($attachmentId);

        if (!is_array($meta)) {
            return [0, 0];
        }

        return [(int) ($meta['width'] ?? 0), (int) ($meta['height'] ?? 0)];
    }
}
