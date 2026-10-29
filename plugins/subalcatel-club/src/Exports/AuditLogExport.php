<?php

declare(strict_types=1);

namespace Subalcatel\Club\Exports;

use Subalcatel\Club\Support\Audit;

/**
 * Le journal d'audit, pour l'analyser dans un tableur.
 *
 * L'écran sert à retrouver une entrée ; il ne permet pas de compter, de
 * trier ou de croiser, par exemple les échecs de connexion par identifiant
 * tenté. L'export reprend exactement le filtre de l'écran (recherche,
 * catégorie, période), dans l'ordre chronologique.
 *
 * Les détails JSON restent entiers dans la dernière colonne ; les deux clés
 * qui servent le plus à l'analyse des connexions — l'identifiant tenté et
 * le navigateur — ont en plus leur propre colonne, pour se filtrer sans
 * formule.
 */
final class AuditLogExport extends Export
{
    public function key(): string
    {
        return 'audit-log';
    }

    public function label(): string
    {
        return 'Journal d’audit';
    }

    public function description(): string
    {
        return 'Actions sensibles et connexions, pour une analyse dans un tableur. '
            . 'Depuis l’onglet « Journal », l’export reprend le filtre affiché.';
    }

    /**
     * Celle de l'onglet « Journal » : l'export n'en montre pas plus que l'écran.
     */
    public function capability(): string
    {
        return 'sub_manage_event_types';
    }

    public function columns(): array
    {
        return [
            'Date (heure de Paris)', 'Auteur', 'Identifiant de l’auteur', 'Action',
            'Catégorie', 'Objet n°', 'Adresse IP', 'Identifiant tenté', 'Navigateur', 'Détails',
        ];
    }

    public function rows(array $args = []): array
    {
        $users = [];

        return array_map(static function (array $row) use (&$users): array {
            $userId = (int) ($row['user_id'] ?? 0);

            if ($userId > 0 && !array_key_exists($userId, $users)) {
                $users[$userId] = get_userdata($userId) ?: null;
            }

            $user    = $userId > 0 ? $users[$userId] : null;
            $details = json_decode((string) ($row['details'] ?? ''), true);
            $details = is_array($details) ? $details : [];
            $ts      = strtotime((string) $row['created_at']);

            return [
                // Format triable, que les tableurs reconnaissent comme une date.
                $ts === false ? '' : wp_date('Y-m-d H:i:s', $ts),
                $user?->display_name ?? ($userId > 0 ? '#' . $userId : ''),
                $user?->user_login ?? '',
                (string) $row['action'],
                (string) $row['entity_type'],
                $row['entity_id'] === null ? '' : (int) $row['entity_id'],
                (string) ($row['ip_address'] ?? ''),
                is_scalar($details['identifiant'] ?? null) ? (string) $details['identifiant'] : '',
                is_scalar($details['agent'] ?? null) ? (string) $details['agent'] : '',
                (string) ($row['details'] ?? ''),
            ];
        }, Audit::matching($args));
    }

    /**
     * Arguments de filtre acceptés, nettoyés : les mêmes que l'écran.
     *
     * @param array<string, mixed> $source
     * @return array{q: string, entity_type: string, from: string, to: string}
     */
    public static function argsFrom(array $source): array
    {
        return [
            'q'           => sanitize_text_field(wp_unslash((string) ($source['audit_q'] ?? ''))),
            'entity_type' => sanitize_key(wp_unslash((string) ($source['audit_type'] ?? ''))),
            'from'        => Audit::day(wp_unslash((string) ($source['audit_from'] ?? ''))),
            'to'          => Audit::day(wp_unslash((string) ($source['audit_to'] ?? ''))),
        ];
    }

    public function filename(): string
    {
        return sprintf('journal-audit-%s', current_time('Y-m-d'));
    }
}
