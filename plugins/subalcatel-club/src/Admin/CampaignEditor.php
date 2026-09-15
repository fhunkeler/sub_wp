<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

use Subalcatel\Club\Membership\CampaignRepository;
use Subalcatel\Club\Membership\Option;
use Subalcatel\Club\Support\Audit;

/**
 * Configuration d'une campagne : formules, options, remises.
 *
 * C'est l'écran qui rend vraie la promesse de configurabilité. Tout ce qui
 * détermine le prix d'une adhésion se règle ici, sans toucher au code.
 *
 * Un parti pris d'ergonomie : les options et les remises s'éditent avec des
 * listes de lignes ajoutables, pas avec une syntaxe à respecter. Un trésorier
 * ne doit pas avoir à apprendre un format.
 */
final class CampaignEditor
{
    public const SLUG = 'subalcatel-campaign-edit';

    public static function register(): void
    {
        add_action('admin_post_sub_plan_save', [self::class, 'handlePlanSave']);
        add_action('admin_post_sub_plan_delete', [self::class, 'handlePlanDelete']);
        add_action('admin_post_sub_option_save', [self::class, 'handleOptionSave']);
        add_action('admin_post_sub_option_delete', [self::class, 'handleOptionDelete']);
        add_action('admin_post_sub_discount_save', [self::class, 'handleDiscountSave']);
        add_action('admin_post_sub_discount_delete', [self::class, 'handleDiscountDelete']);
    }

    public static function url(int $campaignId, string $tab = 'plans'): string
    {
        return admin_url(sprintf(
            'admin.php?page=%s&campaign_id=%d&tab=%s',
            self::SLUG,
            $campaignId,
            $tab
        ));
    }

    public static function render(): void
    {
        AdminUi::requireCap('sub_manage_memberships');
        AdminUi::enqueue();

        global $wpdb;

        $campaignId = absint($_GET['campaign_id'] ?? 0);
        $campaign   = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sub_campaigns WHERE id = %d",
            $campaignId
        ), ARRAY_A);

        if (!$campaign) {
            wp_die('Campagne introuvable.', 404);
        }

        $tab   = sanitize_key($_GET['tab'] ?? 'plans');
        $repo  = new CampaignRepository();
        $tabs  = ['plans' => 'Formules', 'options' => 'Options', 'discounts' => 'Remises'];
        ?>
        <div class="wrap sub-admin">
            <h1>
                <?php echo esc_html((string) $campaign['title']); ?>
                <?php echo AdminUi::statusBadge((string) $campaign['status']); ?>
            </h1>
            <p class="description">
                Inscriptions du <?php echo esc_html(AdminUi::frDate((string) $campaign['opens_on'])); ?>
                au <?php echo esc_html(AdminUi::frDate((string) $campaign['closes_on'])); ?> —
                adhésion valable du <?php echo esc_html(AdminUi::frDate((string) $campaign['valid_from'])); ?>
                au <?php echo esc_html(AdminUi::frDate((string) $campaign['valid_until'])); ?>.
                <a href="<?php echo esc_url(AdminUi::tabUrl(ApplicationsScreen::SLUG, CampaignsScreen::TAB)); ?>">
                    ← Toutes les campagnes
                </a>
            </p>

            <?php AdminUi::flash(); ?>

            <nav class="nav-tab-wrapper" style="margin-top:16px;">
                <?php foreach ($tabs as $key => $label) : ?>
                    <a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"
                       href="<?php echo esc_url(self::url($campaignId, $key)); ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div style="margin-top:20px;">
                <?php
                match ($tab) {
                    'options'   => self::renderOptions($campaignId, $repo),
                    'discounts' => self::renderDiscounts($campaignId, $repo),
                    default     => self::renderPlans($campaignId, $repo),
                };
                ?>
            </div>
        </div>
        <?php
    }

    // ---------------------------------------------------------------- Formules

    private static function renderPlans(int $campaignId, CampaignRepository $repo): void
    {
        $plans = $repo->plans($campaignId);
        ?>
        <p class="description">
            Une formule est un tarif de base. Les options viennent s’y ajouter.
        </p>

        <table class="wp-list-table widefat fixed striped sub-cards">
            <thead>
                <tr>
                    <th>Nom</th>
                    <th style="width:140px;">Identifiant</th>
                    <th style="width:120px;">Prix de base</th>
                    <th style="width:80px;">Ordre</th>
                    <th style="width:120px;"></th>
                </tr>
            </thead>
            <tbody>
            <?php if ($plans === []) : ?>
                <tr><td colspan="5">Aucune formule. Ajoutez-en une ci-dessous.</td></tr>
            <?php endif; ?>

            <?php foreach ($plans as $plan) : ?>
                <?php $formId = 'sub-plan-' . $plan->id; ?>
                <tr>
                    <td data-label="Nom">
                        <?php
                        // Un <form> ne peut pas traverser plusieurs <td> : le
                        // parseur HTML le referme. On déclare donc un formulaire
                        // vide, et chaque champ s'y rattache par form="…"
                        // (HTML5). C'est valide et cela évite les formulaires
                        // imbriqués avec le bouton de suppression.
                        ?>
                        <form id="<?php echo esc_attr($formId); ?>" method="post"
                              action="<?php echo esc_url(admin_url('admin-post.php')); ?>"></form>
                        <input type="hidden" form="<?php echo esc_attr($formId); ?>" name="action" value="sub_plan_save">
                        <input type="hidden" form="<?php echo esc_attr($formId); ?>" name="campaign_id" value="<?php echo esc_attr((string) $campaignId); ?>">
                        <input type="hidden" form="<?php echo esc_attr($formId); ?>" name="plan_id" value="<?php echo esc_attr((string) $plan->id); ?>">
                        <input type="hidden" form="<?php echo esc_attr($formId); ?>" name="_wpnonce"
                               value="<?php echo esc_attr(wp_create_nonce('sub_plan_save')); ?>">
                        <input type="text" form="<?php echo esc_attr($formId); ?>" name="title"
                               value="<?php echo esc_attr($plan->title); ?>" class="regular-text" required>
                    </td>
                    <td data-label="Identifiant"><code><?php echo esc_html($plan->slug); ?></code></td>
                    <td data-label="Prix de base">
                        <span class="sub-amount">
                            <input type="text" form="<?php echo esc_attr($formId); ?>" name="base_price"
                                   value="<?php echo esc_attr(number_format($plan->basePrice, 2, ',', '')); ?>"
                                   class="small-text" inputmode="decimal">
                            <span class="sub-amount__unit">€</span>
                        </span>
                    </td>
                    <td data-label="Ordre">
                        <input type="number" form="<?php echo esc_attr($formId); ?>" name="ordering"
                               value="<?php echo esc_attr((string) $plan->ordering); ?>" class="small-text">
                    </td>
                    <td data-label="Actions">
                        <button class="button button-primary" form="<?php echo esc_attr($formId); ?>">Enregistrer</button>
                        <?php AdminUi::actionButton(
                            'sub_plan_delete',
                            ['campaign_id' => $campaignId, 'plan_id' => $plan->id],
                            'Supprimer',
                            'button-link-delete button-link',
                            'Supprimer cette formule ?'
                        ); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h2 style="margin-top:28px;">Ajouter une formule</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sub-form">
            <input type="hidden" name="action" value="sub_plan_save">
            <input type="hidden" name="campaign_id" value="<?php echo esc_attr((string) $campaignId); ?>">
            <?php wp_nonce_field('sub_plan_save'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Nom</th>
                    <td><input type="text" name="title" class="regular-text" placeholder="Plongée" required></td>
                </tr>
                <tr>
                    <th scope="row">Prix de base</th>
                    <td><input type="text" name="base_price" class="small-text" inputmode="decimal" value="0"> €</td>
                </tr>
                <tr>
                    <th scope="row">Description</th>
                    <td><textarea name="description" rows="3" class="large-text"></textarea></td>
                </tr>
            </table>

            <p class="submit"><button class="button button-primary">Ajouter</button></p>
        </form>
        <?php
    }

    // ----------------------------------------------------------------- Options

    private static function renderOptions(int $campaignId, CampaignRepository $repo): void
    {
        $options = $repo->options($campaignId);
        $plans   = $repo->plans($campaignId);
        ?>
        <p class="description">
            Une option est une question posée à l’adhérent. Chaque réponse peut ajouter
            ou retrancher un montant. Une option peut n’apparaître que si une autre
            option a été répondue d’une certaine façon.
        </p>

        <?php foreach ($options as $option) : ?>
            <details class="sub-card">
                <summary>
                    <strong><?php echo esc_html($option->label); ?></strong>
                    <code><?php echo esc_html($option->name); ?></code>
                    <?php if ($option->isRequired) : ?><em>obligatoire</em><?php endif; ?>
                    <?php if ($option->conditionOption !== null) : ?>
                        <span class="sub-tag">
                            si <?php echo esc_html($option->conditionOption); ?>
                            = <?php echo esc_html(implode(' ou ', $option->conditionValues)); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($option->excludeOption !== null) : ?>
                        <span class="sub-tag sub-tag--exclu">
                            sauf si <?php echo esc_html($option->excludeOption); ?>
                            = <?php echo esc_html(implode(' ou ', $option->excludeValues)); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($option->grants !== []) : ?>
                        <span class="sub-tag sub-tag--grant">
                            ouvre : <?php echo esc_html(implode(', ', $option->grants)); ?>
                        </span>
                    <?php endif; ?>
                </summary>
                <?php self::optionForm($campaignId, $options, $plans, $option); ?>
            </details>
        <?php endforeach; ?>

        <h2 style="margin-top:28px;">Ajouter une option</h2>
        <div class="sub-card sub-card--open">
            <?php self::optionForm($campaignId, $options, $plans, null); ?>
        </div>
        <?php
    }

    /**
     * @param list<\Subalcatel\Club\Membership\Option> $allOptions
     * @param list<\Subalcatel\Club\Membership\Plan> $plans
     */
    private static function optionForm(
        int $campaignId,
        array $allOptions,
        array $plans,
        ?\Subalcatel\Club\Membership\Option $option,
    ): void {
        $isNew = $option === null;
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sub-form">
            <input type="hidden" name="action" value="sub_option_save">
            <input type="hidden" name="campaign_id" value="<?php echo esc_attr((string) $campaignId); ?>">
            <?php if (!$isNew) : ?>
                <input type="hidden" name="option_name" value="<?php echo esc_attr($option->name); ?>">
            <?php endif; ?>
            <?php wp_nonce_field('sub_option_save'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Libellé</th>
                    <td>
                        <input type="text" name="label" class="regular-text" required
                               value="<?php echo esc_attr($option?->label ?? ''); ?>"
                               placeholder="Prêt d’un détendeur">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Aide</th>
                    <td>
                        <input type="text" name="help" class="large-text"
                               value="<?php echo esc_attr($option?->help ?? ''); ?>"
                               placeholder="Texte affiché sous la question (facultatif)">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Comment la question se pose</th>
                    <td>
                        <select name="input_type">
                            <option value="<?php echo esc_attr(Option::INPUT_SINGLE); ?>"
                                    <?php selected($option?->inputType ?? Option::INPUT_SINGLE, Option::INPUT_SINGLE); ?>>
                                Un choix parmi plusieurs
                            </option>
                            <option value="<?php echo esc_attr(Option::INPUT_CHECK); ?>"
                                    <?php selected($option?->inputType ?? '', Option::INPUT_CHECK); ?>>
                                Une case à cocher — cochée, c’est la 1re réponse ; sinon la 2e
                            </option>
                            <option value="<?php echo esc_attr(Option::INPUT_AUTO); ?>"
                                    <?php selected($option?->inputType ?? '', Option::INPUT_AUTO); ?>>
                                Ajoutée d’office — la 1re réponse s’applique, sans choix
                            </option>
                        </select>
                        <p class="description">
                            « Ajoutée d’office » sert aux montants dus dès qu’une condition est
                            remplie, comme la carte de niveau : l’adhérent la voit dans son
                            récapitulatif, mais ne peut pas la refuser.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Réponse obligatoire</th>
                    <td>
                        <label>
                            <input type="checkbox" name="is_required" value="1"
                                   <?php checked($option?->isRequired ?? false); ?>>
                            L’adhérent doit répondre pour soumettre son dossier
                        </label>
                        <p class="description">
                            Sans objet pour une question ajoutée d’office ou posée en case à
                            cocher : l’une comme l’autre ont toujours une réponse.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Réponses possibles</th>
                    <td>
                        <table class="sub-repeat" data-repeat>
                            <thead>
                                <tr>
                                    <th style="width:180px;">Identifiant</th>
                                    <th>Ce que voit l’adhérent</th>
                                    <th style="width:120px;">Montant</th>
                                    <th style="width:110px;">Ouvre le droit</th>
                                    <th style="width:40px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            $choices = $option?->choices ?? [
                                ['value' => 'oui', 'label' => 'Oui', 'amount' => 0],
                                ['value' => 'non', 'label' => 'Non', 'amount' => 0],
                            ];
                            foreach ($choices as $choice) : ?>
                                <tr>
                                    <td data-label="Identifiant"><input type="text" name="choice_value[]" value="<?php echo esc_attr((string) $choice['value']); ?>"></td>
                                    <td data-label="Ce que voit l’adhérent"><input type="text" name="choice_label[]" value="<?php echo esc_attr((string) $choice['label']); ?>" class="regular-text"></td>
                                    <td data-label="Montant">
                                        <span class="sub-amount">
                                            <input type="text" name="choice_amount[]" inputmode="decimal" class="small-text"
                                                   value="<?php echo esc_attr(number_format((float) $choice['amount'], 2, ',', '')); ?>">
                                            <span class="sub-amount__unit">€</span>
                                        </span>
                                    </td>
                                    <?php // Une liste plutôt qu'une case : une case décochée ne poste
                                          // rien, et les réponses se décaleraient les unes sur les autres. ?>
                                    <td data-label="Ouvre le droit">
                                        <select name="choice_grants[]">
                                            <option value="auto" <?php selected(!array_key_exists('grants', $choice)); ?>>
                                                selon le montant
                                            </option>
                                            <option value="oui" <?php selected(!empty($choice['grants'])); ?>>oui</option>
                                            <option value="non"
                                                    <?php selected(array_key_exists('grants', $choice) && empty($choice['grants'])); ?>>
                                                non
                                            </option>
                                        </select>
                                    </td>
                                    <td class="sub-repeat__actions"><button type="button" class="button-link sub-repeat__remove" aria-label="Retirer">✕</button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <button type="button" class="button sub-repeat__add" data-repeat-add>+ Ajouter une réponse</button>
                        <p class="description">
                            Un montant négatif est une réduction. Exemple : <code>-49,00</code> pour une licence déjà détenue.
                        </p>
                        <p class="description">
                            « Ouvre le droit » ne vaut que si l’option ouvre un droit d’emprunt, plus bas.
                            <em>Selon le montant</em> convient presque toujours : une réponse payante ouvre le
                            droit, « non » ne l’ouvre pas. Forcez <em>oui</em> pour une réponse gratuite qui
                            l’ouvre quand même — le bloc prêté à l’encadrant.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Quand l’afficher ?</th>
                    <td>
                        <?php
                        $autres = array_values(array_filter(
                            $allOptions,
                            static fn (Option $other): bool => $option === null || $other->name !== $option->name
                        ));
                        ?>
                        <div class="sub-visibility" data-visibility>
                            <?php self::conditionField(
                                $autres,
                                $option?->conditionOption,
                                $option?->conditionValues ?? [],
                                'Toujours — quelles que soient les autres réponses',
                                'condition',
                                'inclusion',
                                'Ne l’afficher que si cette question reçoit…',
                            ); ?>

                            <?php self::conditionField(
                                $autres,
                                $option?->excludeOption,
                                $option?->excludeValues ?? [],
                                'Aucune exception',
                                'exclude',
                                'exclusion',
                                'Ne jamais l’afficher si cette question reçoit…',
                            ); ?>

                            <?php // Relire sa propre règle en français est le seul moyen de voir
                                  // qu'on s'est trompé de sens. Le JavaScript la recompose à chaque
                                  // clic ; sans lui, le serveur en a déjà posé une version juste. ?>
                            <p class="sub-visibility__summary" data-visibility-summary aria-live="polite">
                                <?php echo esc_html(self::visibilitySentence($option, $autres)); ?>
                            </p>
                        </div>

                        <p class="description">
                            La première règle restreint, la seconde excepte, et l’exception l’emporte.
                            La carte de niveau se sert de la première — elle ne s’affiche que pour les
                            niveaux cochés. La licence déjà détenue se sert de la seconde — elle vaut
                            pour tout le monde sauf les adhérents Nokia, dont le tarif la couvre.
                            Nommer le cas à écarter plutôt qu’énumérer tous les autres évite de voir
                            l’option disparaître le jour où une réponse nouvelle est créée.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Formules concernées</th>
                    <td>
                        <?php foreach ($plans as $plan) : ?>
                            <label style="margin-right:16px;">
                                <input type="checkbox" name="plans[]" value="<?php echo esc_attr($plan->slug); ?>"
                                       <?php checked(in_array($plan->slug, $option?->plans ?? [], true)); ?>>
                                <?php echo esc_html($plan->title); ?>
                            </label>
                        <?php endforeach; ?>
                        <p class="description">Aucune case cochée = l’option s’applique à toutes les formules.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Droits ouverts</th>
                    <td>
                        <input type="text" name="grants" class="regular-text"
                               value="<?php echo esc_attr(implode(', ', $option?->grants ?? [])); ?>"
                               placeholder="detendeur">
                        <p class="description">
                            Types de matériel que l’adhérent pourra emprunter s’il retient cette option
                            (montant supérieur à zéro).
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Ordre d’affichage</th>
                    <td><input type="number" name="ordering" class="small-text" value="<?php echo esc_attr((string) ($option?->ordering ?? 999)); ?>"></td>
                </tr>
            </table>

            <p class="submit">
                <button class="button button-primary"><?php echo $isNew ? 'Ajouter l’option' : 'Enregistrer'; ?></button>
            </p>
        </form>

        <?php
        // Le formulaire de suppression est un FRÈRE de celui d'édition, jamais
        // un enfant : des <form> imbriqués sont du HTML invalide, et le
        // navigateur les redécoupe de façon imprévisible.
        if (!$isNew) {
            AdminUi::actionButton(
                'sub_option_delete',
                ['campaign_id' => $campaignId, 'option_name' => $option->name],
                'Supprimer cette option',
                'button-link-delete button-link',
                'Supprimer cette option ?'
            );
        }
    }

    // ----------------------------------------------------------------- Remises

    private static function renderDiscounts(int $campaignId, CampaignRepository $repo): void
    {
        $rules   = $repo->discountRules($campaignId);
        $options = $repo->options($campaignId);
        $plans   = $repo->plans($campaignId);
        ?>
        <p class="description">
            Une remise combine un <strong>montant forfaitaire</strong> et des
            <strong>réductions sur certaines options</strong>, en euros ou en pourcentage.
            Aucune formule à écrire.
        </p>

        <?php foreach ($rules as $i => $rule) : ?>
            <details class="sub-card">
                <summary>
                    <strong><?php echo esc_html($rule->label); ?></strong>
                    <span class="sub-tag">
                        si <?php echo esc_html($rule->conditionOption); ?>
                        = <?php echo esc_html(implode(' ou ', $rule->conditionValues)); ?>
                    </span>
                    <code><?php echo esc_html(AdminUi::euro($rule->flatAmount)); ?></code>
                </summary>
                <?php self::discountForm($campaignId, $options, $plans, $rule, $i); ?>
            </details>
        <?php endforeach; ?>

        <h2 style="margin-top:28px;">Ajouter une remise</h2>
        <div class="sub-card sub-card--open">
            <?php self::discountForm($campaignId, $options, $plans, null, null); ?>
        </div>
        <?php
    }

    /**
     * @param list<\Subalcatel\Club\Membership\Option> $options
     * @param list<\Subalcatel\Club\Membership\Plan> $plans
     */
    private static function discountForm(
        int $campaignId,
        array $options,
        array $plans,
        ?\Subalcatel\Club\Membership\DiscountRule $rule,
        ?int $index,
    ): void {
        $isNew = $rule === null;
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sub-form">
            <input type="hidden" name="action" value="sub_discount_save">
            <input type="hidden" name="campaign_id" value="<?php echo esc_attr((string) $campaignId); ?>">
            <?php if (!$isNew) : ?>
                <input type="hidden" name="rule_label" value="<?php echo esc_attr($rule->label); ?>">
            <?php endif; ?>
            <?php wp_nonce_field('sub_discount_save'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Nom de la remise</th>
                    <td>
                        <input type="text" name="label" class="regular-text" required
                               value="<?php echo esc_attr($rule?->label ?? ''); ?>"
                               placeholder="Remise Nokia — plongée">
                        <p class="description">Ce nom apparaît sur le récapitulatif de l’adhérent.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">S’applique si…</th>
                    <td>
                        <?php self::conditionField(
                            $options,
                            $rule?->conditionOption,
                            $rule?->conditionValues ?? [],
                            '— choisir une question —',
                            'condition',
                            '',
                            'N’appliquer cette remise que si cette question reçoit…',
                        ); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Montant forfaitaire</th>
                    <td>
                        <input type="text" name="flat_amount" class="small-text" inputmode="decimal"
                               value="<?php echo esc_attr(number_format($rule?->flatAmount ?? 0, 2, ',', '')); ?>"> €
                        <p class="description">Négatif pour une remise. Exemple : <code>-58,00</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Réductions sur options</th>
                    <td>
                        <table class="sub-repeat" data-repeat>
                            <thead>
                                <tr>
                                    <th>Option</th>
                                    <th style="width:150px;">Type</th>
                                    <th style="width:110px;">Valeur</th>
                                    <th style="width:40px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            $reductions = $rule?->perOption ?? [];
                            if ($reductions === []) {
                                $reductions = [['option' => '', 'mode' => 'percent', 'value' => 0]];
                            }
                            foreach ($reductions as $red) : ?>
                                <tr>
                                    <td data-label="Option">
                                        <select name="red_option[]">
                                            <option value="">—</option>
                                            <?php foreach ($options as $o) : ?>
                                                <option value="<?php echo esc_attr($o->name); ?>"
                                                        <?php selected($red['option'], $o->name); ?>>
                                                    <?php echo esc_html($o->label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td data-label="Type">
                                        <select name="red_mode[]">
                                            <option value="percent" <?php selected($red['mode'], 'percent'); ?>>Pourcentage</option>
                                            <option value="amount" <?php selected($red['mode'], 'amount'); ?>>Montant fixe</option>
                                        </select>
                                    </td>
                                    <td data-label="Valeur"><input type="text" name="red_value[]" class="small-text" inputmode="decimal"
                                               value="<?php echo esc_attr(number_format((float) $red['value'], 2, ',', '')); ?>"></td>
                                    <td class="sub-repeat__actions"><button type="button" class="button-link sub-repeat__remove" aria-label="Retirer">✕</button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <button type="button" class="button sub-repeat__add" data-repeat-add>+ Ajouter une réduction</button>
                        <p class="description">
                            Une réduction sur une option non souscrite vaut zéro : elle ne peut pas créer de crédit.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Formules concernées</th>
                    <td>
                        <?php foreach ($plans as $plan) : ?>
                            <label style="margin-right:16px;">
                                <input type="checkbox" name="plans[]" value="<?php echo esc_attr($plan->slug); ?>"
                                       <?php checked(in_array($plan->slug, $rule?->plans ?? [], true)); ?>>
                                <?php echo esc_html($plan->title); ?>
                            </label>
                        <?php endforeach; ?>
                        <p class="description">Aucune case cochée = toutes les formules.</p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button class="button button-primary"><?php echo $isNew ? 'Ajouter la remise' : 'Enregistrer'; ?></button>
            </p>
        </form>

        <?php
        // Frère du formulaire d'édition, pas enfant — voir optionForm().
        if (!$isNew && $index !== null) {
            AdminUi::actionButton(
                'sub_discount_delete',
                ['campaign_id' => $campaignId, 'rule_label' => $rule->label],
                'Supprimer cette remise',
                'button-link-delete button-link',
                'Supprimer cette remise ?'
            );
        }
    }

    // ---------------------------------------------------------------- Actions

    public static function handlePlanSave(): void
    {
        check_admin_referer('sub_plan_save');
        AdminUi::requireCap('sub_manage_memberships');

        global $wpdb;

        $campaignId = absint($_POST['campaign_id'] ?? 0);
        $planId     = absint($_POST['plan_id'] ?? 0);
        $title      = sanitize_text_field(wp_unslash((string) ($_POST['title'] ?? '')));

        if ($title === '') {
            AdminUi::redirect(self::SLUG, 'Le nom de la formule est obligatoire.', true, ['campaign_id' => $campaignId]);
        }

        $data = [
            'title'      => $title,
            'base_price' => AdminUi::amount($_POST['base_price'] ?? 0),
            'ordering'   => absint($_POST['ordering'] ?? 0),
        ];

        if (isset($_POST['description'])) {
            $data['description'] = wp_kses_post(wp_unslash((string) $_POST['description']));
        }

        if ($planId > 0) {
            $wpdb->update("{$wpdb->prefix}sub_plans", $data, ['id' => $planId]);
            Audit::log('plan.updated', 'plan', $planId, $data);
        } else {
            $data['campaign_id'] = $campaignId;
            $data['slug']        = sanitize_title($title);
            $data['published']   = 1;
            $wpdb->insert("{$wpdb->prefix}sub_plans", $data);
            Audit::log('plan.created', 'plan', (int) $wpdb->insert_id, $data);
        }

        AdminUi::redirect(self::SLUG, 'Formule enregistrée.', false, ['campaign_id' => $campaignId, 'tab' => 'plans']);
    }

    public static function handlePlanDelete(): void
    {
        check_admin_referer('sub_plan_delete');
        AdminUi::requireCap('sub_manage_memberships');

        global $wpdb;

        $campaignId = absint($_POST['campaign_id'] ?? 0);
        $planId     = absint($_POST['plan_id'] ?? 0);

        // Une formule déjà souscrite n'est pas supprimée : elle est dépubliée,
        // sinon l'historique des dossiers perdrait sa référence.
        $used = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sub_applications WHERE plan_id = %d",
            $planId
        ));

        if ($used > 0) {
            $wpdb->update("{$wpdb->prefix}sub_plans", ['published' => 0], ['id' => $planId]);
            AdminUi::redirect(
                self::SLUG,
                sprintf('Formule retirée de l’affichage : %d dossier(s) y font référence.', $used),
                false,
                ['campaign_id' => $campaignId, 'tab' => 'plans']
            );
        }

        $wpdb->delete("{$wpdb->prefix}sub_plans", ['id' => $planId]);
        Audit::log('plan.deleted', 'plan', $planId);

        AdminUi::redirect(self::SLUG, 'Formule supprimée.', false, ['campaign_id' => $campaignId, 'tab' => 'plans']);
    }

    public static function handleOptionSave(): void
    {
        check_admin_referer('sub_option_save');
        AdminUi::requireCap('sub_manage_memberships');

        global $wpdb;

        $campaignId = absint($_POST['campaign_id'] ?? 0);
        $label      = sanitize_text_field(wp_unslash((string) ($_POST['label'] ?? '')));
        $existing   = sanitize_key(wp_unslash((string) ($_POST['option_name'] ?? '')));

        if ($label === '') {
            AdminUi::redirect(self::SLUG, 'Le libellé est obligatoire.', true, ['campaign_id' => $campaignId, 'tab' => 'options']);
        }

        // Les réponses sont saisies en lignes parallèles : on les recompose.
        $choices = [];
        $values  = (array) ($_POST['choice_value'] ?? []);
        $labels  = (array) ($_POST['choice_label'] ?? []);
        $amounts = (array) ($_POST['choice_amount'] ?? []);
        $grants  = (array) ($_POST['choice_grants'] ?? []);

        foreach ($values as $i => $value) {
            $value = sanitize_key(wp_unslash((string) $value));

            if ($value === '') {
                continue;
            }

            $choice = [
                'value'  => $value,
                'label'  => sanitize_text_field(wp_unslash((string) ($labels[$i] ?? $value))),
                'amount' => AdminUi::amount($amounts[$i] ?? 0),
            ];

            // Absente, la clé laisse le montant trancher. Écrite, elle l'emporte :
            // c'est ainsi qu'une réponse gratuite ouvre quand même un droit.
            $grant = sanitize_key(wp_unslash((string) ($grants[$i] ?? 'auto')));

            if ($grant === 'oui' || $grant === 'non') {
                $choice['grants'] = $grant === 'oui';
            }

            $choices[] = $choice;
        }

        if ($choices === []) {
            AdminUi::redirect(self::SLUG, 'Une option doit proposer au moins une réponse.', true, ['campaign_id' => $campaignId, 'tab' => 'options']);
        }

        $data = [
            'label'            => $label,
            'help'             => sanitize_text_field(wp_unslash((string) ($_POST['help'] ?? ''))),
            'input_type'       => self::inputType($_POST['input_type'] ?? ''),
            'is_required'      => isset($_POST['is_required']) ? 1 : 0,
            'choices'          => wp_json_encode($choices),
            'condition_option' => sanitize_key(wp_unslash((string) ($_POST['condition_option'] ?? ''))) ?: null,
            'condition_values' => wp_json_encode(self::conditionValues($_POST['condition_values'] ?? [])),
            'exclude_option'   => sanitize_key(wp_unslash((string) ($_POST['exclude_option'] ?? ''))) ?: null,
            'exclude_values'   => wp_json_encode(self::conditionValues($_POST['exclude_values'] ?? [])),
            'grants'           => wp_json_encode(self::csv($_POST['grants'] ?? '')),
            'plans'            => wp_json_encode(array_map('sanitize_key', (array) ($_POST['plans'] ?? []))),
            'ordering'         => absint($_POST['ordering'] ?? 999),
        ];

        if ($existing !== '') {
            $wpdb->update("{$wpdb->prefix}sub_options", $data, ['campaign_id' => $campaignId, 'name' => $existing]);
            Audit::log('option.updated', 'option', null, ['name' => $existing]);
        } else {
            $data['campaign_id'] = $campaignId;
            $data['name']        = self::uniqueOptionName($campaignId, $label);
            $wpdb->insert("{$wpdb->prefix}sub_options", $data);
            Audit::log('option.created', 'option', null, ['name' => $data['name']]);
        }

        AdminUi::redirect(self::SLUG, 'Option enregistrée.', false, ['campaign_id' => $campaignId, 'tab' => 'options']);
    }

    public static function handleOptionDelete(): void
    {
        check_admin_referer('sub_option_delete');
        AdminUi::requireCap('sub_manage_memberships');

        global $wpdb;

        $campaignId = absint($_POST['campaign_id'] ?? 0);
        $name       = sanitize_key(wp_unslash((string) ($_POST['option_name'] ?? '')));

        $wpdb->delete("{$wpdb->prefix}sub_options", ['campaign_id' => $campaignId, 'name' => $name]);
        Audit::log('option.deleted', 'option', null, ['name' => $name]);

        AdminUi::redirect(self::SLUG, 'Option supprimée.', false, ['campaign_id' => $campaignId, 'tab' => 'options']);
    }

    public static function handleDiscountSave(): void
    {
        check_admin_referer('sub_discount_save');
        AdminUi::requireCap('sub_manage_memberships');

        global $wpdb;

        $campaignId = absint($_POST['campaign_id'] ?? 0);
        $label      = sanitize_text_field(wp_unslash((string) ($_POST['label'] ?? '')));
        $existing   = sanitize_text_field(wp_unslash((string) ($_POST['rule_label'] ?? '')));

        if ($label === '') {
            AdminUi::redirect(self::SLUG, 'Le nom de la remise est obligatoire.', true, ['campaign_id' => $campaignId, 'tab' => 'discounts']);
        }

        $reductions = [];
        $options    = (array) ($_POST['red_option'] ?? []);
        $modes      = (array) ($_POST['red_mode'] ?? []);
        $rawValues  = (array) ($_POST['red_value'] ?? []);

        foreach ($options as $i => $optionName) {
            $optionName = sanitize_key(wp_unslash((string) $optionName));

            if ($optionName === '') {
                continue;
            }

            $reductions[] = [
                'option' => $optionName,
                'mode'   => ($modes[$i] ?? 'percent') === 'amount' ? 'amount' : 'percent',
                'value'  => abs(AdminUi::amount($rawValues[$i] ?? 0)),
            ];
        }

        $data = [
            'label'            => $label,
            'condition_option' => sanitize_key(wp_unslash((string) ($_POST['condition_option'] ?? ''))),
            'condition_values' => wp_json_encode(self::conditionValues($_POST['condition_values'] ?? [])),
            'flat_amount'      => AdminUi::amount($_POST['flat_amount'] ?? 0),
            'per_option'       => wp_json_encode($reductions),
            'plans'            => wp_json_encode(array_map('sanitize_key', (array) ($_POST['plans'] ?? []))),
        ];

        if ($existing !== '') {
            $wpdb->update("{$wpdb->prefix}sub_discount_rules", $data, ['campaign_id' => $campaignId, 'label' => $existing]);
        } else {
            $data['campaign_id'] = $campaignId;
            $wpdb->insert("{$wpdb->prefix}sub_discount_rules", $data);
        }

        Audit::log('discount.saved', 'discount_rule', null, ['label' => $label]);

        AdminUi::redirect(self::SLUG, 'Remise enregistrée.', false, ['campaign_id' => $campaignId, 'tab' => 'discounts']);
    }

    public static function handleDiscountDelete(): void
    {
        check_admin_referer('sub_discount_delete');
        AdminUi::requireCap('sub_manage_memberships');

        global $wpdb;

        $campaignId = absint($_POST['campaign_id'] ?? 0);
        $label      = sanitize_text_field(wp_unslash((string) ($_POST['rule_label'] ?? '')));

        $wpdb->delete("{$wpdb->prefix}sub_discount_rules", ['campaign_id' => $campaignId, 'label' => $label]);
        Audit::log('discount.deleted', 'discount_rule', null, ['label' => $label]);

        AdminUi::redirect(self::SLUG, 'Remise supprimée.', false, ['campaign_id' => $campaignId, 'tab' => 'discounts']);
    }

    /**
     * @return list<string>
     */
    /**
     * Type de saisie retenu, ramené à ceux que le formulaire sait rendre.
     */
    private static function inputType(mixed $raw): string
    {
        $value = sanitize_key(wp_unslash((string) $raw));

        return in_array($value, [Option::INPUT_SINGLE, Option::INPUT_CHECK, Option::INPUT_AUTO], true)
            ? $value
            : Option::INPUT_SINGLE;
    }

    /**
     * « N'afficher que si telle question vaut telle réponse. »
     *
     * Les réponses se désignaient autrefois en tapant leurs identifiants
     * techniques, séparés par des virgules. Deux ennuis, tous deux vérifiés :
     * personne ne connaît par cœur le nom interne d'une réponse, et surtout rien
     * ne rappelle d'y revenir. Ajouter P1 à la liste des niveaux laissait la
     * carte de niveau accrochée aux anciens — donc non facturée, sans le moindre
     * message.
     *
     * Les réponses réellement existantes sont donc proposées à cocher. Un groupe
     * par question, celui de la question retenue affiché, les autres neutralisés :
     * une case désactivée ne poste rien, ce qui évite de mêler les réponses d'une
     * question à la condition d'une autre.
     *
     * @param list<Option> $options
     * @param list<string> $values
     */
    private static function conditionField(
        array $options,
        ?string $selected,
        array $values,
        string $emptyLabel,
        string $field = 'condition',
        string $tone = '',
        string $lead = '',
    ): void {
        ?>
        <div class="sub-condition <?php echo $tone === '' ? '' : 'sub-condition--' . esc_attr($tone); ?>"
             data-condition>
            <?php if ($lead !== '') : ?>
                <p class="sub-condition__lead"><?php echo esc_html($lead); ?></p>
            <?php endif; ?>

            <select name="<?php echo esc_attr($field); ?>_option" data-condition-option>
                <option value=""><?php echo esc_html($emptyLabel); ?></option>
                <?php foreach ($options as $other) : ?>
                    <option value="<?php echo esc_attr($other->name); ?>"
                            <?php selected($selected, $other->name); ?>>
                        <?php echo esc_html($other->label); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php // Les réponses descendent sous la question, précédées de ce qu'elles
                  // font. « vaut » suivi de deux cases « Oui » « Non » ne disait pas ce
                  // que cocher voulait dire, et deux règles de sens opposé se lisaient
                  // pareil. ?>
            <div class="sub-condition__answers" data-condition-answers
                 <?php echo $selected === null ? 'hidden' : ''; ?>>
                <p class="sub-condition__hint">Cochez la ou les réponses concernées :</p>

                <?php foreach ($options as $other) : ?>
                    <?php $actif = $selected === $other->name; ?>
                    <div class="sub-condition__choices"
                         data-condition-for="<?php echo esc_attr($other->name); ?>"
                         <?php echo $actif ? '' : 'hidden'; ?>>
                        <?php if ($other->choices === []) : ?>
                            <em>Cette question n’a aucune réponse à cocher.</em>
                        <?php endif; ?>
                        <?php foreach ($other->choices as $choice) : ?>
                            <label class="sub-condition__choice">
                                <input type="checkbox" name="<?php echo esc_attr($field); ?>_values[]"
                                       value="<?php echo esc_attr((string) $choice['value']); ?>"
                                       <?php checked(in_array((string) $choice['value'], $values, true)); ?>
                                       <?php disabled(!$actif); ?>>
                                <span><?php echo esc_html((string) $choice['label']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * La règle d'affichage d'une option, dite en français.
     *
     * Relire sa propre règle est le seul moyen de s'apercevoir qu'on l'a posée à
     * l'envers. Le JavaScript la recompose à chaque clic ; celle-ci est la
     * version servie par le serveur, à l'ouverture du formulaire.
     *
     * Les libellés, jamais les noms techniques : c'est le même principe que les
     * cases à cocher qui ont remplacé la saisie d'identifiants.
     *
     * @param list<Option> $allOptions
     */
    private static function visibilitySentence(?Option $option, array $allOptions): string
    {
        if ($option === null || ($option->conditionOption === null && $option->excludeOption === null)) {
            return 'Toujours affichée, quelles que soient les autres réponses.';
        }

        $phrases = [];

        foreach ([
            ['nom' => $option->conditionOption, 'valeurs' => $option->conditionValues, 'inclus' => true],
            ['nom' => $option->excludeOption,   'valeurs' => $option->excludeValues,   'inclus' => false],
        ] as $regle) {
            if ($regle['nom'] === null) {
                continue;
            }

            $cible   = self::optionNamed($allOptions, (string) $regle['nom']);
            $question = $cible?->label ?? (string) $regle['nom'];

            if ($regle['valeurs'] === []) {
                $phrases[] = $regle['inclus']
                    ? sprintf('Aucune réponse cochée sur « %s » : la question ne s’afficherait jamais.', $question)
                    : sprintf('Aucune réponse cochée sur « %s » : cette exception ne fait rien.', $question);

                continue;
            }

            $phrases[] = sprintf(
                $regle['inclus'] ? 'Affichée seulement si « %s » vaut %s.' : 'Jamais affichée si « %s » vaut %s.',
                $question,
                self::enumerate(self::choiceLabels($cible, $regle['valeurs']))
            );
        }

        return implode(' ', $phrases);
    }

    /**
     * @param list<Option> $options
     */
    private static function optionNamed(array $options, string $name): ?Option
    {
        foreach ($options as $option) {
            if ($option->name === $name) {
                return $option;
            }
        }

        return null;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function choiceLabels(?Option $option, array $values): array
    {
        if ($option === null) {
            return $values;
        }

        return array_map(
            static function (string $value) use ($option): string {
                foreach ($option->choices as $choice) {
                    if ((string) $choice['value'] === $value) {
                        return (string) $choice['label'];
                    }
                }

                // Une réponse qui n'existe plus : la nommer telle quelle plutôt
                // que la taire, c'est ce qui la fera corriger.
                return $value;
            },
            $values
        );
    }

    /**
     * « a, b ou c » — la virgule partout sauf devant le dernier.
     *
     * @param list<string> $items
     */
    private static function enumerate(array $items): string
    {
        if (count($items) <= 1) {
            return implode('', $items);
        }

        $dernier = array_pop($items);

        return implode(', ', $items) . ' ou ' . $dernier;
    }

    /**
     * Les réponses retenues pour une condition.
     *
     * Elles arrivent désormais cochées — donc en tableau. La lecture d'une liste
     * séparée par des virgules reste acceptée : rien ne garantit qu'aucun
     * formulaire encore ouvert dans un onglet ne la poste plus.
     *
     * @return list<string>
     */
    private static function conditionValues(mixed $raw): array
    {
        if (!is_array($raw)) {
            return self::csv($raw);
        }

        return array_values(array_filter(array_map(
            static fn (mixed $v): string => sanitize_key((string) wp_unslash((string) $v)),
            $raw
        )));
    }

    private static function csv(mixed $raw): array
    {
        $parts = array_filter(array_map(
            static fn (string $v): string => sanitize_key(trim($v)),
            explode(',', (string) wp_unslash((string) $raw))
        ));

        return array_values($parts);
    }

    private static function uniqueOptionName(int $campaignId, string $label): string
    {
        global $wpdb;

        $base = sanitize_key(str_replace('-', '_', sanitize_title($label))) ?: 'option';
        $name = $base;
        $i    = 2;

        while ($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sub_options WHERE campaign_id = %d AND name = %s",
            $campaignId,
            $name
        ))) {
            $name = $base . '_' . $i++;
        }

        return $name;
    }
}
