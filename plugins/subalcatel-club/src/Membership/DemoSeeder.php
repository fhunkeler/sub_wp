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

        self::seedOptions($campaignId);

        return $campaignId;
    }

    /**
     * Options et remises de la campagne — la configuration, et rien d'autre.
     */
    private static function seedOptions(int $campaignId): void
    {
        global $wpdb;
        $p = $wpdb->prefix . 'sub_';

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

        // Une case à cocher, pas deux boutons : la réponse est « non » pour la
        // quasi-totalité des dossiers, et la question ne se pose vraiment qu'à
        // qui détient déjà une licence prise ailleurs.
        $option([
            'name'       => 'moins_value_licence',
            'label'      => 'Avez-vous déjà une licence FFESSM valide pour la saison en cours ?',
            'help'       => 'Cochez seulement si vous en détenez déjà une : sa part est alors déduite.',
            'input_type' => Option::INPUT_CHECK,
            'ordering'   => 40,
            'choices'    => $yesNo(-49.00, 'Oui, j’ai déjà une licence valide', 'Non'),
        ]);

        $option([
            'name'     => 'niveau_prepare',
            'label'    => 'Niveau préparé cette saison',
            'ordering' => 50,
            'plans'    => ['plongee'],
            'choices'  => [
                ['value' => 'aucun', 'label' => 'Aucun', 'amount' => 0.0],
                ['value' => 'pe12',  'label' => 'PE12',  'amount' => 0.0],
                ['value' => 'pa20',  'label' => 'PA20',  'amount' => 0.0],
                ['value' => 'p2',    'label' => 'P2',    'amount' => 0.0],
                ['value' => 'pe40',  'label' => 'PE40',  'amount' => 0.0],
                ['value' => 'n3',    'label' => 'N3',    'amount' => 0.0],
                ['value' => 'n4',    'label' => 'N4',    'amount' => 0.0],
                ['value' => 'mf1',   'label' => 'MF1',   'amount' => 0.0],
            ],
        ]);

        // Due dès qu'un niveau est préparé, et sans choix à faire : le club la
        // commande de toute façon. N4 et MF1 en sont dispensés — ces brevets
        // sont délivrés par la fédération, pas par le club.
        $option([
            'name'             => 'carte_niveau',
            'label'            => 'Carte de niveau',
            'help'             => 'Ajoutée d’office : la carte est commandée avec votre passage de niveau.',
            'input_type'       => Option::INPUT_AUTO,
            'ordering'         => 60,
            'plans'            => ['plongee'],
            'condition_option' => 'niveau_prepare',
            'condition_values' => ['pe12', 'pa20', 'p2', 'pe40', 'n3'],
            'choices'          => [
                ['value' => 'oui', 'label' => 'Oui', 'amount' => 16.00],
            ],
        ]);

        // Prêts de matériel : chacun ouvre un droit d'emprunt, consommé par le
        // module Emprunts en phase 8.
        // Les trois prêts sont des questions obligatoires du dossier plongée : ne
        // pas répondre laissait le bureau ignorer si le matériel était à sortir.
        // Elles ne concernent pas la nage avec palmes, qui ne plonge pas.
        $option([
            'name'     => 'pret_bloc',
            'label'    => 'Prêt d’un bloc',
            'required' => 1,
            'ordering' => 70,
            'plans'    => ['plongee'],
            'choices'  => [
                ['value' => 'oui', 'label' => 'Oui', 'amount' => 36.00],
                // Le club prête le bloc à qui encadre : dix encadrements dans la
                // saison valent le prix du prêt. L'engagement est pris ici, et le
                // droit d'emprunt s'ouvre malgré le montant nul.
                [
                    'value'  => 'encadrant',
                    'label'  => 'Oui — encadrant, je m’engage à encadrer au moins 10 fois dans la saison',
                    'amount' => 0.0,
                    'grants' => true,
                ],
                ['value' => 'non', 'label' => 'Non', 'amount' => 0.0],
            ],
            'grants'   => ['bloc'],
        ]);

        $option([
            'name'     => 'pret_detendeur',
            'label'    => 'Prêt d’un détendeur',
            'required' => 1,
            'ordering' => 80,
            'plans'    => ['plongee'],
            'choices'  => $yesNo(90.00),
            'grants'   => ['detendeur'],
        ]);

        $option([
            'name'     => 'pret_gilet',
            'label'    => 'Prêt d’un gilet (stab)',
            'required' => 1,
            'ordering' => 90,
            'plans'    => ['plongee'],
            'choices'  => $yesNo(20.00),
            'grants'   => ['gilet'],
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
    }

    /**
     * Réécrit les options et les remises d'une campagne existante.
     *
     * Sert à rejouer sur une base déjà peuplée un changement de règles décidé
     * après coup — le bureau a retiré le tarif jeune et le prêt d'ordinateur en
     * septembre 2026, sur une démonstration dont la campagne existait déjà, et
     * `run()` ne touche jamais à ce qui existe.
     *
     * Ne concerne QUE la configuration : la campagne, ses formules et les
     * dossiers déjà déposés restent en place, lignes figées comprises. Sur une
     * campagne où le bureau a retouché ses options depuis l'écran d'admin, cet
     * appel efface ces retouches — d'où l'outil dédié, et pas un appel
     * automatique.
     */
    public static function resetOptions(int $campaignId): void
    {
        global $wpdb;
        $p = $wpdb->prefix . 'sub_';

        $wpdb->delete("{$p}options", ['campaign_id' => $campaignId]);
        $wpdb->delete("{$p}discount_rules", ['campaign_id' => $campaignId]);

        self::seedOptions($campaignId);
    }
}
