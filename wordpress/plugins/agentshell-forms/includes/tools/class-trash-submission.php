<?php
/**
 * agentshell_trash_submission — move a submission to the trash.
 *
 * A personal-data act with a destructive edge, gated on both: the `off`
 * policy refuses, the principal must hold `manage_agentshell_forms_submissions`,
 * the explicit acknowledgement is required, and the destruction additionally
 * requires `confirm: true`. Trashing is reversible through the administrative
 * screen; the erasure path is the separate delete tool. The store writes the
 * `form_submission_trashed` row; a refusal writes
 * `form_submission_access_refused` instead.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trash one submission.
 */
class Trash_Submission extends Base {
	public function get_name() { return 'agentshell_trash_submission'; }
	public function get_description() { return 'Move one submission to the trash (reversible in the admin screen). Requires the submissions capability, authorize_personal_data: true and confirm: true; refused under the default off policy.'; }
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'submission_id'           => array( 'type' => 'integer', 'description' => 'The submission\'s entry id.' ),
				'authorize_personal_data' => array( 'type' => 'boolean', 'description' => 'Required: state that this access to personal data is deliberate. Recorded in the audit log; grants nothing.' ),
				'confirm'                 => array( 'type' => 'boolean', 'description' => 'Required: state that the destructive act is deliberate. Not waived for the owner.' ),
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

		$confirmed = \AgentShell_Forms\Form_Tools::require_confirmation(
			$this->get_name(),
			$arguments,
			\AgentShell_Forms\Form_Policy::get(),
			array( 'submission' => $submission_id )
		);
		if ( null !== $confirmed ) {
			return $confirmed;
		}

		$trashed = \AgentShell_Forms\Submission_Store::trash( $submission_id, \AgentShell_Forms\Form_Tools::actor_id() );

		if ( is_wp_error( $trashed ) ) {
			// A store-level authority refusal is a refusal of this tool like
			// any other, and is audited as one. An operational failure or a
			// missing record passes through unaudited: it is not a decision
			// about authority.
			$code = (string) $trashed->get_error_code();
			if ( 'form_submission_trash_forbidden' === $code || 'form_submission_trash_unconfirmed' === $code ) {
				\AgentShell_Forms\Form_Tools::audit_refusal(
					$this->get_name(),
					$code,
					\AgentShell_Forms\Form_Policy::get(),
					array( 'submission' => $submission_id )
				);
			}

			return \AgentShell_Forms\Form_Tools::error_result( $trashed );
		}

		return array(
			'ok'            => true,
			'submission_id' => $submission_id,
			'trashed'       => true,
		);
	}
}
