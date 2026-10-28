/*
 * AgentShell Forms — per-visitor challenge provisioning.
 *
 * ## What this script is for, and what it is not
 *
 * The server renders a form with an **empty** challenge input. A challenge is
 * single-use, so a challenge written into the HTML would be spent by the first
 * visitor who loaded a cached page — and every visitor after them would present
 * a token that was already claimed. This script asks the server for one token
 * **per form instance, per visitor**, after the page has been delivered. That
 * is the whole reason the page can be cached and the whole reason this file
 * exists.
 *
 * It does not validate the form, does not submit it and does not know what a
 * submission is. Phase 3 owns the submission protocol; Phase 2 owns getting a
 * usable token into a cached page without sharing it between visitors.
 *
 * ## The states it reports
 *
 * `data-challenge-state` on the `<form>` is `pending` while a request is in
 * flight, `ready` when a token is in the input, and `error` when the request
 * failed. The attribute exists so a test — and a future stylesheet — can see the
 * state without guessing. A failure is announced in the form's existing
 * `role="status"` region, so a screen-reader user learns that the form is not
 * ready rather than discovering it when the submit button does nothing.
 *
 * ## Back/forward cache
 *
 * A page restored from the bfcache carries the token it had when it was left —
 * which may already be spent. `pageshow` with `event.persisted` therefore clears
 * every instance's token and provisions again. The clear happens before the
 * request, so a form is never `ready` with a token from a previous life.
 *
 * ## Why `fetch` here, when the theme's widgets may not fetch
 *
 * The AgentShell theme's widget runtime forbids `fetch` (AGENTS.md §4.4)
 * because a widget's data must come from the page it was rendered into. This is
 * not that: the token cannot come from the page, by design, and this plugin is
 * theme-independent and has no widget-hydration contract to honour. The request
 * goes to the site's own REST route and nowhere else.
 */

( function () {
	'use strict';

	var SELECTOR = 'form[data-agentshell-form]';
	var CHALLENGE_SELECTOR = '[data-agentshell-forms-challenge]';
	var STATUS_SELECTOR = '[data-agentshell-forms-status]';

	function strings() {
		var l10n = window.agentshellFormsL10n;

		return l10n && typeof l10n === 'object' ? l10n : {};
	}

	function announce( form, message ) {
		var region = form.querySelector( STATUS_SELECTOR );

		if ( ! region ) {
			return;
		}

		// `textContent`, never `innerHTML`: the message is plugin-supplied today,
		// and a status region is not a place to start trusting strings.
		//
		// An empty message **clears** the region. It used to be ignored, which
		// left a failure message on screen after the form had recovered — the
		// visitor kept reading "could not be prepared" over a form that was
		// ready to send.
		region.textContent = message ? String( message ) : '';
	}

	function setState( form, state ) {
		form.setAttribute( 'data-challenge-state', state );
	}

	/**
	 * Per-form request generations.
	 *
	 * Only the newest request may write to a form. A response that arrives
	 * after a newer one has started is stale by definition: the page may have
	 * been restored from the bfcache (which invalidates every token), or a
	 * caller may have asked twice, and the older answer would overwrite the
	 * newer token with one nobody is holding any more.
	 *
	 * A `WeakMap` rather than an expando property, because the form element is
	 * page content and this state is the script's, not the page's.
	 */
	var generations = new WeakMap();

	function nextGeneration( form ) {
		var next = ( generations.get( form ) || 0 ) + 1;

		generations.set( form, next );

		return next;
	}

	function generationOf( form ) {
		return generations.get( form ) || 0;
	}

	function clear( form ) {
		var input = form.querySelector( CHALLENGE_SELECTOR );

		// Invalidate anything in flight *before* dropping the token, so a
		// response for the previous page-life cannot land in this one.
		nextGeneration( form );

		if ( input ) {
			input.value = '';
		}

		announce( form, '' );
		setState( form, 'idle' );
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
			announce( form, strings().error || 'This form could not be prepared. Please reload the page and try again.' );

			return Promise.resolve();
		}

		if ( typeof window.fetch !== 'function' ) {
			setState( form, 'error' );
			announce( form, strings().unsupported || 'This form cannot be started in this browser.' );

			return Promise.resolve();
		}

		setState( form, 'pending' );

		return window
			.fetch( endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
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

				input.value = token;
				setState( form, 'ready' );

				// A form that has recovered must not keep the failure it
				// announced while it was broken.
				announce( form, '' );
			} )
			.catch( function () {
				if ( generationOf( form ) !== mine ) {
					return;
				}

				input.value = '';
				setState( form, 'error' );
				announce( form, strings().error || 'This form could not be prepared. Please reload the page and try again.' );
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
		provisionAll();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	window.addEventListener( 'pageshow', onPageShow );

	window.AgentShellForms = {
		version: '0.1.0',
		provision: provision,
		provisionAll: provisionAll,
		clear: clear
	};
}() );
