<?php

declare(strict_types=1);

namespace Subalcatel\Club\Support;

use Subalcatel\Club\Database\Schema;
use Subalcatel\Club\Documents\DocumentStorage;
use Subalcatel\Club\Notifications\DailyDigest;
use Subalcatel\Club\Notifications\SendQuota;

/**
 * Ce qu'il faut savoir d'un site avant d'y chercher une panne.
 *
 * La production n'offre ni SSH ni WP-CLI. Chaque diagnostic passait par un
 * export SQL rechargé sur un poste de développement, pour répondre à des
 * questions que le site connaît lui-même : quelle version tourne, l'entretien
 * quotidien est-il passé, les permaliens sont-ils restés sur « Simple » après
 * un import, l'éditeur de site a-t-il figé en base un en-tête qui masque celui
 * du thème. Les réponses sont ici, lisibles par un administrateur, et
 * copiables en un bloc de texte pour qui aide à distance.
 *
 * Rien de personnel n'y figure : ni adresse, ni nom, ni clé. Le rapport peut
 * partir dans un courriel ou un ticket sans relecture.
 *
 * Aucun appel réseau : la page doit s'ouvrir même quand le dépôt de mises à
 * jour ou le service d'envoi est en panne — c'est précisément là qu'on la lit.
 */
final class SiteStatus
{
    public const OK   = 'ok';
    public const WARN = 'warn';
    public const BAD  = 'bad';
    public const INFO = 'info';

    /** Structure de permaliens dont dépendent les liens du thème. */
    private const PERMALINKS = '/%postname%/';

    private const THEME = 'subalcatel';

    /**
     * Tous les contrôles, par rubrique.
     *
     * @return array<string, list<array{label: string, status: string, value: string, help: string}>>
     */
    public static function checks(): array
    {
        return [
            'Versions'                => self::versions(),
            'Tâches planifiées'       => self::scheduling(),
            'Courriel'                => self::mail(),
            'Adresses'                => self::addresses(),
            'Éditeur de site'         => self::siteEditorOverrides(),
            'Documents des membres'   => self::documents(),
            'Sécurité'                => self::security(),
        ];
    }

    /**
     * Le pire état rencontré, pour le résumé en tête de page.
     *
     * @param array<string, list<array{status: string}>> $checks
     */
    public static function worst(array $checks): string
    {
        $rank  = [self::INFO => 0, self::OK => 0, self::WARN => 1, self::BAD => 2];
        $worst = self::OK;

        foreach ($checks as $items) {
            foreach ($items as $item) {
                if ($rank[$item['status']] > $rank[$worst]) {
                    $worst = $item['status'];
                }
            }
        }

        return $worst;
    }

    /**
     * Le rapport en texte brut, à coller dans un courriel.
     *
     * @param array<string, list<array{label: string, status: string, value: string, help: string}>> $checks
     */
    public static function report(array $checks): string
    {
        $marks = [self::OK => '[ok]', self::WARN => '[!]', self::BAD => '[!!]', self::INFO => '[i]'];
        $lines = [
            sprintf('État du site %s — %s', wp_parse_url(home_url(), PHP_URL_HOST) ?: home_url(), wp_date('j F Y à H\hi')),
            '',
        ];

        foreach ($checks as $group => $items) {
            $lines[] = '== ' . $group;

            foreach ($items as $item) {
                $lines[] = sprintf('%-5s %s : %s', $marks[$item['status']], $item['label'], $item['value']);
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    // ------------------------------------------------------------- Rubriques

    /**
     * @return list<array{label: string, status: string, value: string, help: string}>
     */
    private static function versions(): array
    {
        global $wpdb;

        $items = [];

        $pluginUpdate = self::pendingUpdate('plugins', plugin_basename(\Subalcatel\Club\PLUGIN_FILE));
        $items[] = self::item(
            'Extension du club',
            $pluginUpdate === null ? self::OK : self::WARN,
            \Subalcatel\Club\VERSION . ($pluginUpdate === null ? '' : ' — ' . $pluginUpdate . ' disponible'),
            $pluginUpdate === null ? '' : 'Une version plus récente est publiée : Tableau de bord → Mises à jour.'
        );

        $theme = wp_get_theme(self::THEME);

        if (!$theme->exists()) {
            $items[] = self::item('Thème du club', self::BAD, 'absent', 'Le thème « subalcatel » n’est pas installé.');
        } else {
            $active      = get_stylesheet() === self::THEME;
            $themeUpdate = self::pendingUpdate('themes', self::THEME);

            $items[] = self::item(
                'Thème du club',
                !$active ? self::BAD : ($themeUpdate === null ? self::OK : self::WARN),
                (string) $theme->get('Version')
                    . ($active ? '' : ' — installé mais pas activé')
                    . ($themeUpdate === null ? '' : ' — ' . $themeUpdate . ' disponible'),
                $active ? '' : 'Le site affiche un autre thème : Apparence → Thèmes.'
            );
        }

        $installed = Schema::installedVersion();
        $expected  = Schema::expectedVersion();

        $items[] = self::item(
            'Schéma de la base',
            $installed === $expected ? self::OK : self::BAD,
            $installed === $expected ? (string) $installed : sprintf('%d en base, %d attendu', $installed, $expected),
            $installed === $expected ? '' : 'La migration n’a pas tourné : désactiver puis réactiver l’extension la relance.'
        );

        $items[] = self::item('WordPress', self::INFO, (string) get_bloginfo('version'));
        $items[] = self::item('PHP', version_compare(PHP_VERSION, '8.1', '>=') ? self::OK : self::BAD, PHP_VERSION);
        $items[] = self::item('Base de données', self::INFO, (string) $wpdb->db_server_info());

        return $items;
    }

    /**
     * @return list<array{label: string, status: string, value: string, help: string}>
     */
    private static function scheduling(): array
    {
        global $wpdb;

        $items = [];

        // L'écart se calcule côté base : `created_at` y est posé par l'horloge
        // du serveur de base de données, qui n'est pas forcément sur le fuseau
        // du site. L'heure affichée s'en déduit, à l'heure du club.
        $last = $wpdb->get_row(
            "SELECT MAX(created_at) AS at, TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) AS seconds
             FROM {$wpdb->prefix}sub_audit_log WHERE action = 'notifications.daily'",
            ARRAY_A
        );

        if (empty($last['at'])) {
            $items[] = self::item(
                'Dernier entretien quotidien',
                self::BAD,
                'jamais',
                'Rappels, purges et passages à la majorité ne tournent pas. Vérifier le cron de l’hébergeur.'
            );
        } else {
            $seconds = max(0, (int) $last['seconds']);
            $hours   = intdiv($seconds, HOUR_IN_SECONDS);

            $items[] = self::item(
                'Dernier entretien quotidien',
                $hours > 50 ? self::BAD : ($hours > 30 ? self::WARN : self::OK),
                sprintf('%s (il y a %d h)', wp_date('j F Y à H\hi', time() - $seconds), $hours),
                $hours > 30
                    ? 'Il devrait passer chaque jour. Un jour manqué se rattrape seul ; plusieurs, non : vérifier le cron de l’hébergeur.'
                    : ''
            );
        }

        $next = wp_next_scheduled(DailyDigest::HOOK);
        $items[] = self::item(
            'Prochain entretien prévu',
            $next === false ? self::BAD : self::INFO,
            $next === false ? 'non planifié' : (string) wp_date('j F Y à H\hi', (int) $next),
            $next === false ? 'Désactiver puis réactiver l’extension le replanifie.' : ''
        );

        $disabled = defined('DISABLE_WP_CRON') && constant('DISABLE_WP_CRON');
        $items[] = self::item(
            'WP-Cron',
            $disabled ? self::WARN : self::OK,
            $disabled ? 'désactivé (DISABLE_WP_CRON)' : 'actif',
            $disabled ? 'Seul le cron de l’hébergeur déclenche alors les tâches : s’il tombe, plus rien ne tourne.' : ''
        );

        return $items;
    }

    /**
     * @return list<array{label: string, status: string, value: string, help: string}>
     */
    private static function mail(): array
    {
        global $wpdb;

        $settings = SendQuota::settings();
        $sent     = SendQuota::sentToday();
        $items    = [];

        $items[] = self::item(
            'Envoyés aujourd’hui',
            $settings['limit'] > 0 && $sent >= $settings['limit'] ? self::WARN : self::OK,
            $settings['limit'] === 0 ? sprintf('%d (sans plafond)', $sent) : sprintf('%d / %d', $sent, $settings['limit'])
        );

        $failed = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sub_notification_log
             WHERE status = 'failed' AND sent_at >= NOW() - INTERVAL 7 DAY"
        );
        $items[] = self::item(
            'Échecs d’envoi (7 jours)',
            $failed > 0 ? self::BAD : self::OK,
            (string) $failed,
            $failed > 0 ? 'wp_mail a refusé ces envois : vérifier la connexion au service d’envoi, puis Communication → Journal.' : ''
        );

        $deferred = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sub_notification_log
             WHERE status = 'deferred' AND sent_at >= NOW() - INTERVAL 7 DAY"
        );
        $items[] = self::item(
            'Rappels reportés (7 jours)',
            $deferred > 0 ? self::WARN : self::OK,
            (string) $deferred,
            $deferred > 0 ? 'Le plafond quotidien a été atteint : ils repartent d’eux-mêmes au prochain entretien.' : ''
        );

        $queue = get_option('subalcatel_mail_queue', []);
        if (is_array($queue) && $queue !== []) {
            $items[] = self::item(
                'Envois groupés en file d’attente',
                self::INFO,
                (string) count($queue),
                'Ils partent d’eux-mêmes dès que le quota le permet : Communication → Journal.'
            );
        }

        // Deux façons pour une extension de prendre l'envoi en main : régler
        // PHPMailer (SMTP), ou remplacer `wp_mail()` tout entière (API d'un
        // service d'envoi). Ni l'une ni l'autre : c'est le mail() du serveur.
        $replaced = !str_starts_with(
            wp_normalize_path((string) (new \ReflectionFunction('wp_mail'))->getFileName()),
            wp_normalize_path(ABSPATH . WPINC)
        );
        $smtp = $replaced || has_action('phpmailer_init');
        $items[] = self::item(
            'Connexion d’envoi',
            $smtp ? self::OK : self::WARN,
            $smtp ? 'prise en charge par une extension' : 'fonction mail() du serveur',
            $smtp ? '' : 'Sans connexion SMTP, les messages partent de l’hébergeur et finissent souvent en indésirables.'
        );

        return $items;
    }

    /**
     * @return list<array{label: string, status: string, value: string, help: string}>
     */
    private static function addresses(): array
    {
        $items = [];

        $structure = (string) get_option('permalink_structure', '');
        $items[] = self::item(
            'Permaliens',
            $structure === self::PERMALINKS ? self::OK : self::BAD,
            $structure === '' ? 'Simple (?p=…)' : $structure,
            $structure === self::PERMALINKS
                ? ''
                : 'Les liens du thème supposent « Nom de l’article ». Réglages → Permaliens, choisir « Nom de l’article », enregistrer.'
        );

        if (self::isApache()) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $htaccess = get_home_path() . '.htaccess';
            $hasBlock = is_readable($htaccess) && str_contains((string) file_get_contents($htaccess), '# BEGIN WordPress');

            $items[] = self::item(
                'Règles de réécriture (.htaccess)',
                $hasBlock ? self::OK : self::BAD,
                $hasBlock ? 'présentes' : (is_readable($htaccess) ? 'bloc WordPress absent' : 'fichier absent'),
                $hasBlock ? '' : 'Toutes les pages sauf l’accueil répondent 404. Réenregistrer les permaliens écrit le bloc.'
            );
        }

        $https = str_starts_with((string) home_url(), 'https://');
        $items[] = self::item(
            'Adresse du site',
            $https ? self::OK : self::WARN,
            (string) home_url(),
            $https ? '' : 'Le site n’est pas servi en HTTPS : les mots de passe circulent en clair.'
        );

        return $items;
    }

    /**
     * Modèles et parties du thème figés en base par l'éditeur de site.
     *
     * Une surcharge n'est pas une faute — c'est ce que l'éditeur fait quand on
     * y touche. Mais elle prend le pas sur le fichier du thème, pour toujours :
     * le 02/10/2026, la refonte du thème est restée invisible en production
     * parce que l'en-tête et l'accueil y avaient été modifiés à la main.
     *
     * @return list<array{label: string, status: string, value: string, help: string}>
     */
    public static function siteEditorOverrides(): array
    {
        $posts = get_posts([
            'post_type'      => ['wp_template', 'wp_template_part'],
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => 100,
            'tax_query'      => [[
                'taxonomy' => 'wp_theme',
                'field'    => 'name',
                'terms'    => [get_stylesheet()],
            ]],
        ]);

        if ($posts === []) {
            return [self::item('Surcharges en base', self::OK, 'aucune', 'Le site affiche les modèles du thème tels que livrés.')];
        }

        $items = [];

        foreach ($posts as $post) {
            $isPart = $post->post_type === 'wp_template_part';
            $file   = get_stylesheet_directory() . ($isPart ? '/parts/' : '/templates/') . $post->post_name . '.html';
            $masks  = is_readable($file);

            $items[] = self::item(
                sprintf('%s « %s »', $isPart ? 'Partie' : 'Modèle', $post->post_title ?: $post->post_name),
                $masks ? self::WARN : self::INFO,
                sprintf(
                    '%s, modifié le %s',
                    $masks ? 'masque le fichier du thème' : 'créé dans l’éditeur',
                    mysql2date('j F Y', $post->post_modified)
                ),
                $masks
                    ? 'Les mises à jour du thème ne s’y voient plus. Apparence → Éditeur, ouvrir l’élément, puis « Réinitialiser ».'
                    : ''
            );
        }

        return $items;
    }

    /**
     * @return list<array{label: string, status: string, value: string, help: string}>
     */
    private static function documents(): array
    {
        $items = [];

        $outside = DocumentStorage::isOutsideWebRoot();
        $items[] = self::item(
            'Stockage des certificats',
            $outside ? self::OK : self::BAD,
            DocumentStorage::driver() . ($outside ? ', hors de la racine web' : ', DANS la racine web'),
            $outside ? '' : 'Les certificats médicaux sont à portée du serveur web : Réglages → Stockage des documents.'
        );

        $key = DocumentStorage::keyIsInConfig();
        $items[] = self::item(
            'Clé de chiffrement',
            $key ? self::OK : self::WARN,
            $key ? 'dans wp-config.php' : 'en base de données',
            $key ? '' : 'Une base volée livrerait aussi de quoi déchiffrer les documents. Poser SUBALCATEL_DOC_KEY dans wp-config.php.'
        );

        return $items;
    }

    /**
     * @return list<array{label: string, status: string, value: string, help: string}>
     */
    private static function security(): array
    {
        $items = [];

        $extension = TwoFactorGate::extensionDisponible();
        $required  = TwoFactorGate::exigenceActive();

        $items[] = self::item(
            'Double authentification',
            $extension && $required ? self::OK : self::WARN,
            match (true) {
                !$extension => 'extension « Two Factor » absente',
                !$required  => 'disponible, non exigée',
                default     => 'exigée pour les comptes concernés',
            },
            $extension && $required ? '' : 'Réglages → Sécurité.'
        );

        $display = defined('WP_DEBUG') && WP_DEBUG && (!defined('WP_DEBUG_DISPLAY') || WP_DEBUG_DISPLAY);
        $items[] = self::item(
            'Affichage des erreurs PHP',
            $display ? self::BAD : self::OK,
            $display ? 'affichées aux visiteurs' : 'masquées',
            $display ? 'WP_DEBUG_DISPLAY doit être à false en production : les erreurs révèlent des chemins du serveur.' : ''
        );

        return $items;
    }

    // ---------------------------------------------------------------- Outils

    /**
     * Version proposée par WordPress pour ce paquet, d'après son propre cache
     * de mises à jour — sans interroger le dépôt.
     */
    private static function pendingUpdate(string $kind, string $key): ?string
    {
        $updates = get_site_transient('update_' . $kind);
        $offer   = is_object($updates) && isset($updates->response[$key]) ? $updates->response[$key] : null;

        if ($offer === null) {
            return null;
        }

        $version = is_array($offer) ? ($offer['new_version'] ?? '') : ($offer->new_version ?? '');

        return $version !== '' ? (string) $version : null;
    }

    private static function isApache(): bool
    {
        return str_contains(strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? '')), 'apache')
            || function_exists('apache_get_modules');
    }

    /**
     * @return array{label: string, status: string, value: string, help: string}
     */
    private static function item(string $label, string $status, string $value, string $help = ''): array
    {
        return ['label' => $label, 'status' => $status, 'value' => $value, 'help' => $help];
    }
}
