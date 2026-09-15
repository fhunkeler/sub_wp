<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use Subalcatel\Club\Membership\Option;
use Subalcatel\Club\Membership\PaymentMethods;
use Subalcatel\Club\Membership\Plan;

/**
 * Les champs tarifaires d'un dossier : formule, options, règlement.
 *
 * Ils étaient dans {@see MembershipForm}, où ils ne servaient qu'une fois. Le
 * bureau corrige désormais un dossier depuis wp-admin, et il le corrige dans le
 * formulaire que l'adhérent a rempli — mêmes questions, même ordre, mêmes
 * règles d'affichage, mêmes attributs `data-*` pour le récapitulatif en direct.
 *
 * Un second formulaire, écrit pour l'administration, aurait divergé du premier
 * dès la première option ajoutée par le bureau. C'est précisément le genre
 * d'écart qui fait facturer deux montants différents pour la même adhésion.
 */
final class MembershipFields
{
    /**
     * Le choix de la formule.
     *
     * @param list<Plan> $plans
     */
    public static function plans(array $plans, Plan $chosen): void
    {
        ?>
        <fieldset class="sub-field">
            <legend>Formule d’adhésion</legend>
            <?php foreach ($plans as $candidate) : ?>
                <label class="sub-choice">
                    <input type="radio" name="plan" value="<?php echo esc_attr($candidate->slug); ?>"
                           <?php checked($candidate->slug, $chosen->slug); ?> required>
                    <span class="sub-choice__label"><?php echo esc_html($candidate->title); ?></span>
                    <span class="sub-choice__price"><?php echo esc_html(self::euro($candidate->basePrice)); ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <?php
    }

    /**
     * Une option de la campagne.
     *
     * @param array<string, string|list<string>> $answers Réponses déjà résolues.
     * @param array<string, string> $errors
     */
    public static function option(Option $option, Plan $plan, array $answers, array $errors = []): void
    {
        $shown = $option->appliesToPlan($plan->slug) && $option->isVisible($answers);
        $error = $errors[$option->name] ?? '';

        $attrs = sprintf(
            'data-option="%s" data-plans="%s"%s',
            esc_attr($option->name),
            esc_attr((string) wp_json_encode($option->plans)),
            $shown ? '' : ' hidden'
        );

        if ($option->conditionOption !== null) {
            $attrs .= sprintf(
                ' data-depends-on="%s" data-depends-values="%s"',
                esc_attr($option->conditionOption),
                esc_attr((string) wp_json_encode($option->conditionValues))
            );
        }

        $answer   = $answers[$option->name] ?? null;
        $selected = is_array($answer) ? $answer : [(string) $answer];
        ?>
        <fieldset class="sub-field <?php echo $error !== '' ? 'sub-field--error' : ''; ?>"
                  <?php echo $attrs; // phpcs:ignore ?>>
            <legend>
                <?php echo esc_html($option->label); ?>
                <?php if ($option->isAutomatic()) : ?>
                    <span class="sub-tag">ajoutée d’office</span>
                <?php elseif ($option->isRequired) : ?>
                    <span class="sub-field__required" aria-label="obligatoire">*</span>
                <?php endif; ?>
            </legend>

            <?php if ($option->help !== '') : ?>
                <p class="sub-field__help"><?php echo esc_html($option->help); ?></p>
            <?php endif; ?>

            <?php if ($error !== '') : ?>
                <p class="sub-field__error"><?php echo esc_html($error); ?></p>
            <?php endif; ?>

            <?php if ($option->isAutomatic()) : ?>
                <?php // Rien à cocher : on annonce ce qui s'ajoute, et combien. ?>
                <p class="sub-choice sub-choice--fixed">
                    <span class="sub-choice__label">Ajoutée à votre cotisation</span>
                    <span class="sub-choice__price">
                        <?php echo esc_html(self::euro((float) ($option->choices[0]['amount'] ?? 0), true)); ?>
                    </span>
                </p>

            <?php elseif ($option->isCheckbox()) : ?>
                <?php $checked = isset($option->choices[0])
                    && in_array((string) $option->choices[0]['value'], $selected, true); ?>
                <label class="sub-choice">
                    <input type="checkbox"
                           name="options[<?php echo esc_attr($option->name); ?>]"
                           value="<?php echo esc_attr((string) ($option->choices[0]['value'] ?? 'oui')); ?>"
                           <?php checked($checked); ?>>
                    <span class="sub-choice__label">
                        <?php echo esc_html((string) ($option->choices[0]['label'] ?? 'Oui')); ?>
                    </span>
                    <?php if (abs((float) ($option->choices[0]['amount'] ?? 0)) >= 0.005) : ?>
                        <span class="sub-choice__price">
                            <?php echo esc_html(self::euro((float) $option->choices[0]['amount'], true)); ?>
                        </span>
                    <?php endif; ?>
                </label>

            <?php else : ?>
                <?php foreach ($option->choices as $choice) : ?>
                    <label class="sub-choice">
                        <input type="radio"
                               name="options[<?php echo esc_attr($option->name); ?>]"
                               value="<?php echo esc_attr((string) $choice['value']); ?>"
                               <?php checked(in_array((string) $choice['value'], $selected, true)); ?>>
                        <span class="sub-choice__label"><?php echo esc_html((string) $choice['label']); ?></span>
                        <?php if (abs((float) $choice['amount']) >= 0.005) : ?>
                            <span class="sub-choice__price">
                                <?php echo esc_html(self::euro((float) $choice['amount'], true)); ?>
                            </span>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            <?php endif; ?>
        </fieldset>
        <?php
    }

    /**
     * Mode de règlement, choisi par l'adhérent au dépôt du dossier.
     *
     * @param array<string, string> $errors
     */
    public static function payment(string $chosen, array $errors = [], string $help = ''): void
    {
        $error = $errors['payment_method'] ?? '';
        $help  = $help !== '' ? $help : 'Le règlement se fait après le dépôt du dossier. '
            . 'Le club n’accepte ni espèces ni virement.';
        ?>
        <fieldset class="sub-field <?php echo $error !== '' ? 'sub-field--error' : ''; ?>">
            <legend>
                Mode de règlement
                <span class="sub-field__required" aria-label="obligatoire">*</span>
            </legend>
            <p class="sub-field__help"><?php echo esc_html($help); ?></p>

            <?php if ($error !== '') : ?>
                <p class="sub-field__error"><?php echo esc_html($error); ?></p>
            <?php endif; ?>

            <?php foreach (PaymentMethods::offered() as $value => $label) : ?>
                <label class="sub-choice">
                    <input type="radio" name="payment_method"
                           value="<?php echo esc_attr($value); ?>"
                           <?php checked($value, $chosen); ?> required>
                    <span class="sub-choice__label"><?php echo esc_html($label); ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <?php
    }

    /**
     * Les réponses postées, ramenées à ce que le serveur accepte de lire.
     *
     * Le formulaire de l'adhérent et celui du bureau postent la même chose ;
     * ils n'ont pas à la nettoyer chacun à leur façon.
     *
     * @param mixed $posted Le `options` de la requête.
     * @return array<string, string|list<string>>
     */
    public static function collectAnswers(mixed $posted): array
    {
        $answers = [];

        foreach ((array) $posted as $name => $value) {
            $key = sanitize_key((string) $name);

            $answers[$key] = is_array($value)
                ? array_map('sanitize_text_field', array_map('wp_unslash', $value))
                : sanitize_text_field(wp_unslash((string) $value));
        }

        return $answers;
    }

    public static function euro(float $amount, bool $signed = false): string
    {
        $prefix = $signed && $amount > 0 ? '+' : '';

        return $prefix . number_format($amount, 2, ',', ' ') . ' €';
    }
}
