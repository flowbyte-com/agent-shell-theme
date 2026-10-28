<?php
/**
 * Generate the page the browser suite drives.
 *
 *   php tests/forms-render-browser-fixture.php /path/to/out.html
 *
 * The page is the real thing: the plugin's real stylesheet, the plugin's real
 * provisioning script, and markup produced by the real renderer. Both assets are
 * **inlined** rather than linked, which changes nothing about what runs or what
 * the browser computes but removes every subresource request: a headless
 * browser driven with a virtual time budget can pause that budget while a
 * subresource is pending, and a suite that hangs is a suite that reports
 * nothing. The bytes are read from the real files, so the page tests the
 * shipped stylesheet and the shipped script.
 *
 * The only fabrication is `window.fetch`, which the harness replaces with an
 * immediate resolver — a `file://` document cannot reach the REST route, and the
 * server side of token issuance is proved against a real WordPress in
 * `tests/forms-render-live-test.php` instead.
 *
 * The stub counts its calls and returns a different token per call, so the page
 * can prove that each form instance provisions its own challenge. It also has a
 * failure mode (`?scenario=fail`), because "the form is not ready and says so"
 * is a state a visitor can meet and the interface must report it.
 *
 * Scenarios:
 *   ok   — every instance provisions; the page then exercises the bfcache path
 *   fail — every request fails; each form must report the error, not hang
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/forms-render-fixtures.php';

use AgentShell_Forms\Form_Renderer;

$out = $argv[1] ?? '';

if ( '' === $out ) {
	fwrite( STDERR, "usage: php forms-render-browser-fixture.php <out.html>\n" );
	exit( 1 );
}

$assets = dirname( __DIR__ ) . '/agentshell-forms/assets/';

$definition_a = agentshell_forms_fixture_definition();
$definition_b = agentshell_forms_fixture_second_definition();

// The invalid state: a rejected submission re-rendered with what the visitor
// typed, plus the errors Phase 3 will produce.
$invalid = Form_Renderer::render_definition(
	$definition_a,
	array(
		'instance' => 4,
		'values'   => array(
			'full_name' => '',
			'email'     => 'not-an-address',
			'topic'     => 'support',
			'topics'    => array( 'design' ),
		),
		'errors'   => array(
			'full_name' => 'Please tell us your name.',
			'email'     => 'Please check the email address.',
		),
		'status'   => '',
	)
);

$evil = agentshell_forms_fixture_second_definition();
$evil['name']                          = 'Evil <script>alert(1)</script>';
$evil['fields'][0]['label']            = 'Label <img src=x onerror="alert(2)">';
$evil['fields'][0]['options']          = array();
$evil['fields'][0]['placeholder']      = '" onfocus="alert(3)';
$evil['settings']['submit_label']      = '</button><script>alert(4)</script>';

$evil_html = Form_Renderer::render_definition( $evil, array( 'instance' => 5 ) );

$forms = array(
	'form A, instance 1' => Form_Renderer::render_definition( $definition_a, array( 'instance' => 1 ) ),
	'form A, instance 2' => Form_Renderer::render_definition( $definition_a, array( 'instance' => 2 ) ),
	'form B, instance 3' => Form_Renderer::render_definition( $definition_b, array( 'instance' => 3 ) ),
	'form A, invalid'    => $invalid,
	'form A, hostile'    => $evil_html,
);

$css = (string) file_get_contents( $assets . 'forms.css' );
$js  = (string) file_get_contents( $assets . 'forms.js' );

if ( '' === $css || '' === $js ) {
	fwrite( STDERR, "could not read the plugin's assets from {$assets}\n" );
	exit( 1 );
}

// The plugin's script is not HTML-embedded in production (it is a file served
// with a JavaScript content type), so a literal `</script` cannot appear in it.
// Refusing to generate a page that would be truncated is better than shipping a
// fixture whose script silently ends early.
if ( false !== stripos( $js, '</script' ) || false !== stripos( $css, '</style' ) ) {
	fwrite( STDERR, "an asset contains a closing tag that would end its inline element\n" );
	exit( 1 );
}

$page = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>pending</title>
<style>
__CSS__
</style>
<script>
/*
 * The harness's fetch stub.
 *
 * It is installed before the plugin's script so the plugin's own code path —
 * `window.fetch(...)` with a JSON body — is the code under test. Each call
 * returns a different token, and the call log lets the page prove that every
 * form instance asked for exactly one.
 */
(function () {
    var scenario = ( function () {
        var m = /(?:^|[?&])scenario=([a-z]+)/.exec( window.location.search );
        return m ? m[1] : 'ok';
    }() );

    var state = window.__asfHarness = {
        calls: [],
        counter: 0,
        scenario: scenario,
        // The first `failFirst` requests fail, then the network is fine. That is
        // what a form whose endpoint was briefly unavailable looks like, and it
        // is the state a bfcache restore has to be able to leave behind.
        failFirst: 'restore' === scenario ? 5 : 0,
        // In `race` mode the first batch of requests is held open, so the page
        // can restore itself and finish a newer request before the older
        // answers arrive — the overlap a generation counter exists to survive.
        manual: [],
        manualMode: 'race' === scenario,
        pageErrors: []
    };

    window.addEventListener( 'error', function ( event ) {
        state.pageErrors.push( String( event.message || 'error' ) );
    } );

    function response( token ) {
        return {
            ok: true,
            status: 200,
            json: function () {
                return Promise.resolve( {
                    challenge: token,
                    form_version: 3,
                    expires_in: 7200
                } );
            }
        };
    }

    window.fetch = function ( url, options ) {
        var body = null;
        try { body = JSON.parse( ( options && options.body ) || '{}' ); } catch ( e ) { body = null; }

        state.counter += 1;

        // The token is captured *now*, not when the response is read. A stub
        // that read the last call's token at resolution time would hand every
        // waiting caller the most recent one — which is the bug the page is
        // meant to detect, so the stub must not have it too.
        var token = 'test-token-' + state.counter + '-a1b2c3d4';

        state.calls.push( {
            url: String( url ),
            method: options && options.method,
            body: body,
            token: token
        } );

        if ( 'race' === scenario && state.manualMode ) {
            return new Promise( function ( resolve ) {
                state.manual.push( {
                    token: token,
                    resolve: function () {
                        resolve( response( token ) );
                    }
                } );
            } );
        }

        if ( 'fail' === scenario || state.counter <= state.failFirst ) {
            return Promise.reject( new Error( 'the network is down' ) );
        }

        return Promise.resolve( response( token ) );
    };
}());
</script>
<script>
__JS__
</script>
</head>
<body>
<main id="content">
<h1>Forms</h1>
__FORMS__
</main>
<script>
/*
 * The assertions.
 *
 * They run after the plugin has provisioned every instance, and report one JSON
 * object in the document title — the shape the repo's other browser suites use,
 * because `--dump-dom` is the only reliable way to get a result out of a
 * headless browser here.
 */
(function () {
    var harness = window.__asfHarness;
    var results = [];
    var failures = [];

    function check( name, condition, detail ) {
        results.push( name );
        if ( ! condition ) {
            failures.push( { name: name, detail: detail === undefined ? null : String( detail ) } );
        }
    }

    function forms() {
        return Array.prototype.slice.call( document.querySelectorAll( 'form[data-agentshell-form]' ) );
    }

    function all( selector, root ) {
        return Array.prototype.slice.call( ( root || document ).querySelectorAll( selector ) );
    }

    function parseColor( value ) {
        var m = /rgba?\(([^)]+)\)/.exec( value || '' );
        if ( ! m ) { return null; }
        var parts = m[ 1 ].split( ',' ).map( function ( p ) { return parseFloat( p ); } );
        return { r: parts[ 0 ], g: parts[ 1 ], b: parts[ 2 ], a: parts.length > 3 ? parts[ 3 ] : 1 };
    }

    function luminance( c ) {
        function channel( v ) {
            v = v / 255;
            return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
        }
        return 0.2126 * channel( c.r ) + 0.7152 * channel( c.g ) + 0.0722 * channel( c.b );
    }

    function contrast( foreground, background ) {
        var a = luminance( foreground );
        var b = luminance( background );
        var lighter = Math.max( a, b );
        var darker = Math.min( a, b );
        return ( lighter + 0.05 ) / ( darker + 0.05 );
    }

    function effectiveBackground( el ) {
        var node = el;
        while ( node && node !== document.documentElement ) {
            var c = parseColor( window.getComputedStyle( node ).backgroundColor );
            if ( c && c.a > 0 ) { return c; }
            node = node.parentNode;
        }
        return { r: 255, g: 255, b: 255, a: 1 };
    }

    // The watchdog. If the assertions never finish — a thrown error, a promise
    // that never settles, a selector that changed shape — the page still
    // reports, because a browser that is killed by a timeout tells the runner
    // nothing at all. `report()` cancels it.
    var watchdog = window.setTimeout( function () {
        document.title = JSON.stringify( {
            harness_error: 'the assertions did not finish',
            total: results.length,
            failures: failures,
            errors: harness.pageErrors
        } );
    }, 15000 );

    function report() {
        window.clearTimeout( watchdog );

        document.title = JSON.stringify( {
            total: results.length,
            failures: failures,
            errors: harness.pageErrors,
            calls: harness.calls.length
        } );
    }

    function ready() {
        return forms().every( function ( form ) {
            return form.getAttribute( 'data-challenge-state' ) === 'ready';
        } );
    }

    function settled() {
        return forms().every( function ( form ) {
            var state = form.getAttribute( 'data-challenge-state' );
            return state === 'ready' || state === 'error';
        } );
    }

    function run() {
        var list = forms();
        var tokens = list.map( function ( form ) {
            var input = form.querySelector( '[data-agentshell-forms-challenge]' );
            return input ? input.value : '';
        } );

        // ── provisioning ────────────────────────────────────────────────────
        check( 'the page contains five form instances', list.length === 5, list.length );

        if ( 'fail' === harness.scenario ) {
            check( 'a failed provisioning is reported as an error state', list.every( function ( form ) {
                return form.getAttribute( 'data-challenge-state' ) === 'error';
            } ) );

            check( 'a failed provisioning is announced in the status region', list.every( function ( form ) {
                var region = form.querySelector( '[data-agentshell-forms-status]' );
                return region && region.textContent.length > 0;
            } ) );

            check( 'a failed provisioning leaves no token behind', tokens.every( function ( t ) { return t === ''; } ) );
            report();
            return;
        }

        check( 'every form instance is ready', ready() );
        check( 'every instance has a token', tokens.every( function ( t ) { return t !== ''; } ) );
        check( 'no two instances share a token', new Set( tokens ).size === tokens.length, JSON.stringify( tokens ) );

        check( 'each instance asked for exactly one challenge', harness.calls.length === list.length, harness.calls.length );
        check( 'each request carried the form identifier', harness.calls.every( function ( call ) {
            return call.body && /^[a-f0-9]{32}$/.test( call.body.form_id );
        } ) );
        check( 'the two instances of the same form asked for the same form', (function () {
            var ids = harness.calls.map( function ( call ) { return call.body.form_id; } );
            return ids[ 0 ] === ids[ 1 ];
        }()) );

        // ── unique ids ──────────────────────────────────────────────────────
        var ids = all( '[id]' ).map( function ( el ) { return el.id; } );
        check( 'every DOM id on the page is unique', new Set( ids ).size === ids.length,
            ids.filter( function ( id, i ) { return ids.indexOf( id ) !== i; } ).join( ',' ) );

        // ── labels ──────────────────────────────────────────────────────────
        var labelled = 0;
        var unlabelled = [];
        all( 'label[for]' ).forEach( function ( label ) {
            var control = document.getElementById( label.getAttribute( 'for' ) );
            if ( control && control === label.control ) {
                labelled += 1;
            } else {
                unlabelled.push( label.getAttribute( 'for' ) );
            }
        } );
        check( 'every label is associated with its control', unlabelled.length === 0, unlabelled.join( ',' ) );
        check( 'labels were actually found', labelled >= 20, labelled );

        var firstLabel = document.querySelector( '.agentshell-forms__field--text label[for]' );
        var firstControl = firstLabel ? document.getElementById( firstLabel.getAttribute( 'for' ) ) : null;
        if ( firstLabel && firstControl ) {
            firstLabel.click();
            check( 'activating a label focuses its control', document.activeElement === firstControl,
                document.activeElement ? document.activeElement.tagName : 'none' );
        }

        // ── groups ──────────────────────────────────────────────────────────
        // Three instances of the ten-field form, each with a radio group and a
        // checkbox group; the two shorter forms have none.
        var fieldsets = all( 'fieldset' );
        check( 'radio and checkbox groups are fieldsets', fieldsets.length === 6, fieldsets.length );
        check( 'every fieldset has a legend', fieldsets.every( function ( fs ) {
            return fs.querySelector( 'legend' ) !== null;
        } ) );

        var radios = all( 'input[type=radio]' );
        check( 'radios in one group share a name', radios.length === 6 && radios.every( function ( radio ) {
            return radio.name === 'fields[contact_method]';
        } ), radios.length );
        check( 'at most one radio in a group is checked', (function () {
            var perForm = {};
            radios.forEach( function ( radio ) {
                var form = radio.form;
                var key = form ? form.getAttribute( 'data-form-instance' ) : 'none';
                perForm[ key ] = ( perForm[ key ] || 0 ) + ( radio.checked ? 1 : 0 );
            } );
            return Object.keys( perForm ).every( function ( key ) { return perForm[ key ] <= 1; } );
        }()) );

        check( 'checkbox groups submit arrays', all( 'input[type=checkbox][name$="[]"]' ).length >= 9 );

        // ── required and validation ─────────────────────────────────────────
        var requiredControls = all( '[required]' ).filter( function ( el ) {
            return el.type !== 'hidden';
        } );
        check( 'required controls carry the required attribute', requiredControls.length >= 4, requiredControls.length );

        // One marker per required *field*, not per control: a radio group is
        // two required radios behind one legend, so counting controls and
        // markers against each other would be wrong by design.
        var requiredFields = all( '.agentshell-forms__field' ).filter( function ( field ) {
            return field.querySelector( '[required]' ) !== null;
        } );

        check( 'every required field is marked in words', requiredFields.length >= 4 && requiredFields.every( function ( field ) {
            return field.querySelector( '.agentshell-forms__required' ) !== null;
        } ), requiredFields.length );

        var emptyRequired = document.querySelector( '.agentshell-forms__field--text input[required]' );
        if ( emptyRequired ) {
            check( 'the browser knows a required field is missing', emptyRequired.validity.valueMissing === true );
        }
        var invalidForm = document.querySelector( 'form[data-form-instance="4"]' );
        if ( invalidForm ) {
            check( 'native validation refuses the invalid form', invalidForm.checkValidity() === false );
            check( 'the invalid controls say so to assistive technology',
                all( '[aria-invalid="true"]', invalidForm ).length >= 2,
                all( '[aria-invalid="true"]', invalidForm ).length );
        }

        // ── focus and keyboard ──────────────────────────────────────────────
        var controls = all( '.agentshell-forms__control, .agentshell-forms__radio, .agentshell-forms__checkbox' );
        check( 'controls are not removed from the tab order', controls.every( function ( c ) { return c.tabIndex >= 0; } ) );

        var unfocusable = [];
        controls.forEach( function ( control ) {
            if ( control.type === 'hidden' ) { return; }
            control.focus();
            if ( document.activeElement !== control ) { unfocusable.push( control.id ); }
        } );
        check( 'every control can receive focus', unfocusable.length === 0, unfocusable.join( ',' ) );

        check( 'the focus order matches the document order', (function () {
            var ordered = controls.filter( function ( c ) { return c.type !== 'hidden'; } );
            var previousTop = -Infinity;
            var outOfOrder = 0;
            ordered.forEach( function ( control ) {
                var box = control.getBoundingClientRect();
                if ( box.width === 0 && box.height === 0 ) { return; }
                if ( box.top < previousTop - 2 ) { outOfOrder += 1; }
                previousTop = Math.max( previousTop, box.top );
            } );
            return outOfOrder === 0;
        }()) );

        // ── the honeypot ────────────────────────────────────────────────────
        var traps = all( '.agentshell-forms__hp' );
        check( 'every form has a honeypot', traps.length === 5, traps.length );
        check( 'the honeypot is hidden from assistive technology', traps.every( function ( trap ) {
            return trap.getAttribute( 'aria-hidden' ) === 'true';
        } ) );
        check( 'the honeypot is off screen', traps.every( function ( trap ) {
            return trap.getBoundingClientRect().left < 0;
        } ) );
        check( 'the honeypot is not in the tab order', traps.every( function ( trap ) {
            var input = trap.querySelector( 'input' );
            return input.tabIndex === -1 && trap.getAttribute( 'aria-hidden' ) === 'true';
        } ) );

        // ── the error summary ───────────────────────────────────────────────
        var summary = document.querySelector( '.agentshell-forms__summary--visible' );
        check( 'the error summary is rendered for a rejected submission', summary !== null );
        if ( summary ) {
            check( 'the summary is announced', summary.getAttribute( 'role' ) === 'alert' );
            check( 'the summary is focusable so it can be moved to', summary.tabIndex === -1 );
            var link = summary.querySelector( 'a' );
            check( 'the summary links to the field', link && document.getElementById( link.getAttribute( 'href' ).slice( 1 ) ) !== null );
        }
        var hiddenSummary = document.querySelector( '.agentshell-forms__summary[hidden]' );
        check( 'a form with no errors carries an empty hidden summary', hiddenSummary !== null );

        // ── the status region ───────────────────────────────────────────────
        check( 'every form has a status region', all( '[role=status][aria-live=polite]' ).length === 5 );

        // ── escaping ────────────────────────────────────────────────────────
        var hostile = document.querySelector( 'form[data-form-instance="5"]' );
        check( 'a hostile label did not become an element', hostile !== null && hostile.querySelector( 'script, img' ) === null );
        // The expected text is assembled rather than written literally: a
        // literal closing script tag inside an inline script would end the
        // element early, and the rest of this file would be parsed as markup.
        var expectedLabelText = '<scr' + 'ipt>alert(1)</scr' + 'ipt>';
        var expectedImageText = '<img src=x onerror="alert(2)">';

        check( 'a hostile label is visible as text', hostile !== null &&
            hostile.textContent.indexOf( expectedLabelText ) !== -1 &&
            hostile.textContent.indexOf( expectedImageText ) !== -1 );
        check( 'the page had no JavaScript errors', harness.pageErrors.length === 0, harness.pageErrors.join( '; ' ) );

        // ── theme independence and contrast ─────────────────────────────────
        var control = document.querySelector( '.agentshell-forms__control' );
        var style = window.getComputedStyle( control );
        check( 'the plugin stylesheet applies without a theme', style.borderTopWidth === '1px' && parseFloat( style.paddingLeft ) > 0,
            style.borderTopWidth + '/' + style.paddingLeft );

        var textColor = parseColor( window.getComputedStyle( document.querySelector( '.agentshell-forms__label' ) ).color );
        check( 'label text meets the 4.5:1 contrast requirement', contrast( textColor, effectiveBackground( control ) ) >= 4.5,
            contrast( textColor, effectiveBackground( control ) ).toFixed( 2 ) );

        var button = document.querySelector( '.agentshell-forms__submit' );
        var buttonStyle = window.getComputedStyle( button );
        check( 'the submit button meets the 4.5:1 contrast requirement',
            contrast( parseColor( buttonStyle.color ), parseColor( buttonStyle.backgroundColor ) ) >= 4.5,
            contrast( parseColor( buttonStyle.color ), parseColor( buttonStyle.backgroundColor ) ).toFixed( 2 ) );

        control.focus();
        var focusedStyle = window.getComputedStyle( control );
        check( 'the focused control has a visible outline',
            focusedStyle.outlineStyle !== 'none' && parseFloat( focusedStyle.outlineWidth ) >= 2,
            focusedStyle.outlineStyle + '/' + focusedStyle.outlineWidth );

        // ── bfcache ─────────────────────────────────────────────────────────
        // A page restored from the back/forward cache still holds the token it
        // had when it was left, which may already be spent. The script must
        // clear every instance and provision again.
        var before = harness.calls.length;
        var event;
        try {
            event = new PageTransitionEvent( 'pageshow', { persisted: true } );
        } catch ( e ) {
            event = document.createEvent( 'Event' );
            event.initEvent( 'pageshow', true, true );
            event.persisted = true;
        }
        window.dispatchEvent( event );

        setTimeout( function () {
            var refilled = forms().map( function ( form ) {
                var input = form.querySelector( '[data-agentshell-forms-challenge]' );
                return input ? input.value : '';
            } );

            check( 'a bfcache restore provisions every instance again',
                harness.calls.length === before + list.length,
                harness.calls.length + ' vs ' + ( before + list.length ) );
            check( 'a bfcache restore does not leave the old tokens in place',
                new Set( refilled ).size === refilled.length && refilled.every( function ( t ) { return t !== ''; } ) );

            report();
        }, 50 );
    }

    /**
     * Dispatch a `pageshow` that looks like a bfcache restore.
     *
     * @return {void}
     */
    function restoreFromBfCache() {
        var event;

        try {
            event = new PageTransitionEvent( 'pageshow', { persisted: true } );
        } catch ( e ) {
            event = document.createEvent( 'Event' );
            event.initEvent( 'pageshow', true, true );
            event.persisted = true;
        }

        window.dispatchEvent( event );
    }

    function statusOf( form ) {
        var region = form.querySelector( '[data-agentshell-forms-status]' );
        return region ? region.textContent : null;
    }

    function tokensOf( list ) {
        return list.map( function ( form ) {
            var input = form.querySelector( '[data-agentshell-forms-challenge]' );
            return input ? input.value : '';
        } );
    }

    /**
     * Failure, then a bfcache restore, then success.
     *
     * The first five requests fail (the stub is told to), so the page begins in
     * the state a visitor sees when the mint route is briefly unavailable: an
     * error state, a message in the status region and no token. The restore must
     * drop that token, drop that *message*, and provision successfully — a form
     * that has recovered while still telling the visitor it could not be
     * prepared is a form that lies.
     *
     * @return {void}
     */
    function runRestore() {
        var list = forms();

        check( 'the page contains five form instances', list.length === 5, list.length );
        check( 'a failed first attempt is an error state', list.every( function ( form ) {
            return form.getAttribute( 'data-challenge-state' ) === 'error';
        } ) );
        check( 'a failed first attempt announces itself', list.every( function ( form ) {
            return ( statusOf( form ) || '' ).length > 0;
        } ), JSON.stringify( list.map( statusOf ) ) );
        check( 'a failed first attempt leaves no token', tokensOf( list ).every( function ( t ) { return t === ''; } ) );

        restoreFromBfCache();

        whenSettled( function () {
            var tokens = tokensOf( list );

            check( 'a bfcache restore recovers every instance', ready(), JSON.stringify( list.map( function ( form ) {
                return form.getAttribute( 'data-challenge-state' );
            } ) ) );
            check( 'a recovered instance has a token again', tokens.every( function ( t ) { return t !== ''; } ) );
            check( 'a recovered instance has a distinct token', new Set( tokens ).size === tokens.length );
            check( 'a recovered instance shows no failure message', list.every( function ( form ) {
                return statusOf( form ) === '';
            } ), JSON.stringify( list.map( statusOf ) ) );

            report();
        } );
    }

    /**
     * An older in-flight response must not overwrite a newer one.
     *
     * The first batch of requests is held open by the stub. The page is then
     * restored from the bfcache, which invalidates those tokens and starts a
     * second batch that completes immediately. Only after the page is ready are
     * the older answers delivered — out of order, newest first — and the tokens
     * on the page must still be the second batch's.
     *
     * @return {void}
     */
    function runRace() {
        var list = forms();
        var attempts = 0;

        function waitForFirstBatch() {
            attempts += 1;

            if ( harness.manual.length < list.length && attempts <= 240 ) {
                window.setTimeout( waitForFirstBatch, 10 );
                return;
            }

            var stale = harness.manual.slice();
            var staleTokens = stale.map( function ( entry ) { return entry.token; } );

            check( 'the first batch of requests is in flight', stale.length === list.length, stale.length );

            // From here the stub answers immediately: the "newer" requests.
            harness.manualMode = false;
            restoreFromBfCache();

            whenSettled( function () {
                var fresh = tokensOf( list );

                check( 'the newer requests provision every instance', ready() );
                check( 'the newer tokens are on the page', fresh.every( function ( token ) {
                    return token !== '' && staleTokens.indexOf( token ) === -1;
                } ), JSON.stringify( fresh ) );

                // Deliver the older answers, newest first, after the page is
                // already ready. None of them may land.
                stale.reverse().forEach( function ( entry ) { entry.resolve(); } );

                window.setTimeout( function () {
                    var after = tokensOf( list );

                    check( 'a stale response does not overwrite a newer token',
                        after.join( ',' ) === fresh.join( ',' ),
                        JSON.stringify( after ) );
                    check( 'a stale response does not change the state', ready() );
                    check( 'a stale response does not announce anything', list.every( function ( form ) {
                        return statusOf( form ) === '';
                    } ), JSON.stringify( list.map( statusOf ) ) );

                    report();
                }, 50 );
            } );
        }

        waitForFirstBatch();
    }

    /**
     * Wait until every instance has reached a terminal state — ready or error —
     * then run `callback`. The stub resolves in a microtask, so this is fast;
     * the bound exists so a broken script reports a result instead of hanging
     * the browser until its virtual time budget runs out.
     *
     * @param {Function} callback What to run once the page has settled.
     * @return {void}
     */
    function whenSettled( callback ) {
        var attempts = 0;

        function poll() {
            attempts += 1;

            if ( settled() || attempts > 240 ) {
                try {
                    callback();
                } catch ( e ) {
                    document.title = JSON.stringify( {
                        harness_error: 'an assertion threw: ' + ( e && e.message ? e.message : String( e ) ),
                        stack: e && e.stack ? String( e.stack ).slice( 0, 800 ) : null,
                        total: results.length,
                        failures: failures
                    } );
                }
                return;
            }

            window.setTimeout( poll, 10 );
        }

        poll();
    }

    function start() {
        if ( ! document.querySelector( 'form[data-agentshell-form]' ) ) {
            document.title = JSON.stringify( { harness_error: 'the page rendered no forms' } );
            return;
        }

        if ( 'race' === harness.scenario ) {
            runRace();
            return;
        }

        if ( 'restore' === harness.scenario ) {
            whenSettled( runRestore );
            return;
        }

        whenSettled( run );
    }

    if ( document.readyState === 'complete' ) {
        start();
    } else {
        window.addEventListener( 'load', start );
    }
}());
</script>
</body>
</html>
HTML;

$body = '';

foreach ( $forms as $label => $html ) {
	$body .= "<!-- {$label} -->\n" . $html . "\n";
}

$page = str_replace( array( '__CSS__', '__JS__', '__FORMS__' ), array( $css, $js, $body ), $page );

// The page has exactly three inline scripts (the fetch stub, the plugin's own
// script, and the assertions) and one inline style. A `</script>` that appears
// anywhere else has ended an element early — which would leave the rest of that
// script parsed as markup, and a stray `alert()` in a headless browser blocks
// the page forever. Refusing to generate such a page is the difference between
// a failing assertion and a job that times out saying nothing.
$script_closes = substr_count( $page, '</script>' );
if ( 3 !== $script_closes ) {
	fwrite( STDERR, "the generated page has {$script_closes} '</script>' sequences; expected 3\n" );
	exit( 1 );
}

if ( 1 !== substr_count( $page, '</style>' ) ) {
	fwrite( STDERR, 'the generated page has a stray </style>' . "\n" );
	exit( 1 );
}

if ( false === file_put_contents( $out, $page ) ) {
	fwrite( STDERR, "could not write {$out}\n" );
	exit( 1 );
}

echo "wrote {$out} (" . strlen( $page ) . " bytes)\n";
