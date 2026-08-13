<?php
/**
 * Title: Bandeau d'accueil
 * Slug: subalcatel/hero-accueil
 * Categories: subalcatel-vitrine
 * Description: Bandeau plein écran avec surtitre, accroche et deux boutons. Fonctionne avec ou sans photo de fond.
 * Keywords: hero, accueil, bandeau, couverture
 *
 * Le dégradé « abysses » sert de fond par défaut : le bandeau est présentable
 * avant même que le club ait fourni sa photo. Pour ajouter une photo, remplacer
 * le groupe par un bloc Bannière et poser le dégradé « voile sombre » par-dessus,
 * faute de quoi le texte blanc devient illisible sur les zones claires.
 *
 * @package Subalcatel
 */

?>
<!-- wp:group {"className":"sub-hero","align":"full","gradient":"abysses","textColor":"blanc","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull sub-hero has-blanc-color has-abysses-gradient-background has-text-color has-background" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)"><!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"},"dimensions":{"minHeight":""}},"layout":{"type":"constrained","contentSize":"660px","justifyContent":"left"}} -->
<div class="wp-block-group"><!-- wp:paragraph {"className":"is-style-sub-surtitre"} -->
<p class="is-style-sub-surtitre">◆ Club affilié FFESSM depuis 1974</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1,"fontSize":"display","textColor":"blanc"} -->
<h1 class="wp-block-heading has-blanc-color has-text-color has-display-font-size">La plongée, en club, sans se prendre au sérieux</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"color":{"text":"#cfe2ec"},"typography":{"lineHeight":"1.55"}},"fontSize":"moyen"} -->
<p class="has-text-color has-moyen-font-size" style="color:#cfe2ec;line-height:1.55">Piscine toute l'année, sorties en mer d'avril à octobre, formations du niveau 1 au niveau 4. Débutant ou confirmé, on vous forme et on vous emmène.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"style":{"spacing":{"margin":{"top":"var:preset|spacing|30"},"blockGap":"14px"}}} -->
<div class="wp-block-buttons" style="margin-top:var(--wp--preset--spacing--30)"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/nous-rejoindre/">Rejoindre le club</a></div>
<!-- /wp:button -->

<!-- wp:button {"className":"is-style-outline","style":{"color":{"background":"transparent","text":"#ffffff"},"border":{"width":"1.5px","color":"#ffffff66"}}} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link has-text-color has-background has-border-color wp-element-button" style="border-color:#ffffff66;border-width:1.5px;color:#ffffff;background-color:transparent" href="/nous-rejoindre/bapteme/">Essayer : le baptême découverte</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
