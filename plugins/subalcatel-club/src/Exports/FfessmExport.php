<?php

declare(strict_types=1);

namespace Subalcatel\Club\Exports;

use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Identity\ProfileFields;
use Subalcatel\Club\Policy\EligibilityPolicy;

/**
 * État civil complet, tel que la fédération le réclame pour l'affiliation.
 *
 * C'est cet export qui explique pourquoi le Joomla rendait obligatoires le nom
 * de naissance, la ville et le département — des champs qui, sans lui,
 * paraissent inutilement intrusifs.
 */
final class FfessmExport extends Export
{
    public function key(): string
    {
        return 'ffessm';
    }

    public function label(): string
    {
        return 'Affiliation FFESSM';
    }

    public function description(): string
    {
        return 'État civil complet pour la déclaration annuelle à la fédération.';
    }

    public function capability(): string
    {
        return 'sub_export_members';
    }

    public function columns(): array
    {
        return [
            'Nom d’usage', 'Nom de naissance', 'Prénom', 'Date de naissance',
            'Ville de naissance', 'Département', 'Pays', 'Sexe',
            'Adresse', 'Code postal', 'Ville', 'Courriel', 'Téléphone',
            'Niveau', 'N° licence',
        ];
    }

    public function rows(array $args = []): array
    {
        $rows = [];

        foreach (Members::all() as $user) {
            $policy = new EligibilityPolicy();

            // Seuls les membres à jour figurent dans une déclaration
            // d'affiliation : inutile de déclarer qui n'a pas renouvelé.
            if (!$policy->hasActiveMembership($user->ID)->allowed) {
                continue;
            }

            $civility = ProfileFields::get($user->ID, 'civility');
            $level    = DiveLevels::forUser($user->ID);

            $rows[] = [
                $user->last_name ?: $user->display_name,
                ProfileFields::get($user->ID, 'birth_name'),
                $user->first_name,
                Members::frDate(ProfileFields::get($user->ID, 'birth_date')),
                ProfileFields::get($user->ID, 'birth_city'),
                ProfileFields::get($user->ID, 'birth_department'),
                ProfileFields::get($user->ID, 'birth_country'),
                match ($civility) { 'mme' => 'F', 'm' => 'M', default => '' },
                ProfileFields::get($user->ID, 'address'),
                ProfileFields::get($user->ID, 'postal_code'),
                ProfileFields::get($user->ID, 'city'),
                $user->user_email,
                ProfileFields::get($user->ID, 'mobile'),
                $level?->name ?? '',
                ProfileFields::get($user->ID, 'licence_number'),
            ];
        }

        return $rows;
    }
}
