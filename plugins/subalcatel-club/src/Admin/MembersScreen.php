<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

use Subalcatel\Club\Documents\DocumentService;
use Subalcatel\Club\Identity\AccountApproval;
use Subalcatel\Club\Identity\AccountFields;
use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Identity\LegalGuardian;
use Subalcatel\Club\Identity\ProfileFields;
use Subalcatel\Club\Identity\Roles;
use Subalcatel\Club\Policy\EligibilityPolicy;
use WP_User;

/**
 * Les personnes : annuaire, fiche individuelle, et les deux files qui les
 * concernent — comptes à valider et pièces justificatives.
 *
 * C'est ici que le bureau attribue les niveaux et les qualifications — les
 * champs que le membre ne peut pas modifier lui-même, parce qu'ils conditionnent
 * l'accès aux plongées.
 *
 * Et c'est ici, depuis le panneau « Compte », que se modifient aussi le
 * courriel, le nom et le rôle WordPress. Ce regroupement est le but : une fiche
 * membre qui renvoie à `wp-admin/users.php` pour changer une adresse oblige à
 * connaître deux écrans, et expose au passage la suppression de compte et la
 * promotion en administrateur. Un seul endroit, et seulement les gestes utiles.
 *
 * Les onglets prolongent ce principe. Valider un compte, vérifier un certificat
 * et consulter une fiche portent sur la même personne ; c'était trois entrées de
 * menu séparées par six lignes, c'est un seul écran.
 */
final class MembersScreen
{
    public const SLUG = 'subalcatel-members';

    /** @var list<string> */
    public const CAPABILITIES = [
        'sub_manage_memberships',
        'sub_validate_account',
        'sub_validate_member_document',
    ];

    public static function register(): void
    {
        add_action('admin_post_sub_member_save', [self::class, 'handleSave']);
        add_action('admin_post_sub_member_account', [self::class, 'handleAccount']);
        add_action('admin_post_sub_member_reset', [self::class, 'handleReset']);
    }

    public static function render(): void
    {
        // La fiche d'un membre n'est pas un onglet : on y arrive depuis
        // l'annuaire, et on en revient. Lui donner un onglet ferait une
        // navigation qui change de sens selon qu'on a cliqué ou non.
        if (absint($_GET['user_id'] ?? 0) > 0) {
            AdminUi::requireCap('sub_manage_memberships');
            AdminUi::enqueue();
            AdminUi::flash();
            self::renderMember(absint($_GET['user_id']));

            return;
        }

        AdminUi::tabbedScreen(self::SLUG, 'Membres', [
            'annuaire' => [
                'label'  => 'Annuaire',
                'cap'    => 'sub_manage_memberships',
                'render' => [self::class, 'renderList'],
            ],
            'comptes'  => [
                'label'  => 'Comptes à valider',
                'cap'    => 'sub_validate_account',
                'count'  => AccountApproval::pendingCount(),
                'render' => [AccountsScreen::class, 'renderTab'],
            ],
            'pieces'   => [
                'label'  => 'Pièces justificatives',
                'cap'    => 'sub_validate_member_document',
                'count'  => count((new DocumentService())->pendingReview()),
                'render' => [DocumentsScreen::class, 'renderTab'],
            ],
        ]);
    }

    public static function renderList(): void
    {
        $search = sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? '')));

        $users = get_users([
            'role__in' => ['sub_member', 'sub_office', 'sub_guest', 'administrator'],
            'search'   => $search !== '' ? '*' . $search . '*' : '',
            'orderby'  => 'display_name',
            'number'   => 200,
        ]);

        $policy = new EligibilityPolicy();
        ?>
            <form method="get" style="margin:0 0 16px;">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                <input type="hidden" name="tab" value="annuaire">
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>"
                       placeholder="Nom ou courriel">
                <button class="button">Rechercher</button>
            </form>

            <table class="wp-list-table widefat striped sub-cards">
                <thead>
                    <tr>
                        <th>Membre</th>
                        <th style="width:110px;">Niveau</th>
                        <th style="width:150px;">Adhésion</th>
                        <th style="width:150px;">Documents</th>
                        <th style="width:140px;">Téléphone</th>
                        <th style="width:110px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($users === []) : ?>
                    <tr><td colspan="6">Aucun membre trouvé.</td></tr>
                <?php endif; ?>

                <?php foreach ($users as $user) : ?>
                    <?php
                    $level      = DiveLevels::forUser($user->ID);
                    $membership = $policy->hasActiveMembership($user->ID);
                    $documents  = $policy->hasValidDocuments($user->ID);
                    ?>
                    <tr>
                        <td data-label="Membre">
                            <strong><?php echo esc_html($user->display_name ?: $user->user_login); ?></strong><br>
                            <span style="color:#50575e;"><?php echo esc_html($user->user_email); ?></span>
                            <?php // Le rôle n'est rappelé que lorsqu'il n'est pas
                                  // « Membre » : sur une liste d'adhérents, l'afficher
                                  // partout ferait du bruit et cacherait l'exception. ?>
                            <?php if (!in_array(Roles::MEMBER, $user->roles, true)) : ?>
                                <br><span class="sub-tag"><?php echo esc_html(self::roleLabel($user, Roles::assignable())); ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Niveau">
                            <?php echo esc_html($level?->name ?? '—'); ?>
                            <?php if (LegalGuardian::isMinor($user->ID)) : ?>
                                <br><span class="sub-tag">mineur</span>
                                <?php if (LegalGuardian::isIncomplete($user->ID)) : ?>
                                    <br><small style="color:#b82a1e;">représentant légal manquant</small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td data-label="Adhésion">
                            <?php if ($membership->allowed) : ?>
                                <?php echo AdminUi::statusBadge('active'); ?>
                            <?php else : ?>
                                <?php echo AdminUi::statusBadge('inactive'); ?>
                                <br><small style="color:#50575e;"><?php echo esc_html($membership->shortLabel()); ?></small>
                            <?php endif; ?>
                        </td>
                        <td data-label="Documents">
                            <?php if ($documents->allowed) : ?>
                                <span class="sub-badge" style="background:#17795e;color:#fff;">À jour</span>
                            <?php else : ?>
                                <span class="sub-badge" style="background:#b82a1e;color:#fff;">À vérifier</span>
                                <br><small style="color:#50575e;"><?php echo esc_html($documents->shortLabel()); ?></small>
                            <?php endif; ?>
                        </td>
                        <td data-label="Téléphone"><?php echo esc_html(ProfileFields::get($user->ID, 'mobile') ?: ProfileFields::get($user->ID, 'phone') ?: '—'); ?></td>
                        <td data-label="Fiche">
                            <a class="button"
                               href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG . '&user_id=' . $user->ID)); ?>">
                                Ouvrir
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php
    }

    private static function renderMember(int $userId): void
    {
        $user = get_userdata($userId);

        if (!$user) {
            wp_die('Membre introuvable.', 404);
        }

        $policy = new EligibilityPolicy();
        $fields = ProfileFields::forUser($userId);
        ?>
        <div class="wrap sub-admin">
            <h1><?php echo esc_html($user->display_name ?: $user->user_login); ?></h1>
            <p class="description">
                <?php echo esc_html($user->user_email); ?> —
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG)); ?>">← Tous les membres</a>
            </p>

            <?php
            $membership = $policy->hasActiveMembership($userId);
            $documents  = $policy->hasValidDocuments($userId);
            ?>
            <ul style="display:flex;flex-wrap:wrap;gap:16px;list-style:none;margin:16px 0;padding:0;">
                <li style="background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:12px 18px;">
                    <strong>Adhésion</strong><br>
                    <?php echo $membership->allowed
                        ? 'Active jusqu’au ' . esc_html(AdminUi::frDate((string) get_user_meta($userId, 'sub_membership_valid_until', true)))
                        : esc_html($membership->shortLabel()); ?>
                </li>
                <li style="background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:12px 18px;">
                    <strong>Documents</strong><br>
                    <?php echo $documents->allowed ? 'À jour' : esc_html($documents->shortLabel()); ?>
                </li>
            </ul>

            <?php self::renderAccountPanel($user); ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="sub_member_save">
                <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $userId); ?>">
                <?php wp_nonce_field('sub_member_save_' . $userId); ?>

                <?php
                // Groupes réellement peuplés : un groupe vidé de ses champs ne
                // doit pas produire un onglet vide.
                $groups = [];
                foreach (ProfileFields::groups() as $group => $groupLabel) {
                    $groupFields = array_filter($fields, static fn (array $f): bool => $f['group'] === $group);

                    if ($groupFields !== []) {
                        $groups[$group] = ['label' => $groupLabel, 'fields' => $groupFields];
                    }
                }
                ?>

                <?php // Masquée par défaut : sans JavaScript, tous les groupes restent lisibles. ?>
                <div class="nav-tab-wrapper sub-tabs" role="tablist" aria-label="Sections de la fiche" hidden>
                    <?php foreach ($groups as $group => $data) : ?>
                        <button type="button" role="tab" class="nav-tab sub-tabs__tab"
                                id="sub-mtab-<?php echo esc_attr($group); ?>"
                                aria-controls="sub-mpanel-<?php echo esc_attr($group); ?>"
                                aria-selected="false" tabindex="-1">
                            <?php echo esc_html((string) $data['label']); ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <?php foreach ($groups as $group => $data) : ?>
                    <div class="sub-panel"
                         id="sub-mpanel-<?php echo esc_attr($group); ?>"
                         role="tabpanel"
                         aria-labelledby="sub-mtab-<?php echo esc_attr($group); ?>">
                        <h2 class="sub-panel__title"><?php echo esc_html((string) $data['label']); ?></h2>
                        <table class="form-table" role="presentation">
                            <?php foreach ((array) $data['fields'] as $name => $field) : ?>
                                <tr>
                                    <th scope="row"><?php echo esc_html((string) $field['label']); ?></th>
                                    <td>
                                        <?php self::renderInput($userId, (string) $name, $field); ?>
                                        <?php if ($field['editable'] === ProfileFields::EDIT_OFFICE) : ?>
                                            <span class="sub-tag">réservé au bureau</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                <?php endforeach; ?>

                <p class="submit"><button class="button button-primary">Enregistrer</button></p>
            </form>

            <?php self::renderLevelHistory($userId); ?>
        </div>
        <?php
    }

    /**
     * Le compte WordPress : courriel, nom, rôle, mot de passe.
     *
     * En tête de fiche et hors des onglets du profil, parce que ce n'est pas
     * une rubrique du dossier de plongée : c'est ce qui décide de qui se
     * connecte, et de ce qu'il voit. Son propre formulaire, aussi — une adresse
     * refusée parce qu'elle est déjà prise ne doit pas faire perdre la saisie
     * d'un niveau et d'une date de RIFAP.
     */
    private static function renderAccountPanel(WP_User $user): void
    {
        $userId = (int) $user->ID;

        if (!current_user_can(AccountFields::CAPABILITY)) {
            return;
        }

        $roles = Roles::assignable();

        // Un compte technique s'affiche sans champ : le masquer laisserait
        // croire à une panne, et il faut bien pouvoir constater son existence.
        if (!Roles::isClubAccount($userId)) {
            ?>
            <div class="notice notice-warning inline" style="margin:16px 0;padding:12px;">
                <p>
                    <strong>Ce compte relève de l’administration technique.</strong>
                    Il porte un rôle WordPress hors du club
                    (<?php echo esc_html(self::roleLabel($user, $roles)); ?>) et ne se modifie
                    pas depuis cet écran — passez par
                    <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $userId)); ?>">
                        la fiche WordPress
                    </a>.
                </p>
            </div>
            <?php

            return;
        }

        $isSelf = $userId === get_current_user_id();
        ?>
        <h2 style="margin-top:24px;">Compte</h2>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="sub_member_account">
            <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $userId); ?>">
            <?php wp_nonce_field('sub_member_account_' . $userId, 'sub_member_account_nonce'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Identifiant</th>
                    <td>
                        <code><?php echo esc_html($user->user_login); ?></code>
                        <p class="description">
                            L’identifiant de connexion ne se change pas : WordPress le fige à
                            la création, et des inscriptions y font référence.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sub_account_first_name">Prénom</label></th>
                    <td>
                        <input type="text" id="sub_account_first_name" name="first_name"
                               class="regular-text" value="<?php echo esc_attr($user->first_name); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sub_account_last_name">Nom</label></th>
                    <td>
                        <input type="text" id="sub_account_last_name" name="last_name"
                               class="regular-text" value="<?php echo esc_attr($user->last_name); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sub_account_email">Courriel</label></th>
                    <td>
                        <input type="email" id="sub_account_email" name="user_email"
                               class="regular-text" value="<?php echo esc_attr($user->user_email); ?>" required>
                        <p class="description">
                            Adresse de connexion, de réinitialisation du mot de passe et de
                            tous les envois du club. La changer engage l’accès au compte :
                            vérifiez-la auprès de la personne.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sub_account_role">Rôle</label></th>
                    <td>
                        <?php if ($isSelf) : ?>
                            <strong><?php echo esc_html(self::roleLabel($user, $roles)); ?></strong>
                            <p class="description">
                                Vous ne pouvez pas changer votre propre rôle. Un autre membre du
                                bureau le fera — c’est ce qui évite de se fermer la porte soi-même.
                            </p>
                        <?php else : ?>
                            <select id="sub_account_role" name="role">
                                <?php foreach ($roles as $slug => $label) : ?>
                                    <option value="<?php echo esc_attr($slug); ?>"
                                        <?php selected(in_array($slug, $user->roles, true)); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                « Membre du bureau » ouvre la gestion du club. « Invité » ne donne
                                accès qu’à la consultation. Le rôle d’administrateur ne s’attribue
                                pas ici : c’est un geste d’administration WordPress.
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Mot de passe</th>
                    <td>
                        <p class="description" style="margin-top:0;">
                            Le bureau ne fixe pas le mot de passe de quelqu’un d’autre : un mot
                            de passe connu de deux personnes n’authentifie plus personne. Le
                            bouton ci-dessous envoie à la personne le lien qui lui permet d’en
                            choisir un.
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit" style="padding-top:0;">
                <button class="button button-primary">Enregistrer le compte</button>
            </p>
        </form>

        <?php // Formulaire distinct : deux boutons dans un même formulaire, et
              // la touche Entrée déclencherait l'envoi du courriel. ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              onsubmit="return confirm('Envoyer un lien de réinitialisation à <?php echo esc_js($user->user_email); ?> ?');">
            <input type="hidden" name="action" value="sub_member_reset">
            <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $userId); ?>">
            <?php wp_nonce_field('sub_member_reset_' . $userId, 'sub_member_reset_nonce'); ?>
            <p>
                <button class="button">Envoyer un lien de réinitialisation</button>
            </p>
        </form>

        <hr style="margin:24px 0;">
        <?php
    }

    /**
     * @param array<string, string> $roles
     */
    private static function roleLabel(WP_User $user, array $roles): string
    {
        $names  = wp_roles()->get_names();
        $labels = [];

        foreach ($user->roles as $slug) {
            $labels[] = $roles[$slug]
                ?? (isset($names[$slug]) ? translate_user_role($names[$slug]) : $slug);
        }

        return $labels === [] ? 'Aucun rôle' : implode(', ', $labels);
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function renderInput(int $userId, string $name, array $field): void
    {
        $value = ProfileFields::get($userId, $name);

        switch ($field['type']) {
            case 'dive_level':
                $terms = DiveLevels::ordered();
                echo '<select name="' . esc_attr($name) . '"><option value="">— non renseigné —</option>';
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

            case 'checkbox':
                printf(
                    '<label><input type="checkbox" name="%s" value="1" %s> Oui</label>',
                    esc_attr($name),
                    checked($value, '1', false)
                );
                break;

            case 'select':
                echo '<select name="' . esc_attr($name) . '">';
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

            case 'textarea':
                printf(
                    '<textarea name="%s" rows="3" class="large-text">%s</textarea>',
                    esc_attr($name),
                    esc_textarea($value)
                );
                break;

            default:
                printf(
                    '<input type="%s" name="%s" value="%s" class="regular-text">',
                    esc_attr((string) $field['type']),
                    esc_attr($name),
                    esc_attr($value)
                );
        }
    }

    private static function renderLevelHistory(int $userId): void
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sub_dive_level_history
             WHERE user_id = %d ORDER BY obtained_on DESC, id DESC",
            $userId
        ), ARRAY_A) ?: [];

        if ($rows === []) {
            return;
        }
        ?>
        <h2>Historique des niveaux</h2>
        <p class="description">
            Le registre des brevets du club. Il ne se reconstitue pas après coup,
            d’où sa conservation systématique.
        </p>
        <table class="wp-list-table widefat striped sub-cards" style="max-width:600px;">
            <thead><tr><th>Niveau</th><th style="width:140px;">Obtenu le</th><th style="width:180px;">Enregistré par</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row) : ?>
                <?php
                $term  = get_term((int) $row['level_term_id'], DiveLevels::TAXONOMY);
                $actor = $row['recorded_by'] ? get_userdata((int) $row['recorded_by']) : null;
                ?>
                <tr>
                    <td data-label="Niveau"><?php echo esc_html($term instanceof \WP_Term ? $term->name : '—'); ?></td>
                    <td data-label="Obtenu le"><?php echo esc_html(AdminUi::frDate((string) $row['obtained_on'])); ?></td>
                    <td data-label="Enregistré par"><?php echo esc_html($actor?->display_name ?? '—'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public static function handleSave(): void
    {
        $userId = absint($_POST['user_id'] ?? 0);
        check_admin_referer('sub_member_save_' . $userId);
        AdminUi::requireCap('sub_manage_memberships');

        $actorId       = get_current_user_id();
        $previousLevel = ProfileFields::get($userId, 'dive_level_id');

        /** @var array<string, mixed> $raw */
        $raw = wp_unslash($_POST);
        ProfileFields::save($userId, $raw, $actorId);

        $newLevel = ProfileFields::get($userId, 'dive_level_id');

        if ($newLevel !== $previousLevel && $newLevel !== '') {
            global $wpdb;

            $wpdb->insert("{$wpdb->prefix}sub_dive_level_history", [
                'user_id'       => $userId,
                'level_term_id' => (int) $newLevel,
                'obtained_on'   => current_time('Y-m-d'),
                'recorded_by'   => $actorId,
            ]);

            \Subalcatel\Club\Support\Audit::log('profile.dive_level_changed', 'user', $userId, [
                'from' => $previousLevel,
                'to'   => $newLevel,
            ], $actorId);
        }

        \Subalcatel\Club\Support\Audit::log('member.updated', 'user', $userId, [], $actorId);

        AdminUi::redirect(self::SLUG, 'Fiche enregistrée.', false, ['user_id' => $userId]);
    }

    public static function handleAccount(): void
    {
        $userId = absint($_POST['user_id'] ?? 0);
        check_admin_referer('sub_member_account_' . $userId, 'sub_member_account_nonce');
        AdminUi::requireCap(AccountFields::CAPABILITY);

        /** @var array<string, mixed> $raw */
        $raw    = wp_unslash($_POST);
        $result = AccountFields::apply($userId, get_current_user_id(), $raw);

        AdminUi::redirect(self::SLUG, $result['message'], !$result['ok'], ['user_id' => $userId]);
    }

    public static function handleReset(): void
    {
        $userId = absint($_POST['user_id'] ?? 0);
        check_admin_referer('sub_member_reset_' . $userId, 'sub_member_reset_nonce');
        AdminUi::requireCap(AccountFields::CAPABILITY);

        $result = AccountFields::sendResetLink($userId, get_current_user_id());

        AdminUi::redirect(self::SLUG, $result['message'], !$result['ok'], ['user_id' => $userId]);
    }
}
