<?php

declare(strict_types=1);

namespace Subalcatel\Club\Support;

use WP_Error;
use WP_User;

/**
 * Politique de mot de passe, appliquée aux **quatre** points où un membre en
 * choisit un : l'inscription, le changement depuis l'espace membre, l'écran
 * natif de réinitialisation (« mot de passe oublié ») et le profil de
 * l'administration.
 *
 * Deux règles s'ajoutent à la longueur minimale que WordPress ne vérifie pas
 * de lui-même :
 *
 * 1. **Refuser un mot de passe trop proche de l'identité** — identifiant,
 *    adresse e-mail, prénom, nom. C'est précisément le cas « login = mot de
 *    passe » : il ne demande aucune force brute, une seule tentative suffit.
 *    Aucun ralentisseur ne l'arrête, seul ce contrôle le ferme.
 * 2. **Refuser un mot de passe déjà présent dans une fuite connue**, via l'API
 *    « Have I Been Pwned » interrogée en **k-anonymité** : on n'envoie que les
 *    cinq premiers caractères du SHA-1, jamais le mot de passe ni son
 *    empreinte complète. Le service renvoie tous les suffixes de ce préfixe,
 *    et la comparaison finale se fait ici, localement.
 *
 * Le contrôle de fuite est **tolérant à la panne** : si l'API est injoignable,
 * on laisse passer. Un club ne peut pas empêcher un adhérent de changer son mot
 * de passe parce qu'un service tiers est en carafe — la règle de similarité,
 * elle, ne dépend de personne et reste toujours active.
 */
final class PasswordPolicy
{
    /** Longueur minimale par défaut, si aucun réglage n'a été enregistré.
     *  La valeur effective se lit dans [SecuritySettings]. */
    public const MIN_LENGTH = 12;

    /** Longueur en deçà de laquelle un fragment d'identité est ignoré : « Ana »
     *  apparaîtrait dans trop de mots de passe légitimes pour être refusé. */
    private const MIN_TOKEN = 4;

    /**
     * Branche la politique sur les deux écrans **natifs** de WordPress. Les
     * deux formulaires du plugin (inscription, changement) l'appellent
     * directement, eux, car ils portent déjà leur propre validation.
     */
    public static function register(): void
    {
        // « Mot de passe oublié » → nouveau mot de passe. C'est le chemin par
        // lequel les 133 comptes repris ont été initialisés, et celui qui
        // n'avait jusqu'ici aucun garde-fou. `$user` est le compte visé.
        add_action('validate_password_reset', [self::class, 'onCoreReset'], 10, 2);

        // Profil de l'administration et édition d'un compte par le bureau.
        add_action('user_profile_update_errors', [self::class, 'onProfileUpdate'], 10, 3);
    }

    /** Longueur minimale effective (réglage de l'administration, défaut 12). */
    public static function minLength(): int
    {
        return SecuritySettings::passwordMinLength();
    }

    /**
     * Première raison de rejet, ou `null` si le mot de passe est acceptable.
     *
     * @param array{login?: string, email?: string, first?: string, last?: string} $identity
     */
    public static function rejectionReason(string $password, array $identity): ?string
    {
        if (SecuritySettings::similarityEnabled() && self::tooSimilar($password, $identity)) {
            return 'Ce mot de passe est trop proche de votre identifiant, de votre '
                . 'adresse e-mail ou de votre nom. Choisissez-en un sans rapport avec '
                . 'votre identité.';
        }

        if (self::isBreached($password)) {
            return 'Ce mot de passe figure dans une fuite de données connue et n’offre '
                . 'plus aucune protection. Choisissez-en un autre.';
        }

        return null;
    }

    /**
     * Trop proche de l'identité ? Égalité (le cas « login = mot de passe »),
     * ou l'un contenu dans l'autre pour un fragment assez long pour être
     * significatif.
     *
     * @param array{login?: string, email?: string, first?: string, last?: string} $identity
     */
    public static function tooSimilar(string $password, array $identity): bool
    {
        $pass = strtolower(trim($password));

        if ($pass === '') {
            return false;
        }

        $email  = strtolower((string) ($identity['email'] ?? ''));
        $tokens = [
            strtolower((string) ($identity['login'] ?? '')),
            $email,
            strstr($email, '@', true) ?: '', // partie locale de l'e-mail
            strtolower((string) ($identity['first'] ?? '')),
            strtolower((string) ($identity['last'] ?? '')),
        ];

        foreach ($tokens as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            // Égalité stricte : « login = mot de passe » et ses variantes.
            if ($pass === $token) {
                return true;
            }

            // Inclusion, dans un sens comme dans l'autre, dès que le fragment
            // est assez long pour ne pas piéger un mot de passe innocent.
            if (strlen($token) >= self::MIN_TOKEN
                && (str_contains($pass, $token) || str_contains($token, $pass))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Le mot de passe figure-t-il dans une fuite connue ? Interrogation en
     * k-anonymité ; tolérante à la panne (renvoie `false` si l'API échoue).
     */
    public static function isBreached(string $password): bool
    {
        // Le réglage de l'administration fixe le défaut ; le filtre garde le
        // dernier mot (surcharge de code, désactivation en test).
        if (!(bool) apply_filters('subalcatel_password_breach_check_enabled', SecuritySettings::breachCheckEnabled())) {
            return false;
        }

        if ($password === '') {
            return false;
        }

        $hash   = strtoupper(sha1($password));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        $response = wp_remote_get(
            'https://api.pwnedpasswords.com/range/' . $prefix,
            [
                'timeout'    => 3,
                'user-agent' => 'SubAlcatel-Club/' . \Subalcatel\Club\VERSION,
                // Réponse rembourrée d'un nombre variable de faux suffixes :
                // un observateur du réseau ne peut pas déduire du volume de la
                // réponse si le préfixe correspondait à quelque chose.
                'headers'    => ['Add-Padding' => 'true'],
            ]
        );

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return false; // porte ouverte : on ne bloque pas sur une panne réseau.
        }

        $body = (string) wp_remote_retrieve_body($response);

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            // Chaque ligne : « SUFFIXE:compte ». Le rembourrage a un compte de 0.
            $parts     = explode(':', $line, 2);
            $candidate = strtoupper(trim($parts[0]));
            $count     = isset($parts[1]) ? (int) trim($parts[1]) : 0;

            if ($count > 0 && hash_equals($candidate, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rappel : `validate_password_reset` reçoit le mot de passe dans
     * `$_POST['pass1']`. On complète la validation native (longueur + les deux
     * règles maison) en ajoutant nos erreurs au `WP_Error` partagé.
     *
     * @param WP_User|WP_Error $user
     */
    public static function onCoreReset(WP_Error $errors, mixed $user): void
    {
        $password = isset($_POST['pass1']) ? (string) $_POST['pass1'] : '';

        if ($password === '' || $errors->has_errors()) {
            return; // rien saisi, ou WordPress signale déjà un problème.
        }

        self::enforce($errors, $password, self::identityOf($user instanceof WP_User ? $user : null));
    }

    /**
     * `user_profile_update_errors` : édition d'un profil dans l'administration
     * (le sien, ou celui d'un membre par le bureau).
     *
     * @param bool             $update vrai s'il s'agit d'une mise à jour
     * @param \stdClass|WP_User $user   données du compte en cours d'édition
     */
    public static function onProfileUpdate(WP_Error $errors, bool $update, mixed $user): void
    {
        $password = isset($_POST['pass1']) ? (string) $_POST['pass1'] : '';

        if ($password === '' || $errors->has_errors()) {
            return;
        }

        $identity = [
            'login' => (string) ($user->user_login ?? ($_POST['user_login'] ?? '')),
            'email' => (string) ($user->user_email ?? ($_POST['email'] ?? '')),
            'first' => (string) ($user->first_name ?? ($_POST['first_name'] ?? '')),
            'last'  => (string) ($user->last_name ?? ($_POST['last_name'] ?? '')),
        ];

        self::enforce($errors, $password, $identity);
    }

    /**
     * Longueur puis les deux règles maison, poussées dans le `WP_Error` natif.
     *
     * @param array{login?: string, email?: string, first?: string, last?: string} $identity
     */
    private static function enforce(WP_Error $errors, string $password, array $identity): void
    {
        $minLength = SecuritySettings::passwordMinLength();

        if (strlen($password) < $minLength) {
            $errors->add('sub_pass_too_short', sprintf(
                'Le mot de passe doit compter au moins %d caractères.',
                $minLength
            ));
            return; // inutile d'empiler les reproches sur un mot de passe déjà trop court.
        }

        $reason = self::rejectionReason($password, $identity);

        if ($reason !== null) {
            $errors->add('sub_pass_weak', $reason);
        }
    }

    /**
     * @return array{login: string, email: string, first: string, last: string}
     */
    private static function identityOf(?WP_User $user): array
    {
        if (!$user instanceof WP_User) {
            return ['login' => '', 'email' => '', 'first' => '', 'last' => ''];
        }

        return [
            'login' => (string) $user->user_login,
            'email' => (string) $user->user_email,
            'first' => (string) $user->first_name,
            'last'  => (string) $user->last_name,
        ];
    }
}
