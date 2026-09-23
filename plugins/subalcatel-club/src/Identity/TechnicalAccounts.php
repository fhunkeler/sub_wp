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
     * Les seules métas du plugin qu'un compte technique conserve.
     *
     * Tout le reste part — et c'est une liste de ce qu'on garde, pas de ce
     * qu'on efface, délibérément. Énumérer ce qu'il faut effacer suppose de
     * connaître d'avance toutes les clés ; la base en portait une, `sub_licence`,
     * qu'aucun code ne lit plus et que [ProfileFields] ignore donc. Elle
     * survivait au marquage. Une liste d'exclusions rate ce qu'elle ne connaît
     * pas ; une liste d'inclusions ne rate rien.
     *
     * `_sub_joomla_user_id` reste : c'est la marque d'origine de la reprise,
     * la garde anti-doublon d'un import rejoué, et elle ne dit rien de
     * personnel. `_sub_technical_account` est le marqueur lui-même.
     */
    private const KEPT_META = [
        '_sub_joomla_user_id',
        self::META,
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
        global $wpdb;

        $keys = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT meta_key FROM {$wpdb->usermeta}
              WHERE user_id = %d AND (meta_key LIKE %s OR meta_key LIKE %s)",
            $userId,
            $wpdb->esc_like('sub_') . '%',
            $wpdb->esc_like('_sub_') . '%'
        )) ?: [];

        $cleared = [];

        foreach ($keys as $key) {
            if (in_array($key, self::KEPT_META, true)) {
                continue;
            }

            delete_user_meta($userId, (string) $key);
            $cleared[] = (string) $key;
        }

        // Préférences de diffusion, jeton de désabonnement, groupes constitués
        // par le bureau : le compte sort des listes de toute façon, laisser ces
        // métas ne ferait qu'entretenir une donnée que plus rien ne lit. Les
        // deux premières sont des métas `sub_*`, déjà parties ci-dessus ; ces
        // appels emportent ce qui vit ailleurs — le jeton et la table des
        // groupes.
        MemberPurge::forgetCommunication($userId);
        MemberPurge::forgetDiveLevelHistory($userId);

        update_user_meta($userId, self::META, '1');

        sort($cleared);

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
