<?php

declare(strict_types=1);

namespace Subalcatel\Club\Membership;

use Subalcatel\Club\Identity\ProfileFields;

/**
 * Les coordonnées sans lesquelles un dossier d'adhésion ne sert à rien.
 *
 * Le profil porte déjà ces champs, et le bureau n'a pas à courir après eux une
 * fois le dossier déposé : la licence FFESSM se prend sur l'état civil complet,
 * date **et** lieu de naissance, faute de quoi deux homonymes se confondent à
 * la fédération. Ils sont donc demandés dans le formulaire d'adhésion, préremplis
 * depuis le profil et réenregistrés dedans — une seule source, deux endroits où
 * la renseigner.
 *
 * Le courriel principal est celui du compte : il ne vit pas dans les métadonnées
 * de profil, d'où le traitement à part.
 */
final class ApplicantIdentity
{
    public const ACCOUNT_EMAIL = 'user_email';

    /**
     * Champs demandés au dépôt du dossier, dans l'ordre d'affichage.
     *
     * @return array<string, array{label: string, type: string, required: bool, help?: string, placeholder?: string}>
     */
    public static function fields(): array
    {
        return [
            'birth_date' => [
                'label' => 'Date de naissance', 'type' => 'date', 'required' => true,
            ],
            'birth_city' => [
                'label' => 'Ville de naissance', 'type' => 'text', 'required' => true,
                'help'  => 'Exigée par la FFESSM pour distinguer deux homonymes.',
            ],
            'birth_department' => [
                'label' => 'Département de naissance', 'type' => 'text', 'required' => true,
                'placeholder' => '22',
                'help'  => 'Pour une naissance hors de France, laissez vide et indiquez le pays.',
            ],
            'birth_country' => [
                'label' => 'Pays de naissance', 'type' => 'text', 'required' => true,
                'placeholder' => 'France',
            ],
            'address' => [
                'label' => 'Adresse postale', 'type' => 'textarea', 'required' => true,
                'placeholder' => 'Numéro et voie',
            ],
            'postal_code' => [
                'label' => 'Code postal', 'type' => 'text', 'required' => true,
                'placeholder' => '22300',
            ],
            'city' => [
                'label' => 'Ville', 'type' => 'text', 'required' => true,
            ],
            'mobile' => [
                'label' => 'Téléphone', 'type' => 'tel', 'required' => true,
                'help'  => 'Communiqué au directeur de plongée le jour d’une sortie.',
            ],
            self::ACCOUNT_EMAIL => [
                'label' => 'Courriel principal', 'type' => 'email', 'required' => true,
                'help'  => 'Il sert d’identifiant de connexion et reçoit les messages du club.',
            ],
            'secondary_email' => [
                'label' => 'Courriel secondaire', 'type' => 'email', 'required' => false,
                'help'  => 'Facultatif. Une seconde adresse, différente de la principale.',
            ],
        ];
    }

    /**
     * Valeurs actuelles, prêtes à préremplir le formulaire.
     *
     * @return array<string, string>
     */
    public static function values(int $userId): array
    {
        $values = [];

        foreach (array_keys(self::fields()) as $name) {
            $values[$name] = $name === self::ACCOUNT_EMAIL
                ? (string) (get_userdata($userId)->user_email ?? '')
                : ProfileFields::get($userId, $name);
        }

        return $values;
    }

    /**
     * Champs obligatoires encore vides, par nom technique.
     *
     * Le département n'a de sens qu'en France : l'exiger d'une personne née à
     * l'étranger reviendrait à lui demander d'inventer un chiffre.
     *
     * @param array<string, string>|null $values Valeurs à contrôler ; celles du compte par défaut.
     * @return array<string, string> nom technique => libellé
     */
    public static function missing(int $userId, ?array $values = null): array
    {
        $values ??= self::values($userId);
        $missing = [];

        foreach (self::fields() as $name => $field) {
            if (!$field['required'] || trim($values[$name] ?? '') !== '') {
                continue;
            }

            if ($name === 'birth_department' && !self::bornInFrance($values['birth_country'] ?? '')) {
                continue;
            }

            $missing[$name] = $field['label'];
        }

        return $missing;
    }

    private static function bornInFrance(string $country): bool
    {
        $country = mb_strtolower(trim($country));

        return $country === '' || $country === 'france';
    }

    /**
     * Enregistre les coordonnées soumises avec un dossier.
     *
     * Rien n'est écrit tant qu'une valeur est refusée : un profil à moitié
     * enregistré est plus difficile à corriger qu'un formulaire à recommencer.
     *
     * @param array<string, mixed> $input Valeurs postées, déjà déséchappées.
     * @return array{values: array<string, string>, errors: array<string, string>, notices: list<string>}
     */
    public static function collect(int $userId, array $input): array
    {
        $values  = [];
        $errors  = [];
        $notices = [];

        foreach (self::fields() as $name => $field) {
            $raw = is_array($input[$name] ?? null) ? '' : (string) ($input[$name] ?? '');

            $values[$name] = match ($field['type']) {
                'email'    => sanitize_email($raw),
                'textarea' => sanitize_textarea_field($raw),
                'date'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($raw)) === 1 ? trim($raw) : '',
                default    => sanitize_text_field($raw),
            };
        }

        foreach (self::missing($userId, $values) as $name => $label) {
            $errors[$name] = sprintf('%s : à renseigner.', $label);
        }

        $email = $values[self::ACCOUNT_EMAIL];

        if ($email !== '' && !is_email($email)) {
            $errors[self::ACCOUNT_EMAIL] = 'Cette adresse de courriel n’est pas valide.';
        } elseif ($email !== '') {
            $owner = email_exists($email);

            if ($owner !== false && (int) $owner !== $userId) {
                $errors[self::ACCOUNT_EMAIL] = 'Cette adresse est déjà utilisée par un autre compte.';
            }
        }

        $secondary = $values['secondary_email'];

        if ($secondary !== '' && !is_email($secondary)) {
            $errors['secondary_email'] = 'Cette adresse de courriel n’est pas valide.';
        } elseif ($secondary !== '' && strcasecmp($secondary, $email) === 0) {
            // Une seconde adresse identique à la première ne double rien : elle
            // donne l'illusion d'un recours qui n'existe pas le jour où la
            // boîte principale ne répond plus.
            $values['secondary_email'] = '';
            $notices[] = 'Le courriel secondaire était identique au principal : il n’a pas été retenu.';
        }

        return ['values' => $values, 'errors' => $errors, 'notices' => $notices];
    }

    /**
     * Écrit les valeurs contrôlées sur le compte et le profil.
     *
     * @param array<string, string> $values Sortie de [collect], sans erreur.
     */
    public static function save(int $userId, array $values): void
    {
        $email = $values[self::ACCOUNT_EMAIL] ?? '';

        if ($email !== '' && is_email($email) && $email !== get_userdata($userId)->user_email) {
            wp_update_user(['ID' => $userId, 'user_email' => $email]);
        }

        foreach (self::fields() as $name => $field) {
            if ($name === self::ACCOUNT_EMAIL) {
                continue;
            }

            update_user_meta($userId, ProfileFields::metaKey($name), $values[$name] ?? '');
        }
    }
}
