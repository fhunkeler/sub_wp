<?php

declare(strict_types=1);

namespace Subalcatel\Club\Privacy;

use Subalcatel\Club\Membership\ApplicationService;
use Subalcatel\Club\Support\Audit;

/**
 * Demande de suppression de compte, à l'initiative du membre.
 *
 * Le droit à l'effacement n'est pas absolu : il cède devant une obligation en
 * cours. Un adhérent qui part en janvier reste inscrit à la sortie de février
 * et son adhésion court jusqu'en août — effacer d'abord et constater ensuite
 * laisserait le club avec une place réservée pour un fantôme.
 *
 * D'où la mécanique : on énumère les obligations, on les montre au membre en
 * clair, et on ne crée la demande que si la voie est libre. Le refus n'est pas
 * un mur, c'est une liste de choses à solder.
 */
final class AccountDeletion
{
    public const ACTION = 'sub_account_deletion';

    public static function register(): void
    {
        add_action('admin_post_' . self::ACTION, [self::class, 'handleRequest']);
    }

    /**
     * Ce qui empêche encore l'effacement.
     *
     * Le filtre `subalcatel_deletion_blockers` laisse la porte ouverte au module
     * matériel de la phase 8 : un tuba non rendu est exactement le même genre
     * d'obligation, et il n'aura rien à modifier ici pour le signaler.
     *
     * Chaque obligation se dit deux fois, comme partout ailleurs dans le
     * plugin : `detail` s'adresse au membre, à la deuxième personne ; `office`
     * s'adresse au bureau, qui lit la situation de quelqu'un d'autre et pour
     * qui « votre adhésion » ne veut rien dire.
     *
     * @return list<array{label: string, detail: string, office: string}>
     */
    public static function blockers(int $userId): array
    {
        global $wpdb;

        $blockers = [];
        $today    = current_time('Y-m-d');

        // 1. Adhésion encore valable. Le membre a payé sa saison ; l'effacer
        //    reviendrait à lui retirer ce qu'il a acheté.
        $membership = $wpdb->get_row($wpdb->prepare(
            "SELECT reference, valid_until FROM {$wpdb->prefix}sub_applications
             WHERE user_id = %d AND status = %s AND valid_until >= %s
             ORDER BY valid_until DESC LIMIT 1",
            $userId,
            ApplicationService::STATUS_ACTIVE,
            $today
        ), ARRAY_A);

        if ($membership) {
            $blockers[] = [
                'label'  => 'Adhésion en cours',
                'detail' => sprintf(
                    'Votre adhésion %s est valable jusqu’au %s. La suppression sera possible '
                    . 'ensuite, ou tout de suite si vous demandez au bureau d’y mettre fin.',
                    $membership['reference'],
                    self::formatDate((string) $membership['valid_until'])
                ),
                'office' => sprintf(
                    'Adhésion %s valable jusqu’au %s. Clôturez-la avant d’effacer le compte.',
                    $membership['reference'],
                    self::formatDate((string) $membership['valid_until'])
                ),
            ];
        }

        // 2. Dossier en cours d'instruction : le bureau attend un règlement ou
        //    une pièce. Partir en silence laisserait une ligne orpheline.
        $pending = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sub_applications
             WHERE user_id = %d AND status IN (%s, %s, %s)",
            $userId,
            ApplicationService::STATUS_SUBMITTED,
            ApplicationService::STATUS_AWAITING_PAYMENT,
            ApplicationService::STATUS_PAYMENT_CONFIRMED
        ));

        if ($pending > 0) {
            $blockers[] = [
                'label'  => 'Demande d’adhésion en cours',
                'detail' => 'Le bureau instruit encore votre dossier. Demandez-lui de l’annuler '
                    . 'avant de supprimer votre compte.',
                'office' => sprintf(
                    '%d dossier(s) en instruction. Annulez-les ou finissez de les traiter.',
                    $pending
                ),
            ];
        }

        // 3. Sorties à venir : une place réservée qu'il faut rendre.
        $registrations = $wpdb->get_results($wpdb->prepare(
            "SELECT e.title, e.starts_at FROM {$wpdb->prefix}sub_event_registrations r
             INNER JOIN {$wpdb->prefix}sub_events e ON e.id = r.event_id
             WHERE r.user_id = %d AND r.status IN ('confirmed','waiting') AND e.starts_at >= %s
             ORDER BY e.starts_at ASC",
            $userId,
            current_time('mysql')
        ), ARRAY_A) ?: [];

        if ($registrations !== []) {
            $blockers[] = [
                'label'  => 'Inscription(s) à venir',
                'detail' => sprintf(
                    'Vous êtes inscrit à %d sortie(s), dont « %s » le %s. Désinscrivez-vous '
                    . 'depuis « Mes inscriptions » : la place profitera à quelqu’un d’autre.',
                    count($registrations),
                    $registrations[0]['title'],
                    self::formatDate(substr((string) $registrations[0]['starts_at'], 0, 10))
                ),
                'office' => sprintf(
                    '%d inscription(s) à venir, dont « %s » le %s. Désinscrivez le membre '
                    . 'pour libérer la place.',
                    count($registrations),
                    $registrations[0]['title'],
                    self::formatDate(substr((string) $registrations[0]['starts_at'], 0, 10))
                ),
            ];
        }

        /** @var list<array{label: string, detail: string}> */
        return apply_filters('subalcatel_deletion_blockers', $blockers, $userId);
    }

    /**
     * Le bloc affiché en bas du profil.
     *
     * Hors du formulaire de profil, jamais dedans : deux formulaires imbriqués
     * ne sont pas du HTML valide, et le navigateur les redécoupe à sa façon.
     */
    public static function renderPanel(int $userId): string
    {
        $blockers = self::blockers($userId);
        $pendingRequest = self::pendingRequest($userId);

        ob_start();
        ?>
        <section class="sub-danger-zone">
            <h2>Supprimer mon compte</h2>
            <p class="sub-help">
                Vous pouvez demander l’effacement de vos données. Vos documents personnels
                et votre profil seront supprimés. Vos règlements resteront dans la
                comptabilité du club, sans usage autre que la tenue des comptes : la loi
                impose de les conserver dix ans.
            </p>

            <?php if ($pendingRequest !== null) : ?>
                <div class="sub-notice sub-notice--info">
                    <p>
                        <strong>Demande enregistrée le <?php echo esc_html(self::formatDate($pendingRequest)); ?>.</strong>
                        Elle sera traitée par le bureau. Vous recevrez un courriel de confirmation.
                    </p>
                </div>
            <?php elseif ($blockers !== []) : ?>
                <div class="sub-notice sub-notice--warning">
                    <p><strong>La suppression n’est pas possible pour l’instant :</strong></p>
                    <ul>
                        <?php foreach ($blockers as $blocker) : ?>
                            <li>
                                <strong><?php echo esc_html($blocker['label']); ?></strong> —
                                <?php echo esc_html($blocker['detail']); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                      onsubmit="return confirm('Confirmer la demande de suppression de votre compte ?');">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                    <?php wp_nonce_field(self::ACTION . '_' . $userId); ?>
                    <p>
                        <button type="submit" class="sub-button sub-button--danger">
                            Demander la suppression de mon compte
                        </button>
                    </p>
                </form>
            <?php endif; ?>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    public static function handleRequest(): void
    {
        $userId = get_current_user_id();

        if ($userId === 0) {
            wp_die('Connectez-vous pour effectuer cette demande.');
        }

        check_admin_referer(self::ACTION . '_' . $userId);

        $user = get_userdata($userId);
        $back = wp_get_referer() ?: home_url('/');

        // Rejouée depuis un onglet resté ouvert, la demande retomberait sur une
        // situation changée : on revérifie au moment d'agir, pas à l'affichage.
        if (self::blockers($userId) !== []) {
            wp_safe_redirect(add_query_arg(
                'sub_error',
                rawurlencode('Votre situation a changé : certaines obligations restent à solder.'),
                $back
            ));
            exit;
        }

        $requestId = wp_create_user_request($user->user_email, 'remove_personal_data');

        if (is_wp_error($requestId)) {
            wp_safe_redirect(add_query_arg(
                'sub_error',
                rawurlencode('Demande impossible : ' . $requestId->get_error_message()),
                $back
            ));
            exit;
        }

        wp_send_user_request($requestId);

        Audit::log('privacy.deletion_requested', 'user', $userId, ['request_id' => $requestId], $userId);

        wp_safe_redirect(add_query_arg(
            'sub_done',
            rawurlencode(
                'Demande enregistrée. Un courriel vient de vous être envoyé : cliquez sur le '
                . 'lien qu’il contient pour la confirmer.'
            ),
            $back
        ));
        exit;
    }

    /**
     * Date de la demande en cours, ou null.
     *
     * WordPress range ces demandes dans un type de contenu dédié. Inutile de
     * tenir une table de plus : elle divergerait au premier traitement fait
     * depuis les écrans natifs.
     */
    public static function pendingRequest(int $userId): ?string
    {
        $user = get_userdata($userId);

        if (!$user) {
            return null;
        }

        $requests = get_posts([
            'post_type'      => 'user_request',
            'post_name__in'  => ['remove_personal_data'],
            'title'          => $user->user_email,
            'post_status'    => ['request-pending', 'request-confirmed'],
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);

        if ($requests === []) {
            return null;
        }

        return get_post_field('post_date', (int) $requests[0]) ?: null;
    }

    private static function formatDate(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed === false ? $date : wp_date('j F Y', $parsed->getTimestamp());
    }
}
