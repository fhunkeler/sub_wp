<?php

declare(strict_types=1);

namespace Subalcatel\Club\Import;

/**
 * Reprise des images de l'arborescence Joomla citées par les articles.
 *
 * ## Ce qui entre, et ce qui n'entre pas
 *
 * Le dossier `images/` du site piraté pèse 296 Mo et contient 36 fichiers PHP —
 * les webshells qui ont servi à l'attaque. Il n'est **jamais** copié en bloc.
 * Seuls les fichiers explicitement cités par un article sont repris, un par un,
 * depuis une zone de transit préparée à part.
 *
 * Chaque fichier franchit trois portes :
 *   1. son chemin résolu doit rester dans la zone de transit — un `src` conçu
 *      pour remonter l'arborescence (`../../wp-config.php`) est refusé ;
 *   2. son contenu doit être une image reconnue par GD ;
 *   3. il est réécrit pixel par pixel par {@see MediaIntake}, ce qui ne laisse
 *      passer que de l'image.
 *
 * Une image absente n'est pas une erreur : l'ancien site avait ses liens morts
 * comme tout site de vingt ans. Elle est signalée, la balise est retirée, et
 * l'article reste lisible.
 */
final class LegacyMedia
{
    private MediaIntake $intake;

    /** @var array<string, string> référence d'origine => URL du nouveau média */
    private array $done = [];

    /** @var array<string, string> référence d'origine => nom dans la zone de transit */
    private array $index = [];

    public function __construct(
        private readonly Report $report,
        private readonly string $stagingDir
    ) {
        $this->intake = new MediaIntake($report);
        $this->loadIndex();
    }

    /**
     * Réécrit les `src` d'un article vers la médiathèque.
     *
     * @return array{html: string, imported: int, missing: int}
     */
    public function rewrite(string $html, string $articleTitle, bool $dryRun): array
    {
        $imported = 0;
        $missing  = 0;

        // On capture la balise ENTIÈRE, pas seulement son attribut `src`.
        // Ne prendre que le `src` laisserait, à la suppression, la queue de la
        // balise en clair dans l'article — « alt="permanence2022" /> » s'était
        // ainsi affiché tel quel sur la page des actualités.
        $html = (string) preg_replace_callback(
            '#<img\b[^>]*>#is',
            function (array $m) use ($articleTitle, $dryRun, &$imported, &$missing): string {
                $tag = $m[0];

                if (!preg_match('#\bsrc\s*=\s*(["\'])(.*?)\1#is', $tag, $attr)) {
                    return '';
                }

                $src = html_entity_decode($attr[2], ENT_QUOTES, 'UTF-8');

                // Déjà dans la médiathèque : on n'y touche pas.
                if (str_contains($src, '/wp-content/')) {
                    return $tag;
                }

                $url = $this->done[$src] ?? $this->importOne($src, $articleTitle, $dryRun);

                if ($url === null) {
                    $missing++;

                    // Balise entière retirée : un carré cassé n'informe personne.
                    return '';
                }

                if (!isset($this->done[$src])) {
                    $imported++;
                    $this->done[$src] = $url;
                }

                return str_replace($attr[0], sprintf('src="%s"', esc_url($url)), $tag);
            },
            $html
        );

        // Une balise <img> retirée peut laisser un paragraphe vide.
        $html = (string) preg_replace('#<p>\s*(&nbsp;)?\s*</p>#i', '', $html);

        return ['html' => $html, 'imported' => $imported, 'missing' => $missing];
    }

    private function importOne(string $src, string $articleTitle, bool $dryRun): ?string
    {
        $path = $this->resolve($src);

        if ($path === null) {
            $this->report->warn(sprintf(
                'Article « %s » : image « %s » introuvable — balise retirée.',
                $articleTitle,
                mb_substr($src, 0, 70)
            ));

            return null;
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            $this->report->warn(sprintf('Article « %s » : image illisible « %s ».', $articleTitle, $src));

            return null;
        }

        // Le titre du média reprend le nom du fichier d'origine, plus parlant
        // que le titre de l'article quand plusieurs images s'y côtoient.
        $title = pathinfo(basename($src), PATHINFO_FILENAME);

        return $this->intake->accept(
            $raw,
            $title !== '' ? $title : $articleTitle,
            sprintf('Article « %s »', $articleTitle),
            $dryRun
        );
    }

    /**
     * Chemin réel du fichier dans la zone de transit, ou null.
     *
     * Le contrôle d'évasion n'est pas théorique : un `src` est du contenu venant
     * d'un site compromis, donc une entrée hostile potentielle. `realpath` puis
     * comparaison de préfixe est la seule vérification qui résiste aux liens
     * symboliques et aux `..` encodés.
     */
    private function resolve(string $src): ?string
    {
        $reference = ltrim(trim($src), '/');

        if (!isset($this->index[$reference])) {
            return null;
        }

        $base = realpath($this->stagingDir);
        $path = realpath($this->stagingDir . '/' . $this->index[$reference]);

        if ($base === false || $path === false) {
            return null;
        }

        if (!str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
            $this->report->warn(sprintf('Chemin sortant de la zone de transit refusé : %s', $src));

            return null;
        }

        return is_file($path) ? $path : null;
    }

    /**
     * Charge la correspondance « référence d'article → fichier de transit ».
     */
    private function loadIndex(): void
    {
        $file = $this->stagingDir . '/index.tsv';

        if (!is_readable($file)) {
            $this->report->warn('Zone de transit des images absente : ' . $file);

            return;
        }

        foreach ((array) file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $parts = explode("\t", (string) $line, 2);

            if (count($parts) === 2) {
                $this->index[ltrim(trim($parts[1]), '/')] = trim($parts[0]);
            }
        }
    }
}
