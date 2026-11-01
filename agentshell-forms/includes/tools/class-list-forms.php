<?php
/**
 * agentshell_list_forms — every form, without any submitted values.
 *
 * The listing carries identifiers, counters and definition metadata: the
 * internal identifier, the slug, the name, the status, the version, the
 * field count and the entry count. A definition is operator-authored
 * configuration and carries no personal data, so the form authority —
 * `manage_agentshell_forms` — is the only authority this tool needs, and
 * the listing stays available in every personal-data policy.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List the forms.
 */
class List_Forms extends Base {
	public function get_name() { return 'agentshell_list_forms'; }
	public function get_description() { return 'List every form: internal id, slug, name, status, version, revision, field count and entry count. Carries no submitted values.'; }
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	public function execute( array $arguments ) {
		$refused = \AgentShell_Forms\Form_Tools::require_forms_capability();
		if ( null !== $refused ) {
			return $refused;
		}

		$posts = get_posts(
			array(
				'post_type'      => \AgentShell_Forms\Form_Post_Types::FORM,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$forms = array();

		foreach ( (array) $posts as $post ) {
			$state = \AgentShell_Forms\Form_Definition::get_by_post_id( (int) $post->ID );

			if ( is_wp_error( $state ) || '' === (string) $state['form_id'] ) {
				continue;
			}

			$forms[] = \AgentShell_Forms\Form_Tools::summarize( $state );
		}

		return array(
			'ok'    => true,
			'forms' => $forms,
			'count' => count( $forms ),
		);
	}
}
