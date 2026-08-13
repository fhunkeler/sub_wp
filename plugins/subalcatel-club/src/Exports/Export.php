<?php

declare(strict_types=1);

namespace Subalcatel\Club\Exports;

/**
 * Un export : un titre, une capacité, des colonnes, des lignes.
 *
 * La liste des exports est fermée — pas de constructeur de requêtes dans
 * l'interface. Un tel outil finit par n'être compris de personne et par
 * exposer plus que prévu.
 *
 * Deux règles valent pour tous :
 *
 * 1. **Un export ne contient jamais plus que ce que la personne voit à
 *    l'écran.** La capacité filtre les colonnes, pas seulement le bouton.
 * 2. **Jamais de fichier.** Un export est une liste, pas une archive : ni
 *    certificat médical, ni pièce jointe.
 */
abstract class Export
{
    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function description(): string;

    /**
     * Capacité requise pour produire cet export.
     */
    abstract public function capability(): string;

    /**
     * @return list<string>
     */
    abstract public function columns(): array;

    /**
     * @param array<string, mixed> $args
     * @return list<list<string|int|float>>
     */
    abstract public function rows(array $args = []): array;

    /**
     * Cet export porte-t-il des données personnelles ?
     *
     * Détermine la journalisation : sortir la liste des adhérents se trace,
     * pas une liste de tarifs.
     */
    public function containsPersonalData(): bool
    {
        return true;
    }

    /**
     * Nom du fichier, sans extension.
     */
    public function filename(): string
    {
        return sprintf('%s-%s', sanitize_title($this->label()), current_time('Y-m-d'));
    }

    public function isAllowed(?int $userId = null): bool
    {
        return $userId === null
            ? current_user_can($this->capability())
            : user_can($userId, $this->capability());
    }
}
