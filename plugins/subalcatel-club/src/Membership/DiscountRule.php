<?php

declare(strict_types=1);

namespace Subalcatel\Club\Membership;

/**
 * Une remise : un forfait, plus des réductions par option.
 *
 * Ce modèle remplace le langage de formules d'OSMembership. La remise Nokia
 * s'écrivait :
 *
 *     -58.00 - [OSM_PRET_BLOC]*14/36 - [OSM_PRET_DETENDEUR]*0.40 - [OSM_PRET_GILET]*0.40
 *
 * Elle s'exprime ici par un forfait de -58 €, une réduction de 14 € sur le prêt
 * de bloc et de 40 % sur le détendeur et le gilet. Résultat identique, mais
 * saisissable par un bénévole — et sans évaluateur d'expressions à sécuriser.
 */
final class DiscountRule
{
    /**
     * @param list<string> $conditionValues Valeurs déclenchant la remise.
     * @param list<array{option: string, mode: string, value: float}> $perOption
     *        mode = 'percent' (pourcentage du montant de l'option) ou 'amount' (euros).
     * @param list<string> $plans Plans concernés. Vide = tous.
     */
    public function __construct(
        public readonly string $label,
        public readonly string $conditionOption,
        public readonly array $conditionValues,
        public readonly float $flatAmount = 0.0,
        public readonly array $perOption = [],
        public readonly array $plans = [],
        public readonly int $ordering = 0,
    ) {
    }

    public function appliesToPlan(string $planSlug): bool
    {
        return $this->plans === [] || in_array($planSlug, $this->plans, true);
    }

    /**
     * @param array<string, string|list<string>> $answers
     */
    public function matches(array $answers): bool
    {
        $value = $answers[$this->conditionOption] ?? null;

        if ($value === null) {
            return false;
        }

        $given = is_array($value) ? $value : [$value];

        return array_intersect($given, $this->conditionValues) !== [];
    }

    /**
     * Montant de la remise, toujours négatif ou nul.
     *
     * @param array<string, float> $optionAmounts Montants facturés par option.
     */
    public function amountFor(array $optionAmounts): float
    {
        $total = $this->flatAmount;

        foreach ($this->perOption as $reduction) {
            $charged = $optionAmounts[$reduction['option']] ?? 0.0;

            // Une réduction sur une option non souscrite vaut zéro : elle ne
            // peut pas générer de crédit.
            if ($charged <= 0.0) {
                continue;
            }

            $total -= $reduction['mode'] === 'percent'
                ? $charged * ((float) $reduction['value'] / 100)
                : min((float) $reduction['value'], $charged);
        }

        return round($total, 2);
    }

    /**
     * @param array<string, mixed> $row Ligne de la table sub_discount_rules.
     */
    public static function fromRow(array $row): self
    {
        $decode = static fn (mixed $json): array => is_string($json) && $json !== ''
            ? (array) (json_decode($json, true) ?: [])
            : [];

        return new self(
            label: (string) $row['label'],
            conditionOption: (string) $row['condition_option'],
            conditionValues: $decode($row['condition_values'] ?? null),
            flatAmount: (float) ($row['flat_amount'] ?? 0),
            perOption: $decode($row['per_option'] ?? null),
            plans: $decode($row['plans'] ?? null),
            ordering: (int) ($row['ordering'] ?? 0),
        );
    }
}
