<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

use Subalcatel\Club\Exports\ExportRegistry;
use Subalcatel\Club\Support\Audit;

/**
 * Exports : la liste de ce que chacun a le droit de sortir.
 *
 * Seuls apparaissent les exports permis par les capacités de la personne
 * connectée. Un responsable matériel ne voit donc pas la liste des règlements —
 * et le contrôle est refait à la production, jamais seulement à l'affichage.
 */
final class ExportsScreen
{
    public const SLUG = 'subalcatel-exports';

    public static function register(): void
    {
        add_action('admin_post_sub_export', [self::class, 'handleExport']);
    }

    public static function render(): void
    {
        AdminUi::enqueue();
        AdminUi::flash();

        $exports = ExportRegistry::availableTo();
        ?>
        <div class="wrap sub-admin">
            <h1>Exports</h1>

            <p class="description">
                Le format CSV s’ouvre dans un tableur et se retravaille ; le format Excel
                garde la mise en forme et se transmet tel quel.
            </p>

            <?php if ($exports === []) : ?>
                <div class="notice notice-info">
                    <p>Aucun export ne correspond à vos droits.</p>
                </div>
                <?php return; ?>
            <?php endif; ?>

            <table class="wp-list-table widefat striped sub-cards" style="margin-top:16px;">
                <thead>
                    <tr>
                        <th>Export</th>
                        <th style="width:120px;">Colonnes</th>
                        <th style="width:260px;">Télécharger</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($exports as $export) : ?>
                    <tr>
                        <td data-label="Export">
                            <strong><?php echo esc_html($export->label()); ?></strong><br>
                            <span style="color:#50575e;"><?php echo esc_html($export->description()); ?></span>
                        </td>
                        <td data-label="Colonnes"><?php echo count($export->columns()); ?></td>
                        <td data-label="Télécharger">
                            <?php if ($export->key() === 'event-roster') : ?>
                                <em>Depuis la liste des inscrits d’un événement.</em>
                            <?php else : ?>
                                <?php foreach ([
                                    ExportRegistry::FORMAT_CSV  => 'CSV',
                                    ExportRegistry::FORMAT_XLSX => 'Excel',
                                ] as $format => $label) : ?>
                                    <?php AdminUi::actionButton(
                                        'sub_export',
                                        ['export' => $export->key(), 'format' => $format],
                                        $label,
                                        $format === ExportRegistry::FORMAT_CSV ? 'button button-primary' : 'button'
                                    ); ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2 style="margin-top:32px;">Derniers exports produits</h2>
            <p class="description">
                Sortir une liste d’adhérents est une opération tracée : elle emporte des
                données personnelles hors du site.
            </p>

            <?php self::renderLog(); ?>
        </div>
        <?php
    }

    private static function renderLog(): void
    {
        global $wpdb;

        $entries = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}sub_audit_log
             WHERE action = 'export.produced'
             ORDER BY created_at DESC LIMIT 20",
            ARRAY_A
        ) ?: [];
        ?>
        <table class="wp-list-table widefat striped sub-cards">
            <thead>
                <tr>
                    <th style="width:150px;">Quand</th>
                    <th style="width:180px;">Qui</th>
                    <th>Export</th>
                    <th style="width:100px;">Format</th>
                    <th style="width:100px;">Lignes</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($entries === []) : ?>
                <tr><td colspan="5">Aucun export produit pour l’instant.</td></tr>
            <?php endif; ?>

            <?php foreach ($entries as $entry) : ?>
                <?php
                $details = (array) (json_decode((string) $entry['details'], true) ?: []);
                $user    = $entry['user_id'] ? get_userdata((int) $entry['user_id']) : null;
                ?>
                <tr>
                    <td data-label="Quand">
                        <?php echo esc_html(wp_date('d/m/Y H:i', (int) strtotime((string) $entry['created_at']))); ?>
                    </td>
                    <td data-label="Qui"><?php echo esc_html($user?->display_name ?? '—'); ?></td>
                    <td data-label="Export"><code><?php echo esc_html((string) ($details['export'] ?? '')); ?></code></td>
                    <td data-label="Format"><?php echo esc_html(strtoupper((string) ($details['format'] ?? ''))); ?></td>
                    <td data-label="Lignes"><?php echo (int) ($details['lignes'] ?? 0); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public static function handleExport(): void
    {
        check_admin_referer('sub_export');

        $key    = sanitize_key(wp_unslash((string) ($_POST['export'] ?? $_GET['export'] ?? '')));
        $format = sanitize_key(wp_unslash((string) ($_POST['format'] ?? $_GET['format'] ?? 'csv')));

        $args = [];

        if (isset($_POST['event_id']) || isset($_GET['event_id'])) {
            $args['event_id'] = absint($_POST['event_id'] ?? $_GET['event_id']);
        }

        if (isset($_POST['list']) || isset($_GET['list'])) {
            $args['list'] = sanitize_text_field(wp_unslash((string) ($_POST['list'] ?? $_GET['list'])));
        }

        ExportRegistry::stream($key, $format, $args);
    }

    /**
     * Boutons d'export à placer sur un autre écran, par exemple la liste des
     * inscrits d'une sortie.
     *
     * @param array<string, string|int> $args
     */
    public static function buttons(string $exportKey, array $args = []): void
    {
        $export = ExportRegistry::find($exportKey);

        if ($export === null || !$export->isAllowed()) {
            return;
        }

        foreach ([
            ExportRegistry::FORMAT_CSV  => 'Exporter en CSV',
            ExportRegistry::FORMAT_XLSX => 'Exporter en Excel',
        ] as $format => $label) {
            AdminUi::actionButton(
                'sub_export',
                array_merge($args, ['export' => $exportKey, 'format' => $format]),
                $label,
                'button'
            );
        }
    }
}
