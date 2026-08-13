<?php

declare(strict_types=1);

namespace Subalcatel\Club\Setup;

use const Subalcatel\Club\VERSION;

/**
 * Mises à jour du plugin et du thème depuis les releases GitHub du club.
 *
 * WordPress sait le faire seul depuis la 5.8 : une extension qui déclare un
 * en-tête « Update URI » voit WordPress appeler un filtre nommé d'après l'hôte
 * de cette adresse — ici `update_plugins_github.com`. Aucune bibliothèque,
 * aucun Composer : le plugin s'installe toujours en copiant son dossier.
 *
 * Cet en-tête vaut d'être posé même sans dépôt distant. Sans lui, WordPress
 * interroge wordpress.org avec le nom du dossier : si quelqu'un y publiait un
 * jour une extension appelée `subalcatel-club`, son code serait proposé à la
 * mise à jour sur le site du club. L'en-tête ferme cette porte.
 *
 * **Ce composant n'installe rien tout seul.** Il signale qu'une version existe
 * et laisse le bureau cliquer. Une mise à jour automatique est un canal
 * d'exécution de code à distance : qui tient le dépôt tient le code qui tourne
 * sur le site, et ce club sort d'un piratage. Le silence de l'arrière-plan est
 * précisément ce qu'on ne veut pas ici. Deux filtres permettent d'en décider
 * autrement le jour où la confiance dans la chaîne de publication est établie :
 * `subalcatel_auto_update_plugin` et `subalcatel_auto_update_theme`.
 *
 * Dépôt privé : définir `SUBALCATEL_GITHUB_TOKEN` dans `wp-config.php`, avec la
 * seule portée « contents: read » sur ce dépôt. Dépôt public : ne rien définir,
 * le téléchargement est anonyme.
 */
final class Updater
{
    /** Dépôt qui porte le code et les archives publiées. */
    private const DEPOT = 'fhunkeler/sub_wp';

    /** Hôte déclaré par l'en-tête « Update URI » du plugin et du thème. */
    private const HOTE = 'github.com';

    private const PLUGIN_FICHIER = 'subalcatel-club/subalcatel-club.php';
    private const PLUGIN_SLUG    = 'subalcatel-club';
    private const THEME_SLUG     = 'subalcatel';

    /**
     * Préfixes de balises. Un seul dépôt porte les deux extensions : sans
     * préfixe, `releases/latest` désignerait tantôt l'un tantôt l'autre.
     */
    private const PREFIXES = [
        'plugin-' => self::PLUGIN_SLUG,
        'theme-'  => self::THEME_SLUG,
    ];

    private const CACHE = 'subalcatel_releases';

    /**
     * GitHub limite l'API anonyme à 60 requêtes par heure **et par adresse IP**.
     * Sur un hébergement mutualisé, cette adresse est partagée avec des
     * inconnus : le quota peut être épuisé sans que le club y soit pour rien.
     * D'où un cache franc, et un cache négatif tout aussi important — sans lui,
     * une panne de GitHub ferait repartir une requête à chaque écran d'admin.
     */
    private const CACHE_SUCCES = 6 * HOUR_IN_SECONDS;
    private const CACHE_ECHEC  = 30 * MINUTE_IN_SECONDS;

    public static function register(): void
    {
        add_filter('update_plugins_' . self::HOTE, [self::class, 'offrePlugin'], 10, 3);
        add_filter('update_themes_' . self::HOTE, [self::class, 'offreTheme'], 10, 3);

        add_filter('auto_update_plugin', [self::class, 'refuserAutomatiquePlugin'], 10, 2);
        add_filter('auto_update_theme', [self::class, 'refuserAutomatiqueTheme'], 10, 2);

        add_filter('http_request_args', [self::class, 'authentifier'], 10, 2);

        // Purge du cache sur clic de « Vérifier à nouveau » : sans cela, le
        // bureau croit le bouton cassé pendant six heures.
        add_action('upgrader_process_complete', [self::class, 'oublier']);
        add_action('load-update-core.php', [self::class, 'oublierSiDemande']);
    }

    // -- Réponses aux filtres de WordPress ------------------------------------

    /**
     * @param array<string,mixed>|false $offre
     * @param array<string,mixed>       $entetes
     * @return array<string,mixed>|false
     */
    public static function offrePlugin(array|false $offre, array $entetes, string $fichier): array|false
    {
        // Le filtre est nommé d'après l'hôte, pas d'après l'extension : il se
        // déclenche pour **toute** extension hébergée sur github.com. Rendre
        // l'offre reçue telle quelle est ce qui empêche d'écraser celle d'un
        // voisin.
        if ($fichier !== self::PLUGIN_FICHIER) {
            return $offre;
        }

        $version = self::versionInstallee($entetes, VERSION);
        $release = self::plusRecente(self::PLUGIN_SLUG, $version);

        if ($release === null) {
            return $offre;
        }

        return [
            'id'      => 'https://github.com/' . self::DEPOT,
            'slug'    => self::PLUGIN_SLUG,
            'plugin'  => self::PLUGIN_FICHIER,
            'version' => $release['version'],
            'url'     => $release['url'],
            'package' => $release['package'],
        ];
    }

    /**
     * @param array<string,mixed>|false $offre
     * @param array<string,mixed>       $entetes
     * @return array<string,mixed>|false
     */
    public static function offreTheme(array|false $offre, array $entetes, string $feuille): array|false
    {
        if ($feuille !== self::THEME_SLUG) {
            return $offre;
        }

        $version = self::versionInstallee($entetes, '0.0.0');
        $release = self::plusRecente(self::THEME_SLUG, $version);

        if ($release === null) {
            return $offre;
        }

        return [
            'id'      => 'https://github.com/' . self::DEPOT,
            'theme'   => self::THEME_SLUG,
            'version' => $release['version'],
            'url'     => $release['url'],
            'package' => $release['package'],
        ];
    }

    /**
     * @param object|array<string,mixed> $element
     */
    public static function refuserAutomatiquePlugin(mixed $choix, mixed $element): mixed
    {
        $fichier = is_object($element) ? ($element->plugin ?? '') : ($element['plugin'] ?? '');

        return $fichier === self::PLUGIN_FICHIER
            ? (bool) apply_filters('subalcatel_auto_update_plugin', false)
            : $choix;
    }

    /**
     * @param object|array<string,mixed> $element
     */
    public static function refuserAutomatiqueTheme(mixed $choix, mixed $element): mixed
    {
        $feuille = is_object($element) ? ($element->theme ?? '') : ($element['theme'] ?? '');

        return $feuille === self::THEME_SLUG
            ? (bool) apply_filters('subalcatel_auto_update_theme', false)
            : $choix;
    }

    /**
     * Ajoute le jeton aux seuls appels vers l'API GitHub.
     *
     * Un seul endroit pose l'en-tête d'autorisation : l'interrogation des
     * releases et le téléchargement de l'archive passent tous deux par la pile
     * HTTP de WordPress, qui déclenche ce filtre. Le contrôle d'hôte est strict
     * — un jeton envoyé au mauvais serveur est un jeton perdu.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function authentifier(array $args, string $url): array
    {
        if (wp_parse_url($url, PHP_URL_HOST) !== 'api.github.com') {
            return $args;
        }

        $args['headers'] = (array) ($args['headers'] ?? []);
        $args['headers']['Accept'] = 'application/vnd.github+json';
        $args['headers']['X-GitHub-Api-Version'] = '2022-11-28';

        // L'API renvoie du JSON décrivant l'archive, sauf sur ce point d'entrée
        // où cet en-tête demande le binaire. C'est la voie de téléchargement
        // d'un dépôt privé, dont l'URL publique n'existe pas.
        if (str_contains((string) wp_parse_url($url, PHP_URL_PATH), '/releases/assets/')) {
            $args['headers']['Accept'] = 'application/octet-stream';
        }

        if (defined('SUBALCATEL_GITHUB_TOKEN') && SUBALCATEL_GITHUB_TOKEN !== '') {
            $args['headers']['Authorization'] = 'Bearer ' . SUBALCATEL_GITHUB_TOKEN;
        }

        return $args;
    }

    // -- Cache ----------------------------------------------------------------

    public static function oublier(): void
    {
        delete_site_transient(self::CACHE);
    }

    /** « Vérifier à nouveau » doit vraiment revérifier. */
    public static function oublierSiDemande(): void
    {
        if (isset($_GET['force-check'])) {
            self::oublier();
        }
    }

    // -- Interrogation du dépôt -----------------------------------------------

    /**
     * Version publiée la plus haute pour une extension, si elle dépasse celle
     * installée.
     *
     * @return array{version:string,package:string,url:string}|null
     */
    private static function plusRecente(string $slug, string $installee): ?array
    {
        $candidate = null;

        foreach (self::releases() as $release) {
            if ($release['slug'] !== $slug) {
                continue;
            }
            if (version_compare($release['version'], $installee, '<=')) {
                continue;
            }
            if ($candidate !== null && version_compare($release['version'], $candidate['version'], '<=')) {
                continue;
            }

            $candidate = $release;
        }

        return $candidate;
    }

    /**
     * Releases publiées, mises en forme et mises en cache.
     *
     * Un seul appel réseau sert le plugin et le thème : ils partagent le dépôt,
     * donc la liste.
     *
     * @return list<array{slug:string,version:string,package:string,url:string}>
     */
    private static function releases(): array
    {
        $cache = get_site_transient(self::CACHE);

        if (is_array($cache)) {
            return $cache;
        }

        $reponse = wp_remote_get(
            'https://api.github.com/repos/' . self::DEPOT . '/releases?per_page=20',
            [
                'timeout'    => 10,
                'user-agent' => 'subalcatel-club/' . VERSION . '; ' . home_url('/'),
            ]
        );

        if (is_wp_error($reponse) || wp_remote_retrieve_response_code($reponse) !== 200) {
            // Échec silencieux, et volontairement : une extension qui hurle
            // dans l'admin parce que GitHub tousse finit par être désactivée.
            set_site_transient(self::CACHE, [], self::CACHE_ECHEC);

            return [];
        }

        $brut = json_decode(wp_remote_retrieve_body($reponse), true);
        $releases = is_array($brut) ? self::interpreter($brut) : [];

        set_site_transient(self::CACHE, $releases, self::CACHE_SUCCES);

        return $releases;
    }

    /**
     * @param array<int,mixed> $brut
     * @return list<array{slug:string,version:string,package:string,url:string}>
     */
    private static function interpreter(array $brut): array
    {
        $prive = defined('SUBALCATEL_GITHUB_TOKEN') && SUBALCATEL_GITHUB_TOKEN !== '';
        $releases = [];

        foreach ($brut as $release) {
            if (!is_array($release) || !empty($release['draft']) || !empty($release['prerelease'])) {
                continue;
            }

            $balise = (string) ($release['tag_name'] ?? '');
            $slug = null;
            $version = '';

            foreach (self::PREFIXES as $prefixe => $candidat) {
                if (str_starts_with($balise, $prefixe)) {
                    $slug = $candidat;
                    $version = ltrim(substr($balise, strlen($prefixe)), 'v');
                    break;
                }
            }

            if ($slug === null || $version === '') {
                continue;
            }

            // L'archive attendue est celle produite par `build-packages.py`,
            // dont le dossier racine porte le nom du slug. Celle que GitHub
            // fabrique seul (« Source code ») a pour racine `sub_wp-<balise>` :
            // WordPress l'installerait comme une **seconde** extension et
            // désactiverait l'ancienne. D'où ce nom exact, et pas un motif.
            $attendu = $slug . '-' . $version . '.zip';
            $paquet = '';

            foreach ((array) ($release['assets'] ?? []) as $piece) {
                if (!is_array($piece) || ($piece['name'] ?? '') !== $attendu) {
                    continue;
                }

                $paquet = (string) ($prive ? ($piece['url'] ?? '') : ($piece['browser_download_url'] ?? ''));
                break;
            }

            if ($paquet === '') {
                continue;
            }

            $releases[] = [
                'slug'    => $slug,
                'version' => $version,
                'package' => $paquet,
                'url'     => (string) ($release['html_url'] ?? 'https://github.com/' . self::DEPOT),
            ];
        }

        return $releases;
    }

    /**
     * @param array<string,mixed> $entetes
     */
    private static function versionInstallee(array $entetes, string $defaut): string
    {
        $version = $entetes['Version'] ?? $entetes['version'] ?? '';

        return is_string($version) && $version !== '' ? $version : $defaut;
    }
}
