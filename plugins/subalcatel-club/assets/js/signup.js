/**
 * Formulaire d'inscription à une sortie.
 *
 * Un seul comportement : révéler les champs qui dépendent d'une réponse — le
 * niveau préparé et le moniteur précédent n'ont de sens que si l'on vient en
 * formation. Le serveur écarte de toute façon les réponses aux champs masqués,
 * ce script ne fait que l'anticiper à l'écran.
 *
 * Deux formes de dépendance : une case cochée (`data-depends-value` vide) ou
 * une valeur précise attendue dans une liste déroulante.
 */
(function () {
	'use strict';

	/**
	 * Valeur courante d'un contrôle, cases à cocher comprises.
	 *
	 * @param {HTMLInputElement|HTMLSelectElement} control
	 * @return {string}
	 */
	function valueOf(control) {
		if (control.type === 'checkbox') {
			return control.checked ? '1' : '';
		}

		return control.value || '';
	}

	/**
	 * @param {HTMLFormElement} form
	 * @param {boolean} clearHidden Vider les champs que l'on masque.
	 *   Faux au premier passage : la valeur reprise du profil serait sinon
	 *   effacée avant même que le membre ait répondu quoi que ce soit.
	 */
	function sync(form, clearHidden) {
		form.querySelectorAll('[data-toggle]').forEach(function (toggle) {
			const name = toggle.dataset.toggle;
			const current = valueOf(toggle);

			form.querySelectorAll('[data-depends-on="' + name + '"]').forEach(function (field) {
				const expected = field.dataset.dependsValue || '';
				const met = expected === '' ? current !== '' : current === expected;

				field.hidden = !met;

				// Un champ masqué ne doit rien envoyer : sinon une réponse
				// saisie puis masquée resterait dans la requête.
				if (!met && clearHidden) {
					field.querySelectorAll('input, select, textarea').forEach(function (input) {
						if (input.type === 'checkbox' || input.type === 'radio') {
							input.checked = false;
						} else {
							input.value = '';
						}
					});
				}
			});
		});
	}

	document.querySelectorAll('form.sub-signup').forEach(function (form) {
		form.addEventListener('change', function () {
			sync(form, true);
		});

		sync(form, false);
	});
})();
