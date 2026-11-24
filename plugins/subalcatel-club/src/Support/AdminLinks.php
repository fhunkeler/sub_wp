<?php

declare(strict_types=1);

namespace Subalcatel\Club\Support;

/**
 * Adresses des écrans d'administration du club.
 *
 * Un courriel au bureau, un lien du tableau de bord membre : le code métier et
 * le site public ont besoin de pointer vers l'administration, sans dépendre
 * pour autant des classes d'écran. Celles-ci reprennent ces identifiants
 * (`ClubMenu::SLUG = AdminLinks::CLUB`, …) : un seul endroit les définit, et
 * les couches basses n'ont pas à connaître les couches hautes.
 */
final class AdminLinks
{
    /** Page d'accueil « Club ». */
    public const CLUB = 'subalcatel-club';

    /** Annuaire des membres. */
    public const MEMBERS = 'subalcatel-members';

    /** Onglet de l'annuaire : comptes en attente de validation. */
    public const ACCOUNTS_TAB = 'comptes';

    /** Correction d'un dossier d'adhésion. */
    public const APPLICATION_EDITOR = 'subalcatel-dossier-edit';

    public static function page(string $page, string $tab = ''): string
    {
        return admin_url('admin.php?page=' . $page . ($tab === '' ? '' : '&tab=' . $tab));
    }

    public static function application(int $applicationId): string
    {
        return admin_url(sprintf('admin.php?page=%s&application_id=%d', self::APPLICATION_EDITOR, $applicationId));
    }
}
