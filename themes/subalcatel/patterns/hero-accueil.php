<?php
/**
 * Title: Bandeau d'accueil
 * Slug: subalcatel/hero-accueil
 * Categories: subalcatel-vitrine
 * Description: Bandeau clair en deux colonnes : surtitre, accroche, deux boutons et infos pratiques à gauche, logo du club à droite.
 * Keywords: hero, accueil, bandeau, couverture
 *
 * Refonte d'octobre 2026, d'après la maquette « Subalcatel – Site web ». Le
 * poulpe détouré tient la place d'une photo : le bandeau est complet sans que
 * le club ait à fournir d'image. Pour en mettre une, remplacer l'image de la
 * colonne de droite depuis l'éditeur.
 *
 * Le fond est la brume claire de l'en-tête : les deux se lisent comme un seul
 * bloc, et la vague du bas (`.sub-hero--clair::after`) raccorde à la nacre de
 * la page.
 *
 * @package Subalcatel
 */

$subalcatel_logo = esc_url( get_theme_file_uri( 'assets/img/logo-accueil.png' ) );
?>
<!-- wp:group {"className":"sub-hero sub-hero--clair","align":"full","backgroundColor":"brume-claire","textColor":"abysse","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"0"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull sub-hero sub-hero--clair has-abysse-color has-brume-claire-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:0"><!-- wp:columns {"verticalAlignment":"center","align":"wide","style":{"spacing":{"blockGap":{"left":"var:preset|spacing|50"}}}} -->
<div class="wp-block-columns alignwide are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center","width":"55%","style":{"spacing":{"blockGap":"var:preset|spacing|30"}}} -->
<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:55%"><!-- wp:paragraph {"className":"is-style-sub-surtitre"} -->
<p class="is-style-sub-surtitre">Plongée loisir associative<span class="sub-surtitre__suite"> · FFESSM depuis 1974</span></p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1,"fontSize":"display"} -->
<h1 class="wp-block-heading has-display-font-size">Plongeons <mark style="background-color:rgba(0, 0, 0, 0)" class="has-inline-color has-lavande-color">ensemble.</mark></h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"ardoise","fontSize":"moyen","style":{"typography":{"lineHeight":"1.55"}}} -->
<p class="has-ardoise-color has-text-color has-moyen-font-size" style="line-height:1.55">Un club géré par ses adhérents, à Lannion. Débutant ou confirmé, du niveau 1 au niveau 4 : on vous forme et on vous emmène.</p>
<!-- /wp:paragraph -->

<!-- wp:group {"style":{"spacing":{"blockGap":"10px"}},"textColor":"abysse","fontSize":"corps","layout":{"type":"flex","orientation":"vertical"}} -->
<div class="wp-block-group has-abysse-color has-text-color has-corps-font-size"><!-- wp:paragraph {"className":"sub-info sub-info--horaire"} -->
<p class="sub-info sub-info--horaire"><strong>Piscine</strong> de novembre à mars, le mercredi</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"className":"sub-info sub-info--lieu"} -->
<p class="sub-info sub-info--lieu"><strong>Mer</strong> d'avril à octobre, le mercredi et le dimanche</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:buttons {"style":{"spacing":{"margin":{"top":"var:preset|spacing|20"},"blockGap":"12px"}}} -->
<div class="wp-block-buttons" style="margin-top:var(--wp--preset--spacing--20)"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/nous-rejoindre/">Rejoindre le club</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"center","width":"45%"} -->
<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:45%"><!-- wp:image {"sizeSlug":"full","linkDestination":"none","align":"center","className":"sub-hero__logo"} -->
<figure class="wp-block-image aligncenter size-full sub-hero__logo"><img src="<?php echo $subalcatel_logo; ?>" alt="Logo du club Sub Alcatel : un poulpe plongeur, masque et détendeur, dans un anneau de bulles" width="640" height="638"/></figure>
<!-- /wp:image --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group -->
