<?php
/**
 * agentshell_export_submissions — export one form's submissions as CSV or JSON.
 *
 * A personal-data act, gated exactly like a read: the `off` policy refuses,
 * the principal must hold `manage_agentshell_forms_submissions`, and the
 * explicit acknowledgement is required. The export itself is produced by the
 * administrative screen's own `export_payload()` — the same capability
 * re-check, the same published-definition columns, the same CSV
 * formula-injection guard, the same audit row with the count — so the tool
 * cannot drift from the screen it shadows. Exporting requires a published
 * definition to export against, exactly as the screen does.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export one form's submissions.
 */
class Export_Submissions extends Base {
	public function get_name() { return 'agentshell_export_submissions'; }
	public function get_description() { return 'Export one form\'s published submissions as CSV or JSON. Requires the submissions capability and authorize_personal_data: true; refused under the default off policy. The export is audited with the row count.'; }
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'form_id'                 => array( 'type' => 'string', 'description' => 'The form\'s internal identifier or post id. The form must have a published definition to export against.' ),
				'format'                  => array( 'type' => 'string', 'description' => 'csv or json. Defaults to csv.' ),
				'authorize_personal_data' => array( 'type' => 'boolean', 'description' => 'Required: state that this access to personal data is deliberate. Recorded in the audit log; grants nothing.' ),
			),
			'required'   => array( 'form_id' ),
		);
	}

	public function execute( array $arguments ) {
		$form_ref = isset( $arguments['form_id'] ) ? $arguments['form_id'] : '';

		$refused = \AgentShell_Forms\Form_Tools::guard_personal_data(
			$this->get_name(),
			$arguments,
			is_scalar( $form_ref ) ? array( 'form_id' => (string) $form_ref ) : array()
		);
		if ( null !== $refused ) {
			return $refused;
		}

		$state = \AgentShell_Forms\Form_Tools::resolve_form( $form_ref );

		if ( is_wp_error( $state ) ) {
			return \AgentShell_Forms\Form_Tools::error_result( $state );
		}

		$format = isset( $arguments['format'] ) ? strtolower( (string) $arguments['format'] ) : 'csv';
		if ( ! in_array( $format, array( 'csv', 'json' ), true ) ) {
			return array(
				'ok'      => false,
				'code'    => 'forms_format_invalid',
				'message' => 'Format must be csv or json.',
			);
		}

		// The screen's own export: the capability is re-checked inside, the
		// columns come from the published definition, and the audit row
		// carries the count. A helper that is safe only when its caller
		// remembers to check is one refactor away from an export nobody
		// authorised — so the gate above stays, and this stays the single
		// path that turns stored personal data into bytes.
		$payload = \AgentShell_Forms\Form_Admin::export_payload(
			(string) $state['form_id'],
			$format,
			\AgentShell_Forms\Form_Tools::actor_id()
		);

		if ( is_wp_error( $payload ) ) {
			return \AgentShell_Forms\Form_Tools::error_result( $payload );
		}

		return array(
			'ok'      => true,
			'form_id' => (string) $state['form_id'],
			'format'  => $format,
			'count'   => isset( $payload['count'] ) ? (int) $payload['count'] : 0,
			'content' => isset( $payload['body'] ) ? (string) $payload['body'] : '',
		);
	}
}
