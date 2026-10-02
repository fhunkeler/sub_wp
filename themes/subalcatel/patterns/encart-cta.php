<?php
/**
 * Title: Encart d'appel à l'action
 * Slug: subalcatel/encart-cta
 * Categories: subalcatel-adhesion
 * Description: Carte lavande arrondie de fin de page invitant à adhérer. À mettre à jour à chaque campagne.
 * Keywords: cta, adhésion, campagne, bandeau
 *
 * Refonte d'octobre 2026 : une carte posée dans la largeur « large » plutôt
 * qu'un bandeau bord à bord, dans la lavande du poulpe. Blanc sur lavande :
 * 6,1:1 ; le #e4e7fa du texte courant : 5,0:1.
 *
 * @package Subalcatel
 */

?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|40","bottom":"var:preset|spacing|60"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--60)"><!-- wp:group {"align":"wide","className":"sub-cta-carte","backgroundColor":"lavande","textColor":"blanc","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50","right":"var:preset|spacing|50"}}},"layout":{"type":"default"}} -->
<div class="wp-block-group alignwide sub-cta-carte has-blanc-color has-lavande-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)"><!-- wp:columns {"verticalAlignment":"center","style":{"spacing":{"blockGap":{"left":"var:preset|spacing|50"}}}} -->
<div class="wp-block-columns are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center","width":"62%","style":{"spacing":{"blockGap":"var:preset|spacing|20"}}} -->
<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:62%"><!-- wp:heading {"textColor":"blanc"} -->
<h2 class="wp-block-heading has-blanc-color has-text-color">La campagne d'adhésion est ouverte</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Du 15 septembre 2026 au 31 décembre 2027. Créez votre compte, choisissez votre formule et réglez par chèque ou en ligne.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"center","width":"38%"} -->
<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:38%"><!-- wp:buttons {"layout":{"type":"flex","justifyContent":"right","orientation":"horizontal"},"style":{"spacing":{"blockGap":"12px"}}} -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/nous-rejoindre/adherer/">Adhérer maintenant</a></div>
<!-- /wp:button -->

<!-- wp:button {"backgroundColor":"blanc","textColor":"abysse"} -->
<div class="wp-block-button"><a class="wp-block-button__link has-abysse-color has-blanc-background-color has-text-color has-background wp-element-button" href="/nous-rejoindre/bapteme/">Faire un baptême</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
