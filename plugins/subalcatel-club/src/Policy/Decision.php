<?php

declare(strict_types=1);

namespace Subalcatel\Club\Policy;

/**
 * Résultat d'un contrôle de droit : autorisé ou non, et pourquoi.
 *
 * Deux façons de dire la même chose, parce qu'il y a deux publics :
 *
 * - `reason` s'adresse au MEMBRE, à la deuxième personne, avec les dates :
 *   « Votre certificat médical a expiré le 15/03/2027. »
 * - `code` s'adresse au CODE, pour que le bureau affiche son propre libellé
 *   court dans une liste — « Votre » n'a pas de sens quand on regarde la fiche
 *   de quelqu'un d'autre.
 */
final class Decision
{
    public const ACCOUNT_PENDING    = 'account_pending';
    public const ACCOUNT_REFUSED    = 'account_refused';
    public const NO_MEMBERSHIP      = 'no_membership';
    public const MEMBERSHIP_EXPIRED = 'membership_expired';
    public const DOCUMENT_MISSING   = 'document_missing';
    public const DOCUMENT_EXPIRED   = 'document_expired';
    public const LEVEL_MISSING      = 'level_missing';
    public const LEVEL_TOO_LOW      = 'level_too_low';
    public const OPTION_MISSING     = 'option_missing';
    public const REGISTRATION_CLOSED = 'registration_closed';
    public const EVENT_FULL         = 'event_full';
    public const NOT_FOUND          = 'not_found';

    /**
     * Libellés courts, à la troisième personne, pour les écrans du bureau.
     */
    private const SHORT_LABELS = [
        self::ACCOUNT_PENDING     => 'Compte à valider',
        self::ACCOUNT_REFUSED     => 'Compte refusé',
        self::NO_MEMBERSHIP       => 'Aucune adhésion',
        self::MEMBERSHIP_EXPIRED  => 'Adhésion expirée',
        self::DOCUMENT_MISSING    => 'Document manquant',
        self::DOCUMENT_EXPIRED    => 'Document expiré',
        self::LEVEL_MISSING       => 'Niveau non renseigné',
        self::LEVEL_TOO_LOW       => 'Niveau insuffisant',
        self::OPTION_MISSING      => 'Option non souscrite',
        self::REGISTRATION_CLOSED => 'Inscriptions closes',
        self::EVENT_FULL          => 'Complet',
        self::NOT_FOUND           => 'Introuvable',
    ];

    private function __construct(
        public readonly bool $allowed,
        public readonly string $reason = '',
        public readonly string $code = '',
    ) {
    }

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(string $reason, string $code = ''): self
    {
        return new self(false, $reason, $code);
    }

    /**
     * Libellé court destiné aux listes du bureau.
     */
    public function shortLabel(): string
    {
        return self::SHORT_LABELS[$this->code] ?? 'Non conforme';
    }
}
