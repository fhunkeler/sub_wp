<?php

declare(strict_types=1);

namespace Subalcatel\Club\Identity;

use WP_User;

/**
 * Fonction précise du bureau — président, trésorier, secrétaire, webmaster —
 * et qui l'occupe.
 *
 * `Roles::OFFICE` dit qui administre le site ; ça ne dit pas qui fait quoi, et
 * les deux ne coïncident pas. Depuis la reprise du Joomla, seuls les quatre
 * comptes techniques `admin_*` portent `Roles::OFFICE` — décision délibérée,
 * pour que l'accès au back-office reste sur des comptes dédiés plutôt que sur
 * ceux du quotidien (voir la décision du 22/09/2026). Les personnes qui tiennent
 * réellement la trésorerie ou le secrétariat, elles, se connectent avec leur
 * compte de membre ordinaire.
 *
 * D'où ce choix : la fonction ne vit pas sur le compte de son titulaire — elle
 * n'exigerait alors que des comptes `admin_*`, qui ne sont personne en
 * particulier — mais dans un registre à part, tenu depuis les Réglages. Les
 * quatre fonctions changent rarement ; qui les occupe change plus souvent,
 * au rythme des élections du bureau. Un registre centralisé se met à jour en
 * une ligne, sans passer par la fiche de qui part ni celle de qui arrive.
 *
 * Une fonction reste facultative et ne donne aucun droit : les capacités
 * restent celles de `Roles::OFFICE` et des capacités atomiques. C'est une
 * étiquette de routage pour les courriels du site, pas un rôle.
 */
final class OfficePosition
{
    public const PRESIDENT  = 'president';
    public const TRESORIER  = 'tresorier';
    public const SECRETAIRE = 'secretaire';
    public const WEBMASTER  = 'webmaster';

    public const OPTION = 'subalcatel_club_office_positions';

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
     * Le registre complet, une entrée par fonction reconnue — 0 si vacante.
     *
     * Toujours les quatre fonctions, même si l'option ne contient encore rien :
     * l'écran de réglages n'a pas à connaître la liste séparément.
     *
     * @return array<string, int> fonction => identifiant de membre (0 = vacante)
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        $map = [];
        foreach (self::LABELS as $position => $label) {
            $map[$position] = (int) ($stored[$position] ?? 0);
        }

        return $map;
    }

    /**
     * Attribue une fonction. `$userId` à 0 la laisse vacante.
     */
    public static function set(string $position, int $userId): bool
    {
        if (!array_key_exists($position, self::LABELS)) {
            return false;
        }

        $stored             = self::all();
        $stored[$position]  = max(0, $userId);
        update_option(self::OPTION, $stored);

        return true;
    }

    /**
     * Titulaire actuel d'une fonction, s'il y en a un.
     *
     * Restreint aux comptes toujours membres du club : une fonction laissée
     * sur un compte parti ou supprimé ne doit pas continuer à recevoir les
     * réponses en son nom.
     */
    public static function holder(string $position): ?WP_User
    {
        $userId = self::all()[$position] ?? 0;

        if ($userId <= 0) {
            return null;
        }

        $user = get_userdata($userId);

        return $user instanceof WP_User && Roles::isMemberOfClub($userId) ? $user : null;
    }

    /**
     * En-tête `Reply-To` vers le titulaire d'une fonction, prêt pour
     * `Mailer::send()`.
     *
     * Tableau vide si la fonction est vacante ou son titulaire introuvable :
     * un envoi ne doit jamais échouer faute d'un registre à jour, il part
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
