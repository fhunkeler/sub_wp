<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use RuntimeException;
use Subalcatel\Club\Identity\AccountApproval;
use Subalcatel\Club\Identity\Roles;
use Subalcatel\Club\Notifications\EmailTemplates;
use Subalcatel\Club\Notifications\Mailer;

/**
 * Création de compte : shortcode [subalcatel_creer_compte].
 *
 * Le compte est créé **en attente de validation**. La personne peut se
 * connecter aussitôt — sinon elle n'aurait aucun moyen de suivre sa demande —
 * mais ne peut ni adhérer ni s'inscrire à une sortie tant que le bureau n'a pas
 * tranché. Le blocage est porté par [EligibilityPolicy], pas par ce formulaire.
 *
 * La création reste ouverte à tous, y compris quand WordPress interdit les
 * inscriptions publiques (`users_can_register`) : ce réglage protège l'écran
 * natif, celui que le club n'utilise pas. Ici, le garde-fou est la validation
 * par le bureau, pas l'interdiction de créer.
 */
final class SignupForm
{
    public const ACTION = 'sub_signup';

    public static function register(): void
    {
        add_shortcode('subalcatel_creer_compte', [self::class, 'render']);
        add_action('admin_post_nopriv_' . self::ACTION, [self::class, 'handle']);
        add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
    }

    public static function render(): string
    {
        if (is_user_logged_in()) {
            return self::alreadyIn();
        }

        wp_enqueue_style(
            'subalcatel-membership',
            \Subalcatel\Club\PLUGIN_URL . 'assets/css/membership.css',
            [],
            \Subalcatel\Club\VERSION
        );

        $values = [
            'first_name' => self::old('first_name'),
            'last_name'  => self::old('last_name'),
            'email'      => self::old('email'),
        ];

        ob_start();

        if (isset($_GET['sub_error'])) {
            echo Notice::feedback(
                'error',
                sanitize_text_field(wp_unslash((string) $_GET['sub_error']))
            ); // déjà échappé
        }
        ?>
        <form class="sub-signup" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
            <?php wp_nonce_field(self::ACTION); ?>

            <p class="sub-help">
                Créez votre compte pour constituer votre dossier d’adhésion.
                Le bureau le validera sous quelques jours ; vous recevrez un courriel.
            </p>

            <p>
                <label for="sub-signup-first">Prénom <span class="sub-field__required">*</span></label>
                <input type="text" id="sub-signup-first" name="first_name" required
                       autocomplete="given-name"
                       value="<?php echo esc_attr($values['first_name']); ?>">
            </p>

            <p>
                <label for="sub-signup-last">Nom <span class="sub-field__required">*</span></label>
                <input type="text" id="sub-signup-last" name="last_name" required
                       autocomplete="family-name"
                       value="<?php echo esc_attr($values['last_name']); ?>">
            </p>

            <p>
                <label for="sub-signup-email">Adresse e-mail <span class="sub-field__required">*</span></label>
                <input type="email" id="sub-signup-email" name="email" required
                       autocomplete="email"
                       value="<?php echo esc_attr($values['email']); ?>">
                <span class="sub-help">Elle servira d’identifiant et recevra les messages du club.</span>
            </p>

            <p>
                <label for="sub-signup-pass">Mot de passe <span class="sub-field__required">*</span></label>
                <input type="password" id="sub-signup-pass" name="password" required
                       minlength="10" autocomplete="new-password">
                <span class="sub-help">Dix caractères minimum.</span>
            </p>

            <p>
                <label class="sub-check">
                    <input type="checkbox" name="consent" value="1" required>
                    J’accepte que le club conserve ces informations pour gérer mon adhésion.
                    <?php if (Pages::exists(Pages::PRIVACY)) : ?>
                        <a href="<?php echo esc_url(Pages::url(Pages::PRIVACY)); ?>">Politique de confidentialité</a>.
                    <?php endif; ?>
                </label>
            </p>

            <p><button type="submit" class="sub-button">Créer mon compte</button></p>

            <p class="sub-help">
                Vous avez déjà un compte ?
                <a href="<?php echo esc_url(LoginForm::url()); ?>">Se connecter</a>.
            </p>
        </form>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Message affiché à quelqu'un déjà connecté.
     *
     * Le contenu dépend de l'état de son compte : rien n'est plus décourageant
     * qu'un formulaire de création à qui vient d'en créer un.
     */
    private static function alreadyIn(): string
    {
        $userId = get_current_user_id();

        if (AccountApproval::isPending($userId)) {
            return '<div class="sub-notice sub-notice--info"><p><strong>Votre compte est créé.</strong> '
                . 'Le bureau doit encore le valider ; vous recevrez un courriel dès que ce sera fait. '
                . 'Vous pourrez alors constituer votre dossier d’adhésion.</p></div>';
        }

        if (AccountApproval::isRefused($userId)) {
            return '<div class="sub-notice sub-notice--error"><p>Votre compte n’a pas été validé '
                . 'par le bureau. Contactez-le si vous pensez qu’il s’agit d’une erreur.</p></div>';
        }

        return sprintf(
            '<div class="sub-notice sub-notice--success"><p>Vous êtes déjà connecté. '
            . '<a href="%s">Aller à mon espace</a></p></div>',
            esc_url(Pages::url(Pages::MEMBER_AREA) ?: home_url('/'))
        );
    }

    public static function handle(): void
    {
        check_admin_referer(self::ACTION);

        $back = wp_get_referer() ?: (Pages::url(Pages::SIGNUP) ?: home_url('/'));

        try {
            $userId = self::create();
        } catch (RuntimeException $e) {
            wp_safe_redirect(add_query_arg([
                'sub_error'  => rawurlencode($e->getMessage()),
                'first_name' => rawurlencode(self::posted('first_name')),
                'last_name'  => rawurlencode(self::posted('last_name')),
                'email'      => rawurlencode(self::posted('email')),
            ], $back));
            exit;
        }

        // On connecte tout de suite : la personne doit pouvoir revenir suivre
        // sa demande sans avoir à retenir qu'elle a créé un compte inutilisable.
        wp_set_current_user($userId);
        wp_set_auth_cookie($userId);

        wp_safe_redirect(Pages::url(Pages::MEMBER_AREA) ?: home_url('/'));
        exit;
    }

    private static function create(): int
    {
        $first    = self::posted('first_name');
        $last     = self::posted('last_name');
        $email    = sanitize_email(self::posted('email'));
        $password = (string) ($_POST['password'] ?? '');

        if ($first === '' || $last === '') {
            throw new RuntimeException('Renseignez votre prénom et votre nom.');
        }

        if (!is_email($email)) {
            throw new RuntimeException('Cette adresse e-mail n’est pas valide.');
        }

        if (email_exists($email)) {
            throw new RuntimeException(
                'Un compte existe déjà avec cette adresse. Connectez-vous, ou demandez '
                . 'un nouveau mot de passe.'
            );
        }

        if (strlen($password) < 10) {
            throw new RuntimeException('Le mot de passe doit compter au moins dix caractères.');
        }

        if (empty($_POST['consent'])) {
            throw new RuntimeException('Vous devez accepter la conservation de vos informations.');
        }

        $userId = wp_insert_user([
            'user_login'   => self::uniqueLogin($first, $last),
            'user_email'   => $email,
            'user_pass'    => $password,
            'first_name'   => $first,
            'last_name'    => $last,
            'display_name' => $first . ' ' . $last,
            'role'         => Roles::GUEST,
        ]);

        if (is_wp_error($userId)) {
            throw new RuntimeException('La création du compte a échoué : ' . $userId->get_error_message());
        }

        AccountApproval::markPending((int) $userId);
        self::notifyOffice((int) $userId);

        return (int) $userId;
    }

    /**
     * Identifiant lisible et unique.
     *
     * Dérivé du nom plutôt que de l'adresse : il s'affiche dans l'écran Comptes
     * de WordPress, où « prenom.nom » se reconnaît alors que
     * « jdupont1987 » ne dit rien à personne.
     */
    private static function uniqueLogin(string $first, string $last): string
    {
        $base = sanitize_user(
            remove_accents($first) . '.' . remove_accents($last),
            true
        );
        $base = strtolower($base) ?: 'membre';

        $login = $base;
        $i     = 2;

        while (username_exists($login)) {
            $login = $base . $i++;
        }

        return $login;
    }

    /**
     * Prévient les personnes habilitées à valider.
     *
     * Sans cet avertissement, un compte peut attendre des semaines : personne
     * ne consulte spontanément une file vide.
     */
    private static function notifyOffice(int $userId): void
    {
        $user = get_userdata($userId);

        if (!$user) {
            return;
        }

        // Vers la file elle-même, pas vers l'annuaire : le courriel annonce une
        // demande à traiter, le lien doit ouvrir là où on la traite.
        $link = \Subalcatel\Club\Admin\AdminUi::tabUrl(
            \Subalcatel\Club\Admin\MembersScreen::SLUG,
            \Subalcatel\Club\Admin\AccountsScreen::TAB
        );

        foreach (get_users(['fields' => ['ID', 'user_email']]) as $candidate) {
            if (!user_can((int) $candidate->ID, 'sub_validate_account')) {
                continue;
            }

            Mailer::send(
                EmailTemplates::ACCOUNT_PENDING,
                (string) $candidate->user_email,
                [
                    'nom'   => $user->display_name,
                    'email' => $user->user_email,
                    'lien'  => $link,
                ],
                ['entity_type' => 'user', 'entity_id' => $userId]
            );
        }
    }

    private static function posted(string $field): string
    {
        return isset($_POST[$field]) ? sanitize_text_field(wp_unslash((string) $_POST[$field])) : '';
    }

    private static function old(string $field): string
    {
        return isset($_GET[$field]) ? sanitize_text_field(wp_unslash((string) $_GET[$field])) : '';
    }
}
