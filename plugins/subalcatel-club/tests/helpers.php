<?php
/**
 * Aides communes aux tests de fumée.
 *
 * Chargé par `require_once` en tête de chaque suite. Pas de `declare` ici :
 * `wp eval-file` évalue le fichier appelant, ce qui l'interdirait.
 */

use Subalcatel\Club\Documents\DocumentService;
use Subalcatel\Club\Documents\DocumentStorage;
use Subalcatel\Club\Documents\DocumentTypes;

if (!function_exists('sub_test_pdf')) {
    /**
     * Un PDF minimal mais authentique : le contrôle de type le reconnaît.
     */
    function sub_test_pdf(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";
    }

    /**
     * Présente un contenu comme un fichier téléversé.
     *
     * @return array{tmp_name: string, name: string, type: string, size: int, error: int}
     */
    function sub_test_upload(string $name, ?string $contents = null): array
    {
        $contents ??= sub_test_pdf();
        $tmp = wp_tempnam($name);
        file_put_contents($tmp, $contents);

        return [
            'tmp_name' => $tmp,
            'name'     => $name,
            'type'     => 'application/pdf',
            'size'     => strlen($contents),
            'error'    => UPLOAD_ERR_OK,
        ];
    }

    /**
     * Met un membre en règle : adhésion active et documents obligatoires déposés.
     *
     * Depuis que `EligibilityPolicy` lit la table des documents, poser des métas
     * ne suffit plus — il faut de vrais documents validés.
     *
     * @return list<int> identifiants des documents créés
     */
    function sub_test_make_compliant(int $userId, string $validUntil = '2027-12-31'): array
    {
        DocumentTypes::seed();

        update_user_meta($userId, 'sub_membership_valid_until', $validUntil);

        $service = new DocumentService();
        $created = [];

        foreach (DocumentTypes::all() as $type) {
            if (!DocumentTypes::isRequiredFor($type, $userId)) {
                continue;
            }

            $id = $service->upload(
                $userId,
                (string) $type['slug'],
                sub_test_upload('piece.pdf'),
                current_time('Y-m-d'),
                $userId
            );

            // Le bureau valide : on teste l'éligibilité, pas la file d'attente.
            global $wpdb;
            $wpdb->update("{$wpdb->prefix}sub_member_documents", [
                'status'      => DocumentService::STATUS_VALID,
                'verified_at' => current_time('mysql'),
            ], ['id' => $id]);

            $created[] = $id;
        }

        return $created;
    }

    /**
     * Une campagne à l'usage exclusif des tests, avec ses propres tarifs.
     *
     * Les suites de tarification vérifient l'arithmétique du moteur, pas le
     * prix de l'adhésion : celui-ci est une donnée que le bureau ajuste à
     * chaque campagne, et un test qui le lit dans la base de démonstration
     * tombe au premier ajustement — ce qui est arrivé à la reprise du Joomla,
     * quand les tarifs réels ont remplacé ceux de la démo.
     *
     * Les montants ci-dessous sont donc figés ici, et nulle part ailleurs. Ce
     * sont ceux sur lesquels la formule OSMembership d'origine a été rejouée,
     * d'où les totaux attendus par les scénarios.
     *
     * La campagne reste en brouillon : elle ne doit ni s'afficher dans la
     * grille publique, ni passer devant la campagne de démonstration.
     */
    function sub_test_pricing_campaign(): int
    {
        global $wpdb;
        $p    = $wpdb->prefix . 'sub_';
        $slug = 'campagne-de-test';

        // Recréée à chaque exécution : une campagne laissée par une version
        // antérieure du fichier mentirait sur ce que le test croit vérifier.
        $previous = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$p}campaigns WHERE slug = %s", $slug)
        );

        if ($previous) {
            sub_test_drop_campaign((int) $previous);
        }

        $wpdb->insert("{$p}campaigns", [
            'title'         => 'Campagne de test',
            'slug'          => $slug,
            'opens_on'      => current_time('Y-m-d'),
            'closes_on'     => '2027-12-31',
            'valid_from'    => '2026-09-15',
            'valid_until'   => '2027-12-31',
            'reminder_days' => '30',
            'status'        => 'draft',
        ]);
        $campaignId = (int) $wpdb->insert_id;

        foreach ([
            ['Plongée', 'plongee', 210.00, 1],
            ['Nage Avec Palmes', 'nap', 120.00, 2],
        ] as [$title, $planSlug, $price, $order]) {
            $wpdb->insert("{$p}plans", [
                'campaign_id' => $campaignId,
                'title'       => $title,
                'slug'        => $planSlug,
                'description' => '',
                'base_price'  => $price,
                'published'   => 1,
                'ordering'    => $order,
            ]);
        }

        $option = static function (array $data) use ($wpdb, $p, $campaignId): void {
            $wpdb->insert("{$p}options", [
                'campaign_id'      => $campaignId,
                'name'             => $data['name'],
                'label'            => $data['label'],
                'help'             => '',
                'input_type'       => 'single',
                'is_required'      => $data['required'] ?? 0,
                'choices'          => wp_json_encode($data['choices'] ?? []),
                'condition_option' => $data['condition_option'] ?? null,
                'condition_values' => wp_json_encode($data['condition_values'] ?? []),
                'grants'           => wp_json_encode($data['grants'] ?? []),
                'plans'            => wp_json_encode($data['plans'] ?? []),
                'ordering'         => $data['ordering'],
            ]);
        };

        $yesNo = static fn (float $amount): array => [
            ['value' => 'oui', 'label' => 'Oui', 'amount' => $amount],
            ['value' => 'non', 'label' => 'Non', 'amount' => 0.0],
        ];

        $option([
            'name'     => 'origine_adhesion',
            'label'    => 'Origine de l’adhésion',
            'required' => 1,
            'ordering' => 10,
            'choices'  => [
                ['value' => 'nokia',     'label' => 'Nokia',             'amount' => 0.0],
                ['value' => 'ce_orange', 'label' => 'CE Orange',         'amount' => 0.0],
                ['value' => 'exterieur', 'label' => 'Extérieur / Autre', 'amount' => 0.0],
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
            'required' => 1,
            'ordering' => 30,
            'choices'  => [
                ['value' => 'aucune',  'label' => 'Aucune',   'amount' => 0.0],
                ['value' => 'loisir1', 'label' => 'Loisir 1', 'amount' => 25.00],
                ['value' => 'loisir2', 'label' => 'Loisir 2', 'amount' => 29.00],
                ['value' => 'loisir3', 'label' => 'Loisir 3', 'amount' => 50.00],
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

        // Remise Nokia, plan Plongée. Formule OSMembership d'origine :
        //   -58.00 - [PRET_BLOC]*14/36 - [PRET_DETENDEUR]*0.40 - [PRET_GILET]*0.40
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

        // Remise Nokia, plan NAP : -23.00 - [PISCINE]*0.40
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

    /**
     * Efface une campagne de test et tout ce qui s'y rattache.
     *
     * Les dossiers en font partie : les laisser orphelins fausserait les
     * statistiques, que d'autres suites vérifient.
     */
    function sub_test_drop_campaign(int $campaignId): void
    {
        global $wpdb;
        $p = $wpdb->prefix . 'sub_';

        $applications = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$p}applications WHERE campaign_id = %d",
            $campaignId
        ));

        foreach ($applications as $applicationId) {
            $applicationId = (int) $applicationId;

            foreach (['application_lines', 'validations', 'payments'] as $table) {
                $wpdb->delete("{$p}{$table}", ['application_id' => $applicationId]);
            }

            delete_option("sub_application_answers_{$applicationId}");
            $wpdb->delete("{$p}applications", ['id' => $applicationId]);
        }

        foreach (['plans', 'options', 'discount_rules'] as $table) {
            $wpdb->delete("{$p}{$table}", ['campaign_id' => $campaignId]);
        }

        $wpdb->delete("{$p}campaigns", ['id' => $campaignId]);
    }

    /**
     * Supprime les documents d'un membre, fichiers compris.
     */
    function sub_test_clean_documents(int $userId): void
    {
        global $wpdb;

        $paths = $wpdb->get_col($wpdb->prepare(
            "SELECT file_path FROM {$wpdb->prefix}sub_member_documents
             WHERE user_id = %d AND file_path <> ''",
            $userId
        ));

        foreach ($paths as $path) {
            DocumentStorage::delete((string) $path);
        }

        $wpdb->delete("{$wpdb->prefix}sub_member_documents", ['user_id' => $userId]);
    }
}
