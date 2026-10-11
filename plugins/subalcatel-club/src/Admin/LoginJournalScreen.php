<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

use Subalcatel\Club\Support\LoginJournal;
use Subalcatel\Club\Support\TwoFactorGate;

/**
 * Réglages → Connexions.
 *
 * Réservé aux administrateurs : on y lit des adresses IP et l'activité des
 * comptes du bureau, ce qui relève de la sécurité du site, pas de la gestion
 * du club.
 */
final class LoginJournalScreen
{
    public const TAB = 'logins';

    public static function renderTab(): void
    {
        AdminUi::requireCap('manage_options');

        $accounts = LoginJournal::accounts();
        $recent   = LoginJournal::recent(100);
        ?>
        <p class="description">
            Les comptes suivis sont ceux qui ouvrent des données sensibles — certificats médicaux,
            exports, gestion des comptes : les mêmes que vise la double authentification. Leur
            titulaire reçoit un courriel quand son mot de passe sert depuis un appareil jamais vu.
            Les traces de connexion sont effacées au bout de <?php echo (int) LoginJournal::RETENTION_MONTHS; ?> mois.
        </p>

        <h2 style="margin-top:24px;">Comptes suivis</h2>
        <div class="sub-scroll">
            <table class="wp-list-table widefat striped" style="min-width:900px;">
                <thead>
                    <tr>
                        <th>Compte</th>
                        <th style="width:190px;">Dernière connexion</th>
                        <th style="width:140px;">Adresse IP</th>
                        <th style="width:170px;">Appareil</th>
                        <th style="width:120px;">Échecs (30 j)</th>
                        <th style="width:150px;">Double authentification</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($accounts === []) : ?>
                    <tr><td colspan="6">Aucun compte ne détient de droit sensible.</td></tr>
                <?php endif; ?>
                <?php foreach ($accounts as $row) : ?>
                    <?php $user = $row['user']; ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($user->display_name); ?></strong>
                            <br><code><?php echo esc_html($user->user_login); ?></code>
                        </td>
                        <td><?php echo esc_html($row['last'] ? mysql2date('j F Y à H\hi', $row['last']['at']) : 'jamais'); ?></td>
                        <td><?php echo esc_html($row['last']['ip'] ?? '—'); ?></td>
                        <td><?php echo esc_html($row['last']['device'] ?? '—'); ?></td>
                        <td>
                            <?php if ($row['failures'] > 0) : ?>
                                <strong style="color:#b26200;"><?php echo (int) $row['failures']; ?></strong>
                            <?php else : ?>
                                0
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo esc_html(match (true) {
                                !TwoFactorGate::extensionDisponible() => '—',
                                TwoFactorGate::aUnSecondFacteur($user->ID) => 'activée',
                                default => 'non activée',
                            }); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <h2 style="margin-top:28px;">Dernières connexions</h2>
        <div class="sub-scroll">
            <table class="wp-list-table widefat striped" style="min-width:900px;">
                <thead>
                    <tr>
                        <th style="width:190px;">Date</th>
                        <th>Compte</th>
                        <th style="width:110px;">Résultat</th>
                        <th style="width:140px;">Adresse IP</th>
                        <th style="width:170px;">Appareil</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($recent === []) : ?>
                    <tr><td colspan="5">Aucune connexion enregistrée pour ces comptes.</td></tr>
                <?php endif; ?>
                <?php foreach ($recent as $event) : ?>
                    <tr>
                        <td><?php echo esc_html(mysql2date('j F Y à H\hi', $event['at'])); ?></td>
                        <td>
                            <?php echo esc_html($event['user']?->display_name ?? $event['login']); ?>
                        </td>
                        <td>
                            <?php echo $event['success']
                                ? '<span style="color:#008a20;">réussie</span>'
                                : '<span style="color:#d63638;">échouée</span>'; ?>
                        </td>
                        <td><?php echo esc_html($event['ip'] !== '' ? $event['ip'] : '—'); ?></td>
                        <td><?php echo esc_html($event['device']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
