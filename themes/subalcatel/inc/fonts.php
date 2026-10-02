<?php
/**
 * Polices auto-hébergées.
 *
 * Les polices ne sont JAMAIS chargées depuis fonts.googleapis.com : un tel
 * appel transmet l'adresse IP des visiteurs à Google sans base légale, ce qui
 * a déjà été sanctionné en Europe. Les fichiers .woff sont déposés dans
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
 * Refonte d'octobre 2026 : Bricolage Grotesque pour les titres, Instrument Sans
 * pour le texte. Les deux sont livrées en graisses statiques 400 et 700 (plus
 * l'italique du texte), sous-ensemble latin, au format WOFF : environ 150 Ko au
 * total. Une graisse intermédiaire (500, 600) demandée par un bloc se résout
 * sur la plus proche, sans requête supplémentaire.
 *
 * @return array<string, array<int, array{fichier: string, graisse: string, style: string}>>
 */
function subalcatel_font_files(): array {
	return array(
		'titre' => array(
			array( 'fichier' => 'bricolage-grotesque-regular.woff', 'graisse' => '400', 'style' => 'normal' ),
			array( 'fichier' => 'bricolage-grotesque-bold.woff', 'graisse' => '700', 'style' => 'normal' ),
		),
		'texte' => array(
			array( 'fichier' => 'instrument-sans-regular.woff', 'graisse' => '400', 'style' => 'normal' ),
			array( 'fichier' => 'instrument-sans-italic.woff', 'graisse' => '400', 'style' => 'italic' ),
			array( 'fichier' => 'instrument-sans-bold.woff', 'graisse' => '700', 'style' => 'normal' ),
		),
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
	$replis   = array(
		'titre' => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
		'texte' => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
	);
	$families = array();

	$definitions = array(
		'titre' => array( 'Bricolage Grotesque', 'Bricolage Grotesque (titres)' ),
		'texte' => array( 'Instrument Sans', 'Instrument Sans (texte)' ),
	);

	foreach ( subalcatel_font_files() as $slug => $faces ) {
		list( $family, $label ) = $definitions[ $slug ];

		$font_faces = array();
		foreach ( $faces as $face ) {
			if ( ! file_exists( SUBALCATEL_DIR . '/assets/fonts/' . $face['fichier'] ) ) {
				continue;
			}
			$font_faces[] = array(
				'fontFamily'  => $family,
				'fontWeight'  => $face['graisse'],
				'fontStyle'   => $face['style'],
				'fontDisplay' => 'swap',
				'src'         => array( 'file:./assets/fonts/' . $face['fichier'] ),
			);
		}

		if ( empty( $font_faces ) ) {
			continue;
		}

		$families[] = array(
			'slug'       => $slug,
			'name'       => $label,
			'fontFamily' => sprintf( '"%s", %s', $family, $replis[ $slug ] ),
			'fontFace'   => $font_faces,
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
	foreach ( subalcatel_font_files() as $faces ) {
		foreach ( $faces as $face ) {
			if ( ! file_exists( SUBALCATEL_DIR . '/assets/fonts/' . $face['fichier'] ) ) {
				$missing[] = $face['fichier'];
			}
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
