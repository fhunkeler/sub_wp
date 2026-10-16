<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

/**
 * Pas de barre d'administration WordPress sur le site public.
 *
 * Un adhérent connecté voyait en haut de chaque page une bande noire aux
 * libellés de WordPress — « Modifier le profil », le logo, un champ de
 * recherche — qui ne lui sert à rien et double l'en-tête du club. Tout ce
 * qu'elle offrait a déjà sa place sur le site : « Mon espace » et
 * « Déconnexion » dans l'en-tête, l'entrée « Administration du club » sur le
 * tableau de bord de qui siège au bureau ({@see MemberDashboard}).
 *
 * Elle disparaît pour tout le monde, administrateurs compris : le bureau a
 * choisi un site identique pour tous plutôt qu'un raccourci réservé à
 * quelques comptes. Dans wp-admin, WordPress l'affiche toujours ; ce filtre
 * ne la touche pas.
 *
 * La case « Afficher la barre d'outils » du profil n'a plus d'effet : elle est
 * masquée, pour ne pas laisser croire qu'elle en a un.
 */
final class AdminBar
{
    public static function register(): void
    {
        add_filter('show_admin_bar', '__return_false');
        add_action('admin_head-profile.php', [self::class, 'hideProfileOption']);
        add_action('admin_head-user-edit.php', [self::class, 'hideProfileOption']);
    }

    public static function hideProfileOption(): void
    {
        echo '<style>.user-admin-bar-front-wrap{display:none}</style>';
    }
}
