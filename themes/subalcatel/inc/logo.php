<?php
/**
 * Logo du site.
 *
 * Le bloc `core/site-logo` ne dessine rien tant qu'aucune image n'a été
 * téléversée : render_block_core_site_logo() s'arrête net quand
 * get_custom_logo() rend une chaîne vide. L'en-tête et le pied de page du thème
 * portent pourtant ce bloc — livrés tels quels, ils affichaient le titre du
 * club sans sa marque, et le bureau devait passer par la médiathèque avant que
 * le site ressemble à quelque chose.
 *
 * Le thème fournit donc un logo par défaut : le poulpe casqué, cadré sur son
 * anneau. C'est le même dessin que l'icône du site (voir inc/icone.php), ce qui
 * fait tenir onglet, en-tête, pied de page et écran de connexion sur une seule
 * image.
 *
 * Le ruban « Subalcatel » du logo complet est écarté ici : dans l'en-tête, le
 * bloc « Titre du site » écrit déjà le nom à côté, et à 40 px le ruban n'est
 * plus qu'une barre floue. `assets/img/logo-complet.png` le conserve pour les
 * usages où la marque est seule — impression, réseaux, médiathèque.
 *
 * Dès qu'un logo est téléversé (Apparence → Éditeur → Styles, ou Réglages
 * généraux), le cœur reprend la main entièrement : ce filtre reçoit un rendu
 * non vide et le laisse passer sans y toucher.
 *
 * @package Subalcatel
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Côté de l'image livrée, en pixels.
 *
 * L'en-tête l'affiche à 40 px et le pied de page à 36 px : 256 px couvre les
 * écrans à forte densité jusqu'à ×6 sans peser plus de 25 ko.
 */
const SUBALCATEL_LOGO_SOURCE = 256;

/**
 * Logo par défaut du bloc « Logo du site ».
 *
 * On reconstruit ici le balisage que le cœur produirait avec une pièce jointe :
 * même enveloppe `wp-block-site-logo`, même classe `custom-logo-link`, même
 * `rel="home"`. Le passage par le rendu du bloc plutôt que par le filtre
 * `get_custom_logo` est ce qui permet d'honorer l'attribut `width` : le cœur ne
 * l'applique qu'en filtrant `wp_get_attachment_image_src`, hors de portée pour
 * une image de thème qui n'est pas une pièce jointe.
 *
 * @param string $html  Rendu du bloc, vide sans logo téléversé.
 * @param array  $block Bloc analysé, porteur de ses attributs.
 * @return string
 */
function subalcatel_default_site_logo( string $html, array $block ): string {
	if ( '' !== trim( $html ) ) {
		return $html;
	}

	$largeur = (int) ( $block['attrs']['width'] ?? 0 );
	$taille  = $largeur > 0 ? $largeur : SUBALCATEL_LOGO_SOURCE;

	// `isLink` a `true` pour valeur par défaut dans block.json : un bloc qui ne
	// porte pas l'attribut veut donc bien un lien vers l'accueil.
	$lie = false !== ( $block['attrs']['isLink'] ?? true );

	/*
	 * Le texte alternatif dépend du lien, et c'est la seule règle qui vaille :
	 *
	 * — logo lié : il est le seul contenu de son <a>, et un lien sans nom
	 *   accessible est un échec net (WCAG 4.1.2). L'alternative porte donc le
	 *   nom du club ;
	 * — logo non lié : il voisine le bloc « Titre du site », qui écrit déjà ce
	 *   nom. Le répéter ferait entendre « Sub Alcatel » deux fois de suite ;
	 *   l'image est alors décorative (WCAG 1.1.1) et son alternative est vide.
	 */
	$image = sprintf(
		'<img src="%1$s" class="custom-logo" width="%2$d" height="%2$d" alt="%3$s" decoding="async" />',
		esc_url( SUBALCATEL_URI . '/assets/img/logo.png' ),
		$taille,
		$lie ? esc_attr( get_bloginfo( 'name', 'display' ) ) : ''
	);

	if ( $lie ) {
		// L'`aria-label` remplace le nom accessible du lien : il doit donc
		// reprendre le nom du club, que l'alternative de l'image ne portera
		// plus. Le cœur, lui, se contente de « (Home link, opens in a new
		// tab) » et perd le nom du site au passage.
		$cible = '_blank' === ( $block['attrs']['linkTarget'] ?? '_self' )
			? sprintf(
				' target="_blank" aria-label="%s"',
				esc_attr(
					sprintf(
						/* translators: %s : nom du site. */
						__( '%s — accueil, ouvre un nouvel onglet', 'subalcatel' ),
						get_bloginfo( 'name', 'display' )
					)
				)
			)
			: '';

		$image = sprintf(
			'<a href="%s" class="custom-logo-link" rel="home"%s>%s</a>',
			esc_url( home_url( '/' ) ),
			$cible,
			$image
		);
	}

	// `is-default-size` est la classe que le cœur pose quand aucune largeur
	// n'est demandée ; la feuille du bloc s'en sert pour retomber sur 120 px.
	$classes = 'wp-block-site-logo' . ( $largeur > 0 ? '' : ' is-default-size' );

	return sprintf( '<div class="%s">%s</div>', esc_attr( $classes ), $image );
}
add_filter( 'render_block_core/site-logo', 'subalcatel_default_site_logo', 10, 2 );
