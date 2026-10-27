<?php
/**
 * The plugin's documented PHP API.
 *
 * §4.3 of the design names a small, stable surface so that other code can read,
 * validate and render forms without touching the store's internals.
 * `agentshell_forms_render()` is the Phase 2 addition; the rest are the Phase 1
 * readers. There are no other global functions in this plugin.
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

/**
 * Render a form.
 *
 * This is the one rendering entry point other code should use. The shortcode,
 * the widget and this function all call `Form_Renderer::render()`, so a caller
 * that renders a form gets exactly what a visitor sees — the **published**
 * definition, never the working one.
 *
 * `$form_id` may be the internal identifier (32 lowercase hexadecimal
 * characters), a post id, or — as a convenience for parity with the shortcode —
 * the form's slug.
 *
 * Returns the form's HTML, or an empty string when the form is unknown,
 * unpublished, trashed or invalid. An actor who can manage forms receives a
 * short diagnostic in place of the form, and that request is marked
 * uncacheable; a visitor receives nothing, so a missing form is not an
 * information leak and not a visible error page.
 *
 * The returned markup is already escaped and is safe to echo. Callers must not
 * escape it again, and must not treat it as untrusted text.
 *
 * Recognised `$args`:
 *
 * | Key | Default | Meaning |
 * |---|---|---|
 * | `title` | `'show'` | `show`/`hide`: render the form's name as a heading |
 * | `heading_level` | `2` | `2`–`6` |
 * | `class` | `''` | Extra classes on the `<form>` |
 * | `values` | `array()` | Values to re-populate (Phase 3's rejected submissions) |
 * | `errors` | `array()` | `field key => message`, rendered as a summary and per field |
 * | `status` | `''` | Text for the status region |
 *
 * @param string|int $form_id Internal identifier, slug or post id.
 * @param array      $args    Rendering arguments.
 * @return string
 */
function agentshell_forms_render( $form_id, array $args = array() ) {
	$args['context'] = isset( $args['context'] ) ? (string) $args['context'] : 'api';

	return \AgentShell_Forms\Form_Renderer::render( $form_id, $args );
}
