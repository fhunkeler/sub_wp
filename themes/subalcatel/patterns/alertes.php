<?php
/**
 * Title: Encarts d'alerte (les trois variantes)
 * Slug: subalcatel/alertes
 * Categories: subalcatel-editorial
 * Description: Information, action requise et confirmation. Insérer, garder celle qui sert, supprimer les autres.
 * Keywords: alerte, information, avertissement, encart
 *
 * Chaque encart porte son sens dans son texte, pas seulement dans sa couleur :
 * une personne daltonienne ou un lecteur d'écran doivent comprendre la même
 * chose (WCAG 1.4.1).
 *
 * @package Subalcatel
 */

?>
<!-- wp:group {"className":"is-style-sub-alerte-info","layout":{"type":"constrained"}} -->
<div class="wp-block-group is-style-sub-alerte-info"><!-- wp:paragraph -->
<p><strong>Information —</strong> la campagne d'adhésion ouvre le 15 septembre. Les dossiers déposés avant cette date sont conservés en brouillon.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:group {"className":"is-style-sub-alerte-attention","layout":{"type":"constrained"}} -->
<div class="wp-block-group is-style-sub-alerte-attention"><!-- wp:paragraph -->
<p><strong>Action requise —</strong> votre certificat médical expire dans 24 jours. Sans certificat à jour, vos inscriptions aux sorties seront bloquées.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:group {"className":"is-style-sub-alerte-succes","layout":{"type":"constrained"}} -->
<div class="wp-block-group is-style-sub-alerte-succes"><!-- wp:paragraph -->
<p><strong>Confirmation —</strong> votre inscription à la sortie du 12 septembre est enregistrée. Un courriel de confirmation vous a été envoyé.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
