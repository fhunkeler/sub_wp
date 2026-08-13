<?php
/**
 * Chargement des feuilles de style.
 *
 * @package Subalcatel
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Version d'un asset : mtime en développement, version du thème sinon.
 *
 * @param string $relative Chemin relatif au thème.
 * @return string
 */
function subalcatel_asset_version( string $relative ): string {
	$path = SUBALCATEL_DIR . '/' . ltrim( $relative, '/' );

	if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && file_exists( $path ) ) {
		return (string) filemtime( $path );
	}

	return SUBALCATEL_VERSION;
}

/**
 * Styles du site public.
 */
function subalcatel_enqueue_styles(): void {
	// Feuille de déclaration du thème (en-tête seulement, mais WordPress l'attend).
	wp_enqueue_style(
		'subalcatel-style',
		get_stylesheet_uri(),
		array(),
		subalcatel_asset_version( 'style.css' )
	);

	// Composants que theme.json ne sait pas exprimer.
	wp_enqueue_style(
		'subalcatel-site',
		SUBALCATEL_URI . '/assets/css/site.css',
		array( 'subalcatel-style' ),
		subalcatel_asset_version( 'assets/css/site.css' )
	);
}
add_action( 'wp_enqueue_scripts', 'subalcatel_enqueue_styles' );

/**
 * Styles de l'éditeur, pour que la rédaction ressemble au rendu.
 */
function subalcatel_editor_styles(): void {
	add_editor_style( array( 'assets/css/site.css', 'assets/css/editor.css' ) );
}
add_action( 'after_setup_theme', 'subalcatel_editor_styles' );
