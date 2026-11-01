<?php
/**
 * The Phase 5 agent tool surface, without a database.
 *
 * Twelve tools, three postures: the seven form tools work under the form
 * capability, the metadata listing works in every policy, and the four
 * entry tools refuse under the default `off` policy. This suite pins what
 * can be pinned without WordPress:
 *
 *   - every tool answers to its documented name, with the documented
 *     required arguments and an MCP-safe definition;
 *   - the policy defaults to `off`, honours `capability`, and fails any
 *     other value closed back to `off`;
 *   - a principal without the form capability is refused before any
 *     storage is touched, and `manage_options` alone is not a substitute;
 *   - the four entry tools refuse under `off`, for a missing capability,
 *     for a missing acknowledgement and (the destructive two) for a
 *     missing confirmation — each refusal naming its code;
 *   - running the tools never flips the policy option and never grants a
 *     capability: the tripwire scans the tool sources for the grant and
 *     policy-write calls that must not be there;
 *   - no tool source mentions a secret: the Turnstile secret lives in its
 *     own option and no tool result may carry it.
 *
 * Everything that needs storage — result shapes, the audit rows, the
 * compare-and-swap races, the per-post delete authority — is
 * `tests/forms-personal-data-test.php`, against a real WordPress.
 */

require_once __DIR__ . '/bootstrap.php';

echo "forms tools (unit)\n";

if ( ! defined( 'AGENTSHELL_FORMS_SCHEMA_VERSION' ) ) {
	define( 'AGENTSHELL_FORMS_SCHEMA_VERSION', 1 );
}

require_once dirname( __DIR__ ) . '/agentshell-forms/includes/class-form-policy.php';
require_once dirname( __DIR__ ) . '/agentshell-forms/includes/class-form-capabilities.php';
require_once dirname( __DIR__ ) . '/agentshell-forms/includes/class-form-audit.php';
require_once dirname( __DIR__ ) . '/agentshell-forms/includes/class-form-tools.php';
require_once dirname( __DIR__ ) . '/agentshell-forms/includes/tools/class-base-tool.php';

$tools_dir = dirname( __DIR__ ) . '/agentshell-forms/includes/tools/';

$tool_files = array(
	'class-list-forms.php'           => 'agentshell_list_forms',
	'class-get-form.php'             => 'agentshell_get_form',
	'class-create-form.php'          => 'agentshell_create_form',
	'class-update-form.php'          => 'agentshell_update_form',
	'class-validate-form.php'        => 'agentshell_validate_form',
	'class-publish-form.php'         => 'agentshell_publish_form',
	'class-discard-form-changes.php' => 'agentshell_discard_form_changes',
	'class-list-submissions.php'     => 'agentshell_list_submissions',
	'class-get-submission.php'       => 'agentshell_get_submission',
	'class-export-submissions.php'   => 'agentshell_export_submissions',
	'class-trash-submission.php'     => 'agentshell_trash_submission',
	'class-delete-submission.php'    => 'agentshell_delete_submission',
);

foreach ( $tool_files as $file => $name ) {
	require_once $tools_dir . $file;
}

use AgentShell_Forms\Form_Capabilities;
use AgentShell_Forms\Form_Policy;
use AgentShell_Forms\Form_Tools;

// The stub bootstrap provides a fake $wpdb. These tests prove the tools
// refuse before any storage is touched — and the audit append is
// best-effort by construction — so they run with no database handle at
// all, exactly as tests/forms-transaction-test.php does. Saved and
// restored: the runner executes every suite in one process.
$ftools_previous_wpdb = $GLOBALS['wpdb'] ?? null;
$GLOBALS['wpdb']       = null;

/**
 * A tool instance by its agent-visible name.
 *
 * @param string $name Tool name.
 * @return object
 */
function ftools_make( $name ) {
	$map = array(
		'agentshell_list_forms'          => 'AgentShell_Forms\\Tools\\List_Forms',
		'agentshell_get_form'            => 'AgentShell_Forms\\Tools\\Get_Form',
		'agentshell_create_form'         => 'AgentShell_Forms\\Tools\\Create_Form',
		'agentshell_update_form'         => 'AgentShell_Forms\\Tools\\Update_Form',
		'agentshell_validate_form'       => 'AgentShell_Forms\\Tools\\Validate_Form',
		'agentshell_publish_form'        => 'AgentShell_Forms\\Tools\\Publish_Form',
		'agentshell_discard_form_changes' => 'AgentShell_Forms\\Tools\\Discard_Form_Changes',
		'agentshell_list_submissions'    => 'AgentShell_Forms\\Tools\\List_Submissions',
		'agentshell_get_submission'      => 'AgentShell_Forms\\Tools\\Get_Submission',
		'agentshell_export_submissions'  => 'AgentShell_Forms\\Tools\\Export_Submissions',
		'agentshell_trash_submission'    => 'AgentShell_Forms\\Tools\\Trash_Submission',
		'agentshell_delete_submission'   => 'AgentShell_Forms\\Tools\\Delete_Submission',
	);

	$class = $map[ $name ];
	return new $class();
}

/**
 * Set the current principal's capabilities in the stub.
 *
 * @param array $caps Capability name => bool.
 * @return void
 */
function ftools_set_caps( array $caps ) {
	$GLOBALS['_agentshell_test_user_caps'] = $caps;
	$GLOBALS['_agentshell_test_user_id']   = 7;
}

/**
 * Set the personal-data policy in the stub option store.
 *
 * @param mixed $value Value to store (strings, but the test also stores junk).
 * @return void
 */
function ftools_set_policy( $value ) {
	update_option( Form_Policy::OPTION, $value );
}

Agentshell_Test::run(
	'the twelve tools answer to their documented names',
	function () use ( $tool_files ) {
		$names = array();
		foreach ( $tool_files as $file => $expected ) {
			$tool = ftools_make( $expected );
			Agentshell_Test::assert_equals( $expected, $tool->get_name(), $file . ' must answer to ' . $expected );
			$names[] = $tool->get_name();
		}

		Agentshell_Test::assert_equals( 12, count( array_unique( $names ) ), 'the twelve names must be distinct' );
	}
);

Agentshell_Test::run(
	'every tool declares its required arguments',
	function () {
		$expected_required = array(
			'agentshell_list_forms'           => array(),
			'agentshell_get_form'             => array( 'form_id' ),
			'agentshell_create_form'          => array( 'name' ),
			'agentshell_update_form'          => array( 'form_id', 'expected_revision', 'definition' ),
			'agentshell_validate_form'        => array( 'definition' ),
			'agentshell_publish_form'         => array( 'form_id', 'expected_revision' ),
			'agentshell_discard_form_changes' => array( 'form_id' ),
			'agentshell_list_submissions'     => array(),
			'agentshell_get_submission'       => array( 'submission_id' ),
			'agentshell_export_submissions'   => array( 'form_id' ),
			'agentshell_trash_submission'     => array( 'submission_id' ),
			'agentshell_delete_submission'    => array( 'submission_id' ),
		);

		foreach ( $expected_required as $name => $required ) {
			$schema = ftools_make( $name )->get_input_schema();

			Agentshell_Test::assert_equals( 'object', $schema['type'], $name . ': schema type' );
			Agentshell_Test::assert_true( isset( $schema['properties'] ) && is_array( $schema['properties'] ), $name . ': properties' );

			$actual = isset( $schema['required'] ) && is_array( $schema['required'] ) ? array_values( $schema['required'] ) : array();
			sort( $actual );
			$want = $required;
			sort( $want );

			Agentshell_Test::assert_equals( $want, $actual, $name . ': required arguments' );

			foreach ( $actual as $key ) {
				Agentshell_Test::assert_true( array_key_exists( $key, $schema['properties'] ), $name . ': required key ' . $key . ' is described' );
			}
		}
	}
);

Agentshell_Test::run(
	'tool definitions serialise the way strict MCP clients require',
	function () use ( $tool_files ) {
		foreach ( $tool_files as $expected ) {
			$def = ftools_make( $expected )->get_definition();

			Agentshell_Test::assert_equals( $expected, $def['name'], $expected . ': definition name' );
			Agentshell_Test::assert_true( '' !== (string) $def['description'], $expected . ': description is not empty' );

			$json = json_decode( wp_json_encode( $def ) );
			Agentshell_Test::assert_true( is_object( $json->inputSchema->properties ), $expected . ': properties serialise as an object' );
			$required = $def['inputSchema']['required'] ?? null;
			Agentshell_Test::assert_true( null === $required || is_array( $required ), $expected . ': required stays an array' );
		}

		// The zero-argument tools are the ones the empty-array defect bit.
		$empty = ftools_make( 'agentshell_list_forms' )->get_definition();
		$json  = json_decode( wp_json_encode( $empty ) );
		Agentshell_Test::assert_equals( 0, count( get_object_vars( $json->inputSchema->properties ) ), 'a zero-argument tool carries {}' );
	}
);

Agentshell_Test::run(
	'the policy defaults to off, honours capability, and fails closed',
	function () {
		delete_option( Form_Policy::OPTION );
		Agentshell_Test::assert_equals( 'off', Form_Policy::get(), 'a missing option reads as off' );

		ftools_set_policy( 'capability' );
		Agentshell_Test::assert_equals( 'capability', Form_Policy::get(), 'capability is honoured' );

		ftools_set_policy( ' Capability ' );
		Agentshell_Test::assert_equals( 'capability', Form_Policy::get(), 'the value is trimmed and lowered' );

		foreach ( array( 'credential', 'off ', 'OFF', '', 'yes', 'true', '1' ) as $value ) {
			ftools_set_policy( $value );
			Agentshell_Test::assert_equals( 'off', Form_Policy::get(), var_export( $value, true ) . ' reads as off' );
		}

		ftools_set_policy( array( 'capability' ) );
		Agentshell_Test::assert_equals( 'off', Form_Policy::get(), 'a non-string reads as off' );

		ftools_set_policy( 'capability' );
	}
);

Agentshell_Test::run(
	'a principal without the form capability is refused before any storage',
	function () {
		ftools_set_caps( array() );
		ftools_set_policy( 'off' );

		$form_tools = array(
			'agentshell_list_forms'           => array(),
			'agentshell_get_form'             => array( 'form_id' => 'nope' ),
			'agentshell_create_form'          => array( 'name' => 'Nope' ),
			'agentshell_update_form'          => array( 'form_id' => 'nope', 'expected_revision' => 0, 'definition' => array() ),
			'agentshell_validate_form'        => array( 'definition' => array() ),
			'agentshell_publish_form'         => array( 'form_id' => 'nope', 'expected_revision' => 0 ),
			'agentshell_discard_form_changes' => array( 'form_id' => 'nope' ),
			'agentshell_list_submissions'     => array(),
		);

		foreach ( $form_tools as $name => $args ) {
			$result = ftools_make( $name )->execute( $args );

			Agentshell_Test::assert_equals( false, $result['ok'], $name . ': refused' );
			Agentshell_Test::assert_equals( 'forms_capability_forbidden', $result['code'], $name . ': the refusal names the capability' );
		}
	}
);

Agentshell_Test::run(
	'manage_options alone authorises nothing on the form surface',
	function () {
		// The transport holds manage_options for every call. A tool that
		// accepted it as a substitute would grant every agent everything.
		ftools_set_caps( array( 'manage_options' => true ) );
		ftools_set_policy( 'capability' );

		foreach ( array( 'agentshell_list_forms', 'agentshell_list_submissions' ) as $name ) {
			$result = ftools_make( $name )->execute( array() );

			Agentshell_Test::assert_equals( false, $result['ok'], $name . ': refused' );
			Agentshell_Test::assert_equals( 'forms_capability_forbidden', $result['code'], $name . ': manage_options is not a substitute' );
		}

		foreach ( array( 'agentshell_get_submission', 'agentshell_export_submissions', 'agentshell_trash_submission', 'agentshell_delete_submission' ) as $name ) {
			$args = 'agentshell_export_submissions' === $name
				? array( 'form_id' => 'x', 'authorize_personal_data' => true, 'confirm' => true )
				: array( 'submission_id' => 1, 'authorize_personal_data' => true, 'confirm' => true );

			$result = ftools_make( $name )->execute( $args );

			Agentshell_Test::assert_equals( false, $result['ok'], $name . ': refused' );
			Agentshell_Test::assert_equals( 'forms_submissions_capability_required', $result['code'], $name . ': manage_options is not a substitute' );
		}
	}
);

Agentshell_Test::run(
	'under the default off policy every entry tool refuses by name',
	function () {
		ftools_set_policy( 'off' );
		ftools_set_caps(
			array(
				'manage_options'                          => true,
				Form_Capabilities::CAP_FORMS              => true,
				Form_Capabilities::CAP_SUBMISSIONS        => true,
			)
		);

		$entry_tools = array(
			'agentshell_get_submission'      => array( 'submission_id' => 1, 'authorize_personal_data' => true ),
			'agentshell_export_submissions'  => array( 'form_id' => 'x', 'authorize_personal_data' => true ),
			'agentshell_trash_submission'    => array( 'submission_id' => 1, 'authorize_personal_data' => true, 'confirm' => true ),
			'agentshell_delete_submission'   => array( 'submission_id' => 1, 'authorize_personal_data' => true, 'confirm' => true ),
		);

		foreach ( $entry_tools as $name => $args ) {
			$result = ftools_make( $name )->execute( $args );

			Agentshell_Test::assert_equals( false, $result['ok'], $name . ': refused under off' );
			Agentshell_Test::assert_equals( 'forms_personal_data_disabled', $result['code'], $name . ': the refusal names the policy' );
			Agentshell_Test::assert_equals( 'off', $result['policy'], $name . ': the refusal carries the policy' );
		}
	}
);

Agentshell_Test::run(
	'with the policy on, the acknowledgement and the confirmation are each required',
	function () {
		ftools_set_policy( 'capability' );
		ftools_set_caps(
			array(
				'manage_options'                   => true,
				Form_Capabilities::CAP_FORMS       => true,
				Form_Capabilities::CAP_SUBMISSIONS => true,
			)
		);

		foreach ( array( 'agentshell_get_submission', 'agentshell_trash_submission', 'agentshell_delete_submission' ) as $name ) {
			$args   = array( 'submission_id' => 1 );
			$result = ftools_make( $name )->execute( $args );

			Agentshell_Test::assert_equals( false, $result['ok'], $name . ': refused without acknowledgement' );
			Agentshell_Test::assert_equals( 'forms_personal_data_acknowledgement_required', $result['code'], $name . ': the refusal names the acknowledgement' );
		}

		$result = ftools_make( 'agentshell_export_submissions' )->execute( array( 'form_id' => 'x' ) );
		Agentshell_Test::assert_equals( 'forms_personal_data_acknowledgement_required', $result['code'], 'export: the refusal names the acknowledgement' );

		foreach ( array( 'agentshell_trash_submission', 'agentshell_delete_submission' ) as $name ) {
			$result = ftools_make( $name )->execute( array( 'submission_id' => 1, 'authorize_personal_data' => true ) );

			Agentshell_Test::assert_equals( false, $result['ok'], $name . ': refused without confirmation' );
			Agentshell_Test::assert_equals( 'forms_confirmation_required', $result['code'], $name . ': the refusal names the confirmation' );
		}
	}
);

Agentshell_Test::run(
	'running the tools never flips the policy option',
	function () {
		ftools_set_policy( 'capability' );
		ftools_set_caps( array() );

		ftools_make( 'agentshell_list_forms' )->execute( array() );
		ftools_make( 'agentshell_get_submission' )->execute( array( 'submission_id' => 1, 'authorize_personal_data' => true, 'confirm' => true ) );
		ftools_make( 'agentshell_delete_submission' )->execute( array( 'submission_id' => 1, 'authorize_personal_data' => true, 'confirm' => true ) );

		Agentshell_Test::assert_equals( 'capability', get_option( Form_Policy::OPTION ), 'the policy option is untouched by the tools' );

		ftools_set_policy( 'off' );
	}
);

Agentshell_Test::run(
	'no tool can grant a capability, revoke one, or write an option',
	function () use ( $tools_dir, $tool_files ) {
		$guard_files = array(
			dirname( $tools_dir ) . '/class-form-tools.php',
			dirname( $tools_dir ) . '/class-form-policy.php',
		);

		// Checks are fine — can_manage_forms, can_access_submissions. Writes
		// are not: a tool that grants, revokes or flips the switch would be
		// an agent widening its own authority.
		$forbidden = array(
			'add_cap',
			'remove_cap',
			'grant_submissions',
			'revoke_submissions',
			'update_option',
			'add_option',
			'delete_option',
		);

		foreach ( array_merge( array_keys( $tool_files ), array( 'class-base-tool.php' ) ) as $file ) {
			$source = file_get_contents( $tools_dir . $file );
			Agentshell_Test::assert_true( is_string( $source ), $file . ' is readable' );

			foreach ( $forbidden as $token ) {
				Agentshell_Test::assert_false(
					false !== strpos( $source, $token ),
					$file . ' must not contain ' . $token
				);
			}
		}

		foreach ( $guard_files as $path ) {
			$source = file_get_contents( $path );
			Agentshell_Test::assert_true( is_string( $source ), basename( $path ) . ' is readable' );

			foreach ( $forbidden as $token ) {
				Agentshell_Test::assert_false(
					false !== strpos( $source, $token ),
					basename( $path ) . ' must not contain ' . $token . ': the guards read authority, they never write it'
				);
			}
		}
	}
);

Agentshell_Test::run(
	'no tool source mentions a secret',
	function () use ( $tools_dir, $tool_files ) {
		foreach ( array_merge( array_keys( $tool_files ), array( 'class-base-tool.php' ) ) as $file ) {
			$source = file_get_contents( $tools_dir . $file );
			Agentshell_Test::assert_true( is_string( $source ), $file . ' is readable' );
			Agentshell_Test::assert_false(
				false !== stripos( $source, 'secret' ),
				$file . ' must not mention a secret: no tool result may carry one'
			);
		}
	}
);

Agentshell_Test::run(
	'error results carry the code, the message and only whitelisted data',
	function () {
		$error = new WP_Error(
			'form_revision_mismatch',
			'Stale.',
			array(
				'expected_revision' => 3,
				'actual_revision'   => 5,
				'internal'          => 'must not cross',
			)
		);

		$result = Form_Tools::error_result( $error );

		Agentshell_Test::assert_equals( false, $result['ok'], 'a failure is not ok' );
		Agentshell_Test::assert_equals( 'form_revision_mismatch', $result['code'], 'the code crosses' );
		Agentshell_Test::assert_equals( 'Stale.', $result['message'], 'the message crosses' );
		Agentshell_Test::assert_equals( 3, $result['expected_revision'], 'the expected revision crosses' );
		Agentshell_Test::assert_equals( 5, $result['actual_revision'], 'the actual revision crosses' );
		Agentshell_Test::assert_false( array_key_exists( 'internal', $result ), 'unlisted error data does not cross' );
	}
);

$GLOBALS['wpdb'] = $ftools_previous_wpdb;
