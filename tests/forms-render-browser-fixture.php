<?php
/**
 * Generate the page the browser suite drives.
 *
 *   php tests/forms-render-browser-fixture.php /path/to/out.html
 *
 * The page is the real thing: the plugin's real stylesheet, the plugin's real
 * provisioning script, and markup produced by the real renderer. The only
 * fabrication is `window.fetch`, which the harness replaces with an immediate
 * resolver — a `file://` document cannot reach the REST route, and the server
 * side of token issuance is proved against a real WordPress in
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

$css = 'file://' . $assets . 'forms.css';
$js  = 'file://' . $assets . 'forms.js';

$page = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>pending</title>
<link rel="stylesheet" href="__CSS__">
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
    var state = window.__asfHarness = {
        calls: [],
        counter: 0,
        fail: /(?:^|[?&])scenario=fail(?:&|$)/.test( window.location.search ),
        pageErrors: []
    };

    window.addEventListener( 'error', function ( event ) {
        state.pageErrors.push( String( event.message || 'error' ) );
    } );

    window.fetch = function ( url, options ) {
        var body = null;
        try { body = JSON.parse( ( options && options.body ) || '{}' ); } catch ( e ) { body = null; }

        state.counter += 1;

        state.calls.push( {
            url: String( url ),
            method: options && options.method,
            body: body,
            token: 'test-token-' + state.counter + '-a1b2c3d4'
        } );

        if ( state.fail ) {
            return Promise.reject( new Error( 'the network is down' ) );
        }

        return Promise.resolve( {
            ok: true,
            status: 200,
            json: function () {
                return Promise.resolve( {
                    challenge: state.calls[ state.calls.length - 1 ].token,
                    form_version: 3,
                    expires_in: 7200
                } );
            }
        } );
    };
}());
</script>
<script src="__JS__"></script>
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

    function report() {
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

        if ( harness.fail ) {
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
        var fieldsets = all( 'fieldset' );
        check( 'radio and checkbox groups are fieldsets', fieldsets.length >= 10, fieldsets.length );
        check( 'every fieldset has a legend', fieldsets.every( function ( fs ) {
            return fs.querySelector( 'legend' ) !== null;
        } ) );

        var radios = all( 'input[type=radio]' );
        check( 'radios in one group share a name', radios.length >= 8 && radios.every( function ( radio ) {
            return radio.name === 'fields[contact_method]';
        } ) );
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
        check( 'the required marker is text', all( '.agentshell-forms__required' ).length >= requiredControls.length );

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
        check( 'a hostile label is visible as text', hostile !== null &&
            hostile.textContent.indexOf( '<script>alert(1)</script>' ) !== -1 &&
            hostile.textContent.indexOf( '<img src=x onerror="alert(2)">' ) !== -1 );
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

    function start() {
        if ( ! document.querySelector( 'form[data-agentshell-form]' ) ) {
            document.title = JSON.stringify( { harness_error: 'the page rendered no forms' } );
            return;
        }

        // Wait until every instance has reached a terminal state — ready or
        // error. The stub resolves in a microtask, so this is fast; the bound
        // exists so a broken script reports a result instead of hanging the
        // browser until its virtual time budget runs out.
        var attempts = 0;

        function waitSettled() {
            attempts += 1;
            if ( settled() || attempts > 240 ) {
                run();
                return;
            }
            window.setTimeout( waitSettled, 10 );
        }

        waitSettled();
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

if ( false === file_put_contents( $out, $page ) ) {
	fwrite( STDERR, "could not write {$out}\n" );
	exit( 1 );
}

echo "wrote {$out} (" . strlen( $page ) . " bytes)\n";
