<?php

declare(strict_types=1);

namespace Subalcatel\Club\Identity;

use WP_User;

/**
 * Fonction précise d'un membre du bureau — président, trésorier, secrétaire,
 * webmaster.
 *
 * `Roles::OFFICE` dit qui siège au bureau ; ça ne dit pas qui fait quoi. Or
 * plusieurs courriels du site ont un interlocuteur naturel : une question sur
 * un paiement va au trésorier, pas à qui a validé le dossier ce soir-là. Sans
 * cette distinction, soit le mail part sans adresse de réponse utile, soit il
 * faut deviner le titulaire à la main à chaque envoi.
 *
 * Une fonction reste facultative — beaucoup de membres du bureau n'en portent
 * aucune des quatre — et ne donne aucun droit : les capacités restent celles
 * de `Roles::OFFICE` et des capacités atomiques. C'est une étiquette de
 * routage, pas un rôle.
 */
final class OfficePosition
{
    public const PRESIDENT  = 'president';
    public const TRESORIER  = 'tresorier';
    public const SECRETAIRE = 'secretaire';
    public const WEBMASTER  = 'webmaster';

    public const META_KEY = 'sub_office_position';

    /**
     * @var array<string, string> fonction => libellé
     */
    public const LABELS = [
        self::PRESIDENT  => 'Président·e',
        self::TRESORIER  => 'Trésorier·ère',
        self::SECRETAIRE => 'Secrétaire',
        self::WEBMASTER  => 'Webmaster',
    ];

    /**
     * Fonction portée par ce compte, ou null s'il n'en a pas — y compris
     * quand la valeur enregistrée n'est plus l'une des quatre reconnues.
     */
    public static function of(int $userId): ?string
    {
        $value = (string) get_user_meta($userId, self::META_KEY, true);

        return array_key_exists($value, self::LABELS) ? $value : null;
    }

    public static function set(int $userId, string $position): void
    {
        update_user_meta($userId, self::META_KEY, $position);
    }

    public static function clear(int $userId): void
    {
        delete_user_meta($userId, self::META_KEY);
    }

    /**
     * Titulaire actuel d'une fonction, s'il y en a un.
     *
     * Restreint aux comptes qui portent encore `Roles::OFFICE` : une
     * fonction laissée sur un compte rétrogradé ne doit pas continuer à
     * recevoir les réponses. Déterministe par nom d'affichage si, par erreur
     * de saisie, deux comptes portent la même fonction.
     */
    public static function holder(string $position): ?WP_User
    {
        if (!array_key_exists($position, self::LABELS)) {
            return null;
        }

        $users = get_users([
            'role'       => Roles::OFFICE,
            'meta_key'   => self::META_KEY,
            'meta_value' => $position,
            'number'     => 1,
            'orderby'    => 'display_name',
        ]);

        return $users[0] ?? null;
    }

    /**
     * En-tête `Reply-To` vers le titulaire d'une fonction, prêt pour
     * `Mailer::send()`.
     *
     * Tableau vide si personne ne porte la fonction : un envoi ne doit jamais
     * échouer faute d'un champ que le bureau n'a pas encore renseigné, il part
     * simplement avec l'expéditeur habituel du site.
     *
     * @return list<string>
     */
    public static function replyToHeader(string $position): array
    {
        $user = self::holder($position);

        if (!$user instanceof WP_User || !is_email($user->user_email)) {
            return [];
        }

        return [sprintf('Reply-To: %s <%s>', $user->display_name, $user->user_email)];
    }
}
