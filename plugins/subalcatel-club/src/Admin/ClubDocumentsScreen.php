<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

use RuntimeException;
use Subalcatel\Club\Content\ClubDocuments;
use Subalcatel\Club\Content\DocumentDelivery;
use Subalcatel\Club\Content\DocumentLibrary;

/**
 * Écrans du bureau pour les documents du club.
 *
 * L'écran de liste, la recherche et les catégories viennent de WordPress. Ce
 * qui est ajouté ici est le fichier lui-même : dépôt, droits d'accès,
 * historique des versions et compteur.
 */
final class ClubDocumentsScreen
{
    /** Onglet de {@see SettingsScreen} qui porte le contrôle d'intégrité. */
    public const TAB = 'integrite';

    public static function register(): void
    {
        add_action('add_meta_boxes', [self::class, 'metaBox']);
        add_action('save_post_' . ClubDocuments::POST_TYPE, [self::class, 'save'], 10, 2);
        add_action('post_edit_form_tag', [self::class, 'enableUpload']);
        add_action('before_delete_post', [self::class, 'purge']);
        add_action('admin_post_sub_club_doc_version', [self::class, 'handleVersion']);

        add_filter('manage_' . ClubDocuments::POST_TYPE . '_posts_columns', [self::class, 'columns']);
        add_action('manage_' . ClubDocuments::POST_TYPE . '_posts_custom_column', [self::class, 'column'], 10, 2);
    }

    /**
     * Recherche, à la demande, des documents porteurs de code exécutable.
     *
     * Le dépôt par l'interface est déjà protégé ; ce contrôle vise ce qui entre
     * autrement — au premier chef la migration des 509 documents du Joomla
     * compromis, importés en masse sans passer par le garde. À lancer après
     * toute reprise de données, et disponible en cas de doute.
     */
    public static function renderIntegrityTab(): void
    {
        if (!current_user_can('sub_manage_content')) {
            wp_die('Droit requis.');
        }

        echo '<p class="description">Recherche les fichiers contenant du code exécutable — '
            . 'le vecteur du piratage précédent. Le dépôt courant est déjà filtré ; ce contrôle '
            . 'sert surtout après une migration, où les documents entrent sans passer par ce filtre.</p>';

        $ran = isset($_POST['sub_run_scan']);

        if ($ran) {
            check_admin_referer('sub_scan_documents');

            $suspects = \Subalcatel\Club\Documents\UploadGuard::scanStored();

            if ($suspects === []) {
                echo '<div class="notice notice-success inline"><p><strong>Aucun document suspect.</strong> '
                    . 'Tous les fichiers stockés sont exempts de code exécutable.</p></div>';
            } else {
                printf(
                    '<div class="notice notice-error inline"><p><strong>%d document(s) suspect(s).</strong> '
                    . 'Ne les supprimez pas à l’aveugle — un vrai document peut citer du code. '
                    . 'Ouvrez chacun, vérifiez, puis retirez ceux qui n’ont rien à faire là.</p></div>',
                    count($suspects)
                );

                echo '<table class="widefat striped" style="margin-top:12px;max-width:760px;">'
                    . '<thead><tr><th>Identifiant</th><th>Fichier stocké</th><th>Signature trouvée</th></tr></thead><tbody>';

                foreach ($suspects as $s) {
                    printf(
                        '<tr><td>%s</td><td><code>%s</code></td><td><code>%s</code></td></tr>',
                        $s['id'] > 0 ? 'certificat #' . (int) $s['id'] : 'document du club',
                        esc_html($s['key']),
                        esc_html($s['signature'])
                    );
                }

                echo '</tbody></table>';
            }
        }

        printf(
            '<form method="post" style="margin-top:16px;"><input type="hidden" name="sub_run_scan" value="1">%s'
            . '<button type="submit" class="button button-primary">%s</button></form>',
            wp_nonce_field('sub_scan_documents', '_wpnonce', true, false),
            $ran ? 'Relancer le contrôle' : 'Lancer le contrôle'
        );
    }

    /**
     * Sans `enctype`, le fichier n'arrive jamais — et rien ne le signale.
     */
    public static function enableUpload(\WP_Post $post): void
    {
        if ($post->post_type === ClubDocuments::POST_TYPE) {
            echo ' enctype="multipart/form-data"';
        }
    }

    public static function metaBox(): void
    {
        add_meta_box(
            'sub-club-document',
            'Fichier et accès',
            [self::class, 'renderBox'],
            ClubDocuments::POST_TYPE,
            'normal',
            'high'
        );
    }

    public static function renderBox(\WP_Post $post): void
    {
        wp_nonce_field('sub_club_doc_' . $post->ID, 'sub_club_doc_nonce');

        $key       = (string) get_post_meta($post->ID, ClubDocuments::META_KEY, true);
        $filename  = (string) get_post_meta($post->ID, ClubDocuments::META_FILENAME, true);
        $size      = (int) get_post_meta($post->ID, ClubDocuments::META_SIZE, true);
        $access    = (string) get_post_meta($post->ID, ClubDocuments::META_ACCESS, true)
            ?: ClubDocuments::ACCESS_MEMBERS;
        $capability = (string) get_post_meta($post->ID, ClubDocuments::META_CAPABILITY, true);
        $downloads  = (int) get_post_meta($post->ID, ClubDocuments::META_DOWNLOADS, true);
        $versions   = DocumentLibrary::versionsOf($post->ID);

        wp_enqueue_style(
            'subalcatel-admin',
            \Subalcatel\Club\PLUGIN_URL . 'assets/css/admin.css',
            [],
            \Subalcatel\Club\VERSION
        );
        ?>
        <div class="sub-doc-box">
            <p>
                <label for="sub-doc-file"><strong>Fichier</strong></label><br>
                <?php if ($key !== '') : ?>
                    <span class="sub-doc-current">
                        <a href="<?php echo esc_url(DocumentDelivery::url($post->ID)); ?>">
                            <?php echo esc_html($filename); ?>
                        </a>
                        <?php echo esc_html(sprintf(' — %s, téléchargé %d fois', size_format($size), $downloads)); ?>
                    </span><br>
                <?php endif; ?>
                <input type="file" name="sub_doc_file" id="sub-doc-file">
                <span class="description">
                    <?php echo $key === ''
                        ? 'PDF, bureautique, image ou archive — 25 Mo maximum.'
                        : 'Déposer un fichier ici remplace le précédent, qui rejoint l’historique.'; ?>
                </span>
            </p>

            <p>
                <label for="sub-doc-access"><strong>Qui peut le télécharger</strong></label><br>
                <select name="sub_doc_access" id="sub-doc-access" class="sub-doc-access">
                    <?php foreach (ClubDocuments::accessLevels() as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>"
                            <?php selected($access, $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p class="sub-doc-capability"
               <?php echo $access === ClubDocuments::ACCESS_CAPABILITY ? '' : 'style="display:none"'; ?>>
                <label for="sub-doc-cap"><strong>Capacité requise</strong></label><br>
                <select name="sub_doc_capability" id="sub-doc-cap">
                    <?php foreach (ClubDocuments::capabilityChoices() as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>"
                            <?php selected($capability, $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>

            <?php if ($versions !== []) : ?>
                <h4>Versions précédentes</h4>
                <div class="sub-scroll">
                    <table class="widefat striped">
                        <thead>
                            <tr><th>Fichier</th><th>Remplacé le</th><th>Par</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach (array_reverse($versions, true) as $index => $version) : ?>
                            <tr>
                                <td><?php echo esc_html((string) $version['filename']); ?></td>
                                <td><?php echo esc_html(mysql2date('j M Y', (string) $version['replaced_on'])); ?></td>
                                <td><?php
                                    $author = get_userdata((int) $version['replaced_by']);
                                    echo esc_html($author ? $author->display_name : '—');
                                ?></td>
                                <td>
                                    <?php // Formulaire frère, jamais imbriqué : celui de l'article l'entoure déjà. ?>
                                    <a class="button button-small"
                                       href="<?php echo esc_url(wp_nonce_url(add_query_arg([
                                           'action'   => 'sub_club_doc_version',
                                           'document' => $post->ID,
                                           'version'  => $index,
                                       ], admin_url('admin-post.php')), 'sub_club_doc_version_' . $post->ID)); ?>">
                                        Restaurer
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <script>
        (function () {
            var select = document.querySelector('.sub-doc-access');
            var block  = document.querySelector('.sub-doc-capability');
            if (!select || !block) { return; }
            select.addEventListener('change', function () {
                block.style.display = select.value === '<?php echo esc_js(ClubDocuments::ACCESS_CAPABILITY); ?>'
                    ? '' : 'none';
            });
        })();
        </script>
        <?php
    }

    public static function save(int $postId, \WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $nonce = isset($_POST['sub_club_doc_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['sub_club_doc_nonce']))
            : '';

        if (!wp_verify_nonce($nonce, 'sub_club_doc_' . $postId)) {
            return;
        }

        if (!current_user_can('sub_manage_content')) {
            return;
        }

        $access = isset($_POST['sub_doc_access'])
            ? sanitize_key(wp_unslash($_POST['sub_doc_access']))
            : ClubDocuments::ACCESS_MEMBERS;

        if (!array_key_exists($access, ClubDocuments::accessLevels())) {
            $access = ClubDocuments::ACCESS_MEMBERS;
        }

        update_post_meta($postId, ClubDocuments::META_ACCESS, $access);

        $capability = isset($_POST['sub_doc_capability'])
            ? sanitize_key(wp_unslash($_POST['sub_doc_capability']))
            : '';

        update_post_meta(
            $postId,
            ClubDocuments::META_CAPABILITY,
            array_key_exists($capability, ClubDocuments::capabilityChoices()) ? $capability : ''
        );

        $file = $_FILES['sub_doc_file'] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return;
        }

        try {
            (new DocumentLibrary())->attach($postId, $file, get_current_user_id());
        } catch (RuntimeException $e) {
            // Impossible de rediriger proprement depuis `save_post` : l'erreur
            // voyage dans un transient et s'affiche au chargement suivant.
            set_transient('sub_doc_error_' . $postId, $e->getMessage(), 60);
            add_filter('redirect_post_location', static fn (string $location): string
                => add_query_arg('sub_doc_error', $postId, $location));
        }
    }

    public static function handleVersion(): void
    {
        $documentId = isset($_GET['document']) ? absint($_GET['document']) : 0;
        $index      = isset($_GET['version']) ? absint($_GET['version']) : 0;

        check_admin_referer('sub_club_doc_version_' . $documentId);

        if (!current_user_can('sub_manage_content')) {
            wp_die('Droit requis.', 403);
        }

        try {
            (new DocumentLibrary())->restoreVersion($documentId, $index, get_current_user_id());
            $notice = 'restored';
        } catch (RuntimeException $e) {
            set_transient('sub_doc_error_' . $documentId, $e->getMessage(), 60);
            $notice = 'error';
        }

        wp_safe_redirect(add_query_arg('sub_doc_notice', $notice, get_edit_post_link($documentId, 'raw')));
        exit;
    }

    /**
     * Les fichiers suivent le document dans la corbeille définitive.
     */
    public static function purge(int $postId): void
    {
        if (get_post_type($postId) === ClubDocuments::POST_TYPE) {
            (new DocumentLibrary())->purgeFiles($postId);
        }
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public static function columns(array $columns): array
    {
        $date = $columns['date'] ?? '';
        unset($columns['date']);

        $columns['sub_access']    = 'Accès';
        $columns['sub_file']      = 'Fichier';
        $columns['sub_downloads'] = 'Téléchargements';
        $columns['date']          = $date;

        return $columns;
    }

    public static function column(string $column, int $postId): void
    {
        switch ($column) {
            case 'sub_access':
                $access = (string) get_post_meta($postId, ClubDocuments::META_ACCESS, true)
                    ?: ClubDocuments::ACCESS_MEMBERS;

                $label = match ($access) {
                    ClubDocuments::ACCESS_PUBLIC     => 'Public',
                    ClubDocuments::ACCESS_CAPABILITY => 'Restreint',
                    default                          => 'Adhérents',
                };

                printf('<span class="sub-tag sub-tag--%s">%s</span>', esc_attr($access), esc_html($label));
                break;

            case 'sub_file':
                $filename = (string) get_post_meta($postId, ClubDocuments::META_FILENAME, true);

                echo $filename === ''
                    ? '<em>Aucun fichier</em>'
                    : esc_html($filename) . ' <span class="description">('
                        . esc_html(size_format((int) get_post_meta($postId, ClubDocuments::META_SIZE, true)))
                        . ')</span>';
                break;

            case 'sub_downloads':
                echo (int) get_post_meta($postId, ClubDocuments::META_DOWNLOADS, true);
                break;
        }
    }
}
