<?php

declare(strict_types=1);

namespace Subalcatel\Club\Support;

/**
 * Ralentisseur de connexion.
 *
 * WordPress n'oppose **aucune** limite aux tentatives de mot de passe : un
 * script peut en essayer des milliers par minute. Contre un mot de passe
 * faible — et un club en compte toujours — c'est une question de temps.
 *
 * Ce n'est pas un pare-feu. C'est un ralentisseur : au-delà d'un seuil, les
 * tentatives d'une même origine sont refusées pendant quelques minutes. Assez
 * pour rendre le bourrinage inutile, sans matériel ni service tiers. Un vrai
 * WAF (Cloudflare, Wordfence) reste préférable en production ; en attendant,
 * ceci ferme la porte grande ouverte.
 *
 * Le comptage se fait par **IP + identifiant visé** : bloquer sur la seule IP
 * punirait tout un réseau d'entreprise derrière une même adresse, et bloquer
 * sur le seul identifiant permettrait de verrouiller le compte d'un tiers en le
 * ciblant exprès (déni de service). Le couple limite les deux.
 *
 * À ce comptage fin s'ajoute un **second compteur, par IP seule**, contre une
 * attaque que le premier ne voit pas : le *password spraying*, qui essaie un
 * seul mot de passe sur des centaines de comptes différents. Chaque couple
 * IP+identifiant reste alors sous le seuil de 8, mais une même origine accumule
 * des dizaines d'échecs sur des identifiants distincts. Le seuil par IP est
 * donc plus haut (il doit tolérer un vrai réseau partagé) et se contente de
 * fermer la porte à une origine manifestement occupée à balayer.
 */
final class LoginThrottle
{
    // Les seuils, la fenêtre et la liste d'IP exemptées se lisent désormais dans
    // [SecuritySettings] (modifiables depuis l'administration) ; les valeurs par
    // défaut y reproduisent l'ancien comportement — 8 échecs par couple
    // IP+identifiant, 30 par IP seule, sur une fenêtre de 15 minutes.

    private const PREFIX    = 'sub_login_fail_';
    private const IP_PREFIX = 'sub_login_ip_';

    public static function register(): void
    {
        // Le réglage de l'administration fixe le défaut ; le filtre garde le
        // dernier mot (surcharge de code, désactivation en test).
        if (!(bool) apply_filters('subalcatel_login_throttle_enabled', SecuritySettings::throttleEnabled())) {
            return;
        }

        // Priorité 40 : APRÈS les callbacks natifs (`wp_authenticate_username_
        // _password` est à 20). S'y brancher plus tôt ne sert à rien — WordPress
        // réévalue ensuite et écrase notre erreur. Le léger coût du hachage,
        // déjà payé à ce stade, est le prix d'un blocage qui tient.
        add_filter('authenticate', [self::class, 'blockIfLockedOut'], 40, 3);
        add_action('wp_login_failed', [self::class, 'recordFailure']);
        add_action('wp_login', [self::class, 'clearOnSuccess'], 10, 2);
    }

    /**
     * @param \WP_User|\WP_Error|null $user
     * @return \WP_User|\WP_Error|null
     */
    public static function blockIfLockedOut(mixed $user, string $username, string $password): mixed
    {
        // Ni identifiant ni mot de passe : ce n'est pas une tentative, on laisse
        // WordPress afficher son formulaire.
        if ($username === '' && $password === '') {
            return $user;
        }

        // Origine de confiance déclarée par le bureau (local du club, VPN…) :
        // jamais ralentie.
        if (SecuritySettings::isIpExempt(self::ip())) {
            return $user;
        }

        if (self::attempts($username) < SecuritySettings::maxAttempts()
            && self::ipAttempts() < SecuritySettings::maxIpAttempts()
        ) {
            return $user;
        }

        return new \WP_Error(
            'sub_locked_out',
            sprintf(
                'Trop de tentatives de connexion. Réessayez dans %d minutes, ou '
                . 'réinitialisez votre mot de passe.',
                (int) ceil(SecuritySettings::windowSeconds() / 60)
            )
        );
    }

    public static function recordFailure(string $username): void
    {
        // Une origine exemptée ne remplit aucun compteur : sinon elle finirait
        // par franchir le seuil par IP et se verrouiller elle-même.
        if (SecuritySettings::isIpExempt(self::ip())) {
            return;
        }

        $window = SecuritySettings::windowSeconds();

        $key   = self::key($username);
        $count = (int) get_transient($key);

        // Chaque échec repousse l'expiration : un attaquant qui insiste reste
        // bloqué tant qu'il insiste, il ne peut pas attendre passivement la
        // fenêtre en continuant à essayer.
        set_transient($key, $count + 1, $window);

        // Même logique, mais sur l'IP seule : c'est ce compteur qui attrape le
        // balayage d'un mot de passe sur beaucoup de comptes.
        $ipKey   = self::ipKey();
        $ipCount = (int) get_transient($ipKey);
        set_transient($ipKey, $ipCount + 1, $window);

        if ($count + 1 === SecuritySettings::maxAttempts()) {
            Audit::log('login.locked_out', 'auth', null, [
                'identifiant' => $username,
                'origine'     => self::ip(),
            ], 0);
        }

        // Le franchissement du seuil par IP est journalisé une seule fois : il
        // signale un balayage, pas la simple maladresse d'un membre.
        if ($ipCount + 1 === SecuritySettings::maxIpAttempts()) {
            Audit::log('login.ip_locked_out', 'auth', null, [
                'origine' => self::ip(),
            ], 0);
        }
    }

    /**
     * @param string   $login identifiant utilisé
     * @param \WP_User $user  compte connecté
     */
    public static function clearOnSuccess(string $login, \WP_User $user): void
    {
        delete_transient(self::key($login));
    }

    private static function attempts(string $username): int
    {
        return (int) get_transient(self::key($username));
    }

    private static function ipAttempts(): int
    {
        return (int) get_transient(self::ipKey());
    }

    private static function key(string $username): string
    {
        // L'identifiant est haché : il n'a pas à traîner en clair dans la table
        // des options, et le couple IP+identifiant suffit à distinguer les cas.
        return self::PREFIX . md5(self::ip() . '|' . strtolower($username));
    }

    private static function ipKey(): string
    {
        // Volontairement non effacé par un succès (voir `clearOnSuccess`) : un
        // balayage qui tombe juste sur un compte ne doit pas remettre à zéro le
        // rempart pour les autres. Il se relâche à l'expiration de la fenêtre.
        return self::IP_PREFIX . md5(self::ip());
    }

    private static function ip(): string
    {
        $raw = $_SERVER['REMOTE_ADDR'] ?? '';

        // Les en-têtes de proxy sont falsifiables : les prendre en compte
        // permettrait de contourner le comptage en changeant d'en-tête à chaque
        // requête. On s'en tient à l'adresse réelle de la connexion.
        return is_string($raw) && filter_var($raw, FILTER_VALIDATE_IP) ? $raw : 'inconnue';
    }
}
