<?php

declare(strict_types=1);

namespace Subalcatel\Club\Import;

/**
 * Entrée unique des images venant de l'ancien site.
 *
 * Toute image reprise passe par ici, qu'elle vienne du HTML d'un article
 * (encodée en base64) ou d'un fichier de l'arborescence Joomla. C'est
 * volontaire : du code de sécurité dupliqué est du code qui finit par diverger,
 * et la moitié oubliée devient la faille.
 *
 * ## Le principe : réécrire plutôt que reconnaître
 *
 * On ne cherche pas à *détecter* si une image est piégée — chercher « <?php »
 * dans du JPEG compressé produit des faux positifs à la pelle, et un attaquant
 * un peu sérieux sait de toute façon se cacher d'une liste de motifs.
 *
 * On reconstruit l'image à la place : GD lit les pixels et réécrit un fichier
 * neuf. Ce qui n'est pas de l'image n'a aucun moyen de traverser — charge
 * ajoutée en queue de fichier, commentaire EXIF piégé, polyglotte GIF/PHP.
 * C'est décisif ici : le dossier `images/` du Joomla contenait 36 webshells.
 *
 * Effet de bord bienvenu : les métadonnées EXIF disparaissent, dont les
 * coordonnées GPS que les photos de plongée embarquent souvent.
 */
final class MediaIntake
{
    /** Au-delà, on refuse : une photo d'article n'a pas à peser cela. */
    private const MAX_BYTES = 12 * 1024 * 1024;

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly Report $report)
    {
    }

    /**
     * Valide, réécrit et dépose une image. Renvoie son URL, ou null.
     *
     * @param string $raw     octets d'origine, de provenance non fiable
     * @param string $title   sert au nom de fichier et au titre du média
     * @param string $context décrit l'origine dans les avertissements
     */
    public function accept(string $raw, string $title, string $context, bool $dryRun): ?string
    {
        if ($raw === '' || strlen($raw) > self::MAX_BYTES) {
            $this->report->warn(sprintf(
                '%s : image de %d Ko écartée (limite %d Ko).',
                $context,
                (int) (strlen($raw) / 1024),
                (int) (self::MAX_BYTES / 1024)
            ));

            return null;
        }

        $info = @getimagesizefromstring($raw);

        if ($info === false || !isset(self::EXTENSIONS[$info['mime']])) {
            $this->report->warn(sprintf('%s : contenu qui n’est pas une image — écarté.', $context));

            return null;
        }

        $clean = $this->reencode($raw, (string) $info['mime']);

        if ($clean === null) {
            $this->report->warn(sprintf('%s : image illisible par GD — écartée.', $context));

            return null;
        }

        $filename = $this->filename($title, $clean, self::EXTENSIONS[$info['mime']]);

        if ($dryRun) {
            $this->report->add('medias', $filename, sprintf(
                '%d Ko, %dx%d — à verser dans la médiathèque',
                (int) (strlen($clean) / 1024),
                (int) $info[0],
                (int) $info[1]
            ));

            return 'about:blank';
        }

        return $this->store($clean, $filename, (string) $info['mime'], $title, $context, $info);
    }

    /**
     * @param  array<int|string, mixed> $info
     */
    private function store(
        string $bytes,
        string $filename,
        string $mime,
        string $title,
        string $context,
        array $info
    ): ?string {
        $upload = wp_upload_bits($filename, null, $bytes);

        if (!empty($upload['error'])) {
            $this->report->warn(sprintf('%s : dépôt impossible (%s).', $context, (string) $upload['error']));

            return null;
        }

        $attachmentId = wp_insert_attachment([
            'post_mime_type' => $mime,
            'post_title'     => Sanitizer::text($title, 120),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ], $upload['file']);

        if (is_wp_error($attachmentId) || (int) $attachmentId === 0) {
            $this->report->warn(sprintf('%s : image non enregistrée.', $context));

            return null;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata(
            (int) $attachmentId,
            wp_generate_attachment_metadata((int) $attachmentId, $upload['file'])
        );

        $this->report->add('medias', $filename, sprintf(
            '%d Ko, %dx%d — média %d',
            (int) (strlen($bytes) / 1024),
            (int) $info[0],
            (int) $info[1],
            (int) $attachmentId
        ));

        return (string) $upload['url'];
    }

    /**
     * Nom de fichier lisible et sans collision.
     *
     * `sanitize_title` laisse les accents sous forme percent-encodée
     * (« Pa¨ka » → « pa%c2%a8ka »), illisible dans la médiathèque : on
     * translittère d'abord.
     */
    private function filename(string $title, string $bytes, string $extension): string
    {
        $name = sanitize_file_name(remove_accents($title));
        $name = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-');
        $name = strtolower(mb_substr($name, 0, 48)) ?: 'image-reprise';

        return sprintf('%s-%s.%s', $name, substr(md5($bytes), 0, 8), $extension);
    }

    /**
     * Réécrit l'image à partir de ses seuls pixels.
     */
    private function reencode(string $raw, string $mime): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }

        $image = @imagecreatefromstring($raw);

        if (!$image instanceof \GdImage) {
            return null;
        }

        if (in_array($mime, ['image/png', 'image/webp'], true)) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        // Même qualité que WordPress applique à ses propres redimensionnements,
        // pour ne pas produire des fichiers plus lourds que l'original.
        $quality = (int) apply_filters('jpeg_quality', 82, 'image_resize');

        ob_start();

        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($image, null, $quality),
            'image/png'  => imagepng($image),
            'image/gif'  => imagegif($image),
            'image/webp' => imagewebp($image, null, $quality),
            default      => false,
        };

        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $ok && $bytes !== '' ? $bytes : null;
    }
}
