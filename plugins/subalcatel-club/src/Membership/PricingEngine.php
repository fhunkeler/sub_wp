<?php

declare(strict_types=1);

namespace Subalcatel\Club\Membership;

/**
 * Moteur de tarification.
 *
 * C'est la fonctionnalité qu'aucune extension WordPress d'adhésion ne sait
 * faire, et la raison principale du développement sur mesure.
 *
 * Deux principes tiennent tout :
 *
 * 1. Le calcul est fait ICI, au serveur. Le récapitulatif affiché au navigateur
 *    est un confort ; le prix qui fait foi est celui que renvoie cette classe à
 *    la soumission.
 * 2. Aucune formule mathématique. Une remise = un forfait + des réductions par
 *    option, exprimées en euros ou en pourcentage. Ce modèle couvre l'intégralité
 *    des remises réellement utilisées par le club, et reste configurable par un
 *    bénévole (cf. §2 bis de la proposition).
 */
final class PricingEngine
{
    /**
     * Calcule le détail tarifaire d'un dossier.
     *
     * @param array<string, string|list<string>> $answers Réponses aux options,
     *        indexées par nom technique d'option.
     * @param list<Option> $options Options de la campagne.
     * @param list<DiscountRule> $rules Règles de remise de la campagne.
     */
    public function calculate(
        Plan $plan,
        array $answers,
        array $options,
        array $rules,
    ): Quote {
        $lines = [];

        // 1. Prix de base du plan.
        $lines[] = new QuoteLine(
            type: 'plan',
            sourceName: $plan->slug,
            label: $plan->title,
            valueLabel: null,
            amount: $plan->basePrice,
        );

        // 2. Options, dans l'ordre d'affichage. Une option masquée par une
        //    condition ne facture rien, même si une réponse traîne dans la
        //    requête — c'est ce qui empêche de forcer un tarif depuis le client.
        $optionAmounts = [];

        foreach ($options as $option) {
            if (!$option->appliesToPlan($plan->slug)) {
                continue;
            }

            if (!$option->isVisible($answers)) {
                continue;
            }

            $answer = $answers[$option->name] ?? null;
            if ($answer === null || $answer === '' || $answer === []) {
                continue;
            }

            foreach ($option->resolve($answer) as [$choiceLabel, $amount]) {
                $optionAmounts[$option->name] = ($optionAmounts[$option->name] ?? 0.0) + $amount;

                if (abs($amount) < 0.005) {
                    continue; // Un choix à 0 € n'encombre pas le récapitulatif.
                }

                $lines[] = new QuoteLine(
                    type: 'option',
                    sourceName: $option->name,
                    label: $option->label,
                    valueLabel: $choiceLabel,
                    amount: $amount,
                );
            }
        }

        // 3. Remises. Elles s'appuient sur les montants d'options réellement
        //    facturés ci-dessus : une remise de 40 % sur un prêt non souscrit
        //    vaut zéro, elle ne peut pas créer de crédit.
        foreach ($rules as $rule) {
            if (!$rule->appliesToPlan($plan->slug) || !$rule->matches($answers)) {
                continue;
            }

            $amount = $rule->amountFor($optionAmounts);

            if (abs($amount) < 0.005) {
                continue;
            }

            $lines[] = new QuoteLine(
                type: 'discount',
                sourceName: $rule->conditionOption,
                label: $rule->label,
                valueLabel: null,
                amount: $amount,
            );
        }

        return new Quote($lines);
    }
}
