<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Identity\ProfileFields;
use Subalcatel\Club\Support\Audit;

/**
 * Profil du membre : shortcode [subalcatel_profil].
 *
 * Le membre tient ses coordonnées à jour lui-même. Ce qui engage la sécurité —
 * niveau de plongée, qualifications — reste au bureau : ces champs s'affichent,
 * en lecture seule, avec la raison.
 */
final class ProfileForm
{
    public static function register(): void
    {
        add_shortcode('subalcatel_profil', [self::class, 'render']);
        add_action('admin_post_sub_profile_save', [self::class, 'handleSave']);
    }

    public static function render(): string
    {
        if (!is_user_logged_in()) {
            return sprintf(
                '<div class="sub-notice"><strong>Profil réservé aux membres</strong><p><a href="%s">Se connecter</a></p></div>',
                esc_url(wp_login_url(get_permalink()))
            );
        }

        wp_enqueue_style(
            'subalcatel-membership',
            \Subalcatel\Club\PLUGIN_URL . 'assets/css/membership.css',
            [],
            \Subalcatel\Club\VERSION
        );

        wp_enqueue_script(
            'subalcatel-profile',
            \Subalcatel\Club\PLUGIN_URL . 'assets/js/profile.js',
            [],
            \Subalcatel\Club\VERSION,
            true
        );

        $user   = wp_get_current_user();
        $userId = $user->ID;

        ob_start();

        echo Notice::fromQuery(); // déjà échappé
        ?>
        <form class="sub-profile" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="sub_profile_save">
            <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $userId); ?>">
            <?php wp_nonce_field('sub_profile_save_' . $userId); ?>

            <fieldset class="sub-field">
                <legend>Compte</legend>
                <div class="sub-grid">
                    <p class="sub-input">
                        <label for="sub_first_name">Prénom</label>
                        <input type="text" id="sub_first_name" name="first_name"
                               value="<?php echo esc_attr($user->first_name); ?>" required>
                    </p>
                    <p class="sub-input">
                        <label for="sub_last_name">Nom</label>
                        <input type="text" id="sub_last_name" name="last_name"
                               value="<?php echo esc_attr($user->last_name); ?>" required>
                    </p>
                    <p class="sub-input">
                        <label for="sub_email">Courriel</label>
                        <input type="email" id="sub_email" name="user_email"
                               value="<?php echo esc_attr($user->user_email); ?>" required>
                    </p>
                </div>
            </fieldset>

            <?php
            $fields        = ProfileFields::forUser($userId);
            $canEditOthers = current_user_can('sub_manage_memberships');

            // Groupes réellement peuplés : un groupe vidé de ses champs ne doit
            // pas produire un onglet fantôme.
            $groups = [];
            foreach (ProfileFields::groups() as $group => $groupLabel) {
                $groupFields = array_filter($fields, static fn (array $f): bool => $f['group'] === $group);

                if ($groupFields !== []) {
                    $groups[$group] = ['label' => $groupLabel, 'fields' => $groupFields];
                }
            }
            ?>

            <?php // Masqués par défaut : sans JavaScript, tous les groupes restent lisibles. ?>
            <div class="sub-tabs" role="tablist" aria-label="Sections du profil" hidden>
                <?php foreach ($groups as $group => $data) : ?>
                    <button type="button" role="tab" class="sub-tabs__tab"
                            id="sub-tab-<?php echo esc_attr($group); ?>"
                            aria-controls="sub-panel-<?php echo esc_attr($group); ?>"
                            aria-selected="false" tabindex="-1">
                        <?php echo esc_html((string) $data['label']); ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <?php foreach ($groups as $group => $data) : ?>
                <fieldset class="sub-field sub-panel"
                          id="sub-panel-<?php echo esc_attr($group); ?>"
                          role="tabpanel"
                          aria-labelledby="sub-tab-<?php echo esc_attr($group); ?>">
                    <legend><?php echo esc_html((string) $data['label']); ?></legend>
                    <div class="sub-grid">
                        <?php foreach ((array) $data['fields'] as $name => $field) : ?>
                            <?php self::renderField($userId, (string) $name, $field, $canEditOthers); ?>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            <?php endforeach; ?>

            <p><button type="submit" class="sub-button">Enregistrer mon profil</button></p>
        </form>
        <?php

        // Hors du formulaire : un formulaire imbriqué n'est pas du HTML valide,
        // et le navigateur le redécoupe d'une façon qui a déjà coûté cher.
        if ($userId === get_current_user_id()) {
            echo \Subalcatel\Club\Identity\PasswordChange::renderPanel($userId);
            echo \Subalcatel\Club\Communication\Subscriptions::renderPanel($userId);
            echo \Subalcatel\Club\Privacy\AccountDeletion::renderPanel($userId);
        }

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function renderField(int $userId, string $name, array $field, bool $canEditOthers): void
    {
        $editable = ProfileFields::mayEdit($field, true, $canEditOthers);
        $value    = ProfileFields::get($userId, $name);
        $id       = 'sub_' . $name;
        $wide     = in_array($field['type'], ['textarea'], true);
        ?>
        <p class="sub-input <?php echo $wide ? 'sub-input--wide' : ''; ?>">
            <label for="<?php echo esc_attr($id); ?>">
                <?php echo esc_html((string) $field['label']); ?>
                <?php if (!empty($field['required'])) : ?>
                    <span class="sub-field__required" aria-label="obligatoire">*</span>
                <?php endif; ?>
            </label>

            <?php if (!$editable) : ?>
                <span class="sub-readonly">
                    <?php echo esc_html(self::displayValue($name, $field, $value)); ?>
                </span>
            <?php else : ?>
                <?php self::renderInput($id, $name, $field, $value); ?>
            <?php endif; ?>

            <?php if (!empty($field['help'])) : ?>
                <small class="sub-input__help"><?php echo esc_html((string) $field['help']); ?></small>
            <?php endif; ?>
        </p>
        <?php
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function renderInput(string $id, string $name, array $field, string $value): void
    {
        $required = !empty($field['required']) ? 'required' : '';

        switch ($field['type']) {
            case 'textarea':
                printf(
                    '<textarea id="%s" name="%s" rows="3" %s>%s</textarea>',
                    esc_attr($id),
                    esc_attr($name),
                    $required,
                    esc_textarea($value)
                );
                break;

            case 'checkbox':
                printf(
                    '<span class="sub-checkbox"><input type="checkbox" id="%s" name="%s" value="1" %s></span>',
                    esc_attr($id),
                    esc_attr($name),
                    checked($value, '1', false)
                );
                break;

            case 'select':
                printf('<select id="%s" name="%s" %s>', esc_attr($id), esc_attr($name), $required);
                foreach ((array) ($field['options'] ?? []) as $optValue => $optLabel) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr((string) $optValue),
                        selected($value, (string) $optValue, false),
                        esc_html((string) $optLabel)
                    );
                }
                echo '</select>';
                break;

            case 'dive_level':
                $terms = DiveLevels::ordered();
                printf('<select id="%s" name="%s">', esc_attr($id), esc_attr($name));
                echo '<option value="">— non renseigné —</option>';
                foreach ($terms as $term) {
                    printf(
                        '<option value="%d" %s>%s</option>',
                        $term->term_id,
                        selected($value, (string) $term->term_id, false),
                        esc_html($term->name)
                    );
                }
                echo '</select>';
                break;

            default:
                printf(
                    '<input type="%s" id="%s" name="%s" value="%s" placeholder="%s" %s>',
                    esc_attr((string) $field['type']),
                    esc_attr($id),
                    esc_attr($name),
                    esc_attr($value),
                    esc_attr((string) ($field['placeholder'] ?? '')),
                    $required
                );
        }
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function displayValue(string $name, array $field, string $value): string
    {
        if ($field['type'] === 'dive_level') {
            $term = $value !== '' ? get_term((int) $value, DiveLevels::TAXONOMY) : null;

            return $term instanceof \WP_Term ? $term->name : 'Non renseigné';
        }

        if ($field['type'] === 'checkbox') {
            return $value === '1' ? 'Oui' : 'Non';
        }

        if ($field['type'] === 'select') {
            return (string) (($field['options'] ?? [])[$value] ?? 'Non renseigné');
        }

        return $value !== '' ? $value : 'Non renseigné';
    }

    public static function handleSave(): void
    {
        $userId = absint($_POST['user_id'] ?? 0);

        if (!is_user_logged_in() || !check_admin_referer('sub_profile_save_' . $userId)) {
            wp_die('Requête non autorisée.', 403);
        }

        $actorId = get_current_user_id();

        // On ne modifie que son propre profil, sauf droit explicite.
        if ($userId !== $actorId && !current_user_can('sub_manage_memberships')) {
            wp_die('Accès refusé.', 403);
        }

        $previousLevel = ProfileFields::get($userId, 'dive_level_id');

        $update = ['ID' => $userId];
        foreach (['first_name', 'last_name'] as $key) {
            if (isset($_POST[$key])) {
                $update[$key] = sanitize_text_field(wp_unslash((string) $_POST[$key]));
            }
        }

        if (isset($_POST['user_email'])) {
            $email = sanitize_email(wp_unslash((string) $_POST['user_email']));

            if ($email !== '' && is_email($email)) {
                $existing = email_exists($email);

                if ($existing !== false && (int) $existing !== $userId) {
                    self::back('Cette adresse est déjà utilisée par un autre compte.', true);
                }

                $update['user_email'] = $email;
            }
        }

        $update['display_name'] = trim(
            ($update['first_name'] ?? '') . ' ' . ($update['last_name'] ?? '')
        ) ?: get_userdata($userId)->display_name;

        wp_update_user($update);

        /** @var array<string, mixed> $raw */
        $raw     = wp_unslash($_POST);
        $refused = ProfileFields::save($userId, $raw, $actorId);

        // Un changement de niveau est historisé : c'est le registre des brevets
        // du club, et il ne se reconstitue pas après coup.
        $newLevel = ProfileFields::get($userId, 'dive_level_id');

        if ($newLevel !== $previousLevel && $newLevel !== '') {
            global $wpdb;

            $wpdb->insert("{$wpdb->prefix}sub_dive_level_history", [
                'user_id'       => $userId,
                'level_term_id' => (int) $newLevel,
                'obtained_on'   => current_time('Y-m-d'),
                'recorded_by'   => $actorId,
            ]);

            Audit::log('profile.dive_level_changed', 'user', $userId, [
                'from' => $previousLevel,
                'to'   => $newLevel,
            ], $actorId);
        }

        Audit::log('profile.updated', 'user', $userId, [], $actorId);

        self::back(
            $refused === []
                ? 'Profil enregistré.'
                : 'Profil enregistré. Certains champs relèvent du bureau et n’ont pas été modifiés.'
        );
    }

    private static function back(string $message, bool $isError = false): never
    {
        wp_safe_redirect(add_query_arg(
            [$isError ? 'sub_error' : 'sub_done' => rawurlencode($message)],
            wp_get_referer() ?: home_url('/')
        ));
        exit;
    }
}
