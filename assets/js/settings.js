/**
 * Post SEO Optimizer – settings page "Test connection".
 */
( function () {
	'use strict';

	const config = window.bzpsoSettings;
	const button = document.getElementById( 'bzpso-test-connection' );
	const output = document.getElementById( 'bzpso-test-result' );
	if ( ! config || ! button || ! output ) {
		return;
	}

	button.addEventListener( 'click', async () => {
		button.disabled = true;
		output.className = 'bzpso-test-result';
		output.textContent = config.i18n.testing;

		const body = new FormData();
		body.append( 'action', 'bzpso_test_connection' );
		body.append( 'nonce', config.nonce );

		let message = config.i18n.failed;
		let success = false;
		try {
			const response = await fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body } );
			const json = await response.json();
			success = !! json.success;
			if ( json.data && typeof json.data.message === 'string' ) {
				message = json.data.message;
			}
		} catch ( error ) {
			success = false;
		}

		output.textContent = message;
		output.classList.add( success ? 'is-success' : 'is-error' );
		button.disabled = false;
	} );
}() );
