/**
 * Écrans du bureau : lignes ajoutables et onglets.
 *
 * Deux comportements, un principe commun : le HTML complet est servi par le
 * serveur, le JavaScript ne fait que le rendre plus confortable. Sans lui, tout
 * reste accessible — simplement plus long à parcourir.
 */
(function () {
	'use strict';

	// ------------------------------------------------- Condition d'affichage

	/**
	 * « N'afficher que si telle question reçoit telles réponses. »
	 *
	 * Le serveur rend un groupe de cases par question ; celui de la question
	 * retenue est seul visible. Les autres sont masqués ET désactivés : masquer
	 * ne suffit pas, un champ caché poste quand même sa valeur, et la règle
	 * repartirait avec les réponses d'une question qu'elle ne vise pas.
	 */
	function syncCondition(root) {
		const select = root.querySelector('[data-condition-option]');
		const answers = root.querySelector('[data-condition-answers]');
		const groups = root.querySelectorAll('[data-condition-for]');

		if (!select || !groups.length) {
			return;
		}

		function apply() {
			groups.forEach(function (group) {
				const active = group.dataset.conditionFor === select.value;

				group.hidden = !active;
				group.querySelectorAll('input').forEach(function (input) {
					input.disabled = !active;
				});
			});

			// Sans question choisie, il n'y a rien à cocher : on retire le bloc
			// entier plutôt que de laisser une consigne suivie de rien.
			if (answers) {
				answers.hidden = select.value === '';
			}
		}

		select.addEventListener('change', apply);
		apply();
	}

	document.querySelectorAll('[data-condition]').forEach(syncCondition);

	// ------------------------------------------- Règle d'affichage, en français

	/** « a, b ou c » — la virgule partout sauf devant le dernier. */
	function enumerate(items) {
		if (items.length <= 1) {
			return items.join('');
		}

		return items.slice(0, -1).join(', ') + ' ou ' + items[items.length - 1];
	}

	/** Ce qu'une des deux règles dit, ou null si elle ne dit rien. */
	function readRule(block) {
		const select = block.querySelector('[data-condition-option]');

		if (!select || !select.value) {
			return null;
		}

		const group = block.querySelector('[data-condition-for="' + select.value + '"]');
		const answers = group
			? Array.from(group.querySelectorAll('input:checked')).map(function (input) {
					return input.parentElement.textContent.trim();
				})
			: [];

		return {
			question: select.options[select.selectedIndex].textContent.trim(),
			answers: answers,
			excludes: block.classList.contains('sub-condition--exclusion'),
		};
	}

	/**
	 * Relire sa propre règle en français est le seul moyen de voir qu'on l'a
	 * posée à l'envers — et de repérer le cas qui ne se voit pas autrement : une
	 * question choisie sans aucune réponse cochée masque l'option pour toujours.
	 */
	function syncVisibility(root) {
		const out = root.querySelector('[data-visibility-summary]');
		const blocks = root.querySelectorAll('[data-condition]');

		if (!out || !blocks.length) {
			return;
		}

		function apply() {
			const phrases = [];

			blocks.forEach(function (block) {
				const rule = readRule(block);

				if (!rule) {
					return;
				}

				if (!rule.answers.length) {
					phrases.push(
						'Aucune réponse cochée sur « ' +
							rule.question +
							' » : ' +
							(rule.excludes
								? 'cette exception ne fait rien.'
								: 'la question ne s’afficherait jamais.')
					);

					return;
				}

				phrases.push(
					(rule.excludes ? 'Jamais affichée si « ' : 'Affichée seulement si « ') +
						rule.question +
						' » vaut ' +
						enumerate(rule.answers) +
						'.'
				);
			});

			out.textContent = phrases.length
				? phrases.join(' ')
				: 'Toujours affichée, quelles que soient les autres réponses.';
		}

		root.addEventListener('change', apply);
		apply();
	}

	document.querySelectorAll('[data-visibility]').forEach(syncVisibility);

	// ---------------------------------------------- Heures locales des journaux

	/**
	 * Les journaux sont horodatés côté serveur, dans le fuseau du site ; on
	 * réécrit l'heure dans celui du navigateur.
	 *
	 * L'attribut `datetime` porte l'instant en UTC : c'est lui qui fait foi, le
	 * texte n'est qu'un repli pour qui n'a pas de JavaScript. L'infobulle rappelle
	 * le fuseau appliqué — sans elle, une trace opposable affiche une heure dont
	 * personne ne sait à quel fuseau la rapporter.
	 */
	function showLocalTime(element) {
		const instant = new Date(element.getAttribute('datetime'));

		if (isNaN(instant.getTime())) {
			return;
		}

		element.textContent = new Intl.DateTimeFormat('fr-FR', {
			day: '2-digit',
			month: '2-digit',
			year: 'numeric',
			hour: '2-digit',
			minute: '2-digit',
			hour12: false,
		}).format(instant);

		element.title = new Intl.DateTimeFormat('fr-FR', {
			dateStyle: 'full',
			timeStyle: 'long',
		}).format(instant);
	}

	document.querySelectorAll('time[data-sub-localtime]').forEach(showLocalTime);

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

	// --- Création d'un événement : le formulaire suit le type choisi ---------
	//
	// Une assemblée générale n'a pas de niveau minimum, et une réunion du
	// bureau pas de directeur de plongée. Laisser les deux champs visibles les
	// faisait remplir au hasard, et laissait croire qu'une réunion pouvait se
	// réserver aux P2.
	//
	// Le serveur reste juge : il refuse une personne désignée qui n'a pas le
	// niveau. Ce qui suit ne fait qu'épargner la saisie d'un choix impossible.

	function setupEventForm(typeSelect) {
		let rules = {};
		try {
			rules = JSON.parse(typeSelect.getAttribute('data-event-types') || '{}');
		} catch (e) {
			return; // Sans les règles, tout reste visible : rien ne se perd.
		}

		const form = typeSelect.closest('form');
		if (!form) {
			return;
		}

		const leaderRow = form.querySelector('[data-event-row="leader"]');
		const levelsRow = form.querySelector('[data-event-row="levels"]');
		const leaderSelect = form.querySelector('#sub-dive-leader');

		function apply() {
			const rule = rules[typeSelect.value] || {};
			const needsLeader = Boolean(rule.leader || rule.autonomous);

			if (levelsRow) {
				levelsRow.hidden = !rule.diving;
			}

			if (leaderRow) {
				leaderRow.hidden = !needsLeader;
			}

			if (!leaderSelect) {
				return;
			}

			// Un directeur de plongée convient partout où un autonome suffit ;
			// l'inverse est faux. On masque donc les noms que ce type refuse,
			// et on relâche la sélection si elle vient d'être écartée.
			const besoin = rule.leader ? 'leader' : 'autonomous';

			Array.prototype.forEach.call(leaderSelect.options, function (option) {
				if (option.value === '') {
					return;
				}

				const convient = option.getAttribute('data-' + besoin) === '1';
				option.hidden = !convient;
				option.disabled = !convient;

				if (!convient && option.selected) {
					leaderSelect.value = '';
				}
			});
		}

		typeSelect.addEventListener('change', apply);
		apply();
	}

	const eventType = document.getElementById('sub-event-type');

	if (eventType) {
		setupEventForm(eventType);
	}
})();
