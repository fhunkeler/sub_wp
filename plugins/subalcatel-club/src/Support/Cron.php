<?php

declare(strict_types=1);

namespace Subalcatel\Club\Support;

/**
 * Noms des tâches planifiées.
 *
 * L'entretien quotidien ({@see \Subalcatel\Club\Maintenance\DailyDigest})
 * orchestre tous les domaines : il est tout en haut des couches. Ceux qui
 * veulent simplement s'y greffer — la purge du journal des connexions, par
 * exemple — n'ont besoin que du nom de la tâche, qui vit donc ici.
 */
final class Cron
{
    /** Valeur historique : la changer laisserait l'ancienne tâche planifiée orpheline. */
    public const DAILY = 'subalcatel_daily';
}
