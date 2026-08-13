<?php

declare(strict_types=1);

namespace Subalcatel\Club\Database;

use Subalcatel\Club\Privacy\MemberPurge;

/**
 * Schéma des tables métier, versionné.
 *
 * dbDelta est capricieux : deux espaces après PRIMARY KEY, types en minuscules,
 * une clé par ligne. Ne pas reformater sans revérifier que les tables sont
 * toujours créées.
 */
final class Schema
{
    private const VERSION_OPTION = 'subalcatel_club_db_version';
    private const VERSION        = 9;

    /**
     * Colonnes qui désignent la personne concernée par la ligne.
     *
     * Elles doivent accepter NULL : c'est ainsi qu'une ligne conservée cesse de
     * désigner quelqu'un quand le compte disparaît (cf. [MemberPurge]). Zéro ne
     * ferait pas l'affaire — la clé UNIQUE (event_id,user_id) n'en tolérerait
     * qu'un seul par sortie, alors que MySQL considère deux NULL comme
     * distincts.
     */
    private const SUBJECT_COLUMNS = [
        'event_registrations' => 'user_id',
        'applications'        => 'user_id',
        'payments'            => 'user_id',
        'events'              => 'organizer_id',
        'notification_log'    => 'recipient_id',
    ];

    public static function migrateIfNeeded(): void
    {
        if ((int) get_option(self::VERSION_OPTION, 0) < self::VERSION) {
            self::migrate();
        }
    }

    public static function migrate(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix . 'sub_';

        // --- Socle -----------------------------------------------------------

        dbDelta("CREATE TABLE {$p}audit_log (
            id bigint(20) unsigned NOT NULL auto_increment,
            user_id bigint(20) unsigned default NULL,
            action varchar(100) NOT NULL,
            entity_type varchar(100) NOT NULL,
            entity_id bigint(20) unsigned default NULL,
            details longtext,
            ip_address varchar(45) default NULL,
            created_at datetime NOT NULL default CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_created (user_id,created_at),
            KEY entity (entity_type,entity_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$p}dive_level_history (
            id bigint(20) unsigned NOT NULL auto_increment,
            user_id bigint(20) unsigned NOT NULL,
            level_term_id bigint(20) unsigned NOT NULL,
            obtained_on date default NULL,
            recorded_by bigint(20) unsigned default NULL,
            created_at datetime NOT NULL default CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_obtained (user_id,obtained_on)
        ) {$charset};");

        // --- Adhésions -------------------------------------------------------

        // Une campagne porte les dates ET les tarifs de l'année. Dupliquer une
        // campagne suffit à ouvrir la suivante, sans toucher à l'historique.
        dbDelta("CREATE TABLE {$p}campaigns (
            id bigint(20) unsigned NOT NULL auto_increment,
            title varchar(190) NOT NULL,
            slug varchar(190) NOT NULL,
            opens_on date NOT NULL,
            closes_on date NOT NULL,
            valid_from date NOT NULL,
            valid_until date NOT NULL,
            reminder_days varchar(100) NOT NULL default '30',
            status varchar(20) NOT NULL default 'draft',
            created_at datetime NOT NULL default CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY status (status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$p}plans (
            id bigint(20) unsigned NOT NULL auto_increment,
            campaign_id bigint(20) unsigned NOT NULL,
            title varchar(190) NOT NULL,
            slug varchar(190) NOT NULL,
            description longtext,
            base_price decimal(10,2) NOT NULL default 0.00,
            published tinyint(1) NOT NULL default 1,
            ordering int(11) NOT NULL default 0,
            PRIMARY KEY  (id),
            KEY campaign_slug (campaign_id,slug)
        ) {$charset};");

        // Une option = une question posée à l'adhérent, avec un impact tarifaire.
        // `choices`, `condition`, `grants` et `plans` sont du JSON : ce sont des
        // listes courtes, lues en bloc, jamais requêtées ligne à ligne.
        dbDelta("CREATE TABLE {$p}options (
            id bigint(20) unsigned NOT NULL auto_increment,
            campaign_id bigint(20) unsigned NOT NULL,
            name varchar(100) NOT NULL,
            label varchar(190) NOT NULL,
            help text,
            input_type varchar(20) NOT NULL default 'single',
            is_required tinyint(1) NOT NULL default 0,
            choices longtext,
            condition_option varchar(100) default NULL,
            condition_values longtext,
            grants longtext,
            plans longtext,
            ordering int(11) NOT NULL default 0,
            PRIMARY KEY  (id),
            UNIQUE KEY campaign_name (campaign_id,name),
            KEY ordering (ordering)
        ) {$charset};");

        // Remise = un forfait + des réductions par option. Pas de formules :
        // deux champs qu'un bénévole sait remplir (cf. §2 bis de la proposition).
        dbDelta("CREATE TABLE {$p}discount_rules (
            id bigint(20) unsigned NOT NULL auto_increment,
            campaign_id bigint(20) unsigned NOT NULL,
            label varchar(190) NOT NULL,
            condition_option varchar(100) NOT NULL,
            condition_values longtext,
            flat_amount decimal(10,2) NOT NULL default 0.00,
            per_option longtext,
            plans longtext,
            ordering int(11) NOT NULL default 0,
            PRIMARY KEY  (id),
            KEY campaign (campaign_id)
        ) {$charset};");

        // `user_id` accepte NULL : le dossier survit au compte, parce que c'est
        // une pièce comptable, mais il cesse alors de désigner quelqu'un.
        dbDelta("CREATE TABLE {$p}applications (
            id bigint(20) unsigned NOT NULL auto_increment,
            reference varchar(40) NOT NULL,
            user_id bigint(20) unsigned default NULL,
            campaign_id bigint(20) unsigned NOT NULL,
            plan_id bigint(20) unsigned NOT NULL,
            status varchar(30) NOT NULL default 'draft',
            total_amount decimal(10,2) NOT NULL default 0.00,
            valid_from date default NULL,
            valid_until date default NULL,
            submitted_at datetime default NULL,
            activated_at datetime default NULL,
            created_at datetime NOT NULL default CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL default CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY reference (reference),
            KEY user_status (user_id,status),
            KEY campaign_status (campaign_id,status)
        ) {$charset};");

        // Lignes figées du dossier : c'est le registre comptable. Une colonne
        // par montant, jamais du JSON — ces lignes sont additionnées, filtrées
        // et exportées.
        dbDelta("CREATE TABLE {$p}application_lines (
            id bigint(20) unsigned NOT NULL auto_increment,
            application_id bigint(20) unsigned NOT NULL,
            line_type varchar(20) NOT NULL,
            source_name varchar(100) default NULL,
            label varchar(190) NOT NULL,
            value_label varchar(190) default NULL,
            amount decimal(10,2) NOT NULL default 0.00,
            ordering int(11) NOT NULL default 0,
            PRIMARY KEY  (id),
            KEY application (application_id,ordering)
        ) {$charset};");

        dbDelta("CREATE TABLE {$p}validations (
            id bigint(20) unsigned NOT NULL auto_increment,
            application_id bigint(20) unsigned NOT NULL,
            step varchar(50) NOT NULL,
            decision varchar(20) NOT NULL,
            actor_id bigint(20) unsigned default NULL,
            comment text,
            created_at datetime NOT NULL default CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY application (application_id,created_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$p}payments (
            id bigint(20) unsigned NOT NULL auto_increment,
            application_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned default NULL,
            amount decimal(10,2) NOT NULL default 0.00,
            method varchar(30) NOT NULL default 'cheque',
            status varchar(20) NOT NULL default 'pending',
            reference varchar(190) default NULL,
            received_on date default NULL,
            recorded_by bigint(20) unsigned default NULL,
            notes text,
            created_at datetime NOT NULL default CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY application (application_id),
            KEY status (status)
        ) {$charset};");

        // --- Événements -------------------------------------------------------

        // Un type porte les règles ; l'événement les COPIE à sa création, pour
        // qu'une modification du type ne réécrive pas l'historique.
        dbDelta("CREATE TABLE {$p}event_types (
            id bigint(20) unsigned NOT NULL auto_increment,
            name varchar(190) NOT NULL,
            slug varchar(190) NOT NULL,
            create_capability varchar(100) NOT NULL default 'sub_create_governance_event',
            requires_autonomous tinyint(1) NOT NULL default 0,
            requires_dive_leader tinyint(1) NOT NULL default 0,
            requires_medical tinyint(1) NOT NULL default 1,
            requires_membership tinyint(1) NOT NULL default 1,
            default_capacity int(11) NOT NULL default 0,
            allow_waiting_list tinyint(1) NOT NULL default 1,
            registration_fields longtext,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug)
        ) {$charset};");

        // `ends_at` dès la v1 : les sorties sur plusieurs jours et la
        // réservation du bateau en dépendent. Une colonne aujourd'hui, toutes
        // les requêtes d'agenda à reprendre plus tard (cf. §9 bis).
        dbDelta("CREATE TABLE {$p}events (
            id bigint(20) unsigned NOT NULL auto_increment,
            type_id bigint(20) unsigned NOT NULL,
            title varchar(190) NOT NULL,
            slug varchar(190) NOT NULL,
            description longtext,
            location varchar(190) default NULL,
            starts_at datetime NOT NULL,
            ends_at datetime default NULL,
            registration_closes_at datetime default NULL,
            capacity int(11) NOT NULL default 0,
            allow_waiting_list tinyint(1) NOT NULL default 1,
            requires_medical tinyint(1) NOT NULL default 1,
            requires_membership tinyint(1) NOT NULL default 1,
            accepted_levels longtext,
            organizer_id bigint(20) unsigned default NULL,
            status varchar(20) NOT NULL default 'published',
            created_at datetime NOT NULL default CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY starts_at (starts_at,status)
        ) {$charset};");

        // La clé UNIQUE interdit qu'on prenne deux fois la même place. Elle
        // laisse pourtant coexister autant d'inscriptions détachées que
        // nécessaire sur une même sortie : deux NULL ne sont pas égaux.
        dbDelta("CREATE TABLE {$p}event_registrations (
            id bigint(20) unsigned NOT NULL auto_increment,
            event_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned default NULL,
            status varchar(20) NOT NULL default 'confirmed',
            details longtext,
            registered_at datetime NOT NULL default CURRENT_TIMESTAMP,
            cancelled_at datetime default NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_user (event_id,user_id),
            KEY event_status (event_id,status)
        ) {$charset};");

        // --- Documents personnels ---------------------------------------------

        // Les règles vivent sur le TYPE, pas dans le code : chiffrement,
        // visibilité, conservation, blocage. Ajouter un type de document ne
        // demande donc aucun développement.
        dbDelta("CREATE TABLE {$p}document_types (
            id bigint(20) unsigned NOT NULL auto_increment,
            slug varchar(60) NOT NULL,
            label varchar(190) NOT NULL,
            help text,
            is_required tinyint(1) NOT NULL default 0,
            required_when varchar(30) NOT NULL default 'always',
            encrypted tinyint(1) NOT NULL default 0,
            log_access tinyint(1) NOT NULL default 0,
            view_capability varchar(100) NOT NULL default 'sub_manage_memberships',
            blocks_dives tinyint(1) NOT NULL default 0,
            has_validity tinyint(1) NOT NULL default 1,
            validity_months int(11) NOT NULL default 12,
            purge_delay_days int(11) NOT NULL default 30,
            reminder_days varchar(100) NOT NULL default '30',
            needs_validation tinyint(1) NOT NULL default 1,
            ordering int(11) NOT NULL default 0,
            published tinyint(1) NOT NULL default 1,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug)
        ) {$charset};");

        dbDelta("CREATE TABLE {$p}member_documents (
            id bigint(20) unsigned NOT NULL auto_increment,
            user_id bigint(20) unsigned NOT NULL,
            type_slug varchar(60) NOT NULL,
            file_path varchar(255) NOT NULL,
            original_name varchar(255) NOT NULL,
            mime_type varchar(100) NOT NULL,
            file_size bigint(20) unsigned NOT NULL default 0,
            is_encrypted tinyint(1) NOT NULL default 0,
            issued_on date default NULL,
            valid_until date default NULL,
            status varchar(20) NOT NULL default 'pending',
            reject_reason varchar(255) default NULL,
            verified_by bigint(20) unsigned default NULL,
            verified_at datetime default NULL,
            purge_on date default NULL,
            purged_at datetime default NULL,
            uploaded_at datetime NOT NULL default CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_type (user_id,type_slug,status),
            KEY purge_on (purge_on,purged_at),
            KEY valid_until (valid_until,status)
        ) {$charset};");

        // Journal des consultations : distinct de l'audit général, parce qu'il
        // concerne des données de santé et doit pouvoir être produit seul.
        dbDelta("CREATE TABLE {$p}document_access_log (
            id bigint(20) unsigned NOT NULL auto_increment,
            document_id bigint(20) unsigned NOT NULL,
            actor_id bigint(20) unsigned default NULL,
            action varchar(30) NOT NULL default 'view',
            ip_address varchar(45) default NULL,
            created_at datetime NOT NULL default CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY document (document_id,created_at)
        ) {$charset};");

        // --- Notifications ----------------------------------------------------

        // Les modèles sont en base, pas dans le code : le bureau réécrit un
        // message sans développeur, et sans risque de casser l'envoi.
        dbDelta("CREATE TABLE {$p}email_templates (
            id bigint(20) unsigned NOT NULL auto_increment,
            code varchar(60) NOT NULL,
            label varchar(190) NOT NULL,
            description text,
            channel varchar(20) NOT NULL default 'transactional',
            subject varchar(255) NOT NULL,
            body longtext NOT NULL,
            variables longtext,
            copy_guardian tinyint(1) NOT NULL default 0,
            published tinyint(1) NOT NULL default 1,
            updated_at datetime NOT NULL default CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code)
        ) {$charset};");

        // Journal des envois : sans trace, personne ne sait si l'information
        // est partie — et le bureau finit par tout renvoyer « au cas où ».
        dbDelta("CREATE TABLE {$p}notification_log (
            id bigint(20) unsigned NOT NULL auto_increment,
            template_code varchar(60) NOT NULL,
            channel varchar(20) NOT NULL default 'transactional',
            recipient_id bigint(20) unsigned default NULL,
            recipient_email varchar(190) NOT NULL,
            subject varchar(255) NOT NULL,
            entity_type varchar(60) default NULL,
            entity_id bigint(20) unsigned default NULL,
            sender_id bigint(20) unsigned default NULL,
            status varchar(20) NOT NULL default 'sent',
            error text,
            sent_at datetime NOT NULL default CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY template_entity (template_code,entity_type,entity_id),
            KEY recipient (recipient_id,sent_at),
            KEY sent_at (sent_at)
        ) {$charset};");

        // --- Communication ---------------------------------------------------

        // Seuls les groupes *constitués à la main* ont une table. Les listes
        // calculées — adhérents actifs, encadrants, par niveau — n'en ont pas :
        // les stocker reviendrait à figer ce qui doit se recalculer.
        dbDelta("CREATE TABLE {$p}mailing_groups (
            id bigint(20) unsigned NOT NULL auto_increment,
            slug varchar(100) NOT NULL,
            label varchar(190) NOT NULL,
            description text,
            created_at datetime NOT NULL default CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug)
        ) {$charset};");

        dbDelta("CREATE TABLE {$p}mailing_group_members (
            id bigint(20) unsigned NOT NULL auto_increment,
            group_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            added_at datetime NOT NULL default CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY group_user (group_id,user_id),
            KEY user_id (user_id)
        ) {$charset};");

        self::allowDetachedRows();

        // Les colonnes acceptent désormais NULL : le nettoyage peut solder ce
        // que les suppressions de comptes d'avant ont laissé derrière elles.
        MemberPurge::repairOrphans();

        update_option(self::VERSION_OPTION, self::VERSION, false);
    }

    /**
     * Rend nullables les colonnes qui désignent une personne.
     *
     * dbDelta compare le type et la valeur par défaut d'une colonne, jamais sa
     * nullabilité : sur une installation déjà en place, la déclaration
     * `default NULL` ci-dessus ne suffirait pas. Il faut le demander.
     */
    private static function allowDetachedRows(): void
    {
        global $wpdb;

        foreach (self::SUBJECT_COLUMNS as $table => $column) {
            $name = $wpdb->prefix . 'sub_' . $table;

            $current = $wpdb->get_row($wpdb->prepare(
                "SHOW COLUMNS FROM `{$name}` LIKE %s",
                $column
            ));

            if ($current !== null && strtoupper((string) $current->Null) === 'NO') {
                $wpdb->query(
                    "ALTER TABLE `{$name}` MODIFY `{$column}` bigint(20) unsigned default NULL"
                );
            }
        }
    }

    /**
     * Colonnes à détacher, pour [MemberPurge::repairOrphans].
     *
     * @return array<string, string>
     */
    public static function subjectColumns(): array
    {
        return self::SUBJECT_COLUMNS;
    }
}
