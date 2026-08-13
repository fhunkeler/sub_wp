<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use Subalcatel\Club\Membership\CampaignRepository;
use Subalcatel\Club\Membership\Option;
use Subalcatel\Club\Membership\Plan;

/**
 * Grille tarifaire publique : shortcode [subalcatel_tarifs].
 *
 * Lit la campagne configurée plutôt que d'afficher des montants recopiés à la
 * main. Les tarifs changent chaque année ; une grille écrite en dur est fausse
 * dès la première campagne suivante, et personne ne pense à la corriger — c'est
 * exactement ce qui s'était produit sur le Joomla, où le cahier des charges
 * décrivait les tarifs 2022 alors que la base portait ceux de 2026.
 *
 * **Formules et options seulement.** Les remises n'y figurent pas, sur décision
 * du club : elles dépendent de la situation de chacun — employeur, âge — et une
 * grille publique qui les détaille invite à réclamer celle qu'on croit mériter.
 * Elles restent appliquées, automatiquement, dans le formulaire d'adhésion.
 *
 * Ce que cette page **ne fait pas** non plus : calculer. Le montant exact
 * dépend des réponses de l'adhérent et se calcule dans le formulaire, seul
 * endroit où le barème fait autorité. Ici, on informe.
 */
final class PricingTable
{
    public static function register(): void
    {
        add_shortcode('subalcatel_tarifs', [self::class, 'render']);
    }

    public static function render(): string
    {
        $repository = new CampaignRepository();
        $shown      = $repository->campaignToShow();

        if ($shown === null) {
            return '<div class="sub-notice"><p>La grille tarifaire n’est pas encore publiée. '
                . 'Elle est mise en ligne à l’ouverture de la campagne d’adhésion.</p></div>';
        }

        $campaign   = $shown['campaign'];
        $campaignId = (int) $campaign['id'];

        $plans   = $repository->plans($campaignId);
        $options = $repository->options($campaignId);

        wp_enqueue_style(
            'subalcatel-membership',
            \Subalcatel\Club\PLUGIN_URL . 'assets/css/membership.css',
            [],
            \Subalcatel\Club\VERSION
        );

        ob_start();

        echo '<div class="sub-pricing">';

        self::renderHeader($campaign, (bool) $shown['is_open']);
        self::renderPlans($plans, $options);
        self::renderOptions($options, $plans);
        self::renderCta((bool) $shown['is_open']);

        echo '</div>';

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $campaign
     */
    private static function renderHeader(array $campaign, bool $isOpen): void
    {
        ?>
        <p class="sub-pricing__season">
            <strong><?php echo esc_html((string) $campaign['title']); ?></strong> —
            adhésion valable du <?php echo esc_html(self::date((string) $campaign['valid_from'])); ?>
            au <?php echo esc_html(self::date((string) $campaign['valid_until'])); ?>.
        </p>

        <?php if (!$isOpen) : ?>
            <div class="sub-notice sub-notice--warning">
                <p>
                    <strong>Les inscriptions ne sont pas ouvertes.</strong>
                    Les montants ci-dessous sont ceux de la dernière campagne et vous donnent
                    un ordre de grandeur ; ils seront réévalués à l’ouverture de la suivante.
                </p>
            </div>
        <?php endif;
    }

    /**
     * @param list<Plan>   $plans
     * @param list<Option> $options
     */
    private static function renderPlans(array $plans, array $options): void
    {
        if ($plans === []) {
            return;
        }
        ?>
        <h2>Les formules</h2>
        <div class="sub-pricing__plans">
            <?php foreach ($plans as $plan) : ?>
                <article class="sub-plan">
                    <h3 class="sub-plan__title"><?php echo esc_html($plan->title); ?></h3>

                    <p class="sub-plan__price">
                        <span class="sub-plan__amount"><?php echo esc_html(self::money($plan->basePrice)); ?></span>
                        <span class="sub-plan__unit">pour la saison</span>
                    </p>

                    <?php if ($plan->description !== '') : ?>
                        <p class="sub-plan__desc"><?php echo esc_html($plan->description); ?></p>
                    <?php endif; ?>

                    <?php $required = self::requiredOptionsFor($plan, $options); ?>
                    <?php if ($required !== []) : ?>
                        <p class="sub-plan__note">
                            À compléter obligatoirement :
                            <?php echo esc_html(implode(', ', $required)); ?>.
                        </p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Options facturables, avec leurs montants.
     *
     * Les options sans aucun montant sont écartées : ce sont des questions
     * administratives — origine de l'adhésion, niveau préparé — qui n'ont rien
     * à faire dans une grille de prix.
     *
     * @param list<Option> $options
     * @param list<Plan>   $plans
     */
    private static function renderOptions(array $options, array $plans): void
    {
        $priced = array_values(array_filter($options, [self::class, 'hasPrice']));

        if ($priced === []) {
            return;
        }
        ?>
        <h2>Les options</h2>
        <p class="sub-help">
            Elles s’ajoutent à la formule choisie. Certaines ne concernent qu’une formule
            ou qu’une situation : le formulaire d’adhésion ne vous montrera que celles
            qui vous concernent.
        </p>

        <div class="sub-scroll">
            <table class="sub-pricing__table">
                <thead>
                    <tr>
                        <th scope="col">Option</th>
                        <th scope="col">Choix</th>
                        <th scope="col" class="sub-pricing__amount">Montant</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($priced as $option) : ?>
                    <?php $rows = self::pricedChoices($option); ?>
                    <?php foreach ($rows as $index => $choice) : ?>
                        <tr>
                            <?php if ($index === 0) : ?>
                                <th scope="row" rowspan="<?php echo count($rows); ?>">
                                    <?php echo esc_html($option->label); ?>
                                    <?php if ($option->isRequired) : ?>
                                        <span class="sub-tag">obligatoire</span>
                                    <?php endif; ?>
                                    <?php $scope = self::scopeOf($option, $plans, $options); ?>
                                    <?php if ($scope !== '') : ?>
                                        <span class="sub-pricing__scope"><?php echo esc_html($scope); ?></span>
                                    <?php endif; ?>
                                </th>
                            <?php endif; ?>
                            <td><?php echo esc_html((string) $choice['label']); ?></td>
                            <?php $amount = (float) $choice['amount']; ?>
                            <td class="sub-pricing__amount<?php echo $amount < 0 ? ' sub-pricing__amount--credit' : ''; ?>">
                                <?php if ($amount === 0.0) : ?>
                                    inclus
                                <?php elseif ($amount < 0) : ?>
                                    <?php // Un montant négatif est une déduction, pas un coût :
                                          // affiché comme les autres, il se lit de travers. ?>
                                    − <?php echo esc_html(self::money(abs($amount))); ?>
                                <?php else : ?>
                                    <?php echo esc_html(self::money($amount)); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function renderCta(bool $isOpen): void
    {
        $url = Pages::url(Pages::HOW_TO_JOIN);

        if ($url === '') {
            return;
        }
        ?>
        <div class="sub-pricing__cta">
            <p>
                <strong>Le montant exact dépend de votre situation.</strong>
                Des remises peuvent s’appliquer — comité d’entreprise, tarif jeune. Le
                formulaire d’adhésion affiche le détail du calcul au fur et à mesure,
                avant tout engagement.
            </p>
            <p>
                <a class="sub-button sub-button--inline" href="<?php echo esc_url($url); ?>">
                    <?php echo $isOpen ? 'Adhérer maintenant' : 'Voir comment adhérer'; ?>
                </a>
            </p>
        </div>
        <?php
    }

    // ------------------------------------------------------------------ Aides

    private static function hasPrice(Option $option): bool
    {
        foreach ($option->choices as $choice) {
            if ((float) ($choice['amount'] ?? 0) !== 0.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Choix retenus pour l'affichage.
     *
     * Un choix à zéro n'est montré que s'il cohabite avec un choix payant :
     * « sans assurance — inclus » éclaire le tableau, « non — 0 € » sur une
     * option binaire ne fait que l'allonger.
     *
     * @return list<array{label: string, amount: float}>
     */
    private static function pricedChoices(Option $option): array
    {
        $paid = [];
        $free = [];

        foreach ($option->choices as $choice) {
            $entry = ['label' => (string) $choice['label'], 'amount' => (float) ($choice['amount'] ?? 0)];

            if ($entry['amount'] === 0.0) {
                $free[] = $entry;
            } else {
                $paid[] = $entry;
            }
        }

        // Les déductions ferment la marche : on lit d'abord ce qu'on paie.
        usort($paid, static fn (array $a, array $b): int => ($a['amount'] < 0) <=> ($b['amount'] < 0));

        return count($paid) > 1 ? array_merge($paid, $free) : $paid;
    }

    /**
     * À qui s'adresse cette option, en une phrase courte.
     *
     * @param list<Plan>   $plans
     * @param list<Option> $options
     */
    private static function scopeOf(Option $option, array $plans, array $options): string
    {
        $parts = [];
        $scope = self::planScope($option->plans, $plans);

        if ($scope !== '') {
            $parts[] = trim($scope, '()');
        }

        if ($option->conditionOption !== null) {
            $parts[] = sprintf(
                'si %s',
                self::conditionText($option->conditionOption, $option->conditionValues, $options)
            );
        }

        return $parts === [] ? '' : '(' . implode(', ', $parts) . ')';
    }

    /**
     * @param list<string> $slugs
     * @param list<Plan>   $plans
     */
    private static function planScope(array $slugs, array $plans): string
    {
        if ($slugs === [] || count($slugs) >= count($plans)) {
            return '';
        }

        $titles = [];

        foreach ($plans as $plan) {
            if (in_array($plan->slug, $slugs, true)) {
                $titles[] = $plan->title;
            }
        }

        return $titles === [] ? '' : '(' . implode(' et ', $titles) . ' uniquement)';
    }

    /**
     * Traduit une condition en texte lisible.
     *
     * @param list<string> $values
     * @param list<Option> $options
     */
    private static function conditionText(string $optionName, array $values, array $options): string
    {
        $labels = [];

        foreach ($options as $option) {
            if ($option->name !== $optionName) {
                continue;
            }

            foreach ($option->choices as $choice) {
                if (in_array((string) $choice['value'], $values, true)) {
                    $labels[] = (string) $choice['label'];
                }
            }

            return sprintf(
                '%s : %s',
                mb_strtolower($option->label),
                $labels === [] ? implode(', ', $values) : implode(' ou ', $labels)
            );
        }

        return $optionName;
    }

    /**
     * @param list<Option> $options
     * @return list<string>
     */
    private static function requiredOptionsFor(Plan $plan, array $options): array
    {
        $labels = [];

        foreach ($options as $option) {
            if ($option->isRequired
                && $option->conditionOption === null
                && $option->appliesToPlan($plan->slug)
                && self::hasPrice($option)) {
                $labels[] = mb_strtolower($option->label);
            }
        }

        return $labels;
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2, ',', ' ') . ' €';
    }

    private static function date(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed === false ? $date : wp_date('j F Y', $parsed->getTimestamp());
    }
}
