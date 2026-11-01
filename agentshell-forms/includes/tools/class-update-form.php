<?php
/**
 * agentshell_update_form — replace the working definition, guarded on the revision seen.
 *
 * The write is a compare-and-swap against `expected_revision`: the revision
 * the caller read. A caller whose snapshot went stale while it was editing
 * is refused with `form_revision_mismatch` instead of silently discarding
 * somebody else's save. The definition's identity fields (form id, slug,
 * version, status) are taken from the stored state, never from the caller,
 * so an update is not a way around the slug freeze or the version counter.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replace the working definition.
 */
class Update_Form extends Base {
	public function get_name() { return 'agentshell_update_form'; }
	public function get_description() { return 'Replace a form\'s working (draft) definition. A compare-and-swap against expected_revision: a stale write is refused with form_revision_mismatch rather than overwriting a newer save.'; }
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'form_id'           => array( 'type' => 'string', 'description' => 'The form\'s internal identifier or post id.' ),
				'expected_revision' => array( 'type' => 'integer', 'description' => 'The working-revision counter the caller observed (read it from agentshell_get_form first).' ),
				'definition'        => array( 'type' => 'object', 'description' => 'The complete new working definition: fields, settings, description. Identity fields are taken from the stored form, not from this document.' ),
			),
			'required'   => array( 'form_id', 'expected_revision', 'definition' ),
		);
	}

	public function execute( array $arguments ) {
		$refused = \AgentShell_Forms\Form_Tools::require_forms_capability();
		if ( null !== $refused ) {
			return $refused;
		}

		if ( ! isset( $arguments['definition'] ) || ! is_array( $arguments['definition'] ) ) {
			return array(
				'ok'      => false,
				'code'    => 'form_definition_invalid',
				'message' => 'A definition object is required.',
			);
		}

		$state = \AgentShell_Forms\Form_Tools::resolve_form( isset( $arguments['form_id'] ) ? $arguments['form_id'] : '' );

		if ( is_wp_error( $state ) ) {
			return \AgentShell_Forms\Form_Tools::error_result( $state );
		}

		$updated = \AgentShell_Forms\Form_Definition::update_working(
			(string) $state['form_id'],
			$arguments['definition'],
			(int) $arguments['expected_revision'],
			\AgentShell_Forms\Form_Tools::actor_id()
		);

		if ( is_wp_error( $updated ) ) {
			return \AgentShell_Forms\Form_Tools::error_result( $updated );
		}

		return array(
			'ok'   => true,
			'form' => \AgentShell_Forms\Form_Tools::summarize( $updated ),
		);
	}
}
