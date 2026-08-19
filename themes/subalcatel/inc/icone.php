<?php
/**
 * Icône du site (favicon).
 *
 * Sans réglage, WordPress renvoie /favicon.ico vers son propre logo — le « W »
 * bleu de wordpress.org s'affichait donc dans l'onglet du club. Voir
 * do_favicon() dans wp-includes/functions.php.
 *
 * Le thème fournit donc une icône par défaut : l'hippocampe de la charte, posé
 * sur un carré arrondi abysse pour qu'il tienne à 32 px, où un trait sur fond
 * transparent disparaît.
 *
 * Le mécanisme passe par le filtre `get_site_icon_url` plutôt que par des
 * balises écrites à la main : has_site_icon() devient vrai, et le cœur produit
 * alors lui-même le <link rel="icon">, l'icône Apple, la tuile Windows et la
 * redirection de /favicon.ico. Un seul point d'entrée, aucune balise en double.
 *
 * Le téléversement d'une icône dans l'administration reprend la main à tout
 * moment : le filtre s'efface dès que l'option `site_icon` est renseignée.
 *
 * @package Subalcatel
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tailles réellement présentes dans assets/img/, en pixels.
 *
 * Le cœur demande 32, 180, 192, 270 et 512 ; on les fournit toutes plutôt que
 * de laisser le navigateur réduire une seule image.
 */
const SUBALCATEL_ICON_SIZES = array( 32, 180, 192, 270, 512 );

/**
 * Icône par défaut, tant que le bureau n'en a pas téléversé une.
 *
 * @param string $url  Adresse trouvée par le cœur (vide sans option `site_icon`).
 * @param int    $size Taille demandée, en pixels.
 * @return string
 */
function subalcatel_default_site_icon( string $url, int $size ): string {
	// Une icône choisie par le club prime toujours sur celle du thème.
	if ( 0 !== (int) get_option( 'site_icon' ) ) {
		return $url;
	}

	$fichier = SUBALCATEL_ICON_SIZES[ count( SUBALCATEL_ICON_SIZES ) - 1 ];

	foreach ( SUBALCATEL_ICON_SIZES as $disponible ) {
		if ( $disponible >= $size ) {
			$fichier = $disponible;
			break;
		}
	}

	return SUBALCATEL_URI . '/assets/img/favicon-' . $fichier . '.png';
}
add_filter( 'get_site_icon_url', 'subalcatel_default_site_icon', 10, 2 );

/**
 * Complète les balises produites par wp_site_icon().
 *
 * Le SVG est déclaré en premier : les navigateurs qui le comprennent le
 * préfèrent, et il reste net sur les écrans à forte densité pour 634 octets.
 * `theme-color` teinte la barre d'adresse mobile aux couleurs du club.
 *
 * @param string[] $balises Balises calculées par le cœur.
 * @return string[]
 */
function subalcatel_site_icon_meta_tags( array $balises ): array {
	if ( 0 !== (int) get_option( 'site_icon' ) ) {
		return $balises;
	}

	array_unshift(
		$balises,
		sprintf(
			'<link rel="icon" href="%s" type="image/svg+xml" />',
			esc_url( SUBALCATEL_URI . '/assets/img/favicon.svg' )
		)
	);

	$balises[] = '<meta name="theme-color" content="#041E30" />';

	return $balises;
}
add_filter( 'site_icon_meta_tags', 'subalcatel_site_icon_meta_tags' );
