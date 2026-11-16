<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use RuntimeException;
use Subalcatel\Club\Content\ClubDocuments;
use Subalcatel\Club\Content\DocumentDelivery;
use Subalcatel\Club\Content\DocumentLibrary;

/**
 * Dépôt de documents par les adhérents, sur la page Documents du club.
 *
 * Un moniteur dépose ses supports de cours sans passer par l'administration :
 * le formulaire s'affiche au-dessus de la liste, pour tout adhérent à jour. Il
 * y retrouve aussi les documents qu'il a déposés, pour en remplacer le fichier
 * ou les retirer.
 *
 * Aucune règle ici : catégories ouvertes, accès permis, contrôle du fichier et
 * droits sur un document passent tous par [DocumentLibrary] et
 * [ClubDocuments]. Ce fichier est une IHM.
 */
final class ClubDocumentsContribute
{
    private const ACTION_ADD      = 'sub_club_doc_contribute';
    private const ACTION_REPLACE  = 'sub_club_doc_contrib_replace';
    private const ACTION_WITHDRAW = 'sub_club_doc_contrib_withdraw';

    public static function register(): void
    {
        add_action('admin_post_' . self::ACTION_ADD, [self::class, 'handleAdd']);
        add_action('admin_post_' . self::ACTION_REPLACE, [self::class, 'handleReplace']);
        add_action('admin_post_' . self::ACTION_WITHDRAW, [self::class, 'handleWithdraw']);
    }

    /**
     * Le bloc « Déposer un document », ou rien pour qui ne peut pas déposer.
     */
    public static function render(): string
    {
        $userId = get_current_user_id();

        if (!ClubDocuments::mayContribute($userId)) {
            return '';
        }

        $categories = ClubDocuments::openCategories();
        $mine       = (new DocumentLibrary())->contributedBy($userId);

        // Ouvert après un dépôt (réussi ou non) : le retour s'affiche dedans,
        // et un formulaire refermé sur une erreur la cacherait.
        $open = isset($_GET['sub_done']) || isset($_GET['sub_error']);

        ob_start();
        ?>
        <details class="sub-doclib__contribute" id="deposer-un-document"<?php echo $open ? ' open' : ''; ?>>
            <summary class="sub-button sub-button--small">Déposer un document</summary>

            <div class="sub-doclib__contribute-body">
                <?php echo Notice::fromQuery(); // déjà échappé ?>

                <?php if ($categories === []) : ?>
                    <div class="sub-notice sub-notice--info">
                        <p>Aucune catégorie n’est ouverte au dépôt pour le moment. Le bureau peut en
                            ouvrir une depuis l’écran des catégories de documents.</p>
                    </div>
                <?php else : ?>
                    <?php self::renderForm($categories); ?>
                <?php endif; ?>

                <?php if ($mine !== []) : ?>
                    <?php self::renderMine($mine); ?>
                <?php endif; ?>
            </div>
        </details>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @param list<array{term: \WP_Term, depth: int}> $categories
     */
    private static function renderForm(array $categories): void
    {
        ?>
        <form class="sub-doclib__form" method="post" enctype="multipart/form-data"
              action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_ADD); ?>">
            <?php wp_nonce_field(self::ACTION_ADD); ?>

            <fieldset class="sub-field">
                <legend>Nouveau document</legend>
                <div class="sub-grid">
                    <p class="sub-input sub-input--wide">
                        <label for="sub-contrib-title">Titre</label>
                        <input type="text" id="sub-contrib-title" name="title" required maxlength="150"
                               placeholder="Réglementation — cours Niveau 1">
                    </p>
                    <p class="sub-input sub-input--wide">
                        <label for="sub-contrib-desc">Description <span class="sub-input__help">(facultative)</span></label>
                        <textarea id="sub-contrib-desc" name="description" rows="2" maxlength="500"></textarea>
                    </p>
                    <p class="sub-input">
                        <label for="sub-contrib-term">Catégorie</label>
                        <select id="sub-contrib-term" name="term" required>
                            <option value="">— Choisir —</option>
                            <?php foreach ($categories as $category) : ?>
                                <option value="<?php echo (int) $category['term']->term_id; ?>">
                                    <?php echo esc_html(str_repeat('— ', $category['depth']) . $category['term']->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <p class="sub-input">
                        <label for="sub-contrib-access">Qui peut le télécharger</label>
                        <select id="sub-contrib-access" name="access" required>
                            <?php foreach (ClubDocuments::contributorAccessLevels() as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>"
                                    <?php selected($value, ClubDocuments::ACCESS_MEMBERS); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <p class="sub-input sub-input--wide">
                        <label for="sub-contrib-file">Fichier</label>
                        <input type="file" id="sub-contrib-file" name="file" required
                               accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.jpg,.jpeg,.png,.zip">
                        <span class="sub-input__help">PDF, bureautique, image ou archive ZIP — 25 Mo maximum.
                            Le document est publié dès l’envoi.</span>
                    </p>
                </div>
            </fieldset>

            <p><button type="submit" class="sub-button">Publier le document</button></p>
        </form>
        <?php
    }

    /**
     * @param list<\WP_Post> $documents
     */
    private static function renderMine(array $documents): void
    {
        $post = admin_url('admin-post.php');
        ?>
        <h3 class="sub-doclib__mine-title">Mes documents déposés</h3>
        <ul class="sub-doclib__list sub-doclib__mine">
            <?php foreach ($documents as $document) : ?>
                <?php
                $filename = (string) get_post_meta($document->ID, ClubDocuments::META_FILENAME, true);
                $fileId   = 'sub-contrib-replace-' . $document->ID;
                ?>
                <li class="sub-doclib__item">
                    <a class="sub-doclib__link"
                       href="<?php echo esc_url(DocumentDelivery::url($document->ID)); ?>"
                       target="_blank" rel="noopener"><?php echo esc_html($document->post_title); ?></a>
                    <span class="sub-doclib__meta"><?php
                        echo esc_html($filename . ' · ' . mysql2date('j M Y', $document->post_date));
                    ?></span>

                    <div class="sub-doclib__actions">
                        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url($post); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_REPLACE); ?>">
                            <input type="hidden" name="document" value="<?php echo (int) $document->ID; ?>">
                            <?php wp_nonce_field(self::ACTION_REPLACE . '_' . $document->ID); ?>
                            <label class="screen-reader-text" for="<?php echo esc_attr($fileId); ?>">
                                Nouveau fichier pour « <?php echo esc_html($document->post_title); ?> »
                            </label>
                            <input type="file" id="<?php echo esc_attr($fileId); ?>" name="file" required>
                            <button type="submit" class="sub-button sub-button--ghost sub-button--small">
                                Remplacer le fichier
                            </button>
                        </form>

                        <form method="post" action="<?php echo esc_url($post); ?>"
                              onsubmit="return confirm('Retirer ce document ? Il ne sera plus visible sur le site.');">
                            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_WITHDRAW); ?>">
                            <input type="hidden" name="document" value="<?php echo (int) $document->ID; ?>">
                            <?php wp_nonce_field(self::ACTION_WITHDRAW . '_' . $document->ID); ?>
                            <button type="submit" class="sub-button sub-button--ghost sub-button--small">
                                Retirer
                            </button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
    }

    public static function handleAdd(): void
    {
        if (!is_user_logged_in() || !check_admin_referer(self::ACTION_ADD)) {
            wp_die('Requête non autorisée.', 403);
        }

        try {
            (new DocumentLibrary())->contribute(
                get_current_user_id(),
                sanitize_text_field(wp_unslash((string) ($_POST['title'] ?? ''))),
                // Pas de HTML depuis le site public : du texte, comme pour une sortie.
                sanitize_textarea_field(wp_unslash((string) ($_POST['description'] ?? ''))),
                absint($_POST['term'] ?? 0),
                sanitize_key(wp_unslash((string) ($_POST['access'] ?? ''))),
                self::uploadedFile()
            );
        } catch (RuntimeException $e) {
            self::back($e->getMessage(), true);
        }

        self::back('Document publié. Il apparaît dans la liste ci-dessous.');
    }

    public static function handleReplace(): void
    {
        $documentId = absint($_POST['document'] ?? 0);

        if (!is_user_logged_in() || !check_admin_referer(self::ACTION_REPLACE . '_' . $documentId)) {
            wp_die('Requête non autorisée.', 403);
        }

        try {
            (new DocumentLibrary())->replaceAsContributor($documentId, get_current_user_id(), self::uploadedFile());
        } catch (RuntimeException $e) {
            self::back($e->getMessage(), true);
        }

        self::back('Fichier remplacé. L’ancien reste dans l’historique du document.');
    }

    public static function handleWithdraw(): void
    {
        $documentId = absint($_POST['document'] ?? 0);

        if (!is_user_logged_in() || !check_admin_referer(self::ACTION_WITHDRAW . '_' . $documentId)) {
            wp_die('Requête non autorisée.', 403);
        }

        try {
            (new DocumentLibrary())->withdraw($documentId, get_current_user_id());
        } catch (RuntimeException $e) {
            self::back($e->getMessage(), true);
        }

        self::back('Document retiré.');
    }

    /**
     * @return array{name: string, tmp_name: string, type: string, size: int, error: int}|null
     */
    private static function uploadedFile(): ?array
    {
        $file = $_FILES['file'] ?? null;

        if (!is_array($file) || !is_string($file['name'] ?? null)) {
            return null;
        }

        // Le fichier doit venir de ce téléversement, pas d'un chemin glissé
        // dans la requête.
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && !is_uploaded_file((string) $file['tmp_name'])) {
            return null;
        }

        return [
            'name'     => (string) $file['name'],
            'tmp_name' => (string) $file['tmp_name'],
            'type'     => (string) ($file['type'] ?? ''),
            'size'     => (int) ($file['size'] ?? 0),
            'error'    => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE),
        ];
    }

    private static function back(string $message, bool $isError = false): void
    {
        $url = remove_query_arg(['sub_done', 'sub_error'], wp_get_referer() ?: home_url('/'));

        wp_safe_redirect(add_query_arg(
            [$isError ? 'sub_error' : 'sub_done' => rawurlencode($message)],
            $url
        ) . '#deposer-un-document');
        exit;
    }
}
