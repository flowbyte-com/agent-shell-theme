<?php
/**
 * agentshell_get_submission — read one submission's contents.
 *
 * A personal-data act, and gated as one: under the default `off` policy the
 * tool refuses without examining any principal; otherwise the principal must
 * hold `manage_agentshell_forms_submissions` (`manage_options` is not a
 * substitute) and must pass the explicit `authorize_personal_data`
 * acknowledgement, which is recorded in the audit row. A successful read
 * writes a `form_submission_inspected` row naming the submission, the form,
 * the acknowledgement and the policy — never a submitted value.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read one submission.
 */
class Get_Submission extends Base {
	public function get_name() { return 'agentshell_get_submission'; }
	public function get_description() { return 'Read one submission including its submitted values. Requires the submissions capability and authorize_personal_data: true; refused under the default off policy. The read is audited.'; }
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'submission_id'           => array( 'type' => 'integer', 'description' => 'The submission\'s entry id.' ),
				'authorize_personal_data' => array( 'type' => 'boolean', 'description' => 'Required: state that this access to personal data is deliberate. Recorded in the audit log; grants nothing.' ),
			),
			'required'   => array( 'submission_id' ),
		);
	}

	public function execute( array $arguments ) {
		$submission_id = isset( $arguments['submission_id'] ) ? (int) $arguments['submission_id'] : 0;

		$refused = \AgentShell_Forms\Form_Tools::guard_personal_data(
			$this->get_name(),
			$arguments,
			array( 'submission' => $submission_id )
		);
		if ( null !== $refused ) {
			return $refused;
		}

		$record = \AgentShell_Forms\Submission_Store::get( $submission_id, true );

		if ( is_wp_error( $record ) ) {
			return \AgentShell_Forms\Form_Tools::error_result( $record );
		}

		\AgentShell_Forms\Form_Audit::record(
			'form_submission_inspected',
			array(
				'submission'              => (int) $record['id'],
				'form_id'                 => (string) $record['form_id'],
				'authorize_personal_data' => true,
				'policy'                  => \AgentShell_Forms\Form_Policy::get(),
			),
			\AgentShell_Forms\Form_Tools::actor_id()
		);

		$notification = isset( $record['notification'] ) && is_array( $record['notification'] ) ? $record['notification'] : array();

		return array(
			'ok'         => true,
			'submission' => array(
				'id'                  => (int) $record['id'],
				'form_id'             => (string) $record['form_id'],
				'form_version'        => (int) $record['form_version'],
				'received_at'         => (string) $record['received_at'],
				'spam_status'         => (string) $record['spam_status'],
				'notification_status' => isset( $notification['status'] ) ? (string) $notification['status'] : '',
				'field_count'         => (int) $record['field_count'],
				'values'              => is_array( $record['values'] ) ? $record['values'] : array(),
			),
		);
	}
}
