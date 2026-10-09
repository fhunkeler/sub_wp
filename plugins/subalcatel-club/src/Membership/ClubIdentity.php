<?php

declare(strict_types=1);

namespace Subalcatel\Club\Membership;

/**
 * Ce que le club écrit en tête de ses reçus et attestations.
 *
 * Un comité d'entreprise ou une mutuelle qui reçoit une attestation veut
 * savoir qui l'émet : la raison sociale, une adresse, le numéro d'affiliation
 * à la fédération. Rien de cela n'était saisi sur le site — le nom du site
 * n'est pas celui de l'association, et l'adresse d'envoi des chèques change
 * avec le trésorier. Trois champs, tenus depuis les Réglages.
 */
final class ClubIdentity
{
    public const OPTION = 'subalcatel_club_identity';

    /**
     * @return array{name: string, address: string, affiliation: string}
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        $name = trim((string) ($stored['name'] ?? ''));

        return [
            // Le nom du site en attendant mieux : un reçu sans émetteur ne
            // vaut rien, un reçu au nom du site vaut presque toujours juste.
            'name'        => $name !== '' ? $name : (string) get_bloginfo('name'),
            'address'     => trim((string) ($stored['address'] ?? '')),
            'affiliation' => trim((string) ($stored['affiliation'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function save(array $input): void
    {
        update_option(self::OPTION, [
            'name'        => sanitize_text_field((string) ($input['name'] ?? '')),
            'address'     => sanitize_textarea_field((string) ($input['address'] ?? '')),
            'affiliation' => sanitize_text_field((string) ($input['affiliation'] ?? '')),
        ], false);
    }

    /**
     * L'adresse est-elle saisie ? Sans elle, le bureau est prévenu dans
     * l'écran des réglages — les documents restent délivrés.
     */
    public static function isComplete(): bool
    {
        return self::all()['address'] !== '';
    }
}
