<?php
/**
 * Title: À la une — dernière actualité
 * Slug: subalcatel/une-actualite
 * Categories: subalcatel-vitrine
 * Description: La dernière actualité publiée, en encart large sous le bandeau d'accueil.
 * Keywords: actualité, une, encart, accueil
 *
 * Le site provisoire ouvrait sur une carte datée — « Venez nous rencontrer,
 * forum des associations » — posée juste sous le bandeau. C'est ce qu'un
 * visiteur cherche en arrivant : la preuve que le club vit maintenant, et pas
 * seulement qu'il existe depuis 1974.
 *
 * L'encart reprend cette place, mais pas sa méthode : rien n'y est écrit en
 * dur. Il affiche la dernière actualité publiée, et se périme donc tout seul.
 * Une date figée dans un gabarit reste juste une semaine, puis ment.
 *
 * La grille d'actualités plus bas décale sa requête d'un cran (`offset`), sans
 * quoi l'article mis en avant ici s'afficherait deux fois sur la même page.
 *
 * @package Subalcatel
 */

?>
<!-- wp:group {"align":"full","backgroundColor":"ecume","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-ecume-background-color has-background" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)"><!-- wp:query {"queryId":2,"query":{"perPage":1,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","inherit":false},"align":"wide","layout":{"type":"default"}} -->
<div class="wp-block-query alignwide"><!-- wp:post-template -->
<!-- wp:columns {"className":"sub-une"} -->
<div class="wp-block-columns sub-une"><!-- wp:column {"width":"42%"} -->
<div class="wp-block-column" style="flex-basis:42%"><!-- wp:post-featured-image {"isLink":true,"sizeSlug":"large","className":"sub-vignette"} /--></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"center","width":"58%"} -->
<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:58%"><!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"default"}} -->
<div class="wp-block-group"><!-- wp:paragraph {"className":"is-style-sub-surtitre"} -->
<p class="is-style-sub-surtitre">◆ À la une</p>
<!-- /wp:paragraph -->

<!-- wp:group {"style":{"spacing":{"blockGap":"10px"}},"layout":{"type":"flex","flexWrap":"wrap"}} -->
<div class="wp-block-group"><!-- wp:post-terms {"term":"category"} /-->

<!-- wp:post-date {"format":"j F Y"} /--></div>
<!-- /wp:group -->

<!-- wp:post-title {"isLink":true,"level":2,"fontSize":"titre-2"} /-->

<!-- wp:post-excerpt {"excerptLength":38} /-->

<!-- wp:read-more {"content":"Lire l’article →","fontSize":"petit"} /--></div>
<!-- /wp:group --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
<!-- /wp:post-template -->

<!-- wp:query-no-results -->
<!-- wp:paragraph -->
<p>Aucune actualité publiée pour le moment.</p>
<!-- /wp:paragraph -->
<!-- /wp:query-no-results --></div>
<!-- /wp:query --></div>
<!-- /wp:group -->
