<?php

declare(strict_types=1);

namespace Subalcatel\Club\Exports;

/**
 * Registre des règlements, pour la trésorerie.
 *
 * Sert au rapprochement avec le relevé bancaire et le tableau de bord
 * HelloAsso : d'où la référence de paiement et la date d'encaissement, plutôt
 * que la seule date de saisie.
 */
final class PaymentsExport extends Export
{
    public function key(): string
    {
        return 'payments';
    }

    public function label(): string
    {
        return 'Règlements';
    }

    public function description(): string
    {
        return 'Paiements enregistrés, pour le rapprochement bancaire et HelloAsso.';
    }

    public function capability(): string
    {
        return 'sub_export_payments';
    }

    public function columns(): array
    {
        return [
            'Encaissé le', 'Dossier', 'Membre', 'Formule', 'Montant',
            'Mode', 'Référence', 'Enregistré par',
        ];
    }

    public function rows(array $args = []): array
    {
        global $wpdb;
        $p = $wpdb->prefix . 'sub_';

        $rows = $wpdb->get_results(
            "SELECT pay.*, a.reference, pl.title AS plan_title,
                    u.display_name, u.user_email
             FROM {$p}payments pay
             LEFT JOIN {$p}applications a ON a.id = pay.application_id
             LEFT JOIN {$p}plans pl ON pl.id = a.plan_id
             LEFT JOIN {$wpdb->users} u ON u.ID = pay.user_id
             ORDER BY pay.received_on DESC, pay.id DESC",
            ARRAY_A
        ) ?: [];

        $modes = [
            'cheque'    => 'Chèque',
            'helloasso' => 'HelloAsso',
            'virement'  => 'Virement',
            'especes'   => 'Espèces',
        ];

        return array_map(static function (array $row) use ($modes): array {
            $recorder = $row['recorded_by'] ? get_userdata((int) $row['recorded_by']) : null;

            return [
                Members::frDate((string) $row['received_on']),
                (string) ($row['reference'] ?? ''),
                (string) ($row['display_name'] ?? ''),
                (string) ($row['plan_title'] ?? ''),
                // Montant numérique : le tableur doit pouvoir en faire la somme.
                round((float) $row['amount'], 2),
                $modes[$row['method']] ?? (string) $row['method'],
                (string) ($row['reference'] ?? ''),
                $recorder?->display_name ?? '',
            ];
        }, $rows);
    }
}
