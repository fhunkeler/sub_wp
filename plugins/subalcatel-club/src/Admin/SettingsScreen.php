<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

use Subalcatel\Club\Content\Visibility;
use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Frontend\Pages;
use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Setup\SiteBuilder;
use Subalcatel\Club\Setup\SiteMap;
use Subalcatel\Club\Support\Audit;
use Subalcatel\Club\Support\SecuritySettings;

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
        add_action('admin_post_sub_security_save', [self::class, 'handleSecuritySave']);
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
            'security'                   => [
                'label'  => 'Sécurité',
                // Réservé aux administrateurs : régler la protection des
                // connexions n'est pas une tâche de gestion courante du bureau.
                'cap'    => 'manage_options',
                'render' => [self::class, 'renderSecurity'],
            ],
            'audit'                      => [
                'label'  => 'Journal',
                'cap'    => 'sub_manage_event_types',
                'render' => [self::class, 'renderAudit'],
            ],
            UpdatesScreen::TAB           => [
                'label'  => 'Mises à jour',
                // Même raison que « Sécurité » : poser un jeton dans
                // wp-config.php n'est pas une tâche de gestion du bureau.
                'cap'    => 'manage_options',
                'render' => [UpdatesScreen::class, 'renderTab'],
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
                                'button button-link-delete',
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
                    <span class="sub-tag"><?php echo esc_html(
                        EventService::VISIBILITY_LABELS[
                            EventService::normalizeVisibility((string) ($type['visibility'] ?? ''))
                        ]
                    ); ?></span>
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
                    <th scope="row">À qui il s’annonce</th>
                    <td>
                        <select name="visibility">
                            <?php foreach (EventService::VISIBILITY_LABELS as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>"
                                        <?php selected(
                                            EventService::normalizeVisibility((string) ($type['visibility'] ?? '')),
                                            $value
                                        ); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            Qui voit l’événement dans l’agenda, et qui en reçoit l’annonce. Une
                            réunion du bureau ne concerne que lui ; une sortie ne concerne que
                            les niveaux qu’elle accepte — et tout le monde si elle n’en exige
                            aucun. L’événement copie ce réglage à sa création : le changer ici
                            ne rouvre pas ce qui a déjà été annoncé.
                        </p>
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
                'button button-link-delete',
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

    // -------------------------------------------------------------- Sécurité

    public static function renderSecurity(): void
    {
        AdminUi::requireCap('manage_options');

        $s = SecuritySettings::all();
        ?>
        <h2>Politique de mot de passe</h2>
        <p class="description">
            Ces règles s’appliquent partout où un mot de passe est choisi : inscription,
            changement depuis l’espace membre, « mot de passe oublié » et profil de
            l’administration.
        </p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="sub_security_save">
            <?php wp_nonce_field('sub_security_save'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="sub_pw_min">Longueur minimale</label></th>
                    <td>
                        <input type="number" id="sub_pw_min" name="password_min_length" min="8" max="64"
                               value="<?php echo esc_attr((string) $s['password_min_length']); ?>" class="small-text">
                        caractères
                        <p class="description">Entre 8 et 64. Une longue phrase se retient et résiste mieux qu’un mot compliqué.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Refus des mots de passe faibles</th>
                    <td>
                        <label>
                            <input type="checkbox" name="password_similarity" value="1"
                                <?php checked($s['password_similarity']); ?>>
                            Refuser un mot de passe trop proche de l’identifiant, de l’e-mail ou du nom
                        </label>
                        <p class="description">Ferme le cas « identifiant = mot de passe », qu’aucun ralentisseur n’arrête.</p>
                        <label>
                            <input type="checkbox" name="password_breach_check" value="1"
                                <?php checked($s['password_breach_check']); ?>>
                            Refuser un mot de passe présent dans une fuite connue
                        </label>
                        <p class="description">
                            Vérifié auprès du service « Have I Been Pwned » en k-anonymité : le mot de passe
                            ne quitte jamais le serveur, seuls cinq caractères de son empreinte sont envoyés.
                            Sans effet si le service est injoignable.
                        </p>
                    </td>
                </tr>
            </table>

            <h2>Ralentisseur de connexion</h2>
            <p class="description">
                Contre la devinette de mot de passe. Ce n’est pas un pare-feu : un vrai WAF
                reste préférable en production, mais ceci ferme la porte grande ouverte.
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Activation</th>
                    <td>
                        <label>
                            <input type="checkbox" name="throttle_enabled" value="1"
                                <?php checked($s['throttle_enabled']); ?>>
                            Ralentir les tentatives de connexion répétées
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sub_thr_user">Seuil par compte</label></th>
                    <td>
                        <input type="number" id="sub_thr_user" name="throttle_max_attempts" min="3" max="100"
                               value="<?php echo esc_attr((string) $s['throttle_max_attempts']); ?>" class="small-text">
                        échecs pour un même couple identifiant + adresse IP
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sub_thr_ip">Seuil par adresse IP</label></th>
                    <td>
                        <input type="number" id="sub_thr_ip" name="throttle_max_ip_attempts" min="3" max="100"
                               value="<?php echo esc_attr((string) $s['throttle_max_ip_attempts']); ?>" class="small-text">
                        échecs depuis une même IP, tous identifiants confondus
                        <p class="description">
                            Attrape le balayage d’un mot de passe sur beaucoup de comptes. Ramené au seuil
                            par compte s’il est saisi plus bas. Prévoir de la marge pour un réseau partagé.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sub_thr_win">Durée de la fenêtre</label></th>
                    <td>
                        <input type="number" id="sub_thr_win" name="throttle_window_minutes" min="1" max="1440"
                               value="<?php echo esc_attr((string) $s['throttle_window_minutes']); ?>" class="small-text">
                        minutes
                        <p class="description">Sert à la fois de fenêtre de comptage et de durée du blocage.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sub_thr_allow">Adresses IP de confiance</label></th>
                    <td>
                        <textarea id="sub_thr_allow" name="throttle_ip_allowlist" rows="4" class="large-text code"
                                  placeholder="203.0.113.10&#10;192.168.1.0/24&#10;2001:db8::/32"><?php
                            echo esc_textarea(implode("\n", $s['throttle_ip_allowlist']));
                        ?></textarea>
                        <p class="description">
                            Une adresse ou un bloc CIDR par ligne. Ces origines (local du club, VPN du bureau…)
                            ne sont jamais ralenties. Les entrées invalides sont ignorées à l’enregistrement.
                            Votre adresse actuelle : <code><?php echo esc_html(self::currentIp()); ?></code>.
                        </p>
                    </td>
                </tr>
            </table>

            <p>
                <button type="submit" class="button button-primary">Enregistrer les réglages</button>
            </p>
        </form>
        <?php
    }

    public static function handleSecuritySave(): void
    {
        check_admin_referer('sub_security_save');
        AdminUi::requireCap('manage_options');

        SecuritySettings::save([
            'password_min_length'      => $_POST['password_min_length'] ?? 12,
            'password_similarity'      => $_POST['password_similarity'] ?? '',
            'password_breach_check'    => $_POST['password_breach_check'] ?? '',
            'throttle_enabled'         => $_POST['throttle_enabled'] ?? '',
            'throttle_max_attempts'    => $_POST['throttle_max_attempts'] ?? 8,
            'throttle_max_ip_attempts' => $_POST['throttle_max_ip_attempts'] ?? 30,
            'throttle_window_minutes'  => $_POST['throttle_window_minutes'] ?? 15,
            'throttle_ip_allowlist'    => wp_unslash((string) ($_POST['throttle_ip_allowlist'] ?? '')),
        ]);

        Audit::log('security.settings_saved', 'auth', null, [
            'ip_de_confiance' => count(SecuritySettings::ipAllowlist()),
        ]);

        AdminUi::redirect(self::SLUG, 'Réglages de sécurité enregistrés.', false, ['tab' => 'security']);
    }

    /**
     * Adresse réelle de la connexion en cours, pour aider à remplir la liste de
     * confiance sans se tromper d'IP.
     */
    private static function currentIp(): string
    {
        $raw = $_SERVER['REMOTE_ADDR'] ?? '';

        return is_string($raw) && filter_var($raw, FILTER_VALIDATE_IP) ? $raw : 'inconnue';
    }

    /** Catégories du journal : libellés lisibles, repli sur la valeur brute. */
    private const AUDIT_CATEGORIES = [
        'auth'        => 'Connexions & sécurité',
        'user'        => 'Comptes',
        'membership'  => 'Adhésions',
        'event'       => 'Événements',
        'document'    => 'Documents',
        'campaign'    => 'Campagnes',
    ];

    public static function renderAudit(): void
    {
        $q       = sanitize_text_field(wp_unslash((string) ($_GET['audit_q'] ?? '')));
        $entity  = sanitize_key(wp_unslash((string) ($_GET['audit_type'] ?? '')));
        $perPage = (int) ($_GET['audit_per'] ?? 50);
        $page    = max(1, (int) ($_GET['audit_page'] ?? 1));

        $result = Audit::search([
            'q'           => $q,
            'entity_type' => $entity,
            'per_page'    => $perPage,
            'page'        => $page,
        ]);

        $entries = $result['rows'];
        $baseArgs = ['page' => self::SLUG, 'tab' => 'audit'];
        ?>
        <p class="description">
            Trace des actions sensibles — connexions, adhésions, documents, réglages.
            Ce journal ne peut pas être modifié : c'est ce qui le rend opposable.
        </p>

        <form method="get" style="margin:12px 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
            <input type="hidden" name="tab" value="audit">
            <input type="search" name="audit_q" value="<?php echo esc_attr($q); ?>"
                   class="regular-text" placeholder="Identifiant, action, adresse IP…">
            <select name="audit_type">
                <option value="">Toutes les catégories</option>
                <?php foreach (Audit::entityTypes() as $type) : ?>
                    <option value="<?php echo esc_attr($type); ?>" <?php selected($entity, $type); ?>>
                        <?php echo esc_html(self::AUDIT_CATEGORIES[$type] ?? $type); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="audit_per">
                <?php foreach ([50, 100, 200, 500] as $n) : ?>
                    <option value="<?php echo (int) $n; ?>" <?php selected($result['per_page'], $n); ?>>
                        <?php echo (int) $n; ?> par page
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button">Rechercher</button>
            <?php if ($q !== '' || $entity !== '') : ?>
                <a class="button-link" href="<?php echo esc_url(add_query_arg($baseArgs, admin_url('admin.php'))); ?>">
                    Réinitialiser
                </a>
            <?php endif; ?>
        </form>

        <p class="description">
            <strong><?php echo (int) $result['total']; ?></strong> entrée(s)<?php
                echo ($q !== '' || $entity !== '') ? ' correspondant au filtre' : '';
            ?> — page <?php echo (int) $result['page']; ?> sur <?php echo (int) $result['pages']; ?>.
        </p>

        <div class="sub-scroll">
        <table class="wp-list-table widefat striped" style="min-width:900px;">
            <thead>
                <tr>
                    <th style="width:150px;">Quand</th>
                    <th style="width:160px;">Qui</th>
                    <th style="width:190px;">Action</th>
                    <th style="width:120px;">Objet</th>
                    <th style="width:130px;">Adresse IP</th>
                    <th>Détails</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($entries === []) : ?>
                <tr><td colspan="6"><?php echo $q !== '' || $entity !== ''
                    ? 'Aucune entrée ne correspond à cette recherche.'
                    : 'Journal vide.'; ?></td></tr>
            <?php endif; ?>

            <?php foreach ($entries as $entry) : ?>
                <?php // `get_userdata` rend `false` sur un compte supprimé, et `?->`
                      // ne court-circuite que sur `null` : sans ce `?: null`, une
                      // entrée dont l'auteur a été supprimé émet un avertissement. ?>
                <?php $user = $entry['user_id'] ? get_userdata((int) $entry['user_id']) ?: null : null; ?>
                <tr>
                    <td><?php echo AdminUi::localTime($entry['created_at']); ?></td>
                    <td><?php echo esc_html($user?->display_name ?: ($entry['user_id'] ? '#' . (int) $entry['user_id'] : '—')); ?></td>
                    <td><code><?php echo esc_html((string) $entry['action']); ?></code></td>
                    <td>
                        <?php echo esc_html((string) $entry['entity_type']); ?>
                        <?php echo $entry['entity_id'] ? '#' . (int) $entry['entity_id'] : ''; ?>
                    </td>
                    <td style="font-variant-numeric:tabular-nums;font-size:12px;">
                        <?php echo esc_html((string) ($entry['ip_address'] ?? '') ?: '—'); ?>
                    </td>
                    <td style="color:#50575e;font-size:12px;">
                        <?php echo esc_html((string) ($entry['details'] ?? '')); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <?php if ($result['pages'] > 1) : ?>
            <?php
            $current  = (int) $result['page'];
            $filter   = array_filter([
                'audit_q'    => $q,
                'audit_type' => $entity,
                'audit_per'  => $result['per_page'],
            ], static fn ($v): bool => $v !== '' && $v !== null);
            $pageUrl  = static fn (int $n): string => esc_url(add_query_arg(
                array_merge($baseArgs, $filter, ['audit_page' => $n]),
                admin_url('admin.php')
            ));
            ?>
            <div class="tablenav" style="margin-top:12px;">
                <div class="tablenav-pages">
                    <?php if ($current > 1) : ?>
                        <a class="button" href="<?php echo $pageUrl(1); ?>">« Début</a>
                        <a class="button" href="<?php echo $pageUrl($current - 1); ?>">‹ Précédent</a>
                    <?php endif; ?>
                    <span style="margin:0 8px;">Page <?php echo $current; ?> / <?php echo (int) $result['pages']; ?></span>
                    <?php if ($current < (int) $result['pages']) : ?>
                        <a class="button" href="<?php echo $pageUrl($current + 1); ?>">Suivant ›</a>
                        <a class="button" href="<?php echo $pageUrl((int) $result['pages']); ?>">Fin »</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
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
            'visibility'           => EventService::normalizeVisibility(
                sanitize_key(wp_unslash((string) ($_POST['visibility'] ?? '')))
            ),
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
