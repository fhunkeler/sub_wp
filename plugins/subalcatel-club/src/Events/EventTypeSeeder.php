<?php

declare(strict_types=1);

namespace Subalcatel\Club\Events;

/**
 * Types d'événement initiaux.
 *
 * Ce sont des DONNÉES : le bureau les modifie et en ajoute depuis l'interface.
 * Les valeurs ci-dessous traduisent les règles de wordpress/projet.md.
 */
final class EventTypeSeeder
{
    public static function run(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sub_event_types';

        $types = [
            [
                'name'                 => 'Assemblée générale',
                'slug'                 => 'assemblee-generale',
                'visibility'           => 'members',
                'create_capability'    => 'sub_create_governance_event',
                'requires_autonomous'  => 0,
                'requires_dive_leader' => 0,
                'requires_medical'     => 0,
                'requires_membership'  => 1,
                'default_capacity'     => 0,
                'allow_waiting_list'   => 0,
            ],
            [
                'name'                 => 'Réunion du bureau',
                'slug'                 => 'reunion-bureau',
                'visibility'           => 'office',
                'create_capability'    => 'sub_create_governance_event',
                'requires_autonomous'  => 0,
                'requires_dive_leader' => 0,
                'requires_medical'     => 0,
                'requires_membership'  => 1,
                'default_capacity'     => 0,
                'allow_waiting_list'   => 0,
            ],
            [
                'name'                 => 'Plongée d’exploration',
                'slug'                 => 'plongee-exploration',
                'visibility'           => 'levels',
                'registration_fields'  => 'DIVING_DEFAULTS',
                'create_capability'    => 'sub_create_exploration_event',
                'requires_autonomous'  => 1,
                'requires_dive_leader' => 0,
                'requires_medical'     => 1,
                'requires_membership'  => 1,
                'default_capacity'     => 12,
                'allow_waiting_list'   => 1,
            ],
            [
                'name'                 => 'Plongée de formation',
                'slug'                 => 'plongee-formation',
                'visibility'           => 'levels',
                'registration_fields'  => 'DIVING_DEFAULTS',
                'create_capability'    => 'sub_create_training_event',
                'requires_autonomous'  => 0,
                'requires_dive_leader' => 1,
                'requires_medical'     => 1,
                'requires_membership'  => 1,
                'default_capacity'     => 8,
                'allow_waiting_list'   => 1,
            ],
            [
                'name'                 => 'Séance piscine',
                'slug'                 => 'seance-piscine',
                'visibility'           => 'levels',
                'registration_fields'  => 'DIVING_DEFAULTS',
                'create_capability'    => 'sub_create_training_event',
                'requires_autonomous'  => 0,
                'requires_dive_leader' => 0,
                'requires_medical'     => 1,
                'requires_membership'  => 1,
                'default_capacity'     => 20,
                'allow_waiting_list'   => 1,
            ],
        ];

        foreach ($types as $type) {
            // Les plongées reçoivent le formulaire complet ; les réunions non.
            if (($type['registration_fields'] ?? '') === 'DIVING_DEFAULTS') {
                $type['registration_fields'] = wp_json_encode(RegistrationFields::divingDefaults());
            }

            $exists = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$table} WHERE slug = %s", $type['slug'])
            );

            if (!$exists) {
                $wpdb->insert($table, $type);
            }
        }
    }

    /**
     * Visibilité d'origine des types installés avant qu'elle n'existe.
     *
     * La colonne arrive avec « tout le monde » pour défaut — le comportement
     * d'avant, et le bon choix quand on ne sait pas. Restent les deux cas où le
     * club sait déjà : la réunion du bureau ne regarde que lui, et les sorties
     * s'annoncent aux niveaux qu'elles acceptent.
     *
     * @var array<string, string>
     */
    private const VISIBILITY_BACKFILL = [
        'reunion-bureau'       => 'office',
        'plongee-exploration'  => 'levels',
        'plongee-formation'    => 'levels',
        'seance-piscine'       => 'levels',
    ];

    private const VISIBILITY_OPTION = 'subalcatel_event_visibility_backfilled';

    /**
     * Pose la visibilité d'origine des types déjà installés.
     *
     * **Une seule fois, et seulement sur ce qui n'a pas été réglé.** Le bureau
     * qui a ouvert ses réunions à tout le club ne doit pas les voir se refermer
     * à la mise à jour suivante ; la condition sur la valeur par défaut le
     * garantit, l'option empêche le second passage.
     */
    public static function backfillVisibility(): void
    {
        if ((int) get_option(self::VISIBILITY_OPTION, 0) === 1) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sub_event_types';

        foreach (self::VISIBILITY_BACKFILL as $slug => $visibility) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET visibility = %s WHERE slug = %s AND visibility = 'members'",
                $visibility,
                $slug
            ));
        }

        update_option(self::VISIBILITY_OPTION, 1, false);
    }

    /**
     * Version du jeu de champs partagés propagés aux types existants.
     *
     * À incrémenter chaque fois qu'un champ de sortie destiné à tous les types
     * de plongée est ajouté. Le seeder n'insère que les types absents ; sans ce
     * rattrapage, un champ ajouté après coup n'atteindrait jamais les types
     * déjà en base — c'est le même piège que pour les rôles et le schéma.
     */
    private const FIELDS_VERSION = 2;
    private const VERSION_OPTION = 'subalcatel_event_fields_version';

    /**
     * Champs à garantir sur tout type de plongée, s'ils manquent.
     *
     * @var list<string>
     */
    private const BACKFILL_FIELDS = ['dive_intent', 'training_level', 'previous_instructor', 'conviviality', 'shared_note'];

    /**
     * Marqueurs reconnaissant un type de plongée parmi les autres.
     *
     * Un seul suffit. La liste en compte plusieurs parce qu'elle sert aussi de
     * mémoire : `dive_count` a été le marqueur historique, il a disparu du
     * formulaire mais reste en base sur les types installés avant.
     *
     * @var list<string>
     */
    private const DIVING_MARKERS = ['dive_intent', 'dive_count', 'conviviality'];

    /**
     * Ce type est-il une plongée ?
     *
     * La question se pose au formulaire de création : demander un niveau
     * minimum pour une assemblée générale n'a pas de sens, et le champ laissait
     * croire qu'une réunion pouvait se réserver aux P2. La réponse se lit dans
     * le jeu de champs d'inscription, seul endroit qui distingue déjà les deux
     * familles — pas de colonne de plus à tenir à jour.
     *
     * @param array<string, mixed> $type
     */
    public static function isDivingType(array $type): bool
    {
        $fields = json_decode((string) ($type['registration_fields'] ?? ''), true);

        return is_array($fields) && array_intersect($fields, self::DIVING_MARKERS) !== [];
    }

    /**
     * Ajoute les champs partagés récents aux types de plongée existants.
     *
     * **Ajoute seulement**, n'écrase rien : si le bureau a personnalisé le jeu
     * de champs d'un type, sa configuration est préservée — on insère les
     * nouveaux champs, on ne réécrit pas les siens. Un type dont on a retiré les
     * marqueurs de plongée n'est pas touché.
     */
    public static function backfillSharedFields(): void
    {
        if ((int) get_option(self::VERSION_OPTION, 0) >= self::FIELDS_VERSION) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sub_event_types';
        $rows  = $wpdb->get_results("SELECT id, registration_fields FROM {$table}", ARRAY_A) ?: [];

        $known = array_keys(RegistrationFields::all());

        foreach ($rows as $row) {
            $fields = json_decode((string) $row['registration_fields'], true);

            // Réservé aux plongées : une AG ou une réunion n'a pas de moment
            // convivial à cocher à l'inscription.
            if (!is_array($fields) || array_intersect($fields, self::DIVING_MARKERS) === []) {
                continue;
            }

            $before = $fields;

            // Les champs matériel, transport et « nombre de plongées » ont été
            // retirés du formulaire : le club ne s'en servait pas. Les laisser
            // cochés en réglages ferait croire qu'ils existent encore.
            $fields = array_values(array_intersect($fields, $known));

            foreach (self::BACKFILL_FIELDS as $field) {
                if (!in_array($field, $fields, true)) {
                    $fields[] = $field;
                }
            }

            // Remis dans l'ordre du formulaire, pas dans celui des ajouts.
            $fields = array_values(array_filter($known, static fn (string $f): bool => in_array($f, $fields, true)));

            if ($fields !== $before) {
                $wpdb->update(
                    $table,
                    ['registration_fields' => wp_json_encode($fields)],
                    ['id' => (int) $row['id']]
                );
            }
        }

        update_option(self::VERSION_OPTION, self::FIELDS_VERSION, false);
    }
}
