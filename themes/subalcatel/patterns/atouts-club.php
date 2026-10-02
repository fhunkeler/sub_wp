<?php
/**
 * Title: Le club en bref (trois atouts)
 * Slug: subalcatel/atouts-club
 * Categories: subalcatel-vitrine
 * Description: Trois cartes à pastille d'icône : club associatif, plongée pour tous, formations reconnues.
 * Keywords: atouts, club, présentation, cartes
 *
 * Le titre « Le club en bref » est masqué à l'écran mais lu par les lecteurs
 * d'écran : sans lui, les trois H3 suivaient directement le H1 du bandeau,
 * un saut de niveau que le RGAA (critère 9.1) relève.
 *
 * Les pastilles sont dessinées par la feuille de style (`.sub-atout--club`,
 * `--tous`, `--formation`) : le rédacteur ne manipule que du texte, et
 * l'icône ne peut pas être supprimée par erreur.
 *
 * @package Subalcatel
 */

?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|60"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--60)"><!-- wp:heading {"className":"sub-visuellement-masque"} -->
<h2 class="wp-block-heading sub-visuellement-masque">Le club en bref</h2>
<!-- /wp:heading -->

<!-- wp:columns {"align":"wide","className":"is-style-sub-cartes"} -->
<div class="wp-block-columns alignwide is-style-sub-cartes"><!-- wp:column {"className":"is-style-sub-carte sub-atout sub-atout--club","style":{"spacing":{"blockGap":"12px"}}} -->
<div class="wp-block-column is-style-sub-carte sub-atout sub-atout--club"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Un club associatif</h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"ardoise"} -->
<p class="has-ardoise-color has-text-color">Géré par ses adhérents depuis 1974, encadré par des moniteurs fédéraux bénévoles. Chacun y trouve sa place.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column {"className":"is-style-sub-carte sub-atout sub-atout--tous","style":{"spacing":{"blockGap":"12px"}}} -->
<div class="wp-block-column is-style-sub-carte sub-atout sub-atout--tous"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">La plongée pour tous</h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"ardoise"} -->
<p class="has-ardoise-color has-text-color">Du baptême découverte aux plongeurs confirmés, et la nage avec palmes pour qui préfère rester en surface.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column {"className":"is-style-sub-carte sub-atout sub-atout--formation","style":{"spacing":{"blockGap":"12px"}}} -->
<div class="wp-block-column is-style-sub-carte sub-atout sub-atout--formation"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Des formations reconnues</h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"ardoise"} -->
<p class="has-ardoise-color has-text-color">Brevets FFESSM du niveau 1 au niveau 4, préparés en piscine puis validés en milieu naturel.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group -->
