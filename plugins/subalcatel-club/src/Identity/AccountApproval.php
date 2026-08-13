<?php

declare(strict_types=1);

namespace Subalcatel\Club\Identity;

use RuntimeException;
use Subalcatel\Club\Notifications\EmailTemplates;
use Subalcatel\Club\Notifications\Mailer;
use Subalcatel\Club\Support\Audit;

/**
 * Validation des comptes par le bureau.
 *
 * N'importe qui peut créer un compte : c'est ce qui permet à un futur adhérent
 * de démarrer son dossier un dimanche soir. Mais **créer un compte n'est pas
 * entrer au club**. Le bureau vérifie qui demande à entrer avant d'ouvrir
 * l'adhésion et les sorties.
 *
 * L'état vit dans le **rôle**, pas seulement dans une méta : un compte en
 * attente est `sub_guest`, un compte validé est `sub_member`. Deux raisons —
 * les capacités WordPress suivent automatiquement, et un rôle se lit d'un coup
 * d'œil dans l'écran Comptes, y compris par quelqu'un qui ne connaît pas ce
 * plugin. Les métas ne portent que la traçabilité : qui, quand, pourquoi.
 */
final class AccountApproval
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REFUSED  = 'refused';

    public const META_STATUS   = 'sub_account_status';
    public const META_DATE     = 'sub_account_reviewed_on';
    public const META_ACTOR    = 'sub_account_reviewed_by';
    public const META_REASON   = 'sub_account_refusal_reason';
    public const META_SIGNUP   = 'sub_account_created_on';

    /**
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING  => 'En attente de validation',
            self::STATUS_APPROVED => 'Validé',
            self::STATUS_REFUSED  => 'Refusé',
        ];
    }

    /**
     * État d'un compte.
     *
     * Un compte créé avant la mise en place de ce circuit — ou par
     * l'administrateur depuis WordPress — est considéré comme validé : il
     * serait absurde de bloquer les 330 adhérents repris du Joomla en attente
     * d'une validation que personne n'a demandée.
     */
    public static function statusOf(int $userId): string
    {
        $stored = (string) get_user_meta($userId, self::META_STATUS, true);

        return array_key_exists($stored, self::statuses()) ? $stored : self::STATUS_APPROVED;
    }

    public static function isPending(int $userId): bool
    {
        return self::statusOf($userId) === self::STATUS_PENDING;
    }

    public static function isRefused(int $userId): bool
    {
        return self::statusOf($userId) === self::STATUS_REFUSED;
    }

    /**
     * Marque un compte comme en attente. Appelé à la création.
     */
    public static function markPending(int $userId): void
    {
        update_user_meta($userId, self::META_STATUS, self::STATUS_PENDING);
        update_user_meta($userId, self::META_SIGNUP, current_time('mysql'));

        $user = get_userdata($userId);
        $user?->set_role(Roles::GUEST);

        Audit::log('account.created', 'user', $userId, [], $userId);
    }

    /**
     * Le bureau accepte : le compte devient membre.
     */
    public static function approve(int $userId, int $actorId): void
    {
        self::guard($actorId);

        if (self::statusOf($userId) === self::STATUS_APPROVED) {
            return;
        }

        $user = get_userdata($userId);

        if (!$user) {
            throw new RuntimeException('Compte introuvable.');
        }

        // On ne rétrograde jamais : quelqu'un promu au bureau entre-temps
        // garderait son rôle. Seul un invité devient membre.
        if (in_array(Roles::GUEST, $user->roles, true)) {
            $user->set_role(Roles::MEMBER);
        }

        self::record($userId, self::STATUS_APPROVED, $actorId, '');

        Mailer::toUser(EmailTemplates::ACCOUNT_APPROVED, $userId, [
            'prenom' => $user->first_name !== '' ? $user->first_name : $user->display_name,
        ]);

        Audit::log('account.approved', 'user', $userId, [], $actorId);
    }

    /**
     * Le bureau refuse.
     *
     * Le compte n'est pas supprimé : la personne peut avoir déposé des
     * documents, et une suppression silencieuse empêcherait toute explication.
     * Il est bloqué et le motif est conservé — c'est ce que le bureau devra
     * pouvoir relire si l'intéressé rappelle.
     */
    public static function refuse(int $userId, int $actorId, string $reason): void
    {
        self::guard($actorId);

        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Un refus doit être motivé.');
        }

        $user = get_userdata($userId);

        if (!$user) {
            throw new RuntimeException('Compte introuvable.');
        }

        $user->set_role(Roles::GUEST);
        self::record($userId, self::STATUS_REFUSED, $actorId, $reason);

        Mailer::toUser(EmailTemplates::ACCOUNT_REFUSED, $userId, [
            'prenom' => $user->first_name !== '' ? $user->first_name : $user->display_name,
            'motif'  => $reason,
        ]);

        Audit::log('account.refused', 'user', $userId, ['reason' => $reason], $actorId);
    }

    /**
     * Comptes en attente, du plus ancien au plus récent.
     *
     * Le plus ancien d'abord : c'est celui qui attend depuis le plus longtemps,
     * et une file d'attente qui se traite dans le désordre laisse toujours
     * quelqu'un au fond.
     *
     * @return list<\WP_User>
     */
    public static function pending(): array
    {
        $users = get_users([
            'meta_key'   => self::META_STATUS,
            'meta_value' => self::STATUS_PENDING,
            'orderby'    => 'registered',
            'order'      => 'ASC',
        ]);

        return array_values($users);
    }

    public static function pendingCount(): int
    {
        return count(self::pending());
    }

    private static function record(int $userId, string $status, int $actorId, string $reason): void
    {
        update_user_meta($userId, self::META_STATUS, $status);
        update_user_meta($userId, self::META_DATE, current_time('mysql'));
        update_user_meta($userId, self::META_ACTOR, $actorId);

        if ($reason === '') {
            delete_user_meta($userId, self::META_REASON);
        } else {
            update_user_meta($userId, self::META_REASON, $reason);
        }
    }

    private static function guard(int $actorId): void
    {
        if (!user_can($actorId, 'sub_validate_account')) {
            throw new RuntimeException('Droit de validation des comptes requis.');
        }
    }
}
