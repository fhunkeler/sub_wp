<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

use Subalcatel\Club\Content\ClubDocuments;
use Subalcatel\Club\Documents\DocumentService;
use Subalcatel\Club\Identity\AccountApproval;
use Subalcatel\Club\Identity\Roles;

/**
 * Le menu « Club », déclaré en un seul endroit.
 *
 * Chaque écran déclarait auparavant sa propre entrée, avec une priorité
 * `admin_menu` choisie pour se placer au bon rang. L'ordre du menu se lisait
 * donc en ouvrant douze fichiers, personne ne le voyait en entier, et une
 * entrée nouvelle atterrissait n'importe où. Il se lit maintenant ci-dessous,
 * dans l'ordre où il s'affiche.
 *
 * Le regroupement suit l'objet, pas le geste : tout ce qui concerne une
 * personne est sous « Membres » — son compte, sa fiche, ses pièces —, tout ce
 * qui concerne son adhésion sous « Adhésions ». Les écrans restent des classes
 * distinctes ; ce sont des onglets seulement à l'affichage.
 */
final class ClubMenu
{
    public const SLUG = 'subalcatel-club';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'build']);
    }

    public static function build(): void
    {
        // Un adhérent ordinaire n'a rien à faire dans le bureau : pas de menu
        // du tout, plutôt qu'un menu dont chaque entrée refuserait l'accès.
        if (!self::hasAnyClubCapability()) {
            return;
        }

        add_menu_page(
            'Club',
            'Club',
            'read',
            self::SLUG,
            [DashboardScreen::class, 'render'],
            'dashicons-groups',
            3
        );

        // Le premier sous-menu porte le slug du parent, sinon WordPress
        // duplique l'entrée. C'est donc lui qui décide de l'écran d'accueil —
        // le tableau de bord, dont c'est le rôle d'orienter.
        //
        // Il ne s'appelle pas « Tableau de bord » pour autant : le menu « Club »
        // est en position 3, soit juste sous le tableau de bord de WordPress.
        // Deux entrées du même nom, l'une sous l'autre, ne se distinguent que
        // par leur cible. « Vue d'ensemble » dit la même chose sans la collision.
        add_submenu_page(
            self::SLUG,
            'Vue d’ensemble',
            'Vue d’ensemble',
            'read',
            self::SLUG,
            [DashboardScreen::class, 'render']
        );

        self::add(
            'Membres',
            MembersScreen::SLUG,
            [MembersScreen::class, 'render'],
            MembersScreen::CAPABILITIES,
            self::pendingForMembers()
        );

        self::add(
            'Adhésions',
            ApplicationsScreen::SLUG,
            [ApplicationsScreen::class, 'render'],
            ApplicationsScreen::CAPABILITIES
        );

        self::add('Événements', EventsScreen::SLUG, [EventsScreen::class, 'render'], ['read']);

        self::add(
            'Communication',
            CommunicationScreen::SLUG,
            [CommunicationScreen::class, 'render'],
            CommunicationScreen::CAPABILITIES
        );

        self::addClubDocuments();

        // Statistiques et exports voisinent : deux écrans dont on ressort avec
        // un chiffre ou un fichier, jamais avec une décision à prendre.
        self::add(
            'Statistiques',
            StatisticsScreen::SLUG,
            [StatisticsScreen::class, 'render'],
            StatisticsScreen::CAPABILITIES
        );

        self::add('Exports', ExportsScreen::SLUG, [ExportsScreen::class, 'render'], ['read']);

        self::add(
            'Réglages',
            SettingsScreen::SLUG,
            [SettingsScreen::class, 'render'],
            SettingsScreen::CAPABILITIES
        );

        self::addHidden();
    }

    /**
     * Une entrée de menu ouverte à qui détient l'une des capacités.
     *
     * WordPress n'en accepte qu'une par entrée, alors qu'un écran à onglets en
     * sert plusieurs. On lui passe donc celle que la personne détient
     * réellement : l'entrée disparaît pour les autres, sans recourir à `read`,
     * qui l'aurait montrée à tout le monde.
     *
     * @param list<string> $capabilities
     */
    private static function add(
        string $label,
        string $slug,
        callable $render,
        array $capabilities,
        int $count = 0,
    ): void {
        $granted = self::firstHeld($capabilities);

        if ($granted === null) {
            return;
        }

        add_submenu_page(
            self::SLUG,
            $label,
            $label . AdminUi::countBubble($count),
            $granted,
            $slug,
            $render
        );
    }

    /**
     * @param list<string> $capabilities
     */
    private static function firstHeld(array $capabilities): ?string
    {
        foreach ($capabilities as $capability) {
            if (current_user_can($capability)) {
                return $capability;
            }
        }

        return null;
    }

    /**
     * Les documents du club gardent les écrans natifs de WordPress : liste,
     * recherche et catégories y sont déjà, et mieux faits qu'ils ne le seraient
     * à la main.
     */
    private static function addClubDocuments(): void
    {
        if (!current_user_can('sub_manage_content')) {
            return;
        }

        add_submenu_page(
            self::SLUG,
            'Documents du club',
            'Documents du club',
            'sub_manage_content',
            'edit.php?post_type=' . ClubDocuments::POST_TYPE
        );

        add_submenu_page(
            self::SLUG,
            'Catégories de documents',
            '— Catégories',
            'sub_manage_content',
            'edit-tags.php?taxonomy=' . ClubDocuments::TAXONOMY . '&post_type=' . ClubDocuments::POST_TYPE
        );
    }

    /**
     * Écrans atteints depuis un autre écran, jamais depuis le menu.
     *
     * Le parent vide les enregistre sans les afficher : sans cela, `admin.php`
     * refuserait la page, faute de la connaître.
     */
    private static function addHidden(): void
    {
        foreach ([
            [CampaignEditor::SLUG, 'Configurer la campagne', [CampaignEditor::class, 'render'], 'sub_manage_memberships'],
            [EventsScreen::SLUG_ROSTER, 'Inscrits', [EventsScreen::class, 'renderRoster'], 'read'],
        ] as [$slug, $title, $render, $capability]) {
            add_submenu_page('', $title, $title, $capability, $slug, $render);
        }
    }

    /**
     * Ce qui attend une décision derrière l'entrée « Membres ».
     *
     * Seules les files que la personne peut traiter sont comptées : annoncer
     * trois pièces à valider à qui n'a pas le droit de les valider n'appelle
     * aucune action, et le compteur perdrait tout crédit.
     */
    private static function pendingForMembers(): int
    {
        $count = 0;

        if (current_user_can('sub_validate_account')) {
            $count += AccountApproval::pendingCount();
        }

        if (current_user_can('sub_validate_member_document')) {
            $count += count((new DocumentService())->pendingReview());
        }

        return $count;
    }

    /**
     * Cette personne exerce-t-elle une responsabilité au club ?
     *
     * Aucune capacité ne convient seule : le trésorier n'a pas celle du
     * secrétariat, le responsable des sorties n'a ni l'une ni l'autre. C'est
     * donc « au moins une capacité `sub_*` » — ce qui exclut l'adhérent
     * ordinaire, qui n'a rien à faire dans le menu du bureau.
     */
    public static function hasAnyClubCapability(): bool
    {
        foreach (array_keys(Roles::CAPABILITIES) as $capability) {
            if (current_user_can($capability)) {
                return true;
            }
        }

        return false;
    }
}
