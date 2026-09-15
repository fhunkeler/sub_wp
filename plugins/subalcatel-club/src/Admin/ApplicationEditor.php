<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

use Subalcatel\Club\Frontend\MembershipFields;
use Subalcatel\Club\Membership\ApplicationService;
use Subalcatel\Club\Membership\CampaignRepository;
use Subalcatel\Club\Membership\IncompleteApplication;
use Subalcatel\Club\Membership\PricingEngine;

/**
 * Correction d'un dossier d'adhésion, avant son activation.
 *
 * Une adhésion se remplit une fois par an, sur un formulaire qu'on redécouvre à
 * chaque saison : la case oubliée est la règle. Jusqu'ici le bureau n'avait que
 * deux gestes — refuser le dossier et faire tout retaper, ou corriger le montant
 * à la main dans la base. Le premier fait abandonner des adhésions, le second
 * laisse des lignes qui ne totalisent plus le montant.
 *
 * D'où cet écran, qui rejoue le formulaire de l'adhérent au lieu d'en inventer
 * un autre : mêmes questions, même ordre, même récapitulatif en direct, et
 * surtout le même recalcul au serveur. Le bureau corrige ce que l'adhérent
 * aurait dû cocher, pas un champ « montant ».
 */
final class ApplicationEditor
{
    public const SLUG = 'subalcatel-dossier-edit';

    public static function register(): void
    {
        add_action('admin_post_sub_amend_application', [self::class, 'handleAmend']);
    }

    public static function url(int $applicationId): string
    {
        return admin_url(sprintf('admin.php?page=%s&application_id=%d', self::SLUG, $applicationId));
    }

    public static function render(): void
    {
        AdminUi::requireCap('sub_manage_memberships');
        AdminUi::enqueue();
        self::enqueueMembershipForm();

        $applicationId = absint($_GET['application_id'] ?? 0);
        $service       = new ApplicationService();
        $application   = $service->find($applicationId);

        if ($application === null) {
            wp_die('Dossier introuvable.', 404);
        }

        $status = (string) $application['status'];
        $back   = AdminUi::tabUrl(ApplicationsScreen::SLUG, ApplicationsScreen::TAB);

        if (!ApplicationService::isEditable($status)) {
            // Pas un `wp_die` : arriver ici par un signet sur un dossier validé
            // entre-temps n'est pas une erreur, c'est une course. On l'explique
            // et on ramène à la liste.
            printf(
                '<div class="wrap sub-admin"><h1>Dossier %s</h1>'
                . '<div class="notice notice-error"><p>Ce dossier n’est plus modifiable : '
                . 'une adhésion active ou close ne se réécrit pas. Pour la corriger, il faut '
                . 'l’annuler et en déposer une nouvelle.</p></div>'
                . '<p><a class="button" href="%s">← Retour aux dossiers</a></p></div>',
                esc_html((string) $application['reference']),
                esc_url($back)
            );

            return;
        }

        $campaignId = (int) $application['campaign_id'];
        $repo       = new CampaignRepository();
        $plans      = $repo->plans($campaignId);
        $options    = $repo->options($campaignId);

        if ($plans === []) {
            wp_die('Cette campagne n’a plus aucune formule publiée : le dossier ne peut pas être recalculé.', 409);
        }

        // Le dossier garde le plan sur lequel il a été déposé. S'il a depuis été
        // dépublié, on retombe sur le premier — le bureau verra le changement
        // dans le récapitulatif avant d'enregistrer.
        $plan = self::planOf($plans, (int) $application['plan_id']) ?? $plans[0];

        $answers  = $service->answers($applicationId);
        $resolved = PricingEngine::resolveAnswers($plan, $answers, $options);

        $user = $application['user_id'] === null ? null : get_userdata((int) $application['user_id']);
        $paid = $service->paidAmount($applicationId);
        ?>
        <div class="wrap sub-admin sub-dossier">
            <h1>
                Corriger le dossier <?php echo esc_html((string) $application['reference']); ?>
                <?php echo AdminUi::statusBadge($status); ?>
            </h1>

            <p class="description">
                <?php if ($user === null) : ?>
                    Titulaire : <em>compte supprimé</em>.
                <?php else : ?>
                    <?php echo esc_html($user->display_name); ?>
                    (<?php echo esc_html($user->user_email); ?>) —
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . MembersScreen::SLUG . '&user_id=' . $user->ID)); ?>">
                        sa fiche
                    </a>
                <?php endif; ?>
                <a href="<?php echo esc_url($back); ?>">← Tous les dossiers</a>
            </p>

            <?php AdminUi::flash(); ?>

            <p class="description">
                L’état civil, l’adresse et le téléphone ne se corrigent pas ici : ils vivent sur
                la fiche du membre, et servent à tous ses dossiers. Cet écran ne touche qu’à ce
                qui fait le prix de <em>cette</em> adhésion.
            </p>

            <?php if ($paid > 0) : ?>
                <div class="notice notice-warning inline" style="margin:16px 0;">
                    <p>
                        <strong><?php echo esc_html(AdminUi::euro($paid)); ?></strong> ont déjà été
                        encaissés sur ce dossier. Si la correction change le montant dû, l’écart
                        s’affichera sur la liste des dossiers — le paiement enregistré, lui, n’est
                        pas touché.
                    </p>
                </div>
            <?php endif; ?>

            <form class="sub-membership"
                  method="post"
                  action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  data-campaign="<?php echo esc_attr((string) $campaignId); ?>">

                <input type="hidden" name="action" value="sub_amend_application">
                <input type="hidden" name="application_id" value="<?php echo esc_attr((string) $applicationId); ?>">
                <?php wp_nonce_field('sub_amend_application_' . $applicationId); ?>

                <div class="sub-membership__layout">
                    <div class="sub-membership__fields">

                        <?php MembershipFields::plans($plans, $plan); ?>

                        <?php foreach ($options as $option) : ?>
                            <?php MembershipFields::option($option, $plan, $resolved); ?>
                        <?php endforeach; ?>

                        <?php MembershipFields::payment(
                            (string) ($application['payment_method'] ?? ''),
                            [],
                            'Ce que l’adhérent a annoncé. À rectifier s’il règle autrement.'
                        ); ?>

                        <fieldset class="sub-field">
                            <legend>Motif de la correction</legend>
                            <p class="sub-field__help">
                                Repris dans le journal du dossier, et dans le courriel envoyé à
                                l’adhérent si le montant change. « Carte de niveau oubliée »
                                suffit — c’est ce qu’on relira dans six mois.
                            </p>
                            <p class="sub-input">
                                <label for="sub-amend-comment" class="screen-reader-text">Motif</label>
                                <input type="text" id="sub-amend-comment" name="comment"
                                       placeholder="Carte de niveau oubliée">
                            </p>
                        </fieldset>
                    </div>

                    <aside class="sub-summary">
                        <h3 class="sub-summary__title">Détail recalculé</h3>
                        <div class="sub-summary__quote" aria-live="polite">
                            <div class="sub-summary__lines" data-quote-lines>
                                <p class="sub-summary__empty">Choisissez une formule pour voir le détail.</p>
                            </div>
                            <p class="sub-summary__total">
                                <span>Total</span>
                                <strong data-quote-total>—</strong>
                            </p>
                        </div>
                        <button type="submit" class="sub-button">Enregistrer la correction</button>
                        <p class="sub-summary__note">
                            Montant actuel du dossier :
                            <strong><?php echo esc_html(AdminUi::euro((float) $application['total_amount'])); ?></strong>.
                            Le nouveau est recalculé au serveur à l’enregistrement, et les lignes
                            figées sont remplacées.
                        </p>
                    </aside>
                </div>
            </form>
        </div>
        <?php
    }

    public static function handleAmend(): void
    {
        $applicationId = isset($_POST['application_id']) ? absint($_POST['application_id']) : 0;
        check_admin_referer('sub_amend_application_' . $applicationId);
        AdminUi::requireCap('sub_manage_memberships');

        $service     = new ApplicationService();
        $application = $service->find($applicationId);
        $previous    = $application === null ? 0.0 : (float) $application['total_amount'];

        try {
            $total = $service->amend(
                $applicationId,
                get_current_user_id(),
                isset($_POST['plan']) ? sanitize_key(wp_unslash($_POST['plan'])) : '',
                MembershipFields::collectAnswers($_POST['options'] ?? []),
                isset($_POST['payment_method']) ? sanitize_key(wp_unslash($_POST['payment_method'])) : '',
                sanitize_text_field(wp_unslash((string) ($_POST['comment'] ?? '')))
            );

            AdminUi::redirect(
                ApplicationsScreen::SLUG,
                self::confirmation($service, $applicationId, $previous, $total),
                false,
                ['tab' => ApplicationsScreen::TAB]
            );
        } catch (IncompleteApplication $e) {
            // Le détail des réponses manquantes revient sur l'écran de
            // correction, pas sur la liste : c'est là que se trouve la case.
            self::back($applicationId, $e->getMessage());
        } catch (\RuntimeException $e) {
            self::back($applicationId, $e->getMessage());
        }
    }

    /**
     * Ce que le bureau doit lire après avoir corrigé.
     *
     * Le montant seul ne suffit pas quand un règlement est déjà encaissé : la
     * question qui suit immédiatement est « et donc, il me doit combien ? ».
     * Y répondre ici évite de la reposer au trésorier.
     */
    private static function confirmation(
        ApplicationService $service,
        int $applicationId,
        float $previous,
        float $total,
    ): string {
        $message = abs($total - $previous) < 0.005
            ? sprintf('Dossier corrigé. Le montant reste de %s.', AdminUi::euro($total))
            : sprintf(
                'Dossier corrigé : %s au lieu de %s.',
                AdminUi::euro($total),
                AdminUi::euro($previous)
            );

        $paid = $service->paidAmount($applicationId);
        $gap  = round($total - $paid, 2);

        if ($paid > 0 && abs($gap) >= 0.005) {
            $message .= $gap > 0
                ? sprintf(' %s déjà réglés : il reste %s à encaisser.', AdminUi::euro($paid), AdminUi::euro($gap))
                : sprintf(' %s déjà réglés : %s à rembourser.', AdminUi::euro($paid), AdminUi::euro(-$gap));
        }

        return $message;
    }

    private static function back(int $applicationId, string $message): never
    {
        wp_safe_redirect(add_query_arg(
            ['sub_error' => rawurlencode($message)],
            self::url($applicationId)
        ));
        exit;
    }

    /**
     * @param list<\Subalcatel\Club\Membership\Plan> $plans
     */
    private static function planOf(array $plans, int $planId): ?\Subalcatel\Club\Membership\Plan
    {
        foreach ($plans as $plan) {
            if ($plan->id === $planId) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * Le formulaire de l'adhérent, servi dans wp-admin.
     *
     * Même feuille de style et même script qu'en front : le récapitulatif en
     * direct vient du serveur, et c'est lui qui masque les options hors sujet.
     * La charte du club n'existe pas dans l'administration — theme.json ne s'y
     * applique pas —, d'où les variables reposées par `admin.css`.
     */
    private static function enqueueMembershipForm(): void
    {
        $base = \Subalcatel\Club\PLUGIN_URL;

        wp_enqueue_style(
            'subalcatel-membership',
            $base . 'assets/css/membership.css',
            ['subalcatel-admin'],
            \Subalcatel\Club\VERSION
        );

        wp_enqueue_script(
            'subalcatel-membership',
            $base . 'assets/js/membership.js',
            [],
            \Subalcatel\Club\VERSION,
            true
        );

        wp_localize_script('subalcatel-membership', 'subalcatelQuote', [
            'endpoint' => rest_url('subalcatel/v1/quote'),
            'nonce'    => wp_create_nonce('wp_rest'),
        ]);
    }
}
