/**
 * Petits agréments d'interface.
 *
 * Enveloppe la flèche finale des liens (« En savoir plus → ») dans un
 * <span class="sub-fleche"> pour qu'elle puisse glisser au survol (site.css,
 * section 23). La flèche, purement décorative, est masquée aux lecteurs
 * d'écran : ils lisent « En savoir plus » et non « En savoir plus flèche droite ».
 */
( function () {
	'use strict';

	document.querySelectorAll( 'a' ).forEach( function ( lien ) {
		var dernier = lien.lastChild;
		if ( ! dernier || dernier.nodeType !== 3 ) {
			return;
		}
		var texte = dernier.nodeValue;
		var m = texte.match( /\s*→\s*$/ );
		if ( ! m ) {
			return;
		}
		dernier.nodeValue = texte.slice( 0, m.index ) + ' ';
		var fleche = document.createElement( 'span' );
		fleche.className = 'sub-fleche';
		fleche.setAttribute( 'aria-hidden', 'true' );
		fleche.textContent = '→';
		lien.appendChild( fleche );
	} );
} )();
