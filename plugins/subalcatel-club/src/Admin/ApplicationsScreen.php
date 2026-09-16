<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

use Subalcatel\Club\Membership\ApplicationService;
use Subalcatel\Club\Membership\CampaignRepository;
use Subalcatel\Club\Membership\PaymentMethods;

/**
 * Adhésions : les dossiers, et les campagnes qui en fixent les tarifs.
 *
 * Le choix de wp-admin pour le bureau est délibéré : un trésorier qui traite
 * quatre-vingts dossiers a besoin de tableaux denses et filtrables, ce que
 * WordPress fait déjà bien. Les membres, eux, restent en front-office.
 *
 * Dossiers et campagnes partagent une entrée de menu parce qu'ils partagent une
 * question — combien, et jusqu'à quand — et surtout une personne : le trésorier
 * ouvre la campagne en septembre, les dossiers toute l'année.
 */
final class ApplicationsScreen
{
    public const SLUG = 'subalcatel-adhesions';

    /** Onglet des dossiers. */
    public const TAB = 'dossiers';

    /** @var list<string> */
    public const CAPABILITIES = ['sub_manage_memberships'];

    public static function register(): void
    {
        add_action('admin_post_sub_record_payment', [self::class, 'handlePayment']);
        add_action('admin_post_sub_validate_secretariat', [self::class, 'handleValidation']);
    }

    public static function render(): void
    {
        AdminUi::tabbedScreen(self::SLUG, 'Adhésions', [
            self::TAB              => [
                'label'  => 'Dossiers',
                'cap'    => 'sub_manage_memberships',
                'render' => [self::class, 'renderApplications'],
            ],
            CampaignsScreen::TAB   => [
                'label'  => 'Campagnes',
                'cap'    => 'sub_manage_memberships',
                'render' => [CampaignsScreen::class, 'renderTab'],
            ],
        ]);
    }

    public static function renderApplications(): void
    {
        AdminUi::requireCap('sub_manage_memberships');

        global $wpdb;
        $p = $wpdb->prefix . 'sub_';

        // Le montant encaissé est joint plutôt que compté ligne à ligne : une
        // correction peut creuser un écart avec le montant dû, et c'est un écart
        // qui doit se voir sur la liste, pas au dossier ouvert.
        $rows = $wpdb->get_results(
            "SELECT a.*, u.display_name, u.user_email, pl.title AS plan_title,
                    COALESCE(pay.paid, 0) AS paid_amount
             FROM {$p}applications a
             LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id
             LEFT JOIN {$p}plans pl ON pl.id = a.plan_id
             LEFT JOIN (
                 SELECT application_id, SUM(amount) AS paid
                 FROM {$p}payments WHERE status = 'received'
                 GROUP BY application_id
             ) pay ON pay.application_id = a.id
             ORDER BY FIELD(a.status,'awaiting_payment','payment_confirmed','active','refused'),
                      a.created_at DESC
             LIMIT 100",
            ARRAY_A
        ) ?: [];

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
        }

        $service = new ApplicationService();
        ?>
            <ul class="subwrap-stats" style="display:flex;flex-wrap:wrap;gap:16px;list-style:none;margin:0 0 24px;padding:0;">
                <?php foreach ([
                    ApplicationService::STATUS_AWAITING_PAYMENT  => 'En attente de paiement',
                    ApplicationService::STATUS_PAYMENT_CONFIRMED => 'À valider (secrétariat)',
                    ApplicationService::STATUS_ACTIVE            => 'Actives',
                ] as $status => $label) : ?>
                    <li style="background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:12px 18px;">
                        <strong style="font-size:24px;display:block;"><?php echo (int) ($counts[$status] ?? 0); ?></strong>
                        <span style="color:#50575e;"><?php echo esc_html($label); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="sub-scroll"><table class="wp-list-table widefat striped sub-cards sub-table--wide">
                <thead>
                    <tr>
                        <th style="width:140px;">Référence</th>
                        <th>Membre</th>
                        <th style="width:130px;">Formule</th>
                        <th style="width:100px;">Montant</th>
                        <th style="width:130px;">Règlement annoncé</th>
                        <th style="width:170px;">État</th>
                        <th style="width:470px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []) : ?>
                    <tr><td colspan="7">Aucun dossier pour l’instant.</td></tr>
                <?php endif; ?>

                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <td data-label="Référence"><code><?php echo esc_html((string) $row['reference']); ?></code></td>
                        <td data-label="Membre">
                            <?php if ($row['user_id'] === null) : ?>
                                <?php // Le dossier reste — c'est une pièce comptable — mais son
                                      // titulaire a supprimé son compte. Le dire, plutôt que
                                      // laisser une ligne vide qu'on croira cassée. ?>
                                <em style="color:#50575e;">Compte supprimé</em>
                            <?php else : ?>
                                <strong><?php echo esc_html((string) ($row['display_name'] ?: '—')); ?></strong><br>
                                <span style="color:#50575e;"><?php echo esc_html((string) $row['user_email']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Formule"><?php echo esc_html((string) $row['plan_title']); ?></td>
                        <td data-label="Montant" style="font-variant-numeric:tabular-nums;">
                            <?php echo esc_html(AdminUi::euro((float) $row['total_amount'])); ?>
                            <?php self::renderPaymentGap($row); ?>
                        </td>
                        <td data-label="Règlement annoncé">
                            <?php
                            $announced = (string) ($row['payment_method'] ?? '');
                            echo esc_html($announced === '' ? '—' : PaymentMethods::label($announced));
                            ?>
                        </td>
                        <td data-label="État"><?php echo AdminUi::statusBadge((string) $row['status']); ?></td>
                        <td data-label="Action"><?php self::renderActions($row); ?></td>
                    </tr>
                    <tr class="sub-cards__detail">
                        <td colspan="7" data-label="Détail" style="background:#fbfbfb;">
                            <?php self::renderLines($service, (int) $row['id']); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function renderActions(array $row): void
    {
        echo '<div class="sub-row-actions">';

        self::renderStepAction($row);

        // Corriger reste possible tant que le dossier n'est pas activé, quelle
        // que soit l'étape : l'erreur de saisie se découvre aussi bien à
        // l'encaissement du chèque qu'à la vérification des pièces.
        if (
            ApplicationService::isEditable((string) $row['status'])
            && current_user_can('sub_manage_memberships')
        ) {
            printf(
                '<a class="button" href="%s">Corriger</a>',
                esc_url(ApplicationEditor::url((int) $row['id']))
            );
        }

        echo '</div>';
    }

    /**
     * L'écart entre ce qui est dû et ce qui a été encaissé.
     *
     * Il n'apparaît que lorsqu'il existe. Un dossier corrigé après règlement est
     * le seul cas courant — et le seul où le trésorier doit relancer quelqu'un.
     *
     * @param array<string, mixed> $row
     */
    private static function renderPaymentGap(array $row): void
    {
        $paid = (float) ($row['paid_amount'] ?? 0);
        $gap  = round((float) $row['total_amount'] - $paid, 2);

        if ($paid <= 0 || abs($gap) < 0.005) {
            return;
        }

        printf(
            '<br><small style="color:%s;">%s réglés — %s %s</small>',
            $gap > 0 ? '#b82a1e' : '#c0561a',
            esc_html(AdminUi::euro($paid)),
            esc_html(AdminUi::euro(abs($gap))),
            $gap > 0 ? 'à encaisser' : 'à rembourser'
        );
    }

    /**
     * L'action qui fait avancer le dossier d'une étape.
     *
     * @param array<string, mixed> $row
     */
    private static function renderStepAction(array $row): void
    {
        $id = (int) $row['id'];

        if ($row['status'] === ApplicationService::STATUS_AWAITING_PAYMENT) {
            if (!current_user_can('sub_validate_membership_treasury')) {
                echo '<em>Réservé à la trésorerie.</em>';

                return;
            }
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  class="sub-step-form">
                <input type="hidden" name="action" value="sub_record_payment">
                <input type="hidden" name="application_id" value="<?php echo esc_attr((string) $id); ?>">
                <?php wp_nonce_field('sub_record_payment_' . $id); ?>
                <?php // Présélectionné sur ce que l'adhérent a annoncé : la trésorerie
                      // n'a plus qu'à confirmer, et l'écart se voit s'il y en a un. ?>
                <select name="method">
                    <?php foreach (PaymentMethods::offered() as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>"
                                <?php selected($value, (string) ($row['payment_method'] ?? '')); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="reference" placeholder="Référence" style="width:110px;">
                <button class="button button-primary">Paiement reçu</button>
            </form>
            <?php

            return;
        }

        if ($row['status'] === ApplicationService::STATUS_PAYMENT_CONFIRMED) {
            if (!current_user_can('sub_validate_membership_secretariat')) {
                echo '<em>Réservé au secrétariat.</em>';

                return;
            }
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  class="sub-step-form">
                <input type="hidden" name="action" value="sub_validate_secretariat">
                <input type="hidden" name="application_id" value="<?php echo esc_attr((string) $id); ?>">
                <?php wp_nonce_field('sub_validate_secretariat_' . $id); ?>
                <input type="text" name="comment" placeholder="Commentaire" style="width:150px;">
                <button class="button button-primary">Valider le dossier</button>
            </form>
            <?php

            return;
        }

        echo '—';
    }

    private static function renderLines(ApplicationService $service, int $applicationId): void
    {
        $lines = $service->lines($applicationId);

        if ($lines === []) {
            return;
        }

        echo '<div style="display:flex;flex-wrap:wrap;gap:6px 18px;font-size:12px;color:#50575e;">';

        foreach ($lines as $line) {
            $label = $line['value_label'] !== null && $line['value_label'] !== ''
                ? $line['label'] . ' — ' . $line['value_label']
                : $line['label'];

            $color = $line['line_type'] === 'discount' ? '#17795e' : 'inherit';

            printf(
                '<span style="color:%s;">%s <strong>%s €</strong></span>',
                esc_attr($color),
                esc_html((string) $label),
                esc_html(number_format((float) $line['amount'], 2, ',', ' '))
            );
        }

        echo '</div>';
    }


    public static function handlePayment(): void
    {
        $id = isset($_POST['application_id']) ? absint($_POST['application_id']) : 0;
        check_admin_referer('sub_record_payment_' . $id);

        $service     = new ApplicationService();
        $application = $service->find($id);

        try {
            if ($application === null) {
                throw new \RuntimeException('Dossier introuvable.');
            }

            $service->recordPayment(
                $id,
                (float) $application['total_amount'],
                sanitize_key(wp_unslash((string) ($_POST['method'] ?? 'cheque'))),
                null,
                get_current_user_id(),
                sanitize_text_field(wp_unslash((string) ($_POST['reference'] ?? '')))
            );

            self::redirect('Paiement enregistré.');
        } catch (\RuntimeException $e) {
            self::redirect($e->getMessage(), true);
        }
    }

    public static function handleValidation(): void
    {
        $id = isset($_POST['application_id']) ? absint($_POST['application_id']) : 0;
        check_admin_referer('sub_validate_secretariat_' . $id);

        try {
            (new ApplicationService())->validateSecretariat(
                $id,
                get_current_user_id(),
                sanitize_text_field(wp_unslash((string) ($_POST['comment'] ?? '')))
            );

            self::redirect('Dossier validé : l’adhésion est active.');
        } catch (\RuntimeException $e) {
            self::redirect($e->getMessage(), true);
        }
    }

    private static function redirect(string $message, bool $isError = false): never
    {
        AdminUi::redirect(self::SLUG, $message, $isError, ['tab' => self::TAB]);
    }
}
