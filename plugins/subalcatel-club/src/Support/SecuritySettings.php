<?php

declare(strict_types=1);

namespace Subalcatel\Club\Support;

/**
 * Réglages de sécurité modifiables depuis l'administration.
 *
 * Politique de mot de passe et ralentisseur de connexion étaient réglés en dur
 * (constantes) et par filtres — pratique pour un développeur, hors de portée
 * d'un bureau. Ce magasin porte les mêmes valeurs dans une option unique, que
 * l'onglet « Sécurité » des réglages édite.
 *
 * Les **valeurs par défaut reproduisent exactement** le comportement d'avant :
 * tant que personne n'a rien changé dans l'administration, rien ne bouge. Les
 * filtres existants continuent de primer (voir [PasswordPolicy], [LoginThrottle])
 * pour qu'un réglage de code ou un test puisse toujours forcer la main.
 */
final class SecuritySettings
{
    private const OPTION = 'subalcatel_security';

    /** Bornes de sûreté : un réglage saisi à la main ne doit pas pouvoir
     *  désarmer la protection par une valeur absurde. */
    private const MIN_LENGTH_FLOOR = 8;
    private const MIN_LENGTH_CEIL  = 64;
    private const ATTEMPTS_FLOOR   = 3;
    private const ATTEMPTS_CEIL    = 100;
    private const WINDOW_MIN_FLOOR = 1;
    private const WINDOW_MIN_CEIL  = 1440; // 24 h

    /**
     * @return array{
     *   password_min_length: int,
     *   password_similarity: bool,
     *   password_breach_check: bool,
     *   two_factor_required: bool,
     *   throttle_enabled: bool,
     *   throttle_max_attempts: int,
     *   throttle_max_ip_attempts: int,
     *   throttle_window_minutes: int,
     *   throttle_ip_allowlist: list<string>
     * }
     */
    public static function defaults(): array
    {
        return [
            'password_min_length'      => 12,
            'password_similarity'      => true,
            'password_breach_check'    => true,
            // Armée par défaut, mais sans effet tant que l'extension `two-factor`
            // n'est pas active : voir [TwoFactorGate]. Un durcissement qui
            // s'applique sans moyen de s'y conformer enferme dehors.
            'two_factor_required'      => true,
            'throttle_enabled'         => true,
            'throttle_max_attempts'    => 8,
            'throttle_max_ip_attempts' => 30,
            'throttle_window_minutes'  => 15,
            'throttle_ip_allowlist'    => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);

        return array_merge(self::defaults(), is_array($stored) ? $stored : []);
    }

    public static function passwordMinLength(): int
    {
        return (int) self::all()['password_min_length'];
    }

    public static function similarityEnabled(): bool
    {
        return (bool) self::all()['password_similarity'];
    }

    public static function breachCheckEnabled(): bool
    {
        return (bool) self::all()['password_breach_check'];
    }

    public static function twoFactorRequired(): bool
    {
        return (bool) self::all()['two_factor_required'];
    }

    public static function throttleEnabled(): bool
    {
        return (bool) self::all()['throttle_enabled'];
    }

    public static function maxAttempts(): int
    {
        return (int) self::all()['throttle_max_attempts'];
    }

    public static function maxIpAttempts(): int
    {
        return (int) self::all()['throttle_max_ip_attempts'];
    }

    public static function windowSeconds(): int
    {
        return (int) self::all()['throttle_window_minutes'] * 60;
    }

    /**
     * @return list<string>
     */
    public static function ipAllowlist(): array
    {
        $list = self::all()['throttle_ip_allowlist'];

        return is_array($list) ? array_values(array_map('strval', $list)) : [];
    }

    /**
     * L'IP est-elle exemptée du ralentisseur ? Le local du club, un VPN du
     * bureau : autant d'origines de confiance qu'on ne veut jamais verrouiller.
     * Accepte l'égalité stricte et la notation CIDR (v4 et v6).
     */
    public static function isIpExempt(string $ip): bool
    {
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        foreach (self::ipAllowlist() as $rule) {
            if (self::ipMatches($ip, $rule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nettoie une saisie de formulaire avant enregistrement. Chaque valeur est
     * bornée : le formulaire ne doit pas pouvoir écrire un réglage qui ouvre la
     * porte plus grand que si la protection n'existait pas.
     *
     * @param array<string, mixed> $input
     */
    public static function save(array $input): void
    {
        $clean = [
            'password_min_length'      => self::clamp(
                (int) ($input['password_min_length'] ?? 12),
                self::MIN_LENGTH_FLOOR,
                self::MIN_LENGTH_CEIL
            ),
            'password_similarity'      => !empty($input['password_similarity']),
            'password_breach_check'    => !empty($input['password_breach_check']),
            'two_factor_required'      => !empty($input['two_factor_required']),
            'throttle_enabled'         => !empty($input['throttle_enabled']),
            'throttle_max_attempts'    => self::clamp(
                (int) ($input['throttle_max_attempts'] ?? 8),
                self::ATTEMPTS_FLOOR,
                self::ATTEMPTS_CEIL
            ),
            'throttle_max_ip_attempts' => self::clamp(
                (int) ($input['throttle_max_ip_attempts'] ?? 30),
                self::ATTEMPTS_FLOOR,
                self::ATTEMPTS_CEIL
            ),
            'throttle_window_minutes'  => self::clamp(
                (int) ($input['throttle_window_minutes'] ?? 15),
                self::WINDOW_MIN_FLOOR,
                self::WINDOW_MIN_CEIL
            ),
            'throttle_ip_allowlist'    => self::parseAllowlist((string) ($input['throttle_ip_allowlist'] ?? '')),
        ];

        // Le compteur par IP doit rester au moins aussi tolérant que le compteur
        // fin, sinon il verrouillerait avant lui et le rendrait inutile.
        if ($clean['throttle_max_ip_attempts'] < $clean['throttle_max_attempts']) {
            $clean['throttle_max_ip_attempts'] = $clean['throttle_max_attempts'];
        }

        update_option(self::OPTION, $clean);
    }

    /**
     * Retient les IP et blocs CIDR valides d'un texte (une entrée par ligne),
     * écarte silencieusement le reste. Les invalides ne sont pas une exemption :
     * mieux vaut les ignorer que verrouiller sur une faute de frappe.
     *
     * @return list<string>
     */
    public static function parseAllowlist(string $raw): array
    {
        $kept = [];

        foreach (preg_split('/\r\n|\r|\n|,/', $raw) ?: [] as $line) {
            $entry = trim($line);

            if ($entry === '') {
                continue;
            }

            if (str_contains($entry, '/')) {
                if (self::isValidCidr($entry)) {
                    $kept[] = $entry;
                }
            } elseif (filter_var($entry, FILTER_VALIDATE_IP)) {
                $kept[] = $entry;
            }
        }

        return array_values(array_unique($kept));
    }

    private static function clamp(int $value, int $floor, int $ceil): int
    {
        return max($floor, min($ceil, $value));
    }

    private static function isValidCidr(string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '');

        if (!filter_var($subnet, FILTER_VALIDATE_IP) || $bits === '' || !ctype_digit($bits)) {
            return false;
        }

        $max = str_contains($subnet, ':') ? 128 : 32;

        return (int) $bits >= 0 && (int) $bits <= $max;
    }

    /**
     * Vrai si `$ip` tombe dans la règle (égalité ou bloc CIDR). Compare sur la
     * forme binaire (`inet_pton`) : robuste aux écritures d'une même adresse.
     */
    private static function ipMatches(string $ip, string $rule): bool
    {
        if (!str_contains($rule, '/')) {
            $a = inet_pton($ip);
            $b = inet_pton($rule);

            return $a !== false && $b !== false && hash_equals($b, $a);
        }

        if (!self::isValidCidr($rule)) {
            return false;
        }

        [$subnet, $bits] = explode('/', $rule, 2);
        $bits = (int) $bits;

        $ipBin     = inet_pton($ip);
        $subnetBin = inet_pton($subnet);

        // Familles différentes (une v4 face à un bloc v6) : pas de correspondance.
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $whole = intdiv($bits, 8);
        $rest  = $bits % 8;

        if ($whole > 0 && !hash_equals(substr($subnetBin, 0, $whole), substr($ipBin, 0, $whole))) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        $mask    = chr((0xFF << (8 - $rest)) & 0xFF);
        $ipByte  = $ipBin[$whole] & $mask;
        $subByte = $subnetBin[$whole] & $mask;

        return hash_equals($subByte, $ipByte);
    }
}
