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

    /**
     * Recherche paginée dans le journal.
     *
     * Le terme est cherché à la fois dans l'action, les détails, l'adresse IP,
     * et — après résolution — dans l'identité de l'auteur (identifiant, nom
     * affiché, e-mail). C'est ce dernier point qui permet de taper « mathieu »
     * et de retrouver ses connexions, alors que la table ne stocke qu'un
     * `user_id`. Un filtre facultatif par catégorie (`entity_type`) isole par
     * exemple les événements de connexion (`auth`) pour déboguer le ralentisseur.
     *
     * @param array{q?: string, entity_type?: string, page?: int, per_page?: int} $args
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public static function search(array $args = []): array
    {
        global $wpdb;

        $table    = $wpdb->prefix . 'sub_audit_log';
        $q        = trim((string) ($args['q'] ?? ''));
        $entity   = trim((string) ($args['entity_type'] ?? ''));
        $perPage  = max(1, min(500, (int) ($args['per_page'] ?? 50)));
        $page     = max(1, (int) ($args['page'] ?? 1));

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

        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

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

    private static function clientIp(): ?string
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
