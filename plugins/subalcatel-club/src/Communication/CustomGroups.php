<?php

declare(strict_types=1);

namespace Subalcatel\Club\Communication;

use RuntimeException;
use Subalcatel\Club\Support\Audit;

/**
 * Groupes constitués à la main par le bureau.
 *
 * Commission bio, équipe compresseur, encadrants d'un cursus : des ensembles
 * qu'aucune règle ne sait calculer parce qu'ils relèvent d'un choix. Tout le
 * reste est dynamique — voir [MailingLists].
 */
final class CustomGroups
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}sub_mailing_groups ORDER BY label ASC",
            ARRAY_A
        ) ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $slug): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sub_mailing_groups WHERE slug = %s",
            $slug
        ), ARRAY_A);

        return $row ?: null;
    }

    public static function create(string $label, string $description = ''): int
    {
        global $wpdb;

        $label = trim($label);

        if ($label === '') {
            throw new RuntimeException('Le nom du groupe est obligatoire.');
        }

        $wpdb->insert("{$wpdb->prefix}sub_mailing_groups", [
            'slug'        => self::uniqueSlug($label),
            'label'       => $label,
            'description' => $description,
        ]);

        $id = (int) $wpdb->insert_id;

        Audit::log('mailing_group.created', 'mailing_group', $id, ['label' => $label]);

        return $id;
    }

    public static function rename(int $groupId, string $label, string $description): void
    {
        global $wpdb;

        // Le slug ne bouge pas : des envois programmés peuvent s'y référer.
        $wpdb->update(
            "{$wpdb->prefix}sub_mailing_groups",
            ['label' => trim($label), 'description' => $description],
            ['id' => $groupId]
        );
    }

    public static function delete(int $groupId): void
    {
        global $wpdb;

        $wpdb->delete("{$wpdb->prefix}sub_mailing_group_members", ['group_id' => $groupId]);
        $wpdb->delete("{$wpdb->prefix}sub_mailing_groups", ['id' => $groupId]);

        Audit::log('mailing_group.deleted', 'mailing_group', $groupId);
    }

    /**
     * @return list<int>
     */
    public static function members(string $slug): array
    {
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT m.user_id FROM {$wpdb->prefix}sub_mailing_group_members m
             INNER JOIN {$wpdb->prefix}sub_mailing_groups g ON g.id = m.group_id
             WHERE g.slug = %s",
            $slug
        ));

        return array_map('intval', $ids);
    }

    /**
     * Remplace d'un coup la composition du groupe.
     *
     * @param list<int> $userIds
     */
    public static function setMembers(int $groupId, array $userIds): void
    {
        global $wpdb;

        $wpdb->delete("{$wpdb->prefix}sub_mailing_group_members", ['group_id' => $groupId]);

        foreach (array_unique(array_map('intval', $userIds)) as $userId) {
            if ($userId > 0) {
                $wpdb->insert("{$wpdb->prefix}sub_mailing_group_members", [
                    'group_id' => $groupId,
                    'user_id'  => $userId,
                ]);
            }
        }

        Audit::log('mailing_group.members_set', 'mailing_group', $groupId, [
            'count' => count($userIds),
        ]);
    }

    /**
     * Un compte supprimé ne doit pas laisser de ligne fantôme.
     */
    public static function forgetUser(int $userId): void
    {
        global $wpdb;

        $wpdb->delete("{$wpdb->prefix}sub_mailing_group_members", ['user_id' => $userId]);
    }

    private static function uniqueSlug(string $label): string
    {
        global $wpdb;

        $base = sanitize_title($label) ?: 'groupe';
        $slug = $base;
        $i    = 2;

        while ($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sub_mailing_groups WHERE slug = %s",
            $slug
        ))) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
