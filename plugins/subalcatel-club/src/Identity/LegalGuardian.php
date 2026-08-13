<?php

declare(strict_types=1);

namespace Subalcatel\Club\Identity;

use Subalcatel\Club\Support\Audit;

/**
 * Représentant légal d'un adhérent mineur.
 *
 * Un club de plongée accueille des jeunes — l'option « tarif jeune » du Joomla
 * en atteste. Trois conséquences que ni le cahier des charges ni la proposition
 * initiale n'avaient prévues :
 *
 * 1. Le **consentement** est donné par le représentant légal, pas par le mineur.
 * 2. Les **rappels** doivent lui parvenir : un adolescent ne renouvellera pas
 *    seul son certificat médical.
 * 3. Le passage à la majorité doit être **automatique**. Attendre que quelqu'un
 *    y pense, c'est garder un adulte sous tutelle pendant des années.
 */
final class LegalGuardian
{
    public const MAJORITY_AGE = 18;

    /**
     * Le membre est-il mineur à une date donnée ?
     *
     * Sans date de naissance renseignée, on répond non : mieux vaut ne pas
     * exiger une autorisation à tort que bloquer un adulte.
     */
    public static function isMinor(int $userId, ?string $onDate = null): bool
    {
        $age = self::ageOf($userId, $onDate);

        return $age !== null && $age < self::MAJORITY_AGE;
    }

    public static function ageOf(int $userId, ?string $onDate = null): ?int
    {
        $birthDate = (string) get_user_meta($userId, 'sub_birth_date', true);

        if ($birthDate === '') {
            return null;
        }

        // Le « ! » remet l'heure à zéro. Sans lui, createFromFormat conserve
        // l'heure courante : une naissance datée d'aujourd'hui il y a quinze ans
        // se retrouve à 13h47, et la différence avec minuit donne quatorze ans.
        $birth = \DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);

        if ($birth === false) {
            return null;
        }

        $reference = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $onDate ?? current_time('Y-m-d')
        );

        if ($reference === false) {
            return null;
        }

        return (int) $birth->diff($reference)->y;
    }

    /**
     * Coordonnées du représentant, si le membre est mineur et qu'elles sont
     * renseignées.
     *
     * @return array{name: string, email: string, phone: string, relation: string}|null
     */
    public static function of(int $userId): ?array
    {
        if (!self::isMinor($userId)) {
            return null;
        }

        $email = (string) get_user_meta($userId, 'sub_guardian_email', true);

        if ($email === '' || !is_email($email)) {
            return null;
        }

        return [
            'name'     => (string) get_user_meta($userId, 'sub_guardian_name', true),
            'email'    => $email,
            'phone'    => (string) get_user_meta($userId, 'sub_guardian_phone', true),
            'relation' => (string) get_user_meta($userId, 'sub_guardian_relation', true),
        ];
    }

    /**
     * Le dossier du mineur est-il complet côté représentant légal ?
     *
     * Sert au bureau : un mineur sans contact parental est une situation à
     * régulariser avant la première sortie, pas au bord de l'eau.
     */
    public static function isIncomplete(int $userId): bool
    {
        return self::isMinor($userId) && self::of($userId) === null;
    }

    /**
     * Membres devenus majeurs depuis le dernier passage.
     *
     * On compare l'âge du jour à celui de la veille : le jour de l'anniversaire,
     * la bascule est détectée une fois et une seule.
     *
     * @return list<int>
     */
    public static function newlyOfAge(?string $onDate = null): array
    {
        global $wpdb;

        $today     = new \DateTimeImmutable($onDate ?? current_time('Y-m-d'));
        $threshold = $today->modify('-' . self::MAJORITY_AGE . ' years')->format('Y-m-d');

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta}
             WHERE meta_key = 'sub_birth_date' AND meta_value = %s",
            $threshold
        ));

        return array_map('intval', $ids);
    }

    /**
     * Bascule un compte devenu majeur : les données du représentant sont
     * effacées, car plus rien ne justifie de les conserver.
     */
    public static function comeOfAge(int $userId): void
    {
        foreach (['guardian_name', 'guardian_email', 'guardian_phone', 'guardian_relation'] as $field) {
            delete_user_meta($userId, 'sub_' . $field);
        }

        update_user_meta($userId, 'sub_came_of_age_on', current_time('Y-m-d'));

        Audit::log('member.came_of_age', 'user', $userId, [
            'note' => 'Coordonnées du représentant légal effacées.',
        ], 0);
    }
}
