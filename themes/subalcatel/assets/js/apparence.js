/**
 * Sélecteur d'apparence : Système, Clair, Sombre.
 *
 * Le script de tête (inc/apparence.php) a déjà posé `data-theme` avant
 * l'affichage ; celui-ci rend les sélecteurs actifs, enregistre le choix et
 * suit la préférence du système quand « Système » est retenu.
 */
( function () {
	'use strict';

	var CLE = 'sub-apparence';
	var ORDRE = [ 'systeme', 'clair', 'sombre' ];
	var racine = document.documentElement;
	var media = window.matchMedia ? window.matchMedia( '(prefers-color-scheme: dark)' ) : null;

	function lire() {
		try {
			return window.localStorage.getItem( CLE ) || 'systeme';
		} catch ( e ) {
			return 'systeme';
		}
	}

	function appliquer( choix ) {
		var sombre = choix === 'sombre' || ( choix !== 'clair' && media && media.matches );
		racine.setAttribute( 'data-theme', sombre ? 'dark' : 'light' );
		racine.setAttribute( 'data-apparence', choix );
		document.querySelectorAll( '.sub-apparence__choix' ).forEach( function ( bouton ) {
			bouton.setAttribute( 'aria-pressed', bouton.dataset.apparence === choix ? 'true' : 'false' );
		} );
		document.querySelectorAll( '.sub-apparence-bascule' ).forEach( function ( bouton ) {
			var libelles = {};
			try {
				libelles = JSON.parse( bouton.dataset.libelles || '{}' );
			} catch ( e ) {}
			var suivant = ORDRE[ ( ORDRE.indexOf( choix ) + 1 ) % ORDRE.length ];
			var texte = 'Apparence : ' + ( libelles[ choix ] || choix ) + '. Passer à : ' + ( libelles[ suivant ] || suivant );
			bouton.dataset.apparence = choix;
			bouton.setAttribute( 'title', texte );
			bouton.querySelector( '.sub-apparence-bascule__texte' ).textContent = texte;
		} );
	}

	function choisir( choix ) {
		try {
			if ( choix === 'systeme' ) {
				window.localStorage.removeItem( CLE );
			} else {
				window.localStorage.setItem( CLE, choix );
			}
		} catch ( e ) {
			// Stockage indisponible (navigation privée stricte) : le choix vaut
			// pour la page en cours seulement.
		}
		appliquer( choix );
	}

	document.querySelectorAll( '.sub-apparence' ).forEach( function ( groupe ) {
		groupe.hidden = false;
		groupe.addEventListener( 'click', function ( evenement ) {
			var bouton = evenement.target.closest( '.sub-apparence__choix' );
			if ( bouton ) {
				choisir( bouton.dataset.apparence );
			}
		} );
	} );

	document.querySelectorAll( '.sub-apparence-bascule' ).forEach( function ( bouton ) {
		bouton.hidden = false;
		bouton.addEventListener( 'click', function () {
			var actuel = lire();
			choisir( ORDRE[ ( ORDRE.indexOf( actuel ) + 1 ) % ORDRE.length ] );
		} );
	} );

	if ( media ) {
		var suivre = function () {
			if ( lire() === 'systeme' ) {
				appliquer( 'systeme' );
			}
		};
		if ( media.addEventListener ) {
			media.addEventListener( 'change', suivre );
		} else if ( media.addListener ) {
			media.addListener( suivre );
		}
	}

	// Un autre onglet a changé d'avis.
	window.addEventListener( 'storage', function ( evenement ) {
		if ( evenement.key === CLE ) {
			appliquer( lire() );
		}
	} );

	appliquer( lire() );
} )();
