<?php

declare(strict_types=1);

namespace Subalcatel\Club\Support;

use WP_User;

/**
 * Trace des connexions dans le journal du club.
 *
 * Le journal d'audit consignait déjà les actions sensibles (adhésions,
 * documents, exports…) mais **pas les connexions** : impossible, depuis
 * l'administration, de répondre à « ce compte a-t-il été utilisé, et depuis
 * où ? ». Il fallait alors reconstituer la réponse à la main dans les journaux
 * du serveur web. On la rend disponible sur place.
 *
 * Deux événements :
 *   - `auth.login`        — connexion réussie (l'information la plus utile) ;
 *   - `auth.login_failed` — échec, avec l'identifiant tenté mais **jamais** le
 *     mot de passe.
 *
 * Le volume reste borné : le ralentisseur ([LoginThrottle]) plafonne les
 * tentatives par origine, donc un bourrinage ne peut pas noyer la table.
 */
final class LoginAudit
{
    public static function register(): void
    {
        if (!(bool) apply_filters('subalcatel_login_audit_enabled', true)) {
            return;
        }

        add_action('wp_login', [self::class, 'onSuccess'], 10, 2);
        add_action('wp_login_failed', [self::class, 'onFailure'], 10, 1);
    }

    /**
     * @param string  $login identifiant utilisé
     * @param WP_User $user  compte connecté
     */
    public static function onSuccess(string $login, WP_User $user): void
    {
        // L'IP est posée par Audit lui-même ; on note ici le contexte utile à la
        // lecture (le navigateur), qui aide à distinguer « le membre sur son
        // téléphone » d'« un accès inhabituel ».
        Audit::log('auth.login', 'auth', $user->ID, [
            'identifiant' => $login,
            'agent'       => self::userAgent(),
        ], $user->ID);
    }

    public static function onFailure(string $login): void
    {
        Audit::log('auth.login_failed', 'auth', null, [
            'identifiant' => $login,
            'agent'       => self::userAgent(),
        ], 0);
    }

    private static function userAgent(): string
    {
        $raw = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Borné et nettoyé : c'est une chaîne fournie par le client, elle n'a
        // pas à entrer telle quelle dans la base.
        return is_string($raw) ? substr(sanitize_text_field($raw), 0, 255) : '';
    }
}
