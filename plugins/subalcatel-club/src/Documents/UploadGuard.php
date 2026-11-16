<?php

declare(strict_types=1);

namespace Subalcatel\Club\Documents;

use RuntimeException;

/**
 * Dernier filet avant l'écriture d'un fichier déposé.
 *
 * Le contrôle d'extension et de type MIME laisse passer les **polyglottes** :
 * un fichier qui commence par `%PDF-1.4` est un PDF valide aux yeux de
 * WordPress, même s'il contient `<?php system($_GET['c'])` juste après. Stocké
 * en clair dans la racine web, il ne demande qu'un serveur mal configuré — ou
 * un `.htaccess` ignoré sous nginx — pour devenir une porte d'entrée.
 *
 * C'est très exactement le vecteur du hack Joomla que ce projet remplace. On
 * refuse donc tout fichier portant une signature de code exécutable, quelle que
 * soit son extension.
 *
 * Ce garde ne remplace pas le stockage hors racine web ni la barrière
 * d'exécution du serveur : c'est une couche de plus, pas la seule.
 */
final class UploadGuard
{
    /**
     * Motifs de code exécutable serveur.
     *
     * Insensibles à la casse et aux variantes courtes (`<?=`). On vise ce qui
     * s'exécute côté serveur : PHP d'abord, puis les autres langages qu'un
     * hébergement mutualisé peut interpréter.
     *
     * @var list<string>
     */
    private const SIGNATURES = [
        '<?php',
        '<?=',
        '<%',              // ASP / JSP
        '<script language="php"',
        '#!/',            // shebang : script shell/perl/python
    ];

    /**
     * Combien d'octets inspecter.
     *
     * Un PDF légitime peut peser plusieurs mégaoctets ; scanner l'intégralité
     * à chaque dépôt est inutilement coûteux. Le code d'attaque est placé au
     * début, pour être atteint avant que le parseur d'images ne s'arrête. On
     * lit large — 1 Mo — pour couvrir aussi les charges enfouies après un
     * en-tête d'image volumineux.
     */
    private const SCAN_BYTES = 1024 * 1024;

    /**
     * Combien d'octets examiner après une signature, et quelle part doit être
     * du texte imprimable.
     *
     * **Sans ce contrôle de contexte, le garde refusait presque tout.** Chercher
     * `<%` — deux octets — dans un flux compressé, c'est chercher une suite qui
     * s'y trouve par hasard : sur un mégaoctet de données à forte entropie, on
     * l'attend seize fois. Autrement dit un certificat scanné ou photographié,
     * dès quelques centaines de kilo-octets, était rejeté à coup sûr. Mesuré :
     * 15 PDF de scan sur 15, et 9 photos sur 12, refusés à tort.
     *
     * Une charge PHP réelle, elle, est du texte : `<?php` y est suivi d'espaces,
     * de lettres, de ponctuation ASCII. Un octet tiré au hasard n'est imprimable
     * que dans 38 % des cas — exiger que le voisinage le soit presque entièrement
     * fait tomber le hasard sous le milliardième, sans rien retirer à la
     * détection d'un polyglotte véritable.
     *
     * La limite est assumée : un attaquant qui connaît ce contrôle peut ouvrir
     * sa balise sur un commentaire rempli de binaire. Ce garde n'a jamais été la
     * seule barrière — le fichier n'est de toute façon jamais servi par une URL,
     * et le dossier de stockage coupe le moteur PHP.
     */
    private const CONTEXT_BYTES = 24;
    private const CONTEXT_MIN   = 8;
    private const CONTEXT_RATIO = 0.9;

    /**
     * @throws RuntimeException si le contenu porte une signature exécutable
     */
    public static function rejectExecutable(string $path): void
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Le fichier n’a pas pu être lu pour vérification.');
        }

        $chunk = (string) fread($handle, self::SCAN_BYTES);
        fclose($handle);

        if (self::firstSignature($chunk) !== null) {
            throw new RuntimeException(
                'Ce fichier contient du code exécutable et ne peut pas être accepté. '
                . 'Si c’est un vrai document, ré-enregistrez-le depuis votre logiciel '
                . 'et réessayez.'
            );
        }
    }

    /**
     * Le contenu porte-t-il une signature de code ? Renvoie la première trouvée.
     *
     * Une signature ne suffit pas : elle doit être suivie de ce qui ressemble à
     * du code, faute de quoi on refuse des documents parfaitement sains. Voir
     * `CONTEXT_BYTES`, et [ascii85Regions] pour les flux PDF où ce critère ne
     * suffit plus.
     */
    public static function firstSignature(string $content): ?string
    {
        $regions = self::ascii85Regions($content);
        $cursor  = 0;

        // Le contenu est découpé en tronçons : hors flux ASCII85, le contrôle
        // habituel ; dans un flux ASCII85, le contrôle renforcé.
        foreach ($regions as [$start, $end]) {
            $found = self::scanSegment(substr($content, $cursor, $start - $cursor), false)
                ?? self::scanSegment(substr($content, $start, $end - $start), true);

            if ($found !== null) {
                return $found;
            }

            $cursor = $end;
        }

        return self::scanSegment(substr($content, $cursor), false);
    }

    private static function scanSegment(string $segment, bool $ascii85): ?string
    {
        if ($segment === '') {
            return null;
        }

        // `<?php` peut contenir un octet nul intercalé (`<\0?php`) pour tromper
        // une recherche naïve tout en restant exécutable après un filtre qui
        // supprime les nuls. On les retire avant de chercher.
        $original = str_replace("\0", '', $segment);
        $haystack = strtolower($original);

        foreach (self::SIGNATURES as $signature) {
            $offset = 0;

            while (($at = strpos($haystack, $signature, $offset)) !== false) {
                $from = $at + strlen($signature);

                if (self::readsAsCode($haystack, $from)
                    && (!$ascii85 || self::leavesAscii85($original, $from))) {
                    return $signature;
                }

                $offset = $at + 1;
            }
        }

        return null;
    }

    /**
     * Flux PDF codés en ASCII85 : positions [début, fin[ de leur contenu.
     *
     * **Le critère du texte imprimable n'y vaut rien.** L'ASCII85 traduit du
     * binaire en caractères de `!` à `u` : tout y est imprimable par
     * construction, et `<%`, `<?=` ou `#!/` y apparaissent par hasard, suivis
     * de vingt-quatre caractères « lisibles ». Un support de cours exporté
     * d'InDesign, sain, a été refusé ainsi : `<%` au milieu d'une vignette.
     *
     * Seuls comptent les flux dont le **premier** filtre est l'ASCII85 : c'est
     * alors lui qui fixe les octets écrits dans le fichier. Un flux
     * `FlateDecode` seul reste du binaire, que le contrôle habituel sait juger.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function ascii85Regions(string $content): array
    {
        // Le marqueur doit se trouver dans le premier kilo-octet (norme PDF).
        if (!str_contains(substr($content, 0, 1024), '%PDF-')) {
            return [];
        }

        $regions = [];
        $offset  = 0;

        while (preg_match('/stream\r?\n/', $content, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $keyword = (int) $m[0][1];
            $start   = $keyword + strlen($m[0][0]);
            $end     = strpos($content, 'endstream', $start);

            if ($end === false) {
                break;
            }

            // `endstream` contient lui-même « stream » : on repart après lui.
            $offset = $end + strlen('endstream');

            // Le dictionnaire du flux précède le mot-clé ; 2 Ko suffisent
            // largement, et bornent la lecture en arrière.
            $from       = max(0, $keyword - 2048);
            $dictionary = substr($content, $from, $keyword - $from);
            $open       = strrpos($dictionary, 'obj');
            $dictionary = $open === false ? $dictionary : substr($dictionary, $open);

            if (preg_match('#/Filter\s*\[?\s*/(ASCII85Decode|A85)\b#', $dictionary) === 1) {
                $regions[] = [$start, $end];
            }
        }

        return $regions;
    }

    /**
     * Après une signature trouvée dans un flux ASCII85 : un caractère que
     * l'ASCII85 n'emploie jamais apparaît-il dans le voisinage ?
     *
     * Des données ASCII85 n'en contiennent aucun : `v` à `y`, accolades, barre
     * verticale, et tout ce qui sort de l'ASCII. Du code en contient presque
     * toujours — `system`, `exec`, `{`, `$x`… Lu sur les octets d'origine :
     * la mise en minuscules ferait passer `V`…`Z`, valides, pour du code.
     *
     * La limite est la même que celle de `CONTEXT_BYTES` : une charge écrite
     * exprès dans l'alphabet de l'ASCII85 et rangée dans un flux qui s'en
     * réclame passerait. Le fichier n'est de toute façon jamais servi par une
     * URL, et le dossier de stockage coupe le moteur PHP.
     */
    private static function leavesAscii85(string $original, int $from): bool
    {
        $context = substr($original, $from, self::CONTEXT_BYTES);

        return preg_match('/[^\x21-\x75z\s]/', $context) === 1;
    }

    /**
     * Ce qui suit la signature ressemble-t-il à du code source ?
     */
    private static function readsAsCode(string $haystack, int $from): bool
    {
        $context = substr($haystack, $from, self::CONTEXT_BYTES);

        // Trop peu d'octets pour trancher : soit la signature tombe en fin de
        // fichier — où elle ne peut rien exécuter — soit au bord de la fenêtre
        // de lecture, et un verdict sur trois octets serait du tirage au sort.
        if (strlen($context) < self::CONTEXT_MIN) {
            return false;
        }

        $printable = strlen((string) preg_replace('/[^\x09\x0a\x0d\x20-\x7e]/', '', $context));

        return $printable >= (int) ceil(strlen($context) * self::CONTEXT_RATIO);
    }

    /**
     * Passe au crible tous les documents déjà stockés.
     *
     * `rejectExecutable` protège les dépôts faits **par l'interface** ; il ne
     * voit rien de ce qui entre autrement. Or la migration importera les 509
     * documents du Joomla en masse, directement en base — et le Joomla a été
     * compromis. Un polyglotte laissé par l'attaquant passerait sans être vu.
     *
     * Cette vérification relit chaque fichier stocké et signale ceux qui portent
     * une signature de code. À lancer après toute migration, et disponible pour
     * un contrôle d'intégrité à tout moment. Elle ne supprime rien : le tri des
     * faux positifs — un PDF qui cite du code dans son texte — reste humain.
     *
     * @param callable(int, string): void|null $reader résout une clé de stockage
     *        en contenu ; par défaut, le stockage du plugin
     * @return list<array{id: int, key: string, signature: string}>
     */
    public static function scanStored(?callable $reader = null): array
    {
        global $wpdb;

        $suspects = [];

        // 1. Documents personnels (certificats). Chiffrés : on déchiffre pour
        //    inspecter le clair, sinon on ne verrait que du bruit.
        $personal = $wpdb->get_results(
            "SELECT id, file_path, is_encrypted FROM {$wpdb->prefix}sub_member_documents WHERE file_path <> ''",
            ARRAY_A
        ) ?: [];

        foreach ($personal as $row) {
            $sig = self::inspect((int) $row['id'], (string) $row['file_path'], (int) $row['is_encrypted'] === 1);

            if ($sig !== null) {
                $suspects[] = ['id' => (int) $row['id'], 'key' => (string) $row['file_path'], 'signature' => $sig];
            }
        }

        // 2. Documents du club (jamais chiffrés).
        $club = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_sub_doc_key' AND meta_value <> ''"
        );

        foreach ($club as $key) {
            $sig = self::inspect(0, (string) $key, false);

            if ($sig !== null) {
                $suspects[] = ['id' => 0, 'key' => (string) $key, 'signature' => $sig];
            }
        }

        return $suspects;
    }

    private static function inspect(int $id, string $key, bool $encrypted): ?string
    {
        try {
            $content = DocumentStorage::read($key, $encrypted);
        } catch (\Throwable) {
            // Fichier illisible (clé changée, corruption) : ce n'est pas le rôle
            // de ce scan de le signaler — d'autres contrôles s'en chargent.
            return null;
        }

        return self::firstSignature(substr($content, 0, self::SCAN_BYTES));
    }
}
