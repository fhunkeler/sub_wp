<?php

declare(strict_types=1);

namespace Subalcatel\Club\Membership;

/**
 * Période de grâce après l'échéance d'une adhésion.
 *
 * Une adhésion expirée l'était le jour même : l'adhérent dont le chèque de
 * renouvellement attend sur le bureau du trésorier ne pouvait plus s'inscrire
 * à la sortie du week-end. La grâce prolonge, pour un nombre de jours réglé
 * par le bureau, le droit de **participer** — s'inscrire à une sortie,
 * emprunter le matériel souscrit. Elle ne prolonge pas l'adhésion :
 *
 *  - la date de fin reste celle de la campagne, partout où elle est lue
 *    (exports FFESSM, listes de diffusion, statistiques, fiche membre) ;
 *  - ouvrir une sortie ou encadrer reste réservé aux adhésions à jour —
 *    on n'organise pas au nom du club pendant un sursis ;
 *  - les documents (certificat médical, licence) restent exigés à jour :
 *    c'est la licence qui assure le plongeur, pas la cotisation.
 *
 * Par défaut, la grâce suppose un renouvellement **déposé** sur la campagne
 * ouverte : c'est le cas qu'elle sert, celui de l'adhérent qui a fait sa part
 * et attend le bureau. Sans dossier, l'adhérent est simplement invité à le
 * déposer — le motif du refus le lui dit.
 */
final class MembershipGrace
{
    public const OPTION = 'subalcatel_membership_grace';

    public const DEFAULT_DAYS = 30;
    public const MAX_DAYS     = 90;

    /**
     * Dossiers qui montrent qu'un renouvellement est en route. Une adhésion
     * activée n'en fait pas partie : elle a déjà repoussé l'échéance.
     */
    private const RENEWAL_IN_PROGRESS = [
        ApplicationService::STATUS_SUBMITTED,
        ApplicationService::STATUS_AWAITING_PAYMENT,
        ApplicationService::STATUS_PAYMENT_CONFIRMED,
    ];

    /**
     * @return array{days: int, requires_renewal: bool}
     */
    public static function settings(): array
    {
        $stored = get_option(self::OPTION, null);

        if (!is_array($stored)) {
            return ['days' => self::DEFAULT_DAYS, 'requires_renewal' => true];
        }

        return [
            'days'             => max(0, min(self::MAX_DAYS, (int) ($stored['days'] ?? 0))),
            'requires_renewal' => (bool) ($stored['requires_renewal'] ?? true),
        ];
    }

    public static function save(int $days, bool $requiresRenewal): void
    {
        update_option(self::OPTION, [
            'days'             => max(0, min(self::MAX_DAYS, $days)),
            'requires_renewal' => $requiresRenewal,
        ], false);
    }

    /**
     * Dernier jour de grâce de cette personne, ou null si elle n'y a pas droit.
     *
     * Null aussi tant que l'adhésion court : la grâce commence le lendemain de
     * l'échéance, pas avant.
     */
    public static function until(int $userId, ?string $onDate = null): ?string
    {
        $onDate ??= current_time('Y-m-d');
        $expiry   = (string) get_user_meta($userId, 'sub_membership_valid_until', true);
        $settings = self::settings();

        if ($expiry === '' || $expiry >= $onDate || $settings['days'] === 0) {
            return null;
        }

        $last = self::lastDay($expiry, $settings['days']);

        if ($last < $onDate) {
            return null;
        }

        if ($settings['requires_renewal'] && !self::hasRenewalInProgress($userId)) {
            return null;
        }

        return $last;
    }

    /**
     * Le dernier jour de grâce qu'aurait cette personne si elle déposait son
     * renouvellement maintenant — pour le lui dire dans le motif du refus.
     */
    public static function reachableUntil(int $userId, ?string $onDate = null): ?string
    {
        $onDate ??= current_time('Y-m-d');
        $expiry   = (string) get_user_meta($userId, 'sub_membership_valid_until', true);
        $settings = self::settings();

        // Pas d'invitation à déposer un dossier quand aucune campagne n'en
        // reçoit : ce serait envoyer l'adhérent vers un formulaire fermé.
        if ($expiry === '' || $settings['days'] === 0 || !$settings['requires_renewal']
            || (new CampaignRepository())->openCampaign() === null) {
            return null;
        }

        $last = self::lastDay($expiry, $settings['days']);

        return $last >= $onDate ? $last : null;
    }

    public static function hasRenewalInProgress(int $userId): bool
    {
        $campaign = (new CampaignRepository())->openCampaign();

        if ($campaign === null) {
            return false;
        }

        $application = (new ApplicationService())->currentFor($userId, (int) $campaign['id']);

        return $application !== null
            && in_array((string) $application['status'], self::RENEWAL_IN_PROGRESS, true);
    }

    private static function lastDay(string $expiry, int $days): string
    {
        return (new \DateTimeImmutable($expiry))->modify("+{$days} days")->format('Y-m-d');
    }
}
