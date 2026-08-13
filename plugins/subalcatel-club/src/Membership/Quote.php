<?php

declare(strict_types=1);

namespace Subalcatel\Club\Membership;

/**
 * Détail tarifaire d'un dossier : les lignes et leur total.
 *
 * Le détail compte autant que le total. Un adhérent qui voit « Adhésion 210 € »
 * appelle le trésorier ; un adhérent qui voit sa remise Nokia ligne à ligne
 * comprend ce qu'il paie.
 */
final class Quote
{
    /**
     * @param list<QuoteLine> $lines
     */
    public function __construct(
        public readonly array $lines,
    ) {
    }

    public function total(): float
    {
        $total = 0.0;

        foreach ($this->lines as $line) {
            $total += $line->amount;
        }

        // Une adhésion ne peut pas être négative, quelles que soient les remises.
        return max(0.0, round($total, 2));
    }

    /**
     * Somme des lignes d'un type donné : 'plan', 'option' ou 'discount'.
     */
    public function subtotal(string $type): float
    {
        $total = 0.0;

        foreach ($this->lines as $line) {
            if ($line->type === $type) {
                $total += $line->amount;
            }
        }

        return round($total, 2);
    }

    /**
     * Rendu texte du détail, utilisé par les tests et les exports.
     */
    public function toText(): string
    {
        $out = '';

        foreach ($this->lines as $line) {
            $label = $line->valueLabel !== null && $line->valueLabel !== ''
                ? sprintf('%s (%s)', $line->label, $line->valueLabel)
                : $line->label;

            $out .= sprintf("  %-46s %10s\n", $label, number_format($line->amount, 2, ',', ' ') . ' €');
        }

        return $out . sprintf("  %-46s %10s\n", 'TOTAL', number_format($this->total(), 2, ',', ' ') . ' €');
    }

    /**
     * @return list<array{type: string, label: string, value: ?string, amount: float}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (QuoteLine $l): array => [
                'type'   => $l->type,
                'label'  => $l->label,
                'value'  => $l->valueLabel,
                'amount' => $l->amount,
            ],
            $this->lines
        );
    }
}
