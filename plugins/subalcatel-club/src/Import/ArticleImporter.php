<?php

declare(strict_types=1);

namespace Subalcatel\Club\Import;

/**
 * Reprise des articles publiés.
 *
 * Le contenu passe par la liste blanche de {@see Sanitizer} — l'audit du dump
 * n'a relevé aucune injection, mais on ne fonde pas la sécurité d'un site sur
 * un constat daté.
 *
 * Les **images** sont un cas à part. Elles restent référencées par leur URL
 * d'origine et ne sont pas rapatriées : `images/` contenait 36 webshells PHP,
 * et c'est exactement là que porte le risque de réinfection. Chaque article qui
 * pointe vers un média est signalé, pour que le bureau réimporte les visuels
 * qu'il veut garder, un par un, par la médiathèque WordPress qui contrôle le
 * type réel du fichier.
 */
final class ArticleImporter
{
    public const JOOMLA_ID_META = '_sub_joomla_article_id';

    public function __construct(
        private readonly LegacySource $source,
        private readonly Report $report
    ) {
    }

    /**
     * Articles publiés, avec leur catégorie.
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(): array
    {
        $content    = $this->source->table('content');
        $categories = $this->source->table('categories');

        return $this->source->rows(
            "SELECT a.id, a.title, a.alias, a.introtext, a.`fulltext`, a.state,
                    a.created, a.modified, a.publish_up, a.metadesc,
                    c.title AS category_title
             FROM {$content} a
             LEFT JOIN {$categories} c ON c.id = a.catid
             WHERE a.state = 1
             ORDER BY a.created"
        );
    }

    public function run(bool $dryRun = true): void
    {
        foreach ($this->candidates() as $row) {
            $legacyId = (int) $row['id'];
            $title    = Sanitizer::text($row['title'] ?? '', 190);

            if ($title === '') {
                $this->report->skip('articles', $legacyId, 'titre vide');
                continue;
            }

            if ($this->existing($legacyId) > 0) {
                $this->report->skip('articles', $legacyId, 'déjà importé');
                continue;
            }

            $body = trim((string) ($row['introtext'] ?? '') . "\n\n" . (string) ($row['fulltext'] ?? ''));

            // Impérativement AVANT le nettoyage : `wp_kses` retire le préfixe
            // `data:`, ce qui transformerait l'image en `src` inexploitable
            // sans que rien ne le signale.
            $extracted = (new EmbeddedImages($this->report))->extract($body, $title, $dryRun);
            $body      = $extracted['html'];

            $safe = Sanitizer::html($body);

            // Après le nettoyage : le normaliseur produit du balisage WordPress
            // complet (srcset, dimensions réelles), qu'il serait absurde de
            // repasser ensuite dans un filtre qui pourrait l'amputer.
            $normalised   = (new ArticleImages())->normalise($safe['html']);
            $safe['html'] = $normalised['html'];

            if ($safe['removed'] !== []) {
                $this->report->warn(sprintf(
                    'Article « %s » : éléments retirés au nettoyage — %s.',
                    $title,
                    implode(', ', $safe['removed'])
                ));
            }

            $images = $this->countImages($safe['html']);
            if ($images > 0) {
                $this->report->warn(sprintf(
                    'Article « %s » : %d image(s) pointant encore vers l’ancien site — à réimporter par la médiathèque.',
                    $title,
                    $images
                ));
            }

            if ($dryRun) {
                $this->report->add('articles', $legacyId, $title);
                continue;
            }

            $postId = wp_insert_post([
                'post_title'    => $title,
                'post_name'     => sanitize_title((string) ($row['alias'] ?? $title)),
                'post_content'  => $safe['html'],
                'post_excerpt'  => Sanitizer::text($row['metadesc'] ?? '', 300),
                'post_status'   => 'publish',
                'post_type'     => 'post',
                'post_date'     => Sanitizer::date($row['created'] ?? null)
                    ? Sanitizer::date($row['created'] ?? null) . ' 00:00:00'
                    : current_time('mysql'),
                'post_category' => [$this->categoryFor($row['category_title'] ?? null)],
            ], true);

            if (is_wp_error($postId)) {
                $this->report->skip('articles', $legacyId, $postId->get_error_message());
                continue;
            }

            update_post_meta((int) $postId, self::JOOMLA_ID_META, (string) $legacyId);
            $this->report->add('articles', $legacyId, $title);
        }
    }

    private function existing(int $legacyId): int
    {
        $found = get_posts([
            'post_type'   => 'post',
            'post_status' => 'any',
            'meta_key'    => self::JOOMLA_ID_META,
            'meta_value'  => (string) $legacyId,
            'numberposts' => 1,
            'fields'      => 'ids',
        ]);

        return $found === [] ? 0 : (int) $found[0];
    }

    /**
     * Retrouve ou crée la catégorie WordPress correspondante.
     */
    private function categoryFor(?string $title): int
    {
        $name = Sanitizer::text($title ?? '', 120);

        if ($name === '' || strtolower($name) === 'uncategorised') {
            return (int) get_option('default_category', 1);
        }

        $term = get_term_by('name', $name, 'category');

        if ($term instanceof \WP_Term) {
            return $term->term_id;
        }

        $created = wp_insert_term($name, 'category');

        return is_wp_error($created)
            ? (int) get_option('default_category', 1)
            : (int) $created['term_id'];
    }

    private function countImages(string $html): int
    {
        return preg_match_all('/<img\b/i', $html);
    }
}
