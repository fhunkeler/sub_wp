<?php

declare(strict_types=1);

namespace Subalcatel\Club\Documents;

/**
 * Types de documents personnels.
 *
 * Un seul circuit technique, des règles portées par le type : chiffrement,
 * visibilité, durée de conservation, blocage des plongées. Ajouter un type ne
 * demande donc aucun développement — c'est le principe de configurabilité
 * appliqué à un domaine sensible.
 */
final class DocumentTypes
{
    public const MEDICAL = 'certificat-medical';

    public const REQUIRED_ALWAYS = 'always';
    public const REQUIRED_MINOR  = 'minor';
    public const REQUIRED_NEVER  = 'never';

    /**
     * Seul type installé au démarrage : le certificat médical.
     *
     * C'est le seul document dont le club a réellement besoin, et le seul qui
     * relève de l'article 9 du RGPD — d'où le chiffrement et la journalisation.
     * Le bureau crée les autres types depuis l'interface s'il en apparaît le
     * besoin : la mécanique ne demande aucun développement.
     *
     * @return list<array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            [
                'slug'             => self::MEDICAL,
                'label'            => 'Certificat médical',
                'help'             => 'Certificat de non contre-indication à la plongée, daté de moins d’un an.',
                'is_required'      => 1,
                'required_when'    => self::REQUIRED_ALWAYS,
                'encrypted'        => 1,
                'log_access'       => 1,
                'view_capability'  => 'sub_view_medical_certificate',
                'blocks_dives'     => 1,
                'has_validity'     => 1,
                'validity_months'  => 12,
                'purge_delay_days' => 30,
                'reminder_days'    => '30',
                'needs_validation' => 1,
                'ordering'         => 10,
            ],
        ];
    }

    /**
     * Crée un type. Le slug est dérivé du libellé et ne changera plus : les
     * documents déjà déposés s'y réfèrent.
     *
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'sub_document_types';
        $slug  = self::uniqueSlug((string) $data['label']);

        $wpdb->insert($table, array_merge([
            'slug'             => $slug,
            'label'            => (string) $data['label'],
            'help'             => '',
            'is_required'      => 0,
            'required_when'    => self::REQUIRED_NEVER,
            'encrypted'        => 0,
            'log_access'       => 0,
            'view_capability'  => 'sub_manage_memberships',
            'blocks_dives'     => 0,
            'has_validity'     => 1,
            'validity_months'  => 12,
            'purge_delay_days' => 30,
            'reminder_days'    => '30',
            'needs_validation' => 1,
            'ordering'         => 100,
            'published'        => 1,
        ], $data, ['slug' => $slug]));

        return (int) $wpdb->insert_id;
    }

    /**
     * Retire un type, à condition qu'aucun document ne s'y rattache.
     *
     * Un type utilisé n'est jamais supprimé mais dépublié : sinon des documents
     * déposés perdraient leurs règles de conservation.
     *
     * @return string '' si supprimé, sinon le motif du dépublication
     */
    public static function remove(int $typeId): string
    {
        global $wpdb;

        $slug = $wpdb->get_var($wpdb->prepare(
            "SELECT slug FROM {$wpdb->prefix}sub_document_types WHERE id = %d",
            $typeId
        ));

        if ($slug === null) {
            return 'Type introuvable.';
        }

        $used = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sub_member_documents WHERE type_slug = %s",
            $slug
        ));

        if ($used > 0) {
            $wpdb->update("{$wpdb->prefix}sub_document_types", ['published' => 0], ['id' => $typeId]);

            return sprintf(
                'Type retiré des formulaires : %d document(s) s’y rattachent et gardent leurs règles.',
                $used
            );
        }

        $wpdb->delete("{$wpdb->prefix}sub_document_types", ['id' => $typeId]);

        return '';
    }

    private static function uniqueSlug(string $label): string
    {
        global $wpdb;

        $base = sanitize_title($label) ?: 'document';
        $slug = $base;
        $i    = 2;

        while ($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sub_document_types WHERE slug = %s",
            $slug
        ))) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    public static function seed(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sub_document_types';

        foreach (self::defaults() as $type) {
            $exists = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$table} WHERE slug = %s", $type['slug'])
            );

            if (!$exists) {
                $wpdb->insert($table, $type);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(bool $publishedOnly = true): array
    {
        global $wpdb;

        $sql = "SELECT * FROM {$wpdb->prefix}sub_document_types";

        if ($publishedOnly) {
            $sql .= ' WHERE published = 1';
        }

        return $wpdb->get_results($sql . ' ORDER BY ordering ASC, id ASC', ARRAY_A) ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $slug): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}sub_document_types WHERE slug = %s", $slug),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Ce type est-il exigé de ce membre ?
     *
     * L'autorisation parentale ne concerne que les mineurs : la question se
     * pose donc au cas par cas, pas une fois pour toutes.
     *
     * @param array<string, mixed> $type
     */
    public static function isRequiredFor(array $type, int $userId): bool
    {
        if ((int) $type['is_required'] !== 1) {
            return false;
        }

        return match ($type['required_when']) {
            self::REQUIRED_MINOR => self::isMinor($userId),
            self::REQUIRED_NEVER => false,
            default              => true,
        };
    }

    /**
     * Le membre est-il mineur à la date du jour ?
     *
     * Sans date de naissance renseignée, on répond non : mieux vaut ne pas
     * exiger un document à tort que bloquer un adhérent majeur.
     */
    public static function isMinor(int $userId, ?string $onDate = null): bool
    {
        $birthDate = (string) get_user_meta($userId, 'sub_birth_date', true);

        if ($birthDate === '') {
            return false;
        }

        // Une seule source de vérité pour la minorité : le calcul vit dans
        // LegalGuardian, qui gère aussi le représentant et la majorité.
        return \Subalcatel\Club\Identity\LegalGuardian::isMinor($userId, $onDate);
    }
}
