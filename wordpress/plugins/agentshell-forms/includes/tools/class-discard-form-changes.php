<?php
/**
 * agentshell_discard_form_changes — revert the working definition to the published one.
 *
 * Discards the draft, restoring the working definition to the published
 * definition. Like every other write it is guarded: when the caller passes
 * the revision it read, a stale discard is refused instead of throwing away
 * a revision somebody else saved after the caller loaded the page.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discard the working definition.
 */
class Discard_Form_Changes extends Base {
	public function get_name() { return 'agentshell_discard_form_changes'; }
	public function get_description() { return 'Revert a form\'s working definition to its published definition. Pass the observed expected_revision to refuse a stale discard instead of throwing away a newer save.'; }
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'form_id'           => array( 'type' => 'string', 'description' => 'The form\'s internal identifier or post id.' ),
				'expected_revision' => array( 'type' => 'integer', 'description' => 'Optional: the working-revision counter the caller observed. When supplied, a discard that would throw away a newer save is refused.' ),
			),
			'required'   => array( 'form_id' ),
		);
	}

	public function execute( array $arguments ) {
		$refused = \AgentShell_Forms\Form_Tools::require_forms_capability();
		if ( null !== $refused ) {
			return $refused;
		}

		$state = \AgentShell_Forms\Form_Tools::resolve_form( isset( $arguments['form_id'] ) ? $arguments['form_id'] : '' );

		if ( is_wp_error( $state ) ) {
			return \AgentShell_Forms\Form_Tools::error_result( $state );
		}

		$expected = array_key_exists( 'expected_revision', $arguments ) ? (int) $arguments['expected_revision'] : null;

		$discarded = \AgentShell_Forms\Form_Definition::discard(
			(string) $state['form_id'],
			\AgentShell_Forms\Form_Tools::actor_id(),
			$expected
		);

		if ( is_wp_error( $discarded ) ) {
			return \AgentShell_Forms\Form_Tools::error_result( $discarded );
		}

		return array(
			'ok'   => true,
			'form' => \AgentShell_Forms\Form_Tools::summarize( $discarded ),
		);
	}
}
