<?php
/**
 * Generate the builder page for the browser suite.
 *
 *   php tests/forms-builder-browser-fixture.php /path/to/out.html
 *
 * argv: 1 output path
 */

$out = $argv[1] ?? '';

if ( '' === $out ) {
    fwrite( STDERR, "usage: php forms-builder-browser-fixture.php <out.html>\n" );
    exit( 1 );
}

$assets = dirname( __DIR__ ) . '/agentshell-forms/admin/';

$model = (string) file_get_contents( $assets . 'js/forms-model.js' );
$api   = (string) file_get_contents( $assets . 'js/forms-api.js' );
$ui    = (string) file_get_contents( $assets . 'js/forms-ui.js' );
$app   = (string) file_get_contents( $assets . 'js/forms-app.js' );
$css   = (string) file_get_contents( $assets . 'css/forms-admin.css' );

foreach ( array( $model, $api, $ui, $app ) as $script ) {
    if ( false !== strpos( $script, '</script' ) ) {
        fwrite( STDERR, "a module contains a closing script tag\n" );
        exit( 1 );
    }
}

$model = str_replace( '</script', '<\\/script', $model );
$api   = str_replace( '</script', '<\\/script', $api );
$ui    = str_replace( '</script', '<\\/script', $ui );
$app   = str_replace( '</script', '<\\/script', $app );

$assertions = <<<'JS'
(function () {
    'use strict';

    var failures = [];
    var total = 0;

    function ok( name, condition, detail ) {
        total++;
        if ( ! condition ) {
            failures.push( { name: name, detail: detail === undefined ? null : String( detail ) } );
        }
    }

    function finish() {
        document.title = JSON.stringify( { total: total, failures: failures } );
    }

    ok( 'the model module loaded', typeof AgentshellFormsModel !== 'undefined' );
    ok( 'make_form_model is exported', typeof AgentshellFormsModel.make_form_model === 'function' );

    var m = AgentshellFormsModel.make_form_model( { id: 'b1', name: 'Contact', revision: 2, working: { fields: [ { key: 'name', type: 'text', label: 'Name' } ] } } );

    ok( 'a fresh model is clean', m.dirty() === false );
    m.working.fields.push( { key: 'email', type: 'email', label: 'Email' } );
    ok( 'adding a field makes the model dirty', m.dirty() === true );
    ok( 'the status helper never says saved for dirty work', AgentshellFormsModel.status_label( m, false ) !== 'saved' );

    var moved = AgentshellFormsModel.move_field( m.working.fields, 0, 1 );
    ok( 'move_field reports whether it moved', moved.moved === true );
    ok( 'move_field does not mutate the input', m.working.fields[ 0 ].key === 'name' );

    ok( 'the API module loaded', typeof AgentshellFormsApi !== 'undefined' );
    ok( 'FormsApi is exported', typeof AgentshellFormsApi.FormsApi === 'function' );

    var api = new AgentshellFormsApi.FormsApi( 'http://example.test/wp-json/wp/v2/agentshell-forms/v1', 'nonce' );
    ok( 'the API builds a save URL', typeof api.save === 'function' );

    ok( 'the UI module loaded', typeof AgentshellFormsUi !== 'undefined' );
    ok( 'FormsUi is exported', typeof AgentshellFormsUi.FormsUi === 'function' );

    var ui = new AgentshellFormsUi.FormsUi( document.body );
    ok( 'the UI can build an element', typeof ui.el === 'function' );

    ok( 'the app module loaded', typeof AgentshellFormsApp !== 'undefined' );
    ok( 'FormsApp is exported', typeof AgentshellFormsApp.FormsApp === 'function' );

    finish();
}());
JS;

$html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>pending</title>
<style>
' . $css . '
</style>
</head>
<body>
<div id="agentshell-forms-builder" data-rest="http://example.test/wp-json/wp/v2/agentshell-forms/v1" data-nonce="nonce"></div>
<script>
' . $model . '
</script>
<script>
' . $api . '
</script>
<script>
' . $ui . '
</script>
<script>
' . $app . '
</script>
<script>
' . $assertions . '
</script>
</body>
</html>';

file_put_contents( $out, $html );
echo "written\n";
