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
     * Documents visibles par cette personne, groupés par catégorie.
     *
     * @return list<array{term: \WP_Term|null, documents: list<\WP_Post>}>
     */
    public function browse(?int $userId = null, string $search = ''): array
    {
        $userId ??= get_current_user_id();

        $posts = get_posts([
            'post_type'      => ClubDocuments::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'orderby'        => 'title',
            'order'          => 'ASC',
            's'              => $search,
        ]);

        $grouped = [];

        foreach ($posts as $post) {
            if (!ClubDocuments::mayDownload($post->ID, $userId)) {
                continue;
            }

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
