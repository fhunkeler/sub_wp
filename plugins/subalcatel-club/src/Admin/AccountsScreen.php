<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

use RuntimeException;
use Subalcatel\Club\Identity\AccountApproval;

/**
 * File des comptes à valider.
 *
 * Une file distincte de l'annuaire plutôt qu'une colonne de plus : ce sont deux
 * gestes différents. Consulter un adhérent est une consultation ; traiter une
 * demande d'entrée est une décision, et une décision veut une file qu'on vide.
 *
 * Un onglet de l'écran « Membres », en revanche, et non une entrée de menu à
 * elle seule : la demande porte sur une personne, comme le reste de cet écran.
 */
final class AccountsScreen
{
    /** Onglet de {@see MembersScreen} où vit cette file. */
    public const TAB = 'comptes';

    public static function register(): void
    {
        add_action('admin_post_sub_account_approve', [self::class, 'handleApprove']);
        add_action('admin_post_sub_account_refuse', [self::class, 'handleRefuse']);
    }

    public static function renderTab(): void
    {
        AdminUi::requireCap('sub_validate_account');

        $pending = AccountApproval::pending();
        ?>
        <p class="description">
            Un compte créé sur le site ne permet ni d’adhérer ni de s’inscrire à une sortie
            tant qu’il n’est pas validé. Validez ceux que vous reconnaissez ; refusez les
            autres en indiquant pourquoi — le motif est envoyé à la personne.
        </p>

        <?php if ($pending === []) : ?>
            <p><strong>Aucun compte en attente.</strong></p>
        <?php else : ?>
            <div class="sub-cards">
                <?php foreach ($pending as $user) : ?>
                    <?php
                    $created = (string) get_user_meta($user->ID, AccountApproval::META_SIGNUP, true);
                    $waiting = self::waitingDays($created);
                    ?>
                    <div class="sub-card">
                        <div class="sub-card__head">
                            <strong><?php echo esc_html($user->display_name); ?></strong>
                            <?php if ($waiting >= 7) : ?>
                                <span class="sub-tag sub-tag--late">
                                    <?php echo esc_html(sprintf('%d jours d’attente', $waiting)); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <p class="sub-card__meta">
                            <a href="mailto:<?php echo esc_attr($user->user_email); ?>">
                                <?php echo esc_html($user->user_email); ?>
                            </a><br>
                            <span class="description">
                                Demandé le <?php echo esc_html(
                                    $created === '' ? '—' : mysql2date('j F Y', $created)
                                ); ?>
                            </span>
                        </p>

                        <?php // Deux formulaires frères : imbriqués, le navigateur
                              // les redécoupe et le refus l'emporte sur la validation. ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="sub_account_approve">
                            <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $user->ID); ?>">
                            <?php wp_nonce_field('sub_account_approve_' . $user->ID); ?>
                            <button class="button button-primary">Valider ce compte</button>
                        </form>

                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                              class="sub-card__refuse">
                            <input type="hidden" name="action" value="sub_account_refuse">
                            <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $user->ID); ?>">
                            <?php wp_nonce_field('sub_account_refuse_' . $user->ID); ?>
                            <label for="sub-refuse-<?php echo esc_attr((string) $user->ID); ?>">
                                Motif du refus
                            </label>
                            <input type="text" id="sub-refuse-<?php echo esc_attr((string) $user->ID); ?>"
                                   name="reason" class="regular-text"
                                   placeholder="Doublon avec un compte existant…" required>
                            <button class="button button-link-delete">Refuser</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif;

        self::renderRefused();
    }

    /**
     * Comptes refusés, rappelés en bas d'écran.
     *
     * Un refus se discute parfois par téléphone quinze jours plus tard : il
     * faut pouvoir relire le motif, et revenir dessus.
     */
    private static function renderRefused(): void
    {
        $refused = get_users([
            'meta_key'   => AccountApproval::META_STATUS,
            'meta_value' => AccountApproval::STATUS_REFUSED,
            'orderby'    => 'registered',
            'order'      => 'DESC',
            'number'     => 20,
        ]);

        if ($refused === []) {
            return;
        }
        ?>
        <h2 style="margin-top:32px;">Comptes refusés</h2>
        <div class="sub-scroll">
            <table class="widefat striped">
                <thead>
                    <tr><th>Personne</th><th>Motif</th><th>Refusé le</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($refused as $user) : ?>
                    <tr>
                        <td>
                            <?php echo esc_html($user->display_name); ?><br>
                            <span class="description"><?php echo esc_html($user->user_email); ?></span>
                        </td>
                        <td><?php echo esc_html((string) get_user_meta($user->ID, AccountApproval::META_REASON, true)); ?></td>
                        <td><?php
                            $on = (string) get_user_meta($user->ID, AccountApproval::META_DATE, true);
                            echo esc_html($on === '' ? '—' : mysql2date('j M Y', $on));
                        ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="sub_account_approve">
                                <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $user->ID); ?>">
                                <?php wp_nonce_field('sub_account_approve_' . $user->ID); ?>
                                <button class="button button-small">Revenir sur ce refus</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Retour sur la file, avec son message.
     */
    private static function back(string $message, bool $isError = false): never
    {
        AdminUi::redirect(MembersScreen::SLUG, $message, $isError, ['tab' => self::TAB]);
    }

    public static function handleApprove(): void
    {
        $userId = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;

        check_admin_referer('sub_account_approve_' . $userId);

        try {
            AccountApproval::approve($userId, get_current_user_id());
            $user = get_userdata($userId);

            self::back(sprintf(
                'Compte de %s validé. La personne en est informée par courriel.',
                $user ? $user->display_name : '#' . $userId
            ));
        } catch (RuntimeException $e) {
            self::back($e->getMessage(), true);
        }
    }

    public static function handleRefuse(): void
    {
        $userId = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;

        check_admin_referer('sub_account_refuse_' . $userId);

        $reason = isset($_POST['reason'])
            ? sanitize_text_field(wp_unslash((string) $_POST['reason']))
            : '';

        try {
            AccountApproval::refuse($userId, get_current_user_id(), $reason);
            self::back('Compte refusé. Le motif a été envoyé à la personne.');
        } catch (RuntimeException $e) {
            self::back($e->getMessage(), true);
        }
    }

    private static function waitingDays(string $created): int
    {
        if ($created === '') {
            return 0;
        }

        $timestamp = strtotime($created);

        return $timestamp === false ? 0 : (int) floor((time() - $timestamp) / DAY_IN_SECONDS);
    }
}
