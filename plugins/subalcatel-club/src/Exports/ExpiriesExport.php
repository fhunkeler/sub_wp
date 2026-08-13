<?php

declare(strict_types=1);

namespace Subalcatel\Club\Exports;

/**
 * Échéances à venir : adhésions et documents.
 */
final class ExpiriesExport extends Export
{
    public function key(): string
    {
        return 'expiries';
    }

    public function label(): string
    {
        return 'Échéances à venir';
    }

    public function description(): string
    {
        return 'Adhésions et documents arrivant à terme dans les 90 jours.';
    }

    public function capability(): string
    {
        return 'sub_export_members';
    }

    public function columns(): array
    {
        return ['Nom', 'Prénom', 'Courriel', 'Objet', 'Échéance', 'Jours restants'];
    }

    public function rows(array $args = []): array
    {
        global $wpdb;

        $horizon = (int) ($args['days'] ?? 90);
        $today   = new \DateTimeImmutable(current_time('Y-m-d'));
        $limit   = $today->modify('+' . $horizon . ' days')->format('Y-m-d');
        $rows    = [];

        $applications = $wpdb->get_results($wpdb->prepare(
            "SELECT a.user_id, a.valid_until FROM {$wpdb->prefix}sub_applications a
             WHERE a.status = 'active' AND a.valid_until BETWEEN %s AND %s
             ORDER BY a.valid_until ASC",
            $today->format('Y-m-d'),
            $limit
        ), ARRAY_A) ?: [];

        foreach ($applications as $row) {
            $user = get_userdata((int) $row['user_id']);

            if (!$user) {
                continue;
            }

            $rows[] = [
                $user->last_name ?: $user->display_name,
                $user->first_name,
                $user->user_email,
                'Adhésion',
                Members::frDate((string) $row['valid_until']),
                (int) $today->diff(new \DateTimeImmutable((string) $row['valid_until']))->days,
            ];
        }

        $documents = $wpdb->get_results($wpdb->prepare(
            "SELECT d.user_id, d.valid_until, t.label
             FROM {$wpdb->prefix}sub_member_documents d
             LEFT JOIN {$wpdb->prefix}sub_document_types t ON t.slug = d.type_slug
             WHERE d.status = 'valid' AND d.valid_until BETWEEN %s AND %s
             ORDER BY d.valid_until ASC",
            $today->format('Y-m-d'),
            $limit
        ), ARRAY_A) ?: [];

        foreach ($documents as $row) {
            $user = get_userdata((int) $row['user_id']);

            if (!$user) {
                continue;
            }

            $rows[] = [
                $user->last_name ?: $user->display_name,
                $user->first_name,
                $user->user_email,
                (string) $row['label'],
                Members::frDate((string) $row['valid_until']),
                (int) $today->diff(new \DateTimeImmutable((string) $row['valid_until']))->days,
            ];
        }

        // Les échéances les plus proches en premier : c'est l'ordre dans lequel
        // le secrétariat traite.
        usort($rows, static fn (array $a, array $b): int => $a[5] <=> $b[5]);

        return $rows;
    }
}
