<?php

declare(strict_types=1);

namespace Subalcatel\Club\Support;

/**
 * Journal des actions sensibles.
 *
 * Écriture seule : on ajoute, on ne modifie ni ne supprime. C'est ce qui rend
 * la trace opposable — un journal que l'on peut réécrire ne prouve rien.
 */
final class Audit
{
    /**
     * @param array<string, mixed> $details
     */
    public static function log(
        string $action,
        string $entityType,
        ?int $entityId = null,
        array $details = [],
        ?int $userId = null,
    ): void {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'sub_audit_log', [
            'user_id'     => $userId ?? get_current_user_id() ?: null,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'details'     => $details === [] ? null : wp_json_encode($details),
            'ip_address'  => self::clientIp(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recent(int $limit = 50): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sub_audit_log ORDER BY created_at DESC, id DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /** Plafond d'un export : au-delà, le fichier ne s'ouvre plus bien dans un tableur. */
    public const EXPORT_LIMIT = 50000;

    /**
     * Recherche paginée dans le journal.
     *
     * Le terme est cherché à la fois dans l'action, les détails, l'adresse IP,
     * et — après résolution — dans l'identité de l'auteur (identifiant, nom
     * affiché, e-mail). C'est ce dernier point qui permet de taper « mathieu »
     * et de retrouver ses connexions, alors que la table ne stocke qu'un
     * `user_id`. Un filtre facultatif par catégorie (`entity_type`) isole par
     * exemple les événements de connexion (`auth`) pour déboguer le ralentisseur.
     * `from` et `to` (AAAA-MM-JJ, jours de l'heure de Paris, bornes incluses)
     * restreignent à une période.
     *
     * @param array{q?: string, entity_type?: string, from?: string, to?: string, page?: int, per_page?: int} $args
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public static function search(array $args = []): array
    {
        global $wpdb;

        $table   = $wpdb->prefix . 'sub_audit_log';
        $perPage = max(1, min(500, (int) ($args['per_page'] ?? 50)));
        $page    = max(1, (int) ($args['page'] ?? 1));

        [$whereSql, $params] = self::filter($args);

        // Total (sans LIMIT) : `prepare` refuse une requête sans marqueur, d'où
        // le branchement selon qu'il y a des paramètres ou non.
        $countSql = "SELECT COUNT(*) FROM {$table} {$whereSql}";
        $total    = (int) ($params === []
            ? $wpdb->get_var($countSql)
            : $wpdb->get_var($wpdb->prepare($countSql, $params)));

        $pages  = max(1, (int) ceil($total / $perPage));
        $page   = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $rowsSql = "SELECT * FROM {$table} {$whereSql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
        $rows    = $wpdb->get_results(
            $wpdb->prepare($rowsSql, array_merge($params, [$perPage, $offset])),
            ARRAY_A
        ) ?: [];

        return [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => $pages,
        ];
    }

    /**
     * Toutes les entrées d'un filtre, sans pagination, pour un export.
     *
     * Ordre chronologique, à l'inverse de l'écran : une analyse se lit du
     * début à la fin.
     *
     * @param array{q?: string, entity_type?: string, from?: string, to?: string} $args
     * @return list<array<string, mixed>>
     */
    public static function matching(array $args = [], int $limit = self::EXPORT_LIMIT): array
    {
        global $wpdb;

        [$whereSql, $params] = self::filter($args);

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sub_audit_log {$whereSql}
                 ORDER BY created_at ASC, id ASC LIMIT %d",
                array_merge($params, [max(1, $limit)])
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Clause WHERE commune à l'écran et à l'export : les deux montrent
     * exactement les mêmes entrées pour un même filtre.
     *
     * @param array<string, mixed> $args
     * @return array{0: string, 1: list<string|int>}
     */
    private static function filter(array $args): array
    {
        global $wpdb;

        $q      = trim((string) ($args['q'] ?? ''));
        $entity = trim((string) ($args['entity_type'] ?? ''));
        $from   = self::day((string) ($args['from'] ?? ''));
        $to     = self::day((string) ($args['to'] ?? ''));

        $where  = [];
        $params = [];

        if ($q !== '') {
            $like  = '%' . $wpdb->esc_like($q) . '%';
            $parts = ['action LIKE %s', 'details LIKE %s', 'ip_address LIKE %s'];
            array_push($params, $like, $like, $like);

            // Résolution du terme vers des comptes, pour chercher « par personne »
            // sans stocker de nom dans le journal.
            $userIds = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} WHERE user_login LIKE %s OR display_name LIKE %s OR user_email LIKE %s",
                $like,
                $like,
                $like
            ));

            if ($userIds !== []) {
                $slots   = implode(',', array_fill(0, count($userIds), '%d'));
                $parts[] = "user_id IN ($slots)";
                foreach ($userIds as $id) {
                    $params[] = (int) $id;
                }
            }

            $where[] = '(' . implode(' OR ', $parts) . ')';
        }

        if ($entity !== '') {
            $where[]  = 'entity_type = %s';
            $params[] = $entity;
        }

        // Le journal est en UTC, la personne raisonne en jours de Paris : les
        // bornes sont converties, sinon une connexion à 00:30 changerait de jour.
        if ($from !== '') {
            $where[]  = 'created_at >= %s';
            $params[] = get_gmt_from_date($from . ' 00:00:00');
        }

        if ($to !== '') {
            $where[]  = 'created_at < %s';
            $params[] = get_gmt_from_date(
                gmdate('Y-m-d', (int) strtotime($to . ' +1 day')) . ' 00:00:00'
            );
        }

        return [$where === [] ? '' : 'WHERE ' . implode(' AND ', $where), $params];
    }

    /** Une date AAAA-MM-JJ valide, ou chaîne vide. */
    public static function day(string $raw): string
    {
        $raw = trim($raw);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) !== 1) {
            return '';
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $raw : '';
    }

    /**
     * Catégories présentes dans le journal, pour alimenter un filtre.
     *
     * @return list<string>
     */
    public static function entityTypes(): array
    {
        global $wpdb;

        $types = $wpdb->get_col(
            "SELECT DISTINCT entity_type FROM {$wpdb->prefix}sub_audit_log ORDER BY entity_type"
        );

        return array_values(array_filter(array_map('strval', $types)));
    }

    public static function clientIp(): ?string
    {
        $raw = $_SERVER['REMOTE_ADDR'] ?? '';

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        // On ne fait pas confiance aux en-têtes de proxy : ils sont falsifiables
        // et le site n'en a pas besoin pour l'usage qui en est fait ici.
        return filter_var($raw, FILTER_VALIDATE_IP) ?: null;
    }
}
