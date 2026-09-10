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
     * Complète l'état civil et les coordonnées exigés par un dossier.
     *
     * Depuis que `ApplicationService` refuse un dossier sans état civil — la
     * licence FFESSM ne se prend pas sans lieu de naissance — un compte de test
     * tout neuf ne peut plus adhérer. Ces valeurs n'ont pas à être réalistes,
     * seulement présentes.
     */
    function sub_test_complete_identity(int $userId): void
    {
        // Un compte de test créé sans adresse : WordPress l'accepte, un dossier
        // d'adhésion non — c'est par là que passent toutes les notifications.
        $user = get_userdata($userId);

        if ($user && $user->user_email === '') {
            wp_update_user([
                'ID'         => $userId,
                'user_email' => 'test_' . $userId . '@subalcatel.test',
            ]);
        }

        foreach ([
            'birth_date'       => '1980-05-14',
            'birth_city'       => 'Lannion',
            'birth_department' => '22',
            'birth_country'    => 'France',
            'address'          => '3 rue des Ancres',
            'postal_code'      => '22300',
            'city'             => 'Lannion',
            'mobile'           => '0600000000',
        ] as $field => $value) {
            // On ne remplit que ce qui manque : une suite qui a posé ses propres
            // coordonnées les vérifie ensuite, et ne doit pas les voir écrasées.
            if ((string) get_user_meta($userId, 'sub_' . $field, true) === '') {
                update_user_meta($userId, 'sub_' . $field, $value);
            }
        }
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
            'name'       => 'moins_value_licence',
            'label'      => 'Avez-vous déjà une licence FFESSM valide pour la saison en cours ?',
            'input_type' => \Subalcatel\Club\Membership\Option::INPUT_CHECK,
            'ordering'   => 40,
            'choices'    => $yesNo(-49.00),
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

        $option([
            'name'             => 'carte_niveau',
            'label'            => 'Carte de niveau',
            'input_type'       => \Subalcatel\Club\Membership\Option::INPUT_AUTO,
            'ordering'         => 60,
            'plans'            => ['plongee'],
            'condition_option' => 'niveau_prepare',
            'condition_values' => ['pe12', 'pa20', 'p2', 'pe40', 'n3'],
            'choices'          => [
                ['value' => 'oui', 'label' => 'Oui', 'amount' => 16.00],
            ],
        ]);

        $option([
            'name'     => 'pret_bloc',
            'label'    => 'Prêt d’un bloc',
            'required' => 1,
            'ordering' => 70,
            'plans'    => ['plongee'],
            'choices'  => [
                ['value' => 'oui', 'label' => 'Oui', 'amount' => 36.00],
                ['value' => 'encadrant', 'label' => 'Oui — encadrant', 'amount' => 0.0, 'grants' => true],
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
