<?php

declare(strict_types=1);

namespace Subalcatel\Club\Content;

use Subalcatel\Club\Identity\Roles;

/**
 * Documents du club : statuts, comptes rendus d'AG, procédures, formations.
 *
 * Remplace EDocman. À ne pas confondre avec `Documents\` : celui-ci gère les
 * pièces que le *club* publie, l'autre les pièces que le *membre* dépose. Rien
 * de commun entre les deux régimes — un compte rendu d'AG n'a ni titulaire, ni
 * date de validité, ni chiffrement.
 *
 * Le contenu s'appuie sur un type de publication et une taxonomie : WordPress
 * fournit alors l'écran de liste, la recherche, les catégories et les
 * révisions. Ce qui reste à écrire est ce qu'il ne sait pas faire — un fichier
 * hors de la racine web, servi après contrôle des droits.
 */
final class ClubDocuments
{
    public const POST_TYPE = 'sub_club_doc';
    public const TAXONOMY  = 'sub_doc_category';

    /** Ouvert à tous, y compris aux visiteurs non connectés. */
    public const ACCESS_PUBLIC = 'public';

    /** Réservé aux titulaires d'une adhésion en cours. */
    public const ACCESS_MEMBERS = 'members';

    /** Réservé aux porteurs d'une capacité — bureau, encadrants… */
    public const ACCESS_CAPABILITY = 'capability';

    public const META_KEY         = '_sub_doc_key';
    public const META_FILENAME    = '_sub_doc_filename';
    public const META_SIZE        = '_sub_doc_size';
    public const META_ACCESS      = '_sub_doc_access';
    public const META_CAPABILITY  = '_sub_doc_capability';
    public const META_DOWNLOADS   = '_sub_doc_downloads';
    public const META_VERSIONS    = '_sub_doc_versions';

    /**
     * @return array<string, string>
     */
    public static function accessLevels(): array
    {
        return [
            self::ACCESS_PUBLIC     => 'Public — visible par tout le monde',
            self::ACCESS_MEMBERS    => 'Adhérents — adhésion en cours requise',
            self::ACCESS_CAPABILITY => 'Restreint — selon une capacité précise',
        ];
    }

    /**
     * Catégories installées au démarrage, reprises de l'arborescence EDocman.
     *
     * Le bureau en ajoute librement : ce ne sont que des valeurs de départ pour
     * qu'un écran vide ne décourage pas la première prise en main.
     *
     * @return array<string, string>
     */
    public static function defaultCategories(): array
    {
        return [
            'assemblees-generales' => 'Assemblées générales',
            'comptes-rendus'       => 'Comptes rendus de réunion',
            'statuts-reglements'   => 'Statuts et règlement intérieur',
            'procedures'           => 'Procédures et consignes',
            'formations'           => 'Supports de formation',
        ];
    }

    /**
     * Une seule capacité, `sub_manage_content`, pour toute la famille.
     *
     * Deux pièges, tous deux payés comptant :
     *
     * 1. **La liste doit être complète.** Avec `map_meta_cap`, modifier un
     *    document déjà publié passe par `edit_published_posts`, pas par
     *    `edit_posts`. En oublier une donne un écran de liste qui s'affiche et
     *    un bouton « Modifier » qui refuse.
     *
     * 2. **Elle ne doit contenir que des capacités primitives.** Y ajouter les
     *    méta-capacités `edit_post`, `read_post` ou `delete_post` paraît
     *    cohérent et casse tout le site : `get_post_type_capabilities()`
     *    enregistre alors la correspondance **inverse** dans
     *    `$post_type_meta_caps`, et le moindre contrôle de
     *    `sub_manage_content`, où qu'il soit, se retrouve réacheminé vers
     *    `edit_post` sans article — donc refusé. Les menus disparaissent sans
     *    la moindre erreur.
     *
     * @return array<string, string>
     */
    private static function capabilityMap(): array
    {
        $caps = [
            'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts',
            'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts',
            'publish_posts', 'read_private_posts', 'create_posts',
        ];

        return array_fill_keys($caps, 'sub_manage_content');
    }

    public static function register(): void
    {
        register_post_type(self::POST_TYPE, [
            'label'               => 'Documents du club',
            'labels'              => [
                'name'          => 'Documents du club',
                'singular_name' => 'Document du club',
                'add_new_item'  => 'Ajouter un document',
                'edit_item'     => 'Modifier le document',
                'search_items'  => 'Rechercher un document',
                'not_found'     => 'Aucun document.',
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => false, // Rangé sous le menu « Club ».
            'supports'            => ['title', 'editor', 'revisions'],
            'taxonomies'          => [self::TAXONOMY],
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'capabilities'        => self::capabilityMap(),
            // Aucune page publique : un document n'a pas d'adresse propre, il a
            // un lien de téléchargement contrôlé.
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'rewrite'             => false,
            'query_var'           => false,
        ]);

        register_taxonomy(self::TAXONOMY, self::POST_TYPE, [
            'label'             => 'Catégories de documents',
            'labels'            => [
                'name'          => 'Catégories',
                'singular_name' => 'Catégorie',
                'add_new_item'  => 'Ajouter une catégorie',
            ],
            'public'            => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'hierarchical'      => true,
            'rewrite'           => false,
            'query_var'         => false,
            'capabilities'      => [
                'manage_terms' => 'sub_manage_content',
                'edit_terms'   => 'sub_manage_content',
                'delete_terms' => 'sub_manage_content',
                'assign_terms' => 'sub_manage_content',
            ],
        ]);
    }

    public static function seedCategories(): void
    {
        foreach (self::defaultCategories() as $slug => $label) {
            if (!term_exists($slug, self::TAXONOMY)) {
                wp_insert_term($label, self::TAXONOMY, ['slug' => $slug]);
            }
        }
    }

    /**
     * Cette personne peut-elle télécharger ce document ?
     *
     * Un seul point de décision, appelé aussi bien par l'affichage de la liste
     * que par l'endpoint de téléchargement. Masquer un lien n'est pas une
     * protection : c'est le second appel qui protège réellement.
     */
    public static function mayDownload(int $documentId, ?int $userId = null): bool
    {
        $userId ??= get_current_user_id();
        $access  = (string) get_post_meta($documentId, self::META_ACCESS, true);

        if ($access === '' || $access === self::ACCESS_PUBLIC) {
            return true;
        }

        if ($userId === 0) {
            return false;
        }

        // Le bureau accède à tout : il produit ces documents.
        if (user_can($userId, 'sub_manage_content')) {
            return true;
        }

        if ($access === self::ACCESS_CAPABILITY) {
            $capability = (string) get_post_meta($documentId, self::META_CAPABILITY, true);

            return $capability !== '' && user_can($userId, $capability);
        }

        return self::isActiveMember($userId);
    }

    /**
     * Adhésion en cours ?
     *
     * On lit la méta entretenue par le module d'adhésion plutôt que d'ouvrir la
     * table : la liste des documents peut afficher des dizaines de lignes, et
     * une requête par ligne se paierait cash.
     */
    public static function isActiveMember(int $userId): bool
    {
        if (user_can($userId, 'sub_manage_memberships')) {
            return true;
        }

        $validUntil = (string) get_user_meta($userId, 'sub_membership_valid_until', true);

        return $validUntil !== '' && $validUntil >= current_time('Y-m-d');
    }

    /**
     * Capacités proposées pour un accès restreint.
     *
     * @return array<string, string>
     */
    public static function capabilityChoices(): array
    {
        return Roles::CAPABILITIES;
    }
}
