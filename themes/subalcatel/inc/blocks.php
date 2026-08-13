<?php
/**
 * Blocs et styles de blocs propres au thème.
 *
 * Tout ce qui est ici relève de la présentation. Le contrôle d'accès réel est
 * assuré côté serveur par l'extension subalcatel-club : masquer un lien n'est
 * jamais une autorisation.
 *
 * @package Subalcatel
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Styles de blocs proposés dans l'éditeur.
 *
 * Ils évitent aux rédacteurs de bricoler des couleurs à la main pour obtenir
 * une carte ou un encart d'alerte.
 */
function subalcatel_register_block_styles(): void {
	$styles = array(
		array( 'core/group', 'sub-carte', __( 'Carte', 'subalcatel' ) ),
		array( 'core/group', 'sub-panneau', __( 'Panneau applicatif', 'subalcatel' ) ),
		array( 'core/group', 'sub-alerte-info', __( 'Alerte — information', 'subalcatel' ) ),
		array( 'core/group', 'sub-alerte-attention', __( 'Alerte — action requise', 'subalcatel' ) ),
		array( 'core/group', 'sub-alerte-succes', __( 'Alerte — confirmation', 'subalcatel' ) ),
		array( 'core/columns', 'sub-cartes', __( 'Grille de cartes', 'subalcatel' ) ),
		array( 'core/paragraph', 'sub-chapeau', __( 'Chapeau', 'subalcatel' ) ),
		array( 'core/paragraph', 'sub-surtitre', __( 'Surtitre', 'subalcatel' ) ),
		array( 'core/list', 'sub-liste-cochee', __( 'Liste cochée', 'subalcatel' ) ),
		array( 'core/image', 'sub-image-nette', __( 'Angles droits', 'subalcatel' ) ),
	);

	foreach ( $styles as list( $block, $name, $label ) ) {
		register_block_style(
			$block,
			array(
				'name'  => $name,
				'label' => $label,
			)
		);
	}
}
add_action( 'init', 'subalcatel_register_block_styles' );

/**
 * Bloc « bouton de compte » de l'en-tête.
 *
 * Rendu côté serveur : un visiteur reçoit « Nous rejoindre / Connexion »,
 * un membre reçoit ses initiales et son prénom. Aucune donnée d'un autre
 * utilisateur n'est exposée.
 */
function subalcatel_register_account_block(): void {
	register_block_type(
		'subalcatel/compte',
		array(
			'api_version'     => 3,
			'title'           => __( 'Sub Alcatel — Bouton de compte', 'subalcatel' ),
			'category'        => 'design',
			'icon'            => 'admin-users',
			'description'     => __( "Affiche « Nous rejoindre / Connexion » à un visiteur, et le menu de compte à un membre connecté.", 'subalcatel' ),
			'supports'        => array(
				'html'      => false,
				'reusable'  => false,
				'multiple'  => false,
				'spacing'   => array( 'blockGap' => true ),
				'interactivity' => array( 'clientNavigation' => false ),
			),
			'attributes'      => array(
				'urlAdhesion'  => array(
					'type'    => 'string',
					'default' => '/nous-rejoindre/',
				),
				'urlConnexion' => array(
					'type'    => 'string',
					'default' => '/connexion/',
				),
				'urlEspace'    => array(
					'type'    => 'string',
					'default' => '/espace-membre/',
				),
			),
			'render_callback' => 'subalcatel_render_account_block',
		)
	);
}
add_action( 'init', 'subalcatel_register_account_block' );

/**
 * Adresse de connexion.
 *
 * Passe par la page du club si l'extension en propose une — elle porte le
 * design du site et garde le contexte. Sans extension, on retombe sur l'écran
 * natif : le thème doit rester utilisable seul.
 *
 * @param string $redirect_to Où revenir après connexion.
 * @return string
 */
function subalcatel_login_url( string $redirect_to = '' ): string {
	if ( class_exists( '\Subalcatel\Club\Frontend\LoginForm' ) ) {
		return \Subalcatel\Club\Frontend\LoginForm::url( home_url( $redirect_to ) );
	}

	return wp_login_url( $redirect_to );
}

/**
 * Rendu du bloc de compte.
 *
 * @param array $attributes Attributs du bloc.
 * @return string
 */
function subalcatel_render_account_block( array $attributes ): string {
	$wrapper = get_block_wrapper_attributes( array( 'class' => 'sub-compte' ) );

	if ( ! is_user_logged_in() ) {
		return sprintf(
			'<div %1$s><a class="sub-btn sub-btn--principal" href="%2$s">%3$s</a><a class="sub-btn sub-btn--fantome" href="%4$s">%5$s</a></div>',
			$wrapper,
			esc_url( $attributes['urlAdhesion'] ),
			esc_html__( 'Nous rejoindre', 'subalcatel' ),
			esc_url( subalcatel_login_url( $attributes['urlEspace'] ) ),
			esc_html__( 'Connexion', 'subalcatel' )
		);
	}

	$user     = wp_get_current_user();
	$prenom   = $user->first_name ? $user->first_name : $user->display_name;
	$initales = subalcatel_initials( $user->first_name, $user->last_name, $user->display_name );

	return sprintf(
		'<div %1$s><a class="sub-compte__lien" href="%2$s"><span class="sub-avatar" aria-hidden="true">%3$s</span><span class="sub-compte__nom">%4$s</span></a><a class="sub-btn sub-btn--fantome sub-btn--sm" href="%5$s">%6$s</a></div>',
		$wrapper,
		esc_url( $attributes['urlEspace'] ),
		esc_html( $initales ),
		esc_html( $prenom ),
		esc_url( wp_logout_url( home_url( '/' ) ) ),
		esc_html__( 'Déconnexion', 'subalcatel' )
	);
}

/**
 * Initiales affichées dans la pastille d'avatar.
 *
 * @param string $prenom  Prénom.
 * @param string $nom     Nom.
 * @param string $affiche Nom affiché, en repli.
 * @return string
 */
function subalcatel_initials( string $prenom, string $nom, string $affiche ): string {
	if ( '' !== $prenom || '' !== $nom ) {
		return mb_strtoupper( mb_substr( $prenom, 0, 1 ) . mb_substr( $nom, 0, 1 ) );
	}

	$mots = preg_split( '/\s+/', trim( $affiche ) ) ?: array();

	if ( count( $mots ) >= 2 ) {
		return mb_strtoupper( mb_substr( $mots[0], 0, 1 ) . mb_substr( $mots[1], 0, 1 ) );
	}

	return mb_strtoupper( mb_substr( $affiche, 0, 2 ) );
}

/**
 * Lien d'évitement, en tout premier dans le corps du document.
 */
function subalcatel_skip_link(): void {
	printf(
		'<a class="sub-skip-link" href="#sub-contenu">%s</a>',
		esc_html__( 'Aller au contenu principal', 'subalcatel' )
	);
}
add_action( 'wp_body_open', 'subalcatel_skip_link', 1 );

/**
 * Bloc « fil d'Ariane ».
 *
 * Les gabarits écrivaient chacun leur chemin en dur. Un lien devenait faux dès
 * qu'une page changeait de parent, et les gabarits génériques n'en avaient pas
 * du tout. Ce bloc le calcule depuis la hiérarchie réelle des pages.
 *
 * Il ne montre que ce que la personne peut ouvrir : un fil d'Ariane qui affiche
 * un parent inaccessible propose un lien vers une porte fermée. Le contrôle est
 * délégué à l'extension, seule à connaître les règles d'accès.
 */
function subalcatel_register_breadcrumb_block(): void {
	register_block_type(
		'subalcatel/fil-ariane',
		array(
			'api_version'     => 3,
			'title'           => __( 'Sub Alcatel — Fil d’Ariane', 'subalcatel' ),
			'category'        => 'design',
			'icon'            => 'menu-alt',
			'description'     => __( 'Chemin de navigation calculé depuis la hiérarchie des pages.', 'subalcatel' ),
			'supports'        => array(
				'html'      => false,
				'reusable'  => false,
				'multiple'  => false,
				'spacing'   => array( 'margin' => true ),
			),
			'render_callback' => 'subalcatel_render_breadcrumb',
		)
	);
}
add_action( 'init', 'subalcatel_register_breadcrumb_block' );

/**
 * Rendu du fil d'Ariane.
 *
 * @return string Chaîne vide sur la page d'accueil : un fil d'Ariane qui ne
 *                contient que « Accueil » n'apprend rien à personne.
 */
function subalcatel_render_breadcrumb(): string {
	if ( is_front_page() ) {
		return '';
	}

	$trail = array(
		array(
			'label' => __( 'Accueil', 'subalcatel' ),
			'url'   => home_url( '/' ),
		),
	);

	if ( is_singular() ) {
		$trail = array_merge( $trail, subalcatel_breadcrumb_ancestors( get_queried_object_id() ) );
		$trail[] = array(
			'label' => get_the_title(),
			'url'   => '',
		);
	} elseif ( is_home() ) {
		$trail[] = array(
			'label' => get_the_title( (int) get_option( 'page_for_posts' ) ),
			'url'   => '',
		);
	} elseif ( is_archive() ) {
		$trail[] = array(
			'label' => wp_strip_all_tags( get_the_archive_title() ),
			'url'   => '',
		);
	} elseif ( is_search() ) {
		$trail[] = array(
			/* translators: %s: terme recherché. */
			'label' => sprintf( __( 'Recherche : %s', 'subalcatel' ), get_search_query() ),
			'url'   => '',
		);
	} else {
		return '';
	}

	$items = array();

	foreach ( $trail as $step ) {
		$items[] = '' === $step['url']
			? sprintf( '<span aria-current="page">%s</span>', esc_html( $step['label'] ) )
			: sprintf( '<a href="%s">%s</a>', esc_url( $step['url'] ), esc_html( $step['label'] ) );
	}

	// `get_block_wrapper_attributes()` suppose un bloc en cours de rendu et
	// émet un avertissement si on l'appelle autrement — ce que fait tout code
	// qui invoque directement le callback, à commencer par les tests.
	$wrapper = \WP_Block_Supports::$block_to_render
		? get_block_wrapper_attributes()
		: 'class="wp-block-subalcatel-fil-ariane"';

	return sprintf(
		'<nav %1$s aria-label="%2$s"><p class="sub-fil-ariane">%3$s</p></nav>',
		$wrapper,
		esc_attr__( 'Fil d’Ariane', 'subalcatel' ),
		implode( ' <span aria-hidden="true">›</span> ', $items )
	);
}

/**
 * Ascendants d'une page, du plus haut au plus proche.
 *
 * @param int $post_id Page courante.
 * @return array<int, array{label: string, url: string}>
 */
function subalcatel_breadcrumb_ancestors( int $post_id ): array {
	$ancestors = array_reverse( get_post_ancestors( $post_id ) );
	$steps     = array();

	foreach ( $ancestors as $ancestor_id ) {
		// L'extension porte les règles d'accès. Sans elle — thème utilisé seul —
		// on affiche le parent : ne rien masquer vaut mieux que masquer au hasard.
		if ( class_exists( '\Subalcatel\Club\Content\Visibility' )
			&& ! \Subalcatel\Club\Content\Visibility::mayRead( (int) $ancestor_id ) ) {
			continue;
		}

		$steps[] = array(
			'label' => get_the_title( (int) $ancestor_id ),
			'url'   => (string) get_permalink( (int) $ancestor_id ),
		);
	}

	return $steps;
}

/**
 * Repli de vignette sur les cartes d'article.
 *
 * Une carte sans image ne se contente pas d'être terne : elle casse la grille.
 * Les cartes voisines commencent par une photo de 200 px, la sienne commence
 * par la catégorie, et l'œil ne trouve plus de ligne de lecture commune.
 *
 * Le repli n'invente donc pas une photo — il occupe la place, avec un aplat de
 * la charte. Les articles de service du club (« Permanence gonflage »,
 * « Où envoyer vos chèques ») n'ont jamais eu d'illustration et n'en auront
 * pas ; c'est un état durable, pas un trou à combler plus tard.
 *
 * Le repli ne s'applique qu'aux blocs marqués `sub-vignette` dans les gabarits,
 * c'est-à-dire aux cartes. Sur la page d'un article, un grand aplat décoratif
 * en tête de page ne vaudrait pas mieux que rien.
 *
 * @param string   $block_content Rendu du bloc, vide si l'article n'a pas d'image.
 * @param array    $block         Bloc analysé.
 * @param WP_Block $instance      Instance, porteuse du contexte de boucle.
 * @return string
 */
function subalcatel_featured_image_fallback( string $block_content, array $block, WP_Block $instance ): string {
	if ( '' !== trim( $block_content ) ) {
		return $block_content;
	}

	$classes = (string) ( $block['attrs']['className'] ?? '' );

	if ( ! in_array( 'sub-vignette', preg_split( '/\s+/', $classes ) ?: array(), true ) ) {
		return $block_content;
	}

	$post_id = (int) ( $instance->context['postId'] ?? get_the_ID() );

	if ( $post_id <= 0 ) {
		return $block_content;
	}

	// La hauteur du bloc, pour que le repli fasse exactement la taille des
	// vignettes voisines. Sans elle, la feuille de style retombe sur le 3/2.
	$height = (string) ( $block['attrs']['height'] ?? '' );
	$style  = '' === $height ? '' : sprintf( ' style="height:%s"', esc_attr( $height ) );

	return sprintf(
		'<div class="wp-block-post-featured-image sub-vignette sub-vignette--repli sub-vignette--t%1$d"%2$s>'
			. '<a href="%3$s" tabindex="-1" aria-hidden="true"></a></div>',
		subalcatel_placeholder_tint( $post_id ),
		$style,
		esc_url( (string) get_permalink( $post_id ) )
	);
}
add_filter( 'render_block_core/post-featured-image', 'subalcatel_featured_image_fallback', 10, 3 );

/**
 * Teinte du repli, tirée de la catégorie principale.
 *
 * Quatre aplats identiques côte à côte ressemblent à une panne d'affichage.
 * On les fait varier — mais pas au hasard : deux articles de la même rubrique
 * partagent leur teinte, ce qui donne à la grille une cohérence lisible plutôt
 * qu'un damier. Sans catégorie, l'identifiant de l'article suffit à répartir.
 *
 * @param int $post_id Article.
 * @return int Indice de teinte, de 0 à 3.
 */
function subalcatel_placeholder_tint( int $post_id ): int {
	$terms = get_the_terms( $post_id, 'category' );
	$key   = ( is_array( $terms ) && array() !== $terms ) ? (int) $terms[0]->term_id : $post_id;

	return $key % 4;
}

/**
 * Image mise en avant masquée quand l'article la contient déjà.
 *
 * Les articles repris de Joomla portent leurs photos dans le corps du texte :
 * l'ancien site n'avait pas d'image mise en avant. Celle que la reprise a
 * choisie sort donc de ce même corps, et le gabarit d'article l'afficherait une
 * première fois en tête, puis une seconde fois dans le texte, à quelques lignes
 * d'écart.
 *
 * On masque la première. Le choix est purement d'affichage : la métadonnée
 * reste posée, les cartes et les partages sociaux continuent de s'en servir, et
 * la règle se désamorce d'elle-même le jour où un rédacteur retire la photo du
 * corps de l'article.
 *
 * Priorité 20 : après le repli, qu'un rendu vidé ici ne doit pas déclencher.
 *
 * @param string   $block_content Rendu du bloc.
 * @param array    $block         Bloc analysé.
 * @param WP_Block $instance      Instance, porteuse du contexte de boucle.
 * @return string
 */
function subalcatel_hide_redundant_featured_image( string $block_content, array $block, WP_Block $instance ): string {
	if ( '' === trim( $block_content ) || ! is_singular() ) {
		return $block_content;
	}

	$post_id = (int) ( $instance->context['postId'] ?? get_the_ID() );

	// Seulement l'article consulté : les cartes « À lire aussi » de la même page
	// pointent vers d'autres articles, dont le corps n'est pas affiché ici.
	if ( $post_id !== get_queried_object_id() ) {
		return $block_content;
	}

	$thumbnail_id = get_post_thumbnail_id( $post_id );

	if ( ! $thumbnail_id ) {
		return $block_content;
	}

	$content = (string) get_post_field( 'post_content', $post_id );

	// La limite de mot est indispensable : sans elle, le média 28 se croirait
	// présent dans toute page citant le média 2811.
	return preg_match( '/\bwp-image-' . $thumbnail_id . '\b/', $content )
		? ''
		: $block_content;
}
add_filter( 'render_block_core/post-featured-image', 'subalcatel_hide_redundant_featured_image', 20, 3 );
