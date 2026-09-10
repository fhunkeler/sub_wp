<?php

declare(strict_types=1);

namespace Subalcatel\Club\Membership;

use RuntimeException;

/**
 * Un dossier refusé faute de réponses, avec le détail de ce qui manque.
 *
 * Un message seul obligeait l'adhérent à relire tout le formulaire pour trouver
 * la case oubliée. Les noms techniques permettent au formulaire de la désigner.
 */
final class IncompleteApplication extends RuntimeException
{
    /**
     * @param array<string, string> $fields nom technique => libellé
     */
    public function __construct(public readonly array $fields)
    {
        parent::__construct(
            'Il manque une réponse : ' . implode(', ', $fields) . '.'
        );
    }
}
