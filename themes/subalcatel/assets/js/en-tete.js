/**
 * En-tête compact au défilement.
 *
 * Pose `sub-header--compact` sur l'en-tête dès que la page a défilé. Le style
 * et les transitions vivent dans site.css ; ce script ne fait que basculer la
 * classe et réserver la place de l'en-tête.
 *
 * Réserver la place : l'enveloppe collante garde la hauteur de l'en-tête
 * déplié pendant que celui-ci rétrécit à l'intérieur. Sans cela, l'en-tête
 * perdait 30 à 60 px d'un coup, la page remontait d'autant sous le doigt et
 * l'animation paraissait sauter. La partie réservée est transparente et
 * laisse passer les clics (`pointer-events`, voir site.css).
 *
 * Deux seuils (hystérésis) : l'en-tête rétrécit au-delà de 80 px et ne
 * reprend sa taille qu'en revenant sous 20 px, pour ne pas osciller autour
 * d'un seuil unique.
 */
( function () {
	'use strict';

	var entete = document.querySelector( '.sub-header' );
	if ( ! entete ) {
		return;
	}

	var enveloppe = entete.parentElement;
	var compact = false;
	var enAttente = false;

	function reserver() {
		var etaitCompact = compact;
		entete.classList.add( 'sub-header--sans-transition' );
		if ( etaitCompact ) {
			entete.classList.remove( 'sub-header--compact' );
		}
		enveloppe.style.height = '';
		enveloppe.style.height = enveloppe.offsetHeight + 'px';
		if ( etaitCompact ) {
			entete.classList.add( 'sub-header--compact' );
		}
		// Forcer le calcul avant de rétablir les transitions.
		void entete.offsetHeight;
		entete.classList.remove( 'sub-header--sans-transition' );
	}

	function mettreAJour() {
		enAttente = false;
		var y = window.scrollY || window.pageYOffset;
		if ( ! compact && y > 80 ) {
			compact = true;
			entete.classList.add( 'sub-header--compact' );
		} else if ( compact && y < 20 ) {
			compact = false;
			entete.classList.remove( 'sub-header--compact' );
		}
	}

	window.addEventListener( 'scroll', function () {
		if ( ! enAttente ) {
			enAttente = true;
			window.requestAnimationFrame( mettreAJour );
		}
	}, { passive: true } );

	var minuteur;
	window.addEventListener( 'resize', function () {
		window.clearTimeout( minuteur );
		minuteur = window.setTimeout( reserver, 150 );
	} );

	enveloppe.classList.add( 'sub-entete-reserve' );
	reserver();
	// Les polices changent la hauteur du titre une fois chargées.
	if ( document.fonts && document.fonts.ready ) {
		document.fonts.ready.then( reserver );
	}
	mettreAJour();
} )();
