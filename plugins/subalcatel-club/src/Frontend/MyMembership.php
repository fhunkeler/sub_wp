<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use Subalcatel\Club\Membership\ApplicationService;
use Subalcatel\Club\Membership\PaymentMethods;

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
        add_action('admin_post_sub_cancel_application', [self::class, 'handleCancel']);
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
        echo self::feedback(); // déjà échappé
        self::renderCurrent($current, $service);

        $history = self::pastMemberships($applications);

        if ($history !== []) {
            self::renderHistory($history);
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
                <?php $method = (string) ($application['payment_method'] ?? ''); ?>
                <div class="sub-notice sub-notice--waiting">
                    <strong>En attente de votre règlement</strong>
                    <p>
                        Montant : <strong><?php echo esc_html(self::euro((float) $application['total_amount'])); ?></strong>
                        <?php if ($method !== '') : ?>
                            — mode choisi : <strong><?php echo esc_html(PaymentMethods::label($method)); ?></strong>.
                        <?php else : ?>
                            .
                        <?php endif; ?>
                        <?php echo PaymentMethods::instructionsHtml($method); // déjà échappé ?>
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
            <?php elseif ($status === ApplicationService::STATUS_CANCELLED) : ?>
                <div class="sub-notice">
                    <strong>Dossier annulé</strong>
                    <p>
                        Ce dossier ne suit plus son cours.
                        <?php if (Pages::exists(Pages::SUBSCRIBE)) : ?>
                            Vous pouvez en
                            <a href="<?php echo esc_url(Pages::url(Pages::SUBSCRIBE)); ?>">déposer un nouveau</a>.
                        <?php endif; ?>
                    </p>
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

            <?php self::renderCancel((int) $application['id'], $service); ?>
        </section>
        <?php
    }

    /**
     * L'annulation de son propre dossier, tant qu'elle est ouverte.
     *
     * Elle l'est jusqu'à l'encaissement : passé lui, l'annulation touche à la
     * trésorerie et c'est au bureau de la prononcer. Le service tranche —
     * l'écran ne fait que lui demander, et se tait quand il dit non.
     */
    private static function renderCancel(int $applicationId, ApplicationService $service): void
    {
        if (!$service->canCancel($applicationId, get_current_user_id())) {
            return;
        }

        ?>
        <form class="sub-membership-view__cancel"
              method="post"
              action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              onsubmit="return confirm('Annuler ce dossier ? Vous pourrez en déposer un nouveau ensuite.');">
            <input type="hidden" name="action" value="sub_cancel_application">
            <input type="hidden" name="application_id" value="<?php echo esc_attr((string) $applicationId); ?>">
            <?php wp_nonce_field('sub_cancel_application_' . $applicationId); ?>
            <button type="submit" class="sub-button sub-button--danger">Annuler ce dossier</button>
            <small>
                Une erreur de saisie&nbsp;? Annulez, puis déposez un nouveau dossier.
                Le bureau en garde la trace.
            </small>
        </form>
        <?php
    }

    /**
     * Annulation demandée par l'adhérent lui-même.
     */
    public static function handleCancel(): void
    {
        $applicationId = isset($_POST['application_id']) ? absint($_POST['application_id']) : 0;

        if (!is_user_logged_in() || !check_admin_referer('sub_cancel_application_' . $applicationId)) {
            wp_die('Requête non autorisée.', 403);
        }

        $redirect = wp_get_referer() ?: home_url('/');

        try {
            (new ApplicationService())->cancel($applicationId, get_current_user_id());
            $args = ['sub_cancelled' => 1];
        } catch (\RuntimeException $e) {
            $args = ['sub_error' => rawurlencode($e->getMessage())];
        }

        wp_safe_redirect(add_query_arg($args, $redirect));
        exit;
    }

    /**
     * Ce que la redirection a laissé dans l'URL, remis à l'écran.
     */
    private static function feedback(): string
    {
        if (isset($_GET['sub_cancelled'])) {
            return '<div class="sub-notice sub-notice--success">'
                . '<strong>Dossier annulé</strong>'
                . '<p>Vous pouvez en déposer un nouveau dès maintenant.</p></div>';
        }

        if (isset($_GET['sub_error'])) {
            return sprintf(
                '<div class="sub-notice sub-notice--error"><strong>Annulation impossible</strong><p>%s</p></div>',
                esc_html(rawurldecode(sanitize_text_field(wp_unslash($_GET['sub_error']))))
            );
        }

        return '';
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

        ?>
        <h3 class="sub-membership-view__subtitle">Règlements enregistrés</h3>
        <ul class="sub-list">
            <?php foreach ($payments as $payment) : ?>
                <li class="sub-list__item">
                    <span class="sub-list__main">
                        <?php echo esc_html(self::euro((float) $payment['amount'])); ?>
                        — <?php echo esc_html(PaymentMethods::label((string) $payment['method'])); ?>
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
     * Ce qui a droit de figurer sous « Adhésions précédentes ».
     *
     * Un dossier annulé ou refusé n'a jamais été une adhésion. Il porte pourtant
     * une date de fin de validité — celle de sa campagne, recopiée au dépôt —,
     * si bien que l'historique l'affichait comme les vraies saisons, date de
     * validité comprise : l'adhérent y lisait une année réglée là où il n'y
     * avait qu'une saisie abandonnée. Le bureau en garde la trace côté
     * administration, où elle sert ; ici, elle ne fait qu'induire en erreur.
     *
     * @param list<array<string, mixed>> $applications
     *
     * @return list<array<string, mixed>>
     */
    private static function pastMemberships(array $applications): array
    {
        $abandonnes = [
            ApplicationService::STATUS_CANCELLED,
            ApplicationService::STATUS_REFUSED,
        ];

        return array_values(array_filter(
            $applications,
            static fn (array $application): bool
                => !in_array((string) $application['status'], $abandonnes, true)
        ));
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
