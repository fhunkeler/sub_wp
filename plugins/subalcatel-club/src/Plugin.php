<?php

declare(strict_types=1);

namespace Subalcatel\Club;

use Subalcatel\Club\Admin\ApplicationsScreen;
use Subalcatel\Club\Admin\CampaignEditor;
use Subalcatel\Club\Admin\ClubMenu;
use Subalcatel\Club\Admin\CampaignsScreen;
use Subalcatel\Club\Admin\DocumentsScreen;
use Subalcatel\Club\Admin\EventsScreen;
use Subalcatel\Club\Admin\ExportsScreen;
use Subalcatel\Club\Admin\MembersScreen;
use Subalcatel\Club\Admin\NotificationsScreen;
use Subalcatel\Club\Admin\SettingsScreen;
use Subalcatel\Club\Admin\AccountsScreen;
use Subalcatel\Club\Admin\ClubDocumentsScreen;
use Subalcatel\Club\Admin\MailingListsScreen;
use Subalcatel\Club\Communication\Subscriptions;
use Subalcatel\Club\Content\ClubDocuments;
use Subalcatel\Club\Content\DocumentDelivery;
use Subalcatel\Club\Content\Visibility;
use Subalcatel\Club\Database\Schema;
use Subalcatel\Club\Documents\DocumentTypes;
use Subalcatel\Club\Frontend\AgendaShortcode;
use Subalcatel\Club\Frontend\Assets;
use Subalcatel\Club\Events\IcalFeed;
use Subalcatel\Club\Frontend\CalendarShortcode;
use Subalcatel\Club\Frontend\ClubDocumentsList;
use Subalcatel\Club\Frontend\MenuVisibility;
use Subalcatel\Club\Frontend\LoginForm;
use Subalcatel\Club\Frontend\PricingTable;
use Subalcatel\Club\Frontend\SignupForm;
use Subalcatel\Club\Frontend\SiteMapShortcode;
use Subalcatel\Club\Frontend\UpcomingEvents;
use Subalcatel\Club\Frontend\DocumentsForm;
use Subalcatel\Club\Frontend\MemberDashboard;
use Subalcatel\Club\Frontend\MyMembership;
use Subalcatel\Club\Frontend\MyRegistrations;
use Subalcatel\Club\Frontend\OutingForm;
use Subalcatel\Club\Frontend\OutingRoster;
use Subalcatel\Club\Frontend\MembershipForm;
use Subalcatel\Club\Frontend\ProfileForm;
use Subalcatel\Club\Frontend\QuoteEndpoint;
use Subalcatel\Club\Identity\DerivedCapabilities;
use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Identity\PasswordChange;
use Subalcatel\Club\Setup\Updater;
use Subalcatel\Club\Support\Hardening;
use Subalcatel\Club\Support\LoginThrottle;
use Subalcatel\Club\Support\LoginUrl;
use Subalcatel\Club\Notifications\DailyDigest;
use Subalcatel\Club\Notifications\EmailTemplates;
use Subalcatel\Club\Identity\Roles;
use Subalcatel\Club\Privacy\AccountDeletion;
use Subalcatel\Club\Privacy\MemberPurge;
use Subalcatel\Club\Privacy\PersonalData;

/**
 * Point d'entrée du plugin.
 *
 * Ne contient aucune règle métier : il câble les modules et rien d'autre.
 */
final class Plugin
{
    /**
     * Appelé à l'activation, une seule fois.
     */
    public static function activate(): void
    {
        Roles::install();
        DiveLevels::register();
        DiveLevels::seed();
        Schema::migrate();
        DocumentTypes::seed();
        EmailTemplates::seedIfNeeded();
        ClubDocuments::register();
        ClubDocuments::seedCategories();

        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        DailyDigest::unregister();

        // Les rôles et les données sont conservés : désactiver n'est pas désinstaller.
        flush_rewrite_rules();
    }

    /**
     * Appelé à chaque requête.
     */
    public static function boot(): void
    {
        // Durcissement en premier : cacher la version, couper XML-RPC et poser
        // les en-têtes doit s'appliquer avant tout rendu.
        Hardening::register();
        LoginThrottle::register();

        // Signale les versions publiées sur le dépôt du club, et ferme au
        // passage la porte des mises à jour venues de wordpress.org par
        // collision de nom de dossier. N'installe rien de lui-même.
        Updater::register();

        // Après le ralentisseur, et pour la même porte : celui-ci ralentit les
        // tentatives, celui-là déplace la serrure. Enregistré ici parce qu'il
        // doit s'accrocher à `plugins_loaded`, donc avant toute résolution.
        LoginUrl::register();

        // Avant tout écran : les droits déduits du niveau de plongée doivent
        // être en place quand le premier `current_user_can` est évalué.
        DerivedCapabilities::register();

        // Sur `init`, jamais sur `plugins_loaded` : register_taxonomy a besoin
        // de $wp_rewrite, qui n'existe pas encore à ce stade du chargement.
        add_action('init', [DiveLevels::class, 'register']);
        add_action('init', [ClubDocuments::class, 'register']);

        // Rattrapage : si le plugin a été mis à jour par copie de fichiers,
        // l'activation n'est pas rejouée. On vérifie donc le schéma ici.
        add_action('admin_init', [Schema::class, 'migrateIfNeeded']);
        add_action('admin_init', [Roles::class, 'refreshIfNeeded']);
        add_action('admin_init', [EmailTemplates::class, 'seedIfNeeded']);
        add_action('admin_init', [\Subalcatel\Club\Events\EventTypeSeeder::class, 'backfillSharedFields']);

        // Un compte supprimé depuis l'écran natif de WordPress ne doit pas
        // laisser d'inscription, de document ni de dossier derrière lui.
        MemberPurge::register();

        Assets::register();
        MembershipForm::register();
        QuoteEndpoint::register();
        AgendaShortcode::register();
        ProfileForm::register();
        PasswordChange::register();
        DocumentsForm::register();
        MemberDashboard::register();
        MyMembership::register();
        MyRegistrations::register();
        OutingForm::register();
        OutingRoster::register();
        DailyDigest::register();
        ClubDocumentsList::register();
        CalendarShortcode::register();
        SiteMapShortcode::register();
        UpcomingEvents::register();
        LoginForm::register();
        SignupForm::register();
        PricingTable::register();
        MenuVisibility::register();
        IcalFeed::register();
        DocumentDelivery::register();
        Visibility::register();
        Subscriptions::register();
        PersonalData::register();
        AccountDeletion::register();

        // Le menu du bureau est déclaré d'un seul tenant par ClubMenu ; les
        // écrans n'enregistrent plus ici que leurs actions de formulaire.
        // L'ordre de ces lignes n'a donc plus d'incidence sur le menu.
        ClubMenu::register();

        ApplicationsScreen::register();
        AccountsScreen::register();
        ClubDocumentsScreen::register();
        MailingListsScreen::register();
        CampaignsScreen::register();
        CampaignEditor::register();
        EventsScreen::register();
        MembersScreen::register();
        DocumentsScreen::register();
        NotificationsScreen::register();
        ExportsScreen::register();
        SettingsScreen::register();
    }
}
