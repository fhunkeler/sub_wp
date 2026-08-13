<?php

declare(strict_types=1);

namespace Subalcatel\Club\Documents;

use RuntimeException;
use Subalcatel\Club\Documents\Storage\LocalAdapter;
use Subalcatel\Club\Documents\Storage\S3Adapter;
use Subalcatel\Club\Documents\Storage\StorageAdapter;
use Subalcatel\Club\Documents\Storage\WebDavAdapter;

/**
 * Point d'entrée du stockage des documents.
 *
 * Deux protections, indépendantes du support choisi :
 *
 * 1. **Aucun fichier n'est servi directement.** Le contenu passe toujours par
 *    PHP, qui vérifie les droits avant d'envoyer quoi que ce soit. Aucune URL
 *    publique n'est jamais produite, même sur un stockage distant.
 * 2. **Les documents sensibles sont chiffrés avant de quitter le serveur.**
 *    C'est ce qui rend un stockage externe acceptable pour un certificat
 *    médical : le prestataire n'héberge que des octets illisibles.
 *
 * Le nom de fichier est aléatoire : rien ne relie un objet à un membre.
 */
final class DocumentStorage
{
    public const OPTION      = 'subalcatel_storage';
    private const KEY_OPTION = 'subalcatel_document_key';

    public const DRIVER_LOCAL  = 'local';
    public const DRIVER_S3     = 's3';
    public const DRIVER_WEBDAV = 'webdav';

    private static ?StorageAdapter $adapter = null;

    /**
     * @return array<string, string>
     */
    public static function drivers(): array
    {
        return [
            self::DRIVER_LOCAL  => 'Disque du serveur',
            self::DRIVER_S3     => 'Stockage objet compatible S3',
            self::DRIVER_WEBDAV => 'Partage WebDAV',
        ];
    }

    /**
     * Réglages enregistrés, complétés par les valeurs par défaut.
     *
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        $stored = get_option(self::OPTION, []);

        return array_merge([
            'driver'     => self::DRIVER_LOCAL,
            'path'       => '',
            'endpoint'   => '',
            'region'     => 'fr-par',
            'bucket'     => '',
            'access_key' => '',
            'secret_key' => '',
            'prefix'     => 'documents',
            'path_style' => true,
            'url'        => '',
            'user'       => '',
            'password'   => '',
        ], is_array($stored) ? $stored : []);
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function saveSettings(array $settings): void
    {
        update_option(self::OPTION, $settings, false);
        self::$adapter = null;
    }

    /**
     * Adaptateur courant. Retombe sur le disque local si la configuration
     * distante est incomplète : mieux vaut un stockage qui fonctionne qu'un
     * site qui refuse tout dépôt.
     *
     * @param array<string, mixed>|null $override Pour tester une configuration sans l'enregistrer.
     */
    public static function adapter(?array $override = null): StorageAdapter
    {
        if ($override === null && self::$adapter !== null) {
            return self::$adapter;
        }

        $settings = $override ?? self::settings();

        try {
            $adapter = match ($settings['driver'] ?? self::DRIVER_LOCAL) {
                self::DRIVER_S3     => new S3Adapter($settings),
                self::DRIVER_WEBDAV => new WebDavAdapter($settings),
                default             => new LocalAdapter($settings),
            };
        } catch (RuntimeException $e) {
            if ($override !== null) {
                throw $e;
            }

            $adapter = new LocalAdapter($settings);
        }

        if ($override === null) {
            self::$adapter = $adapter;
        }

        return $adapter;
    }

    public static function driver(): string
    {
        return (string) self::settings()['driver'];
    }

    public static function isRemote(): bool
    {
        return self::driver() !== self::DRIVER_LOCAL;
    }

    /**
     * Enregistre un fichier téléversé et renvoie sa clé.
     *
     * Le préfixe sépare les espaces : les pièces des membres et les documents
     * du club ne se mélangent pas, et une purge côté membre ne peut pas
     * atteindre les statuts de l'association.
     *
     * @param array{tmp_name: string, name: string, type: string, size: int} $file
     */
    public static function store(array $file, bool $encrypt, string $prefix = ''): string
    {
        $extension = strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION));

        $key = sprintf(
            '%s%s/%s.%s%s',
            $prefix === '' ? '' : trim($prefix, '/') . '/',
            gmdate('Y/m'),
            bin2hex(random_bytes(16)),
            $extension,
            $encrypt ? '.enc' : ''
        );

        $contents = file_get_contents($file['tmp_name']);

        if ($contents === false) {
            throw new RuntimeException('Le fichier n’a pas pu être lu.');
        }

        // Le chiffrement a lieu AVANT l'envoi : un stockage distant ne reçoit
        // jamais de donnée de santé en clair.
        if ($encrypt) {
            $contents = self::encrypt($contents);
        }

        self::adapter()->put($key, $contents);

        return $key;
    }

    public static function read(string $key, bool $encrypted): string
    {
        $contents = self::adapter()->get($key);

        return $encrypted ? self::decrypt($contents) : $contents;
    }

    public static function delete(string $key): void
    {
        if ($key !== '') {
            self::adapter()->delete($key);
        }
    }

    public static function exists(string $key): bool
    {
        return $key !== '' && self::adapter()->exists($key);
    }

    /**
     * @param array<string, mixed>|null $settings
     * @return array{ok: bool, message: string}
     */
    public static function test(?array $settings = null): array
    {
        try {
            return self::adapter($settings)->test();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public static function describe(): string
    {
        try {
            return self::adapter()->describe();
        } catch (\Throwable $e) {
            return '—';
        }
    }

    /**
     * Le stockage local est-il hors de portée du serveur web ?
     *
     * Sans objet pour un stockage distant, qui n'est de toute façon jamais
     * exposé par le serveur web du site.
     */
    public static function isOutsideWebRoot(): bool
    {
        $adapter = self::adapter();

        return $adapter instanceof LocalAdapter ? $adapter->isOutsideWebRoot() : true;
    }

    // ------------------------------------------------------------ Chiffrement

    private static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        // Le nonce précède le message : il n'est pas secret, mais doit être unique.
        return $nonce . sodium_crypto_secretbox($plain, $nonce, self::key());
    }

    private static function decrypt(string $cipher): string
    {
        $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

        if (strlen($cipher) <= $nonceLength) {
            throw new RuntimeException('Fichier chiffré illisible.');
        }

        $plain = sodium_crypto_secretbox_open(
            substr($cipher, $nonceLength),
            substr($cipher, 0, $nonceLength),
            self::key()
        );

        if ($plain === false) {
            throw new RuntimeException(
                'Déchiffrement impossible : la clé a changé ou le fichier est corrompu.'
            );
        }

        return $plain;
    }

    /**
     * Clé de chiffrement.
     *
     * En production elle appartient à `wp-config.php` : une base compromise ne
     * doit pas livrer de quoi déchiffrer les fichiers. À défaut, elle est
     * générée et stockée en option — moins protecteur, et signalé au bureau.
     */
    private static function key(): string
    {
        if (defined('SUBALCATEL_DOC_KEY')) {
            $raw = (string) constant('SUBALCATEL_DOC_KEY');

            return strlen($raw) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES
                ? $raw
                : sodium_crypto_generichash($raw, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        }

        $stored = get_option(self::KEY_OPTION, '');

        if (!is_string($stored) || $stored === '') {
            $stored = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
            update_option(self::KEY_OPTION, $stored, false);
        }

        return (string) base64_decode($stored, true);
    }

    public static function keyIsInConfig(): bool
    {
        return defined('SUBALCATEL_DOC_KEY');
    }
}
