/**
 * Écrans du bureau : lignes ajoutables et onglets.
 *
 * Deux comportements, un principe commun : le HTML complet est servi par le
 * serveur, le JavaScript ne fait que le rendre plus confortable. Sans lui, tout
 * reste accessible — simplement plus long à parcourir.
 */
(function () {
	'use strict';

	// ------------------------------------------------------- Lignes ajoutables

	function clearInputs(row) {
		row.querySelectorAll('input').forEach(function (input) {
			input.value = input.type === 'text' && input.inputMode === 'decimal' ? '0,00' : '';
		});
		row.querySelectorAll('select').forEach(function (select) {
			select.selectedIndex = 0;
		});
	}

	document.addEventListener('click', function (event) {
		const addButton = event.target.closest('[data-repeat-add]');

		if (addButton) {
			const table = addButton.previousElementSibling;
			const body = table && table.querySelector('tbody');
			if (!body || !body.lastElementChild) {
				return;
			}

			const row = body.lastElementChild.cloneNode(true);
			clearInputs(row);
			body.appendChild(row);
			const first = row.querySelector('input, select');
			if (first) {
				first.focus();
			}
			return;
		}

		const removeButton = event.target.closest('.sub-repeat__remove');

		if (removeButton) {
			const row = removeButton.closest('tr');
			const body = row && row.parentElement;

			// On garde toujours une ligne : un tableau vide ne se recompléterait
			// plus, faute de modèle à cloner.
			if (body && body.children.length > 1) {
				row.remove();
			} else if (row) {
				clearInputs(row);
			}
		}
	});

	// ------------------------------------------------------------------ Onglets

	/**
	 * Active la première barre d'onglets trouvée dans `root`.
	 *
	 * `storageKey` mémorise l'onglet ouvert d'une visite à l'autre : un
	 * secrétaire qui corrige dix fiches revient dix fois au même endroit.
	 */
	function setupTabs(root, storageKey) {
		const tablist = root.querySelector('.sub-tabs');
		const tabs = Array.from(root.querySelectorAll('.sub-tabs__tab'));
		const panels = Array.from(root.querySelectorAll('.sub-panel'));

		if (!tablist || tabs.length < 2 || tabs.length !== panels.length) {
			return;
		}

		tablist.hidden = false;

		function select(index, moveFocus) {
			tabs.forEach(function (tab, i) {
				const active = i === index;
				tab.setAttribute('aria-selected', active ? 'true' : 'false');
				tab.classList.toggle('nav-tab-active', active);
				tab.tabIndex = active ? 0 : -1;
				panels[i].hidden = !active;
			});

			if (moveFocus) {
				tabs[index].focus();
			}

			try {
				window.sessionStorage.setItem(storageKey, String(index));
			} catch (e) {
				// Navigation privée : sans mémorisation, mais sans conséquence.
			}
		}

		tabs.forEach(function (tab, index) {
			tab.addEventListener('click', function () {
				select(index, false);
			});

			tab.addEventListener('keydown', function (event) {
				const moves = { ArrowRight: 1, ArrowLeft: -1, Home: 'first', End: 'last' };
				const move = moves[event.key];

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

		// Un champ obligatoire vide dans un onglet masqué ne peut pas recevoir le
		// focus : le navigateur bloque l'envoi sans rien montrer. On révèle donc
		// l'onglet fautif avant que la validation native ne s'exécute.
		const form = root.closest('form') || root.querySelector('form');

		if (form) {
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
		}

		let initial = 0;
		try {
			const stored = parseInt(window.sessionStorage.getItem(storageKey), 10);
			if (!isNaN(stored) && stored >= 0 && stored < tabs.length) {
				initial = stored;
			}
		} catch (e) {
			// On repart du premier onglet.
		}

		select(initial, false);
	}

	// On part de la barre d'onglets et on remonte à son formulaire, plutôt que
	// de désigner « le premier formulaire de la page » et d'espérer que ce soit
	// le bon. La fiche membre en compte trois — compte, réinitialisation,
	// dossier — et l'ordre a déjà changé une fois : ce jour-là les onglets ont
	// disparu, sans erreur en console, parce que le premier formulaire n'en
	// contenait aucun.
	const tablist = document.querySelector('.sub-admin .sub-tabs');
	const memberForm = tablist && tablist.closest('form');

	if (memberForm) {
		setupTabs(memberForm, 'subMemberTab');
	}
})();
