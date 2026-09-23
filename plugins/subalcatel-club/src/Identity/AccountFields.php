<?php

declare(strict_types=1);

namespace Subalcatel\Club\Identity;

use Subalcatel\Club\Support\Audit;
use WP_User;

/**
 * Le compte WordPress d'un membre : courriel, nom, rôle, mot de passe.
 *
 * Ces quatre champs vivaient jusqu'ici dans `wp-admin/users.php`, à part du
 * reste du dossier. Un membre du bureau devait donc connaître deux écrans, et
 * celui de WordPress offre au passage tout ce qu'on ne veut pas lui mettre sous
 * la main : suppression de compte, promotion en administrateur, mot de passe
 * fixé pour autrui. D'où ce module — les règles — et le panneau « Compte » de
 * la fiche membre, qui est désormais le **seul** endroit où l'on modifie un
 * utilisateur.
 *
 * Les règles sont ici et non dans l'écran, pour deux raisons : elles se
 * vérifient sans navigateur, et elles ne bougeront pas si l'écran change.
 *
 * Trois garde-fous, chacun pour une manière connue de perdre un site :
 *
 * 1. **Aucun compte hors club n'est modifiable** ([Roles::isClubAccount]).
 *    Sans cela, un membre du bureau pourrait mettre son adresse sur le compte
 *    de l'administrateur technique, demander un mot de passe oublié, et prendre
 *    la main sur l'ensemble du site.
 * 2. **`administrator` n'est pas attribuable** : la liste des rôles est fermée
 *    ([Roles::assignable]). Promouvoir un administrateur reste un geste
 *    d'administration WordPress, fait par quelqu'un qui y a déjà accès.
 * 3. **Personne ne change son propre rôle.** Se retirer du bureau par erreur
 *    ferme la porte derrière soi.
 *
 * Et une règle de plus, qui n'est pas un garde-fou mais un principe : le
 * mot de passe ne se **fixe** jamais pour quelqu'un d'autre. On envoie un lien
 * de réinitialisation — même mécanique que celle qui a servi à toute la reprise
 * Joomla, et la seule qui laisse le mot de passe connu de son seul porteur.
 */
final class AccountFields
{
    /**
     * Capacité distincte de `sub_manage_memberships`.
     *
     * Tenir à jour un niveau de plongée et changer le rôle d'un compte ne sont
     * pas le même geste : le premier est de la tenue de registre, le second
     * donne ou retire des droits. Deux capacités permettent au club de confier
     * le premier largement et le second à deux personnes.
     */
    public const CAPABILITY = 'sub_manage_accounts';

    /**
     * Applique les modifications d'un compte, ou dit ce qui s'y oppose.
     *
     * Les droits sont revérifiés ici : appelée d'ailleurs un jour, la méthode
     * ne doit pas compter sur la vigilance de son appelant.
     *
     * @param array<string, mixed> $input
     * @return array{ok: bool, message: string, changed: list<string>}
     */
    public static function apply(int $userId, int $actorId, array $input): array
    {
        $user = self::guard($userId, $actorId);

        if (!$user instanceof WP_User) {
            return self::$refusal;
        }

        $changed = [];
        $update  = ['ID' => $userId];

        foreach (['first_name', 'last_name'] as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }

            $value = sanitize_text_field((string) $input[$key]);

            if ($value !== $user->$key) {
                $changed[] = $key;
            }

            $update[$key] = $value;
        }

        if (array_key_exists('user_email', $input)) {
            $email = sanitize_email((string) $input['user_email']);

            if ($email === '' || !is_email($email)) {
                return self::failure('Adresse de courriel invalide.');
            }

            $existing = email_exists($email);

            if ($existing !== false && (int) $existing !== $userId) {
                return self::failure('Cette adresse est déjà utilisée par un autre compte.');
            }

            if ($email !== $user->user_email) {
                $changed[]            = 'user_email';
                $update['user_email'] = $email;
            }
        }

        $update['display_name'] = trim(
            ($update['first_name'] ?? $user->first_name) . ' ' . ($update['last_name'] ?? $user->last_name)
        ) ?: $user->display_name;

        $newRole = array_key_exists('role', $input) ? sanitize_key((string) $input['role']) : '';

        if ($newRole !== '' && !in_array($newRole, $user->roles, true)) {
            // Vérifié avant l'appartenance à la liste : se voir répondre « ce
            // rôle n'existe pas » quand on a simplement voulu se démettre
            // soi-même enverrait chercher une panne là où il y a une règle.
            if ($userId === $actorId) {
                return self::failure('Vous ne pouvez pas changer votre propre rôle.');
            }

            if (!array_key_exists($newRole, Roles::assignable())) {
                return self::failure('Ce rôle ne peut pas être attribué depuis cet écran.');
            }

            $update['role'] = $newRole;
            $changed[]      = 'role';
        }

        if ($changed === []) {
            return ['ok' => true, 'message' => 'Aucune modification du compte.', 'changed' => []];
        }

        $result = wp_update_user($update);

        if (is_wp_error($result)) {
            // Les messages de WordPress contiennent du HTML ; le bandeau les
            // échappe, et le bureau lirait des balises. On les aplatit ici.
            return self::failure('Enregistrement impossible : ' . wp_strip_all_tags($result->get_error_message()));
        }

        if (in_array('role', $changed, true)) {
            Audit::log('account.role_changed', 'user', $userId, [
                'from' => $user->roles,
                'to'   => $update['role'],
            ], $actorId);
        }

        Audit::log('account.updated', 'user', $userId, ['fields' => $changed], $actorId);

        return ['ok' => true, 'message' => 'Compte enregistré.', 'changed' => $changed];
    }

    /**
     * Envoie à la personne le lien qui lui permet de choisir un mot de passe.
     *
     * @return array{ok: bool, message: string, changed: list<string>}
     */
    public static function sendResetLink(int $userId, int $actorId): array
    {
        $user = self::guard($userId, $actorId);

        if (!$user instanceof WP_User) {
            return self::$refusal;
        }

        // La fonction native de WordPress : elle pose la clé, l'horodate et
        // envoie le courriel. En réécrire une reviendrait à refaire, moins
        // bien, la seule partie du site où une erreur donne un compte ouvert.
        $sent = retrieve_password($user->user_login);

        if (is_wp_error($sent)) {
            return self::failure('Envoi impossible : ' . wp_strip_all_tags($sent->get_error_message()));
        }

        Audit::log('account.password_reset_sent', 'user', $userId, [], $actorId);

        return [
            'ok'      => true,
            'message' => sprintf('Lien de réinitialisation envoyé à %s.', $user->user_email),
            'changed' => ['password_reset'],
        ];
    }

    /**
     * Ce compte peut-il être modifié, et par cette personne ?
     *
     * Sert aussi à l'écran, qui n'affiche le panneau que si la réponse est oui
     * — on ne montre pas des champs pour refuser ensuite l'enregistrement.
     */
    public static function mayEdit(int $userId, int $actorId): bool
    {
        return user_can($actorId, self::CAPABILITY) && Roles::isClubAccount($userId);
    }

    /**
     * Motif du dernier refus prononcé par `guard`.
     *
     * @var array{ok: false, message: string, changed: list<string>}
     */
    private static array $refusal = ['ok' => false, 'message' => '', 'changed' => []];

    /**
     * Contrôles communs aux deux traitements.
     */
    private static function guard(int $userId, int $actorId): ?WP_User
    {
        $user = get_userdata($userId);

        if (!$user instanceof WP_User) {
            self::$refusal = self::failure('Compte introuvable.');

            return null;
        }

        if (!user_can($actorId, self::CAPABILITY)) {
            self::$refusal = self::failure('La gestion des comptes est réservée au bureau.');

            return null;
        }

        if (!Roles::isClubAccount($userId)) {
            self::$refusal = self::failure(
                'Ce compte relève de l’administration technique : il se modifie depuis les '
                . 'écrans WordPress, pas ici.'
            );

            return null;
        }

        return $user;
    }

    /**
     * @return array{ok: false, message: string, changed: list<string>}
     */
    private static function failure(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'changed' => []];
    }
}
