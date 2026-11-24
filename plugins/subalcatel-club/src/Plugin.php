<?php

declare(strict_types=1);

namespace Subalcatel\Club;

use Subalcatel\Club\Membership\Admin\ApplicationEditor;
use Subalcatel\Club\Membership\Admin\ApplicationsScreen;
use Subalcatel\Club\Membership\Admin\CampaignEditor;
use Subalcatel\Club\Site\Admin\ClubMenu;
use Subalcatel\Club\Membership\Admin\CampaignsScreen;
use Subalcatel\Club\Site\Admin\DashboardWidget;
use Subalcatel\Club\Documents\Admin\DocumentsScreen;
use Subalcatel\Club\Events\Admin\EventsScreen;
use Subalcatel\Club\Exports\Admin\ExportsScreen;
use Subalcatel\Club\Identity\Admin\MembersScreen;
use Subalcatel\Club\Notifications\Admin\NotificationsScreen;
use Subalcatel\Club\Site\Admin\SettingsScreen;
use Subalcatel\Club\Setup\Admin\UpdatesScreen;
use Subalcatel\Club\Identity\Admin\AccountsScreen;
use Subalcatel\Club\Content\Admin\ClubDocumentsScreen;
use Subalcatel\Club\Communication\Admin\MailingListsScreen;
use Subalcatel\Club\Communication\Subscriptions;
use Subalcatel\Club\Content\ClubDocuments;
use Subalcatel\Club\Content\DocumentDelivery;
use Subalcatel\Club\Content\SearchIndexing;
use Subalcatel\Club\Content\Visibility;
use Subalcatel\Club\Database\Schema;
use Subalcatel\Club\Documents\DocumentTypes;
use Subalcatel\Club\Site\Frontend\AdminBar;
use Subalcatel\Club\Events\Frontend\AgendaShortcode;
use Subalcatel\Club\Ui\Assets;
use Subalcatel\Club\Events\IcalFeed;
use Subalcatel\Club\Events\Frontend\CalendarShortcode;
use Subalcatel\Club\Content\Frontend\ClubDocumentsContribute;
use Subalcatel\Club\Content\Frontend\ClubDocumentsList;
use Subalcatel\Club\Identity\Frontend\ClubFigures;
use Subalcatel\Club\Content\Frontend\MenuVisibility;
use Subalcatel\Club\Identity\Frontend\LoginForm;
use Subalcatel\Club\Content\Frontend\PermanencesShortcode;
use Subalcatel\Club\Membership\Frontend\PricingTable;
use Subalcatel\Club\Identity\Frontend\SignupForm;
use Subalcatel\Club\Content\Frontend\SiteMapShortcode;
use Subalcatel\Club\Events\Frontend\UpcomingEvents;
use Subalcatel\Club\Documents\Frontend\DocumentsForm;
use Subalcatel\Club\Site\Frontend\MemberDashboard;
use Subalcatel\Club\Membership\Frontend\MyMembership;
use Subalcatel\Club\Events\Frontend\MyRegistrations;
use Subalcatel\Club\Events\Frontend\OutingForm;
use Subalcatel\Club\Communication\Frontend\ListMessageForm;
use Subalcatel\Club\Events\Frontend\OutingRoster;
use Subalcatel\Club\Membership\Frontend\MembershipForm;
use Subalcatel\Club\Identity\Frontend\ProfileForm;
use Subalcatel\Club\Membership\Frontend\QuoteEndpoint;
use Subalcatel\Club\Policy\DerivedCapabilities;
use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Security\PasswordChange;
use Subalcatel\Club\Membership\MembershipCertificate;
use Subalcatel\Club\Setup\Updater;
use Subalcatel\Club\Security\Hardening;
use Subalcatel\Club\Security\LoginAudit;
use Subalcatel\Club\Security\LoginJournal;
use Subalcatel\Club\Security\LoginThrottle;
use Subalcatel\Club\Security\LoginUrl;
use Subalcatel\Club\Security\TwoFactorGate;
use Subalcatel\Club\Security\PasswordPolicy;
use Subalcatel\Club\Maintenance\DailyDigest;
use Subalcatel\Club\Notifications\EmailTemplates;
use Subalcatel\Club\Notifications\MailQueue;
use Subalcatel\Club\Notifications\SendQuota;
use Subalcatel\Club\Identity\Roles;
use Subalcatel\Club\Privacy\AccountDeletion;
use Subalcatel\Club\Privacy\MemberPurge;
use Subalcatel\Club\Privacy\PersonalData;
use Subalcatel\Club\Identity\LegalGuardian;
use Subalcatel\Club\Exports\ExportRegistry;
use Subalcatel\Club\Exports\AuditLogExport;
use Subalcatel\Club\Identity\Exports\MembersExport;
use Subalcatel\Club\Membership\Exports\FfessmExport;
use Subalcatel\Club\Membership\Exports\MembershipDetailExport;
use Subalcatel\Club\Membership\Exports\ExpiriesExport;
use Subalcatel\Club\Membership\Exports\PaymentsExport;
use Subalcatel\Club\Documents\Exports\MissingDocumentsExport;
use Subalcatel\Club\Communication\Exports\MailingListExport;
use Subalcatel\Club\Events\Exports\EventRosterExport;

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
        MailQueue::unregister();

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

        // Consigne les connexions (réussies et échouées) dans le journal du
        // club, et refuse un mot de passe proche de l'identité ou déjà fuité,
        // y compris sur les écrans natifs de réinitialisation et de profil.
        LoginAudit::register();
        LoginJournal::register();
        PasswordPolicy::register();

        // Impose le second facteur aux comptes qui voient les données des
        // adhérents. Inerte tant que l'extension `two-factor` n'est pas active :
        // elle seule sait en poser un, et une exigence sans moyen de s'y
        // conformer ferme l'administration à tout le monde.
        TwoFactorGate::register();

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
        add_action('admin_init', [\Subalcatel\Club\Events\EventTypeSeeder::class, 'backfillVisibility']);

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
        MembershipCertificate::register();
        MyRegistrations::register();
        OutingForm::register();
        OutingRoster::register();
        ListMessageForm::register();
        DailyDigest::register();
        SendQuota::register();
        MailQueue::register();
        ClubDocumentsList::register();
        ClubDocumentsContribute::register();
        CalendarShortcode::register();
        SiteMapShortcode::register();
        UpcomingEvents::register();
        LoginForm::register();
        SignupForm::register();
        PricingTable::register();
        PermanencesShortcode::register();
        ClubFigures::register();
        MenuVisibility::register();
        AdminBar::register();
        IcalFeed::register();
        DocumentDelivery::register();
        Visibility::register();
        SearchIndexing::register();
        Subscriptions::register();
        LegalGuardian::register();
        PersonalData::register();
        AccountDeletion::register();

        // Le menu du bureau est déclaré d'un seul tenant par ClubMenu ; les
        // écrans n'enregistrent plus ici que leurs actions de formulaire.
        // L'ordre de ces lignes n'a donc plus d'incidence sur le menu.
        ClubMenu::register();

        // Le rappel sur le tableau de bord de WordPress : c'est là qu'arrive le
        // bureau en se connectant, pas sur l'écran « Club ».
        DashboardWidget::register();

        ApplicationsScreen::register();
        ApplicationEditor::register();
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

        // Chaque export vit dans son domaine ; l'ordre est celui de l'écran.
        ExportRegistry::add(
            MembersExport::class,
            FfessmExport::class,
            MembershipDetailExport::class,
            MissingDocumentsExport::class,
            ExpiriesExport::class,
            PaymentsExport::class,
            EventRosterExport::class,
            MailingListExport::class,
            AuditLogExport::class,
        );
        SettingsScreen::register();
        UpdatesScreen::register();
    }
}
