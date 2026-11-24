<?php

declare(strict_types=1);

namespace Subalcatel\Club\Ui;

/**
 * Dates affichées aux membres, en toutes lettres.
 *
 * Vivaient dans le tableau de bord membre, que les pages d'adhésion et de
 * sorties devaient alors aller chercher : des écrans de domaine qui
 * dépendaient de l'écran qui les assemble.
 */
final class Dates
{
    public static function frDate(string $isoDate): string
    {
        $ts = strtotime($isoDate);

        return $ts === false ? $isoDate : wp_date('j F Y', $ts);
    }

    public static function frDateTime(string $mysqlDate): string
    {
        // Heure du site, sans fuseau : strtotime() la lirait en UTC, et
        // wp_date() la décalerait d'une ou deux heures selon la saison.
        $formatted = mysql2date('l j F Y à H\hi', $mysqlDate);

        return $formatted === false ? $mysqlDate : (string) $formatted;
    }
}
