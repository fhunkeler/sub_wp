<?php

declare(strict_types=1);

namespace Subalcatel\Club\Identity;

use Subalcatel\Club\Privacy\MemberPurge;

/**
 * Comptes techniques : ceux qui administrent, sans être personne.
 *
 * Le club en compte quatre, hérités de Joomla — `admin_langlais`,
 * `admin_pivette`, `admin_rougeolle`, `admin_tuffin`. Ce ne sont pas des
 * adhérents : la même personne possède à côté son compte ordinaire, qui porte
 * l'adhésion, le niveau de plongée et l'historique. Le compte technique ne
 * sert qu'à ouvrir les écrans du bureau.
 *
 * [MembersScreen] avait déjà tiré la conclusion pour les administrateurs
 * WordPress — « ils y apparaissaient éternellement *adhésion pas à jour*, ils
 * n'ont pas d'adhésion à être à jour » — et les avait sortis de l'annuaire en
 * retirant `administrator` des rôles du club. La règle passait à côté des
 * quatre comptes ci-dessus, qui portent `sub_office` : un rôle du club, donc
 * un adhérent aux yeux de ce filtre. Ils restaient dans l'annuaire, dans les
 * listes de diffusion par niveau, et le formulaire de profil leur réclamait
 * une date de naissance et une personne à prévenir en cas d'accident.
 *
 * D'où cette classe : le manque n'était pas une exception à ajouter quelque
 * part, c'était une notion absente. Un compte technique n'est pas un rôle —
 * `admin_pivette` est au bureau, c'est bien le sens de son existence — c'est
 * une propriété du compte, orthogonale à ce qu'il a le droit de faire.
 *
 * **Marquer un compte, c'est le vider.** Un compte technique qui garderait
 * une adresse postale et un contact d'urgence serait la même anomalie sous un
 * autre nom ; l'invariant tient parce que [self::mark] efface en même temps
 * qu'il marque.
 *
 * Ce qui ne change pas : le compte reste, son rôle reste, et il reste dans la
 * liste de diffusion « Bureau » — c'est par là qu'on écrit à ces gens, et
 * leurs adresses sont de vraies adresses.
 */
final class TechnicalAccounts
{
    public const META = '_sub_technical_account';

    /**
     * Métas de membre qui ne sont pas des champs de profil.
     *
     * Les champs de profil, eux, ne sont pas recopiés ici : [self::mark] les
     * énumère depuis [ProfileFields] — un champ ajouté demain sera effacé sans
     * qu'on y pense. Le niveau de plongée en fait partie (`dive_level_id`), il
     * n'a donc pas à figurer dans cette liste.
     */
    private const MEMBER_META = [
        'sub_membership_valid_until',
        'sub_lending_rights',
    ];

    public static function is(int $userId): bool
    {
        return get_user_meta($userId, self::META, true) === '1';
    }

    /**
     * Marque le compte et efface ce qu'il porte de personnel.
     *
     * @return list<string> ce qui a réellement été effacé, pour le rapport
     *                      d'export — un nettoyage silencieux ne se relit pas.
     */
    public static function mark(int $userId): array
    {
        $cleared = [];

        foreach (array_keys(ProfileFields::all()) as $field) {
            $key = ProfileFields::metaKey($field);

            if (get_user_meta($userId, $key, true) !== '') {
                delete_user_meta($userId, $key);
                $cleared[] = $field;
            }
        }

        foreach (self::MEMBER_META as $key) {
            if (get_user_meta($userId, $key, true) !== '') {
                delete_user_meta($userId, $key);
                $cleared[] = $key;
            }
        }

        // Préférences de diffusion, jeton de désabonnement, groupes constitués
        // par le bureau : le compte sort des listes de toute façon, laisser ces
        // métas ne ferait qu'entretenir une donnée que plus rien ne lit.
        MemberPurge::forgetCommunication($userId);
        MemberPurge::forgetDiveLevelHistory($userId);

        update_user_meta($userId, self::META, '1');

        return $cleared;
    }

    public static function unmark(int $userId): void
    {
        delete_user_meta($userId, self::META);
    }

    /**
     * Identifiants de tous les comptes techniques.
     *
     * @return list<int>
     */
    public static function all(): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = '1'",
            self::META
        )));
    }

    /**
     * Retire les comptes techniques d'une liste d'identifiants.
     *
     * Une seule requête, quelle que soit la longueur de la liste : ces filtres
     * sont appelés sur des listes de diffusion de plusieurs centaines de
     * membres, et un `get_user_meta` par ligne y coûterait cher.
     *
     * @param  list<int> $userIds
     * @return list<int>
     */
    public static function filter(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $technical = self::all();

        if ($technical === []) {
            return array_values($userIds);
        }

        return array_values(array_diff($userIds, $technical));
    }
}
