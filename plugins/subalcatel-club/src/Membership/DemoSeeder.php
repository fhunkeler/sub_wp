<?php

declare(strict_types=1);

namespace Subalcatel\Club\Membership;

/**
 * Jeu de données de démonstration : la campagne 2026-2027 du club.
 *
 * Les montants et les conditions proviennent du dump Joomla du 22/07/2026
 * (voir AUDIT_DONNEES_JOOMLA.md §3). Ils sont ici des DONNÉES, pas du code :
 * le bureau les modifiera depuis l'interface, et dupliquera la campagne pour
 * ouvrir la suivante.
 *
 * Les prix de base des deux plans ne figuraient pas dans cette extraction ;
 * ils proviennent de la table des plans OSMembership, reprise depuis.
 */
final class DemoSeeder
{
    public const CAMPAIGN_SLUG = 'campagne-2026-2027';

    public static function run(): int
    {
        global $wpdb;
        $p = $wpdb->prefix . 'sub_';

        $existing = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$p}campaigns WHERE slug = %s", self::CAMPAIGN_SLUG)
        );

        if ($existing) {
            return (int) $existing;
        }

        $wpdb->insert("{$p}campaigns", [
            'title'         => 'Campagne 2026-2027',
            'slug'          => self::CAMPAIGN_SLUG,
            // Ouverture des inscriptions avancée à aujourd'hui pour que la
            // démonstration fonctionne toute l'année. En exploitation réelle,
            // le bureau saisira la vraie date d'ouverture — c'est un champ de
            // la campagne, pas une constante du code.
            'opens_on'      => current_time('Y-m-d'),
            'closes_on'     => '2027-12-31',
            // La période de validité, elle, reste celle du club : 15/09 → 31/12 N+1.
            'valid_from'    => '2026-09-15',
            'valid_until'   => '2027-12-31',
            'reminder_days' => '30',
            'status'        => 'open',
        ]);
        $campaignId = (int) $wpdb->insert_id;

        // --- Plans -----------------------------------------------------------

        foreach ([
            ['Plongée', 'plongee', 144.00, 'Adhésion pour toutes les disciplines fédérales proposées par le club.', 1],
            ['Nage Avec Palmes', 'nap', 59.00, 'Adhésion pour la seule pratique de la nage avec palmes.', 2],
        ] as [$title, $slug, $price, $desc, $order]) {
            $wpdb->insert("{$p}plans", [
                'campaign_id' => $campaignId,
                'title'       => $title,
                'slug'        => $slug,
                'description' => $desc,
                'base_price'  => $price,
                'published'   => 1,
                'ordering'    => $order,
            ]);
        }

        // --- Options ---------------------------------------------------------

        $option = static function (array $data) use ($wpdb, $p, $campaignId): void {
            $wpdb->insert("{$p}options", [
                'campaign_id'      => $campaignId,
                'name'             => $data['name'],
                'label'            => $data['label'],
                'help'             => $data['help'] ?? '',
                'input_type'       => $data['input_type'] ?? 'single',
                'is_required'      => $data['required'] ?? 0,
                'choices'          => wp_json_encode($data['choices'] ?? []),
                'condition_option' => $data['condition_option'] ?? null,
                'condition_values' => wp_json_encode($data['condition_values'] ?? []),
                'grants'           => wp_json_encode($data['grants'] ?? []),
                'plans'            => wp_json_encode($data['plans'] ?? []),
                'ordering'         => $data['ordering'],
            ]);
        };

        $yesNo = static fn (float $amount, string $yesLabel = 'Oui', string $noLabel = 'Non'): array => [
            ['value' => 'oui', 'label' => $yesLabel, 'amount' => $amount],
            ['value' => 'non', 'label' => $noLabel,  'amount' => 0.0],
        ];

        // Origine de l'adhésion : c'est elle qui déclenche les remises.
        $option([
            'name'     => 'origine_adhesion',
            'label'    => 'Origine de l’adhésion',
            'required' => 1,
            'ordering' => 10,
            'choices'  => [
                ['value' => 'nokia',     'label' => 'Nokia',              'amount' => 0.0],
                ['value' => 'ce_orange', 'label' => 'CE Orange',          'amount' => 0.0],
                ['value' => 'exterieur', 'label' => 'Extérieur / Autre',  'amount' => 0.0],
            ],
        ]);

        $option([
            'name'     => 'jeune',
            'label'    => 'Tarif jeune (moins de 16 ans)',
            'required' => 1,
            'ordering' => 20,
            'choices'  => [
                ['value' => 'non', 'label' => 'Non', 'amount' => 0.0],
                ['value' => 'oui', 'label' => 'Oui', 'amount' => 18.00],
            ],
        ]);

        $option([
            'name'     => 'assurance_individuelle',
            'label'    => 'Assurance individuelle complémentaire',
            'help'     => 'Souscrite via la licence FFESSM.',
            'required' => 1,
            'ordering' => 30,
            'choices'  => [
                ['value' => 'aucune',  'label' => 'Aucune',        'amount' => 0.0],
                ['value' => 'loisir1', 'label' => 'Loisir 1',      'amount' => 25.00],
                ['value' => 'loisir2', 'label' => 'Loisir 2',      'amount' => 29.00],
                ['value' => 'loisir3', 'label' => 'Loisir 3',      'amount' => 50.00],
            ],
        ]);

        $option([
            'name'             => 'moins_value_licence',
            'label'            => 'Licence FFESSM déjà détenue',
            'ordering'         => 40,
            'condition_option' => 'jeune',
            'condition_values' => ['non'],
            'choices'          => $yesNo(-49.00),
        ]);

        $option([
            'name'     => 'niveau_prepare',
            'label'    => 'Niveau préparé cette saison',
            'ordering' => 50,
            'choices'  => [
                ['value' => 'aucun', 'label' => 'Aucun', 'amount' => 0.0],
                ['value' => 'pe12',  'label' => 'PE12',  'amount' => 0.0],
                ['value' => 'pa20',  'label' => 'PA20',  'amount' => 0.0],
                ['value' => 'p2',    'label' => 'P2',    'amount' => 0.0],
                ['value' => 'pe40',  'label' => 'PE40',  'amount' => 0.0],
            ],
        ]);

        $option([
            'name'             => 'carte_niveau',
            'label'            => 'Carte de niveau',
            'ordering'         => 60,
            'condition_option' => 'niveau_prepare',
            'condition_values' => ['pe12', 'pa20', 'p2', 'pe40'],
            'choices'          => $yesNo(16.00),
        ]);

        // Prêts de matériel : chacun ouvre un droit d'emprunt, consommé par le
        // module Emprunts en phase 8.
        $option([
            'name'     => 'pret_bloc',
            'label'    => 'Prêt d’un bloc',
            'ordering' => 70,
            'choices'  => $yesNo(36.00),
            'grants'   => ['bloc'],
        ]);

        $option([
            'name'     => 'pret_detendeur',
            'label'    => 'Prêt d’un détendeur',
            'ordering' => 80,
            'choices'  => $yesNo(90.00),
            'grants'   => ['detendeur'],
        ]);

        $option([
            'name'     => 'pret_gilet',
            'label'    => 'Prêt d’un gilet',
            'ordering' => 90,
            'choices'  => $yesNo(20.00),
            'grants'   => ['gilet'],
        ]);

        $option([
            'name'             => 'pret_ordinateur',
            'label'            => 'Prêt d’un ordinateur',
            'ordering'         => 100,
            'condition_option' => 'niveau_prepare',
            'condition_values' => ['pa20', 'p2', 'pe40'],
            'choices'          => $yesNo(40.00),
            'grants'           => ['ordinateur'],
        ]);

        $option([
            'name'     => 'piscine',
            'label'    => 'Créneau piscine',
            'ordering' => 110,
            'choices'  => $yesNo(60.00),
            'plans'    => ['nap'],
        ]);

        // --- Remises ---------------------------------------------------------

        // Nokia, plan Plongée. La formule d'origine était :
        //   -58.00 - [PRET_BLOC]*14/36 - [PRET_DETENDEUR]*0.40 - [PRET_GILET]*0.40
        // 14/36 d'un prêt de bloc à 36 € vaut exactement 14 € : on l'exprime en
        // euros, plus lisible pour le bureau qu'un pourcentage à 38,89 %.
        $wpdb->insert("{$p}discount_rules", [
            'campaign_id'      => $campaignId,
            'label'            => 'Remise Nokia — plongée',
            'condition_option' => 'origine_adhesion',
            'condition_values' => wp_json_encode(['nokia']),
            'flat_amount'      => -58.00,
            'per_option'       => wp_json_encode([
                ['option' => 'pret_bloc',      'mode' => 'amount',  'value' => 14.0],
                ['option' => 'pret_detendeur', 'mode' => 'percent', 'value' => 40.0],
                ['option' => 'pret_gilet',     'mode' => 'percent', 'value' => 40.0],
            ]),
            'plans'            => wp_json_encode(['plongee']),
            'ordering'         => 10,
        ]);

        // Nokia, plan NAP : -23.00 - [PISCINE]*0.40
        $wpdb->insert("{$p}discount_rules", [
            'campaign_id'      => $campaignId,
            'label'            => 'Remise Nokia — nage avec palmes',
            'condition_option' => 'origine_adhesion',
            'condition_values' => wp_json_encode(['nokia']),
            'flat_amount'      => -23.00,
            'per_option'       => wp_json_encode([
                ['option' => 'piscine', 'mode' => 'percent', 'value' => 40.0],
            ]),
            'plans'            => wp_json_encode(['nap']),
            'ordering'         => 20,
        ]);

        return $campaignId;
    }
}
