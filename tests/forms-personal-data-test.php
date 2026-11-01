<?php
/**
 * The Phase 5 authority matrix, against a real WordPress.
 *
 * Three principals share one disposable bed: B, an administrator without the
 * submissions capability (every agent principal looks like B, because the
 * transport requires manage_options); C, a subscriber with both forms
 * capabilities but without manage_options; and D, an administrator with the
 * submissions capability granted per user. The child exercises all twelve
 * tools through their real execute() paths; this file asserts the matrix:
 *
 *   - under the default `off` policy every entry tool refuses by name while
 *     the form tools and the metadata listing keep working;
 *   - with the policy on, an administrator holding manage_options but not
 *     the submissions capability is still refused on all four entry tools;
 *   - the acknowledgement and the confirmation are each independently
 *     required, and every refusal is audited with identifiers, never values;
 *   - a fully authorised principal reads, exports, trashes and deletes, and
 *     each act is audited;
 *   - the per-post delete authority is re-checked at deletion: a capable
 *     subscriber who may read still cannot erase;
 *   - the compare-and-swap races refuse stale revisions through the tools;
 *   - no tool result carries the Turnstile secret, no tool grants a
 *     capability, and no tool changes the policy;
 *   - everything created is removed again.
 *
 * The schemas, the policy defaults and the refusal paths that need no
 * storage are `tests/forms-tools-test.php`, which runs without a database.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "forms personal data (real WordPress)\n";

$config = agentshell_wp_live_config_destructive();

if ( null === $config ) {
	Agentshell_Test::run(
		'forms personal data suite',
		function () {
			Agentshell_Test::assert_true( true, 'skipped: no test-bed config (destructive suites require one)' );
		}
	);
	echo "  (skipped: destructive suites require a test-bed config)\n";
	return;
}

$report_file = sys_get_temp_dir() . '/as-forms-personal-data-' . getmypid() . '.json';
@unlink( $report_file );

$run = agentshell_wp_live_run(
	$config,
	__DIR__ . '/forms-personal-data-child.php',
	array( $config['wp_root'], $report_file )
);

$raw = @file_get_contents( $report_file );
@unlink( $report_file );

$f = json_decode( (string) $raw, true );

if ( ! is_array( $f ) ) {
	Agentshell_Test::run(
		'forms personal data: the child reported',
		function () use ( $run ) {
			Agentshell_Test::assert_true( false, 'the child produced no report: ' . $run['output'] );
		}
	);
	return;
}

$p = isset( $f['probes'] ) && is_array( $f['probes'] ) ? $f['probes'] : array();

if ( isset( $p['fatal'] ) ) {
	Agentshell_Test::run(
		'forms personal data: the child reached its subjects',
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
function asfpd( array $probes, $probe, $key ) {
	if ( ! isset( $probes[ $probe ] ) ) {
		throw new Exception( "the child did not report \"{$probe}\"" );
	}
	if ( ! array_key_exists( $key, $probes[ $probe ] ) ) {
		throw new Exception( "the probe \"{$probe}\" has no field \"{$key}\"" );
	}
	return $probes[ $probe ][ $key ];
}

Agentshell_Test::run(
	'the three principals are what the matrix needs',
	function () use ( $p ) {
		Agentshell_Test::assert_true( asfpd( $p, 'principals', 'b' ) > 0, 'B exists' );
		Agentshell_Test::assert_true( asfpd( $p, 'principals', 'c' ) > 0, 'C exists' );
		Agentshell_Test::assert_true( asfpd( $p, 'principals', 'd' ) > 0, 'D exists' );
		Agentshell_Test::assert_true( asfpd( $p, 'principals', 'b_manage_options' ), 'B holds manage_options' );
		Agentshell_Test::assert_true( asfpd( $p, 'principals', 'b_form_cap' ), 'B holds the form capability through the role' );
		Agentshell_Test::assert_false( asfpd( $p, 'principals', 'b_sub_cap' ), 'B does not hold the submissions capability' );
		Agentshell_Test::assert_false( asfpd( $p, 'principals', 'c_manage_options' ), 'C does not hold manage_options' );
		Agentshell_Test::assert_true( asfpd( $p, 'principals', 'c_form_cap' ), 'C holds the form capability by grant' );
		Agentshell_Test::assert_true( asfpd( $p, 'principals', 'c_sub_cap' ), 'C holds the submissions capability by grant' );
		Agentshell_Test::assert_true( asfpd( $p, 'principals', 'd_sub_cap' ), 'D holds the submissions capability by grant' );
	}
);

Agentshell_Test::run(
	'under the default off policy every entry tool refuses by name',
	function () use ( $p ) {
		$refusals = asfpd( $p, 'off_policy', 'refusals' );
		foreach ( array( 'agentshell_get_submission', 'agentshell_export_submissions', 'agentshell_trash_submission', 'agentshell_delete_submission' ) as $name ) {
			Agentshell_Test::assert_equals( 'forms_personal_data_disabled', $refusals[ $name ]['code'], $name . ': refused under off' );
			Agentshell_Test::assert_equals( 'off', $refusals[ $name ]['policy'], $name . ': the refusal carries the policy' );
		}
		Agentshell_Test::assert_true( asfpd( $p, 'off_policy', 'list_forms_ok' ), 'the form listing works under off' );
		Agentshell_Test::assert_true( asfpd( $p, 'off_policy', 'list_subs_ok' ), 'the metadata listing works under off' );
		Agentshell_Test::assert_equals( 2, asfpd( $p, 'off_policy', 'list_count' ), 'both submissions are listed' );
		Agentshell_Test::assert_true( asfpd( $p, 'off_policy', 'list_pure' ), 'no listed row carries values' );
		Agentshell_Test::assert_false( asfpd( $p, 'off_policy', 'list_leaks_name' ), 'the listing carries no submitted value' );
		Agentshell_Test::assert_true( asfpd( $p, 'off_policy', 's1_intact' ), 'a refused trash changes nothing' );
	}
);

Agentshell_Test::run(
	'manage_options is not a substitute for the submissions capability',
	function () use ( $p ) {
		Agentshell_Test::assert_true( asfpd( $p, 'capability_refusals', 'b_manage_options' ), 'same principal, same moment: manage_options held' );
		Agentshell_Test::assert_false( asfpd( $p, 'capability_refusals', 'b_sub_cap' ), 'same principal, same moment: submissions capability not held' );
		$refusals = asfpd( $p, 'capability_refusals', 'refusals' );
		foreach ( array( 'agentshell_get_submission', 'agentshell_export_submissions', 'agentshell_trash_submission', 'agentshell_delete_submission' ) as $name ) {
			Agentshell_Test::assert_equals( 'forms_submissions_capability_required', $refusals[ $name ], $name . ': refused for want of the capability' );
		}
		Agentshell_Test::assert_true( asfpd( $p, 'capability_refusals', 's1_intact' ), 'a refused delete changes nothing' );
	}
);

Agentshell_Test::run(
	'the acknowledgement and the confirmation are each required',
	function () use ( $p ) {
		Agentshell_Test::assert_equals( 'forms_personal_data_acknowledgement_required', asfpd( $p, 'gates', 'noack' ), 'a read without acknowledgement is refused' );
		Agentshell_Test::assert_equals( 'forms_confirmation_required', asfpd( $p, 'gates', 'noconfirm' ), 'a trash without confirmation is refused' );
		Agentshell_Test::assert_true( asfpd( $p, 'gates', 'c_read_ok' ), 'with the acknowledgement the capable subscriber reads' );
		Agentshell_Test::assert_true( asfpd( $p, 'gates', 'c_read_name' ), 'the read carries the submitted values' );
	}
);

Agentshell_Test::run(
	'a fully authorised principal reads and exports',
	function () use ( $p ) {
		Agentshell_Test::assert_true( asfpd( $p, 'authorised', 'get_ok' ), 'the read succeeds' );
		Agentshell_Test::assert_true( asfpd( $p, 'authorised', 'get_values' ), 'the read carries both submitted values' );
		Agentshell_Test::assert_true( asfpd( $p, 'authorised', 'export_ok' ), 'the CSV export succeeds' );
		Agentshell_Test::assert_equals( 2, asfpd( $p, 'authorised', 'export_count' ), 'the export carries both rows' );
		Agentshell_Test::assert_true( asfpd( $p, 'authorised', 'export_has_name' ), 'the export carries the submitted values' );
		Agentshell_Test::assert_true( asfpd( $p, 'authorised', 'export_json_ok' ), 'the JSON export succeeds' );
		Agentshell_Test::assert_equals( 2, asfpd( $p, 'authorised', 'export_json_n' ), 'the JSON export carries both rows' );
	}
);

Agentshell_Test::run(
	'the form tools work end to end and refuse stale revisions',
	function () use ( $p ) {
		Agentshell_Test::assert_true( asfpd( $p, 'form_tools', 'create_ok' ), 'create succeeds' );
		Agentshell_Test::assert_true( asfpd( $p, 'form_tools', 'get_ok' ), 'get succeeds' );
		Agentshell_Test::assert_true( asfpd( $p, 'form_tools', 'get_valid' ), 'a created form validates' );
		Agentshell_Test::assert_equals( 'form_revision_mismatch', asfpd( $p, 'form_tools', 'stale_update' ), 'a stale update is refused' );
		Agentshell_Test::assert_true( asfpd( $p, 'form_tools', 'stale_mismatch' ), 'the refusal names both revisions' );
		Agentshell_Test::assert_true( asfpd( $p, 'form_tools', 'current_update' ), 'a current update succeeds' );
		Agentshell_Test::assert_true( asfpd( $p, 'form_tools', 'rev_advanced' ), 'the update advances the revision' );
		Agentshell_Test::assert_equals( 'form_revision_mismatch', asfpd( $p, 'form_tools', 'stale_publish' ), 'a stale publish is refused' );
		Agentshell_Test::assert_true( asfpd( $p, 'form_tools', 'current_publish' ), 'a current publish succeeds' );
		Agentshell_Test::assert_equals( 'form_revision_mismatch', asfpd( $p, 'form_tools', 'stale_discard' ), 'a stale discard is refused' );
		Agentshell_Test::assert_true( asfpd( $p, 'form_tools', 'current_discard' ), 'a current discard succeeds' );
		Agentshell_Test::assert_true( asfpd( $p, 'form_tools', 'validate_bad_ok' ), 'an invalid definition fails validation with errors' );
		Agentshell_Test::assert_true( asfpd( $p, 'form_tools', 'validate_good_ok' ), 'a stored definition validates' );
	}
);

Agentshell_Test::run(
	'destruction is confirmed, ordered and audited',
	function () use ( $p ) {
		Agentshell_Test::assert_true( asfpd( $p, 'destructive', 'trash_ok' ), 'the confirmed trash succeeds' );
		Agentshell_Test::assert_equals( 'trash', asfpd( $p, 'destructive', 'trash_status' ), 'the submission is in the trash' );
		Agentshell_Test::assert_true( asfpd( $p, 'destructive', 'delete_ok' ), 'the confirmed delete succeeds' );
		Agentshell_Test::assert_equals( 'form_submission_not_found', asfpd( $p, 'destructive', 'gone_code' ), 'the erased submission is gone' );
	}
);

Agentshell_Test::run(
	'the per-post delete authority is re-checked at deletion',
	function () use ( $p ) {
		Agentshell_Test::assert_equals( 'form_submission_delete_forbidden', asfpd( $p, 'gates', 'c_delete' ), 'a reader without delete_post cannot erase' );
		Agentshell_Test::assert_true( asfpd( $p, 'gates', 's2_intact' ), 'the refused erasure leaves the record intact' );
	}
);

Agentshell_Test::run(
	'every personal-data act and every refusal is audited, without values',
	function () use ( $p ) {
		Agentshell_Test::assert_true( asfpd( $p, 'audit', 'created' ), 'form_created' );
		Agentshell_Test::assert_true( asfpd( $p, 'audit', 'updated' ), 'form_updated' );
		Agentshell_Test::assert_true( asfpd( $p, 'audit', 'published' ), 'form_published' );
		Agentshell_Test::assert_true( asfpd( $p, 'audit', 'discarded' ), 'form_discarded' );
		Agentshell_Test::assert_true( asfpd( $p, 'audit', 'inspected' ), 'form_submission_inspected' );
		Agentshell_Test::assert_true( asfpd( $p, 'audit', 'exported' ), 'form_submission_exported' );
		Agentshell_Test::assert_true( asfpd( $p, 'audit', 'trashed' ), 'form_submission_trashed' );
		Agentshell_Test::assert_true( asfpd( $p, 'audit', 'deleted' ), 'form_submission_deleted' );
		Agentshell_Test::assert_true( asfpd( $p, 'audit', 'refused_rows' ) >= 8, 'every refusal is a row' );
		Agentshell_Test::assert_true( asfpd( $p, 'audit', 'refused_off' ), 'off-policy refusals are audited' );
		Agentshell_Test::assert_true( asfpd( $p, 'audit', 'refused_cap' ), 'capability refusals are audited' );
		Agentshell_Test::assert_true( asfpd( $p, 'audit', 'refused_ack' ), 'acknowledgement refusals are audited' );
		Agentshell_Test::assert_true( asfpd( $p, 'audit', 'refused_con' ), 'confirmation refusals are audited' );
		Agentshell_Test::assert_true( asfpd( $p, 'audit', 'refused_forbid' ), 'the per-post authority refusal is audited' );
		Agentshell_Test::assert_false( asfpd( $p, 'audit', 'values_in_audit' ), 'no audit row carries a submitted value' );
	}
);

Agentshell_Test::run(
	'no tool result carries the Turnstile secret',
	function () use ( $p ) {
		Agentshell_Test::assert_false( asfpd( $p, 'secrets', 'leaks' ), 'get, list, export and form reads carry no secret' );
	}
);

Agentshell_Test::run(
	'no tool grants a capability and no tool changes the policy',
	function () use ( $p ) {
		Agentshell_Test::assert_true( asfpd( $p, 'audit', 'b_still_clean' ), 'the refused administrator still lacks the capability' );
		Agentshell_Test::assert_true( asfpd( $p, 'audit', 'role_clean' ), 'the administrator role never gained it' );
		Agentshell_Test::assert_true( asfpd( $p, 'audit', 'policy_kept' ), 'the policy option is what the test set' );
	}
);

Agentshell_Test::run(
	'everything created is removed again',
	function () use ( $p ) {
		Agentshell_Test::assert_equals( asfpd( $p, 'cleanup', 'tracked_posts' ), asfpd( $p, 'cleanup', 'tracked_gone' ), 'every form post is gone' );
		Agentshell_Test::assert_equals( 2, asfpd( $p, 'cleanup', 'entries_gone' ), 'both submissions are gone' );
		Agentshell_Test::assert_equals( asfpd( $p, 'cleanup', 'tracked_users' ), asfpd( $p, 'cleanup', 'users_gone' ), 'every test user is gone' );
		Agentshell_Test::assert_true( asfpd( $p, 'cleanup', 'policy_restored' ), 'the policy option is restored' );
		Agentshell_Test::assert_true( asfpd( $p, 'cleanup', 'secret_restored' ), 'the secret option is restored' );
		Agentshell_Test::assert_true( asfpd( $p, 'cleanup', 'c_cap_revoked' ), 'the subscriber grant is gone with the user' );
		Agentshell_Test::assert_true( asfpd( $p, 'cleanup', 'd_cap_revoked' ), 'the administrator grant is gone with the user' );
	}
);
