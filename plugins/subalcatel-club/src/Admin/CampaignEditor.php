<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

use Subalcatel\Club\Membership\CampaignRepository;
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

        <table class="wp-list-table widefat fixed striped">
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
                    <td>
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
                    <td><code><?php echo esc_html($plan->slug); ?></code></td>
                    <td>
                        <input type="text" form="<?php echo esc_attr($formId); ?>" name="base_price"
                               value="<?php echo esc_attr(number_format($plan->basePrice, 2, ',', '')); ?>"
                               class="small-text" inputmode="decimal"> €
                    </td>
                    <td>
                        <input type="number" form="<?php echo esc_attr($formId); ?>" name="ordering"
                               value="<?php echo esc_attr((string) $plan->ordering); ?>" class="small-text">
                    </td>
                    <td>
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
                    <th scope="row">Réponse obligatoire</th>
                    <td>
                        <label>
                            <input type="checkbox" name="is_required" value="1"
                                   <?php checked($option?->isRequired ?? false); ?>>
                            L’adhérent doit répondre pour soumettre son dossier
                        </label>
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
                                    <td><input type="text" name="choice_value[]" value="<?php echo esc_attr((string) $choice['value']); ?>"></td>
                                    <td><input type="text" name="choice_label[]" value="<?php echo esc_attr((string) $choice['label']); ?>" class="regular-text"></td>
                                    <td><input type="text" name="choice_amount[]" inputmode="decimal" class="small-text"
                                               value="<?php echo esc_attr(number_format((float) $choice['amount'], 2, ',', '')); ?>"> €</td>
                                    <td><button type="button" class="button-link sub-repeat__remove" aria-label="Retirer">✕</button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <button type="button" class="button sub-repeat__add" data-repeat-add>+ Ajouter une réponse</button>
                        <p class="description">
                            Un montant négatif est une réduction. Exemple : <code>-49,00</code> pour une licence déjà détenue.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">N’afficher que si…</th>
                    <td>
                        <select name="condition_option">
                            <option value="">— toujours affichée —</option>
                            <?php foreach ($allOptions as $other) : ?>
                                <?php if ($option !== null && $other->name === $option->name) { continue; } ?>
                                <option value="<?php echo esc_attr($other->name); ?>"
                                        <?php selected($option?->conditionOption, $other->name); ?>>
                                    <?php echo esc_html($other->label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        vaut
                        <input type="text" name="condition_values" class="regular-text"
                               value="<?php echo esc_attr(implode(', ', $option?->conditionValues ?? [])); ?>"
                               placeholder="nokia, ce_orange">
                        <p class="description">Identifiants des réponses, séparés par des virgules.</p>
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
                        <select name="condition_option" required>
                            <option value="">— choisir une option —</option>
                            <?php foreach ($options as $o) : ?>
                                <option value="<?php echo esc_attr($o->name); ?>"
                                        <?php selected($rule?->conditionOption, $o->name); ?>>
                                    <?php echo esc_html($o->label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        vaut
                        <input type="text" name="condition_values" class="regular-text" required
                               value="<?php echo esc_attr(implode(', ', $rule?->conditionValues ?? [])); ?>"
                               placeholder="nokia">
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
                                    <td>
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
                                    <td>
                                        <select name="red_mode[]">
                                            <option value="percent" <?php selected($red['mode'], 'percent'); ?>>Pourcentage</option>
                                            <option value="amount" <?php selected($red['mode'], 'amount'); ?>>Montant fixe</option>
                                        </select>
                                    </td>
                                    <td><input type="text" name="red_value[]" class="small-text" inputmode="decimal"
                                               value="<?php echo esc_attr(number_format((float) $red['value'], 2, ',', '')); ?>"></td>
                                    <td><button type="button" class="button-link sub-repeat__remove" aria-label="Retirer">✕</button></td>
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

        foreach ($values as $i => $value) {
            $value = sanitize_key(wp_unslash((string) $value));

            if ($value === '') {
                continue;
            }

            $choices[] = [
                'value'  => $value,
                'label'  => sanitize_text_field(wp_unslash((string) ($labels[$i] ?? $value))),
                'amount' => AdminUi::amount($amounts[$i] ?? 0),
            ];
        }

        if ($choices === []) {
            AdminUi::redirect(self::SLUG, 'Une option doit proposer au moins une réponse.', true, ['campaign_id' => $campaignId, 'tab' => 'options']);
        }

        $data = [
            'label'            => $label,
            'help'             => sanitize_text_field(wp_unslash((string) ($_POST['help'] ?? ''))),
            'input_type'       => 'single',
            'is_required'      => isset($_POST['is_required']) ? 1 : 0,
            'choices'          => wp_json_encode($choices),
            'condition_option' => sanitize_key(wp_unslash((string) ($_POST['condition_option'] ?? ''))) ?: null,
            'condition_values' => wp_json_encode(self::csv($_POST['condition_values'] ?? '')),
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
            'condition_values' => wp_json_encode(self::csv($_POST['condition_values'] ?? '')),
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
