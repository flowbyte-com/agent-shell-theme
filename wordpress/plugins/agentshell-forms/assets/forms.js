/*
 * AgentShell Forms — challenge provisioning and submission.
 *
 * ## Two jobs, one file, because they share one state machine
 *
 * 1. **Provisioning.** The server renders a form with an **empty** challenge
 *    input. A challenge is single-use, so a challenge written into the HTML
 *    would be spent by the first visitor who loaded a cached page. This script
 *    asks the server for one token **per form instance, per visitor**, after
 *    the page has been delivered.
 * 2. **Submission.** It sends the validated values to the submission endpoint,
 *    reports field errors, handles a stale page, renews the challenge and
 *    prevents a double-click, a reload or a lost response from creating two
 *    submissions.
 *
 * ## The states it reports
 *
 * `data-challenge-state` on the `<form>` is `idle`, `pending`, `ready`,
 * `error` or `stale`. `data-submission-state` is `sending` or `sent`. Both
 * exist so a test — and a stylesheet — can see the state without guessing.
 * A failure is announced in the form's existing `role="status"` region, so a
 * screen-reader user learns that the form is not ready rather than discovering
 * it when the submit button does nothing.
 *
 * ## The stale-page contract
 *
 * The page carries the version it was rendered from (`data-form-version`). The
 * challenge response carries the **current published** version. If they
 * differ, the page is stale: the script refuses to submit the old fields,
 * keeps what the visitor typed, and offers a reload button. The server repeats
 * the check, so a client that ignores this script cannot submit an old schema
 * against a new one either.
 *
 * ## Duplicates
 *
 * The challenge is the idempotency token. A double-click is stopped by a
 * per-form in-flight flag; a reload is a new page with a new token; a lost
 * response is retried **with the same token**, which the server answers with
 * the original success rather than a second submission. A pending response
 * from an attempt that is genuinely still running is retried with the same
 * token too, so the two cases converge.
 *
 * ## Why `fetch` here, when the theme's widgets may not fetch
 *
 * The AgentShell theme's widget runtime forbids `fetch` (AGENTS.md §4.4)
 * because a widget's data must come from the page it was rendered into. This
 * is not that: the token cannot come from the page, by design, and this plugin
 * is theme-independent. Every request goes to the site's own REST routes and
 * nowhere else.
 */

( function () {
	'use strict';

	var SELECTOR = 'form[data-agentshell-form]';
	var CHALLENGE_SELECTOR = '[data-agentshell-forms-challenge]';
	var STATUS_SELECTOR = '[data-agentshell-forms-status]';
	var SUBMIT_SELECTOR = '[data-agentshell-forms-submit]';
	var SUMMARY_SELECTOR = '.agentshell-forms__summary';
	var HONEYPOT_SELECTOR = '[name="agentshell_forms_hp"]';
	var TURNSTILE_SELECTOR = '[name="cf-turnstile-response"]';
	var TURNSTILE_WIDGET = '.cf-turnstile';
	var STASH_PREFIX = 'agentshellFormsStash:';
	var RELOAD_CLASS = 'agentshell-forms__reload';

	function strings() {
		var l10n = window.agentshellFormsL10n;

		return l10n && typeof l10n === 'object' ? l10n : {};
	}

	function text( key, fallback ) {
		var l10n = strings();

		return typeof l10n[ key ] === 'string' && l10n[ key ] ? l10n[ key ] : fallback;
	}

	/**
	 * Announce a message in the form's status region.
	 *
	 * `textContent`, never `innerHTML`: the message is plugin-supplied today,
	 * and a status region is not a place to start trusting strings. An empty
	 * message **clears** the region — a form that has recovered must not keep
	 * the failure it announced while it was broken.
	 *
	 * @param {HTMLFormElement} form    The form.
	 * @param {string}          message The message.
	 * @return {void}
	 */
	function announce( form, message ) {
		var region = form.querySelector( STATUS_SELECTOR );

		if ( ! region ) {
			return;
		}

		region.textContent = message ? String( message ) : '';
	}

	function setState( form, state ) {
		form.setAttribute( 'data-challenge-state', state );
	}

	function setSubmissionState( form, state ) {
		if ( state ) {
			form.setAttribute( 'data-submission-state', state );
		} else {
			form.removeAttribute( 'data-submission-state' );
		}
	}

	/**
	 * Per-form request generations and the version each token was minted for.
	 *
	 * Only the newest provisioning request may write to a form. A response that
	 * arrives after a newer one has started is stale by definition: the page
	 * may have been restored from the bfcache (which invalidates every token),
	 * or a caller may have asked twice, and the older answer would overwrite
	 * the newer token with one nobody is holding any more.
	 */
	var generations = new WeakMap();
	var mintedVersion = new WeakMap();
	var inFlight = new WeakSet();
	var autoRetries = new WeakMap();

	function nextGeneration( form ) {
		var next = ( generations.get( form ) || 0 ) + 1;

		generations.set( form, next );

		return next;
	}

	function generationOf( form ) {
		return generations.get( form ) || 0;
	}

	function renderedVersion( form ) {
		var raw = parseInt( form.getAttribute( 'data-form-version' ), 10 );

		return isNaN( raw ) ? 0 : raw;
	}

	function clear( form ) {
		var input = form.querySelector( CHALLENGE_SELECTOR );

		// Invalidate anything in flight *before* dropping the token, so a
		// response for the previous page-life cannot land in this one.
		nextGeneration( form );
		mintedVersion.delete( form );
		inFlight.delete( form );

		if ( input ) {
			input.value = '';
		}

		idle( form );
		setSubmissionState( form, '' );
		removeReload( form );
		announce( form, '' );
		setState( form, 'idle' );
	}

	function button( form ) {
		return form.querySelector( SUBMIT_SELECTOR );
	}

	function busy( form ) {
		var control = button( form );

		if ( control ) {
			if ( ! control.hasAttribute( 'data-label' ) ) {
				control.setAttribute( 'data-label', control.textContent );
			}

			control.disabled = true;
			control.textContent = text( 'submitting', 'Sending…' );
		}

		form.setAttribute( 'aria-busy', 'true' );
		setSubmissionState( form, 'sending' );
	}

	function idle( form ) {
		var control = button( form );

		if ( control ) {
			control.disabled = false;

			if ( control.hasAttribute( 'data-label' ) ) {
				control.textContent = control.getAttribute( 'data-label' );
			}
		}

		form.setAttribute( 'aria-busy', 'false' );

		if ( 'sending' === form.getAttribute( 'data-submission-state' ) ) {
			setSubmissionState( form, '' );
		}
	}

	function sent( form ) {
		var control = button( form );

		if ( control ) {
			control.disabled = true;
			control.textContent = text( 'sent', 'Sent' );
		}

		form.setAttribute( 'aria-busy', 'false' );
		setSubmissionState( form, 'sent' );
	}

	/**
	 * Ask Cloudflare for a new token, if the form carries a widget.
	 *
	 * A Turnstile token is **single-use**: the server verifies it while it
	 * decides whether to accept the request, so once a submission has been
	 * verified the token in the input is spent. That is fine when the request
	 * succeeded. It is not fine when the visitor has to try again — a field
	 * error, a refused attempt, a fresh challenge — because every retry would
	 * present the same dead token, and a form whose policy is `required`
	 * would answer with the generic refusal every time, forever, while the
	 * page looked perfectly normal.
	 *
	 * Resetting the widget makes Cloudflare issue a new token and re-run its
	 * challenge, which is exactly what the next attempt needs. Without the
	 * script (the widget blocked, or a form that declares no policy) this is a
	 * no-op, and the form still works the way it did before.
	 *
	 * @param {HTMLFormElement} form The form.
	 * @return {void}
	 */
	function renewTurnstile( form ) {
		if ( ! form.querySelector( TURNSTILE_WIDGET ) ) {
			return;
		}

		if ( ! window.turnstile || typeof window.turnstile.reset !== 'function' ) {
			return;
		}

		try {
			window.turnstile.reset( form.querySelector( TURNSTILE_WIDGET ) );
		} catch ( e ) {
			// A widget Cloudflare has already torn down is not something this
			// script can fix, and it must not break the retry.
		}
	}

	function delay( ms ) {
		return new Promise( function ( resolve ) {
			window.setTimeout( resolve, ms );
		} );
	}

	/**
	 * Provision one form instance.
	 *
	 * Never rejects: a form whose token could not be fetched must not take the
	 * other forms on the page down with it.
	 *
	 * @param {HTMLFormElement} form The form.
	 * @return {Promise<void>}
	 */
	function provision( form ) {
		if ( form.getAttribute( 'data-challenge-state' ) === 'ready' ) {
			return Promise.resolve();
		}

		var input = form.querySelector( CHALLENGE_SELECTOR );
		var endpoint = form.getAttribute( 'data-challenge-endpoint' );
		var formId = form.getAttribute( 'data-form-id' );

		if ( ! input || ! formId ) {
			return Promise.resolve();
		}

		// This request is now the newest one for this form; anything older that
		// is still in flight becomes stale.
		var mine = nextGeneration( form );

		if ( ! endpoint ) {
			setState( form, 'error' );
			announce( form, text( 'error', 'This form could not be prepared. Please reload the page and try again.' ) );

			return Promise.resolve();
		}

		if ( typeof window.fetch !== 'function' ) {
			setState( form, 'error' );
			announce( form, text( 'unsupported', 'This form cannot be started in this browser.' ) );

			return Promise.resolve();
		}

		setState( form, 'pending' );

		return window
			.fetch( endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
				body: JSON.stringify( { form_id: formId } )
			} )
			.then( function ( response ) {
				return response
					.json()
					.then(
						function ( body ) {
							return { ok: response.ok, body: body };
						},
						function () {
							return { ok: false, body: null };
						}
					);
			} )
			.then( function ( result ) {
				// A newer request has started since this one: its answer is the
				// one that belongs on the page.
				if ( generationOf( form ) !== mine ) {
					return;
				}

				var token = result.ok && result.body && typeof result.body.challenge === 'string'
					? result.body.challenge
					: '';

				if ( ! token ) {
					throw new Error( 'no challenge' );
				}

				var minted = result.body && typeof result.body.form_version === 'number'
					? result.body.form_version
					: 0;

				// The cached-page acceptance case: the page was rendered from
				// one version and the server is now publishing another. Never
				// put a token into a form whose fields the server would
				// reinterpret.
				if ( minted && minted !== renderedVersion( form ) ) {
					input.value = '';
					mintedVersion.delete( form );
					stale( form );

					return;
				}

				input.value = token;
				mintedVersion.set( form, minted );
				setState( form, 'ready' );

				// A form that has recovered must not keep the failure it
				// announced while it was broken.
				announce( form, '' );
				removeReload( form );
			} )
			.catch( function () {
				if ( generationOf( form ) !== mine ) {
					return;
				}

				input.value = '';
				mintedVersion.delete( form );
				setState( form, 'error' );
				announce( form, text( 'error', 'This form could not be prepared. Please reload the page and try again.' ) );
			} );
	}

	/**
	 * Provision every form on the page.
	 *
	 * Each form is independent: two instances of the same form are two
	 * challenges, which is the point.
	 *
	 * @return {Promise<void>}
	 */
	function provisionAll() {
		var forms = document.querySelectorAll( SELECTOR );
		var jobs = [];

		for ( var i = 0; i < forms.length; i++ ) {
			jobs.push( provision( forms[ i ] ) );
		}

		return Promise.all( jobs ).then( function () {} );
	}

	/**
	 * The form's field values, keyed by field key.
	 *
	 * Hidden fields are not sent: the server takes them from the definition and
	 * ignores anything the request says about them.
	 *
	 * @param {HTMLFormElement} form The form.
	 * @return {Object}
	 */
	function collectFields( form ) {
		var values = {};
		var wrappers = form.querySelectorAll( '[data-field-key]' );

		for ( var i = 0; i < wrappers.length; i++ ) {
			var wrapper = wrappers[ i ];
			var key = wrapper.getAttribute( 'data-field-key' );
			var type = wrapper.getAttribute( 'data-field-type' );

			if ( ! key || 'hidden' === type ) {
				continue;
			}

			var control = wrapper.querySelector( 'input,select,textarea' );

			if ( 'consent' === type ) {
				values[ key ] = !! ( control && control.checked );
				continue;
			}

			if ( 'checkbox' === type ) {
				var checked = [];
				var boxes = wrapper.querySelectorAll( 'input[type="checkbox"]' );

				for ( var b = 0; b < boxes.length; b++ ) {
					if ( boxes[ b ].checked ) {
						checked.push( boxes[ b ].value );
					}
				}

				values[ key ] = checked;
				continue;
			}

			if ( 'radio' === type ) {
				var chosen = wrapper.querySelector( 'input[type="radio"]:checked' );
				values[ key ] = chosen ? chosen.value : '';
				continue;
			}

			values[ key ] = control ? control.value : '';
		}

		return values;
	}

	/**
	 * The form's stashed values, saved before a stale page is reloaded.
	 *
	 * Only values that are safe to restore are kept: a hidden field's value is
	 * the definition's, a consent must be re-affirmed explicitly, and a choice
	 * group's option may not exist in the new schema. Free-text controls are
	 * the ones a visitor would otherwise have to type again.
	 *
	 * @param {HTMLFormElement} form The form.
	 * @return {void}
	 */
	function stash( form ) {
		try {
			var data = {
				version: renderedVersion( form ),
				values: collectFields( form )
			};

			window.sessionStorage.setItem( STASH_PREFIX + form.getAttribute( 'data-form-id' ), JSON.stringify( data ) );
		} catch ( e ) {
			// Private mode, a disabled store, or a full quota: the form is
			// still usable, the visitor just has to type again.
		}
	}

	/**
	 * Restore a stash left by a stale page.
	 *
	 * @param {HTMLFormElement} form The form.
	 * @return {void}
	 */
	function restore( form ) {
		try {
			var key = STASH_PREFIX + form.getAttribute( 'data-form-id' );
			var raw = window.sessionStorage.getItem( key );

			if ( ! raw ) {
				return;
			}

			var data = JSON.parse( raw );

			// A stash written by **this** page load is not for this page load: it
			// was written because the page is stale, and it exists for the reload
			// that follows. Consuming it here would delete it before the reload
			// could use it, which is how "your answers have been kept" becomes a
			// promise the page breaks a moment later. It is removed only when it
			// belongs to an older version — that is, when there really is
			// something to restore.
			if ( ! data || ! data.values || data.version === renderedVersion( form ) ) {
				return;
			}

			window.sessionStorage.removeItem( key );

			var restored = false;
			var wrappers = form.querySelectorAll( '[data-field-key]' );

			for ( var i = 0; i < wrappers.length; i++ ) {
				var wrapper = wrappers[ i ];
				var field = wrapper.getAttribute( 'data-field-key' );
				var type = wrapper.getAttribute( 'data-field-type' );

				if ( ! field || ! Object.prototype.hasOwnProperty.call( data.values, field ) ) {
					continue;
				}

				if ( 'hidden' === type || 'consent' === type || 'checkbox' === type || 'radio' === type || 'select' === type ) {
					continue;
				}

				if ( typeof data.values[ field ] !== 'string' ) {
					continue;
				}

				var control = wrapper.querySelector( 'input,textarea' );

				if ( control ) {
					control.value = data.values[ field ];
					restored = true;
				}
			}

			if ( restored ) {
				announce( form, text( 'retained', 'Your previously entered answers have been kept. Please check them before sending.' ) );
			}
		} catch ( e ) {
			// A damaged stash is discarded rather than trusted.
		}
	}

	/**
	 * The stale-page state: keep the values, refuse to submit, offer a reload.
	 *
	 * @param {HTMLFormElement} form The form.
	 * @return {void}
	 */
	function stale( form ) {
		stash( form );
		setState( form, 'stale' );
		announce( form, text( 'stale', 'This form has changed since this page was loaded. Reload the page to see the current version.' ) );

		var control = button( form );

		if ( control ) {
			control.disabled = true;
		}

		showReload( form );
	}

	/**
	 * Add the reload button, once.
	 *
	 * @param {HTMLFormElement} form The form.
	 * @return {void}
	 */
	function showReload( form ) {
		if ( form.querySelector( '.' + RELOAD_CLASS ) ) {
			return;
		}

		var region = form.querySelector( STATUS_SELECTOR );

		if ( ! region || ! region.parentNode ) {
			return;
		}

		var reload = document.createElement( 'button' );

		reload.type = 'button';
		reload.className = 'button ' + RELOAD_CLASS;
		reload.textContent = text( 'reload', 'Reload the form' );
		reload.addEventListener( 'click', function () {
			window.location.reload();
		} );

		region.parentNode.insertBefore( reload, region.nextSibling );
		reload.focus();
	}

	function removeReload( form ) {
		var existing = form.querySelector( '.' + RELOAD_CLASS );

		if ( existing && existing.parentNode ) {
			existing.parentNode.removeChild( existing );
		}
	}

	/**
	 * Show field errors, mark the controls and focus the summary.
	 *
	 * @param {HTMLFormElement} form   The form.
	 * @param {Object}          errors Field key => message.
	 * @return {void}
	 */
	function showErrors( form, errors ) {
		var summary = form.querySelector( SUMMARY_SELECTOR );

		clearErrors( form );

		if ( ! summary ) {
			return;
		}

		while ( summary.firstChild ) {
			summary.removeChild( summary.firstChild );
		}

		var title = document.createElement( 'p' );
		title.className = 'agentshell-forms__summary-title';
		title.textContent = text( 'summary', 'There is a problem' );

		var list = document.createElement( 'ul' );
		list.className = 'agentshell-forms__summary-list';

		Object.keys( errors || {} ).forEach( function ( key ) {
			var wrapper = findField( form, key );
			var li = document.createElement( 'li' );
			var control = wrapper ? wrapper.querySelector( 'input,select,textarea' ) : null;
			var label = wrapper ? wrapper.querySelector( 'label' ) : null;

			li.appendChild( document.createTextNode( ( label ? label.textContent : key ) + ': ' ) );

			var link = document.createElement( 'a' );

			link.href = '#' + ( control && control.id ? control.id : key );
			link.textContent = errors[ key ];
			li.appendChild( link );
			list.appendChild( li );

			if ( wrapper && control ) {
				markInvalid( wrapper, control, errors[ key ] );
			}
		} );

		summary.appendChild( title );
		summary.appendChild( list );
		summary.hidden = false;
		summary.classList.add( 'agentshell-forms__summary--visible' );
		summary.focus();
	}

	function findField( form, key ) {
		var wrappers = form.querySelectorAll( '[data-field-key]' );

		for ( var i = 0; i < wrappers.length; i++ ) {
			if ( wrappers[ i ].getAttribute( 'data-field-key' ) === key ) {
				return wrappers[ i ];
			}
		}

		return null;
	}

	function markInvalid( wrapper, control, message ) {
		var id = control.id ? control.id + '-error' : '';
		var error = wrapper.querySelector( '.agentshell-forms__error' );

		if ( ! error ) {
			error = document.createElement( 'p' );
			error.className = 'agentshell-forms__error';

			if ( id ) {
				error.id = id;
			}

			wrapper.appendChild( error );
		}

		error.textContent = message;

		var controls = wrapper.querySelectorAll( 'input,select,textarea' );

		for ( var i = 0; i < controls.length; i++ ) {
			controls[ i ].setAttribute( 'aria-invalid', 'true' );

			if ( id ) {
				var described = controls[ i ].getAttribute( 'aria-describedby' );

				if ( ! described || described.indexOf( id ) === -1 ) {
					controls[ i ].setAttribute( 'aria-describedby', ( described ? described + ' ' : '' ) + id );
				}
			}
		}
	}

	function clearErrors( form ) {
		var summary = form.querySelector( SUMMARY_SELECTOR );

		if ( summary ) {
			while ( summary.firstChild ) {
				summary.removeChild( summary.firstChild );
			}

			summary.hidden = true;
			summary.classList.remove( 'agentshell-forms__summary--visible' );
		}

		var errors = form.querySelectorAll( '.agentshell-forms__error' );

		for ( var i = 0; i < errors.length; i++ ) {
			if ( errors[ i ].parentNode ) {
				errors[ i ].parentNode.removeChild( errors[ i ] );
			}
		}

		var controls = form.querySelectorAll( '[aria-invalid="true"]' );

		for ( var c = 0; c < controls.length; c++ ) {
			controls[ c ].removeAttribute( 'aria-invalid' );
		}
	}

	/**
	 * Send one submission.
	 *
	 * @param {HTMLFormElement} form The form.
	 * @return {void}
	 */
	function submit( form ) {
		if ( inFlight.has( form ) || 'sent' === form.getAttribute( 'data-submission-state' ) ) {
			return;
		}

		var input = form.querySelector( CHALLENGE_SELECTOR );
		var endpoint = form.getAttribute( 'data-submit-endpoint' );
		var challenge = input ? input.value : '';

		if ( ! challenge || 'ready' !== form.getAttribute( 'data-challenge-state' ) ) {
			// The token was never provisioned, or provisioning failed. Trying
			// again before giving up is what makes a transient failure
			// recoverable without a reload.
			provision( form ).then( function () {
				if ( 'ready' === form.getAttribute( 'data-challenge-state' ) && input && input.value ) {
					submit( form );
				} else if ( 'stale' !== form.getAttribute( 'data-challenge-state' ) ) {
					announce( form, text( 'error', 'This form could not be prepared. Please reload the page and try again.' ) );
				}
			} );

			return;
		}

		if ( ! endpoint || typeof window.fetch !== 'function' ) {
			announce( form, text( 'unsupported', 'This form cannot be started in this browser.' ) );

			return;
		}

		var version = renderedVersion( form );
		var minted = mintedVersion.get( form );

		// The cached-page case, caught before anything is sent.
		if ( minted && minted !== version ) {
			stale( form );

			return;
		}

		clearErrors( form );

		var body = {
			form_id: form.getAttribute( 'data-form-id' ),
			form_version: version,
			challenge: challenge,
			fields: collectFields( form )
		};

		var honeypot = form.querySelector( HONEYPOT_SELECTOR );

		if ( honeypot ) {
			body.agentshell_forms_hp = honeypot.value;
		}

		var turnstile = form.querySelector( TURNSTILE_SELECTOR );

		if ( turnstile ) {
			body.turnstile_token = turnstile.value;
		}

		inFlight.add( form );
		autoRetries.set( form, 0 );
		busy( form );
		announce( form, '' );

		send( form, endpoint, body );
	}

	/**
	 * Send a request and handle the answer.
	 *
	 * @param {HTMLFormElement} form     The form.
	 * @param {string}          endpoint The route.
	 * @param {Object}          body     The JSON body.
	 * @return {void}
	 */
	function send( form, endpoint, body ) {
		window
			.fetch( endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
				body: JSON.stringify( body )
			} )
			.then( function ( response ) {
				return response
					.json()
					.then(
						function ( parsed ) {
							return { status: response.status, ok: response.ok, body: parsed };
						},
						function () {
							return { status: response.status, ok: response.ok, body: null };
						}
					);
			} )
			.then( function ( result ) {
				handle( form, result, endpoint, body );
			} )
			.catch( function () {
				// A network failure means the outcome is unknown. Retrying with
				// the same challenge is safe: if the first request landed, the
				// server replays its success; if it did not, this one creates
				// the single submission.
				var tries = autoRetries.get( form ) || 0;

				if ( tries < 1 ) {
					autoRetries.set( form, tries + 1 );

					delay( 1200 ).then( function () {
						send( form, endpoint, body );
					} );

					return;
				}

				inFlight.delete( form );
				idle( form );

				// The request may have reached the server, in which case its
				// Turnstile token is spent. Renewing it costs the visitor a
				// challenge they may not have needed; not renewing it costs
				// them every future attempt.
				renewTurnstile( form );

				announce( form, text( 'network', 'The form could not be sent. Please check your connection and try again.' ) );
			} );
	}

	/**
	 * Handle one submission response.
	 *
	 * @param {HTMLFormElement} form     The form.
	 * @param {Object}          result   Status, ok and the parsed body.
	 * @param {string}          endpoint The route, for a retry.
	 * @param {Object}          body     The request body, for a retry.
	 * @return {void}
	 */
	function handle( form, result, endpoint, body ) {
		var data = result.body && typeof result.body === 'object' ? result.body : null;
		var code = data && typeof data.code === 'string' ? data.code : '';

		if ( data && data.ok && ! code ) {
			announce( form, data.message || '' );
			sent( form );
			inFlight.delete( form );

			if ( data.redirect ) {
				window.location.assign( data.redirect );
			}

			return;
		}

		if ( 'form_invalid' === code ) {
			inFlight.delete( form );
			idle( form );
			// The token was verified while the server decided to refuse, so it
			// is spent; the corrected attempt needs a new one.
			renewTurnstile( form );
			showErrors( form, data.errors || {} );
			return;
		}

		if ( 'form_stale' === code ) {
			// The request is over, and the form is not sending any more even
			// though it did not send. Leaving `data-submission-state` at
			// `sending` would tell a stylesheet and a test that a request is
			// still in flight, and a client that waits for it would wait
			// forever.
			inFlight.delete( form );
			idle( form );
			stale( form );
			return;
		}

		if ( 'form_pending' === code ) {
			var tries = autoRetries.get( form ) || 0;
			var wait = data && data.retry_after ? parseInt( data.retry_after, 10 ) : 2;

			if ( tries < 2 ) {
				autoRetries.set( form, tries + 1 );
				announce( form, text( 'pending', 'This submission is still being processed. Please wait a moment…' ) );

				delay( ( isNaN( wait ) ? 2 : wait ) * 1000 ).then( function () {
					send( form, endpoint, body );
				} );

				return;
			}

			inFlight.delete( form );
			idle( form );
			announce( form, text( 'pending', 'This submission is still being processed. Please wait a moment…' ) );
			return;
		}

		if ( 'form_retry' === code ) {
			inFlight.delete( form );
			idle( form );
			renewTurnstile( form );

			if ( data && typeof data.challenge === 'string' && data.challenge ) {
				var input = form.querySelector( CHALLENGE_SELECTOR );

				if ( input ) {
					input.value = data.challenge;
				}

				mintedVersion.set( form, typeof data.form_version === 'number' ? data.form_version : renderedVersion( form ) );
				setState( form, 'ready' );
				announce( form, data.message || '' );

				return;
			}

			provision( form );
			return;
		}

		// Any other refusal. The body never names which control fired, so the
		// message is shown as it is; the form is re-enabled so the visitor can
		// try again — with a token that has not already been spent.
		inFlight.delete( form );
		idle( form );
		renewTurnstile( form );
		announce( form, data && data.message ? data.message : text( 'network', 'The form could not be sent. Please check your connection and try again.' ) );
	}

	/**
	 * Bind one form.
	 *
	 * @param {HTMLFormElement} form The form.
	 * @return {void}
	 */
	function bind( form ) {
		if ( form.getAttribute( 'data-agentshell-forms-bound' ) ) {
			return;
		}

		form.setAttribute( 'data-agentshell-forms-bound', '1' );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			submit( form );
		} );
	}

	function bindAll() {
		var forms = document.querySelectorAll( SELECTOR );

		for ( var i = 0; i < forms.length; i++ ) {
			bind( forms[ i ] );
		}
	}

	/**
	 * Restore stashed answers after provisioning, because a successful
	 * provision clears the status region and would otherwise wipe the
	 * "your answers were kept" notice the restore just put there.
	 *
	 * @return {void}
	 */
	function restoreAll() {
		var forms = document.querySelectorAll( SELECTOR );

		for ( var i = 0; i < forms.length; i++ ) {
			restore( forms[ i ] );
		}
	}

	function onPageShow( event ) {
		if ( ! event || ! event.persisted ) {
			return;
		}

		var forms = document.querySelectorAll( SELECTOR );

		for ( var i = 0; i < forms.length; i++ ) {
			clear( forms[ i ] );
		}

		provisionAll();
	}

	function init() {
		bindAll();
		provisionAll().then( restoreAll );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	window.addEventListener( 'pageshow', onPageShow );

	window.AgentShellForms = {
		version: '0.3.0',
		provision: provision,
		provisionAll: provisionAll,
		clear: clear,
		submit: submit
	};
}() );
