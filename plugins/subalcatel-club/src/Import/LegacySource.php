<?php

declare(strict_types=1);

namespace Subalcatel\Club\Import;

/**
 * Accès en LECTURE SEULE à la base Joomla héritée.
 *
 * Le site Joomla est compromis : son code ne doit jamais être exécuté. On ne
 * touche donc qu'aux **données**, chargées au préalable dans une base séparée
 * depuis le dump Akeeba. Cette classe est le seul point de contact avec elles,
 * et elle refuse toute requête qui ne soit pas un SELECT — une sécurité de
 * ceinture et bretelles : même une erreur de programmation ne peut pas écrire
 * dans la base héritée, ni s'en servir comme tremplin.
 */
final class LegacySource
{
    private \wpdb $db;

    /** Préfixe des tables héritées dans la base de reprise. */
    private string $prefix;

    public function __construct(
        string $name,
        string $user,
        string $password,
        string $host,
        string $prefix = 'jml_'
    ) {
        $this->prefix = $prefix;

        // Connexion distincte de celle de WordPress : la base héritée est une
        // pièce à conviction, pas une base de travail.
        $this->db              = new \wpdb($user, $password, $name, $host);
        $this->db->suppress_errors(true);
        $this->db->set_charset($this->db->dbh, 'utf8mb4');
    }

    /**
     * Construit la source depuis les réglages, ou null si non configurée.
     */
    public static function fromSettings(): ?self
    {
        $name = (string) get_option('subalcatel_legacy_db_name', '');
        if ($name === '') {
            return null;
        }

        return new self(
            $name,
            (string) get_option('subalcatel_legacy_db_user', ''),
            (string) get_option('subalcatel_legacy_db_pass', ''),
            (string) get_option('subalcatel_legacy_db_host', ''),
            (string) get_option('subalcatel_legacy_db_prefix', 'jml_')
        );
    }

    /**
     * La base est-elle joignable et peuplée ?
     */
    public function isReady(): bool
    {
        $found = $this->db->get_var(
            $this->db->prepare('SHOW TABLES LIKE %s', $this->prefix . 'users')
        );

        return $found !== null && $this->db->last_error === '';
    }

    public function table(string $name): string
    {
        return $this->prefix . $name;
    }

    /**
     * @param  array<int, scalar> $args
     * @return list<array<string, mixed>>
     */
    public function rows(string $sql, array $args = []): array
    {
        return $this->db->get_results($this->guard($sql, $args), ARRAY_A) ?: [];
    }

    /**
     * @param  array<int, scalar> $args
     * @return array<string, mixed>|null
     */
    public function row(string $sql, array $args = []): ?array
    {
        return $this->db->get_row($this->guard($sql, $args), ARRAY_A) ?: null;
    }

    /**
     * @param array<int, scalar> $args
     */
    public function value(string $sql, array $args = []): ?string
    {
        $value = $this->db->get_var($this->guard($sql, $args));

        return $value === null ? null : (string) $value;
    }

    public function lastError(): string
    {
        return $this->db->last_error;
    }

    /**
     * Refuse tout ce qui n'est pas une lecture, puis prépare la requête.
     *
     * @param array<int, scalar> $args
     */
    private function guard(string $sql, array $args): string
    {
        if (!preg_match('/^\s*(SELECT|SHOW)\s/i', $sql)) {
            throw new \LogicException(
                'La base héritée est en lecture seule : seuls SELECT et SHOW sont admis.'
            );
        }

        return $args === [] ? $sql : $this->db->prepare($sql, ...$args);
    }
}
