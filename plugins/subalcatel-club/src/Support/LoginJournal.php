<?php

declare(strict_types=1);

namespace Subalcatel\Club\Support;

use Subalcatel\Club\Notifications\DailyDigest;
use Subalcatel\Club\Notifications\EmailTemplates;
use Subalcatel\Club\Notifications\Mailer;
use WP_User;

/**
 * Journal des connexions des comptes qui touchent aux données sensibles.
 *
 * [LoginAudit] consigne chaque connexion dans le journal d'audit, mais elles
 * s'y noient parmi les adhésions et les exports : savoir quand un compte du
 * bureau a servi pour la dernière fois, et depuis où, demandait de fouiller.
 * Ce journal-ci les relit pour les comptes concernés — ceux que la double
 * authentification vise, c'est-à-dire ceux qui ouvrent les certificats
 * médicaux, les exports ou la gestion des comptes.
 *
 * Il ajoute deux choses :
 *
 *  - une **alerte** au titulaire quand son mot de passe sert depuis un
 *    appareil jamais vu : c'est souvent le seul moyen de découvrir qu'un
 *    identifiant a fuité — ce qui est déjà arrivé au club, avec l'ancien site ;
 *  - une **durée de conservation** : une adresse IP est une donnée
 *    personnelle, et une trace de connexion n'a plus d'usage passé un an.
 */
final class LoginJournal
{
    /** Ancienneté au-delà de laquelle les traces de connexion sont effacées. */
    public const RETENTION_MONTHS = 12;

    /** Fenêtre dans laquelle un appareil reste « connu ». */
    private const KNOWN_DAYS = 180;

    public static function register(): void
    {
        // Avant [LoginAudit] (priorité 10) : l'historique consulté pour juger
        // l'appareil ne doit pas contenir la connexion en cours.
        add_action('wp_login', [self::class, 'onLogin'], 5, 2);
        add_action(DailyDigest::HOOK, [self::class, 'purge']);
    }

    /**
     * Les comptes suivis, du plus récemment connecté au plus ancien.
     *
     * @return list<array{user: WP_User, last: ?array{at: string, ip: string, device: string}, failures: int}>
     */
    public static function accounts(): array
    {
        $rows = [];

        foreach (get_users(['orderby' => 'login']) as $user) {
            if (!TwoFactorGate::estConcerne($user)) {
                continue;
            }

            $rows[] = [
                'user'     => $user,
                'last'     => self::lastLogin($user->ID),
                'failures' => self::failuresFor($user, 30),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['last']['at'] ?? ''), (string) ($a['last']['at'] ?? '')));

        return $rows;
    }

    /**
     * @return array{at: string, ip: string, device: string}|null
     */
    public static function lastLogin(int $userId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT created_at, ip_address, details FROM {$wpdb->prefix}sub_audit_log
             WHERE action = 'auth.login' AND entity_id = %d
             ORDER BY id DESC LIMIT 1",
            $userId
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        $details = json_decode((string) $row['details'], true) ?: [];

        return [
            'at'     => (string) $row['created_at'],
            'ip'     => (string) $row['ip_address'],
            'device' => self::device((string) ($details['agent'] ?? '')),
        ];
    }

    /**
     * Échecs de connexion visant ce compte, par identifiant ou par adresse.
     */
    public static function failuresFor(WP_User $user, int $days): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sub_audit_log
             WHERE action = 'auth.login_failed'
               AND created_at >= NOW() - INTERVAL %d DAY
               AND JSON_UNQUOTE(JSON_EXTRACT(details, '$.identifiant')) IN (%s, %s)",
            $days,
            $user->user_login,
            $user->user_email
        ));
    }

    /**
     * Les dernières connexions des comptes suivis, réussies ou non.
     *
     * @return list<array{at: string, user: ?WP_User, login: string, success: bool, ip: string, device: string}>
     */
    public static function recent(int $limit = 100): array
    {
        global $wpdb;

        $ids         = [];
        $identifiers = [];

        foreach (get_users() as $user) {
            if (TwoFactorGate::estConcerne($user)) {
                $ids[$user->ID]                            = $user;
                $identifiers[strtolower($user->user_login)] = $user;
                $identifiers[strtolower($user->user_email)] = $user;
            }
        }

        if ($ids === []) {
            return [];
        }

        $idList    = implode(',', array_map('intval', array_keys($ids)));
        $loginList = implode(',', array_fill(0, count($identifiers), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- liste d'entiers et de marqueurs
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT action, entity_id, created_at, ip_address, details FROM {$wpdb->prefix}sub_audit_log
             WHERE (action = 'auth.login' AND entity_id IN ({$idList}))
                OR (action = 'auth.login_failed'
                    AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(details, '$.identifiant'))) IN ({$loginList}))
             ORDER BY id DESC LIMIT %d",
            ...array_merge(array_keys($identifiers), [$limit])
        ), ARRAY_A) ?: [];

        return array_map(static function (array $row) use ($ids, $identifiers): array {
            $details = json_decode((string) $row['details'], true) ?: [];
            $login   = (string) ($details['identifiant'] ?? '');
            $success = $row['action'] === 'auth.login';

            return [
                'at'      => (string) $row['created_at'],
                'user'    => $success ? ($ids[(int) $row['entity_id']] ?? null) : ($identifiers[strtolower($login)] ?? null),
                'login'   => $login,
                'success' => $success,
                'ip'      => (string) $row['ip_address'],
                'device'  => self::device((string) ($details['agent'] ?? '')),
            ];
        }, $rows);
    }

    /**
     * Connexion d'un compte suivi depuis un appareil jamais vu : on prévient
     * son titulaire.
     *
     * Le crochet `wp_login` passe dès le mot de passe vérifié, avant un
     * éventuel second facteur — c'est voulu : un mot de passe correct saisi
     * ailleurs est précisément ce qu'il faut signaler, que la double
     * authentification ait ensuite arrêté l'intrus ou non.
     */
    public static function onLogin(string $login, WP_User $user): void
    {
        if (!TwoFactorGate::estConcerne($user)) {
            return;
        }

        $ip    = (string) Audit::clientIp();
        $agent = self::agent();

        if (!self::isNewDevice($user->ID, $ip, $agent)) {
            return;
        }

        Mailer::toUser(EmailTemplates::SECURITY_NEW_DEVICE, $user->ID, [
            'date'        => wp_date('j F Y à H\hi'),
            'adresse_ip'  => $ip !== '' ? $ip : 'inconnue',
            'appareil'    => self::device($agent),
            'identifiant' => $user->user_login,
        ], ['entity_type' => 'auth', 'entity_id' => $user->ID]);

        Audit::log('auth.new_device', 'auth', $user->ID, ['appareil' => self::device($agent)], $user->ID);
    }

    /**
     * L'appareil est-il nouveau pour ce compte ?
     *
     * Connu si la même adresse IP **ou** le même navigateur a déjà servi à
     * une connexion réussie dans les six derniers mois : une box qui change
     * d'adresse ne déclenche rien, un téléphone neuf sur le wifi de la maison
     * non plus. Un compte sans aucun historique n'alerte pas : sa première
     * connexion n'a rien d'inhabituel, et l'alerte suivrait chaque mise en
     * service.
     */
    public static function isNewDevice(int $userId, string $ip, string $agent): bool
    {
        global $wpdb;

        $history = $wpdb->get_results($wpdb->prepare(
            "SELECT ip_address, details FROM {$wpdb->prefix}sub_audit_log
             WHERE action = 'auth.login' AND entity_id = %d AND created_at >= NOW() - INTERVAL %d DAY",
            $userId,
            self::KNOWN_DAYS
        ), ARRAY_A) ?: [];

        if ($history === []) {
            return false;
        }

        foreach ($history as $row) {
            $details = json_decode((string) $row['details'], true) ?: [];

            if (($ip !== '' && (string) $row['ip_address'] === $ip)
                || ($agent !== '' && (string) ($details['agent'] ?? '') === $agent)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Efface les traces de connexion trop anciennes.
     *
     * @return int lignes effacées
     */
    public static function purge(): int
    {
        global $wpdb;

        $deleted = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}sub_audit_log
             WHERE action IN ('auth.login', 'auth.login_failed', 'auth.new_device')
               AND created_at < NOW() - INTERVAL %d MONTH",
            self::RETENTION_MONTHS
        ));

        if ($deleted > 0) {
            Audit::log('auth.purged', 'system', null, ['rows' => $deleted, 'months' => self::RETENTION_MONTHS]);
        }

        return $deleted;
    }

    /**
     * « Firefox · Windows » plutôt qu'une chaîne User-Agent de 200 caractères.
     */
    public static function device(string $agent): string
    {
        if ($agent === '') {
            return 'inconnu';
        }

        $browser = match (true) {
            str_contains($agent, 'Edg/')                                   => 'Edge',
            str_contains($agent, 'OPR/')                                   => 'Opera',
            str_contains($agent, 'Firefox/')                               => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS/') => 'Chrome',
            str_contains($agent, 'Safari/')                                => 'Safari',
            default                                                        => 'Navigateur inconnu',
        };

        $system = match (true) {
            str_contains($agent, 'Android')                                   => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad')    => 'iOS',
            str_contains($agent, 'Windows')                                   => 'Windows',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Linux')                                     => 'Linux',
            default                                                           => '',
        };

        return $system === '' ? $browser : $browser . ' · ' . $system;
    }

    private static function agent(): string
    {
        $raw = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Même nettoyage que [LoginAudit] : la comparaison porte sur la chaîne
        // telle qu'elle a été enregistrée.
        return is_string($raw) ? substr(sanitize_text_field($raw), 0, 255) : '';
    }
}
