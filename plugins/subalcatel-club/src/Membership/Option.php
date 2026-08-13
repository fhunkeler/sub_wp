<?php

declare(strict_types=1);

namespace Subalcatel\Club\Membership;

/**
 * Une question posée à l'adhérent, avec son impact tarifaire.
 *
 * Chaque option est une donnée de configuration, pas du code : le bureau les
 * crée et les modifie depuis l'interface, campagne par campagne.
 */
final class Option
{
    /**
     * @param list<array{value: string, label: string, amount: float}> $choices
     * @param list<string> $conditionValues Valeurs de `conditionOption` qui rendent l'option visible.
     * @param list<string> $grants          Droits ouverts si l'option est retenue (ex. emprunt détendeur).
     * @param list<string> $plans           Plans concernés. Vide = tous.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $inputType = 'single',
        public readonly bool $isRequired = false,
        public readonly array $choices = [],
        public readonly ?string $conditionOption = null,
        public readonly array $conditionValues = [],
        public readonly array $grants = [],
        public readonly array $plans = [],
        public readonly string $help = '',
        public readonly int $ordering = 0,
    ) {
    }

    public function appliesToPlan(string $planSlug): bool
    {
        return $this->plans === [] || in_array($planSlug, $this->plans, true);
    }

    /**
     * L'option est-elle affichée, compte tenu des autres réponses ?
     *
     * @param array<string, string|list<string>> $answers
     */
    public function isVisible(array $answers): bool
    {
        if ($this->conditionOption === null) {
            return true;
        }

        $value = $answers[$this->conditionOption] ?? null;

        if ($value === null) {
            return false;
        }

        $given = is_array($value) ? $value : [$value];

        return array_intersect($given, $this->conditionValues) !== [];
    }

    /**
     * Traduit une réponse en lignes facturables.
     *
     * @param string|list<string> $answer
     * @return list<array{0: string, 1: float}> [libellé du choix, montant]
     */
    public function resolve(string|array $answer): array
    {
        $selected = is_array($answer) ? $answer : [$answer];
        $lines    = [];

        foreach ($selected as $value) {
            foreach ($this->choices as $choice) {
                if ((string) $choice['value'] === (string) $value) {
                    $lines[] = [$choice['label'], (float) $choice['amount']];
                    break;
                }
            }
        }

        return $lines;
    }

    /**
     * Droits ouverts par cette option, si elle est retenue.
     *
     * Les options « prêt bloc / détendeur / gilet / ordinateur » sont payées
     * dès l'adhésion alors que le module Emprunts n'arrive qu'en phase 8. Le
     * droit est donc enregistré maintenant : sans cela, chaque campagne écoulée
     * produirait des emprunts sans droit rattachable.
     *
     * @param string|list<string> $answer
     * @return list<string>
     */
    public function grantsFor(string|array $answer): array
    {
        if ($this->grants === []) {
            return [];
        }

        return $this->resolve($answer) === [] ? [] : $this->grants;
    }

    /**
     * @param array<string, mixed> $row Ligne de la table sub_options.
     */
    public static function fromRow(array $row): self
    {
        $decode = static fn (mixed $json): array => is_string($json) && $json !== ''
            ? (array) (json_decode($json, true) ?: [])
            : [];

        return new self(
            name: (string) $row['name'],
            label: (string) $row['label'],
            inputType: (string) ($row['input_type'] ?? 'single'),
            isRequired: (bool) ($row['is_required'] ?? false),
            choices: $decode($row['choices'] ?? null),
            conditionOption: ($row['condition_option'] ?? null) ?: null,
            conditionValues: $decode($row['condition_values'] ?? null),
            grants: $decode($row['grants'] ?? null),
            plans: $decode($row['plans'] ?? null),
            help: (string) ($row['help'] ?? ''),
            ordering: (int) ($row['ordering'] ?? 0),
        );
    }
}
