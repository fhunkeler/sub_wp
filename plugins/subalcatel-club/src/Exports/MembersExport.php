<?php

declare(strict_types=1);

namespace Subalcatel\Club\Exports;

use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Identity\ProfileFields;
use Subalcatel\Club\Policy\EligibilityPolicy;

/**
 * Exports centrés sur les membres.
 */
final class MembersExport extends Export
{
    public function key(): string
    {
        return 'members';
    }

    public function label(): string
    {
        return 'Liste des adhérents';
    }

    public function description(): string
    {
        return 'Identité, contact, niveau et état de l’adhésion. L’export courant du secrétariat.';
    }

    public function capability(): string
    {
        return 'sub_export_members';
    }

    public function columns(): array
    {
        return [
            'Nom', 'Prénom', 'Courriel', 'Téléphone mobile', 'Niveau',
            'N° licence FFESSM', 'Adhésion', 'Valable jusqu’au', 'Contact d’urgence',
        ];
    }

    public function rows(array $args = []): array
    {
        $policy = new EligibilityPolicy();
        $rows   = [];

        foreach (Members::all() as $user) {
            $membership = $policy->hasActiveMembership($user->ID);
            $level      = DiveLevels::forUser($user->ID);

            $rows[] = [
                $user->last_name ?: $user->display_name,
                $user->first_name,
                $user->user_email,
                ProfileFields::get($user->ID, 'mobile') ?: ProfileFields::get($user->ID, 'phone'),
                $level?->name ?? '',
                ProfileFields::get($user->ID, 'licence_number'),
                $membership->allowed ? 'Active' : $membership->shortLabel(),
                Members::frDate((string) get_user_meta($user->ID, 'sub_membership_valid_until', true)),
                trim(ProfileFields::get($user->ID, 'emergency_contact') . ' ' . ProfileFields::get($user->ID, 'emergency_phone')),
            ];
        }

        return $rows;
    }
}
