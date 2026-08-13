<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

/**
 * Connexion aux couleurs du club : shortcode [subalcatel_connexion].
 *
 * Sans elle, un visiteur qui clique sur « Connexion » atterrit sur l'écran brut
 * de WordPress, qui ne ressemble en rien au site. Ce n'est pas qu'une question
 * d'apparence : cet écran affiche le logo WordPress, propose « Retour sur
 * Subalcatel » et perd le contexte de ce que la personne cherchait à faire.
 *
 * L'authentification elle-même reste celle de WordPress — ce formulaire ne fait
 * que la présenter. Réimplémenter une vérification de mot de passe serait la
 * plus mauvaise idée du projet.
 */
final class LoginForm
{
    /** Champ témoin : dit que la tentative vient de notre page. */
    private const SOURCE = 'sub_login_page';

    public static function register(): void
    {
        add_shortcode('subalcatel_connexion', [self::class, 'render']);
        add_action('wp_login_failed', [self::class, 'onFailure']);

        // Un identifiant ou un mot de passe vide ne déclenche pas
        // `wp_login_failed` : WordPress s'arrête plus tôt. On rattrape ici,
        // sinon la personne repart sur l'écran natif sans comprendre pourquoi.
        add_filter('authenticate', [self::class, 'onEmptyCredentials'], 30, 3);
    }

    public static function render(): string
    {
        if (is_user_logged_in()) {
            return sprintf(
                '<div class="sub-notice sub-notice--success"><p>Vous êtes connecté. '
                . '<a href="%s">Aller à mon espace</a></p></div>',
                esc_url(Pages::url(Pages::MEMBER_AREA) ?: home_url('/'))
            );
        }

        wp_enqueue_style(
            'subalcatel-membership',
            \Subalcatel\Club\PLUGIN_URL . 'assets/css/membership.css',
            [],
            \Subalcatel\Club\VERSION
        );

        $redirect = self::redirectTarget();

        ob_start();

        if (isset($_GET['connexion'])) {
            $code = sanitize_key(wp_unslash((string) $_GET['connexion']));

            printf(
                '<div class="sub-notice sub-notice--error"><p>%s</p></div>',
                esc_html(match ($code) {
                    'vide'  => 'Renseignez votre identifiant et votre mot de passe.',
                    default => 'Identifiant ou mot de passe incorrect. Réessayez, ou '
                        . 'demandez un nouveau mot de passe.',
                })
            );
        }

        echo '<div class="sub-login">';

        wp_login_form([
            'redirect'       => $redirect,
            'label_username' => 'Identifiant ou adresse e-mail',
            'label_password' => 'Mot de passe',
            'label_remember' => 'Rester connecté',
            'label_log_in'   => 'Se connecter',
            'remember'       => true,
        ]);

        printf(
            '<input type="hidden" name="%s" value="%s" form="loginform">',
            esc_attr(self::SOURCE),
            esc_url(get_permalink() ?: home_url('/'))
        );

        printf(
            '<p class="sub-login__lost"><a href="%s">Mot de passe oublié ?</a></p>',
            esc_url(wp_lostpassword_url($redirect))
        );

        echo '</div>';

        return (string) ob_get_clean();
    }

    /**
     * Où renvoyer après une connexion réussie.
     *
     * `redirect_to` est repris s'il pointe vers ce site — c'est lui qui ramène
     * la personne sur la page qu'elle voulait ouvrir. Une adresse externe est
     * ignorée : un lien de connexion ne doit pas servir de tremplin.
     */
    private static function redirectTarget(): string
    {
        $requested = isset($_GET['redirect_to'])
            ? sanitize_url(wp_unslash((string) $_GET['redirect_to']))
            : '';

        $fallback = Pages::url(Pages::MEMBER_AREA) ?: home_url('/');

        if ($requested === '') {
            return $fallback;
        }

        $host = wp_parse_url($requested, PHP_URL_HOST);

        return $host === null || $host === wp_parse_url(home_url(), PHP_URL_HOST)
            ? $requested
            : $fallback;
    }

    /**
     * Ramène l'échec sur notre page plutôt que sur l'écran natif.
     */
    public static function onFailure(string $username): void
    {
        $source = isset($_POST[self::SOURCE])
            ? sanitize_url(wp_unslash((string) $_POST[self::SOURCE]))
            : '';

        if ($source === '') {
            return;
        }

        wp_safe_redirect(add_query_arg('connexion', 'echec', $source));
        exit;
    }

    /**
     * @param \WP_User|\WP_Error|null $user
     * @return \WP_User|\WP_Error|null
     */
    public static function onEmptyCredentials(mixed $user, string $username, string $password): mixed
    {
        if ($username !== '' && $password !== '') {
            return $user;
        }

        $source = isset($_POST[self::SOURCE])
            ? sanitize_url(wp_unslash((string) $_POST[self::SOURCE]))
            : '';

        if ($source === '') {
            return $user;
        }

        wp_safe_redirect(add_query_arg('connexion', 'vide', $source));
        exit;
    }

    /**
     * Adresse de la page de connexion du club, ou l'écran natif à défaut.
     *
     * Utilisée partout où le plugin propose de se connecter, pour qu'un seul
     * changement suffise si le club renonce à sa page.
     */
    public static function url(string $redirectTo = ''): string
    {
        $page = Pages::url(Pages::LOGIN);

        if ($page === '') {
            return wp_login_url($redirectTo);
        }

        return $redirectTo === '' ? $page : add_query_arg('redirect_to', urlencode($redirectTo), $page);
    }
}
