<?php
/**
 * The plugin's documented PHP API.
 *
 * §4.3 of the design names a small, stable surface so that other code can read
 * and validate forms without touching the store's internals. Four of the five
 * functions belong to Phase 1; `agentshell_forms_render()` is Phase 2 and is
 * deliberately absent, because a renderer that does not exist cannot be
 * half-implemented.
 *
 * These are **global** functions on purpose, and they are the only part of the
 * plugin that is global. Everything else lives under the `AgentShell_Forms`
 * namespace, so an internal rename cannot break a caller and a caller cannot
 * reach an internal by accident.
 *
 * @package AgentShell_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The registered field types, after the filter.
 *
 * @return array<string,array>
 */
function agentshell_forms_field_types() {
	return \AgentShell_Forms\Form_Field_Types::all();
}

/**
 * Validate a definition.
 *
 * The document is normalised first, so a caller may supply only the keys it
 * cares about. The normaliser fills declared defaults and **preserves** unknown
 * keys, which is what lets the validator refuse them by name instead of
 * silently discarding them.
 *
 * @param array $definition The document.
 * @return array{ok:bool,errors:array<int,array{code:string,path:string,message:string}>,warnings:array<int,array>}
 */
function agentshell_forms_validate( array $definition ) {
	$normalised = \AgentShell_Forms\Form_Schema::normalise( $definition );
	$errors     = \AgentShell_Forms\Form_Schema::validate( $normalised );

	return array(
		'ok'       => empty( $errors ),
		'errors'   => $errors,
		// The validator has no warning channel today: a rule either holds or it
		// does not. The key exists because a caller that has to branch on it
		// later should not have to discover a new return shape.
		'warnings' => array(),
	);
}

/**
 * The working (draft) definition of a form, or null.
 *
 * @param string|int $form_id Internal identifier or post id.
 * @return array|null
 */
function agentshell_forms_get_working( $form_id ) {
	$post_id = \AgentShell_Forms\Form_Definition::resolve_post_id( $form_id );
	if ( 0 === $post_id ) {
		return null;
	}

	$state = \AgentShell_Forms\Form_Definition::get_by_post_id( $post_id );
	if ( is_wp_error( $state ) ) {
		return null;
	}

	return is_array( $state['working'] ) ? $state['working'] : null;
}

/**
 * The published definition of a form, or null.
 *
 * The renderer and the submission pipeline read this and nothing else, which is
 * what makes an uncommitted edit not merely hidden but absent from the render
 * path.
 *
 * @param string|int $form_id Internal identifier or post id.
 * @return array|null
 */
function agentshell_forms_get_published( $form_id ) {
	$post_id = \AgentShell_Forms\Form_Definition::resolve_post_id( $form_id );
	if ( 0 === $post_id ) {
		return null;
	}

	$state = \AgentShell_Forms\Form_Definition::get_by_post_id( $post_id );
	if ( is_wp_error( $state ) ) {
		return null;
	}

	return is_array( $state['published'] ) ? $state['published'] : null;
}
