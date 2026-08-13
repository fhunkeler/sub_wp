<?php

declare(strict_types=1);

namespace Subalcatel\Club\Support;

/**
 * Déplace l'écran de connexion sur une adresse choisie par le club.
 *
 * `wp-login.php` est la première porte qu'un robot essaie : elle est au même
 * endroit sur tous les WordPress du monde. La déplacer ne referme aucune
 * faille — c'est une mesure de discrétion, pas de sécurité. Elle vaut pour ce
 * qu'elle supprime : le bruit de fond, et les tentatives à l'aveugle.
 *
 * **Ce n'est pas ce qui protège le site.** Contre le bourrinage, c'est
 * {@see LoginThrottle} qui agit ; contre un compte compromis, la double
 * authentification. Un attaquant qui vise ce club précisément retrouvera
 * l'adresse : WordPress la publie dans ses redirections, ses courriels de
 * réinitialisation et ses en-têtes. On ne se raconte donc pas d'histoire — on
 * enlève le tapis de bombes, pas le tireur d'élite.
 *
 * ## Réglage
 *
 *     wp option update subalcatel_login_slug 'porte-du-club'
 *     wp option delete subalcatel_login_slug        # retour à wp-login.php
 *
 * Aucune valeur par défaut : tant que l'option est vide, rien ne change. Un
 * durcissement qui s'active tout seul est un durcissement qui enferme dehors.
 *
 * ## En cas de porte claquée
 *
 * L'option se supprime en ligne de commande, sans passer par l'administration :
 *
 *     docker compose exec -T cli wp option delete subalcatel_login_slug
 */
final class LoginUrl
{
    private const OPTION = 'subalcatel_login_slug';

    public static function register(): void
    {
        if (self::slug() === '') {
            return;
        }

        // Sur `wp_loaded`, et pas plus tôt. Deux raisons, apprises à l'essai :
        //
        //   - à `plugins_loaded`, l'objet `WP` n'existe pas encore et
        //     `is_user_logged_in()` n'est pas encore défini — rendre une page
        //     à ce stade provoque une erreur fatale ;
        //   - `wp_loaded` s'exécute pendant le chargement du cœur, donc avant
        //     que `wp-login.php` ou un écran de `wp-admin/` n'ait rien affiché.
        //
        // C'est la seule fenêtre où l'on sait tout et où rien n'est encore dit.
        add_action('wp_loaded', [self::class, 'router'], 0);

        // Toutes les adresses que WordPress fabrique doivent suivre, sans quoi
        // le site renvoie ses visiteurs vers une page qui n'existe plus — à
        // commencer par les liens de réinitialisation de mot de passe, dont
        // dépendent les 86 courriels de la mise en service.
        add_filter('site_url', [self::class, 'filtrerSiteUrl'], 10, 4);
        add_filter('network_site_url', [self::class, 'filtrerSiteUrl'], 10, 3);
        add_filter('wp_redirect', [self::class, 'filtrerRedirection'], 10, 1);
    }

    /**
     * Adresse choisie, ou chaîne vide si la mesure est désactivée.
     *
     * `sanitize_title` et non un simple filtrage : l'adresse arrive d'une
     * option modifiable en ligne de commande, et se retrouve dans des URL.
     */
    public static function slug(): string
    {
        $brut = (string) apply_filters(
            'subalcatel_login_slug',
            (string) get_option(self::OPTION, '')
        );

        $propre = sanitize_title($brut);

        // Refus des adresses qui masqueraient une page réelle du site.
        return in_array($propre, ['', 'wp-admin', 'wp-login', 'admin', 'login'], true) ? '' : $propre;
    }

    /** Adresse complète de l'écran de connexion. */
    public static function url(string $requete = ''): string
    {
        $url = home_url('/' . self::slug() . '/');

        return $requete === '' ? $url : $url . '?' . ltrim($requete, '?');
    }

    /**
     * Décide du sort de la requête, avant que WordPress ne la résolve.
     */
    public static function router(): void
    {
        $chemin = self::cheminDemande();

        // 1. La nouvelle adresse sert l'écran de connexion natif.
        if ($chemin === self::slug()) {
            self::servir();
        }

        // 2. L'ancienne adresse n'existe plus. `wp-login.php` reste servi par
        //    Apache : c'est la seule mesure qui demande que PHP réponde avant
        //    que le fichier ne fasse son travail.
        if ($chemin === 'wp-login.php') {
            self::introuvable();
        }

        // 3. `wp-admin` pour un visiteur non connecté : WordPress y répond par
        //    une redirection vers l'écran de connexion, qui publierait la
        //    nouvelle adresse. On répond « rien ici » à la place.
        //
        //    `admin-post.php` et `admin-ajax.php` sont **exclus** : ils vivent
        //    sous `wp-admin/` mais servent les formulaires publics du site —
        //    l'inscription d'un nouvel adhérent passe par là. Les fermer
        //    casserait la création de compte sans le moindre message.
        if (str_starts_with($chemin, 'wp-admin')
            && !str_contains($chemin, 'admin-post.php')
            && !str_contains($chemin, 'admin-ajax.php')
            && !is_user_logged_in()
        ) {
            self::introuvable();
        }
    }

    /**
     * Sert l'écran de connexion natif sur la nouvelle adresse.
     *
     * Les `require_once` de `wp-login.php` empêchent le double chargement du
     * cœur : il redemande `wp-load.php`, qui a déjà été satisfait.
     *
     * Les globales déclarées ici ne sont pas décoratives : `wp-login.php` les
     * écrit en supposant la portée globale. Chargé depuis une méthode, il les
     * écrirait dans une portée locale, et l'écran perdrait ses messages
     * d'erreur — un mot de passe refusé sans le dire.
     */
    private static function servir(): void
    {
        global $pagenow, $error, $interim_login, $action, $user_login, $user, $redirect_to, $errors;

        $pagenow = 'wp-login.php';

        require_once ABSPATH . 'wp-login.php';
        exit;
    }

    /**
     * Réécrit les adresses fabriquées par `site_url()` en contexte de connexion.
     *
     * C'est ce filtre qui fait suivre `wp_login_url()`, `wp_logout_url()`,
     * `wp_lostpassword_url()` et les liens des courriels : tous passent par là.
     */
    public static function filtrerSiteUrl(string $url, string $chemin, ?string $schema = null, $blogId = null): string
    {
        return self::remplacer($url, $schema);
    }

    /** Réécrit les redirections que WordPress émet vers `wp-login.php`. */
    public static function filtrerRedirection(string $url): string
    {
        return self::remplacer($url, null);
    }

    private static function remplacer(string $url, ?string $schema): string
    {
        if (!str_contains($url, 'wp-login.php')) {
            return $url;
        }

        if ($schema !== null && !in_array($schema, ['login', 'login_post', 'rpc', null], true)) {
            return $url;
        }

        $requete = (string) parse_url($url, PHP_URL_QUERY);

        return self::url($requete);
    }

    /**
     * Chemin demandé, relatif à la racine du site, sans barre ni requête.
     */
    private static function cheminDemande(): string
    {
        $chemin = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $chemin = trim($chemin, '/');

        // Un site installé dans un sous-dossier : on retire le préfixe, sans
        // quoi la comparaison ne correspond jamais.
        $racine = trim((string) parse_url(home_url(), PHP_URL_PATH), '/');

        if ($racine !== '' && str_starts_with($chemin, $racine)) {
            $chemin = trim(substr($chemin, strlen($racine)), '/');
        }

        return $chemin;
    }

    /**
     * Répond « cette page n'existe pas », avec la page 404 du thème.
     *
     * On ne coupe pas la requête à la main : on la réécrit vers un chemin
     * impossible et on laisse WordPress produire son 404 habituel. La réponse
     * est alors indiscernable de celle d'une adresse quelconque — ce qui est
     * exactement le but, puisque le contraire signalerait qu'il y a là quelque
     * chose à trouver.
     */
    private static function introuvable(): void
    {
        // Réécrire l'adresse ne suffit pas : `wp-login.php` et les écrans de
        // `wp-admin/` poursuivent leur exécution après le chargement des
        // extensions, sans jamais consulter `REQUEST_URI`. Il faut donc rendre
        // la page nous-mêmes, puis sortir.
        //
        // Et il faut la rendre **explicitement**, sans passer par
        // `wp-blog-header.php` : celui-ci ne produit rien tant que
        // `WP_USE_THEMES` n'est pas défini, ce que fait `index.php` mais pas
        // `wp-admin/index.php`. À l'essai, `wp-admin/` répondait « 200, corps
        // vide » — pire qu'une 404, puisque c'est une réponse qu'aucune autre
        // adresse du site ne donne.
        global $wp_query;

        $wp_query->set_404();
        status_header(404);
        nocache_headers();

        $gabarit = get_404_template();
        $gabarit = apply_filters('template_include', $gabarit);

        if (is_string($gabarit) && $gabarit !== '' && file_exists($gabarit)) {
            include $gabarit;
        }

        exit;
    }
}
