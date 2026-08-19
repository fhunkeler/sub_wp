<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use Subalcatel\Club\Documents\DocumentService;
use Subalcatel\Club\Documents\DocumentTypes;

/**
 * Mes documents : shortcode [subalcatel_documents].
 *
 * Un écran, une question par document : est-il là, est-il valide, jusqu'à quand.
 * L'état prime sur le formulaire — un membre vient d'abord vérifier s'il est en
 * règle, et seulement ensuite déposer.
 */
final class DocumentsForm
{
    public static function register(): void
    {
        add_shortcode('subalcatel_documents', [self::class, 'render']);
        add_action('admin_post_sub_document_upload', [self::class, 'handleUpload']);
        add_action('admin_post_sub_document_download', [self::class, 'handleDownload']);
    }

    public static function render(): string
    {
        if (!is_user_logged_in()) {
            return sprintf(
                '<div class="sub-notice"><strong>Espace réservé aux membres</strong><p><a href="%s">Se connecter</a></p></div>',
                esc_url(wp_login_url(get_permalink()))
            );
        }

        wp_enqueue_style(
            'subalcatel-membership',
            \Subalcatel\Club\PLUGIN_URL . 'assets/css/membership.css',
            [],
            \Subalcatel\Club\VERSION
        );

        wp_enqueue_script(
            'subalcatel-documents',
            \Subalcatel\Club\PLUGIN_URL . 'assets/js/documents.js',
            [],
            \Subalcatel\Club\VERSION,
            true
        );

        $userId  = get_current_user_id();
        $service = new DocumentService();

        ob_start();

        echo Notice::fromQuery(); // déjà échappé

        echo '<div class="sub-documents">';

        foreach (DocumentTypes::all() as $type) {
            $required = DocumentTypes::isRequiredFor($type, $userId);

            // Un document facultatif non déposé n'encombre pas l'écran, mais
            // reste proposé plus bas.
            $document = $service->latest($userId, (string) $type['slug']);

            if (!$required && $document === null && (int) $type['is_required'] === 1) {
                continue;
            }

            self::renderType($type, $document, $required, $userId);
        }

        echo '</div>';

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $type
     * @param array<string, mixed>|null $document
     */
    private static function renderType(array $type, ?array $document, bool $required, int $userId): void
    {
        [$stateClass, $stateLabel, $detail] = self::describe($type, $document);
        ?>
        <section class="sub-doc sub-doc--<?php echo esc_attr($stateClass); ?>">
            <header class="sub-doc__head">
                <h3 class="sub-doc__title">
                    <?php echo esc_html((string) $type['label']); ?>
                    <?php if ($required) : ?>
                        <span class="sub-field__required" aria-label="obligatoire">*</span>
                    <?php endif; ?>
                </h3>
                <span class="sub-doc__state"><?php echo esc_html($stateLabel); ?></span>
            </header>

            <?php if ($detail !== '') : ?>
                <p class="sub-doc__detail"><?php echo esc_html($detail); ?></p>
            <?php endif; ?>

            <?php if (!empty($type['help'])) : ?>
                <p class="sub-doc__help"><?php echo esc_html((string) $type['help']); ?></p>
            <?php endif; ?>

            <?php if ($document !== null && $document['status'] !== DocumentService::STATUS_PURGED) : ?>
                <p class="sub-doc__file">
                    <a href="<?php echo esc_url(self::downloadUrl((int) $document['id'])); ?>">
                        Télécharger mon document
                    </a>
                    <span class="sub-doc__filename">
                        (<?php echo esc_html((string) $document['original_name']); ?>)
                    </span>
                </p>
            <?php endif; ?>

            <form class="sub-doc__form" method="post" enctype="multipart/form-data"
                  action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="sub_document_upload">
                <input type="hidden" name="type_slug" value="<?php echo esc_attr((string) $type['slug']); ?>">
                <?php wp_nonce_field('sub_document_upload_' . $type['slug']); ?>

                <p class="sub-input">
                    <label for="file-<?php echo esc_attr((string) $type['slug']); ?>">
                        <?php echo $document === null ? 'Déposer le document' : 'Remplacer le document'; ?>
                    </label>

                    <?php
                    // Le contrôle natif dessine lui-même son bouton et le nom du
                    // fichier, sur une seule ligne et sans qu'on puisse répartir
                    // la place : le bouton prend les deux tiers, et il ne reste au
                    // nom que de quoi afficher « certific...so.pdf ». On garde donc
                    // le champ — il porte l'étiquette, le focus et la validation —
                    // mais on le rend transparent au-dessus d'une présentation qui,
                    // elle, donne au nom toute la largeur restante.
                    ?>
                    <span class="sub-file">
                        <input type="file" id="file-<?php echo esc_attr((string) $type['slug']); ?>"
                               name="document" accept=".pdf,.jpg,.jpeg,.png" required
                               data-file-field>
                        <span class="sub-file__button" aria-hidden="true">Choisir un fichier</span>
                        <span class="sub-file__name" data-file-name aria-hidden="true">Aucun fichier choisi</span>
                    </span>

                    <small class="sub-input__help">PDF, JPG ou PNG — 8 Mo maximum.</small>
                </p>

                <?php if ((int) $type['has_validity'] === 1) : ?>
                    <p class="sub-input">
                        <label for="issued-<?php echo esc_attr((string) $type['slug']); ?>">
                            Date d’établissement
                        </label>
                        <input type="date" id="issued-<?php echo esc_attr((string) $type['slug']); ?>"
                               name="issued_on" max="<?php echo esc_attr(current_time('Y-m-d')); ?>" required>
                        <small class="sub-input__help">
                            La validité court <?php echo (int) $type['validity_months']; ?> mois à partir de cette date.
                        </small>
                    </p>
                <?php endif; ?>

                <button type="submit" class="sub-button">
                    <?php echo $document === null ? 'Envoyer' : 'Remplacer'; ?>
                </button>

                <?php if ($document !== null && $document['status'] !== DocumentService::STATUS_PURGED) : ?>
                    <small class="sub-doc__warning">
                        Le document actuel sera définitivement supprimé.
                    </small>
                <?php endif; ?>
            </form>
        </section>
        <?php
    }

    /**
     * État lisible d'un document : classe CSS, libellé, précision.
     *
     * @param array<string, mixed> $type
     * @param array<string, mixed>|null $document
     * @return array{0: string, 1: string, 2: string}
     */
    private static function describe(array $type, ?array $document): array
    {
        if ($document === null) {
            return ['missing', 'Non déposé', ''];
        }

        $validUntil = $document['valid_until'] !== null
            ? self::frDate((string) $document['valid_until'])
            : null;

        return match ($document['status']) {
            DocumentService::STATUS_PENDING => [
                'pending',
                'En attente de validation',
                'Votre document a bien été reçu. Le bureau le vérifiera prochainement.',
            ],
            DocumentService::STATUS_VALID => [
                'valid',
                'Valide',
                $validUntil !== null ? 'Valable jusqu’au ' . $validUntil . '.' : '',
            ],
            DocumentService::STATUS_REJECTED => [
                'rejected',
                'Refusé',
                (string) ($document['reject_reason'] ?: 'Merci d’en déposer un nouveau.'),
            ],
            DocumentService::STATUS_EXPIRED => [
                'expired',
                'Expiré',
                $validUntil !== null ? 'Expiré depuis le ' . $validUntil . '.' : '',
            ],
            DocumentService::STATUS_PURGED => [
                'missing',
                'Supprimé',
                'Ce document a été supprimé à l’échéance prévue. Déposez-en un nouveau.',
            ],
            default => ['missing', 'Non déposé', ''],
        };
    }

    public static function downloadUrl(int $documentId): string
    {
        return wp_nonce_url(
            add_query_arg(
                ['action' => 'sub_document_download', 'document' => $documentId],
                admin_url('admin-post.php')
            ),
            'sub_document_download_' . $documentId
        );
    }

    public static function handleUpload(): void
    {
        $typeSlug = isset($_POST['type_slug']) ? sanitize_key(wp_unslash($_POST['type_slug'])) : '';

        if (!is_user_logged_in() || !check_admin_referer('sub_document_upload_' . $typeSlug)) {
            wp_die('Requête non autorisée.', 403);
        }

        try {
            $file = $_FILES['document'] ?? null;

            if (!is_array($file)) {
                throw new \RuntimeException('Aucun fichier reçu.');
            }

            (new DocumentService())->upload(
                get_current_user_id(),
                $typeSlug,
                $file,
                isset($_POST['issued_on']) ? sanitize_text_field(wp_unslash($_POST['issued_on'])) : null,
                get_current_user_id()
            );

            self::back('Document envoyé. Le bureau le vérifiera prochainement.');
        } catch (\RuntimeException $e) {
            self::back($e->getMessage(), true);
        }
    }

    /**
     * Sert le fichier après contrôle des droits.
     *
     * Le fichier n'est jamais accessible par une URL directe : il est lu par
     * PHP, déchiffré si nécessaire, et envoyé en pièce jointe.
     */
    public static function handleDownload(): void
    {
        $documentId = isset($_GET['document']) ? absint($_GET['document']) : 0;

        if (!is_user_logged_in() || !check_admin_referer('sub_document_download_' . $documentId)) {
            wp_die('Requête non autorisée.', 403);
        }

        try {
            $file = (new DocumentService())->download($documentId, get_current_user_id());
        } catch (\RuntimeException $e) {
            wp_die(esc_html($e->getMessage()), 403);
        }

        nocache_headers();
        header('Content-Type: ' . $file['mime']);
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($file['name']) . '"');
        header('Content-Length: ' . strlen($file['body']));
        header('X-Content-Type-Options: nosniff');

        echo $file['body']; // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    private static function back(string $message, bool $isError = false): never
    {
        wp_safe_redirect(add_query_arg(
            [$isError ? 'sub_error' : 'sub_done' => rawurlencode($message)],
            wp_get_referer() ?: home_url('/')
        ));
        exit;
    }

    private static function frDate(string $isoDate): string
    {
        $ts = strtotime($isoDate);

        return $ts === false ? $isoDate : wp_date('j F Y', $ts);
    }
}
