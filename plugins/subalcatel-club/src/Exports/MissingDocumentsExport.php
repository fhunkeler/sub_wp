<?php

declare(strict_types=1);

namespace Subalcatel\Club\Exports;

use Subalcatel\Club\Documents\DocumentService;
use Subalcatel\Club\Identity\ProfileFields;

/**
 * Qui n'a pas déposé quoi, et depuis quand.
 *
 * L'export le plus utile au secrétariat : il transforme une relance générale
 * en liste d'appels ciblés.
 */
final class MissingDocumentsExport extends Export
{
    public function key(): string
    {
        return 'missing-documents';
    }

    public function label(): string
    {
        return 'Documents manquants';
    }

    public function description(): string
    {
        return 'Membres dont un document obligatoire est absent, expiré ou en attente de validation.';
    }

    public function capability(): string
    {
        return 'sub_export_members';
    }

    public function columns(): array
    {
        return ['Nom', 'Prénom', 'Courriel', 'Téléphone', 'Situation', 'Documents concernés'];
    }

    public function rows(array $args = []): array
    {
        $documents = new DocumentService();
        $rows      = [];

        foreach (Members::all() as $user) {
            $status = $documents->statusFor($user->ID);

            $situations = [];

            if ($status['missing'] !== []) {
                $situations[] = ['Non déposé', implode(', ', $status['missing'])];
            }

            if ($status['expired'] !== []) {
                $situations[] = ['Expiré', implode(', ', array_map(
                    static fn (array $d): string => $d['label'] . ' (' . Members::frDate($d['date']) . ')',
                    $status['expired']
                ))];
            }

            if ($status['pending'] !== []) {
                $situations[] = ['En attente de validation', implode(', ', $status['pending'])];
            }

            foreach ($situations as [$situation, $labels]) {
                $rows[] = [
                    $user->last_name ?: $user->display_name,
                    $user->first_name,
                    $user->user_email,
                    ProfileFields::get($user->ID, 'mobile'),
                    $situation,
                    $labels,
                ];
            }
        }

        return $rows;
    }
}
