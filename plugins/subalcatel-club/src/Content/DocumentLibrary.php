<?php

declare(strict_types=1);

namespace Subalcatel\Club\Content;

use RuntimeException;
use Subalcatel\Club\Documents\DocumentStorage;
use Subalcatel\Club\Support\Audit;

/**
 * Dépôt, remplacement et service des documents du club.
 *
 * Le fichier ne vit jamais dans la médiathèque : il part dans l'espace de
 * stockage configuré, sous un préfixe qui lui est propre, et n'est atteignable
 * que par l'endpoint de téléchargement.
 */
final class DocumentLibrary
{
    public const PREFIX = 'club';

    /**
     * Extensions acceptées.
     *
     * Liste blanche, jamais liste noire : on sait ce qu'un club de plongée
     * publie, on ne sait pas ce qu'un attaquant inventera.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt'  => 'application/vnd.oasis.opendocument.text',
        'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'zip'  => 'application/zip',
    ];

    private const MAX_BYTES = 25 * 1024 * 1024;

    /**
     * Attache un fichier à un document, en archivant le précédent.
     *
     * Le remplacement ne détruit rien : la version sortante rejoint
     * l'historique. Un compte rendu d'AG corrigé après coup laisse une trace de
     * ce qui avait été diffusé — c'est le minimum pour une association.
     *
     * @param array{tmp_name: string, name: string, type: string, size: int, error?: int} $file
     */
    public function attach(int $documentId, array $file, int $actorId): void
    {
        $this->guardUpload($file);

        $previousKey = (string) get_post_meta($documentId, ClubDocuments::META_KEY, true);

        if ($previousKey !== '') {
            $versions   = self::versionsOf($documentId);
            $versions[] = [
                'key'          => $previousKey,
                'filename'     => (string) get_post_meta($documentId, ClubDocuments::META_FILENAME, true),
                'size'         => (int) get_post_meta($documentId, ClubDocuments::META_SIZE, true),
                'replaced_on'  => current_time('mysql'),
                'replaced_by'  => $actorId,
            ];

            update_post_meta($documentId, ClubDocuments::META_VERSIONS, $versions);
        }

        $key = DocumentStorage::store($file, false, self::PREFIX);

        update_post_meta($documentId, ClubDocuments::META_KEY, $key);
        update_post_meta($documentId, ClubDocuments::META_FILENAME, sanitize_file_name($file['name']));
        update_post_meta($documentId, ClubDocuments::META_SIZE, (int) $file['size']);

        Audit::log('club_document.uploaded', 'club_document', $documentId, [
            'filename' => sanitize_file_name($file['name']),
            'replaced' => $previousKey !== '',
        ], $actorId);
    }

    /**
     * Dépôt d'un document par un adhérent, depuis le site.
     *
     * Le document est publié aussitôt. Toutes les règles sont vérifiées ici et
     * non dans le formulaire : adhésion à jour, catégorie ouverte, accès parmi
     * les trois proposés, fichier contrôlé par [attach]. Si le fichier est
     * refusé, le document créé pour l'accueillir est effacé : pas de fiche
     * fantôme sans fichier dans la liste.
     *
     * @param array{name: string, tmp_name: string, type?: string, size: int, error?: int}|null $file
     * @return int identifiant du document
     */
    public function contribute(
        int $userId,
        string $title,
        string $description,
        int $termId,
        string $access,
        ?array $file
    ): int {
        if (!ClubDocuments::mayContribute($userId)) {
            throw new RuntimeException('Le dépôt de documents est réservé aux adhérents à jour de cotisation.');
        }

        $title = trim($title);

        if ($title === '') {
            throw new RuntimeException('Le titre est obligatoire.');
        }

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Aucun fichier reçu.');
        }

        $term = get_term($termId, ClubDocuments::TAXONOMY);

        if (!$term instanceof \WP_Term) {
            throw new RuntimeException('Choisissez une catégorie.');
        }

        if (ClubDocuments::isOfficeOnly($termId) && !user_can($userId, 'sub_manage_content')) {
            throw new RuntimeException(sprintf('La catégorie « %s » est réservée au bureau.', $term->name));
        }

        if (!array_key_exists($access, ClubDocuments::contributorAccessLevels())) {
            throw new RuntimeException('Niveau d’accès inconnu.');
        }

        $documentId = wp_insert_post([
            'post_type'    => ClubDocuments::POST_TYPE,
            'post_status'  => 'publish',
            'post_title'   => mb_substr($title, 0, 150),
            'post_content' => $description,
            'post_author'  => $userId,
        ], true);

        if (is_wp_error($documentId)) {
            throw new RuntimeException('Le document n’a pas pu être créé.');
        }

        wp_set_object_terms($documentId, [$termId], ClubDocuments::TAXONOMY);

        if ($access === 'instructors') {
            update_post_meta($documentId, ClubDocuments::META_ACCESS, ClubDocuments::ACCESS_CAPABILITY);
            update_post_meta($documentId, ClubDocuments::META_CAPABILITY, ClubDocuments::INSTRUCTOR_CAPABILITY);
        } else {
            update_post_meta($documentId, ClubDocuments::META_ACCESS, $access);
            update_post_meta($documentId, ClubDocuments::META_CAPABILITY, '');
        }

        try {
            $this->attach($documentId, $file, $userId);
        } catch (RuntimeException $e) {
            wp_delete_post($documentId, true);

            throw $e;
        }

        Audit::log('club_document.contributed', 'club_document', $documentId, [
            'term'   => $term->slug,
            'access' => $access,
        ], $userId);

        return $documentId;
    }

    /**
     * Remplacement du fichier par son auteur (ou le bureau).
     *
     * @param array{name: string, tmp_name: string, type?: string, size: int, error?: int}|null $file
     */
    public function replaceAsContributor(int $documentId, int $userId, ?array $file): void
    {
        if (!ClubDocuments::mayManage($documentId, $userId)) {
            throw new RuntimeException('Seul l’auteur du document ou le bureau peut le modifier.');
        }

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Aucun fichier reçu.');
        }

        $this->attach($documentId, $file, $userId);
    }

    /**
     * Retrait par son auteur (ou le bureau) : le document part à la corbeille.
     *
     * La corbeille plutôt que l'effacement : le bureau peut encore le
     * restaurer, et les fichiers ne sont purgés qu'à la vidange.
     */
    public function withdraw(int $documentId, int $userId): void
    {
        if (!ClubDocuments::mayManage($documentId, $userId)) {
            throw new RuntimeException('Seul l’auteur du document ou le bureau peut le retirer.');
        }

        wp_trash_post($documentId);

        Audit::log('club_document.withdrawn', 'club_document', $documentId, [], $userId);
    }

    /**
     * Documents publiés par cette personne, du plus récent au plus ancien.
     *
     * @return list<\WP_Post>
     */
    public function contributedBy(int $userId): array
    {
        if ($userId === 0) {
            return [];
        }

        return get_posts([
            'post_type'      => ClubDocuments::POST_TYPE,
            'post_status'    => 'publish',
            'author'         => $userId,
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
    }

    /**
     * Versions archivées, toujours sous forme de tableau.
     *
     * Une méta absente vaut chaîne vide, et `(array) ''` produit `['']` — un
     * tableau d'un élément vide, pas un tableau vide. Le raccourci a coûté une
     * version fantôme en tête de liste ; il vit désormais à un seul endroit.
     *
     * @return list<array{key: string, filename: string, size: int, replaced_on: string, replaced_by: int}>
     */
    public static function versionsOf(int $documentId): array
    {
        $stored = get_post_meta($documentId, ClubDocuments::META_VERSIONS, true);

        return is_array($stored) ? array_values($stored) : [];
    }

    /**
     * Restaure une version archivée.
     *
     * L'échange est symétrique : la version courante prend la place de celle
     * qu'on rappelle, donc rien ne se perd et l'opération est réversible.
     */
    public function restoreVersion(int $documentId, int $index, int $actorId): void
    {
        $versions = self::versionsOf($documentId);

        if (!isset($versions[$index])) {
            throw new RuntimeException('Cette version n’existe plus.');
        }

        $restored = $versions[$index];
        $current  = (string) get_post_meta($documentId, ClubDocuments::META_KEY, true);

        if ($current !== '') {
            $versions[$index] = [
                'key'         => $current,
                'filename'    => (string) get_post_meta($documentId, ClubDocuments::META_FILENAME, true),
                'size'        => (int) get_post_meta($documentId, ClubDocuments::META_SIZE, true),
                'replaced_on' => current_time('mysql'),
                'replaced_by' => $actorId,
            ];
        } else {
            unset($versions[$index]);
            $versions = array_values($versions);
        }

        update_post_meta($documentId, ClubDocuments::META_VERSIONS, $versions);
        update_post_meta($documentId, ClubDocuments::META_KEY, $restored['key']);
        update_post_meta($documentId, ClubDocuments::META_FILENAME, $restored['filename']);
        update_post_meta($documentId, ClubDocuments::META_SIZE, $restored['size']);

        Audit::log('club_document.restored', 'club_document', $documentId, [
            'filename' => $restored['filename'],
        ], $actorId);
    }

    /**
     * Lit le fichier après contrôle des droits.
     *
     * @return array{contents: string, filename: string, mime: string}
     */
    public function download(int $documentId, ?int $userId = null): array
    {
        $userId ??= get_current_user_id();

        if (get_post_status($documentId) !== 'publish' && !user_can($userId, 'sub_manage_content')) {
            throw new RuntimeException('Ce document n’est pas disponible.');
        }

        if (!ClubDocuments::mayDownload($documentId, $userId)) {
            throw new RuntimeException($this->denialReason($documentId, $userId));
        }

        $key = (string) get_post_meta($documentId, ClubDocuments::META_KEY, true);

        if ($key === '') {
            throw new RuntimeException('Aucun fichier n’est attaché à ce document.');
        }

        $filename = (string) get_post_meta($documentId, ClubDocuments::META_FILENAME, true);

        // Le compteur avant la lecture : un fichier illisible reste un
        // téléchargement tenté, et l'écart signale un stockage en panne.
        $count = (int) get_post_meta($documentId, ClubDocuments::META_DOWNLOADS, true);
        update_post_meta($documentId, ClubDocuments::META_DOWNLOADS, $count + 1);

        return [
            'contents' => DocumentStorage::read($key, false),
            'filename' => $filename !== '' ? $filename : 'document',
            'mime'     => self::ALLOWED[strtolower((string) pathinfo($filename, PATHINFO_EXTENSION))]
                ?? 'application/octet-stream',
        ];
    }

    /**
     * Pourquoi l'accès est refusé, dit au visiteur.
     */
    private function denialReason(int $documentId, int $userId): string
    {
        $access = (string) get_post_meta($documentId, ClubDocuments::META_ACCESS, true);

        if ($userId === 0) {
            return 'Ce document est réservé aux adhérents. Connectez-vous pour y accéder.';
        }

        if ($access === ClubDocuments::ACCESS_CAPABILITY) {
            return 'Ce document est réservé à certains membres du club.';
        }

        return 'Ce document est réservé aux adhérents à jour de cotisation.';
    }

    /**
     * Supprime le document, ses versions et tous ses fichiers.
     */
    public function purgeFiles(int $documentId): void
    {
        $keys = [(string) get_post_meta($documentId, ClubDocuments::META_KEY, true)];

        foreach (self::versionsOf($documentId) as $version) {
            $keys[] = (string) ($version['key'] ?? '');
        }

        foreach (array_filter($keys) as $key) {
            DocumentStorage::delete($key);
        }
    }

    /**
     * Documents visibles par cette personne, groupés par catégorie, à plat.
     *
     * Chaque document range sous sa première catégorie. La page publique passe
     * par [tree] ; cette vue à plat reste pour qui veut une liste simple.
     *
     * @return list<array{term: \WP_Term|null, documents: list<\WP_Post>}>
     */
    public function browse(?int $userId = null, string $search = ''): array
    {
        $grouped = [];

        foreach ($this->visibleDocuments($userId, $search) as $post) {
            $terms = get_the_terms($post->ID, ClubDocuments::TAXONOMY);
            $term  = is_array($terms) && $terms !== [] ? $terms[0] : null;
            $key   = $term?->term_id ?? 0;

            $grouped[$key] ??= ['term' => $term, 'documents' => []];
            $grouped[$key]['documents'][] = $post;
        }

        // Les documents sans catégorie ferment la marche plutôt que de s'ouvrir
        // sur un intitulé vide.
        uasort($grouped, static function (array $a, array $b): int {
            if (($a['term'] === null) !== ($b['term'] === null)) {
                return $a['term'] === null ? 1 : -1;
            }

            return strcasecmp($a['term']->name ?? '', $b['term']->name ?? '');
        });

        return array_values($grouped);
    }

    /**
     * Documents visibles par cette personne, rangés dans l'arborescence des
     * catégories.
     *
     * Les catégories se hiérarchisent (« Supports de formation › Niveau 1 ») ;
     * l'arbre rendu suit cette hiérarchie. Trois règles :
     *
     * - une branche sans aucun document visible disparaît. Un visiteur ne doit
     *   pas voir une rubrique vide dont le contenu lui est caché ;
     * - un document coché dans plusieurs catégories apparaît dans chacune,
     *   sauf dans celles qui sont ancêtres d'une autre de ses catégories :
     *   cocher « Formation » et « Niveau 1 » le range sous « Niveau 1 » seul ;
     * - les documents sans catégorie forment une branche finale sans terme.
     *
     * `count` est le nombre de documents de la branche, sous-catégories
     * comprises, chaque document compté une fois.
     *
     * @return list<array{term: \WP_Term|null, documents: list<\WP_Post>, children: list<array<string, mixed>>, count: int}>
     */
    public function tree(?int $userId = null, string $search = ''): array
    {
        $terms = get_terms([
            'taxonomy'   => ClubDocuments::TAXONOMY,
            'hide_empty' => false,
        ]);
        $terms = is_array($terms) ? $terms : [];

        /** @var array<int, \WP_Term> $byId */
        $byId = [];

        foreach ($terms as $term) {
            $byId[$term->term_id] = $term;
        }

        /** @var array<int, list<\WP_Post>> $documentsOf terme => documents, 0 pour « sans catégorie » */
        $documentsOf = [];

        foreach ($this->visibleDocuments($userId, $search) as $post) {
            // `get_the_terms` lit le cache rempli par `get_posts` : aucune
            // requête par document, contrairement à `wp_get_object_terms`.
            $postTerms = get_the_terms($post->ID, ClubDocuments::TAXONOMY);
            $ids       = is_array($postTerms) ? array_values(array_filter(
                array_map(static fn (\WP_Term $t): int => (int) $t->term_id, $postTerms),
                static fn (int $id): bool => isset($byId[$id])
            )) : [];

            // Un ancêtre d'une autre catégorie cochée cède la place au plus précis.
            $ancestors = [];

            foreach ($ids as $id) {
                foreach (get_ancestors($id, ClubDocuments::TAXONOMY, 'taxonomy') as $ancestor) {
                    $ancestors[(int) $ancestor] = true;
                }
            }

            $leaves = array_values(array_filter($ids, static fn (int $id): bool => !isset($ancestors[$id])));

            foreach ($leaves === [] ? [0] : $leaves as $id) {
                $documentsOf[$id][] = $post;
            }
        }

        /** @var array<int, list<int>> $childrenOf */
        $childrenOf = [];

        foreach ($byId as $id => $term) {
            // Un parent disparu (supprimé à la main en base) ne doit pas faire
            // perdre la branche : elle remonte à la racine.
            $parent = isset($byId[$term->parent]) ? (int) $term->parent : 0;
            $childrenOf[$parent][] = $id;
        }

        $build = function (int $id, array $seen = []) use (&$build, $byId, $childrenOf, $documentsOf): ?array {
            // Garde-fou contre une boucle de parents corrompue.
            if (isset($seen[$id])) {
                return null;
            }

            $seen[$id] = true;
            $children  = [];
            $unique    = [];

            foreach ($childrenOf[$id] ?? [] as $childId) {
                $child = $build($childId, $seen);

                if ($child !== null) {
                    $children[] = $child;

                    foreach ($child['ids'] as $documentId => $_) {
                        $unique[$documentId] = true;
                    }
                }
            }

            $documents = $documentsOf[$id] ?? [];

            foreach ($documents as $document) {
                $unique[$document->ID] = true;
            }

            if ($unique === []) {
                return null;
            }

            usort($children, static fn (array $a, array $b): int
                => strnatcasecmp($a['term']->name, $b['term']->name));

            return [
                'term'      => $byId[$id],
                'documents' => $documents,
                'children'  => $children,
                'count'     => count($unique),
                'ids'       => $unique,
            ];
        };

        $tree = [];

        foreach ($childrenOf[0] ?? [] as $rootId) {
            $node = $build($rootId);

            if ($node !== null) {
                $tree[] = $node;
            }
        }

        usort($tree, static fn (array $a, array $b): int
            => strnatcasecmp($a['term']->name, $b['term']->name));

        if (($documentsOf[0] ?? []) !== []) {
            $tree[] = [
                'term'      => null,
                'documents' => $documentsOf[0],
                'children'  => [],
                'count'     => count($documentsOf[0]),
                'ids'       => [],
            ];
        }

        return self::stripIds($tree);
    }

    /**
     * Retire l'index interne qui a servi à compter sans doublon.
     *
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    private static function stripIds(array $nodes): array
    {
        return array_map(static function (array $node): array {
            unset($node['ids']);
            $node['children'] = self::stripIds($node['children']);

            return $node;
        }, $nodes);
    }

    /**
     * Documents publiés que cette personne peut télécharger, par titre.
     *
     * Aucune limite de nombre : la reprise Joomla en apporte plus de 500, et
     * une limite coupait la liste sans prévenir. Les métadonnées et les termes
     * sont chargés en bloc par `get_posts`, le contrôle d'accès ne coûte donc
     * pas une requête par document.
     *
     * @return list<\WP_Post>
     */
    private function visibleDocuments(?int $userId, string $search): array
    {
        $userId ??= get_current_user_id();

        $posts = get_posts([
            'post_type'      => ClubDocuments::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            's'              => $search,
        ]);

        return array_values(array_filter(
            $posts,
            static fn (\WP_Post $post): bool => ClubDocuments::mayDownload($post->ID, $userId)
        ));
    }

    /**
     * @param array{tmp_name: string, name: string, size: int, error?: int} $file
     */
    private function guardUpload(array $file): void
    {
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Le téléversement a échoué. Le fichier dépasse peut-être la taille autorisée par le serveur.');
        }

        if ($file['size'] <= 0) {
            throw new RuntimeException('Le fichier est vide.');
        }

        if ($file['size'] > self::MAX_BYTES) {
            throw new RuntimeException(sprintf(
                'Le fichier dépasse %d Mo.',
                (int) (self::MAX_BYTES / 1024 / 1024)
            ));
        }

        $extension = strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!isset(self::ALLOWED[$extension])) {
            throw new RuntimeException(sprintf(
                'Format « %s » non accepté. Formats admis : %s.',
                $extension,
                implode(', ', array_keys(self::ALLOWED))
            ));
        }

        // L'extension annonce, le contenu confirme. Un .pdf qui contient du PHP
        // est exactement ce par quoi le Joomla a été compromis.
        $checked = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], self::ALLOWED);

        if (empty($checked['ext'])) {
            throw new RuntimeException('Le contenu du fichier ne correspond pas à son extension.');
        }

        // Un PDF valide peut cacher du PHP après son en-tête : c'est le vecteur
        // du hack Joomla, et ces documents-ci ne sont pas chiffrés.
        \Subalcatel\Club\Documents\UploadGuard::rejectExecutable((string) $file['tmp_name']);
    }
}
