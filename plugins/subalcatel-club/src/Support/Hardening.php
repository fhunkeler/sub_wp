<?php

declare(strict_types=1);

namespace Subalcatel\Club\Support;

/**
 * Durcissement de WordPress, porté par le plugin.
 *
 * WordPress est livré ouvert : il annonce sa version, laisse énumérer ses
 * comptes, expose XML-RPC. Rien de tout cela n'est une faille en soi, mais
 * l'ensemble dresse la carte qu'un attaquant consulte avant de frapper — et le
 * site précédent a été frappé.
 *
 * Ces réglages vivent dans le plugin plutôt que dans la configuration serveur
 * pour une raison concrète : ils suivent le site. Un club géré par des
 * bénévoles changera d'hébergeur sans refaire un audit ; ce qui est dans le
 * code reste, ce qui est dans un `.conf` oublié se perd.
 *
 * Chaque mesure est **désactivable par un filtre** : si l'une gêne un usage
 * légitime, on la coupe sans toucher au code.
 */
final class Hardening
{
    public static function register(): void
    {
        if (!(bool) apply_filters('subalcatel_hardening_enabled', true)) {
            return;
        }

        self::hideVersion();
        self::blockUserEnumeration();
        self::disableXmlRpc();
        self::securityHeaders();
        self::disableFileEditor();
        self::warnOnWeakKeyStorage();

        add_action('admin_init', [self::class, 'protectUploads']);
    }

    /**
     * Interdit l'exécution de code dans le dossier des médias.
     *
     * C'est la leçon directe du piratage : l'attaquant avait déposé 36 fichiers
     * PHP dans le `images/` du Joomla, où le serveur acceptait de les exécuter.
     * `wp-content/uploads/` est l'équivalent WordPress, et c'est le seul dossier
     * du site où un visiteur peut faire arriver un fichier de son choix.
     *
     * La règle diffère de celle du coffre à documents : les médias doivent
     * rester **lisibles** — ce sont les photos du site — mais ne jamais être
     * **exécutés**. On interdit donc l'exécution, pas l'accès.
     */
    public static function protectUploads(): void
    {
        $uploads = wp_get_upload_dir();
        $base    = $uploads['basedir'] ?? '';

        if ($base === '' || !is_dir($base) || !is_writable($base)) {
            return;
        }

        $files = [
            '.htaccess' => "# Aucun code ne s'exécute ici : les médias se servent, ils ne tournent pas.\n"
                . "php_flag engine off\n"
                . "<FilesMatch \"\\.(?i:php|phtml|phar|php[0-9]|pl|py|cgi|asp|aspx|sh)$\">\n"
                . "Require all denied\n"
                . "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
                . "</FilesMatch>\n",
            // Lu par PHP-FPM quel que soit le serveur web : c'est la protection
            // qui survit à un passage d'Apache à nginx, où le .htaccess devient
            // lettre morte et où la faille se rouvrirait sans bruit.
            '.user.ini' => "engine = Off\n",
        ];

        foreach ($files as $name => $contents) {
            $path = $base . '/' . $name;

            if (!file_exists($path)) {
                file_put_contents($path, $contents);
            }
        }
    }

    /**
     * Alerter partout dans l'administration si la clé des certificats médicaux
     * dort en base.
     *
     * L'écran Documents portait déjà cet avertissement, mais un bureau qui n'y
     * va jamais ne le voit jamais. Pour une donnée de santé, la mise en garde
     * doit suivre l'administrateur, pas l'attendre. On la borne aux personnes
     * qui peuvent agir, pour ne pas alarmer tout le club.
     */
    private static function warnOnWeakKeyStorage(): void
    {
        add_action('admin_notices', static function (): void {
            if (!current_user_can('manage_options')
                || \Subalcatel\Club\Documents\DocumentStorage::keyIsInConfig()) {
                return;
            }

            printf(
                '<div class="notice notice-warning"><p><strong>Sécurité — certificats médicaux.</strong> '
                . 'La clé de chiffrement est enregistrée en base de données : une sauvegarde SQL '
                . 'dérobée suffirait à déchiffrer les certificats. Définissez '
                . '<code>SUBALCATEL_DOC_KEY</code> dans <code>wp-config.php</code> avant la mise en '
                . 'ligne.</p></div>'
            );
        });
    }

    /**
     * Cesser d'annoncer la version de WordPress.
     *
     * La version en clair permet à un scanner de ne tenter que les exploits qui
     * marchent sur cette version précise. La cacher ne corrige aucune faille —
     * elle retire la pancarte qui indique lesquelles chercher.
     */
    private static function hideVersion(): void
    {
        remove_action('wp_head', 'wp_generator');
        add_filter('the_generator', '__return_empty_string');

        // La version voyage aussi en query string des CSS et JS (?ver=7.0.2).
        // On ne la retire que des ressources du cœur : la retirer partout
        // casserait l'invalidation de cache des thèmes et plugins.
        $strip = static function (string $src): string {
            if (str_contains($src, 'ver=') && (str_contains($src, '/wp-includes/') || str_contains($src, '/wp-admin/'))) {
                return remove_query_arg('ver', $src);
            }

            return $src;
        };

        add_filter('style_loader_src', $strip, 9);
        add_filter('script_loader_src', $strip, 9);
    }

    /**
     * Fermer les deux voies d'énumération des comptes.
     *
     * `?author=1` et l'API REST `/wp/v2/users` révèlent tous deux les
     * identifiants de connexion — la moitié du travail d'une attaque par force
     * brute. On ne casse pas l'API : on retire seulement la liste publique des
     * comptes, que rien, sur un site de club, ne justifie d'exposer.
     */
    private static function blockUserEnumeration(): void
    {
        // `?author=N` doit être coupé **avant** que WordPress ne résolve l'ID
        // en login : sa redirection canonique vers /author/{login}/ divulgue le
        // login dans l'en-tête Location, même si la page finale est masquée.
        // `parse_request` s'exécute avant cette résolution.
        add_action('parse_request', static function (\WP $wp): void {
            if (!is_admin() && isset($_GET['author']) && is_numeric($_GET['author'])) {
                wp_safe_redirect(home_url('/'), 301);
                exit;
            }
        });

        // Une archive d'auteur atteinte par son slug (/author/xxx/) est aussi
        // fermée : elle republie le login et n'a aucun usage sur ce site.
        add_action('template_redirect', static function (): void {
            if (is_author()) {
                wp_safe_redirect(home_url('/'), 301);
                exit;
            }
        });

        // API REST : la liste des utilisateurs n'est renvoyée qu'à qui peut
        // déjà lister les comptes dans l'administration.
        add_filter('rest_endpoints', static function (array $endpoints): array {
            if (!current_user_can('list_users')) {
                unset($endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)']);
            }

            return $endpoints;
        });

        // Le flux oEmbed republie l'auteur : on le retire aussi.
        add_filter('oembed_response_data', static function (array $data): array {
            unset($data['author_name'], $data['author_url']);

            return $data;
        });

        // Les flux RSS/Atom exposent le nom d'auteur de chaque article
        // (`<dc:creator>`). Quand ce nom vaut le login — le cas de « admin » —
        // c'est une énumération de compte offerte. Un flux de club n'a pas
        // besoin de nommer l'auteur : on le neutralise.
        add_filter('the_author', static fn (string $author): string => is_feed() ? '' : $author);
        add_filter('comment_author_rss', '__return_empty_string');

        // Depuis WordPress 5.5, un sitemap XML liste les auteurs à
        // /wp-sitemap-users-1.xml, chacun avec son URL /author/{login}/ : une
        // énumération de comptes clés en main. On retire ce fournisseur ; les
        // sitemaps de pages et d'articles restent, utiles au référencement.
        add_filter('wp_sitemaps_add_provider', static function (mixed $provider, string $name): mixed {
            return $name === 'users' ? false : $provider;
        }, 10, 2);
    }

    /**
     * Couper XML-RPC.
     *
     * Il sert à publier depuis une application tierce et aux pingbacks — deux
     * usages qu'un club n'a pas. En face, `system.multicall` permet de tester
     * des centaines de mots de passe en une requête : c'est l'amplificateur de
     * force brute préféré des botnets. Aucun usage à perdre, un vecteur à
     * fermer.
     */
    private static function disableXmlRpc(): void
    {
        // Bloquer l'accès au **fichier**, pas seulement vider les méthodes.
        // `xmlrpc_enabled` et `xmlrpc_methods` ne retirent que les méthodes
        // applicatives (`wp.*`) : le fichier répond encore, expose
        // `system.multicall` et se signale « activé » à tout scanner. On coupe
        // la requête à la racine.
        //
        // `XMLRPC_REQUEST` est défini par xmlrpc.php **avant** le chargement de
        // WordPress : quand ce code s'exécute (`plugins_loaded`), il est déjà là.
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            status_header(403);
            exit('XML-RPC est désactivé sur ce site.');
        }

        add_filter('xmlrpc_enabled', '__return_false');
        add_filter('xmlrpc_methods', static fn (): array => []);

        // L'en-tête Pingback annonce xmlrpc.php dans chaque réponse : on le
        // retire pour ne pas pointer vers une porte désormais close.
        add_filter('wp_headers', static function (array $headers): array {
            unset($headers['X-Pingback']);

            return $headers;
        });

        remove_action('wp_head', 'rsd_link'); // supprime le lien RSD (→ xmlrpc)
    }

    /**
     * En-têtes de sécurité HTTP.
     *
     * Ils ne referment pas une faille, ils réduisent ce qu'une faille permet :
     * un XSS qui ne peut pas être encadré dans une iframe, un navigateur qui ne
     * devine pas le type d'un fichier servi. En production, l'hébergeur peut
     * les poser au niveau serveur ; les mettre ici garantit qu'ils existent
     * même s'il ne le fait pas.
     *
     * `Content-Security-Policy` n'y figure pas : une CSP stricte casse
     * l'éditeur de blocs, et une CSP laxiste ne protège de rien. Elle demande
     * un réglage propre au thème, à faire une fois le contenu figé.
     */
    private static function securityHeaders(): void
    {
        add_action('send_headers', static function (): void {
            if (is_admin()) {
                return;
            }

            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        });
    }

    /**
     * Interdire l'éditeur de fichiers de l'administration.
     *
     * Il permet de modifier le code des thèmes et plugins depuis le navigateur.
     * Un compte administrateur compromis y trouve un moyen direct d'installer
     * une porte dérobée, sans jamais toucher au FTP. Le club n'édite pas son
     * code depuis WordPress : on ferme la porte.
     */
    private static function disableFileEditor(): void
    {
        if (!defined('DISALLOW_FILE_EDIT')) {
            define('DISALLOW_FILE_EDIT', true);
        }
    }
}
