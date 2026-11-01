<?php
/**
 * agentshell_get_form — read one form: its definitions, its validation and its metadata.
 *
 * Returns the working (draft) definition, the published definition (or null
 * when the form has never been published), the validation of the working
 * definition, and the summary metadata. Definitions are operator-authored
 * configuration and carry no submitted values, so the form authority is the
 * only authority this tool needs.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read one form.
 */
class Get_Form extends Base {
	public function get_name() { return 'agentshell_get_form'; }
	public function get_description() { return 'Read one form by its internal id or post id: the working and published definitions, the validation of the working definition, and the summary metadata.'; }
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'form_id' => array( 'type' => 'string', 'description' => 'The form\'s internal identifier (32 lowercase hex characters) or its WordPress post id.' ),
			),
			'required'   => array( 'form_id' ),
		);
	}

	public function execute( array $arguments ) {
		$refused = \AgentShell_Forms\Form_Tools::require_forms_capability();
		if ( null !== $refused ) {
			return $refused;
		}

		$reference = isset( $arguments['form_id'] ) ? $arguments['form_id'] : '';
		$state     = \AgentShell_Forms\Form_Tools::resolve_form( $reference );

		if ( is_wp_error( $state ) ) {
			return \AgentShell_Forms\Form_Tools::error_result( $state );
		}

		$working = isset( $state['working'] ) && is_array( $state['working'] ) ? $state['working'] : null;

		if ( ! is_array( $working ) ) {
			return array(
				'ok'      => false,
				'code'    => 'form_no_working_definition',
				'message' => 'This form has no working definition.',
			);
		}

		$normalised = \AgentShell_Forms\Form_Schema::normalise( $working );
		$errors     = \AgentShell_Forms\Form_Schema::validate(
			$normalised,
			array(
				'site_host'          => \AgentShell_Forms\Form_Definition::site_host(),
				'slug_taken'         => function ( $candidate ) use ( $state ) {
					return \AgentShell_Forms\Form_Definition::slug_is_taken( (string) $candidate, (int) $state['post_id'] );
				},
				'has_been_published' => (bool) $state['has_been_published'],
				'previous_slug'      => (string) $state['slug'],
			)
		);

		return array(
			'ok'         => true,
			'form'       => \AgentShell_Forms\Form_Tools::summarize( $state ),
			'working'    => $working,
			'published'  => isset( $state['published'] ) && is_array( $state['published'] ) ? $state['published'] : null,
			'validation' => array(
				'ok'       => empty( $errors ),
				'errors'   => array_values( $errors ),
				'warnings' => array(),
			),
		);
	}
}
