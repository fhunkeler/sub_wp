<?php

declare(strict_types=1);

namespace Subalcatel\Club\Events;

use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Identity\ProfileFields;

/**
 * Champs du formulaire d'inscription à une sortie.
 *
 * S'inscrire à une plongée, ce n'est pas cocher « je participe » : c'est
 * déclarer ce dont on aura besoin. L'audit du Joomla le confirme — 40 607
 * valeurs de champs pour 6 537 inscriptions, soit six réponses par inscrit.
 *
 * Deux principes :
 *
 * - **Ce qui est déjà au profil est pré-rempli.** On ne redemande pas son
 *   immatriculation à chaque sortie ; le membre corrige si besoin, et la valeur
 *   est figée dans l'inscription.
 * - **Chaque type d'événement choisit ses champs.** Une assemblée générale n'a
 *   pas besoin de savoir qui apporte son bloc.
 */
final class RegistrationFields
{
    /** Ce que le membre vient faire sur la sortie. */
    public const INTENT_EXPLORATION = 'exploration';
    public const INTENT_TRAINING    = 'formation';
    public const INTENT_TEACHING    = 'encadrement';
    public const INTENT_ANY         = 'indifferent';

    /**
     * @return array<string, array{
     *     label: string, type: string, group: string, help?: string,
     *     options?: array<string, string>, options_source?: string,
     *     restricted?: list<string>, profile?: string, unit?: string,
     *     depends_on?: string, depends_value?: string, shared?: bool
     * }>
     */
    public static function all(): array
    {
        $fields = [
            // --- Ce que je viens faire -----------------------------------------
            //
            // La question qui structure la palanquée. Le directeur de plongée
            // compose ses groupes avec : qui explore, qui passe un niveau, qui
            // encadre. Tant qu'elle n'était pas posée, il fallait la poser par
            // téléphone à chaque sortie.
            'dive_intent' => [
                'label'      => 'Ce que je viens faire',
                'type'       => 'select',
                'group'      => 'diving',
                'options'    => [
                    self::INTENT_EXPLORATION => 'Exploration',
                    self::INTENT_TRAINING    => 'Formation',
                    self::INTENT_TEACHING    => 'Encadrement',
                    self::INTENT_ANY         => 'Indifférent — je m’adapte',
                ],
                // Encadrer suppose un brevet ; « indifférent » n'a de sens que
                // pour qui peut tout faire. Les deux options sont retirées de la
                // liste — et refusées au serveur — pour les autres.
                'restricted' => [self::INTENT_TEACHING, self::INTENT_ANY],
            ],
            'training_level' => [
                'label'          => 'Niveau préparé',
                'type'           => 'select',
                'group'          => 'diving',
                'options_source' => 'dive_levels',
                'depends_on'     => 'dive_intent',
                'depends_value'  => self::INTENT_TRAINING,
            ],
            'previous_instructor' => [
                'label'         => 'Moniteur précédent',
                'type'          => 'text',
                'group'         => 'diving',
                'depends_on'    => 'dive_intent',
                'depends_value' => self::INTENT_TRAINING,
                'help'          => 'Qui vous a encadré à la séance précédente — pour que '
                    . 'la progression reste suivie d’une sortie à l’autre.',
            ],

            // --- Ce que les autres inscrits voient ------------------------------
            'conviviality' => [
                'label'  => 'Je propose un pot',
                'type'   => 'boolean',
                'group'  => 'shared',
                'shared' => true,
                'help'   => 'Un pot, un repas pour fêter la sortie ou un passage de niveau ? '
                    . 'Cochez : les autres inscrits verront que vous êtes partant.',
            ],
            'shared_note' => [
                'label'  => 'Commentaire libre',
                'type'   => 'textarea',
                'group'  => 'shared',
                'shared' => true,
                'help'   => 'Visible de tous les inscrits — « je fête mon N2 », « je cherche '
                    . 'un binôme autonome », « je propose l’apéro au port »…',
            ],
        ];

        /** @param array<string, array<string, mixed>> $fields */
        return (array) apply_filters('subalcatel_registration_fields', $fields);
    }

    /**
     * @return array<string, string>
     */
    public static function groups(): array
    {
        return [
            'diving' => 'Votre plongée',
            'shared' => 'Visible des autres inscrits',
        ];
    }

    /**
     * Options réellement proposées à ce membre, pour ce champ.
     *
     * Deux sources : la liste écrite dans la définition, ou les niveaux de
     * plongée du club — qu'on ne recopie pas ici, sans quoi ils divergeraient du
     * jour où le bureau en ajoute un.
     *
     * @param array<string, mixed> $field
     * @return array<string, string>
     */
    public static function optionsFor(string $name, array $field, int $userId): array
    {
        if (($field['options_source'] ?? '') === 'dive_levels') {
            $options = [];

            foreach (DiveLevels::ordered() as $level) {
                if (DiveLevels::rankOf($level->term_id, DiveLevels::RANK_DIVER) === 0) {
                    continue;
                }

                $options[$level->slug] = $level->name;
            }

            return $options;
        }

        $options = (array) ($field['options'] ?? []);

        if (empty($field['restricted']) || DiveLevels::maySupervise($userId)) {
            return $options;
        }

        foreach ((array) $field['restricted'] as $key) {
            unset($options[$key]);
        }

        return $options;
    }

    /**
     * Champs activés pour un type d'événement.
     *
     * Un type sans réglage n'affiche aucun champ : c'est le cas d'une réunion,
     * et c'est le bon défaut — mieux vaut un formulaire vide qu'un formulaire
     * incongru.
     *
     * @param array<string, mixed>|null $type
     * @return array<string, array<string, mixed>>
     */
    public static function forType(?array $type): array
    {
        if ($type === null) {
            return [];
        }

        $enabled = (array) (json_decode((string) ($type['registration_fields'] ?? ''), true) ?: []);

        return array_filter(
            self::all(),
            static fn (string $name): bool => in_array($name, $enabled, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Valeur proposée par défaut, reprise du profil quand elle y figure.
     */
    public static function defaultValue(int $userId, string $name, array $field): string
    {
        if (isset($field['profile'])) {
            return ProfileFields::get($userId, (string) $field['profile']);
        }

        return '';
    }

    /**
     * Nettoie les réponses soumises, en ne gardant que les champs du type.
     *
     * Deux contrôles que le navigateur ne peut pas garantir : une option
     * réservée reste refusée même si elle est postée à la main, et un champ
     * conditionnel est ignoré quand sa condition n'est pas remplie — sinon un
     * « niveau préparé » saisi puis reconverti en exploration resterait
     * attaché à l'inscription.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $type
     * @return array<string, string>
     */
    public static function sanitize(array $input, ?array $type, int $userId = 0): array
    {
        $fields  = self::forType($type);
        $answers = [];

        foreach ($fields as $name => $field) {
            $raw = $input[$name] ?? '';

            if (is_array($raw)) {
                continue;
            }

            $value = (string) wp_unslash((string) $raw);

            $answers[$name] = match ($field['type']) {
                'boolean'  => $value !== '' ? '1' : '',
                'number'   => $value === '' ? '' : (string) max(0, (int) $value),
                'textarea' => sanitize_textarea_field($value),
                'select'   => isset(self::optionsFor($name, $field, $userId)[$value]) ? $value : '',
                default    => sanitize_text_field($value),
            };
        }

        foreach ($fields as $name => $field) {
            if (!isset($field['depends_on'])) {
                continue;
            }

            $master   = (string) ($answers[(string) $field['depends_on']] ?? '');
            $expected = (string) ($field['depends_value'] ?? '');

            // Sans valeur attendue, la dépendance porte sur une case cochée.
            $met = $expected === '' ? $master !== '' : $master === $expected;

            if (!$met) {
                $answers[$name] = '';
            }
        }

        return array_filter($answers, static fn (string $v): bool => $v !== '');
    }

    /**
     * Rendu lisible d'une réponse, pour la liste du directeur de plongée.
     *
     * @param array<string, mixed> $field
     */
    public static function display(string $name, string $value, array $field): string
    {
        if ($value === '') {
            return '';
        }

        return match ($field['type']) {
            'boolean' => 'Oui',
            // Une option réservée doit rester lisible une fois choisie : ici on
            // traduit, on ne filtre pas. D'où la restriction neutralisée.
            'select'  => (string) (self::optionsFor($name, array_merge($field, ['restricted' => []]), 0)[$value]
                ?? $value),
            'number'  => $value . (isset($field['unit']) ? ' ' . $field['unit'] : ''),
            default   => $value,
        };
    }

    /**
     * Résumé compact des réponses d'un inscrit, pour l'affichage en liste.
     *
     * @param array<string, string> $answers
     * @param array<string, mixed>|null $type
     * @return list<string>
     */
    public static function summarise(array $answers, ?array $type): array
    {
        $summary = [];

        foreach (self::forType($type) as $name => $field) {
            $value = (string) ($answers[$name] ?? '');

            if ($value === '') {
                continue;
            }

            $summary[] = sprintf('%s : %s', $field['label'], self::display($name, $value, $field));
        }

        return $summary;
    }

    /**
     * Champs proposés par défaut à la création d'un type de plongée.
     *
     * @return list<string>
     */
    public static function divingDefaults(): array
    {
        return ['dive_intent', 'training_level', 'previous_instructor', 'conviviality', 'shared_note'];
    }
}
