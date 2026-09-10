/**
 * Formulaire d'adhésion — récapitulatif tarifaire en direct.
 *
 * Aucune règle métier ici : le serveur calcule les montants ET dit quelles
 * options sont visibles. Ce fichier ne fait qu'afficher sa réponse. Dupliquer
 * les conditions en JavaScript garantirait qu'un jour les deux divergent.
 */
(function () {
	'use strict';

	const form = document.querySelector('.sub-membership');
	if (!form || typeof subalcatelQuote === 'undefined') {
		return;
	}

	const linesEl = form.querySelector('[data-quote-lines]');
	const totalEl = form.querySelector('[data-quote-total]');
	const campaignId = form.dataset.campaign;

	let pending = null;

	function collect() {
		const data = new FormData(form);
		const options = {};

		for (const [key, value] of data.entries()) {
			const match = key.match(/^options\[(.+)\]$/);
			if (match) {
				options[match[1]] = value;
			}
		}

		return { campaign_id: campaignId, plan: data.get('plan') || '', options };
	}

	function applyVisibility(visible) {
		form.querySelectorAll('[data-option]').forEach(function (field) {
			const shown = visible.indexOf(field.dataset.option) !== -1;
			field.hidden = !shown;

			// Une option masquée ne doit rien envoyer : sinon un choix fait puis
			// masqué resterait dans la requête.
			if (!shown) {
				field.querySelectorAll('input').forEach(function (input) {
					input.checked = false;
				});
			}
		});
	}

	function renderLines(lines) {
		if (!lines.length) {
			linesEl.innerHTML = '<p class="sub-summary__empty">Choisissez une formule pour voir le détail.</p>';
			return;
		}

		linesEl.innerHTML = lines
			.map(function (line) {
				const label = line.value ? line.label + ' — ' + line.value : line.label;
				return (
					'<div class="sub-summary__line sub-summary__line--' +
					line.type +
					'"><span>' +
					escapeHtml(label) +
					'</span><span>' +
					escapeHtml(line.display) +
					'</span></div>'
				);
			})
			.join('');
	}

	function escapeHtml(value) {
		const div = document.createElement('div');
		div.textContent = value;
		return div.innerHTML;
	}

	function refresh() {
		const payload = collect();
		if (!payload.plan) {
			return;
		}

		if (pending) {
			pending.abort();
		}
		pending = new AbortController();

		fetch(subalcatelQuote.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			signal: pending.signal,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': subalcatelQuote.nonce,
			},
			body: JSON.stringify(payload),
		})
			.then(function (response) {
				return response.ok ? response.json() : Promise.reject(response);
			})
			.then(function (data) {
				applyVisibility(data.visible || []);
				renderLines(data.lines || []);
				totalEl.textContent = data.display;
			})
			.catch(function (error) {
				if (error.name !== 'AbortError') {
					totalEl.textContent = '—';
				}
			});
	}

	/**
	 * Deux fois la même adresse ne fait pas deux adresses : le jour où la boîte
	 * principale ne répond plus, la seconde ne répond pas davantage. Le serveur
	 * l'écarte de toute façon — l'avertissement évite juste de le découvrir
	 * après coup.
	 */
	function watchSecondaryEmail() {
		const primary = form.querySelector('[data-primary-email]');
		const secondary = form.querySelector('[data-secondary-email]');
		const warning = form.querySelector('[data-secondary-email-warning]');

		if (!primary || !secondary || !warning) {
			return;
		}

		function check() {
			const value = secondary.value.trim().toLowerCase();
			const same = value !== '' && value === primary.value.trim().toLowerCase();

			warning.hidden = !same;
			secondary.closest('.sub-input').classList.toggle('sub-input--warning', same);
		}

		primary.addEventListener('input', check);
		secondary.addEventListener('input', check);
		check();
	}

	form.addEventListener('change', refresh);
	watchSecondaryEmail();
	refresh();
})();
