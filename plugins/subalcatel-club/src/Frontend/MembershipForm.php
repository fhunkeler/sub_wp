<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use Subalcatel\Club\Membership\ApplicationService;
use Subalcatel\Club\Membership\CampaignRepository;
use Subalcatel\Club\Membership\Option;

/**
 * Formulaire d'adhésion : shortcode [subalcatel_adhesion].
 *
 * Le rendu est fait au serveur ; le JavaScript ne sert qu'à masquer les options
 * non pertinentes et à rafraîchir le récapitulatif. Sans JavaScript, le
 * formulaire reste soumettable — le calcul se refait de toute façon au serveur.
 */
final class MembershipForm
{
    public static function register(): void
    {
        add_shortcode('subalcatel_adhesion', [self::class, 'render']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
        add_action('admin_post_sub_submit_application', [self::class, 'handleSubmit']);
    }

    public static function enqueue(): void
    {
        global $post;

        if (!$post instanceof \WP_Post || !has_shortcode((string) $post->post_content, 'subalcatel_adhesion')) {
            return;
        }

        $base = \Subalcatel\Club\PLUGIN_URL;

        wp_enqueue_style('subalcatel-membership', $base . 'assets/css/membership.css', [], \Subalcatel\Club\VERSION);
        wp_enqueue_script('subalcatel-membership', $base . 'assets/js/membership.js', [], \Subalcatel\Club\VERSION, true);

        wp_localize_script('subalcatel-membership', 'subalcatelQuote', [
            'endpoint' => rest_url('subalcatel/v1/quote'),
            'nonce'    => wp_create_nonce('wp_rest'),
        ]);
    }

    public static function render(): string
    {
        if (!is_user_logged_in()) {
            return self::notice(
                'Connectez-vous pour adhérer',
                sprintf(
                    'L’adhésion se fait depuis votre compte. <a href="%s">Se connecter</a>.',
                    esc_url(wp_login_url(get_permalink()))
                )
            );
        }

        $feedback = self::feedback();

        $repo     = new CampaignRepository();
        $campaign = $repo->openCampaign();

        if ($campaign === null) {
            return self::notice(
                'Aucune campagne ouverte',
                'Les adhésions ne sont pas ouvertes actuellement. Revenez à partir du 15 septembre.'
            );
        }

        $campaignId = (int) $campaign['id'];
        $plans      = $repo->plans($campaignId);
        $options    = $repo->options($campaignId);

        if ($plans === []) {
            return self::notice('Campagne incomplète', 'Aucun plan n’est publié pour cette campagne.');
        }

        ob_start();
        echo $feedback; // déjà échappé
        ?>
        <form class="sub-membership"
              method="post"
              action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              data-campaign="<?php echo esc_attr((string) $campaignId); ?>">

            <input type="hidden" name="action" value="sub_submit_application">
            <input type="hidden" name="campaign_id" value="<?php echo esc_attr((string) $campaignId); ?>">
            <?php wp_nonce_field('sub_submit_application_' . $campaignId); ?>

            <h2 class="sub-membership__title"><?php echo esc_html((string) $campaign['title']); ?></h2>
            <p class="sub-membership__dates">
                Adhésion valable du
                <strong><?php echo esc_html(self::frDate((string) $campaign['valid_from'])); ?></strong>
                au
                <strong><?php echo esc_html(self::frDate((string) $campaign['valid_until'])); ?></strong>.
            </p>

            <div class="sub-membership__layout">
                <div class="sub-membership__fields">

                    <fieldset class="sub-field">
                        <legend>Formule d’adhésion</legend>
                        <?php foreach ($plans as $i => $plan) : ?>
                            <label class="sub-choice">
                                <input type="radio" name="plan" value="<?php echo esc_attr($plan->slug); ?>"
                                       <?php checked($i, 0); ?> required>
                                <span class="sub-choice__label"><?php echo esc_html($plan->title); ?></span>
                                <span class="sub-choice__price"><?php echo esc_html(self::euro($plan->basePrice)); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>

                    <?php foreach ($options as $option) : ?>
                        <?php self::renderOption($option); ?>
                    <?php endforeach; ?>

                </div>

                <aside class="sub-summary">
                    <h3 class="sub-summary__title">Détail de votre cotisation</h3>
                    <?php // La région vivante s'arrête au chiffrage : posée sur l'aside entier,
                          // elle faisait réannoncer « Soumettre mon dossier » à chaque option. ?>
                    <div class="sub-summary__quote" aria-live="polite">
                        <div class="sub-summary__lines" data-quote-lines>
                            <p class="sub-summary__empty">Choisissez une formule pour voir le détail.</p>
                        </div>
                        <p class="sub-summary__total">
                            <span>Total</span>
                            <strong data-quote-total>—</strong>
                        </p>
                    </div>
                    <button type="submit" class="sub-button">Soumettre mon dossier</button>
                    <p class="sub-summary__note">
                        Le montant est recalculé et vérifié à la soumission.
                        Le règlement se fait ensuite par chèque ou HelloAsso.
                    </p>
                </aside>
            </div>
        </form>
        <?php

        return (string) ob_get_clean();
    }

    private static function renderOption(Option $option): void
    {
        $attrs = sprintf(
            'data-option="%s" data-plans="%s"',
            esc_attr($option->name),
            esc_attr((string) wp_json_encode($option->plans))
        );

        if ($option->conditionOption !== null) {
            $attrs .= sprintf(
                ' data-depends-on="%s" data-depends-values="%s" hidden',
                esc_attr($option->conditionOption),
                esc_attr((string) wp_json_encode($option->conditionValues))
            );
        }
        ?>
        <fieldset class="sub-field" <?php echo $attrs; // phpcs:ignore ?>>
            <legend>
                <?php echo esc_html($option->label); ?>
                <?php if ($option->isRequired) : ?>
                    <span class="sub-field__required" aria-label="obligatoire">*</span>
                <?php endif; ?>
            </legend>

            <?php if ($option->help !== '') : ?>
                <p class="sub-field__help"><?php echo esc_html($option->help); ?></p>
            <?php endif; ?>

            <?php foreach ($option->choices as $choice) : ?>
                <label class="sub-choice">
                    <input type="radio"
                           name="options[<?php echo esc_attr($option->name); ?>]"
                           value="<?php echo esc_attr((string) $choice['value']); ?>">
                    <span class="sub-choice__label"><?php echo esc_html((string) $choice['label']); ?></span>
                    <?php if (abs((float) $choice['amount']) >= 0.005) : ?>
                        <span class="sub-choice__price">
                            <?php echo esc_html(self::euro((float) $choice['amount'], true)); ?>
                        </span>
                    <?php endif; ?>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <?php
    }

    /**
     * Soumission du dossier. Le prix est recalculé au serveur : rien de ce qui
     * vient du navigateur n'est cru sur parole.
     */
    public static function handleSubmit(): void
    {
        $campaignId = isset($_POST['campaign_id']) ? absint($_POST['campaign_id']) : 0;

        if (!is_user_logged_in() || !check_admin_referer('sub_submit_application_' . $campaignId)) {
            wp_die('Requête non autorisée.', 403);
        }

        $planSlug = isset($_POST['plan']) ? sanitize_key(wp_unslash($_POST['plan'])) : '';
        $answers  = [];

        foreach ((array) ($_POST['options'] ?? []) as $name => $value) {
            $key = sanitize_key((string) $name);

            $answers[$key] = is_array($value)
                ? array_map('sanitize_text_field', array_map('wp_unslash', $value))
                : sanitize_text_field(wp_unslash((string) $value));
        }

        $redirect = wp_get_referer() ?: home_url('/');

        try {
            $service = new ApplicationService();
            $id      = $service->submit(get_current_user_id(), $campaignId, $planSlug, $answers);

            wp_safe_redirect(add_query_arg(['sub_application' => $id], $redirect));
        } catch (\RuntimeException $e) {
            wp_safe_redirect(add_query_arg(['sub_error' => rawurlencode($e->getMessage())], $redirect));
        }

        exit;
    }

    /**
     * Message affiché au retour de soumission : dossier accepté, ou motif du refus.
     */
    private static function feedback(): string
    {
        if (isset($_GET['sub_application'])) {
            $service     = new ApplicationService();
            $application = $service->find(absint($_GET['sub_application']));

            // On ne montre un dossier qu'à son auteur.
            if ($application === null || (int) $application['user_id'] !== get_current_user_id()) {
                return '';
            }

            return sprintf(
                '<div class="sub-notice sub-notice--success" role="status"><strong>Dossier %s enregistré</strong>'
                . '<p>Montant à régler : <strong>%s</strong>. Le bureau vous confirmera la réception '
                . 'de votre règlement par chèque ou HelloAsso.</p></div>',
                esc_html((string) $application['reference']),
                esc_html(self::euro((float) $application['total_amount']))
            );
        }

        if (isset($_GET['sub_error'])) {
            return sprintf(
                '<div class="sub-notice sub-notice--error" role="alert" tabindex="-1" autofocus>'
                . '<strong>Dossier non enregistré</strong><p>%s</p></div>',
                esc_html(sanitize_text_field(wp_unslash((string) $_GET['sub_error'])))
            );
        }

        return '';
    }

    private static function notice(string $title, string $html): string
    {
        return sprintf(
            '<div class="sub-notice"><strong>%s</strong><p>%s</p></div>',
            esc_html($title),
            wp_kses_post($html)
        );
    }

    private static function euro(float $amount, bool $signed = false): string
    {
        $prefix = $signed && $amount > 0 ? '+' : '';

        return $prefix . number_format($amount, 2, ',', ' ') . ' €';
    }

    private static function frDate(string $isoDate): string
    {
        $ts = strtotime($isoDate);

        return $ts === false ? $isoDate : wp_date('j F Y', $ts);
    }
}
