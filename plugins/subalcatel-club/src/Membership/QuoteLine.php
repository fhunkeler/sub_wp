<?php

declare(strict_types=1);

namespace Subalcatel\Club\Membership;

/**
 * Une ligne du détail tarifaire.
 *
 * Ces lignes sont recopiées telles quelles dans sub_application_lines à la
 * soumission du dossier, montant compris. Changer un tarif l'année suivante ne
 * doit jamais réécrire la comptabilité de l'année passée.
 */
final class QuoteLine
{
    public function __construct(
        public readonly string $type,        // plan | option | discount
        public readonly ?string $sourceName,
        public readonly string $label,
        public readonly ?string $valueLabel,
        public readonly float $amount,
    ) {
    }
}
