<?php

declare(strict_types=1);

namespace Subalcatel\Club\Identity;

/**
 * Registre des champs de profil.
 *
 * Déclaratif plutôt que configurable en base, et c'est un choix : ces champs
 * n'ont pas bougé en dix ans, ils sont imposés par la FFESSM ou par la sécurité
 * en plongée. Les rendre paramétrables produirait un écran de configuration que
 * personne n'ouvrirait, au prix d'un code plus difficile à lire.
 *
 * L'extension reste possible par le filtre `subalcatel_profile_fields`.
 *
 * La colonne qui compte est `editable` : un membre ne s'attribue pas son propre
 * niveau de plongée. Ce qui engage la sécurité relève du bureau.
 */
final class ProfileFields
{
    public const EDIT_SELF   = 'self';   // le membre, et le bureau
    public const EDIT_OFFICE = 'office'; // le bureau seul

    /**
     * @return array<string, array{
     *     label: string, group: string, type: string, editable: string,
     *     required?: bool, help?: string, options?: array<string, string>, placeholder?: string
     * }>
     */
    public static function all(): array
    {
        $fields = [
            // --- Identité ----------------------------------------------------
            'civility' => [
                'label' => 'Civilité', 'group' => 'identity', 'type' => 'select',
                'editable' => self::EDIT_SELF,
                'options' => ['' => '—', 'mme' => 'Madame', 'm' => 'Monsieur', 'nb' => 'Non précisé'],
            ],
            'birth_name' => [
                'label' => 'Nom de naissance', 'group' => 'identity', 'type' => 'text',
                'editable' => self::EDIT_SELF,
                'help' => 'Si différent du nom d’usage. Exigé pour l’affiliation FFESSM.',
            ],
            'birth_date' => [
                'label' => 'Date de naissance', 'group' => 'identity', 'type' => 'date',
                'editable' => self::EDIT_SELF, 'required' => true,
            ],
            'birth_city' => [
                'label' => 'Ville de naissance', 'group' => 'identity', 'type' => 'text',
                'editable' => self::EDIT_SELF, 'required' => true,
            ],
            'birth_department' => [
                'label' => 'Département de naissance', 'group' => 'identity', 'type' => 'text',
                'editable' => self::EDIT_SELF, 'placeholder' => '22',
            ],
            'birth_country' => [
                'label' => 'Pays de naissance', 'group' => 'identity', 'type' => 'text',
                'editable' => self::EDIT_SELF, 'placeholder' => 'France',
            ],

            // --- Coordonnées --------------------------------------------------
            'address' => [
                'label' => 'Adresse', 'group' => 'contact', 'type' => 'textarea',
                'editable' => self::EDIT_SELF,
            ],
            'postal_code' => [
                'label' => 'Code postal', 'group' => 'contact', 'type' => 'text',
                'editable' => self::EDIT_SELF,
            ],
            'city' => [
                'label' => 'Ville', 'group' => 'contact', 'type' => 'text',
                'editable' => self::EDIT_SELF,
            ],
            'mobile' => [
                'label' => 'Téléphone mobile', 'group' => 'contact', 'type' => 'tel',
                'editable' => self::EDIT_SELF, 'required' => true,
                'help' => 'Communiqué au directeur de plongée le jour d’une sortie.',
            ],
            'phone' => [
                'label' => 'Téléphone fixe', 'group' => 'contact', 'type' => 'tel',
                'editable' => self::EDIT_SELF,
            ],
            'work_phone' => [
                'label' => 'Téléphone professionnel', 'group' => 'contact', 'type' => 'tel',
                'editable' => self::EDIT_SELF,
            ],
            'secondary_email' => [
                'label' => 'Courriel secondaire', 'group' => 'contact', 'type' => 'email',
                'editable' => self::EDIT_SELF,
            ],

            // --- Urgence ------------------------------------------------------
            'emergency_contact' => [
                'label' => 'Personne à prévenir', 'group' => 'emergency', 'type' => 'text',
                'editable' => self::EDIT_SELF, 'required' => true,
                'placeholder' => 'Prénom Nom — lien de parenté',
            ],
            'emergency_phone' => [
                'label' => 'Téléphone d’urgence', 'group' => 'emergency', 'type' => 'tel',
                'editable' => self::EDIT_SELF, 'required' => true,
            ],

            // --- Plongée ------------------------------------------------------
            // Le niveau n'est PAS modifiable par le membre : il conditionne
            // l'accès aux plongées, donc la sécurité.
            'dive_level_id' => [
                'label' => 'Niveau de plongée', 'group' => 'diving', 'type' => 'dive_level',
                'editable' => self::EDIT_OFFICE,
                'help' => 'Attribué par le bureau sur présentation du brevet.',
            ],
            'nitrox' => [
                'label' => 'Qualification Nitrox', 'group' => 'diving', 'type' => 'select',
                'editable' => self::EDIT_OFFICE,
                'options' => ['' => 'Aucune', 'nitrox' => 'Nitrox élémentaire', 'nitrox_conf' => 'Nitrox confirmé'],
            ],
            'trimix' => [
                'label' => 'Qualification Trimix', 'group' => 'diving', 'type' => 'select',
                'editable' => self::EDIT_OFFICE,
                'options' => ['' => 'Aucune', 'trimix_elem' => 'Trimix élémentaire', 'trimix' => 'Trimix'],
            ],
            'rifap' => [
                'label' => 'RIFAP', 'group' => 'diving', 'type' => 'checkbox',
                'editable' => self::EDIT_OFFICE,
                'help' => 'Réactions et intervention face à un accident de plongée.',
            ],
            'tiv' => [
                'label' => 'Technicien en inspection visuelle (TIV)', 'group' => 'diving', 'type' => 'checkbox',
                'editable' => self::EDIT_OFFICE,
                'help' => 'Habilité à réaliser l’inspection visuelle des blocs.',
            ],
            'tiv_number' => [
                'label' => 'Numéro TIV', 'group' => 'diving', 'type' => 'text',
                'editable' => self::EDIT_OFFICE,
            ],
            'tiv_date' => [
                'label' => 'Date de délivrance TIV', 'group' => 'diving', 'type' => 'date',
                'editable' => self::EDIT_OFFICE,
            ],
            'non_ffessm_certificate' => [
                'label' => 'Brevet non-FFESSM', 'group' => 'diving', 'type' => 'text',
                'editable' => self::EDIT_OFFICE,
                'help' => 'Équivalence PADI, SSI, CMAS… le cas échéant.',
            ],
            'licence_number' => [
                'label' => 'Numéro de licence FFESSM', 'group' => 'diving', 'type' => 'text',
                'editable' => self::EDIT_SELF,
                'placeholder' => 'A-03-000000',
            ],
            'asac_card' => [
                'label' => 'Numéro de carte ASAC', 'group' => 'diving', 'type' => 'text',
                'editable' => self::EDIT_OFFICE,
            ],

            // --- Titres et habilitations ---------------------------------------
            'boat_licence' => [
                'label' => 'Permis bateau', 'group' => 'credentials', 'type' => 'checkbox',
                'editable' => self::EDIT_OFFICE,
            ],
            'boat_licence_number' => [
                'label' => 'Numéro du permis mer', 'group' => 'credentials', 'type' => 'text',
                'editable' => self::EDIT_OFFICE,
            ],
            'boat_licence_date' => [
                'label' => 'Date du permis bateau', 'group' => 'credentials', 'type' => 'date',
                'editable' => self::EDIT_OFFICE,
            ],
            'boat_licence_district' => [
                'label' => 'Quartier maritime', 'group' => 'credentials', 'type' => 'text',
                'editable' => self::EDIT_OFFICE,
            ],
            'radio_certificate' => [
                'label' => 'Certificat restreint de radiotéléphoniste (CRR)', 'group' => 'credentials', 'type' => 'checkbox',
                'editable' => self::EDIT_OFFICE,
            ],
            'radio_certificate_number' => [
                'label' => 'Numéro du CRR', 'group' => 'credentials', 'type' => 'text',
                'editable' => self::EDIT_OFFICE,
            ],
            'radio_certificate_date' => [
                'label' => 'Date du CRR', 'group' => 'credentials', 'type' => 'date',
                'editable' => self::EDIT_OFFICE,
            ],
            'compressor_clearance' => [
                'label' => 'Habilitation compresseur', 'group' => 'credentials', 'type' => 'checkbox',
                'editable' => self::EDIT_OFFICE,
                'help' => 'Autorise le gonflage des blocs.',
            ],
            'compressor_clearance_date' => [
                'label' => 'Date d’habilitation compresseur', 'group' => 'credentials', 'type' => 'date',
                'editable' => self::EDIT_OFFICE,
            ],
            'compressor_trainer' => [
                'label' => 'Entretien et formation gonflage', 'group' => 'credentials', 'type' => 'checkbox',
                'editable' => self::EDIT_OFFICE,
            ],

            // --- Représentant légal ---------------------------------------------
            // Affichés seulement si le membre est mineur : les demander à un
            // adulte n'aurait pas de sens, et les conserver après la majorité
            // encore moins.
            'guardian_name' => [
                'label' => 'Représentant légal', 'group' => 'guardian', 'type' => 'text',
                'editable' => self::EDIT_SELF, 'minor_only' => true, 'required' => true,
                'placeholder' => 'Prénom Nom',
            ],
            'guardian_relation' => [
                'label' => 'Lien', 'group' => 'guardian', 'type' => 'select',
                'editable' => self::EDIT_SELF, 'minor_only' => true,
                'options' => [
                    '' => '—', 'mere' => 'Mère', 'pere' => 'Père',
                    'tuteur' => 'Tuteur ou tutrice', 'autre' => 'Autre',
                ],
            ],
            'guardian_email' => [
                'label' => 'Courriel du représentant', 'group' => 'guardian', 'type' => 'email',
                'editable' => self::EDIT_SELF, 'minor_only' => true, 'required' => true,
                'help' => 'Les rappels d’adhésion et de certificat médical lui seront aussi envoyés.',
            ],
            'guardian_phone' => [
                'label' => 'Téléphone du représentant', 'group' => 'guardian', 'type' => 'tel',
                'editable' => self::EDIT_SELF, 'minor_only' => true, 'required' => true,
            ],

            // --- Véhicule -----------------------------------------------------
            'vehicle_1' => [
                'label' => 'Véhicule principal', 'group' => 'vehicle', 'type' => 'text',
                'editable' => self::EDIT_SELF,
                'help' => 'Immatriculation — utile pour organiser le covoiturage vers les sites.',
            ],
            'vehicle_2' => [
                'label' => 'Second véhicule', 'group' => 'vehicle', 'type' => 'text',
                'editable' => self::EDIT_SELF,
            ],

            // --- Préférences ---------------------------------------------------
            'directory_visible' => [
                'label' => 'Figurer dans l’annuaire des membres', 'group' => 'preferences', 'type' => 'checkbox',
                'editable' => self::EDIT_SELF,
                'help' => 'Votre nom, votre niveau et votre courriel seront visibles des autres membres.',
            ],
        ];

        /**
         * Permet d'ajouter un champ sans modifier ce fichier.
         *
         * @param array<string, array<string, mixed>> $fields
         */
        return (array) apply_filters('subalcatel_profile_fields', $fields);
    }

    /**
     * Champs applicables à un membre donné.
     *
     * Les champs marqués `minor_only` n'existent que pour les mineurs : un
     * majeur ne les voit pas, et une valeur résiduelle ne serait pas relue.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forUser(int $userId): array
    {
        $isMinor = LegalGuardian::isMinor($userId);

        return array_filter(
            self::all(),
            static fn (array $field): bool => empty($field['minor_only']) || $isMinor
        );
    }

    /**
     * @return array<string, string>
     */
    public static function groups(): array
    {
        return [
            'identity'    => 'Identité',
            'contact'     => 'Coordonnées',
            'emergency'   => 'En cas d’urgence',
            'guardian'    => 'Représentant légal',
            'diving'      => 'Plongée',
            'credentials' => 'Titres et habilitations',
            'vehicle'     => 'Véhicules',
            'preferences' => 'Préférences',
        ];
    }

    public static function metaKey(string $field): string
    {
        return 'sub_' . $field;
    }

    public static function get(int $userId, string $field): string
    {
        return (string) get_user_meta($userId, self::metaKey($field), true);
    }

    /**
     * Enregistre les champs soumis, en ne retenant que ceux réellement autorisés.
     *
     * @param array<string, mixed> $input
     * @return list<string> champs refusés
     */
    public static function save(int $userId, array $input, int $actorId): array
    {
        $canEditOthers = user_can($actorId, 'sub_manage_memberships');
        $isSelf        = $userId === $actorId;
        $refused       = [];

        foreach (self::all() as $name => $field) {
            if (!array_key_exists($name, $input)) {
                // Une case décochée n'est pas envoyée : on la remet à zéro
                // uniquement si l'acteur avait le droit de la modifier.
                if ($field['type'] === 'checkbox' && self::mayEdit($field, $isSelf, $canEditOthers)) {
                    update_user_meta($userId, self::metaKey($name), '');
                }

                continue;
            }

            if (!self::mayEdit($field, $isSelf, $canEditOthers)) {
                $refused[] = $name;

                continue;
            }

            update_user_meta($userId, self::metaKey($name), self::sanitize($field, $input[$name]));
        }

        return $refused;
    }

    /**
     * @param array<string, mixed> $field
     */
    public static function mayEdit(array $field, bool $isSelf, bool $canEditOthers): bool
    {
        if ($field['editable'] === self::EDIT_OFFICE) {
            return $canEditOthers;
        }

        return $isSelf || $canEditOthers;
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function sanitize(array $field, mixed $raw): string
    {
        $value = is_array($raw) ? '' : (string) wp_unslash((string) $raw);

        return match ($field['type']) {
            'checkbox'   => $value !== '' ? '1' : '',
            'email'      => sanitize_email($value),
            'date'       => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '',
            'textarea'   => sanitize_textarea_field($value),
            'dive_level' => (string) absint($value),
            'select'     => sanitize_key($value),
            default      => sanitize_text_field($value),
        };
    }
}
