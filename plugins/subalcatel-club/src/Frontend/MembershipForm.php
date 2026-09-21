<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use Subalcatel\Club\Membership\ApplicantIdentity;
use Subalcatel\Club\Membership\ApplicationService;
use Subalcatel\Club\Membership\CampaignRepository;
use Subalcatel\Club\Membership\IncompleteApplication;
use Subalcatel\Club\Membership\PaymentMethods;
use Subalcatel\Club\Membership\PricingEngine;

/**
 * Formulaire d'adhésion : shortcode [subalcatel_adhesion].
 *
 * Le rendu est fait au serveur ; le JavaScript ne sert qu'à masquer les options
 * non pertinentes et à rafraîchir le récapitulatif. Sans JavaScript, le
 * formulaire reste soumettable — le calcul se refait de toute façon au serveur.
 *
 * Un dossier refusé ne repart pas de zéro : ce qui a été saisi est mis de côté
 * le temps du renvoi, et le formulaire se réaffiche rempli, la case fautive
 * désignée. Tout retaper pour une date de naissance oubliée avait de quoi faire
 * abandonner l'adhésion.
 */
final class MembershipForm
{
    /** Durée pendant laquelle une saisie refusée reste rattrapable. */
    private const RETRY_TTL = 30 * MINUTE_IN_SECONDS;

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

        $userId = get_current_user_id();

        $repo     = new CampaignRepository();
        $campaign = $repo->openCampaign();

        if ($campaign === null) {
            return self::notice(
                'Aucune campagne ouverte',
                'Les adhésions ne sont pas ouvertes actuellement. Revenez à partir du 15 septembre.'
            );
        }

        $campaignId = (int) $campaign['id'];

        // Un dossier déjà déposé sur cette campagne prend la place du
        // formulaire. Le laisser affiché derrière la confirmation invitait à
        // resoumettre, et le bureau ramassait les doublons (retour du bureau,
        // 17/09/2026). Pour en déposer un autre, il faut d'abord annuler
        // celui-là — le récapitulatif ci-dessous y mène.
        $standing = (new ApplicationService())->currentFor($userId, $campaignId);

        if ($standing !== null) {
            return self::standingApplication($standing);
        }

        $plans      = $repo->plans($campaignId);
        $options    = $repo->options($campaignId);

        if ($plans === []) {
            return self::notice('Campagne incomplète', 'Aucun plan n’est publié pour cette campagne.');
        }

        // Saisie mise de côté par une soumission refusée, s'il y en a une.
        $retry = self::takeRetry($userId);

        $identity = $retry['identity'] ?? ApplicantIdentity::values($userId);
        $errors   = $retry['errors'] ?? [];
        $notices  = $retry['notices'] ?? [];
        $answers  = $retry['options'] ?? [];
        $payment  = (string) ($retry['payment_method'] ?? '');
        $planSlug = (string) ($retry['plan'] ?? $plans[0]->slug);
        $plan     = $repo->planBySlug($campaignId, $planSlug) ?? $plans[0];

        ob_start();
        echo self::feedback($errors, $notices); // déjà échappé
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

                    <?php self::renderIdentity($identity, $errors); ?>

                    <?php MembershipFields::plans($plans, $plan); ?>

                    <?php
                    // Ce que le serveur retiendrait de cette saisie : c'est lui
                    // qui décide de ce qui s'affiche, pas une règle recopiée ici.
                    $resolved = PricingEngine::resolveAnswers($plan, $answers, $options);
                    ?>
                    <?php foreach ($options as $option) : ?>
                        <?php MembershipFields::option($option, $plan, $resolved, $errors); ?>
                    <?php endforeach; ?>

                    <?php MembershipFields::payment($payment, $errors); ?>

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
                        Le règlement se fait ensuite selon le mode que vous avez choisi.
                    </p>
                </aside>
            </div>
        </form>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Ce qui s'affiche à la place du formulaire quand un dossier occupe déjà la
     * place : où il en est, et par où le reprendre.
     *
     * @param array<string, mixed> $application
     */
    private static function standingApplication(array $application): string
    {
        $status = (string) $application['status'];

        $where = $status === ApplicationService::STATUS_ACTIVE
            ? 'Votre adhésion est active pour cette saison.'
            : 'Il suit son cours : le bureau vous tiendra informé de chaque étape.';

        $link = Pages::exists(Pages::MEMBERSHIP)
            ? sprintf(
                ' <a href="%s">Voir mon adhésion</a>.',
                esc_url(Pages::url(Pages::MEMBERSHIP))
            )
            : '';

        return self::notice(
            'Vous avez déjà un dossier pour cette campagne',
            sprintf(
                'Dossier <code>%s</code> — %s.<br>%s%s',
                esc_html((string) $application['reference']),
                esc_html(self::euro((float) $application['total_amount'])),
                esc_html($where),
                $link
            )
        );
    }

    /**
     * État civil et coordonnées, préremplis depuis le profil.
     *
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private static function renderIdentity(array $values, array $errors): void
    {
        ?>
        <fieldset class="sub-field">
            <legend>Vos coordonnées</legend>
            <p class="sub-field__help">
                La FFESSM demande l’état civil complet pour délivrer la licence — deux
                homonymes ne s’y distinguent que par le lieu et la date de naissance.
                Ces informations mettent votre profil à jour.
            </p>

            <div class="sub-grid">
                <?php foreach (ApplicantIdentity::fields() as $name => $field) : ?>
                    <?php
                    $id    = 'sub-adh-' . str_replace('_', '-', $name);
                    $error = $errors[$name] ?? '';
                    $value = (string) ($values[$name] ?? '');
                    $wide  = $field['type'] === 'textarea';
                    ?>
                    <p class="sub-input <?php echo $wide ? 'sub-input--wide' : ''; ?>
                              <?php echo $error !== '' ? 'sub-input--error' : ''; ?>">
                        <label for="<?php echo esc_attr($id); ?>">
                            <?php echo esc_html($field['label']); ?>
                            <?php if ($field['required']) : ?>
                                <span class="sub-field__required" aria-label="obligatoire">*</span>
                            <?php endif; ?>
                        </label>

                        <?php if ($wide) : ?>
                            <textarea id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>"
                                      rows="2"
                                      placeholder="<?php echo esc_attr((string) ($field['placeholder'] ?? '')); ?>"
                                      <?php echo $field['required'] ? 'required' : ''; ?>
                            ><?php echo esc_textarea($value); ?></textarea>
                        <?php else : ?>
                            <input type="<?php echo esc_attr($field['type']); ?>"
                                   id="<?php echo esc_attr($id); ?>"
                                   name="<?php echo esc_attr($name); ?>"
                                   value="<?php echo esc_attr($value); ?>"
                                   placeholder="<?php echo esc_attr((string) ($field['placeholder'] ?? '')); ?>"
                                   <?php echo $name === ApplicantIdentity::ACCOUNT_EMAIL ? 'data-primary-email' : ''; ?>
                                   <?php echo $name === 'secondary_email' ? 'data-secondary-email' : ''; ?>
                                   <?php echo $field['required'] ? 'required' : ''; ?>>
                        <?php endif; ?>

                        <?php if ($error !== '') : ?>
                            <small class="sub-input__error"><?php echo esc_html($error); ?></small>
                        <?php elseif (!empty($field['help'])) : ?>
                            <small class="sub-input__help"><?php echo esc_html((string) $field['help']); ?></small>
                        <?php endif; ?>

                        <?php if ($name === 'secondary_email') : ?>
                            <small class="sub-input__error" data-secondary-email-warning hidden>
                                Cette adresse est identique à la principale : elle ne sera pas retenue.
                            </small>
                        <?php endif; ?>
                    </p>
                <?php endforeach; ?>
            </div>
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

        $userId   = get_current_user_id();
        $redirect = wp_get_referer() ?: home_url('/');

        $planSlug = isset($_POST['plan']) ? sanitize_key(wp_unslash($_POST['plan'])) : '';
        $payment  = isset($_POST['payment_method'])
            ? sanitize_key(wp_unslash($_POST['payment_method']))
            : '';

        $answers = MembershipFields::collectAnswers($_POST['options'] ?? []);

        /** @var array<string, mixed> $raw */
        $raw      = wp_unslash($_POST);
        $identity = ApplicantIdentity::collect($userId, $raw);

        $retry = [
            'plan'           => $planSlug,
            'options'        => $answers,
            'payment_method' => $payment,
            'identity'       => $identity['values'],
            'notices'        => $identity['notices'],
        ];

        // Coordonnées d'abord : le dossier s'appuie dessus, et rien ne sert de
        // chiffrer une adhésion dont l'état civil est incomplet.
        if ($identity['errors'] !== []) {
            self::keepRetry($userId, $retry + ['errors' => $identity['errors']]);
            wp_safe_redirect(add_query_arg(
                ['sub_error' => rawurlencode('Vérifiez les informations signalées ci-dessous.')],
                $redirect
            ));
            exit;
        }

        ApplicantIdentity::save($userId, $identity['values']);

        try {
            $service = new ApplicationService();
            $id      = $service->submit($userId, $campaignId, $planSlug, $answers, $payment);

            // Le dossier est passé : plus rien à rattraper. Reste ce qu'il faut
            // dire à l'adhérent — le courriel secondaire écarté, par exemple, qu'il
            // ne découvrirait autrement qu'en relisant son profil.
            self::forgetRetry($userId);

            if ($identity['notices'] !== []) {
                self::keepRetry($userId, ['notices' => $identity['notices']]);
            }

            wp_safe_redirect(add_query_arg(['sub_application' => $id], $redirect));
        } catch (IncompleteApplication $e) {
            $errors = array_map(
                static fn (string $label): string => sprintf('%s : à renseigner.', $label),
                $e->fields
            );

            self::keepRetry($userId, $retry + ['errors' => $errors]);
            wp_safe_redirect(add_query_arg(['sub_error' => rawurlencode($e->getMessage())], $redirect));
        } catch (\RuntimeException $e) {
            self::keepRetry($userId, $retry + ['errors' => []]);
            wp_safe_redirect(add_query_arg(['sub_error' => rawurlencode($e->getMessage())], $redirect));
        }

        exit;
    }

    // ------------------------------------------------- Saisie mise de côté

    /**
     * @param array<string, mixed> $data
     */
    private static function keepRetry(int $userId, array $data): void
    {
        set_transient(self::retryKey($userId), $data, self::RETRY_TTL);
    }

    /**
     * Reprend la saisie mise de côté, et l'oublie aussitôt.
     *
     * Elle ne vaut que pour le réaffichage qui suit : la laisser traîner ferait
     * réapparaître de vieux choix sur une visite ultérieure.
     *
     * @return array<string, mixed>|null
     */
    private static function takeRetry(int $userId): ?array
    {
        $data = get_transient(self::retryKey($userId));

        if (!is_array($data)) {
            return null;
        }

        self::forgetRetry($userId);

        return $data;
    }

    private static function forgetRetry(int $userId): void
    {
        delete_transient(self::retryKey($userId));
    }

    private static function retryKey(int $userId): string
    {
        return 'sub_application_retry_' . $userId;
    }

    /**
     * Message affiché au retour de soumission : dossier accepté, ou motif du refus.
     *
     * @param array<string, string> $errors
     * @param list<string> $notices
     */
    private static function feedback(array $errors, array $notices): string
    {
        $html = '';

        if (isset($_GET['sub_application'])) {
            $service     = new ApplicationService();
            $application = $service->find(absint($_GET['sub_application']));

            // On ne montre un dossier qu'à son auteur.
            if ($application !== null && (int) $application['user_id'] === get_current_user_id()) {
                $method = (string) ($application['payment_method'] ?? '');

                // Le lien vient de la campagne du dossier, pas de celle qui se
                // trouve ouverte aujourd'hui : pendant la quinzaine où deux
                // campagnes se chevauchent, elles n'encaissent pas au même
                // endroit.
                $link = (new CampaignRepository())
                    ->paymentLink((int) $application['campaign_id'], $method);

                // `instructionsHtml` rend déjà du HTML échappé, lien de
                // paiement compris : le repasser à `esc_html` afficherait la
                // balise au lieu du lien.
                $html .= sprintf(
                    '<div class="sub-notice sub-notice--success" role="status"><strong>Dossier %s enregistré</strong>'
                    . '<p>Montant à régler : <strong>%s</strong>, par %s. %s</p></div>',
                    esc_html((string) $application['reference']),
                    esc_html(self::euro((float) $application['total_amount'])),
                    esc_html(PaymentMethods::label($method)),
                    PaymentMethods::instructionsHtml($method, $link)
                );
            }
        }

        if (isset($_GET['sub_error'])) {
            $detail = '';

            foreach ($errors as $message) {
                $detail .= '<li>' . esc_html($message) . '</li>';
            }

            $html .= sprintf(
                '<div class="sub-notice sub-notice--error" role="alert" tabindex="-1" autofocus>'
                . '<strong>Dossier non enregistré</strong><p>%s</p>'
                . '<p>Votre saisie est conservée : corrigez ce qui est signalé et renvoyez le dossier.</p>'
                . '%s</div>',
                esc_html(sanitize_text_field(wp_unslash((string) $_GET['sub_error']))),
                $detail === '' ? '' : '<ul>' . $detail . '</ul>'
            );
        }

        foreach ($notices as $notice) {
            $html .= sprintf(
                '<div class="sub-notice sub-notice--info" role="status"><p>%s</p></div>',
                esc_html($notice)
            );
        }

        return $html;
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
