<?php
/**
 * Parent test for the published-preview OWNERSHIP regression
 * (HAL 9001 remediation).
 *
 * A staged edit belongs to the actor whose transaction holds it. The
 * published-preview capture read the transaction's journal with no
 * ownership check, so any actor who could edit the page could obtain a
 * credential for — and therefore read — another actor's unpublished
 * staged edit of it.
 *
 * Spawns the live child against the disposable test bed and asserts the
 * structured results. Skips cleanly when no test bed is configured.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "live published-preview ownership (real WordPress)\n";

$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    Agentshell_Test::skip( 'no test-bed config (destructive suites require tests/config/test-bed.json)\\n' );
    Agentshell_Test::run(
        'live published-preview ownership',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config' );
        }
    );
    return;
}

$child       = __DIR__ . '/published-preview-ownership-live-child.php';
$report_file = sys_get_temp_dir() . '/as-ppown-live-' . getmypid() . '.json';
@unlink( $report_file );

$run = agentshell_wp_live_run(
    $config,
    $child,
    array( $config['wp_root'], $report_file )
);

echo $run['output'];

$f = json_decode( (string) @file_get_contents( $report_file ), true );
@unlink( $report_file );
if ( ! is_array( $f ) ) {
    Agentshell_Test::run(
        'live published-preview ownership: child produced a report',
        function () use ( $run ) {
            Agentshell_Test::assert_true(
                is_array( $GLOBALS['ppo_f'] ),
                'child failed to report: ' . $run['output']
            );
        }
    );
    return;
}
$GLOBALS['ppo_f'] = $f;

function ppo_f( $key, $default = null ) {
    $f = $GLOBALS['ppo_f'];
    return array_key_exists( $key, $f ) ? $f[ $key ] : $default;
}

Agentshell_Test::run(
    'live published-preview ownership',
    function () {
        Agentshell_Test::assert_true(
            (bool) ppo_f( 'o0_other_user_created' ),
            'O0.1 a second actor must be creatable'
        );
        Agentshell_Test::assert_true(
            (bool) ppo_f( 'o0_staged' ),
            'O0.2 the edit must have been staged'
        );

        Agentshell_Test::assert_true(
            (bool) ppo_f( 'o1_owner_allowed' ),
            'O1.1 the transaction OWNER must be able to preview their own staged edit: ' . (string) ppo_f( 'o1_owner_error', '' )
        );

        Agentshell_Test::assert_true(
            (bool) ppo_f( 'o2_other_can_edit_page' ),
            'O2.0 the second actor must be able to edit the page — otherwise this proves nothing'
        );
        Agentshell_Test::assert_true(
            (bool) ppo_f( 'o2_other_refused' ),
            'O2.1 a different actor must be REFUSED the staged edit, even though they can edit the page'
        );
        Agentshell_Test::assert_true(
            (bool) ppo_f( 'o3_names_owner' ),
            'O3.1 the refusal must name the transaction\'s owner: ' . (string) ppo_f( 'o2_other_error', '' )
        );
        Agentshell_Test::assert_true(
            (bool) ppo_f( 'o3_names_other' ),
            'O3.2 the refusal must name the caller: ' . (string) ppo_f( 'o2_other_error', '' )
        );

        Agentshell_Test::assert_true(
            (bool) ppo_f( 'o4_owner_still_allowed' ),
            'O4.1 the owner must still be able to preview after another actor was refused: ' . (string) ppo_f( 'o4_owner_error', '' )
        );
    }
);
