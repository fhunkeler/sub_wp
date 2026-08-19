<?php
/**
 * Thème Sub Alcatel — point d'entrée.
 *
 * Règle du projet : aucune règle métier ici. Ce fichier et le dossier inc/
 * ne contiennent que de la présentation. Les adhésions, événements, droits
 * et emprunts vivent dans l'extension subalcatel-club.
 *
 * @package Subalcatel
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Version du thème, lue dans style.css.
 *
 * Elle était écrite ici en dur et avait dérivé : la constante disait 1.0.0
 * quand l'en-tête du thème en était à 1.4.0. Comme subalcatel_asset_version()
 * s'en sert hors mode debug, l'adresse de site.css restait figée sur
 * `?ver=1.0.0` — quatre versions durant, les navigateurs des visiteurs ont
 * resservi leur copie en cache. Une seule source, désormais.
 */
define( 'SUBALCATEL_VERSION', wp_get_theme()->get( 'Version' ) ?: '1.0.0' );
define( 'SUBALCATEL_DIR', get_template_directory() );
define( 'SUBALCATEL_URI', get_template_directory_uri() );

require_once SUBALCATEL_DIR . '/inc/setup.php';
require_once SUBALCATEL_DIR . '/inc/fonts.php';
require_once SUBALCATEL_DIR . '/inc/assets.php';
require_once SUBALCATEL_DIR . '/inc/blocks.php';
require_once SUBALCATEL_DIR . '/inc/icone.php';
require_once SUBALCATEL_DIR . '/inc/connexion.php';
