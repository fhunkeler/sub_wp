<?php

declare(strict_types=1);

namespace Subalcatel\Club\Content;

use Subalcatel\Club\Identity\AccountApproval;
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
     * Méta de catégorie : `1` réserve la catégorie au bureau.
     *
     * Tout adhérent à jour dépose des documents depuis le site ; ce drapeau
     * garde pour le bureau les rubriques officielles (statuts, comptes rendus
     * d'AG). Une catégorie sans drapeau est ouverte.
     */
    public const META_OFFICE_ONLY = 'sub_office_only';

    /** Catégories de départ réservées au bureau. */
    public const OFFICE_ONLY_DEFAULTS = ['assemblees-generales', 'comptes-rendus', 'statuts-reglements'];

    /** Accès « Encadrants » proposé aux adhérents : la capacité qui le porte. */
    public const INSTRUCTOR_CAPABILITY = 'sub_create_training_event';

    /**
     * Événement auquel le document se rattache — convocation et pouvoir d'une
     * AG, programme d'un stage. Le document reste un document du club : mêmes
     * droits, même historique, même place dans l'espace documentaire.
     */
    public const META_EVENT       = '_sub_doc_event';

    /**
     * Documents rattachés à un événement, regroupés par événement.
     *
     * Chargés d'un seul coup à la première demande : l'agenda affiche vingt
     * sorties, et vingt requêtes pour trois documents se paieraient à chaque
     * page.
     *
     * @var array<int, list<\WP_Post>>|null
     */
    private static ?array $byEvent = null;

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
            if (term_exists($slug, self::TAXONOMY)) {
                continue;
            }

            $term = wp_insert_term($label, self::TAXONOMY, ['slug' => $slug]);

            if (is_array($term) && in_array($slug, self::OFFICE_ONLY_DEFAULTS, true)) {
                update_term_meta((int) $term['term_id'], self::META_OFFICE_ONLY, '1');
            }
        }
    }

    /**
     * Réserve au bureau les catégories officielles déjà installées.
     *
     * Migration : avant l'ouverture du dépôt aux adhérents, aucune catégorie
     * ne portait le drapeau. On ne touche qu'aux catégories qui n'ont encore
     * aucune valeur, pour ne pas défaire un choix du bureau.
     */
    public static function markOfficeOnlyDefaults(): void
    {
        foreach (self::OFFICE_ONLY_DEFAULTS as $slug) {
            $term = get_term_by('slug', $slug, self::TAXONOMY);

            if ($term instanceof \WP_Term && get_term_meta($term->term_id, self::META_OFFICE_ONLY, true) === '') {
                update_term_meta($term->term_id, self::META_OFFICE_ONLY, '1');
            }
        }
    }

    public static function isOfficeOnly(int $termId): bool
    {
        return get_term_meta($termId, self::META_OFFICE_ONLY, true) === '1';
    }

    /**
     * Catégories où un adhérent peut ranger un document, dans l'ordre de
     * l'arborescence, avec leur profondeur pour l'affichage.
     *
     * Le drapeau vaut pour la catégorie seule : le bureau peut fermer
     * « Formation » et ouvrir sa sous-catégorie « Supports élèves ».
     *
     * @return list<array{term: \WP_Term, depth: int}>
     */
    public static function openCategories(): array
    {
        $terms = get_terms(['taxonomy' => self::TAXONOMY, 'hide_empty' => false]);
        $terms = is_array($terms) ? $terms : [];

        $childrenOf = [];
        $ids        = [];

        foreach ($terms as $term) {
            $ids[$term->term_id] = true;
        }

        foreach ($terms as $term) {
            $parent = isset($ids[$term->parent]) ? (int) $term->parent : 0;
            $childrenOf[$parent][] = $term;
        }

        $out  = [];
        $walk = static function (int $parent, int $depth, array $seen) use (&$walk, &$out, $childrenOf): void {
            $children = $childrenOf[$parent] ?? [];
            usort($children, static fn (\WP_Term $a, \WP_Term $b): int => strnatcasecmp($a->name, $b->name));

            foreach ($children as $term) {
                if (isset($seen[$term->term_id])) {
                    continue;
                }

                if (!self::isOfficeOnly($term->term_id)) {
                    $out[] = ['term' => $term, 'depth' => $depth];
                }

                $walk($term->term_id, $depth + 1, $seen + [$term->term_id => true]);
            }
        };

        $walk(0, 0, []);

        return $out;
    }

    /**
     * Accès qu'un adhérent peut donner à son document.
     *
     * Trois choix lisibles plutôt que la liste des capacités, qui n'a de sens
     * que pour le bureau.
     *
     * @return array<string, string>
     */
    public static function contributorAccessLevels(): array
    {
        return [
            self::ACCESS_PUBLIC  => 'Public — tout le monde, même sans compte',
            self::ACCESS_MEMBERS => 'Adhérents — membres à jour de cotisation',
            'instructors'        => 'Encadrants — moniteurs et directeurs de plongée',
        ];
    }

    /**
     * Cette personne peut-elle déposer un document depuis le site ?
     *
     * Tout adhérent à jour dont le compte est validé, et le bureau.
     */
    public static function mayContribute(int $userId): bool
    {
        if ($userId === 0) {
            return false;
        }

        if (user_can($userId, 'sub_manage_content')) {
            return true;
        }

        if (AccountApproval::isPending($userId) || AccountApproval::isRefused($userId)) {
            return false;
        }

        return self::isActiveMember($userId);
    }

    /**
     * Peut-elle remplacer ou retirer ce document ? Le bureau, ou son auteur.
     *
     * L'auteur garde la main même après expiration de son adhésion : retirer
     * un support qu'on a déposé ne doit pas dépendre d'une cotisation.
     */
    public static function mayManage(int $documentId, int $userId): bool
    {
        if ($userId === 0 || get_post_type($documentId) !== self::POST_TYPE) {
            return false;
        }

        return user_can($userId, 'sub_manage_content')
            || (int) get_post_field('post_author', $documentId) === $userId;
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

        // L'auteur relit toujours ce qu'il a déposé, même s'il l'a réservé aux
        // encadrants sans en être un.
        if ((int) get_post_field('post_author', $documentId) === $userId) {
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

    /**
     * Documents publiés rattachés à cet événement, du plus ancien au plus récent.
     *
     * Sans filtre de droits : à l'appelant de passer chaque document par
     * {@see self::mayDownload()}.
     *
     * @return list<\WP_Post>
     */
    public static function forEvent(int $eventId): array
    {
        if (self::$byEvent === null) {
            self::$byEvent = [];

            $documents = get_posts([
                'post_type'   => self::POST_TYPE,
                'post_status' => 'publish',
                'numberposts' => -1,
                'orderby'     => 'date',
                'order'       => 'ASC',
                'meta_query'  => [[
                    'key'     => self::META_EVENT,
                    'value'   => 0,
                    'compare' => '>',
                    'type'    => 'UNSIGNED',
                ]],
            ]);

            foreach ($documents as $document) {
                self::$byEvent[(int) get_post_meta($document->ID, self::META_EVENT, true)][] = $document;
            }
        }

        return self::$byEvent[$eventId] ?? [];
    }

    /**
     * Rattache un document à un événement, ou l'en détache avec 0.
     */
    public static function linkToEvent(int $documentId, int $eventId): void
    {
        if ($eventId > 0) {
            update_post_meta($documentId, self::META_EVENT, $eventId);
        } else {
            delete_post_meta($documentId, self::META_EVENT);
        }

        self::$byEvent = null;
    }

    /**
     * L'événement disparaît, ses documents restent.
     *
     * Le procès-verbal d'une AG survit à la suppression de l'AG dans l'agenda :
     * c'est une archive de l'association, pas une pièce de l'événement.
     */
    public static function detachEvent(int $eventId): void
    {
        delete_metadata('post', 0, self::META_EVENT, (string) $eventId, true);
        self::$byEvent = null;
    }
}
