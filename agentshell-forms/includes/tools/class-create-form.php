<?php
/**
 * agentshell_create_form — a new draft form.
 *
 * Takes a name and, optionally, a first field set: the slug, the
 * description, the fields and the settings of the working definition the
 * form is created with. The definition is normalised and validated before
 * anything is written, so a form that cannot validate is refused rather
 * than stored half-formed. The creation commits with its audit row; a
 * creation that cannot be recorded does not happen at all.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create a form.
 */
class Create_Form extends Base {
	public function get_name() { return 'agentshell_create_form'; }
	public function get_description() { return 'Create a new draft form with a name and, optionally, a slug, a description, a first set of fields and settings. Omitting fields starts the form with a single Name field, as the builder does — a stored form always carries at least one field. Returns the new form\'s summary.'; }
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'name'        => array( 'type' => 'string', 'description' => 'The form\'s name.' ),
				'slug'        => array( 'type' => 'string', 'description' => 'Optional slug. Defaults to a slug derived from the name; frozen after the first publication.' ),
				'description' => array( 'type' => 'string', 'description' => 'Optional description shown with the form.' ),
				'fields'      => array( 'type' => 'array', 'description' => 'Optional first set of fields, each with key, type, label and settings.' ),
				'settings'    => array( 'type' => 'object', 'description' => 'Optional settings (notification, spam, storage, privacy). Defaults are filled in.' ),
			),
			'required'   => array( 'name' ),
		);
	}

	public function execute( array $arguments ) {
		$refused = \AgentShell_Forms\Form_Tools::require_forms_capability();
		if ( null !== $refused ) {
			return $refused;
		}

		$args = array(
			'name' => isset( $arguments['name'] ) ? (string) $arguments['name'] : '',
		);

		foreach ( array( 'slug', 'description' ) as $key ) {
			if ( isset( $arguments[ $key ] ) && is_string( $arguments[ $key ] ) ) {
				$args[ $key ] = $arguments[ $key ];
			}
		}

		foreach ( array( 'fields', 'settings' ) as $key ) {
			if ( isset( $arguments[ $key ] ) && is_array( $arguments[ $key ] ) ) {
				$args[ $key ] = $arguments[ $key ];
			}
		}

		if ( empty( $args['fields'] ) ) {
			// The schema requires between one and two hundred fields, so a
			// form cannot be *stored* with none. The builder's REST
			// controller starts with this same field; the tool does the same
			// rather than refusing a form created from only a name.
			$args['fields'] = array(
				array(
					'key'      => 'name',
					'type'     => 'text',
					'label'    => 'Name',
					'required' => true,
					'settings' => array(
						'max_length'   => 120,
						'min_length'   => 0,
						'autocomplete' => 'name',
					),
					'options'  => array(),
				),
			);
		}

		$state = \AgentShell_Forms\Form_Definition::create( $args, \AgentShell_Forms\Form_Tools::actor_id() );

		if ( is_wp_error( $state ) ) {
			return \AgentShell_Forms\Form_Tools::error_result( $state );
		}

		return array(
			'ok'   => true,
			'form' => \AgentShell_Forms\Form_Tools::summarize( $state ),
		);
	}
}
