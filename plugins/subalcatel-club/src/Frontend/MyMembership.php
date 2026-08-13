<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use Subalcatel\Club\Membership\ApplicationService;

/**
 * Mon adhésion : shortcode [subalcatel_mon_adhesion].
 *
 * Deux choses à montrer : où en est le dossier en cours, et ce qui a été payé.
 * Le détail tarifaire figé compte autant que le total — un adhérent qui voit sa
 * remise ligne à ligne ne téléphone pas au trésorier.
 */
final class MyMembership
{
    public static function register(): void
    {
        add_shortcode('subalcatel_mon_adhesion', [self::class, 'render']);
    }

    public static function render(): string
    {
        if (!is_user_logged_in()) {
            return sprintf(
                '<div class="sub-notice"><strong>Espace réservé aux membres</strong><p><a href="%s">Se connecter</a></p></div>',
                esc_url(wp_login_url(get_permalink()))
            );
        }

        wp_enqueue_style(
            'subalcatel-membership',
            \Subalcatel\Club\PLUGIN_URL . 'assets/css/membership.css',
            [],
            \Subalcatel\Club\VERSION
        );

        global $wpdb;
        $userId = get_current_user_id();

        $applications = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, p.title AS plan_title, c.title AS campaign_title
             FROM {$wpdb->prefix}sub_applications a
             LEFT JOIN {$wpdb->prefix}sub_plans p ON p.id = a.plan_id
             LEFT JOIN {$wpdb->prefix}sub_campaigns c ON c.id = a.campaign_id
             WHERE a.user_id = %d
             ORDER BY a.created_at DESC",
            $userId
        ), ARRAY_A) ?: [];

        ob_start();

        if ($applications === []) {
            ?>
            <div class="sub-notice">
                <strong>Aucune adhésion enregistrée</strong>
                <p>
                    Vous n’avez pas encore déposé de dossier.
                    <?php if (Pages::exists(Pages::SUBSCRIBE)) : ?>
                        <a href="<?php echo esc_url(Pages::url(Pages::SUBSCRIBE)); ?>">Adhérer au club</a>.
                    <?php endif; ?>
                </p>
            </div>
            <?php

            return (string) ob_get_clean();
        }

        $service = new ApplicationService();
        $current = array_shift($applications);

        echo '<div class="sub-membership-view">';
        self::renderCurrent($current, $service);

        if ($applications !== []) {
            self::renderHistory($applications);
        }

        echo '</div>';

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $application
     */
    private static function renderCurrent(array $application, ApplicationService $service): void
    {
        $status = (string) $application['status'];
        ?>
        <section class="sub-block">
            <h2 class="sub-block__title">
                <?php echo esc_html((string) ($application['campaign_title'] ?: 'Adhésion en cours')); ?>
            </h2>

            <p class="sub-membership-view__meta">
                Dossier <code><?php echo esc_html((string) $application['reference']); ?></code>
                — formule <?php echo esc_html((string) $application['plan_title']); ?>
            </p>

            <?php self::renderStepper($status); ?>

            <?php if ($status === ApplicationService::STATUS_AWAITING_PAYMENT) : ?>
                <div class="sub-notice sub-notice--waiting">
                    <strong>En attente de votre règlement</strong>
                    <p>
                        Montant : <strong><?php echo esc_html(self::euro((float) $application['total_amount'])); ?></strong>.
                        Par chèque à l’ordre du club, ou via HelloAsso.
                        Le bureau confirmera la réception.
                    </p>
                </div>
            <?php elseif ($status === ApplicationService::STATUS_REFUSED) : ?>
                <div class="sub-notice sub-notice--error">
                    <strong>Dossier refusé</strong>
                    <p><?php echo esc_html(self::lastComment((int) $application['id'])); ?></p>
                </div>
            <?php elseif ($status === ApplicationService::STATUS_ACTIVE) : ?>
                <div class="sub-notice sub-notice--success">
                    <strong>Adhésion active</strong>
                    <p>Valable jusqu’au <?php echo esc_html(MemberDashboard::frDate((string) $application['valid_until'])); ?>.</p>
                </div>
            <?php endif; ?>

            <h3 class="sub-membership-view__subtitle">Détail de votre cotisation</h3>
            <table class="sub-lines">
                <tbody>
                <?php foreach ($service->lines((int) $application['id']) as $line) : ?>
                    <tr class="sub-lines__row sub-lines__row--<?php echo esc_attr((string) $line['line_type']); ?>">
                        <td>
                            <?php echo esc_html((string) $line['label']); ?>
                            <?php if (!empty($line['value_label'])) : ?>
                                <span class="sub-lines__value">— <?php echo esc_html((string) $line['value_label']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="sub-lines__amount">
                            <?php echo esc_html(self::euro((float) $line['amount'])); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td>Total</td>
                        <td class="sub-lines__amount">
                            <strong><?php echo esc_html(self::euro((float) $application['total_amount'])); ?></strong>
                        </td>
                    </tr>
                </tfoot>
            </table>

            <?php self::renderPayments((int) $application['id']); ?>
        </section>
        <?php
    }

    /**
     * Avancement du dossier, du dépôt à l'activation.
     */
    private static function renderStepper(string $status): void
    {
        $steps = [
            ApplicationService::STATUS_AWAITING_PAYMENT  => 'Dossier déposé',
            ApplicationService::STATUS_PAYMENT_CONFIRMED => 'Règlement reçu',
            ApplicationService::STATUS_ACTIVE            => 'Adhésion active',
        ];

        $order   = array_keys($steps);
        $current = array_search($status, $order, true);
        $current = $current === false ? -1 : $current;

        // « Adhésion active » est un état terminal : l'étape est franchie, pas
        // en cours. Sans cette nuance, le membre voit un parcours inachevé
        // alors que tout est réglé.
        $finished = $status === ApplicationService::STATUS_ACTIVE;
        ?>
        <ol class="sub-steps">
            <?php foreach ($steps as $index => $label) : ?>
                <?php
                $position = array_search($index, $order, true);

                if ($finished) {
                    $state = 'done';
                } else {
                    $state = $position < $current ? 'done' : ($position === $current ? 'current' : 'todo');
                }
                ?>
                <li class="sub-steps__step sub-steps__step--<?php echo esc_attr($state); ?>">
                    <span class="sub-steps__mark" aria-hidden="true">
                        <?php echo $state === 'done' ? '✓' : (string) ((int) $position + 1); ?>
                    </span>
                    <?php echo esc_html($label); ?>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php
    }

    private static function renderPayments(int $applicationId): void
    {
        global $wpdb;

        $payments = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sub_payments
             WHERE application_id = %d ORDER BY received_on ASC",
            $applicationId
        ), ARRAY_A) ?: [];

        if ($payments === []) {
            return;
        }

        $modes = [
            'cheque' => 'Chèque', 'helloasso' => 'HelloAsso',
            'virement' => 'Virement', 'especes' => 'Espèces',
        ];
        ?>
        <h3 class="sub-membership-view__subtitle">Règlements enregistrés</h3>
        <ul class="sub-list">
            <?php foreach ($payments as $payment) : ?>
                <li class="sub-list__item">
                    <span class="sub-list__main">
                        <?php echo esc_html(self::euro((float) $payment['amount'])); ?>
                        — <?php echo esc_html($modes[$payment['method']] ?? (string) $payment['method']); ?>
                    </span>
                    <span class="sub-pill sub-pill--ok">
                        <?php echo esc_html(MemberDashboard::frDate((string) $payment['received_on'])); ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
    }

    /**
     * @param list<array<string, mixed>> $applications
     */
    private static function renderHistory(array $applications): void
    {
        ?>
        <section class="sub-block">
            <h2 class="sub-block__title">Adhésions précédentes</h2>

            <ul class="sub-list">
                <?php foreach ($applications as $application) : ?>
                    <li class="sub-list__item">
                        <span class="sub-list__main">
                            <strong><?php echo esc_html((string) ($application['campaign_title'] ?: '—')); ?></strong><br>
                            <?php echo esc_html((string) $application['plan_title']); ?>
                            — <?php echo esc_html(self::euro((float) $application['total_amount'])); ?>
                        </span>
                        <span class="sub-pill">
                            <?php echo esc_html(MemberDashboard::frDate((string) $application['valid_until'])); ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php
    }

    private static function lastComment(int $applicationId): string
    {
        global $wpdb;

        $comment = $wpdb->get_var($wpdb->prepare(
            "SELECT comment FROM {$wpdb->prefix}sub_validations
             WHERE application_id = %d AND decision = 'refused'
             ORDER BY created_at DESC LIMIT 1",
            $applicationId
        ));

        return (string) ($comment ?: 'Contactez le bureau pour connaître la marche à suivre.');
    }

    private static function euro(float $amount): string
    {
        return number_format($amount, 2, ',', ' ') . ' €';
    }
}
