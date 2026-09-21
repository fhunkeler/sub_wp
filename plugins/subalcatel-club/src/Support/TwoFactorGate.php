<?php

declare(strict_types=1);

namespace Subalcatel\Club\Support;

use WP_User;

/**
 * Exige la double authentification des comptes qui touchent aux données
 * sensibles.
 *
 * Le second facteur lui-même n'est pas réécrit ici : il vient de l'extension
 * `two-factor` de WordPress.org (TOTP et codes de secours). Réimplémenter un
 * générateur de codes à usage unique serait, comme pour la vérification de mot
 * de passe, la plus mauvaise idée du projet.
 *
 * Ce que l'extension ne fait pas, en revanche, c'est **l'imposer** : elle
 * attend que chacun aille l'activer dans son profil. Un administrateur qui n'y
 * va jamais n'en aura jamais. La règle du club vit donc ici, dans du code
 * versionné et testé, plutôt que dans une case à cocher d'une extension tierce.
 *
 * ## Le périmètre n'est pas « administrateur »
 *
 * Protéger les deux comptes `administrator` et laisser les treize `sub_office`
 * derrière un simple mot de passe, c'est verrouiller la porte et laisser la
 * fenêtre : ce sont eux qui consultent les certificats médicaux et exportent
 * l'annuaire. Le périmètre est donc défini par les **capacités**, pas par le
 * rôle — un compte qui reçoit `sub_view_medical_certificate` à la main entre
 * dans la règle sans que personne n'ait à y penser.
 *
 * ## Ce qui n'enferme personne dehors
 *
 * Trois garde-fous, dans le même esprit que {@see LoginUrl} :
 *
 *   - extension absente ou désactivée : la règle est **inerte**. Sans elle, il
 *     n'existe aucun moyen d'activer un second facteur ; l'appliquer quand même
 *     fermerait l'administration à tout le monde, sans recours ;
 *   - l'écran de profil reste toujours accessible — c'est là qu'on active la
 *     2FA, une barrière qui le fermerait aussi serait sans issue ;
 *   - le réglage se coupe depuis l'onglet « Sécurité », et en dernier ressort
 *     en ligne de commande :
 *
 *         wp option patch update subalcatel_security two_factor_required 0
 */
final class TwoFactorGate
{
    /**
     * Capacités qui donnent accès aux données personnelles des adhérents, ou au
     * site lui-même.
     *
     * `manage_options` couvre les administrateurs ; les trois autres couvrent le
     * bureau. Les exports de paiements et les demandes RGPD ne sont pas listés
     * séparément : aucun compte ne les détient sans détenir déjà l'une d'elles.
     */
    private const SENSIBLES = [
        'manage_options',
        'sub_manage_accounts',
        'sub_view_medical_certificate',
        'sub_export_members',
    ];

    /**
     * Écrans laissés ouverts malgré la barrière.
     *
     * `profile.php` parce que c'est là qu'on active la 2FA ; les deux points
     * d'entrée AJAX et POST parce que l'assistant de l'extension s'en sert pour
     * valider le premier code avant d'enregistrer le fournisseur.
     */
    private const TOLERES = ['profile.php', 'admin-ajax.php', 'admin-post.php'];

    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'consigne']);

        if (!self::exigenceActive()) {
            return;
        }

        add_action('admin_init', [self::class, 'barriere']);

        // Un mot de passe d'application contourne le second facteur par
        // construction : c'est un identifiant unique qui ouvre l'API sans passer
        // par l'écran de connexion. Le laisser disponible viderait la règle de
        // son sens pour qui sait s'en servir.
        add_filter('wp_is_application_passwords_available_for_user', [self::class, 'motsDePasseApplication'], 10, 2);
    }

    /**
     * Renvoie sur le profil tant que le second facteur n'est pas en place.
     *
     * Une redirection plutôt qu'un `wp_die` : la personne doit comprendre ce
     * qu'on attend d'elle et pouvoir le faire dans le même mouvement, pas se
     * heurter à un mur.
     */
    public static function barriere(): void
    {
        if (wp_doing_ajax() || wp_doing_cron() || !self::manque(wp_get_current_user())) {
            return;
        }

        if (in_array($GLOBALS['pagenow'] ?? '', self::TOLERES, true)) {
            return;
        }

        wp_safe_redirect(admin_url('profile.php#two-factor-options'));
        exit;
    }

    /**
     * Le message affiché en haut de l'administration.
     *
     * Deux cas distincts, à ne pas confondre : le compte à qui il manque un
     * second facteur, et le site à qui il manque l'extension. Le second
     * n'intéresse que les administrateurs — c'est à eux de l'installer.
     */
    public static function consigne(): void
    {
        $utilisateur = wp_get_current_user();

        if (self::manque($utilisateur)) {
            echo '<div class="notice notice-error"><p><strong>Double authentification requise.</strong> '
                . 'Votre compte accède aux données personnelles des adhérents — certificats '
                . 'médicaux, annuaire, exports. Activez « Application d’authentification » '
                . 'ci-dessous, puis <strong>générez vos codes de secours</strong> et rangez-les '
                . 'ailleurs que dans votre boîte mail. Le reste de l’administration reste fermé '
                . 'jusque-là.</p></div>';

            return;
        }

        if (self::exigenceActive()
            && !self::extensionDisponible()
            && current_user_can('manage_options')
            && self::estConcerne($utilisateur)) {
            printf(
                '<div class="notice notice-warning"><p><strong>Sécurité — double authentification.</strong> '
                . 'Le club exige un second facteur pour les %d comptes qui accèdent aux données '
                . 'des adhérents, mais l’extension <code>two-factor</code> n’est pas active : la '
                . 'règle ne s’applique pas. <a href="%s">Installer l’extension</a>.</p></div>',
                count(self::comptesConcernes()),
                esc_url(admin_url('plugin-install.php?s=two-factor&tab=search&type=term'))
            );
        }
    }

    /**
     * Refuse les mots de passe d'application aux comptes soumis à la règle.
     *
     * Le refus vaut **même quand le second facteur est en place** : ces mots de
     * passe s'utilisent sans lui. Rien dans le site n'en dépend — XML-RPC est
     * coupé par {@see Hardening}, et l'API REST du club exige une session.
     */
    public static function motsDePasseApplication(bool $disponible, WP_User $utilisateur): bool
    {
        return self::estConcerne($utilisateur) ? false : $disponible;
    }

    /**
     * Ce compte devrait-il avoir un second facteur, et ne l'a-t-il pas ?
     */
    public static function manque(?WP_User $utilisateur): bool
    {
        if (!$utilisateur instanceof WP_User || !$utilisateur->exists()) {
            return false;
        }

        if (!self::exigenceActive() || !self::extensionDisponible()) {
            return false;
        }

        return self::estConcerne($utilisateur) && !self::aUnSecondFacteur($utilisateur->ID);
    }

    /**
     * Le compte détient-il une capacité sensible ?
     */
    public static function estConcerne(WP_User $utilisateur): bool
    {
        if (!$utilisateur->exists()) {
            return false;
        }

        foreach (self::SENSIBLES as $capacite) {
            if (user_can($utilisateur, $capacite)) {
                return true;
            }
        }

        return false;
    }

    /**
     * L'extension est-elle là pour porter le second facteur ?
     */
    public static function extensionDisponible(): bool
    {
        return (bool) apply_filters(
            'subalcatel_two_factor_available',
            class_exists(\Two_Factor_Core::class)
        );
    }

    /**
     * Ce compte a-t-il activé un second facteur ?
     *
     * Passe par `Two_Factor_Core::is_user_using_two_factor()`, l'API publique de
     * l'extension. Le filtre existe pour les tests, qui doivent pouvoir jouer la
     * règle sans installer l'extension.
     */
    public static function aUnSecondFacteur(int $userId): bool
    {
        $actif = self::extensionDisponible()
            && class_exists(\Two_Factor_Core::class)
            && \Two_Factor_Core::is_user_using_two_factor($userId);

        return (bool) apply_filters('subalcatel_two_factor_user_enabled', $actif, $userId);
    }

    /**
     * La règle est-elle armée ? Réglage du bureau, doublé d'un filtre pour le
     * code et les tests.
     */
    public static function exigenceActive(): bool
    {
        return (bool) apply_filters(
            'subalcatel_two_factor_required',
            SecuritySettings::twoFactorRequired()
        );
    }

    /**
     * Les comptes soumis à la règle, et où ils en sont.
     *
     * Sert l'onglet « Sécurité » : sans cette liste, le bureau ne peut pas
     * savoir qui a activé son second facteur sans ouvrir treize fiches.
     *
     * @return list<array{user: WP_User, protege: bool}>
     */
    public static function comptesConcernes(): array
    {
        $comptes = [];

        foreach (get_users(['fields' => 'ID']) as $id) {
            $utilisateur = get_user_by('id', (int) $id);

            if (!$utilisateur instanceof WP_User || !self::estConcerne($utilisateur)) {
                continue;
            }

            $comptes[] = [
                'user'    => $utilisateur,
                'protege' => self::aUnSecondFacteur($utilisateur->ID),
            ];
        }

        // Les comptes exposés d'abord : c'est la liste sur laquelle on agit.
        usort($comptes, static fn (array $a, array $b): int => [$a['protege'], strtolower($a['user']->display_name)]
            <=> [$b['protege'], strtolower($b['user']->display_name)]);

        return $comptes;
    }
}
