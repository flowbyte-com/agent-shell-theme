<?php
/**
 * The Phase 4 builder acceptance suite.
 *
 * A real WordPress, a real HTTP origin, a real Chrome and the real admin page:
 * the browser creates a form from nothing, adds every kind of field, edits
 * settings and options, reorders, previews, saves, publishes, renders the
 * result on a published page, edits without publishing, races two editors,
 * edits during an in-flight save, discards, recovers from validation and
 * network failures, manages several forms and inserts the shortcode.
 *
 * ## Status: registered in CI as `forms-builder-acceptance` (destructive).
 *
 * The harness runs the full builder workflow against the real admin page,
 * real REST endpoints and the real database: create, fields, settings,
 * options, reorder, preview, save/persist, publish, shortcode page,
 * unpublished edit, in-flight save, discard, validation recovery, network
 * recovery, two-editor conflict, multi-form selection, rename, duplicate,
 * trash and insertion.
 *
 * The browser run is bounded (`timeout 240` around Chrome plus a
 * virtual-time budget): a timeout produces no report, and no report is a
 * failure, never a pass. Cleanup runs regardless of the result.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "forms builder acceptance (real WordPress + real browser)\n";

$config = agentshell_wp_live_config_destructive();

if ( null === $config ) {
    Agentshell_Test::run(
        'forms builder acceptance suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config' );
        }
    );
    echo "  (skipped: destructive suites require a test-bed config)\n";
    return;
}

$browser = getenv( 'AGENTSHELL_BROWSER' );

if ( ! $browser ) {
    foreach ( array( 'google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser', 'chrome' ) as $candidate ) {
        $found = trim( (string) shell_exec( 'command -v ' . escapeshellarg( $candidate ) . ' 2>/dev/null' ) );
        if ( '' !== $found ) {
            $browser = $found;
            break;
        }
    }
}

if ( ! $browser ) {
    Agentshell_Test::run(
        'forms builder acceptance: a browser is available',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no browser found' );
        }
    );
    echo "  (skipped: no browser)\n";
    return;
}

$prepare_report = sys_get_temp_dir() . '/as-builder-prepare-' . getmypid() . '.json';
$cleanup_report = sys_get_temp_dir() . '/as-builder-cleanup-' . getmypid() . '.json';
@unlink( $prepare_report );
@unlink( $cleanup_report );

$prepare = agentshell_wp_live_run(
    $config,
    __DIR__ . '/forms-builder-acceptance-child.php',
    array( $config['wp_root'], $prepare_report, 'prepare' )
);

$prepared = json_decode( (string) @file_get_contents( $prepare_report ), true );

if ( ! is_array( $prepared ) || empty( $prepared['prepared'] ) ) {
    Agentshell_Test::run(
        'forms builder acceptance: the harness was prepared',
        function () use ( $prepare ) {
            Agentshell_Test::assert_true( false, 'preparation failed: ' . $prepare['output'] );
        }
    );
    @unlink( $prepare_report );
    return;
}

$port = 18080 + ( getmypid() % 500 );
$base = 'http://127.0.0.1:' . $port;

$server_env = $config['env'];
$cmd = '';

foreach ( $server_env as $key => $value ) {
    $cmd .= $key . '=' . escapeshellarg( (string) $value ) . ' ';
}

$cmd .= escapeshellarg( (string) $config['php'] );

foreach ( $config['ini'] as $ini ) {
    $cmd .= ' -d ' . escapeshellarg( (string) $ini );
}

$cmd .= ' -d display_errors=1 -d error_reporting=E_ALL'
    . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg( (string) $config['wp_root'] )
    . ' ' . escapeshellarg( (string) $config['wp_root'] . '/asf-test-router.php' );

$descriptors = array(
    0 => array( 'pipe', 'r' ),
    1 => array( 'file', sys_get_temp_dir() . '/as-builder-server-' . getmypid() . '.log', 'a' ),
    2 => array( 'file', sys_get_temp_dir() . '/as-builder-server-' . getmypid() . '.log', 'a' ),
);

$server = proc_open( $cmd, $descriptors, $pipes );

$ready = false;

for ( $i = 0; $i < 100; $i++ ) {
    $probe = @file_get_contents( $base . '/asf-test-harness.php?probe=1', false, stream_context_create( array( 'http' => array( 'timeout' => 2, 'ignore_errors' => true ) ) ) );

    if ( false !== $probe && '' !== (string) $probe ) {
        $ready = true;
        break;
    }

    usleep( 100000 );
}

if ( ! $ready ) {
    proc_terminate( $server );
    proc_close( $server );

    Agentshell_Test::run(
        'forms builder acceptance: the site is served',
        function () {
            Agentshell_Test::assert_true( false, 'the harness server did not become ready' );
        }
    );
    @unlink( $prepare_report );
    return;
}

$work = sys_get_temp_dir() . '/as-builder-profile-' . getmypid();
@mkdir( $work, 0777, true );

$args = array(
    '--headless',
    '--disable-gpu',
    '--no-sandbox',
    '--disable-dev-shm-usage',
    '--no-first-run',
    '--no-default-browser-check',
    '--user-data-dir=' . $work,
    '--virtual-time-budget=180000',
    '--window-size=1400,1000',
    '--dump-dom',
    $base . '/asf-test-harness.php',
);

$env = $config['env'];
unset( $env['LD_LIBRARY_PATH'] );

$command = 'timeout 240 ' . escapeshellarg( $browser ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>/dev/null';

$dom = (string) shell_exec( $command );

proc_terminate( $server );
proc_close( $server );

$title = '';

if ( preg_match( '/<title>([\s\S]*?)<\/title>/', $dom, $m ) ) {
    $title = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

$report = json_decode( $title, true );

// Clean up regardless of the result.
agentshell_wp_live_run(
    $config,
    __DIR__ . '/forms-builder-acceptance-child.php',
    array( $config['wp_root'], $cleanup_report, 'cleanup' )
);

@unlink( $prepare_report );
@unlink( $cleanup_report );

if ( ! is_array( $report ) ) {
    Agentshell_Test::run(
        'forms builder acceptance: the browser reported',
        function () use ( $title, $dom ) {
            Agentshell_Test::assert_true( false, 'the browser produced no result: ' . substr( $title . $dom, 0, 400 ) );
        }
    );
    Agentshell_Test::summary();
    return;
}

$failures = isset( $report['failures'] ) && is_array( $report['failures'] ) ? $report['failures'] : array();
$total    = (int) ( $report['total'] ?? 0 );

Agentshell_Test::run(
    'forms builder acceptance: ' . $total . ' assertions in a real browser',
    function () use ( $failures, $total ) {
        $lines = array();

        foreach ( $failures as $failure ) {
            $lines[] = ( $failure['name'] ?? 'assertion' )
                . ( isset( $failure['detail'] ) && null !== $failure['detail'] ? ' (' . $failure['detail'] . ')' : '' );
        }

        Agentshell_Test::assert_true(
            $total >= 20 && empty( $failures ),
            'the browser reported ' . $total . ' assertions and ' . count( $failures ) . ' failures: ' . implode( ' | ', $lines )
        );
    }
);

Agentshell_Test::summary();
