<?php
/**
 * Plugin Name:  Sub Alcatel — Reprise Joomla
 * Description:  Outils en ligne de commande de reprise des comptes, adhésions, articles et médias de l'ancien site Joomla, et de préparation de l'export de production.
 * Version:      1.0.0
 * Requires PHP: 8.1
 * Requires at least: 6.5
 * Requires Plugins: subalcatel-club
 * Author:       Sub Alcatel
 * Text Domain:  subalcatel-import
 */

declare(strict_types=1);

namespace Subalcatel\Import;

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Rien ne s'accroche à WordPress : la reprise n'a ni écran, ni tâche planifiée,
 * ni code exécuté à chaque page. Elle se pilote par les outils de `tools/`,
 * avec `wp eval-file`. Activer l'extension ne fait que rendre ses classes
 * disponibles — ce que `tools/bootstrap.php` fait de toute façon.
 *
 * Séparée de l'extension du club pour que celle-ci ne porte que ce qui sert à
 * tous les clubs : la reprise d'un Joomla est l'histoire de Sub Alcatel, pas
 * une fonction du produit. Elle ne part pas dans les releases.
 */
require_once __DIR__ . '/autoload.php';
