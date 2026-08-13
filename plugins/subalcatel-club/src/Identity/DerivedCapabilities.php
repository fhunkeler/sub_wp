<?php

declare(strict_types=1);

namespace Subalcatel\Club\Identity;

use Subalcatel\Club\Policy\EligibilityPolicy;
use WP_User;

/**
 * Droits de création de sortie déduits du niveau de plongée.
 *
 * [Roles] pose le principe : « encadrant » et « directeur de plongée » ne sont
 * pas des rôles, ce sont des propriétés du niveau — « le jour où un membre passe
 * son E2, personne ne doit penser à modifier son compte ». Restait à le rendre
 * vrai : jusqu'ici le droit d'ouvrir une sortie s'ajoutait nommément sur chaque
 * compte, et seul l'import Joomla le faisait. Un membre qui passait son P3 le
 * samedi ne pouvait toujours pas proposer une exploration le dimanche.
 *
 * Ce filtre le calcule à chaque requête. Le droit suit le niveau et disparaît
 * avec lui — rien n'est écrit en base, donc rien ne survit à un changement de
 * niveau. Une capacité accordée à la main par le bureau reste indépendante :
 * on ajoute, on ne retire jamais.
 */
final class DerivedCapabilities
{
    /**
     * Drapeau de niveau => capacités accordées.
     *
     * Le type d'événement porte sa propre exigence de drapeau (« plongeur
     * autonome requis », « directeur de plongée requis ») : ces capacités
     * ouvrent la porte, le type décide ensuite qui la franchit. C'est ce qui
     * empêche un autonome de créer une formation alors qu'il détient
     * `sub_create_exploration_event`.
     *
     * @var array<string, list<string>>
     */
    private const GRANTS = [
        DiveLevels::FLAG_AUTONOMOUS  => ['sub_create_exploration_event'],
        DiveLevels::FLAG_INSTRUCTOR  => ['sub_create_training_event'],
        DiveLevels::FLAG_DIVE_LEADER => ['sub_create_exploration_event', 'sub_create_training_event'],
    ];

    /** @var array<int, array<string, true>> */
    private static array $cache = [];

    public static function register(): void
    {
        add_filter('user_has_cap', [self::class, 'grant'], 10, 4);
    }

    /**
     * @param array<string, bool> $allcaps
     * @param list<string>        $caps
     * @param array<int, mixed>   $args
     * @return array<string, bool>
     */
    public static function grant(array $allcaps, array $caps, array $args, WP_User $user): array
    {
        return array_merge($allcaps, self::forUser((int) $user->ID));
    }

    /**
     * Capacités déduites, pour un membre.
     *
     * @return array<string, true>
     */
    public static function forUser(int $userId): array
    {
        if (isset(self::$cache[$userId])) {
            return self::$cache[$userId];
        }

        // Le cache est posé AVANT le calcul : `user_has_cap` est un filtre très
        // sollicité, et les lectures ci-dessous peuvent le déclencher à nouveau.
        // Sans cette réservation, l'appel se rappellerait sans fin.
        self::$cache[$userId] = [];

        if ($userId === 0) {
            return [];
        }

        // Une habilitation technique suppose une adhésion à jour : on n'organise
        // pas une sortie au nom d'un club dont on n'est plus membre. Le droit
        // revient de lui-même au renouvellement, sans intervention du bureau.
        if (!(new EligibilityPolicy())->hasActiveMembership($userId)->allowed) {
            return [];
        }

        $granted = [];

        foreach (self::GRANTS as $flag => $capabilities) {
            if (!DiveLevels::hasFlag($userId, $flag)) {
                continue;
            }

            foreach ($capabilities as $capability) {
                $granted[$capability] = true;
            }
        }

        return self::$cache[$userId] = $granted;
    }

    /**
     * Vide le cache — après un changement de niveau ou d'adhésion dans la même
     * requête, sans quoi l'écran qui suit lirait l'état précédent.
     */
    public static function forget(): void
    {
        self::$cache = [];
    }
}
