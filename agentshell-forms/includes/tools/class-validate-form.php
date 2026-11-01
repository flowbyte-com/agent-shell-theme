<?php
/**
 * agentshell_validate_form — check a definition without writing anything.
 *
 * Normalises the candidate and runs the authoritative validator against it,
 * in the context of an existing form when `form_id` names one (so the slug
 * freeze and the duplicate-slug rule are judged the way a save would judge
 * them) or as a new form otherwise. Nothing is written; a definition that
 * cannot validate is reported, not stored.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validate a definition.
 */
class Validate_Form extends Base {
	public function get_name() { return 'agentshell_validate_form'; }
	public function get_description() { return 'Validate a form definition and return the errors and warnings, without writing anything. Pass form_id to validate in the context of an existing form.'; }
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'definition' => array( 'type' => 'object', 'description' => 'The candidate definition: fields, settings, description.' ),
				'form_id'    => array( 'type' => 'string', 'description' => 'Optional: the internal id or post id of the form this definition belongs to. Validates the slug freeze and duplicate-slug rule in that form\'s context.' ),
			),
			'required'   => array( 'definition' ),
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

		$context = array(
			'site_host'          => \AgentShell_Forms\Form_Definition::site_host(),
			'has_been_published' => false,
			'previous_slug'      => '',
		);

		if ( isset( $arguments['form_id'] ) && '' !== (string) $arguments['form_id'] ) {
			$state = \AgentShell_Forms\Form_Tools::resolve_form( $arguments['form_id'] );

			if ( is_wp_error( $state ) ) {
				return \AgentShell_Forms\Form_Tools::error_result( $state );
			}

			$post_id                  = (int) $state['post_id'];
			$context['slug_taken']    = function ( $candidate ) use ( $post_id ) {
				return \AgentShell_Forms\Form_Definition::slug_is_taken( (string) $candidate, $post_id );
			};
			$context['has_been_published'] = (bool) $state['has_been_published'];
			$context['previous_slug']      = (string) $state['slug'];
		} else {
			$context['slug_taken'] = function ( $candidate ) {
				return \AgentShell_Forms\Form_Definition::slug_is_taken( (string) $candidate, 0 );
			};
		}

		$normalised = \AgentShell_Forms\Form_Schema::normalise( $arguments['definition'] );
		$errors     = \AgentShell_Forms\Form_Schema::validate( $normalised, $context );

		return array(
			'ok'       => empty( $errors ),
			'errors'   => array_values( $errors ),
			'warnings' => array(),
		);
	}
}
