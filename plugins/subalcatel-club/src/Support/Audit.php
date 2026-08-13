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
