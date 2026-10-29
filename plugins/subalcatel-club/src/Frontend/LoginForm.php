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
        add_action('wp_login_failed', [self::class, 'onFailure'], 10, 2);

        // Un identifiant ou un mot de passe vide ne déclenche pas
        // `wp_login_failed` : WordPress s'arrête plus tôt. On rattrape ici,
        // sinon la personne repart sur l'écran natif sans comprendre pourquoi.
        add_filter('authenticate', [self::class, 'onEmptyCredentials'], 30, 3);
    }

    public static function render(): string
    {
        if (is_user_logged_in()) {
            return sprintf(
                '<div class="sub-notice sub-notice--success" role="status"><p>Vous êtes connecté. '
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

        // Bouton « œil » du mot de passe, que l'écran natif a et pas wp_login_form().
        wp_enqueue_script(
            'subalcatel-login',
            \Subalcatel\Club\PLUGIN_URL . 'assets/js/login.js',
            [],
            \Subalcatel\Club\VERSION,
            true
        );

        $redirect = self::redirectTarget();

        ob_start();

        if (isset($_GET['connexion'])) {
            $code = sanitize_key(wp_unslash((string) $_GET['connexion']));

            echo Notice::feedback('error', match ($code) {
                'vide'   => 'Renseignez votre identifiant et votre mot de passe.',
                'bloque' => sprintf(
                    'Trop de tentatives de connexion. Réessayez dans %d minutes, '
                    . 'ou utilisez « Mot de passe oublié ? » sous le formulaire.',
                    (int) ceil(\Subalcatel\Club\Support\SecuritySettings::windowSeconds() / 60)
                ),
                // Le journal montre surtout des adhérents qui tapent leur e-mail
                // avec le mot de passe de l'ancien site, souvent rempli par le
                // navigateur : il n'a pas été repris, il ne marchera jamais.
                default  => 'Identifiant ou mot de passe incorrect. Si votre navigateur '
                    . 'a rempli le mot de passe tout seul, c’est peut-être celui de '
                    . 'l’ancien site, qui ne fonctionne plus : effacez-le et tapez le '
                    . 'vôtre. Sinon, utilisez « Mot de passe oublié ? » sous le formulaire.',
            }); // déjà échappé
        }

        // Les mots de passe de l'ancien site n'ont pas été repris : un adhérent
        // qui ne s'est encore jamais connecté ici n'en a aucun qui fonctionne.
        // Le dire à tous, plutôt qu'aux seuls comptes jamais utilisés, évite
        // de révéler quels identifiants existent.
        echo Notice::feedback(
            'info',
            'Première connexion sur le nouveau site ? Le mot de passe de l’ancien site '
            . 'ne fonctionne plus : utilisez « Mot de passe oublié ? » sous le formulaire '
            . 'pour en créer un.',
            'sub-login__premiere'
        ); // déjà échappé

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

        $invitation = SignupForm::invitation();

        if ($invitation !== '') {
            printf('<p class="sub-login__signup">%s</p>', $invitation); // déjà échappé
        }

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
     *
     * Un blocage du ralentisseur passe aussi par ici : sans le distinguer, la
     * personne lirait « mot de passe incorrect », réessaierait avec le bon, et
     * prolongerait son propre blocage à chaque tentative.
     */
    public static function onFailure(string $username, ?\WP_Error $error = null): void
    {
        $source = isset($_POST[self::SOURCE])
            ? sanitize_url(wp_unslash((string) $_POST[self::SOURCE]))
            : '';

        if ($source === '') {
            return;
        }

        $code = $error !== null && $error->get_error_message('sub_locked_out') !== ''
            ? 'bloque'
            : 'echec';

        wp_safe_redirect(add_query_arg('connexion', $code, $source));
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
