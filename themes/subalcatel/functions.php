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

define( 'SUBALCATEL_VERSION', '1.0.0' );
define( 'SUBALCATEL_DIR', get_template_directory() );
define( 'SUBALCATEL_URI', get_template_directory_uri() );

require_once SUBALCATEL_DIR . '/inc/setup.php';
require_once SUBALCATEL_DIR . '/inc/fonts.php';
require_once SUBALCATEL_DIR . '/inc/assets.php';
require_once SUBALCATEL_DIR . '/inc/blocks.php';
