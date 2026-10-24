<?php
/**
 * Live site-settings regressions, run against a real WordPress install.
 *
 * The unit suite proves the validation and the projection in isolation.
 * This one proves the thing that actually matters to an agent: that a
 * staged site setting does not reach the live site, that a commit applies
 * it, that a rollback does not, and that a refused setting changes
 * nothing at all.
 *
 * The suite needs a WordPress to talk to. Without one it skips, so it is
 * safe to run anywhere; `tests/wp-live.php` finds the install and picks a
 * PHP that can reach its database.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "live site settings (real WordPress)\n";

// Destructive live suite: requires the test-bed config. The strict
// resolution refuses to fall back to LocalWP auto-discovery.
$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    Agentshell_Test::run(
        'live site-settings suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config (destructive suites require tests/config/test-bed.json)' );
        }
    );
    echo "  (skipped: destructive suites require a test-bed config; tests/config/test-bed.json not present or invalid)\n";
    return;
}

$report_file = sys_get_temp_dir() . '/as-ss-live-' . getmypid() . '.json';
@unlink( $report_file );

$run = agentshell_wp_live_run(
    $config,
    __DIR__ . '/site-settings-live-child.php',
    array( $config['wp_root'], $report_file )
);

$f = json_decode( (string) @file_get_contents( $report_file ), true );
@unlink( $report_file );

if ( ! is_array( $f ) ) {
    Agentshell_Test::run(
        'live site-settings child produced a report',
        function () use ( $run ) {
            Agentshell_Test::assert_true(
                is_array( $GLOBALS['ss_f'] ),
                'child failed to report: ' . $run['output']
            );
        }
    );
    return;
}
$GLOBALS['ss_f'] = $f;

function ss_f( $key, $default = null ) {
    $f = $GLOBALS['ss_f'];
    return array_key_exists( $key, $f ) ? $f[ $key ] : $default;
}

Agentshell_Test::run(
    'a site setting is staged, not live',
    function () {
        Agentshell_Test::assert_equals( true, ss_f( 'tx_opened' ) );
        Agentshell_Test::assert_true(
            ! empty( ss_f( 'staged_result' ) ),
            'the staged call must succeed: ' . (string) ss_f( 'staged_error' )
        );
        Agentshell_Test::assert_true(
            (bool) ss_f( 'option_unchanged_after_stage' ),
            'WordPress\'s own option must not change while the setting is staged'
        );
        Agentshell_Test::assert_equals(
            ss_f( 'old_title' ),
            ss_f( 'option_after_stage' ),
            'the live option must still hold the old title'
        );
    }
);

Agentshell_Test::run(
    'the transaction preview shows the pending setting',
    function () {
        $paths = (array) ss_f( 'preview_paths', array() );
        $found = false;
        foreach ( $paths as $path ) {
            // The path is "site" when the section is new (the whole
            // section is added), and "site.title" when it already existed
            // and one value changed.
            if ( is_string( $path ) && ( 'site' === $path || 0 === strpos( $path, 'site.' ) ) ) {
                $found = true;
            }
        }
        Agentshell_Test::assert_true(
            $found,
            'the preview must show the staged site setting; paths: ' . implode( ', ', array_map( 'strval', $paths ) )
        );
    }
);

Agentshell_Test::run(
    'a rollback discards the staged setting and the site does not change',
    function () {
        Agentshell_Test::assert_true(
            ! empty( ss_f( 'rolled_back' ) ),
            'the rollback must succeed: ' . (string) ss_f( 'rollback_error' )
        );
        Agentshell_Test::assert_true(
            (bool) ss_f( 'option_unchanged_after_rollback' ),
            'a rollback must leave the live option alone'
        );
        Agentshell_Test::assert_equals( ss_f( 'old_title' ), ss_f( 'option_after_rollback' ) );
    }
);

Agentshell_Test::run(
    'a commit applies the setting to WordPress',
    function () {
        Agentshell_Test::assert_true(
            ! empty( ss_f( 'commit_stage_ok' ) ),
            'the staged call must succeed: ' . (string) ss_f( 'commit_stage_error' )
        );
        Agentshell_Test::assert_true(
            ! empty( ss_f( 'committed' ) ),
            'the commit must succeed: ' . (string) ss_f( 'commit_error' )
        );
        Agentshell_Test::assert_true(
            (bool) ss_f( 'option_applied_on_commit' ),
            'the commit must project the setting onto the WordPress option'
        );
        Agentshell_Test::assert_true(
            0 === strpos( (string) ss_f( 'tagline_after_commit' ), 'ASss tagline' ),
            'the tagline must be projected too: ' . (string) ss_f( 'tagline_after_commit' )
        );
    }
);

Agentshell_Test::run(
    'a refused setting changes nothing',
    function () {
        $refusals = (array) ss_f( 'refusals', array() );
        Agentshell_Test::assert_equals( 3, count( $refusals ), 'every bad request must produce a refusal' );
        foreach ( $refusals as $refusal ) {
            Agentshell_Test::assert_true(
                0 !== strpos( (string) $refusal, 'accepted:' ),
                'a refused request was accepted: ' . (string) $refusal
            );
        }
        Agentshell_Test::assert_true(
            (bool) ss_f( 'option_unchanged_after_refusal' ),
            'a refused setting must leave the live option alone'
        );
    }
);

Agentshell_Test::run(
    'the protected options are not writable',
    function () {
        $refusals = (array) ss_f( 'refusals', array() );
        $found    = false;
        foreach ( $refusals as $refusal ) {
            if ( false !== strpos( (string) $refusal, 'siteurl' ) ) {
                $found = true;
            }
        }
        Agentshell_Test::assert_true( $found, 'the siteurl refusal must name the key' );
        Agentshell_Test::assert_true(
            '' !== (string) ss_f( 'siteurl_untouched' ),
            'the site URL must still be set'
        );
    }
);

Agentshell_Test::run(
    'the read tool separates the managed value from the live one',
    function () {
        Agentshell_Test::assert_equals(
            ss_f( 'read_managed_title' ),
            ss_f( 'read_settings_title' ),
            'the settings view must show the managed value'
        );
        Agentshell_Test::assert_true(
            in_array( 'title', (array) ss_f( 'read_writable', array() ), true ),
            'the writable list must name the title'
        );
        Agentshell_Test::assert_true(
            ! in_array( 'siteurl', (array) ss_f( 'read_writable', array() ), true ),
            'the writable list must not name the site URL'
        );
    }
);

Agentshell_Test::run(
    'the suite restores the site it found',
    function () {
        Agentshell_Test::assert_true(
            (bool) ss_f( 'option_restored' ),
            'the child must restore the site title it found'
        );
    }
);
