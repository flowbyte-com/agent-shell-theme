<?php
/**
 * Live custom-stylesheet regressions, run against a real WordPress install.
 *
 * The unit suite proves the materialiser in isolation. This one proves the
 * thing that matters to an agent: that a staged stylesheet never reaches a
 * file, that a commit materialises it, that the file's bytes are the bytes
 * the page would have inlined, and that a rollback and a snapshot restore
 * leave the site in a state the render agrees with.
 *
 * The suite needs a WordPress to talk to. Without one it skips, so it is
 * safe to run anywhere; `tests/wp-live.php` finds the install and picks a
 * PHP that can reach its database.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "live custom stylesheet asset (real WordPress)\n";

// Destructive live suite: requires the test-bed config. The strict
// resolution refuses to fall back to LocalWP auto-discovery.
$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    // A skip, declared as one. It used to be an assertion that
    // passed with 'skipped:' in its message, which is a skip
    // counted as a pass.
    Agentshell_Test::skip( 'no test-bed config (destructive suites require tests/config/test-bed.json)' );
    return;
}

$report_file = sys_get_temp_dir() . '/as-cca-live-' . getmypid() . '.json';
@unlink( $report_file );

$run = agentshell_wp_live_run(
    $config,
    __DIR__ . '/custom-css-asset-live-child.php',
    array( $config['wp_root'], $report_file )
);

$f = json_decode( (string) @file_get_contents( $report_file ), true );
@unlink( $report_file );

if ( ! is_array( $f ) ) {
    Agentshell_Test::run(
        'live custom-css child produced a report',
        function () use ( $run ) {
            Agentshell_Test::assert_true(
                is_array( $GLOBALS['cca_f'] ),
                'child failed to report: ' . $run['output']
            );
        }
    );
    return;
}
$GLOBALS['cca_f'] = $f;

Agentshell_Test::run(
    'a staged stylesheet never reaches a file',
    function () use ( $f ) {
        Agentshell_Test::assert_true( (bool) ( $f['staged_ok'] ?? false ), 'the staged write must succeed: ' . ( $f['staged_error'] ?? '' ) );
        Agentshell_Test::assert_true(
            (bool) ( $f['committed_unchanged_after_stage'] ?? false ),
            'the committed stylesheet must not change while a transaction is open'
        );
        Agentshell_Test::assert_true(
            (bool) ( $f['files_unchanged_after_stage'] ?? false ),
            'no file may be written for a staged stylesheet: it would publish uncommitted work'
        );
    }
);

Agentshell_Test::run(
    'the preview inlines the staged stylesheet and links to nothing',
    function () use ( $f ) {
        Agentshell_Test::assert_true( ! isset( $f['preview_error'] ), 'the preview must render: ' . ( $f['preview_error'] ?? '' ) );
        Agentshell_Test::assert_true(
            (bool) ( $f['preview_inlines_staged'] ?? false ),
            'the preview must show the staged stylesheet'
        );
        Agentshell_Test::assert_true(
            (bool) ( $f['preview_has_style'] ?? false ),
            'the preview must carry an inline style block'
        );
        Agentshell_Test::assert_true(
            ! ( $f['preview_has_link'] ?? false ),
            'the preview must not link to a file: the staged stylesheet is not in one'
        );
    }
);

Agentshell_Test::run(
    'a rollback leaves the committed stylesheet and its file alone',
    function () use ( $f ) {
        Agentshell_Test::assert_true( (bool) ( $f['rolled_back'] ?? false ), 'the rollback must succeed: ' . ( $f['rollback_error'] ?? '' ) );
        Agentshell_Test::assert_true(
            (bool) ( $f['committed_unchanged_after_rollback'] ?? false ),
            'the committed stylesheet must be the one the child found'
        );
        Agentshell_Test::assert_true(
            (bool) ( $f['files_unchanged_after_rollback'] ?? false ),
            'a rollback must not remove or add a file'
        );
    }
);

Agentshell_Test::run(
    'a commit materialises the stylesheet as a file',
    function () use ( $f ) {
        Agentshell_Test::assert_true( (bool) ( $f['committed'] ?? false ), 'the commit must succeed: ' . ( $f['commit_error'] ?? '' ) );
        Agentshell_Test::assert_true(
            (bool) ( $f['committed_css_is_new'] ?? false ),
            'the committed stylesheet must be the new one'
        );
        Agentshell_Test::assert_true(
            (int) ( $f['file_count_delta'] ?? 0 ) >= 1,
            'the commit must write a file: ' . json_encode( $f['files_after_commit'] ?? array() )
        );
        Agentshell_Test::assert_true(
            '' !== (string) ( $f['materialised_url'] ?? '' ),
            'the materialised stylesheet must have a URL'
        );
        Agentshell_Test::assert_true(
            ! ( $f['materialised_inline'] ?? true ),
            'the stylesheet must be served from the file, not inlined'
        );
    }
);

Agentshell_Test::run(
    'the file carries exactly the bytes the inline block would',
    function () use ( $f ) {
        Agentshell_Test::assert_true(
            (bool) ( $f['file_bytes_match_inline'] ?? false ),
            'the file and the inline block must be the same string'
        );
    }
);

Agentshell_Test::run(
    'the render links to the file rather than inlining it',
    function () use ( $f ) {
        Agentshell_Test::assert_true( (bool) ( $f['render_links'] ?? false ), 'the render must link to the materialised file' );
        Agentshell_Test::assert_true( ! ( $f['render_inlines'] ?? true ), 'the render must not inline a stylesheet it links to' );
    }
);

Agentshell_Test::run(
    'a snapshot restore re-materialises the stylesheet',
    function () use ( $f ) {
        Agentshell_Test::assert_true( (bool) ( $f['snapshot_created'] ?? false ), 'the snapshot must be created: ' . ( $f['snapshot_error'] ?? '' ) );
        Agentshell_Test::assert_true( (bool) ( $f['restored'] ?? false ), 'the restore must succeed: ' . ( $f['restore_error'] ?? '' ) );
        Agentshell_Test::assert_true(
            (bool) ( $f['restored_matches_first'] ?? false ),
            'the restored stylesheet must be the one that was restored: ' . ( $f['restored_url'] ?? '' ) . ' vs ' . ( $f['materialised_url'] ?? '' )
        );
    }
);
