<?php

declare(strict_types=1);

namespace Subalcatel\Club\Support;

/**
 * Les en-têtes d'un fichier servi par PHP après contrôle des droits.
 *
 * Le bureau consulte les certificats médicaux sans vouloir les ranger dans son
 * dossier Téléchargements : un PDF ou une image s'ouvre donc dans le
 * navigateur. Ce n'est pas une protection — un fichier affiché est déjà sur le
 * poste, et « Enregistrer sous » reste à un clic. C'est un confort.
 *
 * Le revers d'un affichage `inline` : le navigateur interprète le fichier sur
 * le domaine du club. Un adhérent qui déposerait du HTML maquillé en PDF
 * obtiendrait un script exécuté avec la session du bureau. D'où trois règles :
 *
 *   - le type vient de l'extension, par liste blanche, jamais de ce que le
 *     navigateur de l'adhérent a déclaré au dépôt ;
 *   - `nosniff` interdit au navigateur de deviner un autre type ;
 *   - la CSP n'autorise aucun script, et isole les images dans un bac à sable.
 *
 * Tout le reste (Word, tableur, archive) part en téléchargement, comme avant.
 */
final class FileResponse
{
    /**
     * Les seuls types affichés dans le navigateur.
     *
     * @var array<string, string>
     */
    public const INLINE = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
    ];

    /**
     * Aucun script, aucune connexion sortante, aucun formulaire.
     *
     * `sandbox` est réservé aux images : la visionneuse PDF de Chrome refuse de
     * s'ouvrir dans un document isolé et laisse une page grise. Pour un PDF,
     * `default-src 'none'` suffit — sa visionneuse tourne hors de la page, et
     * aucun script du domaine ne peut s'exécuter.
     */
    private const CSP_PDF   = "default-src 'none'; object-src 'self'; "
        . "frame-ancestors 'self'; form-action 'none'; base-uri 'none'";
    private const CSP_OTHER = "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; "
        . "frame-ancestors 'self'; form-action 'none'; base-uri 'none'; sandbox";

    /**
     * @return array<string, string> nom d'en-tête => valeur
     */
    public static function headers(string $filename, string $declaredMime, int $length): array
    {
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $inline    = isset(self::INLINE[$extension]);
        $mime      = $inline ? self::INLINE[$extension] : self::safeMime($declaredMime);

        return [
            'Content-Type'            => $mime,
            'Content-Length'          => (string) $length,
            'Content-Disposition'     => ($inline ? 'inline' : 'attachment') . '; ' . self::filenameParams($filename),
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Security-Policy' => $mime === 'application/pdf' ? self::CSP_PDF : self::CSP_OTHER,
            'Cache-Control'           => 'private, no-store, max-age=0',
            'Referrer-Policy'         => 'no-referrer',
        ];
    }

    public static function send(string $filename, string $declaredMime, string $body): never
    {
        nocache_headers();

        foreach (self::headers($filename, $declaredMime, strlen($body)) as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /**
     * Un type déclaré n'est gardé que s'il ne peut rien exécuter.
     */
    private static function safeMime(string $declared): string
    {
        $declared = strtolower(trim(explode(';', $declared)[0]));

        if (preg_match('#^application/[a-z0-9.+-]+$#', $declared) !== 1
            || preg_match('#html|javascript|ecmascript|x-sh|php|xml$#', $declared) === 1) {
            return 'application/octet-stream';
        }

        return $declared;
    }

    /**
     * `filename` en ASCII pour les vieux lecteurs, `filename*` en UTF-8 pour
     * garder les accents (RFC 6266). Ni guillemet ni retour à la ligne : un nom
     * de fichier ne doit pas pouvoir ajouter un en-tête.
     */
    private static function filenameParams(string $filename): string
    {
        $clean = trim(str_replace(['"', '\\', "\r", "\n", '/'], '', $filename));
        $clean = $clean !== '' ? $clean : 'document';
        $ascii = preg_replace('/[^\x20-\x7E]/u', '_', $clean) ?? preg_replace('/[^\x20-\x7E]/', '_', $clean);

        return sprintf('filename="%s"; filename*=UTF-8\'\'%s', $ascii, rawurlencode($clean));
    }
}
