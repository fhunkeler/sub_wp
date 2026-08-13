<?php

declare(strict_types=1);

namespace Subalcatel\Club\Import;

/**
 * Extraction des images encodées en base64 dans les articles Joomla.
 *
 * Trois articles de félicitations portent leur photo directement dans le HTML,
 * en base64 — jusqu'à 700 Ko pour un seul article. Il faut les traiter **avant**
 * le nettoyage, car `wp_kses` retire le préfixe `data:` (protocole non
 * autorisé) et laisse un `src="image/jpeg;base64,…"` qui n'affiche plus rien :
 * l'article paraît importé, et l'image est silencieusement perdue.
 *
 * On les verse donc dans la médiathèque, où elles redeviennent des fichiers
 * normaux, redimensionnables et remplaçables par le bureau. La validation est
 * déléguée à {@see MediaIntake}, commune à toutes les images reprises.
 */
final class EmbeddedImages
{
    private MediaIntake $intake;

    public function __construct(private readonly Report $report)
    {
        $this->intake = new MediaIntake($report);
    }

    /**
     * Remplace chaque image base64 par une entrée de médiathèque.
     *
     * @return array{html: string, imported: int}
     */
    public function extract(string $html, string $articleTitle, bool $dryRun): array
    {
        $pattern = '#data:(image/(?:jpe?g|png|gif|webp));base64,([A-Za-z0-9+/=\s]+)#i';

        if (!preg_match($pattern, $html)) {
            return ['html' => $html, 'imported' => 0];
        }

        $imported = 0;
        $context  = sprintf('Article « %s »', $articleTitle);

        $html = (string) preg_replace_callback(
            $pattern,
            function (array $m) use ($articleTitle, $context, $dryRun, &$imported): string {
                $raw = base64_decode((string) preg_replace('/\s+/', '', $m[2]), true);

                if ($raw === false || $raw === '') {
                    $this->report->warn($context . ' : image encodée illisible — retirée.');

                    return '';
                }

                $url = $this->intake->accept($raw, $articleTitle, $context, $dryRun);

                if ($url === null) {
                    return '';
                }

                $imported++;

                return $url;
            },
            $html
        );

        return ['html' => $html, 'imported' => $imported];
    }
}
