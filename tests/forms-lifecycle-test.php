<?php
/**
 * The forms plugin lifecycle, against a real WordPress.
 *
 * Three claims:
 *
 *   1. **Activation is complete and idempotent.** The tables, the capabilities,
 *      the storage-revision option and the prune job are all created, and a
 *      reactivation recreates what is missing without duplicating anything.
 *
 *   2. **Deactivation destroys nothing.** A plugin whose deactivation removed
 *      forms would be a deactivation that silently destroyed content.
 *
 *   3. **Uninstall is a decision, not a side effect.** Removing the plugin's
 *      bookkeeping requires one explicit constant; removing the operator's
 *      *content* requires a second, separate one. A single switch would let an
 *      operator destroy forms while intending only to tidy up after the plugin.
 *
 * Each step is its own process, because an uninstall is genuinely a different
 * request from the one that activated the plugin and `uninstall.php` is written
 * to run in that different request.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "forms lifecycle (real WordPress)\n";

$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    Agentshell_Test::run(
        'forms lifecycle suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config (destructive suites require one)' );
        }
    );
    echo "  (skipped: destructive suites require a test-bed config)\n";
    return;
}

$child  = __DIR__ . '/forms-lifecycle-child.php';
$token  = 'ASlife' . substr( md5( (string) ( time() . getmypid() ) ), 0, 8 );

/**
 * Run one lifecycle step and decode its report.
 *
 * @param array  $config Test-bed config.
 * @param string $script Child script.
 * @param string $mode   Step name.
 * @param string $token  Run token.
 * @return array
 */
function flo_step( array $config, $script, $mode, $token ) {
    $report = rtrim( sys_get_temp_dir(), '/' ) . '/as-flo-' . getmypid() . '-' . $mode . '.json';
    @unlink( $report );

    $run = agentshell_wp_live_run( $config, $script, array( $config['wp_root'], $mode, $report, $token ) );

    $decoded = json_decode( (string) @file_get_contents( $report ), true );
    @unlink( $report );

    return is_array( $decoded ) ? $decoded : array( 'error' => 'no_report', 'mode' => $mode, 'output' => $run['output'] );
}

/**
 * One step's reported state.
 *
 * @param array  $step Report.
 * @param string $key  `state`, `before` or `after`.
 * @return array
 */
function flo_state( array $step, $key = 'state' ) {
    return isset( $step[ $key ] ) && is_array( $step[ $key ] ) ? $step[ $key ] : array();
}

/* ---------------------------------------------------------------------------
 * Activation
 * ------------------------------------------------------------------------ */

$seed = flo_step( $config, $child, 'seed', $token );

Agentshell_Test::run(
    'forms lifecycle: activation created the tables, the capabilities and the storage revision',
    function () use ( $seed ) {
        Agentshell_Test::assert_false( isset( $seed['error'] ), 'the seed step must report: ' . wp_json_encode( $seed ) );

        $s = flo_state( $seed );

        Agentshell_Test::assert_true( true === ( $s['tables_exist'] ?? false ), 'both tables must exist' );
        Agentshell_Test::assert_true( true === ( $s['challenge_table'] ?? false ), 'the challenge table must exist' );
        Agentshell_Test::assert_true( true === ( $s['rate_table'] ?? false ), 'the rate-limit table must exist' );
        Agentshell_Test::assert_true( true === ( $s['form_type'] ?? false ), 'the form post type must be registered' );
        Agentshell_Test::assert_true( true === ( $s['entry_type'] ?? false ), 'the entry post type must be registered' );
        Agentshell_Test::assert_equals( '1', $s['db_version_option'] ?? null, 'the storage revision must be recorded' );
        Agentshell_Test::assert_true( true === ( $s['cron_scheduled'] ?? false ), 'the prune job must be scheduled' );
        Agentshell_Test::assert_true( true === ( $s['admin_has_forms'] ?? false ), 'the administrator role must be able to manage forms' );
        Agentshell_Test::assert_true( false === ( $s['admin_has_submissions'] ?? true ), 'the administrator role must not receive submission access' );
        Agentshell_Test::assert_true( (int) ( $seed['form_id'] ?? 0 ) > 0, 'the probe form must exist' );
        Agentshell_Test::assert_true( (int) ( $seed['entry_id'] ?? 0 ) > 0, 'the probe entry must exist' );
        Agentshell_Test::assert_equals( 2, count( (array) ( $s['probe_posts'] ?? array() ) ), 'exactly the two probe posts must be found' );
        Agentshell_Test::assert_equals( 3, (int) ( $s['probe_meta_rows'] ?? -1 ), 'the probe content must carry its metadata' );
    }
);

/* ---------------------------------------------------------------------------
 * Deactivation
 * ------------------------------------------------------------------------ */

$off = flo_step( $config, $child, 'deactivate', $token );

Agentshell_Test::run(
    'forms lifecycle: deactivation removes no data at all',
    function () use ( $off ) {
        $before = flo_state( $off, 'before' );
        $after  = flo_state( $off, 'after' );

        Agentshell_Test::assert_true( true === ( $before['cron_scheduled'] ?? false ), 'the prune job must have been scheduled before deactivation' );
        Agentshell_Test::assert_false( (bool) ( $after['cron_scheduled'] ?? true ), 'deactivation must unschedule the prune job' );

        Agentshell_Test::assert_true( true === ( $after['tables_exist'] ?? false ), 'deactivation must not drop the tables' );
        Agentshell_Test::assert_equals( $before['posts'] ?? null, $after['posts'] ?? 'x', 'deactivation must not remove a single post' );
        Agentshell_Test::assert_equals( $before['probe_meta_rows'] ?? null, $after['probe_meta_rows'] ?? 'x', 'deactivation must not remove metadata' );
        Agentshell_Test::assert_equals( $before['db_version_option'] ?? null, $after['db_version_option'] ?? 'x', 'deactivation must not remove the storage revision' );
        Agentshell_Test::assert_equals( $before['audit_option'] ?? null, $after['audit_option'] ?? 'x', 'deactivation must not clear the audit log' );
        Agentshell_Test::assert_equals( $before['admin_has_forms'] ?? null, $after['admin_has_forms'] ?? 'x', 'deactivation must not remove capabilities' );
        Agentshell_Test::assert_equals( $before['probe_posts'] ?? null, $after['probe_posts'] ?? 'x', 'the probe content must be exactly where it was' );
    }
);

/* ---------------------------------------------------------------------------
 * Reactivation
 * ------------------------------------------------------------------------ */

$on = flo_step( $config, $child, 'reactivate', $token );

Agentshell_Test::run(
    'forms lifecycle: reactivation restores the schedule and keeps the content',
    function () use ( $on ) {
        $before = flo_state( $on, 'before' );
        $after  = flo_state( $on, 'after' );

        Agentshell_Test::assert_true( true === ( $after['cron_scheduled'] ?? false ), 'reactivation must reschedule the prune job' );
        Agentshell_Test::assert_true( true === ( $after['tables_exist'] ?? false ), 'reactivation must leave the tables in place' );
        Agentshell_Test::assert_equals(
            $before['probe_posts'] ?? null,
            $after['probe_posts'] ?? 'x',
            'the probe content must be exactly where it was after a full cycle'
        );
        Agentshell_Test::assert_equals(
            $before['probe_meta_rows'] ?? null,
            $after['probe_meta_rows'] ?? 'x',
            'the metadata must be intact after a full cycle'
        );
    }
);

/* ---------------------------------------------------------------------------
 * Uninstall with no decision made
 * ------------------------------------------------------------------------ */

$default = flo_step( $config, $child, 'uninstall_default', $token );

Agentshell_Test::run(
    'forms lifecycle: uninstalling with no explicit decision removes nothing',
    function () use ( $default ) {
        $before = flo_state( $default, 'before' );
        $after  = flo_state( $default, 'after' );

        Agentshell_Test::assert_false( isset( $default['error'] ), 'the default uninstall must report: ' . wp_json_encode( $default ) );

        Agentshell_Test::assert_true( true === ( $after['tables_exist'] ?? false ), 'an undecided uninstall must not drop the tables' );
        Agentshell_Test::assert_equals( '1', $after['db_version_option'] ?? null, 'an undecided uninstall must not remove the storage revision' );
        Agentshell_Test::assert_true( true === ( $after['audit_option'] ?? false ), 'an undecided uninstall must not clear the audit log' );
        Agentshell_Test::assert_equals( $before['posts'] ?? null, $after['posts'] ?? 'x', 'an undecided uninstall must not remove content' );
        Agentshell_Test::assert_equals( $before['probe_meta_rows'] ?? null, $after['probe_meta_rows'] ?? 'x', 'an undecided uninstall must not remove metadata' );
        Agentshell_Test::assert_true( true === ( $after['admin_has_forms'] ?? false ), 'an undecided uninstall must not remove capabilities' );
        Agentshell_Test::assert_true( true === ( $after['cron_scheduled'] ?? false ), 'an undecided uninstall must not unschedule the prune job' );
    }
);

/* ---------------------------------------------------------------------------
 * Uninstall of the plugin's bookkeeping only
 * ------------------------------------------------------------------------ */

$data = flo_step( $config, $child, 'uninstall_data', $token );

Agentshell_Test::run(
    'forms lifecycle: removing the plugin’s bookkeeping does not remove the operator’s content',
    function () use ( $data ) {
        $before = flo_state( $data, 'before' );
        $after  = flo_state( $data, 'after' );

        Agentshell_Test::assert_false( isset( $data['error'] ), 'the data uninstall must report: ' . wp_json_encode( $data ) );
        Agentshell_Test::assert_true( true === ( $before['tables_exist'] ?? false ), 'the tables must have existed before the uninstall' );

        // The bookkeeping is gone.
        Agentshell_Test::assert_false( (bool) ( $after['tables_exist'] ?? true ), 'the plugin tables must be dropped' );
        Agentshell_Test::assert_false( (bool) ( $after['challenge_table'] ?? true ), 'the challenge table must be dropped' );
        Agentshell_Test::assert_false( (bool) ( $after['rate_table'] ?? true ), 'the rate-limit table must be dropped' );
        Agentshell_Test::assert_equals( '__absent__', $after['db_version_option'] ?? null, 'the storage revision must be removed' );
        Agentshell_Test::assert_false( (bool) ( $after['audit_option'] ?? true ), 'the audit log must be removed' );
        Agentshell_Test::assert_false( (bool) ( $after['cron_scheduled'] ?? true ), 'the prune job must be unscheduled' );
        Agentshell_Test::assert_false( (bool) ( $after['admin_has_forms'] ?? true ), 'the capability must be removed from the role' );

        // The content is not. This is the load-bearing assertion: one constant
        // means "tidy up after the plugin", not "destroy my forms".
        Agentshell_Test::assert_equals(
            $before['posts'] ?? null,
            $after['posts'] ?? 'x',
            'removing the plugin’s bookkeeping must not remove a single post'
        );
        Agentshell_Test::assert_equals(
            $before['probe_meta_rows'] ?? null,
            $after['probe_meta_rows'] ?? 'x',
            'removing the plugin’s bookkeeping must not remove the operator’s metadata'
        );
        Agentshell_Test::assert_equals(
            $before['probe_posts'] ?? null,
            $after['probe_posts'] ?? 'x',
            'the forms and submissions must be exactly where they were'
        );
    }
);

/* ---------------------------------------------------------------------------
 * Uninstall of everything, as an explicit second decision
 * ------------------------------------------------------------------------ */

$all = flo_step( $config, $child, 'uninstall_all', $token );

Agentshell_Test::run(
    'forms lifecycle: the explicitly destructive uninstall removes the content too',
    function () use ( $all ) {
        $before = flo_state( $all, 'before' );
        $after  = flo_state( $all, 'after' );

        Agentshell_Test::assert_false( isset( $all['error'] ), 'the destructive uninstall must report: ' . wp_json_encode( $all ) );
        Agentshell_Test::assert_true( (int) ( $before['posts']['agentshell_form'] ?? 0 ) >= 1, 'the probe form must have existed beforehand' );
        Agentshell_Test::assert_true( (int) ( $before['posts']['agentshell_entry'] ?? 0 ) >= 1, 'the probe entry must have existed beforehand' );

        Agentshell_Test::assert_equals( array(), $after['probe_posts'] ?? null, 'the explicitly destructive uninstall must remove the forms and submissions' );
        Agentshell_Test::assert_equals( 0, (int) ( $after['probe_meta_rows'] ?? -1 ), 'it must not leave orphaned metadata either' );
        Agentshell_Test::assert_false( (bool) ( $after['tables_exist'] ?? true ), 'the tables must be gone' );
    }
);

/* ---------------------------------------------------------------------------
 * Restore the bed
 * ------------------------------------------------------------------------ */

$restore = flo_step( $config, $child, 'restore', $token );

Agentshell_Test::run(
    'forms lifecycle: the plugin can be installed again after a full removal',
    function () use ( $restore ) {
        $s = flo_state( $restore );

        Agentshell_Test::assert_true( true === ( $s['tables_exist'] ?? false ), 'reactivation must recreate the tables' );
        Agentshell_Test::assert_equals( '1', $s['db_version_option'] ?? null, 'reactivation must record the storage revision again' );
        Agentshell_Test::assert_true( true === ( $s['cron_scheduled'] ?? false ), 'reactivation must schedule the prune job' );
        Agentshell_Test::assert_true( true === ( $s['admin_has_forms'] ?? false ), 'reactivation must restore form management' );
        Agentshell_Test::assert_true( false === ( $s['admin_has_submissions'] ?? true ), 'reactivation must still not grant submission access' );
    }
);

/* ---------------------------------------------------------------------------
 * The bed is left clean
 * ------------------------------------------------------------------------ */

$sweep = flo_step( $config, $child, 'sweep', $token );

Agentshell_Test::run(
    'forms lifecycle: no probe content is left behind',
    function () use ( $sweep ) {
        $s = flo_state( $sweep );

        Agentshell_Test::assert_equals( array(), $s['probe_posts'] ?? null, 'every probe post must be gone' );
        Agentshell_Test::assert_equals( 0, (int) ( $s['probe_meta_rows'] ?? -1 ), 'no probe metadata may remain' );
        Agentshell_Test::assert_true( true === ( $s['tables_exist'] ?? false ), 'the bed must be left usable' );
        Agentshell_Test::assert_true( true === ( $s['admin_has_forms'] ?? false ), 'the bed must be left with the plugin usable' );
    }
);
