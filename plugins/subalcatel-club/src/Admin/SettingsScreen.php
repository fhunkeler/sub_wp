<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

use Subalcatel\Club\Content\Visibility;
use Subalcatel\Club\Frontend\Pages;
use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Setup\SiteBuilder;
use Subalcatel\Club\Setup\SiteMap;
use Subalcatel\Club\Support\Audit;

/**
 * Réglages du club : les référentiels que le bureau fait vivre.
 *
 * Niveaux de plongée, types d'événement, types de documents, stockage, pages du
 * site, journal. Ils partagent un même rythme — on les ouvre deux fois par an —
 * et c'est ce rythme qui les réunit ici, loin des écrans de travail quotidien.
 * Aucun n'exige de développeur.
 *
 * Note sur les niveaux : WordPress ne fournit aucun écran natif pour les
 * taxonomies rattachées aux utilisateurs, d'où cet écran maison.
 */
final class SettingsScreen
{
    public const SLUG = 'subalcatel-settings';

    /** @var list<string> */
    public const CAPABILITIES = ['sub_manage_event_types', 'sub_manage_memberships', 'sub_manage_content'];

    public static function register(): void
    {
        add_action('admin_post_sub_build_site', [self::class, 'handleBuildSite']);
        add_action('admin_post_sub_event_type_save', [self::class, 'handleTypeSave']);
        add_action('admin_post_sub_event_type_delete', [self::class, 'handleTypeDelete']);
        add_action('admin_post_sub_level_save', [self::class, 'handleLevelSave']);
        add_action('admin_post_sub_level_delete', [self::class, 'handleLevelDelete']);
    }

    public static function render(): void
    {
        AdminUi::tabbedScreen(self::SLUG, 'Réglages du club', [
            'levels'                     => [
                'label'  => 'Niveaux de plongée',
                'cap'    => 'sub_manage_event_types',
                'render' => [self::class, 'renderLevels'],
            ],
            'event_types'                => [
                'label'  => 'Types d’événement',
                'cap'    => 'sub_manage_event_types',
                'render' => [self::class, 'renderEventTypes'],
            ],
            DocumentsScreen::TAB_TYPES   => [
                'label'  => 'Types de documents',
                'cap'    => 'sub_manage_memberships',
                'render' => [DocumentsScreen::class, 'renderTypesTab'],
            ],
            DocumentsScreen::TAB_STORAGE => [
                'label'  => 'Stockage des documents',
                'cap'    => 'sub_manage_memberships',
                'render' => [DocumentsScreen::class, 'renderStorageTab'],
            ],
            'pages'                      => [
                'label'  => 'Pages du site',
                'cap'    => 'sub_manage_event_types',
                'render' => [self::class, 'renderPages'],
            ],
            ClubDocumentsScreen::TAB     => [
                'label'  => 'Contrôle d’intégrité',
                'cap'    => 'sub_manage_content',
                'render' => [ClubDocumentsScreen::class, 'renderIntegrityTab'],
            ],
            'audit'                      => [
                'label'  => 'Journal',
                'cap'    => 'sub_manage_event_types',
                'render' => [self::class, 'renderAudit'],
            ],
        ]);
    }

    // ------------------------------------------------------- Niveaux de plongée

    public static function renderLevels(): void
    {
        // Par rang, pas par nom : l'alphabet place E4 avant P1 et PA12 avant P0.
        $levels = DiveLevels::ordered();
        ?>
        <p class="description">
            Les trois cases déterminent les droits. Elles remplacent les rôles
            « encadrant » et « directeur de plongée » : quand un membre passe un brevet,
            son niveau change et ses droits suivent, sans intervention.
        </p>
        <p class="description">
            Les deux <strong>rangs</strong> disent l’ordre, et c’est eux que les
            inscriptions comparent. Un rang plus élevé donne accès à tout ce qu’ouvre
            un rang plus bas : une sortie ouverte au P2 accepte les P3, P4 et P5 sans
            qu’on ait à les énumérer. Ils vont de 10 en 10 pour qu’on puisse intercaler
            un niveau sans tout renuméroter. Le rang d’encadrement est un second axe,
            indépendant : « P5/E2 » vaut P5 comme plongeur <em>et</em> E2 comme
            encadrant. Un niveau qui n’est pas de la plongée en scaphandre — NAP — reste
            à 0 sur les deux.
        </p>

        <div class="sub-scroll">
        <table class="wp-list-table widefat striped" style="min-width:820px;">
            <thead>
                <tr>
                    <th>Niveau</th>
                    <th style="width:110px;">Rang plongeur</th>
                    <th style="width:130px;">Rang encadrement</th>
                    <th style="width:130px;">Autonome</th>
                    <th style="width:130px;">Encadrant</th>
                    <th style="width:170px;">Directeur de plongée</th>
                    <th style="width:110px;">Membres</th>
                    <th style="width:200px;"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($levels as $level) : ?>
                <?php
                $formId = 'sub-level-' . $level->term_id;
                $count  = self::membersAtLevel($level->term_id);
                ?>
                <tr>
                    <td>
                        <form id="<?php echo esc_attr($formId); ?>" method="post"
                              action="<?php echo esc_url(admin_url('admin-post.php')); ?>"></form>
                        <input type="hidden" form="<?php echo esc_attr($formId); ?>" name="action" value="sub_level_save">
                        <input type="hidden" form="<?php echo esc_attr($formId); ?>" name="term_id"
                               value="<?php echo esc_attr((string) $level->term_id); ?>">
                        <input type="hidden" form="<?php echo esc_attr($formId); ?>" name="_wpnonce"
                               value="<?php echo esc_attr(wp_create_nonce('sub_level_save')); ?>">
                        <input type="text" form="<?php echo esc_attr($formId); ?>" name="name"
                               value="<?php echo esc_attr($level->name); ?>" required>
                    </td>
                    <?php foreach ([DiveLevels::RANK_DIVER, DiveLevels::RANK_TEACHING] as $axis) : ?>
                        <td>
                            <input type="number" form="<?php echo esc_attr($formId); ?>" min="0" step="10"
                                   class="small-text" name="<?php echo esc_attr($axis); ?>"
                                   value="<?php echo esc_attr((string) DiveLevels::rankOf($level->term_id, $axis)); ?>">
                        </td>
                    <?php endforeach; ?>
                    <?php foreach ([
                        DiveLevels::FLAG_AUTONOMOUS,
                        DiveLevels::FLAG_INSTRUCTOR,
                        DiveLevels::FLAG_DIVE_LEADER,
                    ] as $flag) : ?>
                        <td>
                            <input type="checkbox" form="<?php echo esc_attr($formId); ?>"
                                   name="<?php echo esc_attr($flag); ?>" value="1"
                                   <?php checked(get_term_meta($level->term_id, $flag, true), '1'); ?>>
                        </td>
                    <?php endforeach; ?>
                    <td style="font-variant-numeric:tabular-nums;"><?php echo (int) $count; ?></td>
                    <td>
                        <button class="button" form="<?php echo esc_attr($formId); ?>">Enregistrer</button>
                        <?php if ($count === 0) : ?>
                            <?php AdminUi::actionButton(
                                'sub_level_delete',
                                ['term_id' => $level->term_id],
                                'Supprimer',
                                'button-link-delete button-link',
                                'Supprimer ce niveau ?'
                            ); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <h2 style="margin-top:28px;">Ajouter un niveau</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sub-form">
            <input type="hidden" name="action" value="sub_level_save">
            <?php wp_nonce_field('sub_level_save'); ?>
            <p>
                <input type="text" name="name" placeholder="P4" required>
                <label style="margin-left:12px;">Rang plongeur
                    <input type="number" name="<?php echo esc_attr(DiveLevels::RANK_DIVER); ?>"
                           class="small-text" min="0" step="10" value="0"></label>
                <label style="margin-left:12px;">Rang encadrement
                    <input type="number" name="<?php echo esc_attr(DiveLevels::RANK_TEACHING); ?>"
                           class="small-text" min="0" step="10" value="0"></label>
                <label style="margin-left:12px;"><input type="checkbox" name="<?php echo esc_attr(DiveLevels::FLAG_AUTONOMOUS); ?>" value="1"> Autonome</label>
                <label style="margin-left:12px;"><input type="checkbox" name="<?php echo esc_attr(DiveLevels::FLAG_INSTRUCTOR); ?>" value="1"> Encadrant</label>
                <label style="margin-left:12px;"><input type="checkbox" name="<?php echo esc_attr(DiveLevels::FLAG_DIVE_LEADER); ?>" value="1"> Directeur de plongée</label>
                <button class="button button-primary" style="margin-left:12px;">Ajouter</button>
            </p>
        </form>
        <?php
    }

    // -------------------------------------------------------- Types d'événement

    public static function renderEventTypes(): void
    {
        global $wpdb;

        $types = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}sub_event_types ORDER BY name",
            ARRAY_A
        ) ?: [];

        $capabilities = [
            'sub_create_governance_event'  => 'Bureau (AG, réunions)',
            'sub_create_exploration_event' => 'Plongée d’exploration',
            'sub_create_training_event'    => 'Plongée de formation',
        ];
        ?>
        <p class="description">
            Un type porte les règles de création et d’inscription. Un événement les
            <strong>copie</strong> au moment de sa création : modifier un type ne
            réécrit jamais les événements passés.
        </p>

        <?php foreach ($types as $type) : ?>
            <details class="sub-card">
                <summary>
                    <strong><?php echo esc_html((string) $type['name']); ?></strong>
                    <code><?php echo esc_html((string) $type['slug']); ?></code>
                    <?php if ((int) $type['requires_dive_leader'] === 1) : ?>
                        <span class="sub-tag">directeur de plongée requis</span>
                    <?php elseif ((int) $type['requires_autonomous'] === 1) : ?>
                        <span class="sub-tag">plongeur autonome requis</span>
                    <?php endif; ?>
                </summary>
                <?php self::eventTypeForm($type, $capabilities); ?>
            </details>
        <?php endforeach; ?>

        <h2 style="margin-top:28px;">Ajouter un type</h2>
        <div class="sub-card sub-card--open">
            <?php self::eventTypeForm(null, $capabilities); ?>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed>|null $type
     * @param array<string, string> $capabilities
     */
    private static function eventTypeForm(?array $type, array $capabilities): void
    {
        $isNew = $type === null;
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sub-form">
            <input type="hidden" name="action" value="sub_event_type_save">
            <?php if (!$isNew) : ?>
                <input type="hidden" name="type_id" value="<?php echo esc_attr((string) $type['id']); ?>">
            <?php endif; ?>
            <?php wp_nonce_field('sub_event_type_save'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Nom</th>
                    <td>
                        <input type="text" name="name" class="regular-text" required
                               value="<?php echo esc_attr((string) ($type['name'] ?? '')); ?>"
                               placeholder="Plongée d’exploration">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Qui peut en créer</th>
                    <td>
                        <select name="create_capability">
                            <?php foreach ($capabilities as $cap => $label) : ?>
                                <option value="<?php echo esc_attr($cap); ?>"
                                        <?php selected($type['create_capability'] ?? '', $cap); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Contraintes du créateur</th>
                    <td>
                        <label>
                            <input type="checkbox" name="requires_autonomous" value="1"
                                   <?php checked((int) ($type['requires_autonomous'] ?? 0), 1); ?>>
                            Doit être plongeur autonome
                        </label><br>
                        <label>
                            <input type="checkbox" name="requires_dive_leader" value="1"
                                   <?php checked((int) ($type['requires_dive_leader'] ?? 0), 1); ?>>
                            Doit être directeur de plongée
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Conditions d’inscription</th>
                    <td>
                        <label>
                            <input type="checkbox" name="requires_membership" value="1"
                                   <?php checked((int) ($type['requires_membership'] ?? 1), 1); ?>>
                            Adhésion active exigée
                        </label><br>
                        <label>
                            <input type="checkbox" name="requires_medical" value="1"
                                   <?php checked((int) ($type['requires_medical'] ?? 1), 1); ?>>
                            Certificat médical et licence à jour exigés
                        </label>
                        <p class="description">
                            À décocher pour une assemblée générale ou une réunion.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Valeurs par défaut</th>
                    <td>
                        <input type="number" name="default_capacity" class="small-text" min="0"
                               value="<?php echo esc_attr((string) ($type['default_capacity'] ?? 0)); ?>"> places
                        <label style="margin-left:16px;">
                            <input type="checkbox" name="allow_waiting_list" value="1"
                                   <?php checked((int) ($type['allow_waiting_list'] ?? 1), 1); ?>>
                            Liste d’attente
                        </label>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button class="button button-primary"><?php echo $isNew ? 'Ajouter le type' : 'Enregistrer'; ?></button>
            </p>
        </form>

        <?php
        // Frère du formulaire d'édition, jamais enfant : des <form> imbriqués
        // sont invalides et le navigateur les redécoupe.
        if (!$isNew) {
            AdminUi::actionButton(
                'sub_event_type_delete',
                ['type_id' => (int) $type['id']],
                'Supprimer ce type',
                'button-link-delete button-link',
                'Supprimer ce type ? Les événements déjà créés ne sont pas affectés.'
            );
        }
    }

    // ------------------------------------------------------------------ Journal

    // ------------------------------------------------------------ Pages du site

    public static function renderPages(): void
    {
        $installed = 0;
        $missing   = [];

        foreach (SiteMap::pages() as $page) {
            if (Pages::exists((string) $page['key'])) {
                $installed++;
            } else {
                $missing[] = (string) $page['title'];
            }
        }

        $total = count(SiteMap::pages());
        ?>
        <h2>Arborescence du site</h2>

        <p class="description">
            Installe les pages publiques et l’espace membre, avec leur hiérarchie, leur
            visibilité et les menus. <strong>Rejouable sans risque</strong> : une page dont
            vous avez modifié le texte n’est jamais réécrite, et rien n’est dupliqué.
        </p>

        <p>
            <?php printf(
                '<strong>%d page(s) sur %d</strong> installée(s).',
                (int) $installed,
                (int) $total
            ); ?>
        </p>

        <?php if ($missing !== []) : ?>
            <p class="description">Manquantes : <?php echo esc_html(implode(', ', $missing)); ?></p>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="sub_build_site">
            <?php wp_nonce_field('sub_build_site'); ?>
            <p>
                <button type="submit" class="button button-primary">
                    <?php echo $installed === 0 ? 'Installer les pages du site' : 'Mettre à jour les pages'; ?>
                </button>
            </p>
        </form>

        <h3>Ce qui sera créé</h3>
        <div class="sub-scroll">
            <table class="widefat striped">
                <thead>
                    <tr><th>Page</th><th>Adresse</th><th>Qui peut la voir</th><th>État</th></tr>
                </thead>
                <tbody>
                <?php foreach (SiteMap::pages() as $page) : ?>
                    <?php
                    $key    = (string) $page['key'];
                    $exists = Pages::exists($key);
                    $level  = (string) ($page['visibility'] ?? Visibility::PUBLIC_ACCESS);
                    ?>
                    <tr>
                        <td>
                            <?php if ($exists) : ?>
                                <a href="<?php echo esc_url(Pages::url($key)); ?>">
                                    <?php echo esc_html((string) $page['title']); ?>
                                </a>
                            <?php else : ?>
                                <?php echo esc_html((string) $page['title']); ?>
                            <?php endif; ?>
                        </td>
                        <td><code>/<?php echo esc_html($key); ?>/</code></td>
                        <td><?php echo esc_html(Visibility::levels()[$level] ?? $level); ?></td>
                        <td><?php echo $exists ? '✅ installée' : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function handleBuildSite(): void
    {
        check_admin_referer('sub_build_site');
        AdminUi::requireCap('sub_manage_content');

        $result = SiteBuilder::run();

        $message = sprintf(
            '%d page(s) créée(s), %d mise(s) à jour, %d conservée(s), %d menu(s) construit(s).',
            $result['created'],
            $result['updated'],
            $result['preserved'],
            $result['menus']
        );

        if ($result['messages'] !== []) {
            $message .= ' ' . implode(' ', $result['messages']);
        }

        wp_safe_redirect(add_query_arg(
            ['page' => self::SLUG, 'tab' => 'pages', 'sub_done' => rawurlencode($message)],
            admin_url('admin.php')
        ));
        exit;
    }

    public static function renderAudit(): void
    {
        $entries = Audit::recent(100);
        ?>
        <p class="description">
            Trace des actions sensibles. Ce journal ne peut pas être modifié :
            c'est ce qui le rend opposable.
        </p>

        <div class="sub-scroll">
        <table class="wp-list-table widefat striped" style="min-width:840px;">
            <thead>
                <tr>
                    <th style="width:160px;">Quand</th>
                    <th style="width:170px;">Qui</th>
                    <th style="width:200px;">Action</th>
                    <th style="width:130px;">Objet</th>
                    <th>Détails</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($entries === []) : ?>
                <tr><td colspan="5">Journal vide.</td></tr>
            <?php endif; ?>

            <?php foreach ($entries as $entry) : ?>
                <?php // `get_userdata` rend `false` sur un compte supprimé, et `?->`
                      // ne court-circuite que sur `null` : sans ce `?: null`, une
                      // entrée dont l'auteur a été supprimé émet un avertissement. ?>
                <?php $user = $entry['user_id'] ? get_userdata((int) $entry['user_id']) ?: null : null; ?>
                <tr>
                    <td><?php echo esc_html(wp_date('d/m/Y H:i', strtotime((string) $entry['created_at']))); ?></td>
                    <td><?php echo esc_html($user?->display_name ?: '—'); ?></td>
                    <td><code><?php echo esc_html((string) $entry['action']); ?></code></td>
                    <td>
                        <?php echo esc_html((string) $entry['entity_type']); ?>
                        <?php echo $entry['entity_id'] ? '#' . (int) $entry['entity_id'] : ''; ?>
                    </td>
                    <td style="color:#50575e;font-size:12px;">
                        <?php echo esc_html((string) ($entry['details'] ?? '')); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    // ------------------------------------------------------------------ Actions

    public static function handleTypeSave(): void
    {
        check_admin_referer('sub_event_type_save');
        AdminUi::requireCap('sub_manage_event_types');

        global $wpdb;

        $name = sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? '')));

        if ($name === '') {
            AdminUi::redirect(self::SLUG, 'Le nom est obligatoire.', true, ['tab' => 'event_types']);
        }

        $data = [
            'name'                 => $name,
            'create_capability'    => sanitize_key(wp_unslash((string) ($_POST['create_capability'] ?? 'sub_create_governance_event'))),
            'requires_autonomous'  => isset($_POST['requires_autonomous']) ? 1 : 0,
            'requires_dive_leader' => isset($_POST['requires_dive_leader']) ? 1 : 0,
            'requires_medical'     => isset($_POST['requires_medical']) ? 1 : 0,
            'requires_membership'  => isset($_POST['requires_membership']) ? 1 : 0,
            'default_capacity'     => absint($_POST['default_capacity'] ?? 0),
            'allow_waiting_list'   => isset($_POST['allow_waiting_list']) ? 1 : 0,
        ];

        $typeId = absint($_POST['type_id'] ?? 0);

        if ($typeId > 0) {
            $wpdb->update("{$wpdb->prefix}sub_event_types", $data, ['id' => $typeId]);
        } else {
            $data['slug'] = sanitize_title($name);
            $wpdb->insert("{$wpdb->prefix}sub_event_types", $data);
            $typeId = (int) $wpdb->insert_id;
        }

        Audit::log('event_type.saved', 'event_type', $typeId, ['name' => $name]);
        AdminUi::redirect(self::SLUG, 'Type enregistré.', false, ['tab' => 'event_types']);
    }

    public static function handleTypeDelete(): void
    {
        check_admin_referer('sub_event_type_delete');
        AdminUi::requireCap('sub_manage_event_types');

        global $wpdb;
        $typeId = absint($_POST['type_id'] ?? 0);

        // Un type utilisé n'est jamais supprimé : les événements y renvoient
        // pour leurs règles de formulaire et leur libellé. Le retirer laisserait
        // des sorties sans type, et les inscriptions sans questions.
        $used = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sub_events WHERE type_id = %d",
            $typeId
        ));

        if ($used > 0) {
            AdminUi::redirect(
                self::SLUG,
                sprintf(
                    'Suppression refusée : %d événement(s) utilisent ce type. '
                    . 'Modifiez-le plutôt que de le retirer.',
                    $used
                ),
                true,
                ['tab' => 'event_types']
            );
        }

        $wpdb->delete("{$wpdb->prefix}sub_event_types", ['id' => $typeId]);
        Audit::log('event_type.deleted', 'event_type', $typeId);

        AdminUi::redirect(self::SLUG, 'Type supprimé.', false, ['tab' => 'event_types']);
    }

    public static function handleLevelSave(): void
    {
        check_admin_referer('sub_level_save');
        AdminUi::requireCap('sub_manage_event_types');

        $name   = sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? '')));
        $termId = absint($_POST['term_id'] ?? 0);

        if ($name === '') {
            AdminUi::redirect(self::SLUG, 'Le nom du niveau est obligatoire.', true);
        }

        if ($termId > 0) {
            wp_update_term($termId, DiveLevels::TAXONOMY, ['name' => $name]);
        } else {
            $term = wp_insert_term($name, DiveLevels::TAXONOMY);

            if (is_wp_error($term)) {
                AdminUi::redirect(self::SLUG, 'Ce niveau existe déjà.', true);
            }

            $termId = (int) $term['term_id'];
        }

        foreach ([
            DiveLevels::FLAG_AUTONOMOUS,
            DiveLevels::FLAG_INSTRUCTOR,
            DiveLevels::FLAG_DIVE_LEADER,
        ] as $flag) {
            update_term_meta($termId, $flag, isset($_POST[$flag]) ? '1' : '0');
        }

        foreach ([DiveLevels::RANK_DIVER, DiveLevels::RANK_TEACHING] as $axis) {
            update_term_meta($termId, $axis, (string) absint($_POST[$axis] ?? 0));
        }

        Audit::log('dive_level.saved', 'dive_level', $termId, ['name' => $name]);
        AdminUi::redirect(self::SLUG, 'Niveau enregistré.');
    }

    public static function handleLevelDelete(): void
    {
        check_admin_referer('sub_level_delete');
        AdminUi::requireCap('sub_manage_event_types');

        $termId = absint($_POST['term_id'] ?? 0);

        // Un niveau porté par un membre n'est jamais supprimé : cela laisserait
        // des comptes sans niveau, donc sans droits calculables.
        if (self::membersAtLevel($termId) > 0) {
            AdminUi::redirect(self::SLUG, 'Ce niveau est attribué à des membres : suppression refusée.', true);
        }

        wp_delete_term($termId, DiveLevels::TAXONOMY);
        Audit::log('dive_level.deleted', 'dive_level', $termId);

        AdminUi::redirect(self::SLUG, 'Niveau supprimé.');
    }

    private static function membersAtLevel(int $termId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'sub_dive_level_id' AND meta_value = %d",
            $termId
        ));
    }
}
