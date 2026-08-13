<?php

declare(strict_types=1);

namespace Subalcatel\Club\Documents\Storage;

use RuntimeException;

/**
 * Stockage sur le disque du serveur.
 *
 * L'option par défaut, et celle qui reste recommandée : aucun sous-traitant à
 * inscrire au registre RGPD, aucune clé d'accès à gérer, aucune panne réseau
 * entre le site et ses documents.
 */
final class LocalAdapter implements StorageAdapter
{
    private string $baseDir;

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(array $settings = [])
    {
        $configured = trim((string) ($settings['path'] ?? ''));

        if (defined('SUBALCATEL_PRIVATE_DIR')) {
            // La constante prime : c'est le réglage de production, hors racine web.
            $this->baseDir = (string) constant('SUBALCATEL_PRIVATE_DIR');
        } elseif ($configured !== '') {
            $this->baseDir = $configured;
        } else {
            $uploads       = wp_upload_dir();
            $this->baseDir = rtrim((string) $uploads['basedir'], '/\\') . '/subalcatel-private';
        }

        if (!is_dir($this->baseDir)) {
            wp_mkdir_p($this->baseDir);
        }

        $this->lockDown();
    }

    public function put(string $key, string $contents): void
    {
        $target    = $this->path($key);
        $directory = dirname($target);

        if (!is_dir($directory) && !wp_mkdir_p($directory)) {
            throw new RuntimeException(sprintf(
                'Impossible de créer le répertoire %s. Vérifiez les droits d’écriture.',
                $directory
            ));
        }

        if (!is_writable($directory)) {
            // Cas courant : un import lancé en ligne de commande sous un autre
            // compte a créé le répertoire, que le serveur web ne peut plus
            // alimenter. Le dire évite de chercher du côté de la configuration.
            throw new RuntimeException(sprintf(
                'Le répertoire %s existe mais n’est pas accessible en écriture par le compte '
                . 'qui fait tourner le site. Vérifiez son propriétaire.',
                $directory
            ));
        }

        if (file_put_contents($target, $contents) === false) {
            throw new RuntimeException('Le fichier n’a pas pu être enregistré sur le disque.');
        }

        // 0600 par défaut. Un import lancé en ligne de commande sous un autre
        // compte produirait des fichiers illisibles par le serveur web : d'où
        // FS_CHMOD_FILE, qui permet d'assouplir si l'hébergement l'impose.
        chmod($target, defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0o600);
    }

    public function get(string $key): string
    {
        $path = $this->path($key);

        // Distinguer les deux cas : un fichier absent et un fichier présent mais
        // illisible n'ont ni la même cause ni le même remède.
        if (!file_exists($path)) {
            throw new RuntimeException('Fichier introuvable.');
        }

        if (!is_readable($path)) {
            throw new RuntimeException(
                'Fichier présent mais illisible : vérifiez le compte propriétaire '
                . 'et les droits du répertoire de stockage.'
            );
        }

        return (string) file_get_contents($path);
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function exists(string $key): bool
    {
        return is_file($this->path($key));
    }

    public function test(): array
    {
        $key = '_test/' . bin2hex(random_bytes(8)) . '.txt';

        try {
            $this->put($key, 'test');
            $ok = $this->get($key) === 'test';
            $this->delete($key);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        if (!$ok) {
            return ['ok' => false, 'message' => 'Le fichier relu ne correspond pas à ce qui a été écrit.'];
        }

        return [
            'ok'      => true,
            'message' => $this->isOutsideWebRoot()
                ? 'Écriture et lecture confirmées, hors de la racine web.'
                : 'Écriture et lecture confirmées. Le dossier reste dans la racine web : '
                    . 'définissez SUBALCATEL_PRIVATE_DIR pour l’en sortir.',
        ];
    }

    public function describe(): string
    {
        return $this->baseDir;
    }

    public function isOutsideWebRoot(): bool
    {
        $dir  = realpath($this->baseDir) ?: '';
        $root = realpath(ABSPATH) ?: '';

        return $dir !== '' && $root !== '' && !str_starts_with($dir, $root);
    }

    private function path(string $key): string
    {
        return rtrim($this->baseDir, '/\\') . '/' . ltrim($key, '/');
    }

    /**
     * Interdit l'accès direct au dossier, autant que le serveur le permet.
     */
    private function lockDown(): void
    {
        $files = [
            // Apache : refus d'accès. Le second bloc couvre les versions sans
            // mod_authz_core (Apache 2.2).
            '.htaccess'  => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
                // Ceinture et bretelles : même si une réécriture rendait un
                // fichier accessible, aucun ne serait exécuté comme du PHP.
                . "php_flag engine off\n"
                . "<FilesMatch \"\\.(?i:php|phtml|phar|php[0-9]|pl|py|cgi|asp)$\">\nRequire all denied\n</FilesMatch>\n",
            // IIS : refus d'accès.
            'web.config' => '<?xml version="1.0"?><configuration><system.webServer>'
                . '<authorization><deny users="*" /></authorization>'
                . '</system.webServer></configuration>',
            // PHP-FPM lit ce fichier quel que soit le serveur web devant lui —
            // c'est la protection qui survit à un passage d'Apache à nginx, où
            // le .htaccess est ignoré et où la faille se rouvrirait en silence.
            '.user.ini'  => "engine = Off\n",
            'index.php'  => "<?php\n// Silence.\n",
        ];

        foreach ($files as $name => $contents) {
            $path = $this->baseDir . '/' . $name;

            if (!file_exists($path)) {
                file_put_contents($path, $contents);
            }
        }
    }
}
