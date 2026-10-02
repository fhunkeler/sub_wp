<?php
/**
 * Apparence : Système, Clair ou Sombre, au choix de chaque visiteur.
 *
 * - Le choix est gardé dans le navigateur (localStorage, clé `sub-apparence`),
 *   jamais côté serveur : rien à stocker, rien à consentir, et la page reste
 *   identique en cache pour tout le monde.
 * - Un court script, imprimé en tête de page AVANT les feuilles de style, pose
 *   `data-theme="dark|light"` sur <html> : pas de flash clair au chargement.
 * - « Système » suit `prefers-color-scheme`, y compris quand il change pendant
 *   la visite (assets/js/apparence.js).
 * - Sans JavaScript, le site reste en clair et le sélecteur est masqué.
 *
 * @package Subalcatel
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Script de tête : applique le choix avant le premier affichage.
 */
function subalcatel_apparence_script_tete(): void {
	$script = <<<'JS'
(function(){var d=document.documentElement,c="systeme";try{c=localStorage.getItem("sub-apparence")||"systeme"}catch(e){}var s=c==="sombre"||(c!=="clair"&&window.matchMedia&&matchMedia("(prefers-color-scheme: dark)").matches);d.setAttribute("data-theme",s?"dark":"light");d.setAttribute("data-apparence",c)})();
JS;
	wp_print_inline_script_tag( $script, array( 'id' => 'subalcatel-apparence-tete' ) );
}
add_action( 'wp_head', 'subalcatel_apparence_script_tete', 1 );

/**
 * Feuilles du mode sombre et script du sélecteur.
 */
function subalcatel_apparence_assets(): void {
	wp_enqueue_style(
		'subalcatel-sombre-site',
		SUBALCATEL_URI . '/assets/css/sombre-site.css',
		array( 'subalcatel-site' ),
		subalcatel_asset_version( 'assets/css/sombre-site.css' )
	);

	wp_enqueue_style(
		'subalcatel-sombre',
		SUBALCATEL_URI . '/assets/css/sombre.css',
		array( 'subalcatel-sombre-site' ),
		subalcatel_asset_version( 'assets/css/sombre.css' )
	);

	wp_enqueue_script(
		'subalcatel-apparence',
		SUBALCATEL_URI . '/assets/js/apparence.js',
		array(),
		subalcatel_asset_version( 'assets/js/apparence.js' ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}
add_action( 'wp_enqueue_scripts', 'subalcatel_apparence_assets', 20 );

/**
 * Bloc « Sélecteur d'apparence ».
 */
function subalcatel_register_apparence_block(): void {
	register_block_type(
		'subalcatel/apparence',
		array(
			'api_version'     => 3,
			'title'           => __( 'Sub Alcatel — Sélecteur d\'apparence', 'subalcatel' ),
			'category'        => 'design',
			'icon'            => 'admin-appearance',
			'description'     => __( 'Laisse le visiteur choisir entre l\'apparence du système, claire ou sombre.', 'subalcatel' ),
			'supports'        => array(
				'html'     => false,
				'reusable' => false,
			),
			'attributes'      => array(
				'compact' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'render_callback' => 'subalcatel_render_apparence_block',
		)
	);
}
add_action( 'init', 'subalcatel_register_apparence_block' );

/**
 * Rendu : trois boutons à bascule. Masqué (`hidden`) tant que le script ne l'a
 * pas activé : sans JavaScript, il ne ferait rien.
 *
 * @param array $attributes Attributs du bloc.
 * @return string
 */
function subalcatel_render_apparence_block( array $attributes ): string {
	$choix = array(
		'systeme' => __( 'Système', 'subalcatel' ),
		'clair'   => __( 'Clair', 'subalcatel' ),
		'sombre'  => __( 'Sombre', 'subalcatel' ),
	);

	// Compact (en-tête) : un seul bouton rond qui fait tourner les trois choix.
	// Trois icônes de front faisaient passer l'en-tête sur deux lignes.
	if ( ! empty( $attributes['compact'] ) ) {
		return sprintf(
			'<button %1$s type="button" data-libelles="%2$s" hidden><span class="sub-apparence-bascule__texte">%3$s</span></button>',
			get_block_wrapper_attributes( array( 'class' => 'sub-apparence-bascule' ) ),
			esc_attr( wp_json_encode( $choix ) ),
			esc_html__( 'Apparence : Système', 'subalcatel' )
		);
	}

	$wrapper = get_block_wrapper_attributes( array( 'class' => 'sub-apparence' ) );

	$boutons = '';
	foreach ( $choix as $valeur => $libelle ) {
		$boutons .= sprintf(
			'<button type="button" class="sub-apparence__choix sub-apparence__choix--%1$s" data-apparence="%1$s" aria-pressed="false" title="%2$s"><span class="sub-apparence__texte">%2$s</span></button>',
			esc_attr( $valeur ),
			esc_html( $libelle )
		);
	}

	return sprintf(
		'<div %1$s role="group" aria-label="%2$s" hidden>%3$s</div>',
		$wrapper,
		esc_attr__( 'Apparence du site', 'subalcatel' ),
		$boutons
	);
}
