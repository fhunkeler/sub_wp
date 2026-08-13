/**
 * Profil membre — onglets.
 *
 * Le HTML sert tous les groupes ; ce fichier n'en montre qu'un à la fois. Sans
 * JavaScript, la barre d'onglets reste masquée et le formulaire s'affiche d'un
 * seul tenant : rien n'est perdu, c'est simplement plus long à parcourir.
 */
(function () {
	'use strict';

	const form = document.querySelector('.sub-profile');
	if (!form) {
		return;
	}

	const tablist = form.querySelector('.sub-tabs');
	const tabs = Array.from(form.querySelectorAll('.sub-tabs__tab'));
	const panels = Array.from(form.querySelectorAll('.sub-panel'));

	if (!tablist || tabs.length < 2) {
		return;
	}

	tablist.hidden = false;

	function select(index, moveFocus) {
		tabs.forEach(function (tab, i) {
			const active = i === index;
			tab.setAttribute('aria-selected', active ? 'true' : 'false');
			tab.tabIndex = active ? 0 : -1;
			panels[i].hidden = !active;
		});

		if (moveFocus) {
			tabs[index].focus();
		}

		try {
			window.sessionStorage.setItem('subProfileTab', String(index));
		} catch (e) {
			// Navigation privée : l'onglet ne sera pas mémorisé, sans conséquence.
		}
	}

	tabs.forEach(function (tab, index) {
		tab.addEventListener('click', function () {
			select(index, false);
		});

		tab.addEventListener('keydown', function (event) {
			const map = { ArrowRight: 1, ArrowLeft: -1, Home: 'first', End: 'last' };
			const move = map[event.key];

			if (move === undefined) {
				return;
			}

			event.preventDefault();

			if (move === 'first') {
				select(0, true);
			} else if (move === 'last') {
				select(tabs.length - 1, true);
			} else {
				select((index + move + tabs.length) % tabs.length, true);
			}
		});
	});

	/**
	 * Un champ obligatoire vide dans un onglet masqué ne peut pas recevoir le
	 * focus : le navigateur bloque l'envoi sans rien montrer. On révèle donc
	 * l'onglet fautif avant que la validation native ne s'exécute.
	 */
	form.addEventListener(
		'invalid',
		function (event) {
			const panel = event.target.closest('.sub-panel');

			if (panel && panel.hidden) {
				select(panels.indexOf(panel), false);
			}
		},
		true
	);

	let initial = 0;
	try {
		const stored = parseInt(window.sessionStorage.getItem('subProfileTab'), 10);
		if (!isNaN(stored) && stored >= 0 && stored < tabs.length) {
			initial = stored;
		}
	} catch (e) {
		// Idem : on repart du premier onglet.
	}

	select(initial, false);
})();
