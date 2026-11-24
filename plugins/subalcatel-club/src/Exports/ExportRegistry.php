<?php

declare(strict_types=1);

namespace Subalcatel\Club\Exports;

use RuntimeException;
use Subalcatel\Club\Support\Audit;

/**
 * La liste fermée des exports, et leur production.
 *
 * Fermée volontairement : un constructeur de requêtes dans l'interface finit
 * par n'être compris de personne et par exposer plus que prévu. Chaque export
 * est écrit, relu, et lié à une capacité.
 *
 * Les exports vivent dans leur domaine (`Events\Exports\EventRosterExport`,
 * `Membership\Exports\PaymentsExport`…), au-dessus de ce registre : il ne
 * peut donc pas les nommer. Ils y sont inscrits par {@see add()} — depuis
 * `Plugin::boot()` pour ceux du club, et demain depuis le module qui apporte
 * les siens. La liste reste fermée : seul du code peut l'allonger.
 */
final class ExportRegistry
{
    /** @var list<class-string<Export>> dans l'ordre d'affichage */
    private static array $classes = [];

    /**
     * Inscrit des exports, dans l'ordre où ils doivent s'afficher.
     *
     * Ce sont des classes, pas des instances : chaque appel à {@see all()}
     * en construit de neuves, comme avant le registre ouvert.
     *
     * @param class-string<Export> ...$classes
     */
    public static function add(string ...$classes): void
    {
        foreach ($classes as $class) {
            if (!is_subclass_of($class, Export::class)) {
                throw new RuntimeException(sprintf('%s n’est pas un export.', $class));
            }

            if (!in_array($class, self::$classes, true)) {
                self::$classes[] = $class;
            }
        }
    }

    public const FORMAT_CSV  = 'csv';
    public const FORMAT_XLSX = 'xlsx';

    /**
     * @return list<Export>
     */
    public static function all(): array
    {
        return array_map(static fn (string $class): Export => new $class(), self::$classes);
    }

    /**
     * Exports que cette personne a le droit de produire.
     *
     * @return list<Export>
     */
    public static function availableTo(?int $userId = null): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (Export $export): bool => $export->isAllowed($userId)
        ));
    }

    public static function find(string $key): ?Export
    {
        foreach (self::all() as $export) {
            if ($export->key() === $key) {
                return $export;
            }
        }

        return null;
    }

    /**
     * Produit le fichier et le sert.
     *
     * @param array<string, mixed> $args
     */
    public static function stream(string $key, string $format, array $args = []): never
    {
        $export = self::find($key);

        if ($export === null) {
            wp_die('Export inconnu.', 404);
        }

        if (!$export->isAllowed()) {
            wp_die('Vous n’avez pas le droit de produire cet export.', 403);
        }

        $columns = $export->columns();
        $rows    = $export->rows($args);

        // Tout export de données personnelles laisse une trace : c'est une
        // recommandation de la CNIL, et le seul moyen de répondre un jour à
        // « qui a sorti le fichier des adhérents ? ».
        if ($export->containsPersonalData()) {
            Audit::log('export.produced', 'export', null, [
                'export' => $key,
                'format' => $format,
                'lignes' => count($rows),
            ]);
        }

        nocache_headers();

        if ($format === self::FORMAT_XLSX) {
            self::streamXlsx($export, $columns, $rows);
        }

        self::streamCsv($export, $columns, $rows);
    }

    /**
     * @param list<string> $columns
     * @param list<list<string|int|float>> $rows
     */
    private static function streamCsv(Export $export, array $columns, array $rows): never
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $export->filename() . '.csv"');

        echo CsvWriter::render($columns, $rows); // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /**
     * @param list<string> $columns
     * @param list<list<string|int|float>> $rows
     */
    private static function streamXlsx(Export $export, array $columns, array $rows): never
    {
        try {
            $path = XlsxWriter::render($columns, $rows, $export->label());
        } catch (RuntimeException $e) {
            wp_die(esc_html($e->getMessage()), 500);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $export->filename() . '.xlsx"');
        header('Content-Length: ' . (string) filesize($path));

        readfile($path);
        @unlink($path);
        exit;
    }
}
