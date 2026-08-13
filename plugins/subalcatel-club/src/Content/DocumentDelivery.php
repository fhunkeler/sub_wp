<?php

declare(strict_types=1);

namespace Subalcatel\Club\Content;

use RuntimeException;
use Subalcatel\Club\Support\Audit;

/**
 * Le seul chemin vers un fichier du club.
 *
 * Aucune URL directe, aucune adresse devinable : le visiteur demande un
 * identifiant, le site vérifie ses droits, puis envoie les octets. C'est cette
 * règle qui protège, pas l'emplacement du fichier.
 */
final class DocumentDelivery
{
    public const ACTION = 'sub_club_document';

    public static function register(): void
    {
        add_action('admin_post_' . self::ACTION, [self::class, 'handle']);

        // Sans la variante `nopriv`, un document public renverrait un visiteur
        // déconnecté vers l'écran de connexion — l'inverse de « public ».
        add_action('admin_post_nopriv_' . self::ACTION, [self::class, 'handle']);
    }

    public static function url(int $documentId): string
    {
        return add_query_arg(
            ['action' => self::ACTION, 'document' => $documentId],
            admin_url('admin-post.php')
        );
    }

    public static function handle(): void
    {
        $documentId = isset($_GET['document']) ? absint($_GET['document']) : 0;

        if ($documentId === 0 || get_post_type($documentId) !== ClubDocuments::POST_TYPE) {
            wp_die('Document introuvable.', 'Document introuvable', ['response' => 404]);
        }

        try {
            $file = (new DocumentLibrary())->download($documentId);
        } catch (RuntimeException $e) {
            // Un stockage en panne n'est pas un refus d'accès. Les confondre
            // envoie le bénévole chercher un problème de droits pendant que le
            // vrai problème est un fichier illisible sur le disque.
            if (ClubDocuments::mayDownload($documentId)) {
                wp_die(
                    esc_html($e->getMessage()),
                    'Document indisponible',
                    ['response' => 500]
                );
            }

            $loggedIn = is_user_logged_in();

            wp_die(
                esc_html($e->getMessage()) . ($loggedIn ? '' : sprintf(
                    ' <a href="%s">Se connecter</a>',
                    esc_url(wp_login_url(self::url($documentId)))
                )),
                'Accès refusé',
                ['response' => $loggedIn ? 403 : 401]
            );
        }

        Audit::log('club_document.downloaded', 'club_document', $documentId, [
            'filename' => $file['filename'],
        ]);

        nocache_headers();
        header('Content-Type: ' . $file['mime']);
        header('Content-Length: ' . strlen($file['contents']));
        header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
        header('X-Content-Type-Options: nosniff');

        echo $file['contents'];
        exit;
    }
}
