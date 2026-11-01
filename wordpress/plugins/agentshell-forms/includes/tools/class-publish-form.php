<?php
/**
 * agentshell_publish_form — promote the working definition to published.
 *
 * Publishes the working (draft) definition as the next version: the version
 * counter advances, the previous publication is retained beside it, and the
 * audit row commits with the publication, so a publication that cannot be
 * recorded does not happen at all. Guarded on `expected_revision` like
 * every other write. A form with no fields, or with a working definition
 * that cannot validate, is refused rather than published broken.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publish the working definition.
 */
class Publish_Form extends Base {
	public function get_name() { return 'agentshell_publish_form'; }
	public function get_description() { return 'Promote a form\'s working definition to published as the next version. Guarded on expected_revision; a form with no fields or an invalid working definition is refused.'; }
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'form_id'           => array( 'type' => 'string', 'description' => 'The form\'s internal identifier or post id.' ),
				'expected_revision' => array( 'type' => 'integer', 'description' => 'The working-revision counter the caller observed (read it from agentshell_get_form first).' ),
			),
			'required'   => array( 'form_id', 'expected_revision' ),
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

		$published = \AgentShell_Forms\Form_Definition::publish(
			(string) $state['form_id'],
			(int) $arguments['expected_revision'],
			\AgentShell_Forms\Form_Tools::actor_id()
		);

		if ( is_wp_error( $published ) ) {
			return \AgentShell_Forms\Form_Tools::error_result( $published );
		}

		$result = array(
			'ok'   => true,
			'form' => \AgentShell_Forms\Form_Tools::summarize( $published ),
		);

		if ( array_key_exists( 'status_synced', $published ) ) {
			$result['status_synced'] = (bool) $published['status_synced'];
		}

		return $result;
	}
}
