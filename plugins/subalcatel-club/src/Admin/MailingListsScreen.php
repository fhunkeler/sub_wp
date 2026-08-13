<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

use RuntimeException;
use Subalcatel\Club\Communication\CustomGroups;
use Subalcatel\Club\Communication\MailingLists;
use Subalcatel\Club\Exports\ExportRegistry;

/**
 * Écran « Listes de diffusion ».
 *
 * Le bureau y voit la composition de chaque liste, l'écart entre effectif et
 * abonnés, et en sort la liste des destinataires pour l'outil d'envoi. Il y
 * constitue aussi ses groupes à la main.
 */
final class MailingListsScreen
{
    /** Onglet de {@see CommunicationScreen} où vivent les listes. */
    public const TAB = 'listes';

    /** Page hôte, pour les retours de formulaire. */
    private const PAGE = CommunicationScreen::SLUG;

    public static function register(): void
    {
        add_action('admin_post_sub_group_save', [self::class, 'handleSave']);
        add_action('admin_post_sub_group_delete', [self::class, 'handleDelete']);
        add_action('admin_post_sub_group_members', [self::class, 'handleMembers']);
        add_action('admin_post_sub_list_export', [self::class, 'handleExport']);
    }

    public static function renderTab(): void
    {
        if (!current_user_can('sub_manage_content')) {
            wp_die('Droit requis.');
        }

        $editing = isset($_GET['groupe']) ? sanitize_key(wp_unslash($_GET['groupe'])) : '';

        if ($editing !== '') {
            self::renderGroupEditor($editing);

            return;
        }

        self::renderLists();
        self::renderGroupForm();
    }

    private static function renderLists(): void
    {
        ?>
        <p class="description">
            Les listes se recalculent à chaque consultation : personne n’a à les tenir à jour.
            La colonne <strong>abonnés</strong> ne compte que les membres ayant accepté de
            recevoir la lettre d’information — être adhérent ne vaut pas consentement.
        </p>

        <div class="sub-scroll">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Liste</th>
                        <th>Composition</th>
                        <th style="text-align:right">Membres</th>
                        <th style="text-align:right">Abonnés</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (MailingLists::all() as $list) : ?>
                    <?php $count = MailingLists::count($list['slug']); ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($list['label']); ?></strong>
                            <?php if (!$list['dynamic']) : ?>
                                <span class="sub-tag">manuel</span>
                            <?php endif; ?>
                        </td>
                        <td class="description"><?php echo esc_html($list['description']); ?></td>
                        <td style="text-align:right"><?php echo (int) $count['members']; ?></td>
                        <td style="text-align:right">
                            <?php echo (int) $count['subscribed']; ?>
                            <?php if ($count['members'] > 0 && $count['subscribed'] < $count['members']) : ?>
                                <span class="description">
                                    (<?php echo (int) ($count['members'] - $count['subscribed']); ?> non abonné<?php
                                        echo $count['members'] - $count['subscribed'] > 1 ? 's' : ''; ?>)
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a class="button button-small"
                               href="<?php echo esc_url(wp_nonce_url(add_query_arg([
                                   'action' => 'sub_list_export',
                                   'liste'  => $list['slug'],
                               ], admin_url('admin-post.php')), 'sub_list_export_' . $list['slug'])); ?>">
                                Exporter
                            </a>
                            <?php if (!$list['dynamic']) : ?>
                                <a class="button button-small"
                                   href="<?php echo esc_url(add_query_arg([
                                       'page'   => self::PAGE,
                                       'tab'    => self::TAB,
                                       'groupe' => substr($list['slug'], strlen(MailingLists::GROUP_PREFIX)),
                                   ], admin_url('admin.php'))); ?>">
                                    Composer
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function renderGroupForm(): void
    {
        ?>
        <h2>Créer un groupe</h2>
        <p class="description">
            Pour ce qu’aucune règle ne sait calculer : commission bio, équipe compresseur,
            encadrants d’un cursus.
        </p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="sub_group_save">
            <?php wp_nonce_field('sub_group_save'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="sub-group-label">Nom</label></th>
                    <td><input type="text" id="sub-group-label" name="label" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="sub-group-desc">Description</label></th>
                    <td><input type="text" id="sub-group-desc" name="description" class="regular-text"></td>
                </tr>
            </table>
            <p><button type="submit" class="button button-primary">Créer le groupe</button></p>
        </form>
        <?php
    }

    private static function renderGroupEditor(string $slug): void
    {
        $group = CustomGroups::find($slug);

        if ($group === null) {
            echo '<div class="notice notice-error"><p>Groupe introuvable.</p></div>';

            return;
        }

        $current = CustomGroups::members($slug);
        $users   = get_users(['orderby' => 'display_name', 'number' => 500]);
        ?>
        <h2><?php echo esc_html((string) $group['label']); ?></h2>
        <p>
            <a href="<?php echo esc_url(AdminUi::tabUrl(self::PAGE, self::TAB)); ?>">
                ← Toutes les listes
            </a>
        </p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="sub_group_members">
            <input type="hidden" name="group_id" value="<?php echo esc_attr((string) $group['id']); ?>">
            <?php wp_nonce_field('sub_group_members_' . $group['id']); ?>

            <div class="sub-cards">
                <?php foreach ($users as $user) : ?>
                    <label class="sub-check">
                        <input type="checkbox" name="members[]" value="<?php echo esc_attr((string) $user->ID); ?>"
                            <?php checked(in_array($user->ID, $current, true)); ?>>
                        <?php echo esc_html($user->display_name); ?>
                        <span class="description"><?php echo esc_html($user->user_email); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <p><button type="submit" class="button button-primary">Enregistrer la composition</button></p>
        </form>

        <?php // Formulaire frère : imbriquer les deux ferait supprimer au lieu d'enregistrer. ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              onsubmit="return confirm('Supprimer définitivement ce groupe ?');">
            <input type="hidden" name="action" value="sub_group_delete">
            <input type="hidden" name="group_id" value="<?php echo esc_attr((string) $group['id']); ?>">
            <?php wp_nonce_field('sub_group_delete_' . $group['id']); ?>
            <p><button type="submit" class="button button-link-delete">Supprimer ce groupe</button></p>
        </form>
        <?php
    }

    public static function handleSave(): void
    {
        check_admin_referer('sub_group_save');
        self::guard();

        try {
            CustomGroups::create(
                sanitize_text_field(wp_unslash((string) ($_POST['label'] ?? ''))),
                sanitize_text_field(wp_unslash((string) ($_POST['description'] ?? '')))
            );
            self::back('Groupe créé.');
        } catch (RuntimeException $e) {
            self::back($e->getMessage(), true);
        }
    }

    public static function handleMembers(): void
    {
        $groupId = isset($_POST['group_id']) ? absint($_POST['group_id']) : 0;

        check_admin_referer('sub_group_members_' . $groupId);
        self::guard();

        $members = array_map('absint', (array) ($_POST['members'] ?? []));
        CustomGroups::setMembers($groupId, $members);

        self::back(sprintf('Composition enregistrée : %d membre(s).', count($members)));
    }

    public static function handleDelete(): void
    {
        $groupId = isset($_POST['group_id']) ? absint($_POST['group_id']) : 0;

        check_admin_referer('sub_group_delete_' . $groupId);
        self::guard();

        CustomGroups::delete($groupId);

        self::back('Groupe supprimé.');
    }

    /**
     * Sort les destinataires d'une liste.
     *
     * Passe par le registre d'exports plutôt que d'écrire un second circuit :
     * il apporte le contrôle de capacité, la journalisation CNIL et le format
     * XLSX, tous déjà éprouvés.
     */
    public static function handleExport(): void
    {
        $slug = isset($_GET['liste']) ? sanitize_text_field(wp_unslash($_GET['liste'])) : '';

        check_admin_referer('sub_list_export_' . $slug);
        self::guard();

        if (MailingLists::find($slug) === null) {
            self::back('Liste inconnue.', true);
        }

        $format = isset($_GET['format']) ? sanitize_key(wp_unslash($_GET['format'])) : 'csv';

        ExportRegistry::stream('mailing-list', $format, ['list' => $slug]);
    }

    private static function guard(): void
    {
        if (!current_user_can('sub_manage_content')) {
            wp_die('Droit requis.', 403);
        }
    }

    private static function back(string $message, bool $isError = false): never
    {
        AdminUi::redirect(self::PAGE, $message, $isError, ['tab' => self::TAB]);
    }
}
