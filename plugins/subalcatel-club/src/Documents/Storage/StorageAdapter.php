<?php

declare(strict_types=1);

namespace Subalcatel\Club\Documents\Storage;

/**
 * Un emplacement où déposer les documents des membres.
 *
 * Trois implémentations : disque local, stockage objet compatible S3, partage
 * WebDAV. Le contenu chiffré l'est avant d'arriver ici — un adaptateur ne voit
 * jamais que des octets.
 */
interface StorageAdapter
{
    /**
     * @throws \RuntimeException si l'écriture échoue
     */
    public function put(string $key, string $contents): void;

    /**
     * @throws \RuntimeException si la lecture échoue
     */
    public function get(string $key): string;

    public function delete(string $key): void;

    public function exists(string $key): bool;

    /**
     * Vérifie que la configuration fonctionne réellement.
     *
     * Écrit, relit et supprime un fichier témoin : c'est le seul moyen honnête
     * de dire à un bénévole que ses réglages sont bons.
     *
     * @return array{ok: bool, message: string}
     */
    public function test(): array;

    /**
     * Nom lisible, affiché dans l'écran de configuration.
     */
    public function describe(): string;
}
