<?php

declare(strict_types=1);

namespace Subalcatel\Club\Identity;

/**
 * Rôles et capacités du club.
 *
 * Principe : les rôles portent les fonctions *stables* (être membre, siéger au
 * bureau). Tout ce qui varie — niveau de plongée, adhésion active, option
 * souscrite — est calculé par EligibilityPolicy et n'est jamais un rôle.
 *
 * C'est pourquoi « encadrant » et « directeur de plongée », demandés comme rôles
 * dans le cahier des charges, sont ici dérivés du niveau : le jour où un membre
 * passe son E2, personne ne doit penser à modifier son compte.
 */
final class Roles
{
    public const GUEST  = 'sub_guest';
    public const MEMBER = 'sub_member';
    public const OFFICE = 'sub_office';

    /**
     * Capacités atomiques. Une capacité = une action, jamais un profil.
     *
     * @var array<string, string> capacité => libellé lisible
     */
    public const CAPABILITIES = [
        // Adhésions
        'sub_manage_memberships'              => 'Gérer les adhésions',
        'sub_validate_membership_secretariat' => 'Valider un dossier — secrétariat',
        'sub_validate_membership_treasury'    => 'Valider un paiement — trésorerie',

        // Événements
        'sub_manage_event_types'              => 'Gérer les types d’événement',
        'sub_create_governance_event'         => 'Créer une AG ou une réunion',
        'sub_create_exploration_event'        => 'Créer une plongée d’exploration',
        'sub_create_training_event'           => 'Créer une plongée de formation',
        'sub_communicate_event_participants'  => 'Écrire aux inscrits d’un événement',

        // Comptes
        'sub_validate_account'                => 'Valider un nouveau compte',
        'sub_manage_accounts'                 => 'Gérer les comptes — courriel, rôle, mot de passe',

        // Documents personnels
        'sub_view_medical_certificate'        => 'Consulter un certificat médical',
        'sub_view_medical_validity'           => 'Voir la validité des documents',
        'sub_validate_member_document'        => 'Valider un document déposé',

        // Contenu et exports
        'sub_manage_content'                  => 'Gérer les documents du club',
        'sub_export_members'                  => 'Exporter les membres',
        'sub_export_payments'                 => 'Exporter les paiements',
        'sub_export_event'                    => 'Exporter les inscrits d’un événement',

        // Données personnelles
        'sub_handle_privacy_requests'         => 'Traiter les demandes RGPD',

        // Matériel — phase ultérieure, déclaré dès maintenant pour que
        // l'attribution des droits ne soit pas à refaire.
        'sub_manage_equipment'                => 'Gérer l’inventaire matériel',
        'sub_manage_maintenance'              => 'Gérer les entretiens',
        'sub_manage_loans'                    => 'Gérer les emprunts',
    ];

    /**
     * Capacités accordées par défaut au bureau.
     *
     * Volontairement large au démarrage, à restreindre ensuite via l'extension
     * Members. `sub_view_medical_certificate` en fait partie sur décision du
     * club — voir §6 ter de la proposition.
     *
     * @var list<string>
     */
    private const OFFICE_DEFAULTS = [
        'sub_manage_memberships',
        'sub_validate_membership_secretariat',
        'sub_validate_membership_treasury',
        'sub_validate_account',
        'sub_manage_accounts',
        'sub_manage_event_types',
        'sub_create_governance_event',
        'sub_communicate_event_participants',
        'sub_view_medical_certificate',
        'sub_view_medical_validity',
        'sub_validate_member_document',
        'sub_manage_content',
        'sub_export_members',
        'sub_export_payments',
        'sub_export_event',
        'sub_handle_privacy_requests',
    ];

    /**
     * Version du jeu de capacités.
     *
     * `install()` ne tourne qu'à l'activation : sans ce compteur, une capacité
     * ajoutée après coup n'atteindrait jamais les installations existantes. À
     * incrémenter dès que CAPABILITIES ou OFFICE_DEFAULTS changent.
     */
    private const VERSION        = 4;
    private const VERSION_OPTION = 'subalcatel_club_roles_version';

    /**
     * Les rôles du club : ceux qui font d'un compte une personne du club.
     *
     * La liste est fermée, et `administrator` n'y est pas : promouvoir un
     * administrateur technique reste un geste d'administration WordPress, fait
     * depuis wp-admin par quelqu'un qui y a déjà accès. C'est la contrepartie
     * directe de la reprise Joomla, où quatorze comptes cumulaient ce rôle.
     *
     * Elle sert deux fois : à dire ce que le site sait attribuer, et à dire
     * qui il compte parmi ses adhérents. Les deux coïncident, et doivent
     * continuer de le faire depuis un seul endroit — un annuaire et un export
     * qui divergent sur ce point finissent par se contredire devant le bureau.
     *
     * @var array<string, string> rôle => libellé
     */
    public const CLUB_ROLES = [
        self::GUEST  => 'Invité du club',
        self::MEMBER => 'Membre',
        self::OFFICE => 'Membre du bureau',
    ];

    /**
     * Rôles attribuables depuis le site.
     *
     * @return array<string, string> rôle => libellé
     */
    public static function assignable(): array
    {
        return self::CLUB_ROLES;
    }

    /**
     * Les rôles qui font d'un compte un adhérent, pour un `role__in`.
     *
     * @return list<string>
     */
    public static function clubRoles(): array
    {
        return array_keys(self::CLUB_ROLES);
    }

    /**
     * Ce compte est-il un compte de club ordinaire ?
     *
     * Faux dès qu'il porte un rôle hors de la liste ci-dessus — administrateur,
     * éditeur, rôle posé par une extension. Ces comptes-là ne se modifient pas
     * depuis le site public : on ne veut pas qu'un membre du bureau puisse, par
     * un formulaire du front, changer le courriel de l'administrateur technique
     * puis demander un nouveau mot de passe à sa place.
     */
    public static function isClubAccount(int $userId): bool
    {
        $user = get_userdata($userId);

        if (!$user || $user->roles === []) {
            return false;
        }

        return array_diff($user->roles, self::clubRoles()) === [];
    }

    /**
     * Cette personne fait-elle partie du club ?
     *
     * À ne pas confondre avec {@see self::isClubAccount()}, qui répond à une
     * autre question : « ce compte se modifie-t-il depuis le site ? ». Celle-ci
     * est plus large — un adhérent qui est aussi administrateur technique reste
     * un adhérent, et n'a pas à disparaître de l'annuaire — et sert partout où
     * le site traite quelqu'un comme une personne du club : annuaire, listes de
     * diffusion, exports.
     *
     * Un compte purement technique n'a aucun de ces rôles. Il n'a pas d'adhésion
     * à suivre, pas de certificat à fournir, et n'a rien à faire dans une liste
     * d'adhérents où il figurerait éternellement « pas à jour ».
     */
    public static function isMemberOfClub(int $userId): bool
    {
        $user = get_userdata($userId);

        return $user !== false && array_intersect($user->roles, self::clubRoles()) !== [];
    }

    /**
     * Rejoue l'attribution si le jeu de capacités a évolué.
     *
     * Les droits retirés à la main depuis l'extension Members ne sont pas
     * rétablis : `add_cap` n'écrase que ce qui manque.
     */
    public static function refreshIfNeeded(): void
    {
        if ((int) get_option(self::VERSION_OPTION, 0) < self::VERSION) {
            self::install();
        }
    }

    public static function install(): void
    {
        add_role(self::GUEST, 'Invité du club', ['read' => true]);
        add_role(self::MEMBER, 'Membre', ['read' => true]);
        add_role(self::OFFICE, 'Membre du bureau', ['read' => true]);

        $office = get_role(self::OFFICE);
        foreach (self::OFFICE_DEFAULTS as $cap) {
            $office?->add_cap($cap);
        }

        // L'administrateur technique reçoit tout, sinon il ne peut pas
        // dépanner le bureau.
        $admin = get_role('administrator');
        foreach (array_keys(self::CAPABILITIES) as $cap) {
            $admin?->add_cap($cap);
        }

        update_option(self::VERSION_OPTION, self::VERSION);
    }
}
