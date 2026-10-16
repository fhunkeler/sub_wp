/**
 * Page de connexion du club : bouton « œil » pour afficher le mot de passe.
 *
 * L'écran natif `wp-login.php` en a un ; `wp_login_form()`, qui produit notre
 * formulaire, n'en a pas. Sur téléphone, c'est souvent la seule façon de voir
 * une faute de frappe avant d'être refusé.
 *
 * Le bouton est ajouté ici plutôt que dans le HTML : sans JavaScript, il ne
 * servirait à rien, et le champ reste un champ de mot de passe ordinaire.
 */
(function () {
	'use strict';

	const SHOW = 'Afficher le mot de passe';
	const HIDE = 'Masquer le mot de passe';

	// Œil ouvert, puis œil barré : traits seulement, couleur du texte.
	const EYE = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">'
		+ '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/>'
		+ '<circle cx="12" cy="12" r="3"/></svg>';
	const EYE_OFF = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">'
		+ '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/>'
		+ '<circle cx="12" cy="12" r="3"/><path d="M4 4l16 16"/></svg>';

	function enhance(input) {
		const wrap = document.createElement('span');
		wrap.className = 'sub-login__password';
		input.parentNode.insertBefore(wrap, input);
		wrap.appendChild(input);

		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'sub-login__reveal';
		button.setAttribute('aria-controls', input.id);
		wrap.appendChild(button);

		function render(visible) {
			button.setAttribute('aria-pressed', visible ? 'true' : 'false');
			button.setAttribute('aria-label', visible ? HIDE : SHOW);
			button.title = visible ? HIDE : SHOW;
			button.innerHTML = visible ? EYE_OFF : EYE;
		}

		button.addEventListener('click', function () {
			const visible = input.type === 'password';
			input.type = visible ? 'text' : 'password';
			render(visible);
			input.focus();
		});

		// Le mot de passe ne part jamais en clair dans l'historique de saisie
		// du navigateur : on le remasque juste avant l'envoi.
		if (input.form) {
			input.form.addEventListener('submit', function () {
				input.type = 'password';
				render(false);
			});
		}

		render(false);
	}

	document.querySelectorAll('.sub-login input[type="password"]').forEach(enhance);
})();
