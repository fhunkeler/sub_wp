<?php

declare(strict_types=1);

namespace Subalcatel\Club\Content;

use Subalcatel\Club\Frontend\Pages;

/**
 * Visibilité des articles et des pages : public, connexion requise, ou
 * réservé aux adhérents à jour.
 *
 * WordPress propose bien un statut « privé », mais il est réservé aux éditeurs
 * et administrateurs — un adhérent ordinaire n'y accède jamais. Il ne répond
 * donc pas au besoin, qui est « visible par les membres du club ». D'où cette
 * couche : une méta, et trois endroits où elle est appliquée.
 *
 * Un contenu réservé est retiré des listes et des flux, refusé en accès direct,
 * et marqué `noindex`. Les trois comptent : bloquer la page tout en laissant le
 * titre dans la page d'accueil et dans Google ne protège rien.
 */
final class Visibility
{
    public const META = '_sub_visibility';

    /**
     * Paramètre de requête neutralisant le filtre.
     *
     * Réservé aux recherches internes du plugin. Il n'est jamais lu depuis
     * l'URL : `WP_Query::get()` ne renvoie que ce que le code a posé.
     */
    public const BYPASS = 'sub_bypass_visibility';

    public const PUBLIC_ACCESS = 'public';

    /**
     * Connexion requise, adhésion non.
     *
     * Indispensable pour l'espace membre : quelqu'un dont le dossier est en
     * cours d'instruction n'a pas encore d'adhésion valide, et c'est justement
     * là qu'il doit pouvoir suivre son dossier et déposer ses pièces.
     */
    public const CONNECTED = 'connected';

    public const MEMBERS_ONLY = 'members';

    /**
     * @return array<string, string>
     */
    public static function levels(): array
    {
        return [
            self::PUBLIC_ACCESS => 'Public',
            self::CONNECTED     => 'Connexion requise',
            self::MEMBERS_ONLY  => 'Réservé aux adhérents à jour',
        ];
    }

    /**
     * Types de contenu concernés.
     *
     * @var list<string>
     */
    private const POST_TYPES = ['post', 'page'];

    public static function register(): void
    {
        add_action('add_meta_boxes', [self::class, 'metaBox']);
        add_action('save_post', [self::class, 'save'], 10, 2);

        add_action('pre_get_posts', [self::class, 'filterQueries']);
        add_action('template_redirect', [self::class, 'guardSingle']);
        add_filter('wp_robots', [self::class, 'robots']);

        self::disableComments();
    }

    public static function metaBox(): void
    {
        foreach (self::POST_TYPES as $type) {
            add_meta_box(
                'sub-visibility',
                'Visibilité club',
                [self::class, 'renderBox'],
                $type,
                'side',
                'default'
            );
        }
    }

    public static function renderBox(\WP_Post $post): void
    {
        wp_nonce_field('sub_visibility_' . $post->ID, 'sub_visibility_nonce');

        $current = self::of($post->ID);
        ?>
        <p>
            <label>
                <input type="radio" name="sub_visibility" value="<?php echo esc_attr(self::PUBLIC_ACCESS); ?>"
                    <?php checked($current, self::PUBLIC_ACCESS); ?>>
                Public
            </label><br>
            <label>
                <input type="radio" name="sub_visibility" value="<?php echo esc_attr(self::CONNECTED); ?>"
                    <?php checked($current, self::CONNECTED); ?>>
                Connexion requise
            </label><br>
            <label>
                <input type="radio" name="sub_visibility" value="<?php echo esc_attr(self::MEMBERS_ONLY); ?>"
                    <?php checked($current, self::MEMBERS_ONLY); ?>>
                Réservé aux adhérents à jour
            </label>
        </p>
        <p class="description">
            Un contenu réservé n’apparaît ni dans les listes, ni dans les flux, ni dans
            les moteurs de recherche. Il reste accessible aux adhérents à jour de cotisation.
        </p>
        <?php
    }

    public static function save(int $postId, \WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!in_array($post->post_type, self::POST_TYPES, true)) {
            return;
        }

        $nonce = isset($_POST['sub_visibility_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['sub_visibility_nonce']))
            : '';

        if (!wp_verify_nonce($nonce, 'sub_visibility_' . $postId)) {
            return;
        }

        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        $value = isset($_POST['sub_visibility'])
            ? sanitize_key(wp_unslash($_POST['sub_visibility']))
            : self::PUBLIC_ACCESS;

        update_post_meta(
            $postId,
            self::META,
            array_key_exists($value, self::levels()) ? $value : self::PUBLIC_ACCESS
        );
    }

    public static function of(int $postId): string
    {
        $stored = (string) get_post_meta($postId, self::META, true);

        return array_key_exists($stored, self::levels()) ? $stored : self::PUBLIC_ACCESS;
    }

    /**
     * Cette personne peut-elle lire ce contenu ?
     */
    public static function mayRead(int $postId, ?int $userId = null): bool
    {
        $level = self::of($postId);

        if ($level === self::PUBLIC_ACCESS) {
            return true;
        }

        $userId ??= get_current_user_id();

        if ($userId === 0) {
            return false;
        }

        // Être connecté suffit pour l'espace membre : c'est là qu'on suit un
        // dossier en cours d'instruction, donc avant d'avoir une adhésion.
        if ($level === self::CONNECTED) {
            return true;
        }

        if (user_can($userId, 'sub_manage_content') || user_can($userId, 'edit_post', $postId)) {
            return true;
        }

        return ClubDocuments::isActiveMember($userId);
    }

    /**
     * Retire les contenus réservés des listes et des flux.
     *
     * Un adhérent voit tout, donc la requête n'est pas modifiée pour lui : on
     * évite une jointure sur les métas à chaque page pour les personnes qui y
     * ont droit de toute façon.
     */
    public static function filterQueries(\WP_Query $query): void
    {
        // Une recherche interne — retrouver une page par sa clé, construire
        // l'arborescence — n'est pas un affichage public. Sans cette sortie,
        // le code du plugin ne voit plus ses propres pages dès qu'il tourne
        // hors administration : en ligne de commande, en tâche planifiée, ou
        // dans l'API REST. Le constructeur de site les recréait en double.
        if ($query->get(self::BYPASS) === true) {
            return;
        }

        if (is_admin() || self::currentUserIsMember()) {
            return;
        }

        // Une requête singulière est traitée par `guardSingle`, qui sait
        // expliquer le refus. L'exclure ici donnerait un 404 muet.
        if ($query->is_singular()) {
            return;
        }

        $existing = $query->get('meta_query') ?: [];

        $query->set('meta_query', array_merge($existing, [[
            'relation' => 'OR',
            ['key' => self::META, 'compare' => 'NOT EXISTS'],
            ['key' => self::META, 'value' => [self::MEMBERS_ONLY, self::CONNECTED], 'compare' => 'NOT IN'],
        ]]));
    }

    /**
     * Refuse l'accès direct, en disant pourquoi.
     */
    public static function guardSingle(): void
    {
        if (!is_singular(self::POST_TYPES)) {
            return;
        }

        $postId = get_queried_object_id();

        if ($postId === 0 || self::mayRead($postId)) {
            return;
        }

        // Visiteur non connecté : on l'envoie se connecter et on le ramène ici
        // ensuite. Lui opposer une page d'erreur alors qu'il lui suffit
        // d'ouvrir sa session serait une impasse.
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(get_permalink($postId) ?: home_url('/')));
            exit;
        }

        wp_die(
            '<h1>' . esc_html(get_the_title($postId)) . '</h1>'
            . '<p>Ce contenu est réservé aux adhérents à jour de cotisation.</p>'
            . sprintf('<p><a href="%s">Voir mon adhésion</a></p>', esc_url(Pages::url(Pages::MEMBERSHIP))),
            'Contenu réservé',
            ['response' => 403, 'back_link' => true]
        );
    }

    /**
     * @param array<string, mixed> $robots
     * @return array<string, mixed>
     */
    public static function robots(array $robots): array
    {
        if (is_singular(self::POST_TYPES) && self::of(get_queried_object_id()) !== self::PUBLIC_ACCESS) {
            $robots['noindex']  = true;
            $robots['nofollow'] = true;
        }

        return $robots;
    }

    private static function currentUserIsMember(): bool
    {
        $userId = get_current_user_id();

        return $userId !== 0
            && (user_can($userId, 'sub_manage_content') || ClubDocuments::isActiveMember($userId));
    }

    /**
     * Blog sans commentaires, demandé par `projet.md`.
     *
     * Fermer les commentaires dans les réglages ne suffit pas : ceux déjà
     * ouverts sur des contenus existants le restent. On coupe donc à la racine.
     */
    private static function disableComments(): void
    {
        add_action('init', static function (): void {
            foreach (self::POST_TYPES as $type) {
                remove_post_type_support($type, 'comments');
                remove_post_type_support($type, 'trackbacks');
            }
        }, 20);

        add_filter('comments_open', '__return_false', 20);
        add_filter('pings_open', '__return_false', 20);
        add_filter('comments_array', '__return_empty_array', 20);

        add_action('admin_menu', static function (): void {
            remove_menu_page('edit-comments.php');
        });
    }
}
