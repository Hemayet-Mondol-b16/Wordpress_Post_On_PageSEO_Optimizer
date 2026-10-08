/**
 * Post SEO Optimizer – product edit screen.
 *
 * Generate → review/edit → apply. All server and AI text is inserted with
 * textContent; HTML previews go through an allow-list sanitizer.
 */
( function () {
	'use strict';

	const config = window.bzpsoConfig;
	const app = document.getElementById( 'bzpso-app' );
	if ( ! config || ! app ) {
		return;
	}

	const i18n = config.i18n;
	const notice = app.querySelector( '#bzpso-notice' );
	const results = app.querySelector( '#bzpso-results' );
	const spinner = app.querySelector( '.bzpso-controls .spinner' );
	const generateButton = app.querySelector( '#bzpso-generate' );
	const restoreButton = app.querySelector( '#bzpso-restore' );

	const PREVIEW_TAGS = new Set( [ 'P', 'BR', 'H2', 'H3', 'H4', 'UL', 'OL', 'LI', 'STRONG', 'B', 'EM', 'I', 'TABLE', 'THEAD', 'TBODY', 'TR', 'TH', 'TD', 'BLOCKQUOTE' ] );
	const DROP_TAGS = new Set( [ 'SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'TEMPLATE', 'SVG', 'MATH', 'NOSCRIPT' ] );

	let busy = false;

	/* ---------- helpers ---------- */

	function el( tag, attrs, text ) {
		const node = document.createElement( tag );
		Object.entries( attrs || {} ).forEach( ( [ key, value ] ) => {
			if ( key === 'className' ) {
				node.className = value;
			} else {
				node.setAttribute( key, value );
			}
		} );
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	function format( template, ...values ) {
		let index = 0;
		return template
			.replace( /%(\d)\$[sd]/g, ( match, n ) => String( values[ Number( n ) - 1 ] ) )
			.replace( /%[sd]/g, () => String( values[ index++ ] ) );
	}

	function showNotice( message, type, extra ) {
		notice.hidden = false;
		notice.className = 'bzpso-notice notice inline notice-' + type;
		const paragraph = el( 'p', {}, message );
		notice.replaceChildren( paragraph );
		( extra || [] ).forEach( ( node ) => notice.appendChild( node ) );
	}

	function setBusy( state ) {
		busy = state;
		app.querySelectorAll( 'button' ).forEach( ( button ) => {
			button.disabled = state;
		} );
		if ( spinner ) {
			spinner.classList.toggle( 'is-active', state );
		}
	}

	function charCount( text ) {
		return Array.from( text ).length;
	}

	function resolveYoastVars( text ) {
		return text
			.replace( /%%sitename%%/g, config.siteName )
			.replace( /%%sep%%/g, '-' )
			.replace( /%%[a-z_]+%%/gi, '' )
			.replace( /\s+/g, ' ' )
			.trim();
	}

	/** Returns sanitized nodes for previewing HTML. */
	function sanitizeHtml( html ) {
		const doc = new DOMParser().parseFromString( '<body>' + html + '</body>', 'text/html' );
		const clean = ( parent ) => {
			Array.from( parent.children ).forEach( ( child ) => {
				if ( DROP_TAGS.has( child.tagName ) ) {
					child.remove();
					return;
				}
				clean( child );
				if ( ! PREVIEW_TAGS.has( child.tagName ) ) {
					child.replaceWith( ...child.childNodes );
					return;
				}
				Array.from( child.attributes ).forEach( ( attr ) => child.removeAttribute( attr.name ) );
			} );
		};
		clean( doc.body );
		return Array.from( doc.body.childNodes );
	}

	function hasUnsavedChanges() {
		const server = window.wp && window.wp.autosave && window.wp.autosave.server;
		return !! ( server && typeof server.postChanged === 'function' && server.postChanged() );
	}

	function confirmReload( message ) {
		return window.confirm( hasUnsavedChanges() ? message + '\n\n' + i18n.unsavedWarning : message );
	}

	function reloadPage() {
		// Skip the editor's "leave page?" prompt; the user already confirmed.
		if ( window.jQuery ) {
			window.jQuery( window ).off( 'beforeunload.edit-post' );
		}
		window.onbeforeunload = null;
		window.location.reload();
	}

	async function request( action, data ) {
		const body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', config.nonce );
		body.append( 'product_id', String( config.productId ) );
		Object.keys( data || {} ).forEach( ( key ) => {
			if ( Array.isArray( data[ key ] ) ) {
				data[ key ].forEach( ( item ) => body.append( key + '[]', item ) );
			} else {
				body.append( key, data[ key ] );
			}
		} );

		let response;
		try {
			response = await fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body } );
		} catch ( error ) {
			throw new Error( i18n.networkError );
		}

		let json = null;
		try {
			json = await response.json();
		} catch ( error ) {
			json = null;
		}

		if ( json && json.success ) {
			return json.data || {};
		}
		if ( json && json.data && typeof json.data.message === 'string' ) {
			const error = new Error( json.data.message );
			error.fromServer = true;
			throw error;
		}
		throw new Error( response.status >= 500 ? i18n.serverError : i18n.unknownError );
	}

	/* ---------- results table ---------- */

	function updateCounter( field, input, counter ) {
		const value = field.key === 'seo_title' ? resolveYoastVars( input.value ) : input.value;
		const count = charCount( value.trim() );
		let text = format( i18n.characters, count );
		if ( field.min && field.max ) {
			text += ' · ' + format( i18n.recommendRange, field.min, field.max );
		} else if ( field.max ) {
			text += ' · ' + format( i18n.recommendMax, field.max );
		}
		counter.textContent = text;
		const ok = count === 0 || ( ( ! field.min || count >= field.min ) && ( ! field.max || count <= field.max ) );
		counter.classList.toggle( 'is-warning', ! ok );
	}

	function buildEditor( field, value, checkbox ) {
		const wrap = el( 'div', { className: 'bzpso-editor' } );
		const id = 'bzpso-field-' + field.key;
		let input;

		if ( field.type === 'text' ) {
			input = el( 'input', { type: 'text', id, className: 'large-text', spellcheck: 'true' } );
		} else {
			const rows = field.key === 'description' ? 14 : ( field.type === 'html' ? 7 : 3 );
			input = el( 'textarea', { id, className: 'large-text', rows: String( rows ) } );
		}
		input.value = value;
		input.dataset.field = field.key;
		wrap.appendChild( input );

		input.addEventListener( 'input', () => {
			checkbox.checked = true;
		} );

		if ( field.max ) {
			const counter = el( 'span', { className: 'bzpso-counter' } );
			wrap.appendChild( counter );
			updateCounter( field, input, counter );
			input.addEventListener( 'input', () => updateCounter( field, input, counter ) );
		}

		const notes = { slug: i18n.slugNote, tags: i18n.tagsNote, image_alt: i18n.altNote };
		if ( notes[ field.key ] ) {
			wrap.appendChild( el( 'span', { className: 'bzpso-note' }, notes[ field.key ] ) );
		}
		if ( field.yoast && ! config.yoastActive ) {
			wrap.appendChild( el( 'span', { className: 'bzpso-note' }, i18n.yoastInactive ) );
		}

		if ( field.type === 'html' ) {
			const toggle = el( 'button', { type: 'button', className: 'button-link bzpso-preview-toggle', 'aria-expanded': 'false' }, i18n.showPreview );
			const preview = el( 'div', { className: 'bzpso-preview' } );
			preview.hidden = true;
			const render = () => preview.replaceChildren( ...sanitizeHtml( input.value ) );

			toggle.addEventListener( 'click', () => {
				const open = preview.hidden;
				if ( open ) {
					render();
				}
				preview.hidden = ! open;
				toggle.setAttribute( 'aria-expanded', String( open ) );
				toggle.textContent = open ? i18n.hidePreview : i18n.showPreview;
			} );
			input.addEventListener( 'input', () => {
				if ( ! preview.hidden ) {
					render();
				}
			} );
			wrap.appendChild( toggle );
			wrap.appendChild( preview );
		}

		return wrap;
	}

	function renderResults( data ) {
		const current = data.current || {};
		const suggested = data.suggested || {};

		const table = el( 'table', { className: 'widefat bzpso-table' } );
		const head = el( 'tr' );
		[ i18n.colApply, i18n.colField, i18n.colCurrent, i18n.colSuggested ].forEach( ( label, index ) => {
			head.appendChild( el( 'th', { scope: 'col', className: 'bzpso-col-' + index }, label ) );
		} );
		table.appendChild( el( 'thead' ) ).appendChild( head );

		const body = el( 'tbody' );
		config.fields.forEach( ( field ) => {
			if ( ! Object.prototype.hasOwnProperty.call( suggested, field.key ) ) {
				return;
			}
			const value = String( suggested[ field.key ] || '' );
			const row = el( 'tr', { 'data-field': field.key } );

			const checkbox = el( 'input', { type: 'checkbox', className: 'bzpso-apply', 'aria-label': format( i18n.applyField, field.label ) } );
			// Only requested fields are returned, so every non-empty suggestion starts ticked.
			checkbox.checked = value !== '';
			row.appendChild( el( 'td', { className: 'bzpso-col-0' } ) ).appendChild( checkbox );

			row.appendChild( el( 'td', { className: 'bzpso-col-1' } ) ).appendChild( el( 'label', { for: 'bzpso-field-' + field.key }, field.label ) );

			const currentValue = String( current[ field.key ] || '' );
			const currentCell = el( 'td', { className: 'bzpso-col-2' } );
			currentCell.appendChild( el( 'div', { className: 'bzpso-current' + ( currentValue ? '' : ' is-empty' ) }, currentValue || i18n.empty ) );
			row.appendChild( currentCell );

			row.appendChild( el( 'td', { className: 'bzpso-col-3' } ) ).appendChild( buildEditor( field, value, checkbox ) );
			body.appendChild( row );
		} );
		table.appendChild( body );

		const actions = el( 'div', { className: 'bzpso-actions' } );
		const applyButton = el( 'button', { type: 'button', className: 'button button-primary' }, i18n.applySelected );
		const discardButton = el( 'button', { type: 'button', className: 'button' }, i18n.discard );
		applyButton.addEventListener( 'click', applyChanges );
		discardButton.addEventListener( 'click', () => {
			results.replaceChildren();
			results.hidden = true;
			notice.hidden = true;
		} );
		actions.appendChild( applyButton );
		actions.appendChild( discardButton );
		if ( data.provider ) {
			const model = data.model ? ' (' + data.model + ( data.fallback ? ' – ' + i18n.fallbackUsed : '' ) + ')' : '';
			actions.appendChild( el( 'span', { className: 'bzpso-meta' }, i18n.generatedBy + ' ' + data.provider + model ) );
		}
		const usage = data.usage || {};
		if ( usage.input || usage.output ) {
			const number = ( n ) => Number( n || 0 ).toLocaleString();
			let text = format( i18n.tokens, number( usage.input ), number( usage.output ) );
			if ( usage.thinking ) {
				text += ' ' + format( i18n.tokensThinking, number( usage.thinking ) );
			}
			if ( usage.cached ) {
				text += ' · ' + format( i18n.tokensCached, number( usage.cached ) );
			}
			actions.appendChild( el( 'span', { className: 'bzpso-meta bzpso-usage' }, text ) );
		}

		results.replaceChildren( table, actions );
		results.hidden = false;
	}

	/* ---------- actions ---------- */

	function finish( response ) {
		const warnings = Array.isArray( response.warnings ) ? response.warnings : [];
		if ( ! warnings.length ) {
			showNotice( i18n.reloading, 'success' );
			reloadPage();
			return;
		}
		const list = el( 'ul' );
		warnings.forEach( ( warning ) => list.appendChild( el( 'li', {}, String( warning ) ) ) );
		const reload = el( 'button', { type: 'button', className: 'button' }, i18n.reloadNow );
		reload.addEventListener( 'click', reloadPage );
		showNotice( response.message || '', 'warning', [ list, el( 'p' ) ] );
		notice.lastChild.appendChild( reload );
		setBusy( false );
	}

	/* ---------- background generation ---------- */

	const JOB_KEY = 'bzpso-job-' + config.productId;
	const POLL_MS = 3000;
	const sleep = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

	// Remember the running job for this tab so a page reload can pick it up again.
	function rememberJob( id ) {
		try {
			if ( id ) {
				window.sessionStorage.setItem( JOB_KEY, id );
			} else {
				window.sessionStorage.removeItem( JOB_KEY );
			}
		} catch ( error ) {
			// Storage unavailable (private mode): resuming after reload just won't work.
		}
	}

	function rememberedJob() {
		try {
			return window.sessionStorage.getItem( JOB_KEY ) || '';
		} catch ( error ) {
			return '';
		}
	}

	function formatElapsed( seconds ) {
		const minutes = Math.floor( seconds / 60 );
		return minutes ? format( i18n.minSec, minutes, seconds % 60 ) : format( i18n.sec, seconds );
	}

	async function waitForJob( job ) {
		let failures = 0;
		for ( ;; ) {
			await sleep( POLL_MS );
			let status;
			try {
				status = await request( 'bzpso_job_status', { job } );
				failures = 0;
			} catch ( error ) {
				// A message from the server ends the wait; network blips are retried a few times.
				failures++;
				if ( error.fromServer || failures >= 5 ) {
					throw error;
				}
				continue;
			}
			if ( status.status === 'done' ) {
				return status.data || {};
			}
			showNotice( format( i18n.working, formatElapsed( Number( status.elapsed ) || 0 ) ), 'info' );
		}
	}

	async function generate( resumeJob ) {
		if ( busy ) {
			return;
		}
		const fields = Array.from( app.querySelectorAll( '.bzpso-gen-field:checked' ) ).map( ( input ) => input.value );
		if ( ! resumeJob && ! fields.length ) {
			showNotice( i18n.selectGenerate, 'warning' );
			return;
		}
		setBusy( true );
		showNotice( resumeJob ? i18n.resuming : i18n.generating, 'info' );
		try {
			let job = resumeJob;
			if ( ! job ) {
				job = ( await request( 'bzpso_generate', {
					language: app.querySelector( '#bzpso-language' ).value,
					keyword: app.querySelector( '#bzpso-keyword' ).value,
					instructions: app.querySelector( '#bzpso-instructions' ).value,
					fields,
				} ) ).job;
				rememberJob( job );
			}
			renderResults( await waitForJob( job ) );
			showNotice( i18n.generated, 'success' );
		} catch ( error ) {
			showNotice( error.message, 'error' );
		} finally {
			rememberJob( '' );
			setBusy( false );
		}
	}

	async function applyChanges() {
		if ( busy ) {
			return;
		}
		const data = {};
		results.querySelectorAll( 'tr[data-field]' ).forEach( ( row ) => {
			const checkbox = row.querySelector( '.bzpso-apply' );
			const input = row.querySelector( '[data-field]' );
			if ( checkbox && checkbox.checked && input ) {
				data[ 'fields[' + row.dataset.field + ']' ] = input.value;
			}
		} );

		if ( ! Object.keys( data ).length ) {
			showNotice( i18n.selectField, 'warning' );
			return;
		}
		if ( ! confirmReload( i18n.confirmApply ) ) {
			return;
		}

		setBusy( true );
		showNotice( i18n.applying, 'info' );
		try {
			finish( await request( 'bzpso_apply', data ) );
		} catch ( error ) {
			showNotice( error.message, 'error' );
			setBusy( false );
		}
	}

	async function restore() {
		if ( busy || ! confirmReload( i18n.confirmRestore ) ) {
			return;
		}
		setBusy( true );
		showNotice( i18n.restoring, 'info' );
		try {
			finish( await request( 'bzpso_restore', {} ) );
		} catch ( error ) {
			showNotice( error.message, 'error' );
			setBusy( false );
		}
	}

	/* ---------- events ---------- */

	if ( generateButton ) {
		generateButton.addEventListener( 'click', () => generate() );
	}

	// A job was still running when the page was reloaded: keep following it.
	const pendingJob = rememberedJob();
	if ( pendingJob ) {
		generate( pendingJob );
	}
	if ( restoreButton ) {
		restoreButton.addEventListener( 'click', restore );
	}

	// The box sits inside the product form: stop Enter from submitting (saving) the product.
	app.addEventListener( 'keydown', ( event ) => {
		if ( event.key === 'Enter' && event.target instanceof HTMLInputElement && event.target.type === 'text' ) {
			event.preventDefault();
			if ( event.target.closest( '.bzpso-controls' ) && ! generateButton.disabled ) {
				generate();
			}
		}
	} );
}() );
