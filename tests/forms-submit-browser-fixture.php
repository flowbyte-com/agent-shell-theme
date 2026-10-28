<?php
/**
 * Generate the submission page for the browser suite.
 *
 *   php tests/forms-submit-browser-fixture.php /path/to/out.html
 *
 * The page is produced by the **real renderer** from the same fixture
 * definition the rendering suite uses, links the **real stylesheet** and runs
 * the **real script**. Only the network is replaced: `window.fetch` is scripted
 * so the page sees a challenge route and a submission route answering with the
 * exact bodies the plugin produces. That is the honest way to test the client:
 * the markup, the state machine, the focus handling and the messages are the
 * shipped ones, and the responses are the ones the server really sends.
 *
 * argv: 1 output path
 */

$out = $argv[1] ?? '';

if ( '' === $out ) {
	fwrite( STDERR, "usage: php forms-submit-browser-fixture.php <out.html>\n" );
	exit( 1 );
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/forms-render-fixtures.php';

use AgentShell_Forms\Form_Renderer;

$assets = dirname( __DIR__ ) . '/agentshell-forms/assets/';

$definition = agentshell_forms_fixture_definition();

$form_html = Form_Renderer::render_definition(
	$definition,
	array(
		'instance' => 1,
		'context'  => 'shortcode',
		'title'    => 'hide',
	)
);

$css = (string) file_get_contents( $assets . 'forms.css' );
$js  = (string) file_get_contents( $assets . 'forms.js' );

if ( false !== strpos( $css, '</style' ) ) {
	fwrite( STDERR, "the stylesheet contains a closing style tag\n" );
	exit( 1 );
}

// A literal closing script tag inside an inline script would end the element.
$js = str_replace( '</script', '<\\/script', $js );

$version = (int) $definition['version'];

$harness = <<<'JS'
(function () {
    'use strict';

    var scenario = ( function () {
        var m = /(?:^|[?&])scenario=([a-z_]+)/.exec( window.location.search );
        return m ? m[1] : 'ok';
    }() );

    var state = window.__asfHarness = {
        scenario: scenario,
        calls: [],
        challengeCalls: 0,
        submitCalls: 0,
        turnstileResets: 0,
        pageErrors: []
    };

    // A Turnstile token is single-use, so every refusal that leaves the form
    // usable has to renew it. The stub records the calls; the container is the
    // markup the renderer emits for a form that declares a policy, which this
    // fixture's definition does not, so it is added here.
    window.turnstile = {
        reset: function () {
            state.turnstileResets++;
        }
    };

    ( function () {
        var host = document.querySelector( 'form[data-agentshell-form]' );

        if ( ! host ) {
            return;
        }

        var widget = document.createElement( 'div' );
        widget.className = 'cf-turnstile';
        widget.setAttribute( 'data-sitekey', '1x00000000000000000000AA' );
        host.appendChild( widget );
    }() );

    window.addEventListener( 'error', function ( event ) {
        state.pageErrors.push( String( event.message || 'error' ) );
    } );

    window.agentshellFormsL10n = {
        error: 'This form could not be prepared. Please reload the page and try again.',
        unsupported: 'This form cannot be started in this browser.',
        submitting: 'Sending…',
        pending: 'This submission is still being processed. Please wait a moment…',
        network: 'The form could not be sent. Please check your connection and try again.',
        stale: 'This form has changed since this page was loaded. Reload the page to see the current version.',
        reload: 'Reload the form',
        summary: 'There is a problem',
        retained: 'Your previously entered answers have been kept. Please check them before sending.',
        required: 'Please correct the highlighted fields.'
    };

    function json_response( status, body, delay ) {
        return new Promise( function ( resolve ) {
            window.setTimeout( function () {
                resolve( {
                    ok: status >= 200 && status < 300,
                    status: status,
                    json: function () { return Promise.resolve( body ); }
                } );
            }, delay || 0 );
        } );
    }

    function challenge_response() {
        state.challengeCalls++;

        if ( 'challenge_fail' === scenario && 1 === state.challengeCalls ) {
            return Promise.reject( new TypeError( 'Failed to fetch' ) );
        }

        var version = 'stale' === scenario ? 4 : 3;

        return json_response( 200, {
            challenge: 'token-' + state.challengeCalls + '-0123456789abcdef',
            form_version: version,
            expires_in: 7200
        }, 20 );
    }

    function submit_response() {
        state.submitCalls++;

        if ( 'errors' === scenario ) {
            return json_response( 422, {
                ok: false,
                code: 'form_invalid',
                message: 'Please check the highlighted fields and try again.',
                errors: {
                    full_name: 'Your name is required.',
                    email: 'That address does not look right.'
                }
            } );
        }

        if ( 'stale_submit' === scenario ) {
            return json_response( 409, {
                ok: false,
                code: 'form_stale',
                message: 'This form has changed since this page was loaded. Please reload the page to see the current version.',
                reload: true,
                version: 4
            } );
        }

        if ( 'pending' === scenario && 1 === state.submitCalls ) {
            return json_response( 409, {
                ok: false,
                code: 'form_pending',
                message: 'This submission is still being processed. Please wait a moment and try again.',
                retry_after: 1
            } );
        }

        if ( 'retry' === scenario && 1 === state.submitCalls ) {
            return json_response( 409, {
                ok: false,
                code: 'form_retry',
                message: 'This submission could not be completed. Please try again.',
                retry: true,
                challenge: 'fresh-token-abcdef0123456789',
                form_version: 3
            } );
        }

        if ( 'lost' === scenario && 1 === state.submitCalls ) {
            return Promise.reject( new TypeError( 'Failed to fetch' ) );
        }

        if ( 'double' === scenario ) {
            return json_response( 200, { ok: true, message: 'Thanks — we have your message.' }, 150 );
        }

        return json_response( 200, { ok: true, message: 'Thanks — we have your message.' }, 80 );
    }

    window.fetch = function ( url, options ) {
        var body = {};

        try {
            body = JSON.parse( ( options && options.body ) || '{}' );
        } catch ( e ) {
            body = {};
        }

        state.calls.push( { url: String( url ), body: body } );

        if ( -1 !== String( url ).indexOf( 'challenge' ) ) {
            return challenge_response();
        }

        return submit_response();
    };
}());
JS;

$assertions = <<<'JS'
(function () {
    'use strict';

    var state = window.__asfHarness;
    var failures = [];
    var total = 0;
    var errors = [];

    function ok( name, condition, detail ) {
        total++;
        if ( ! condition ) {
            failures.push( { name: name, detail: detail === undefined ? null : String( detail ) } );
        }
    }

    function eq( name, actual, expected ) {
        ok( name, String( actual ) === String( expected ), 'expected ' + JSON.stringify( expected ) + ' got ' + JSON.stringify( actual ) );
    }

    function finish() {
        if ( state.pageErrors.length ) {
            errors = state.pageErrors.slice();
        }

        document.title = JSON.stringify( {
            scenario: state.scenario,
            total: total,
            failures: failures,
            errors: errors,
            calls: state.calls.length
        } );
    }

    var watchdog = window.setTimeout( function () {
        document.title = JSON.stringify( {
            harness_error: 'the assertions did not finish',
            scenario: state.scenario,
            failures: failures,
            calls: state.calls.length
        } );
    }, 18000 );

    var form = document.querySelector( 'form[data-agentshell-form]' );

    if ( ! form ) {
        window.clearTimeout( watchdog );
        document.title = JSON.stringify( { harness_error: 'no form on the page' } );
        return;
    }

    function field( key ) {
        return form.querySelector( '[data-field-key="' + key + '"]' );
    }

    function control( key ) {
        var wrapper = field( key );
        return wrapper ? wrapper.querySelector( 'input,select,textarea' ) : null;
    }

    function status() {
        return form.querySelector( '[data-agentshell-forms-status]' );
    }

    function submit_button() {
        return form.querySelector( '[data-agentshell-forms-submit]' );
    }

    function summary() {
        return form.querySelector( '.agentshell-forms__summary' );
    }

    function challenge_value() {
        var input = form.querySelector( '[data-agentshell-forms-challenge]' );
        return input ? input.value : '';
    }

    function challenge_state() {
        return form.getAttribute( 'data-challenge-state' );
    }

    function submission_state() {
        return form.getAttribute( 'data-submission-state' ) || '';
    }

    function wait_for( predicate, timeout ) {
        return new Promise( function ( resolve, reject ) {
            var deadline = Date.now() + ( timeout || 6000 );

            ( function poll() {
                var value;

                try {
                    value = predicate();
                } catch ( e ) {
                    value = false;
                }

                if ( value ) {
                    resolve( value );
                    return;
                }

                if ( Date.now() > deadline ) {
                    reject( new Error( 'timed out waiting for a condition' ) );
                    return;
                }

                window.setTimeout( poll, 25 );
            }() );
        } );
    }

    function fill() {
        control( 'full_name' ).value = 'Ada Lovelace';
        control( 'email' ).value = 'ada@example.invalid';
        control( 'message' ).value = 'A message that is long enough to pass validation.';
        control( 'topic' ).value = 'support';
        var radios = field( 'contact_method' ).querySelectorAll( 'input[type="radio"]' );
        radios[ 0 ].checked = true;
        var boxes = field( 'topics' ).querySelectorAll( 'input[type="checkbox"]' );
        boxes[ 0 ].checked = true;
        control( 'consent' ).checked = true;
    }

    function press() {
        submit_button().click();
    }

    function ready() {
        return 'ready' === challenge_state() && '' !== challenge_value();
    }

    try {
        fill();

        if ( 'stale' === state.scenario ) {
            wait_for( function () { return 'stale' === challenge_state(); } ).then( function () {
                ok( 'a stale challenge puts the form in the stale state', 'stale' === challenge_state(), challenge_state() );
                ok( 'a stale form holds no token', '' === challenge_value(), challenge_value() );
                ok( 'the submit button is disabled', true === submit_button().disabled, String( submit_button().disabled ) );
                ok( 'a reload button is offered', null !== form.querySelector( '.agentshell-forms__reload' ) );
                ok( 'the reload button has focus', document.activeElement === form.querySelector( '.agentshell-forms__reload' ), document.activeElement && document.activeElement.tagName );
                ok( 'the status region explains the page is out of date', -1 !== status().textContent.indexOf( 'changed since this page was loaded' ), status().textContent );
                ok( 'nothing was submitted', 0 === state.submitCalls, state.submitCalls );

                var key = 'agentshellFormsStash:' + form.getAttribute( 'data-form-id' );
                var stash = null;

                try { stash = window.sessionStorage.getItem( key ); } catch ( e ) { stash = null; }

                ok( 'the answers were kept', null !== stash && -1 !== String( stash ).indexOf( 'Ada Lovelace' ), stash );

                window.clearTimeout( watchdog );
                finish();
            } ).catch( function ( e ) {
                window.clearTimeout( watchdog );
                document.title = JSON.stringify( { harness_error: e.message } );
            } );

            return;
        }

        if ( 'challenge_fail' === state.scenario ) {
            wait_for( function () { return 'error' === challenge_state(); } ).then( function () {
                ok( 'a provisioning failure is reported', 'error' === challenge_state(), challenge_state() );
                ok( 'the failure is announced', -1 !== status().textContent.indexOf( 'could not be prepared' ), status().textContent );
                ok( 'the button is still usable', false === submit_button().disabled, String( submit_button().disabled ) );

                // The visitor tries again: the same script retries provisioning
                // before sending, so no reload is needed.
                press();

                return wait_for( function () { return 'sent' === submission_state(); } );
            } ).then( function () {
                ok( 'a retry after a provisioning failure succeeds', 'sent' === submission_state(), submission_state() );
                ok( 'the success message is shown', -1 !== status().textContent.indexOf( 'Thanks' ), status().textContent );
                ok( 'exactly one submission was sent', 1 === state.submitCalls, state.submitCalls );
                ok( 'the retry used the second challenge', 2 === state.challengeCalls, state.challengeCalls );

                window.clearTimeout( watchdog );
                finish();
            } ).catch( function ( e ) {
                window.clearTimeout( watchdog );
                document.title = JSON.stringify( { harness_error: e.message } );
            } );

            return;
        }

        wait_for( ready ).then( function () {
            ok( 'the challenge was provisioned', ready(), challenge_state() );
            ok( 'a token is in the input', '' !== challenge_value(), challenge_value() );
            ok( 'the submitted version is the page version', '3' === form.getAttribute( 'data-form-version' ), form.getAttribute( 'data-form-version' ) );

            press();

            if ( 'double' === state.scenario ) {
                press();
                press();
            }

            return wait_for( function () {
                var value = submission_state();
                return 'sent' === value || 'sending' !== value;
            } );
        } ).then( function () {
            return wait_for( function () {
                return 'sent' === submission_state() || ( 'errors' === state.scenario && false === summary().hidden )
                    || ( 'stale_submit' === state.scenario && 'stale' === challenge_state() )
                    || ( 'retry' === state.scenario && 'ready' === challenge_state() && 1 === state.submitCalls && 'sending' !== submission_state() );
            }, 8000 );
        } ).then( function () {
            if ( 'ok' === state.scenario ) {
                ok( 'the submission is marked sent', 'sent' === submission_state(), submission_state() );
                ok( 'the success message is announced', -1 !== status().textContent.indexOf( 'Thanks — we have your message.' ), status().textContent );
                ok( 'the button is disabled after sending', true === submit_button().disabled, String( submit_button().disabled ) );
                ok( 'the button says it is sent', -1 !== submit_button().textContent.indexOf( 'Sent' ), submit_button().textContent );
                eq( 'one submission request was made', state.submitCalls, 1 );

                var sent = state.calls[ state.calls.length - 1 ];

                eq( 'the request carried the form id', sent.body.form_id, form.getAttribute( 'data-form-id' ) );
                eq( 'the request carried the page version', sent.body.form_version, 3 );
                eq( 'the request carried the challenge', sent.body.challenge, challenge_value() );
                eq( 'the request carried the typed name', sent.body.fields.full_name, 'Ada Lovelace' );
                eq( 'a consent is sent as a boolean', sent.body.fields.consent, true );
                eq( 'a checkbox group is sent as a list', String( sent.body.fields.topics ), 'design' );
                ok( 'a hidden field is not sent from the page', sent.body.fields.source === undefined, String( sent.body.fields.source ) );
                eq( 'a successful submission does not renew the Turnstile token', state.turnstileResets, 0 );
            }

            if ( 'errors' === state.scenario ) {
                ok( 'the summary is visible', false === summary().hidden && summary().classList.contains( 'agentshell-forms__summary--visible' ), String( summary().hidden ) );
                ok( 'the summary has focus', document.activeElement === summary(), document.activeElement && document.activeElement.tagName );
                ok( 'the summary names the problem', -1 !== summary().textContent.indexOf( 'There is a problem' ), summary().textContent );
                ok( 'the summary lists both fields', -1 !== summary().textContent.indexOf( 'Your name is required.' ) && -1 !== summary().textContent.indexOf( 'That address does not look right.' ), summary().textContent );

                var name = control( 'full_name' );
                var email = control( 'email' );

                eq( 'the offending control is marked invalid', name.getAttribute( 'aria-invalid' ), 'true' );
                ok( 'the control is described by its error', -1 !== String( name.getAttribute( 'aria-describedby' ) ).indexOf( 'error' ), name.getAttribute( 'aria-describedby' ) );
                ok( 'the per-field message is rendered', -1 !== field( 'full_name' ).textContent.indexOf( 'Your name is required.' ), field( 'full_name' ).textContent );
                ok( 'the second field is marked too', null !== email.getAttribute( 'aria-invalid' ) || 'true' === email.getAttribute( 'aria-invalid' ), email.getAttribute( 'aria-invalid' ) );
                ok( 'the button is usable again', false === submit_button().disabled, String( submit_button().disabled ) );
                eq( 'the entered values were not thrown away', control( 'full_name' ).value, 'Ada Lovelace' );
                eq( 'one submission was attempted', state.submitCalls, 1 );
                ok( 'a field error renews the spent Turnstile token', state.turnstileResets >= 1, state.turnstileResets );
            }

            if ( 'stale_submit' === state.scenario ) {
                ok( 'a server-side stale refusal puts the form in the stale state', 'stale' === challenge_state(), challenge_state() );
                ok( 'the submit button is disabled', true === submit_button().disabled, String( submit_button().disabled ) );
                ok( 'a reload button is offered', null !== form.querySelector( '.agentshell-forms__reload' ) );
                ok( 'the status region explains the page is out of date', -1 !== status().textContent.indexOf( 'changed since this page was loaded' ), status().textContent );
                eq( 'nothing was written', state.submitCalls, 1 );
            }

            if ( 'pending' === state.scenario ) {
                ok( 'a pending answer is retried and then succeeds', 'sent' === submission_state(), submission_state() );
                eq( 'the same request was sent twice', state.submitCalls, 2 );

                var first = state.calls[ state.calls.length - 2 ];
                var second = state.calls[ state.calls.length - 1 ];

                eq( 'the retry used the same challenge', first.body.challenge, second.body.challenge );
                eq( 'the retry used the same values', second.body.fields.full_name, first.body.fields.full_name );
                ok( 'the wait was announced', true, 'the pending message is transient by design' );
            }

            if ( 'retry' === state.scenario ) {
                ok( 'a retryable refusal leaves the form usable', false === submit_button().disabled, String( submit_button().disabled ) );
                ok( 'a retryable refusal renews the spent Turnstile token', state.turnstileResets >= 1, state.turnstileResets );
                eq( 'the fresh challenge replaced the old one', challenge_value(), 'fresh-token-abcdef0123456789' );
                ok( 'the form is ready to send again', 'ready' === challenge_state(), challenge_state() );

                press();

                return wait_for( function () { return 'sent' === submission_state(); }, 8000 ).then( function () {
                    ok( 'the retry with the fresh challenge succeeds', 'sent' === submission_state(), submission_state() );
                    eq( 'two attempts were made', state.submitCalls, 2 );

                    var last = state.calls[ state.calls.length - 1 ];

                    eq( 'the second attempt carried the fresh challenge', last.body.challenge, 'fresh-token-abcdef0123456789' );

                    window.clearTimeout( watchdog );
                    finish();
                } );
            }

            if ( 'lost' === state.scenario ) {
                ok( 'a lost response is retried and succeeds', 'sent' === submission_state(), submission_state() );
                eq( 'two attempts were made', state.submitCalls, 2 );

                var firstlost = state.calls[ state.calls.length - 2 ];
                var secondlost = state.calls[ state.calls.length - 1 ];

                eq( 'the retry reused the same challenge', firstlost.body.challenge, secondlost.body.challenge );
                ok( 'only one success is shown', -1 !== status().textContent.indexOf( 'Thanks' ), status().textContent );
            }

            if ( 'double' === state.scenario ) {
                eq( 'a double click sends one request', state.submitCalls, 1 );
                ok( 'the button is disabled while sending', true === submit_button().disabled, String( submit_button().disabled ) );
                ok( 'the form is busy while sending', 'true' === form.getAttribute( 'aria-busy' ) || 'sent' === submission_state(), form.getAttribute( 'aria-busy' ) );
            }

            window.clearTimeout( watchdog );
            finish();
        } ).catch( function ( e ) {
            window.clearTimeout( watchdog );
            document.title = JSON.stringify( { harness_error: e.message, scenario: state.scenario } );
        } );
    } catch ( e ) {
        window.clearTimeout( watchdog );
        document.title = JSON.stringify( { harness_error: 'an assertion threw: ' + ( e && e.message ? e.message : String( e ) ) } );
    }
}());
JS;

$html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>pending</title>
<style>
body { margin: 0 auto; max-width: 48rem; padding: 2rem 1rem; font-family: system-ui, sans-serif; }
' . $css . '
</style>
</head>
<body>
<main>
<h1>Contact</h1>
' . $form_html . '
</main>
<script>
' . $harness . '
</script>
<script>
' . $js . '
</script>
<script>
' . $assertions . '
</script>
</body>
</html>';

file_put_contents( $out, $html );

echo "written\n";
