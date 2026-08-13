<?php

declare(strict_types=1);

namespace Subalcatel\Club\Identity;

use Subalcatel\Club\Support\Audit;
use WP_Session_Tokens;

/**
 * Changement de mot de passe, à l'initiative du membre.
 *
 * WordPress sait déjà le faire — depuis `wp-admin/profile.php`, un écran que
 * l'espace membre a précisément pour but de ne jamais montrer à un adhérent.
 * Restait donc à l'amener dans le site : un panneau au bas du profil, à côté
 * des abonnements et de la suppression de compte.
 *
 * Trois règles, et chacune répond à un scénario réel :
 *
 * 1. **Le mot de passe actuel est exigé.** Être connecté ne prouve pas être la
 *    bonne personne : un poste laissé ouvert au local du club suffit. Sans
 *    cette vérification, quiconque passe derrière s'approprie le compte en
 *    trois clics.
 * 2. **Les autres sessions sont fermées.** On change son mot de passe parce
 *    qu'on le croit connu d'un tiers ; laisser vivre la session de ce tiers
 *    viderait le geste de son sens.
 * 3. **On ne fixe jamais le mot de passe de quelqu'un d'autre** — ni ici, ni
 *    depuis l'écran du bureau, qui envoie un lien de réinitialisation. Un mot
 *    de passe connu de deux personnes n'authentifie plus personne.
 */
final class PasswordChange
{
    public const ACTION = 'sub_password_change';

    /**
     * Longueur minimale.
     *
     * Douze caractères plutôt que huit, sans exigence de casse ni de chiffre :
     * une longue phrase se retient et résiste mieux qu'un « P@ssw0rd! » que son
     * porteur finit par coller sur l'écran.
     */
    public const MIN_LENGTH = 12;

    /**
     * Champ portant le jeton.
     *
     * Nommé plutôt que le `_wpnonce` par défaut : la page « Mon profil » aligne
     * plusieurs formulaires, et autant d'`id="_wpnonce"` sur un même document.
     */
    private const NONCE_FIELD = 'sub_password_nonce';

    public static function register(): void
    {
        add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
    }

    /**
     * Le panneau affiché au bas du profil.
     *
     * Hors du formulaire de profil, comme les autres : deux formulaires
     * imbriqués ne sont pas du HTML valide.
     */
    public static function renderPanel(int $userId): string
    {
        ob_start();
        ?>
        <section class="sub-panel-block sub-password">
            <h2>Changer mon mot de passe</h2>
            <p class="sub-help">
                Le nouveau mot de passe doit faire au moins
                <?php echo esc_html((string) self::MIN_LENGTH); ?> caractères. Une phrase
                dont vous vous souvenez vaut mieux qu’une suite de symboles que vous
                devrez noter quelque part.
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                <?php wp_nonce_field(self::ACTION . '_' . $userId, self::NONCE_FIELD); ?>

                <div class="sub-grid">
                    <p class="sub-input">
                        <label for="sub_current_password">Mot de passe actuel</label>
                        <input type="password" id="sub_current_password" name="current_password"
                               autocomplete="current-password" required>
                    </p>
                    <p class="sub-input">
                        <label for="sub_new_password">Nouveau mot de passe</label>
                        <input type="password" id="sub_new_password" name="new_password"
                               autocomplete="new-password"
                               minlength="<?php echo esc_attr((string) self::MIN_LENGTH); ?>" required>
                    </p>
                    <p class="sub-input">
                        <label for="sub_new_password_confirm">Confirmation</label>
                        <input type="password" id="sub_new_password_confirm" name="new_password_confirm"
                               autocomplete="new-password"
                               minlength="<?php echo esc_attr((string) self::MIN_LENGTH); ?>" required>
                    </p>
                </div>

                <p>
                    <button type="submit" class="sub-button">Changer mon mot de passe</button>
                </p>
                <p class="sub-help">
                    Vos autres sessions — téléphone, autre navigateur — seront fermées.
                </p>
            </form>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    public static function handle(): void
    {
        $userId = get_current_user_id();

        if ($userId === 0) {
            wp_die('Connectez-vous pour changer votre mot de passe.', 403);
        }

        check_admin_referer(self::ACTION . '_' . $userId, self::NONCE_FIELD);

        $user    = wp_get_current_user();
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

        // Pas de `wp_unslash` ni d'assainissement : un mot de passe est une
        // suite d'octets, pas du texte à nettoyer. `sanitize_text_field` y
        // mangerait les espaces et les accents, et le membre se retrouverait
        // avec un mot de passe différent de celui qu'il a tapé.

        if (!wp_check_password($current, $user->user_pass, $userId)) {
            self::back('Mot de passe actuel incorrect.', true);
        }

        if ($new !== $confirm) {
            self::back('Les deux nouveaux mots de passe ne correspondent pas.', true);
        }

        if (strlen($new) < self::MIN_LENGTH) {
            self::back(sprintf(
                'Le nouveau mot de passe doit faire au moins %d caractères.',
                self::MIN_LENGTH
            ), true);
        }

        if ($new === $current) {
            self::back('Le nouveau mot de passe est identique à l’ancien.', true);
        }

        // `wp_update_user` réémet le cookie de la session courante : le membre
        // reste connecté là où il vient d'agir.
        wp_update_user(['ID' => $userId, 'user_pass' => $new]);

        WP_Session_Tokens::get_instance($userId)->destroy_others(
            wp_get_session_token()
        );

        // Le mot de passe lui-même n'est évidemment pas journalisé — seul le
        // fait qu'il a changé, avec la date et l'adresse IP posées par Audit.
        Audit::log('account.password_changed', 'user', $userId, [], $userId);

        self::back('Mot de passe changé. Vos autres sessions ont été fermées.');
    }

    private static function back(string $message, bool $isError = false): never
    {
        wp_safe_redirect(add_query_arg(
            [$isError ? 'sub_error' : 'sub_done' => rawurlencode($message)],
            wp_get_referer() ?: home_url('/')
        ));
        exit;
    }
}
