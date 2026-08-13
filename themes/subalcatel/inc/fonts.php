<?php
/**
 * Polices auto-hébergées.
 *
 * Les polices ne sont JAMAIS chargées depuis fonts.googleapis.com : un tel
 * appel transmet l'adresse IP des visiteurs à Google sans base légale, ce qui
 * a déjà été sanctionné en Europe. Les fichiers .woff2 sont déposés dans
 * assets/fonts/ (voir le README de ce dossier).
 *
 * Les déclarations @font-face ne sont injectées dans theme.json que si les
 * fichiers sont réellement présents : sans cela le site déclencherait une
 * requête 404 par police et par page. En leur absence, le repli système
 * défini dans theme.json s'applique et le site reste parfaitement lisible.
 *
 * @package Subalcatel
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fichiers attendus, par famille.
 *
 * @return array<string, string> Slug de famille => nom de fichier.
 */
function subalcatel_font_files(): array {
	return array(
		'titre' => 'outfit-variable.woff2',
		'texte' => 'inter-variable.woff2',
	);
}

/**
 * Ajoute les @font-face aux familles déclarées dans theme.json,
 * uniquement pour les fichiers présents sur le disque.
 *
 * @param WP_Theme_JSON_Data $theme_json Données du thème.
 * @return WP_Theme_JSON_Data
 */
function subalcatel_register_font_faces( $theme_json ) {
	$repli    = 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';
	$families = array();

	$definitions = array(
		'titre' => array( 'Outfit', 'Outfit (titres)' ),
		'texte' => array( 'Inter', 'Inter (texte)' ),
	);

	foreach ( subalcatel_font_files() as $slug => $file ) {
		if ( ! file_exists( SUBALCATEL_DIR . '/assets/fonts/' . $file ) ) {
			continue;
		}

		list( $family, $label ) = $definitions[ $slug ];

		$families[] = array(
			'slug'       => $slug,
			'name'       => $label,
			'fontFamily' => sprintf( '"%s", %s', $family, $repli ),
			'fontFace'   => array(
				array(
					'fontFamily'  => $family,
					'fontWeight'  => '400 700',
					'fontStyle'   => 'normal',
					'fontDisplay' => 'swap',
					'src'         => array( 'file:./assets/fonts/' . $file ),
				),
			),
		);
	}

	if ( empty( $families ) ) {
		return $theme_json;
	}

	return $theme_json->update_with(
		array(
			'version'  => 3,
			'settings' => array(
				'typography' => array( 'fontFamilies' => $families ),
			),
		)
	);
}
add_filter( 'wp_theme_json_data_theme', 'subalcatel_register_font_faces' );

/**
 * Signale à l'administrateur que les polices manquent.
 *
 * Le site fonctionne sans, mais il ne ressemble pas à la charte validée :
 * mieux vaut que quelqu'un le sache.
 */
function subalcatel_missing_fonts_notice(): void {
	if ( ! current_user_can( 'switch_themes' ) ) {
		return;
	}

	$missing = array();
	foreach ( subalcatel_font_files() as $file ) {
		if ( ! file_exists( SUBALCATEL_DIR . '/assets/fonts/' . $file ) ) {
			$missing[] = $file;
		}
	}

	if ( empty( $missing ) ) {
		return;
	}

	$message = sprintf(
		/* translators: 1: liste de fichiers, 2: chemin du dossier. */
		__( 'Thème Sub Alcatel : les polices %1$s sont absentes de %2$s. Le site utilise la police système en repli — voir le README de ce dossier.', 'subalcatel' ),
		implode( ', ', $missing ),
		'wp-content/themes/subalcatel/assets/fonts/'
	);

	printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( $message ) );
}
add_action( 'admin_notices', 'subalcatel_missing_fonts_notice' );
