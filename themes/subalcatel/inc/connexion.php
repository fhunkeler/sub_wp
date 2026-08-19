<?php
/**
 * Habillage de l'écran de connexion de WordPress.
 *
 * `wp-login.php` n'est pas rendu par le thème : il ignore theme.json, les
 * gabarits et site.css. Laissé nu, il affiche le logo de wordpress.org et un
 * lien « ← Retour sur Sub Alcatel » dans une typographie qui n'est pas celle du
 * site.
 *
 * Ce n'est pas qu'une question d'apparence. À la mise en service, aucun mot de
 * passe n'est repris : chaque membre reçoit un courriel de réinitialisation qui
 * ouvre `wp-login.php?action=rp`. Cet écran est donc le premier contact de tout
 * le club avec le nouveau site — et le seul que personne ne peut éviter.
 *
 * La page de connexion courante reste celle du club ([subalcatel_connexion],
 * gabarit page-connexion) ; cette feuille traite ce que l'extension ne peut pas
 * remplacer : réinitialisation, mot de passe oublié, écrans d'erreur du cœur.
 *
 * @package Subalcatel
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feuille de style de l'écran de connexion.
 *
 * Les @font-face sont ajoutés en ligne plutôt qu'écrits dans le fichier : sans
 * ce contrôle, un thème déployé sans ses polices déclencherait deux 404 par
 * affichage. Même précaution que dans inc/fonts.php.
 */
function subalcatel_login_styles(): void {
	wp_enqueue_style(
		'subalcatel-login',
		SUBALCATEL_URI . '/assets/css/login.css',
		array(),
		subalcatel_asset_version( 'assets/css/login.css' )
	);

	$declarations = '';

	foreach ( subalcatel_font_files() as $slug => $fichier ) {
		if ( ! file_exists( SUBALCATEL_DIR . '/assets/fonts/' . $fichier ) ) {
			continue;
		}

		$declarations .= sprintf(
			"@font-face{font-family:%s;font-weight:400 700;font-style:normal;font-display:swap;src:url(%s) format('woff2');}",
			'titre' === $slug ? 'Outfit' : 'Inter',
			esc_url( SUBALCATEL_URI . '/assets/fonts/' . $fichier )
		);
	}

	if ( '' !== $declarations ) {
		wp_add_inline_style( 'subalcatel-login', $declarations );
	}
}
add_action( 'login_enqueue_scripts', 'subalcatel_login_styles' );

/**
 * Le logo renvoie au site du club, pas à wordpress.org.
 *
 * @return string
 */
function subalcatel_login_header_url(): string {
	return home_url( '/' );
}
add_filter( 'login_headerurl', 'subalcatel_login_header_url' );

/**
 * Texte alternatif du logo : le nom du club.
 *
 * @return string
 */
function subalcatel_login_header_text(): string {
	return get_bloginfo( 'name', 'display' );
}
add_filter( 'login_headertext', 'subalcatel_login_header_text' );


/**
 * Lien de retour au site, sous le formulaire, réécrit aux mots du club.
 *
 * « ← Retour sur Sub Alcatel » laisse croire qu'on quitte une application
 * tierce. Ce n'en est pas une : c'est le même site.
 *
 * @param string $html Lien produit par le cœur.
 * @return string
 */
function subalcatel_login_site_link( string $html ): string {
	return sprintf(
		'<a href="%s">%s</a>',
		esc_url( home_url( '/' ) ),
		esc_html__( 'Retour au site du club', 'subalcatel' )
	);
}
add_filter( 'login_site_html_link', 'subalcatel_login_site_link' );
