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
    /** Un choix parmi plusieurs, en boutons radio. */
    public const INPUT_SINGLE = 'single';

    /**
     * Une seule case à cocher. Cochée, c'est le premier choix ; décochée, le
     * second s'il existe, sinon rien. Posée pour les questions dont la réponse
     * est « non » dans l'immense majorité des cas — la licence déjà détenue —
     * où deux boutons radio font hésiter sur ce qu'il faut répondre.
     */
    public const INPUT_CHECK = 'check';

    /**
     * Aucune saisie : l'option s'applique d'elle-même dès qu'elle est visible.
     * La carte de niveau en est le cas type — elle est due dès qu'un niveau est
     * préparé, et le bureau ne veut pas laisser le choix.
     */
    public const INPUT_AUTO = 'auto';

    /**
     * @param list<array{value: string, label: string, amount: float, grants?: bool}> $choices
     * @param list<string> $conditionValues Valeurs de `conditionOption` qui rendent l'option visible.
     * @param list<string> $grants          Droits ouverts si l'option est retenue (ex. emprunt détendeur).
     * @param list<string> $plans           Plans concernés. Vide = tous.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $inputType = self::INPUT_SINGLE,
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

    /** L'option s'applique sans que l'adhérent ait à répondre. */
    public function isAutomatic(): bool
    {
        return $this->inputType === self::INPUT_AUTO;
    }

    /** L'option se pose en une seule case à cocher. */
    public function isCheckbox(): bool
    {
        return $this->inputType === self::INPUT_CHECK;
    }

    /**
     * Réponse retenue, une fois le type de saisie pris en compte.
     *
     * Une option automatique ne lit pas ce qui vient du navigateur : elle vaut
     * son premier choix dès qu'elle est visible. Une case à cocher décochée ne
     * poste rien, et vaut alors son second choix — le « non ».
     *
     * @param string|list<string>|null $posted
     * @return string|list<string>|null
     */
    public function answerFrom(string|array|null $posted): string|array|null
    {
        if ($this->isAutomatic()) {
            return isset($this->choices[0]) ? (string) $this->choices[0]['value'] : null;
        }

        if ($this->isCheckbox() && ($posted === null || $posted === '' || $posted === [])) {
            return isset($this->choices[1]) ? (string) $this->choices[1]['value'] : null;
        }

        return $posted;
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
        $lines = [];

        foreach ($this->selectedChoices($answer) as $choice) {
            $lines[] = [(string) $choice['label'], (float) $choice['amount']];
        }

        return $lines;
    }

    /**
     * Choix retenus par une réponse, dans l'ordre de la réponse.
     *
     * @param string|list<string> $answer
     * @return list<array{value: string, label: string, amount: float, grants?: bool}>
     */
    public function selectedChoices(string|array $answer): array
    {
        $selected = is_array($answer) ? $answer : [$answer];
        $found    = [];

        foreach ($selected as $value) {
            foreach ($this->choices as $choice) {
                if ((string) $choice['value'] === (string) $value) {
                    $found[] = $choice;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Droits ouverts par cette option, si elle est retenue.
     *
     * Les options « prêt bloc / détendeur / gilet » sont payées dès l'adhésion
     * alors que le module Emprunts n'arrive qu'en phase 8. Le droit est donc
     * enregistré maintenant : sans cela, chaque campagne écoulée produirait des
     * emprunts sans droit rattachable.
     *
     * Un choix peut ouvrir un droit sans rien coûter — le bloc de l'encadrant
     * qui s'engage à encadrer dix fois dans la saison. C'est ce que dit la clé
     * `grants` d'un choix ; à défaut, le montant tranche, « non » valant 0 €.
     *
     * @param string|list<string> $answer
     * @return list<string>
     */
    public function grantsFor(string|array $answer): array
    {
        if ($this->grants === []) {
            return [];
        }

        foreach ($this->selectedChoices($answer) as $choice) {
            if (self::choiceGrants($choice)) {
                return $this->grants;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $choice
     */
    public static function choiceGrants(array $choice): bool
    {
        return array_key_exists('grants', $choice)
            ? (bool) $choice['grants']
            : (float) ($choice['amount'] ?? 0) > 0;
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
            inputType: (string) ($row['input_type'] ?? self::INPUT_SINGLE),
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
