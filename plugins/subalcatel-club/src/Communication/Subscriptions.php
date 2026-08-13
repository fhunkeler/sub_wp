<?php

declare(strict_types=1);

namespace Subalcatel\Club\Communication;

use Subalcatel\Club\Support\Audit;

/**
 * Consentement à la lettre d'information.
 *
 * Le point de fond : **être adhérent ne vaut pas consentement**. Les listes de
 * diffusion disent qui appartient à quel groupe ; l'abonnement dit à qui le
 * club a le droit d'écrire. Les deux sont indépendants, et c'est le second qui
 * fait foi au moment d'envoyer.
 *
 * L'absence de réponse vaut refus : un compte créé n'est pas abonné tant que
 * personne n'a coché la case. C'est plus lent à constituer qu'une liste
 * remplie d'office, et c'est la seule position tenable.
 */
final class Subscriptions
{
    public const META_STATUS = 'sub_newsletter_optin';
    public const META_DATE   = 'sub_newsletter_optin_on';
    public const META_SOURCE = 'sub_newsletter_source';
    public const META_TOKEN  = 'sub_newsletter_token';

    /**
     * Refus des annonces de sortie.
     *
     * Sens inverse de l'abonnement : ici, l'absence de réponse vaut acceptation.
     * Une annonce de sortie n'est pas une lettre d'information — elle ne part
     * qu'à ceux que la sortie concerne, à l'initiative d'un organisateur, et
     * c'est l'objet même de l'adhésion. Le club a donc un intérêt légitime à
     * l'envoyer, ce qu'il n'a pas pour de l'information générale. Reste qu'un
     * membre doit pouvoir dire stop sans se couper de ses convocations : c'est
     * ce que cette méta enregistre.
     */
    public const META_ANNOUNCEMENTS = 'sub_event_announcements';

    public const ACTION_UNSUBSCRIBE = 'sub_newsletter_unsubscribe';
    public const ACTION_UPDATE      = 'sub_newsletter_update';

    public static function register(): void
    {
        add_action('admin_post_' . self::ACTION_UNSUBSCRIBE, [self::class, 'handleUnsubscribe']);

        // Le désabonnement doit fonctionner sans connexion : exiger un compte
        // pour se désinscrire, c'est ne pas offrir de désinscription.
        add_action('admin_post_nopriv_' . self::ACTION_UNSUBSCRIBE, [self::class, 'handleUnsubscribe']);

        add_action('admin_post_' . self::ACTION_UPDATE, [self::class, 'handleUpdate']);
    }

    /**
     * Bloc affiché dans le profil du membre.
     *
     * Des cases, leur état actuel, et la distinction entre les canaux — sans
     * quoi un membre croit se couper des convocations en se désabonnant.
     */
    public static function renderPanel(int $userId): string
    {
        $state         = self::stateOf($userId);
        $subscribed    = $state['status'] === 'yes';
        $announcements = self::wantsEventAnnouncements($userId);

        ob_start();
        ?>
        <section class="sub-panel-block">
            <h2>Messages du club</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_UPDATE); ?>">
                <?php wp_nonce_field(self::ACTION_UPDATE . '_' . $userId); ?>

                <label class="sub-check">
                    <input type="checkbox" name="subscribed" value="1" <?php checked($subscribed); ?>>
                    Je souhaite recevoir la lettre d’information du club
                </label>

                <label class="sub-check">
                    <input type="checkbox" name="announcements" value="1" <?php checked($announcements); ?>>
                    Je souhaite être prévenu des sorties ouvertes à mon niveau
                </label>

                <p class="sub-help">
                    Ces choix ne concernent que les informations générales et les annonces de
                    sortie. Les messages liés à votre adhésion — confirmations, rappels
                    d’échéance, convocations, messages de l’organisateur d’une sortie où vous
                    êtes inscrit — vous parviennent dans tous les cas : ils font partie de la
                    vie du club.
                </p>

                <?php if ($state['date'] !== '') : ?>
                    <p class="sub-help">
                        <?php printf(
                            esc_html($subscribed ? 'Consentement donné le %s.' : 'Désabonnement enregistré le %s.'),
                            esc_html(mysql2date('j F Y', $state['date']))
                        ); ?>
                    </p>
                <?php endif; ?>

                <p><button type="submit" class="sub-button">Enregistrer ce choix</button></p>
            </form>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    public static function handleUpdate(): void
    {
        $userId = get_current_user_id();

        if ($userId === 0) {
            wp_die('Connectez-vous pour modifier ce choix.');
        }

        check_admin_referer(self::ACTION_UPDATE . '_' . $userId);

        if (isset($_POST['subscribed'])) {
            self::subscribe($userId);
            $message = 'Vous recevrez désormais la lettre d’information.';
        } else {
            // Un désabonnement doit s'enregistrer même si aucun consentement
            // n'avait été donné : la trace du refus vaut aussi.
            update_user_meta($userId, self::META_STATUS, 'no');
            update_user_meta($userId, self::META_DATE, current_time('Y-m-d'));
            $message = 'Vous ne recevrez plus la lettre d’information.';
        }

        // Le choix est enregistré dans les deux sens, y compris quand il
        // confirme le défaut : l'export RGPD doit pouvoir répondre « choix
        // exprimé le … », et non déduire un silence.
        $wantsAnnouncements = isset($_POST['announcements']);
        update_user_meta($userId, self::META_ANNOUNCEMENTS, $wantsAnnouncements ? 'yes' : 'no');

        $message .= $wantsAnnouncements
            ? ' Les annonces de sortie vous parviendront.'
            : ' Vous ne recevrez plus les annonces de sortie.';

        wp_safe_redirect(add_query_arg(
            'sub_done',
            rawurlencode($message),
            wp_get_referer() ?: home_url('/')
        ));
        exit;
    }

    public static function isSubscribed(int $userId): bool
    {
        return get_user_meta($userId, self::META_STATUS, true) === 'yes';
    }

    /**
     * Ce membre accepte-t-il les annonces de sortie ?
     *
     * Seul le refus est enregistré : un compte qui n'a jamais ouvert son profil
     * reçoit les annonces. C'est l'inverse de la lettre d'information, et la
     * raison en est le contenu — une sortie ouverte à son niveau est ce que le
     * membre est venu chercher en adhérant.
     */
    public static function wantsEventAnnouncements(int $userId): bool
    {
        return get_user_meta($userId, self::META_ANNOUNCEMENTS, true) !== 'no';
    }

    /**
     * Enregistre un consentement.
     *
     * `$consentedOn` sert à la reprise depuis AcyMailing : la date d'origine
     * est conservée, car c'est elle qui prouve le consentement. La réécrire au
     * jour de l'import effacerait la seule chose qui rendait la liste légitime.
     */
    public static function subscribe(int $userId, string $source = 'profil', ?string $consentedOn = null): void
    {
        $wasSubscribed = self::isSubscribed($userId);

        update_user_meta($userId, self::META_STATUS, 'yes');
        update_user_meta($userId, self::META_SOURCE, $source);
        update_user_meta($userId, self::META_DATE, $consentedOn ?? current_time('Y-m-d'));

        if (!$wasSubscribed) {
            Audit::log('newsletter.subscribed', 'user', $userId, ['source' => $source], $userId);
        }
    }

    public static function unsubscribe(int $userId, string $source = 'profil'): void
    {
        if (!self::isSubscribed($userId)) {
            return;
        }

        update_user_meta($userId, self::META_STATUS, 'no');
        update_user_meta($userId, self::META_DATE, current_time('Y-m-d'));

        Audit::log('newsletter.unsubscribed', 'user', $userId, ['source' => $source], $userId);
    }

    /**
     * @return array{status: string, date: string, source: string, announcements: string}
     */
    public static function stateOf(int $userId): array
    {
        return [
            'status'        => (string) get_user_meta($userId, self::META_STATUS, true) ?: 'unset',
            'date'          => (string) get_user_meta($userId, self::META_DATE, true),
            'source'        => (string) get_user_meta($userId, self::META_SOURCE, true),
            'announcements' => (string) get_user_meta($userId, self::META_ANNOUNCEMENTS, true) ?: 'unset',
        ];
    }

    /**
     * Lien de désabonnement en un clic, à placer dans chaque envoi.
     *
     * Le jeton est propre au compte et ne donne accès à rien d'autre : le
     * présenter ne connecte pas, il désabonne.
     */
    public static function unsubscribeUrl(int $userId): string
    {
        return add_query_arg([
            'action' => self::ACTION_UNSUBSCRIBE,
            'user'   => $userId,
            'token'  => self::token($userId),
        ], admin_url('admin-post.php'));
    }

    public static function token(int $userId): string
    {
        $token = (string) get_user_meta($userId, self::META_TOKEN, true);

        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            update_user_meta($userId, self::META_TOKEN, $token);
        }

        return $token;
    }

    public static function handleUnsubscribe(): void
    {
        $userId = isset($_GET['user']) ? absint($_GET['user']) : 0;
        $token  = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';

        $expected = (string) get_user_meta($userId, self::META_TOKEN, true);

        // `hash_equals` compare en temps constant : une comparaison ordinaire
        // laisse deviner le jeton octet par octet.
        if ($userId === 0 || $expected === '' || !hash_equals($expected, $token)) {
            wp_die('Ce lien de désabonnement n’est plus valable.', 'Lien invalide', ['response' => 403]);
        }

        self::unsubscribe($userId, 'lien');

        wp_die(
            '<h1>Désabonnement enregistré</h1>'
            . '<p>Vous ne recevrez plus la lettre d’information du club.</p>'
            . '<p>Vous continuerez à recevoir les messages liés à votre adhésion '
            . '— confirmations, rappels d’échéance, convocations : ils font partie '
            . 'de la vie du club et ne relèvent pas de la lettre d’information.</p>'
            . sprintf('<p><a href="%s">Retour au site</a></p>', esc_url(home_url('/'))),
            'Désabonnement',
            ['response' => 200]
        );
    }
}
