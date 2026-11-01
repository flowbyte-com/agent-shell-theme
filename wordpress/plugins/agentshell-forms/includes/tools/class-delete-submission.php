<?php
/**
 * agentshell_delete_submission — hard-delete a submission. The erasure path.
 *
 * A personal-data act that destroys the record, gated on everything at once:
 * the `off` policy refuses, the principal must hold
 * `manage_agentshell_forms_submissions`, the explicit acknowledgement is
 * required, and the destruction additionally requires `confirm: true`. The
 * per-post `delete_post` authority is re-checked at the moment of deletion
 * inside the store — for the acting user, not for a system operation — so a
 * principal whose submission access outruns their per-post authority is
 * refused with the record intact. The store writes the
 * `form_submission_deleted` row; a refusal writes
 * `form_submission_access_refused` instead. There is no undo.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hard-delete one submission.
 */
class Delete_Submission extends Base {
	public function get_name() { return 'agentshell_delete_submission'; }
	public function get_description() { return 'Permanently erase one submission. Requires the submissions capability, authorize_personal_data: true and confirm: true; refused under the default off policy. Per-post delete authority is re-checked at deletion. There is no undo.'; }
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'submission_id'           => array( 'type' => 'integer', 'description' => 'The submission\'s entry id.' ),
				'authorize_personal_data' => array( 'type' => 'boolean', 'description' => 'Required: state that this access to personal data is deliberate. Recorded in the audit log; grants nothing.' ),
				'confirm'                 => array( 'type' => 'boolean', 'description' => 'Required: state that the irreversible erasure is deliberate. Not waived for the owner.' ),
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

		// The third argument is the explicit erasure confirmation: without it
		// the store refuses, so a tool that forgot its own confirm check
		// would still be stopped here rather than erasing on a bare call.
		$deleted = \AgentShell_Forms\Submission_Store::delete_permanently(
			$submission_id,
			\AgentShell_Forms\Form_Tools::actor_id(),
			true
		);

		if ( is_wp_error( $deleted ) ) {
			// A store-level authority refusal — the per-post delete check —
			// is a refusal of this tool like any other, and is audited as
			// one. An operational failure or a missing record passes through
			// unaudited: it is not a decision about authority.
			$code = (string) $deleted->get_error_code();
			if ( 'form_submission_delete_forbidden' === $code || 'form_submission_delete_unconfirmed' === $code ) {
				\AgentShell_Forms\Form_Tools::audit_refusal(
					$this->get_name(),
					$code,
					\AgentShell_Forms\Form_Policy::get(),
					array( 'submission' => $submission_id )
				);
			}

			return \AgentShell_Forms\Form_Tools::error_result( $deleted );
		}

		return array(
			'ok'            => true,
			'submission_id' => $submission_id,
			'deleted'       => true,
		);
	}
}
