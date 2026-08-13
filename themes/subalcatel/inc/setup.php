<?php
/**
 * Déclaration du thème et catégories de compositions.
 *
 * @package Subalcatel
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supports du thème.
 *
 * Un thème bloc active déjà l'essentiel automatiquement ; on ne déclare ici
 * que ce qui ne l'est pas.
 */
function subalcatel_setup(): void {
	load_theme_textdomain( 'subalcatel', SUBALCATEL_DIR . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'html5', array( 'search-form', 'script', 'style', 'navigation-widgets' ) );

	// Formats d'images utilisés par les compositions du thème.
	add_image_size( 'subalcatel-carte', 720, 480, true );      // Cartes activité / article.
	add_image_size( 'subalcatel-portrait', 480, 600, true );   // Trombinoscope.
	add_image_size( 'subalcatel-hero', 2400, 1200, true );     // Bandeau d'accueil.

	// Les commentaires sont désactivés sur tout le site (décision projet).
	remove_theme_support( 'post-comments' );
}
add_action( 'after_setup_theme', 'subalcatel_setup' );

/**
 * Catégories de compositions propres au club, pour que l'inserteur reste lisible.
 */
function subalcatel_register_pattern_categories(): void {
	$categories = array(
		'subalcatel-vitrine'   => __( 'Sub Alcatel — Vitrine', 'subalcatel' ),
		'subalcatel-adhesion'  => __( 'Sub Alcatel — Adhésion', 'subalcatel' ),
		'subalcatel-editorial' => __( 'Sub Alcatel — Éditorial', 'subalcatel' ),
	);

	foreach ( $categories as $slug => $label ) {
		register_block_pattern_category( $slug, array( 'label' => $label ) );
	}
}
add_action( 'init', 'subalcatel_register_pattern_categories' );

/**
 * Classes de corps signalant l'état de connexion.
 *
 * Sert uniquement à des ajustements d'affichage. Ne jamais s'en servir pour
 * masquer une information sensible : le contrôle d'accès est fait côté
 * serveur par l'extension métier.
 *
 * @param string[] $classes Classes existantes.
 * @return string[]
 */
function subalcatel_body_class( array $classes ): array {
	$classes[] = is_user_logged_in() ? 'sub-connecte' : 'sub-visiteur';
	return $classes;
}
add_filter( 'body_class', 'subalcatel_body_class' );

/**
 * Coupe court aux commentaires, y compris sur les contenus importés.
 */
function subalcatel_disable_comments(): void {
	foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
		if ( post_type_supports( $type, 'comments' ) ) {
			remove_post_type_support( $type, 'comments' );
			remove_post_type_support( $type, 'trackbacks' );
		}
	}
}
add_action( 'init', 'subalcatel_disable_comments' );

add_filter( 'comments_open', '__return_false', 20 );
add_filter( 'pings_open', '__return_false', 20 );
add_filter( 'comments_array', '__return_empty_array', 20 );

/**
 * Longueur d'extrait et suffixe.
 */
add_filter( 'excerpt_length', static fn(): int => 26, 20 );
add_filter( 'excerpt_more', static fn(): string => ' …', 20 );
