<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

use Subalcatel\Club\Support\SiteStatus;

/**
 * Réglages → État du site.
 *
 * Réservé aux administrateurs, comme « Sécurité » et « Mises à jour » : on y
 * lit l'emplacement des certificats et l'état de la configuration du serveur,
 * ce qui n'est pas l'affaire de la gestion courante du club.
 */
final class SiteStatusScreen
{
    public const TAB = 'status';

    private const ICONS = [
        SiteStatus::OK   => ['dashicons-yes-alt', '#008a20', 'Correct'],
        SiteStatus::WARN => ['dashicons-warning', '#b26200', 'À surveiller'],
        SiteStatus::BAD  => ['dashicons-dismiss', '#d63638', 'À corriger'],
        SiteStatus::INFO => ['dashicons-info-outline', '#646970', 'Information'],
    ];

    public static function renderTab(): void
    {
        AdminUi::requireCap('manage_options');

        $checks = SiteStatus::checks();
        $worst  = SiteStatus::worst($checks);
        ?>
        <p class="description">
            Ce que le site sait de lui-même : versions, tâches planifiées, envoi des courriels,
            adresses, surcharges de l’éditeur de site. De quoi répondre aux questions d’un
            diagnostic sans accès au serveur. Le rapport en bas de page se copie tel quel dans
            un courriel : il ne contient ni nom, ni adresse, ni clé.
        </p>

        <?php if ($worst === SiteStatus::OK) : ?>
            <div class="notice notice-success inline"><p><strong>Rien à signaler.</strong></p></div>
        <?php else : ?>
            <div class="notice <?php echo $worst === SiteStatus::BAD ? 'notice-error' : 'notice-warning'; ?> inline">
                <p><strong>
                    <?php echo $worst === SiteStatus::BAD
                        ? 'Au moins un point est à corriger.'
                        : 'Quelques points sont à surveiller.'; ?>
                </strong> Le détail et la marche à suivre figurent sur chaque ligne.</p>
            </div>
        <?php endif; ?>

        <?php foreach ($checks as $group => $items) : ?>
            <h2 style="margin-top:28px;"><?php echo esc_html($group); ?></h2>
            <table class="wp-list-table widefat striped" style="max-width:1100px;">
                <tbody>
                <?php foreach ($items as $item) : ?>
                    <?php [$icon, $color, $label] = self::ICONS[$item['status']]; ?>
                    <tr>
                        <td style="width:28px;">
                            <span class="dashicons <?php echo esc_attr($icon); ?>"
                                  style="color:<?php echo esc_attr($color); ?>;"
                                  title="<?php echo esc_attr($label); ?>"></span>
                            <span class="screen-reader-text"><?php echo esc_html($label); ?></span>
                        </td>
                        <th scope="row" style="width:260px;"><?php echo esc_html($item['label']); ?></th>
                        <td>
                            <?php echo esc_html($item['value']); ?>
                            <?php if ($item['help'] !== '') : ?>
                                <p class="description" style="margin:4px 0 0;"><?php echo esc_html($item['help']); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>

        <h2 style="margin-top:28px;">Rapport à transmettre</h2>
        <p>
            <textarea id="sub-site-status-report" class="large-text code" rows="14" readonly><?php
                echo esc_textarea(SiteStatus::report($checks));
            ?></textarea>
        </p>
        <p>
            <button type="button" class="button" id="sub-site-status-copy">Copier le rapport</button>
            <span id="sub-site-status-copied" class="description" hidden>Copié.</span>
        </p>
        <script>
            document.getElementById('sub-site-status-copy').addEventListener('click', function () {
                var field = document.getElementById('sub-site-status-report');
                var done  = function () { document.getElementById('sub-site-status-copied').hidden = false; };

                if (navigator.clipboard) {
                    navigator.clipboard.writeText(field.value).then(done);
                } else {
                    field.select();
                    document.execCommand('copy');
                    done();
                }
            });
        </script>
        <?php
    }
}
