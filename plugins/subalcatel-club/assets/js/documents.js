/**
 * Champ de dépôt de document.
 *
 * Le champ natif est transparent : c'est lui qui ouvre le sélecteur et porte la
 * validation, mais le nom affiché est le nôtre — le contrôle du navigateur ne
 * laisse pas assez de place au sien, et le tronque là où ça n'aide personne.
 *
 * Sans ce script, le nom reste sur « Aucun fichier choisi » : le dépôt marche
 * quand même, on perd seulement le retour visuel.
 */
(function () {
	'use strict';

	const VIDE = 'Aucun fichier choisi';

	/** @param {HTMLInputElement} input */
	function afficher(input) {
		const boite = input.closest('.sub-file');
		const cible = boite && boite.querySelector('[data-file-name]');

		if (!cible) {
			return;
		}

		const fichier = input.files && input.files[0];

		cible.textContent = fichier ? fichier.name : VIDE;
		cible.classList.toggle('sub-file__name--chosen', Boolean(fichier));

		// Le nom complet reste atteignable au survol, l'ellipse ne le perd pas.
		if (fichier) {
			cible.title = fichier.name;
		} else {
			cible.removeAttribute('title');
		}
	}

	document.querySelectorAll('[data-file-field]').forEach(function (input) {
		input.addEventListener('change', function () {
			afficher(input);
		});

		// Un rechargement de page peut restaurer une sélection : on se synchronise
		// avant tout événement.
		afficher(input);
	});
})();
