<?php
/**
 * The zone block, end to end, against a real WordPress.
 *
 * The unit suite (`tests/forms-zone-block-test.php`) proves the contract with
 * stubs. This proves the behaviour an operator and a visitor actually get:
 *
 *   - a published form, placed as an `agentshell_form` block, renders the
 *     plugin's real form markup inside the real zone wrapper — in a header
 *     area and in the main composition;
 *   - an unpublished, unknown, trashed or post-id reference renders nothing,
 *     without a fatal and without the error page;
 *   - the published catalog offers the published form and withholds the
 *     draft;
 *   - the configuration response carries that catalog and nothing private;
 *   - the validator and the doctor accept a configuration that uses the
 *     block;
 *   - nine cells survive when the form stops resolving.
 *
 * The child removes everything it creates and the parent asserts it did.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "forms zone block (real WordPress)\n";

$config = agentshell_wp_live_config_destructive();

if ( null === $config ) {
    Agentshell_Test::run(
        'forms zone block suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config (destructive suites require one)' );
        }
    );
    echo "  (skipped: destructive suites require a test-bed config)\n";
    return;
}

$report_file = sys_get_temp_dir() . '/as-forms-zone-block-' . getmypid() . '.json';
@unlink( $report_file );

$run = agentshell_wp_live_run(
    $config,
    __DIR__ . '/forms-zone-block-live-child.php',
    array( $config['wp_root'], dirname( __DIR__ ), $report_file )
);

$raw = @file_get_contents( $report_file );
@unlink( $report_file );

$f = json_decode( (string) $raw, true );

if ( ! is_array( $f ) ) {
    Agentshell_Test::run(
        'forms zone block: the child reported',
        function () use ( $run ) {
            Agentshell_Test::assert_true( false, 'the child produced no report: ' . $run['output'] );
        }
    );
    return;
}

$p = isset( $f['probes'] ) && is_array( $f['probes'] ) ? $f['probes'] : array();

if ( isset( $p['fatal'] ) ) {
    Agentshell_Test::run(
        'forms zone block: the child reached its subjects',
        function () use ( $p ) {
            Agentshell_Test::assert_true( false, $p['fatal']['where'] . ' — ' . $p['fatal']['message'] );
        }
    );
    Agentshell_Test::summary();
    return;
}

/**
 * One probe's field.
 *
 * @param array  $probes Probes.
 * @param string $probe  Probe.
 * @param string $key    Key.
 * @return mixed
 */
function fzl( array $probes, $probe, $key ) {
    if ( ! isset( $probes[ $probe ] ) ) {
        throw new Exception( "the child did not report \"{$probe}\"" );
    }
    if ( ! array_key_exists( $key, $probes[ $probe ] ) ) {
        throw new Exception( "the probe \"{$probe}\" has no field \"{$key}\"" );
    }
    return $probes[ $probe ][ $key ];
}

Agentshell_Test::run(
    'a published form renders through a zone block',
    function () use ( $p ) {
        Agentshell_Test::assert_true( fzl( $p, 'fixtures', 'published_ok' ), 'a form was published' );
        Agentshell_Test::assert_true( fzl( $p, 'renders', 'slot_has_form' ), 'a header area renders the form element' );
        Agentshell_Test::assert_true( fzl( $p, 'renders', 'slot_has_class' ), 'the plugin\'s form class is what is rendered' );
        Agentshell_Test::assert_true( fzl( $p, 'renders', 'slot_has_field' ), 'the form\'s own field is in the markup' );
        Agentshell_Test::assert_true( fzl( $p, 'renders', 'avoid_double_escape' ), 'the plugin\'s HTML is not escaped a second time' );
        Agentshell_Test::assert_equals( 1, (int) fzl( $p, 'renders', 'form_element_count' ), 'the page carries exactly one form element — the plugin\'s — so the theme wrote no second renderer' );
    }
);

Agentshell_Test::run(
    'the form lands in the area it was placed in, and the grid is intact',
    function () use ( $p ) {
        Agentshell_Test::assert_true( fzl( $p, 'renders', 'slot_cell_is_bottom_right' ), 'the cell is the one named in the configuration' );
        Agentshell_Test::assert_equals( 9, (int) fzl( $p, 'renders', 'slot_cell_count' ), 'all nine cells are still emitted' );
    }
);

Agentshell_Test::run(
    'the main composition renders it too',
    function () use ( $p ) {
        Agentshell_Test::assert_true( fzl( $p, 'renders', 'composition_has_form' ), 'a form block in the main composition renders' );
    }
);

Agentshell_Test::run(
    'a visitor gets nothing for an unusable reference, and a manager gets a diagnostic',
    function () use ( $p ) {
        Agentshell_Test::assert_true( fzl( $p, 'degradation', 'visitor_empty' ), 'a visitor receives no markup at all: no leak, no error page' );
        Agentshell_Test::assert_true( fzl( $p, 'degradation', 'draft_visitor_empty' ), 'an unpublished form renders nothing to a visitor' );
        Agentshell_Test::assert_true( fzl( $p, 'degradation', 'trashed_visitor_empty' ), 'a trashed form renders nothing to a visitor' );
        Agentshell_Test::assert_true( fzl( $p, 'degradation', 'block_refuses_post_id' ), 'the block vocabulary refuses a WordPress post id, so a stored reference can never mean a renumbered row' );
        Agentshell_Test::assert_true( fzl( $p, 'degradation', 'empty_id_empty' ), 'a block with no id renders nothing to anybody' );
        Agentshell_Test::assert_true( fzl( $p, 'degradation', 'manager_diagnostic' ), 'a form manager sees why the placement is blank' );
        Agentshell_Test::assert_true( fzl( $p, 'degradation', 'manager_no_form' ), 'the diagnostic is never a half-built form' );
    }
);

Agentshell_Test::run(
    'the catalog offers published forms only, and nothing private',
    function () use ( $p ) {
        Agentshell_Test::assert_true( fzl( $p, 'catalog', 'has_published' ), 'the published form is offered' );
        Agentshell_Test::assert_true( fzl( $p, 'catalog', 'excludes_draft' ), 'a draft form is not offered' );
        Agentshell_Test::assert_equals( array( 'form_id', 'name', 'slug' ), fzl( $p, 'catalog', 'keys' ), 'the catalog carries identifiers and labels only' );
        Agentshell_Test::assert_true( '' !== (string) fzl( $p, 'catalog', 'name' ), 'the form has a readable name' );
        Agentshell_Test::assert_true( '' !== (string) fzl( $p, 'catalog', 'slug' ), 'the form has a slug a human can paste' );
        Agentshell_Test::assert_true( fzl( $p, 'catalog', 'no_values_key' ), 'no submitted values are exposed' );
        Agentshell_Test::assert_true( fzl( $p, 'catalog', 'no_settings_key' ), 'no form settings are exposed' );
        Agentshell_Test::assert_true( fzl( $p, 'catalog', 'no_secret' ), 'no Turnstile configuration is exposed' );
    }
);

Agentshell_Test::run(
    'the configuration response carries the catalog and stays backward compatible',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( true, fzl( $p, 'config_response', 'flag' ), 'the forms plugin is reported present' );
        Agentshell_Test::assert_true( fzl( $p, 'config_response', 'has_published' ), 'the published form is in the response' );
        Agentshell_Test::assert_true( fzl( $p, 'config_response', 'excludes_draft' ), 'the draft is not in the response' );
        Agentshell_Test::assert_equals( array( 'id', 'name', 'slug' ), fzl( $p, 'config_response', 'entry_keys' ), 'each entry is id, name and slug' );
        Agentshell_Test::assert_true( false !== strpos( (string) fzl( $p, 'config_response', 'builder_url' ), 'agentshell-forms-builder' ), 'the builder route is offered' );
        Agentshell_Test::assert_true( fzl( $p, 'config_response', 'no_secret' ), 'the response carries no Turnstile configuration' );
        Agentshell_Test::assert_true( fzl( $p, 'config_response', 'backward_compatible' ), 'the keys the previous response carried are still there' );
    }
);

Agentshell_Test::run(
    'the validator and the doctor accept a configuration using the block',
    function () use ( $p ) {
        Agentshell_Test::assert_true(
            fzl( $p, 'validation', 'validated' ),
            'the configuration validates: ' . (string) fzl( $p, 'validation', 'validate_error' )
        );
        Agentshell_Test::assert_true(
            fzl( $p, 'validation', 'doctor_ok' ),
            'the doctor reports no errors: ' . wp_json_encode( fzl( $p, 'validation', 'doctor_errors' ) )
        );
    }
);

Agentshell_Test::run(
    'a reference that stops resolving degrades without breaking the zone',
    function () use ( $p ) {
        Agentshell_Test::assert_true( fzl( $p, 'stale_reference', 'fatal_free' ), 'trashing the form does not fatal the page' );
        Agentshell_Test::assert_true( fzl( $p, 'stale_reference', 'empty_form' ), 'the zone renders no form' );
        Agentshell_Test::assert_true( fzl( $p, 'stale_reference', 'cells_intact' ), 'the nine cells are still emitted' );
    }
);

Agentshell_Test::run(
    'the child removed everything it created',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 0, (int) fzl( $p, 'cleanup', 'posts_remaining' ), 'no fixture post remains' );
        Agentshell_Test::assert_equals( 0, (int) fzl( $p, 'cleanup', 'catalog_leaks' ), 'no fixture form remains in the catalog' );
    }
);
