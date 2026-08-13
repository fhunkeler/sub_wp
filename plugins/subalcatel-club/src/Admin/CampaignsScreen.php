<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

use Subalcatel\Club\Support\Audit;

/**
 * Gestion des campagnes d'adhésion.
 *
 * L'écran le plus important du back-office n'est pas la création : c'est la
 * DUPLICATION. Les tarifs changent chaque année ; le trésorier clone la
 * campagne précédente, ajuste ce qui a bougé, publie. L'historique reste
 * intact, puisque les montants sont figés dans chaque dossier.
 */
final class CampaignsScreen
{
    /** Onglet de {@see ApplicationsScreen} où vivent les campagnes. */
    public const TAB = 'campagnes';

    public static function register(): void
    {
        add_action('admin_post_sub_campaign_save', [self::class, 'handleSave']);
        add_action('admin_post_sub_campaign_duplicate', [self::class, 'handleDuplicate']);
        add_action('admin_post_sub_campaign_status', [self::class, 'handleStatus']);
    }

    public static function renderTab(): void
    {
        AdminUi::requireCap('sub_manage_memberships');

        global $wpdb;
        $p = $wpdb->prefix . 'sub_';

        $campaigns = $wpdb->get_results(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM {$p}plans WHERE campaign_id = c.id) AS plan_count,
                    (SELECT COUNT(*) FROM {$p}options WHERE campaign_id = c.id) AS option_count,
                    (SELECT COUNT(*) FROM {$p}applications WHERE campaign_id = c.id) AS application_count
             FROM {$p}campaigns c
             ORDER BY opens_on DESC",
            ARRAY_A
        ) ?: [];
        ?>
            <p class="description">
                Une campagne porte les dates <strong>et</strong> les tarifs d’une année.
                Pour ouvrir la saison suivante, dupliquez la précédente et ajustez ce qui a changé.
            </p>

            <div class="sub-scroll"><table class="wp-list-table widefat striped" style="margin-top:16px;min-width:900px;">
                <thead>
                    <tr>
                        <th>Campagne</th>
                        <th style="width:200px;">Inscriptions</th>
                        <th style="width:200px;">Validité de l’adhésion</th>
                        <th style="width:160px;">Contenu</th>
                        <th style="width:110px;">État</th>
                        <th style="width:260px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($campaigns === []) : ?>
                    <tr><td colspan="6">Aucune campagne. Créez la première ci-dessous.</td></tr>
                <?php endif; ?>

                <?php foreach ($campaigns as $c) : ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html((string) $c['title']); ?></strong><br>
                            <code><?php echo esc_html((string) $c['slug']); ?></code>
                        </td>
                        <td>
                            du <?php echo esc_html(AdminUi::frDate((string) $c['opens_on'])); ?><br>
                            au <?php echo esc_html(AdminUi::frDate((string) $c['closes_on'])); ?>
                        </td>
                        <td>
                            du <?php echo esc_html(AdminUi::frDate((string) $c['valid_from'])); ?><br>
                            au <?php echo esc_html(AdminUi::frDate((string) $c['valid_until'])); ?>
                        </td>
                        <td>
                            <?php printf(
                                '%d formule(s)<br>%d option(s)<br><strong>%d dossier(s)</strong>',
                                (int) $c['plan_count'],
                                (int) $c['option_count'],
                                (int) $c['application_count']
                            ); ?>
                        </td>
                        <td><?php echo AdminUi::statusBadge((string) $c['status']); ?></td>
                        <td>
                            <a class="button button-primary"
                               href="<?php echo esc_url(CampaignEditor::url((int) $c['id'])); ?>">Configurer</a>

                            <?php AdminUi::actionButton(
                                'sub_campaign_duplicate',
                                ['campaign_id' => (int) $c['id']],
                                'Dupliquer',
                                'button',
                                'Créer une nouvelle campagne à partir de celle-ci — plans, options et remises compris ?'
                            ); ?>

                            <?php
                            $next = $c['status'] === 'open' ? 'closed' : 'open';
                            AdminUi::actionButton(
                                'sub_campaign_status',
                                ['campaign_id' => (int) $c['id'], 'status' => $next],
                                $next === 'open' ? 'Ouvrir' : 'Fermer',
                                'button'
                            );
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>

            <h2 style="margin-top:32px;">Nouvelle campagne</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sub-form">
                <input type="hidden" name="action" value="sub_campaign_save">
                <?php wp_nonce_field('sub_campaign_save'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="title">Nom</label></th>
                        <td>
                            <input name="title" id="title" type="text" class="regular-text"
                                   placeholder="Campagne 2027-2028" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Inscriptions ouvertes</th>
                        <td>
                            du <input name="opens_on" type="date" required>
                            au <input name="closes_on" type="date" required>
                            <p class="description">Période pendant laquelle les membres peuvent déposer un dossier.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Adhésion valable</th>
                        <td>
                            du <input name="valid_from" type="date" required>
                            au <input name="valid_until" type="date" required>
                            <p class="description">
                                Période couverte par la cotisation. Au club, du 15/09/N au 31/12/N+1.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="reminder_days">Rappels</label></th>
                        <td>
                            <input name="reminder_days" id="reminder_days" type="text" value="30" class="small-text">
                            <p class="description">
                                Jours avant expiration, séparés par des virgules. Exemple : <code>60,30,7</code>
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit"><button class="button button-primary">Créer la campagne</button></p>
            </form>
        <?php
    }

    /**
     * Retour sur l'onglet des campagnes.
     */
    private static function back(string $message, bool $isError = false): never
    {
        AdminUi::redirect(ApplicationsScreen::SLUG, $message, $isError, ['tab' => self::TAB]);
    }

    public static function handleSave(): void
    {
        check_admin_referer('sub_campaign_save');
        AdminUi::requireCap('sub_manage_memberships');

        global $wpdb;

        $title = sanitize_text_field(wp_unslash((string) ($_POST['title'] ?? '')));

        if ($title === '') {
            self::back('Le nom est obligatoire.', true);
        }

        $wpdb->insert($wpdb->prefix . 'sub_campaigns', [
            'title'         => $title,
            'slug'          => self::uniqueSlug($title),
            'opens_on'      => AdminUi::date($_POST['opens_on'] ?? ''),
            'closes_on'     => AdminUi::date($_POST['closes_on'] ?? ''),
            'valid_from'    => AdminUi::date($_POST['valid_from'] ?? ''),
            'valid_until'   => AdminUi::date($_POST['valid_until'] ?? ''),
            'reminder_days' => sanitize_text_field(wp_unslash((string) ($_POST['reminder_days'] ?? '30'))),
            'status'        => 'draft',
        ]);

        $id = (int) $wpdb->insert_id;
        Audit::log('campaign.created', 'campaign', $id, ['title' => $title]);

        wp_safe_redirect(CampaignEditor::url($id));
        exit;
    }

    /**
     * Duplique une campagne avec ses plans, options et remises.
     *
     * Les dossiers ne sont évidemment pas copiés : seule la configuration l'est.
     */
    public static function handleDuplicate(): void
    {
        check_admin_referer('sub_campaign_duplicate');
        AdminUi::requireCap('sub_manage_memberships');

        global $wpdb;
        $p  = $wpdb->prefix . 'sub_';
        $id = absint($_POST['campaign_id'] ?? 0);

        $source = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}campaigns WHERE id = %d", $id), ARRAY_A);

        if (!$source) {
            self::back('Campagne introuvable.', true);
        }

        // Les dates sont décalées d'un an : c'est le cas courant, et elles
        // restent modifiables ensuite.
        $shift = static fn (string $d): string => date('Y-m-d', strtotime($d . ' +1 year'));

        $title = self::incrementYears((string) $source['title']);

        $wpdb->insert("{$p}campaigns", [
            'title'         => $title,
            'slug'          => self::uniqueSlug($title),
            'opens_on'      => $shift((string) $source['opens_on']),
            'closes_on'     => $shift((string) $source['closes_on']),
            'valid_from'    => $shift((string) $source['valid_from']),
            'valid_until'   => $shift((string) $source['valid_until']),
            'reminder_days' => (string) $source['reminder_days'],
            'status'        => 'draft',
        ]);

        $newId = (int) $wpdb->insert_id;

        foreach (['plans', 'options', 'discount_rules'] as $table) {
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$p}{$table} WHERE campaign_id = %d", $id),
                ARRAY_A
            ) ?: [];

            foreach ($rows as $row) {
                unset($row['id']);
                $row['campaign_id'] = $newId;
                $wpdb->insert("{$p}{$table}", $row);
            }
        }

        Audit::log('campaign.duplicated', 'campaign', $newId, ['from' => $id]);

        wp_safe_redirect(add_query_arg(
            ['sub_done' => rawurlencode('Campagne dupliquée. Ajustez les tarifs, puis ouvrez-la.')],
            CampaignEditor::url($newId)
        ));
        exit;
    }

    public static function handleStatus(): void
    {
        check_admin_referer('sub_campaign_status');
        AdminUi::requireCap('sub_manage_memberships');

        global $wpdb;

        $id     = absint($_POST['campaign_id'] ?? 0);
        $status = sanitize_key(wp_unslash((string) ($_POST['status'] ?? 'draft')));

        if (!in_array($status, ['draft', 'open', 'closed'], true)) {
            self::back('État inconnu.', true);
        }

        $wpdb->update($wpdb->prefix . 'sub_campaigns', ['status' => $status], ['id' => $id]);
        Audit::log('campaign.status', 'campaign', $id, ['status' => $status]);

        self::back($status === 'open' ? 'Campagne ouverte.' : 'Campagne fermée.');
    }

    private static function uniqueSlug(string $title): string
    {
        global $wpdb;

        $base = sanitize_title($title) ?: 'campagne';
        $slug = $base;
        $i    = 2;

        while ($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sub_campaigns WHERE slug = %s",
            $slug
        ))) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    /**
     * « Campagne 2026-2027 » devient « Campagne 2027-2028 ».
     */
    private static function incrementYears(string $title): string
    {
        return (string) preg_replace_callback(
            '/\b(20\d{2})\b/',
            static fn (array $m): string => (string) ((int) $m[1] + 1),
            $title
        );
    }
}
