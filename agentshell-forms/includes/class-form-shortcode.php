<?php
/**
 * `[agentshell_form]` — the insertion primitive a human actually types.
 *
 * ## Why the slug is the documented form
 *
 * `[agentshell_form slug="contact"]` is readable, editable and impossible to
 * confuse with a database key. The internal identifier is accepted too, and it
 * is what the design calls authoritative (§5.1), but a person editing a page by
 * hand should not have to paste 32 hexadecimal characters.
 *
 * ## The resolution rule is total, and it is not repeated here
 *
 * A value matching `^[a-f0-9]{32}$` is an internal identifier; anything else is
 * a slug, because the validator refuses a slug that looks like an identifier.
 * `Form_Renderer::resolve()` implements that rule, so the single-attribute case
 * is delegated to it rather than re-derived here. This class only adds the one
 * thing a shortcode needs that the renderer does not: **two** attributes that
 * must agree.
 *
 * ## Both attributes, and what happens when they disagree
 *
 * Exactly one of `id` or `slug` must be present. If both are present and they
 * name the same form, it renders — that is a harmless redundancy, and refusing
 * it would punish a page that is merely explicit. If they name different
 * forms, nothing renders and an editor who can manage forms is told why.
 * Guessing which of two contradictory strings the operator meant is how a page
 * ends up displaying the wrong form to a visitor.
 *
 * A pair where one resolves and the other does not is also a refusal, not a
 * silent preference for the one that worked: the attribute that failed is a
 * stale reference, and quietly rendering the other one would leave the stale
 * one in the page for the next person to trust.
 *
 * ## What a visitor sees when it fails
 *
 * Nothing. A shortcode that resolved to an error produces the empty string for
 * everybody who cannot manage forms, so a page with a broken shortcode is a
 * page without that form rather than a page with a stack-trace-shaped hole in
 * it. The diagnostic exists for the person who can fix it, and the request is
 * marked uncacheable so the cached copy of *their* view is not served to a
 * visitor.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `[agentshell_form]` shortcode.
 */
class Form_Shortcode {

	/**
	 * The shortcode tag.
	 *
	 * @var string
	 */
	const TAG = 'agentshell_form';

	/**
	 * Register the shortcode tag.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! function_exists( 'add_shortcode' ) ) {
			return;
		}

		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the shortcode.
	 *
	 * Shortcode attributes arrive as strings from the parser (or as `array()` /
	 * `''` from the block editor's call path), so every attribute is treated as
	 * untrusted input and re-validated here rather than trusted because a
	 * shortcode is "author content". An editor is not the renderer, and the
	 * renderer's escaping guarantees must not depend on who wrote the page.
	 *
	 * @param array|string $atts    Shortcode attributes.
	 * @param string|null  $content Enclosed content (unused; the tag is self-closing).
	 * @param string       $tag     The tag that was used.
	 * @return string
	 */
	public static function render( $atts = array(), $content = null, $tag = '' ) {
		unset( $content, $tag );

		$atts = is_array( $atts ) ? $atts : array();

		$id    = isset( $atts['id'] ) && is_scalar( $atts['id'] ) ? trim( (string) $atts['id'] ) : '';
		$slug  = isset( $atts['slug'] ) && is_scalar( $atts['slug'] ) ? trim( (string) $atts['slug'] ) : '';
		$class = isset( $atts['class'] ) && is_scalar( $atts['class'] ) ? (string) $atts['class'] : '';

		if ( '' === $id && '' === $slug ) {
			return Form_Renderer::render_diagnostic(
				new \WP_Error(
					'form_shortcode_missing_reference',
					sprintf(
						/* translators: %s: shortcode tag */
						__( 'The [%s] shortcode needs an “id” or a “slug” attribute.', 'agentshell-forms' ),
						self::TAG
					)
				)
			);
		}

		$args = self::args( $atts, $class );

		// One attribute: the renderer resolves it, and its own message is more
		// precise than anything reconstructed here.
		if ( '' === $id || '' === $slug ) {
			return Form_Renderer::render( '' !== $id ? $id : $slug, $args );
		}

		$by_id   = self::post_id_for( $id, true );
		$by_slug = self::post_id_for( $slug, false );

		// Two forms claim the slug, or the slug is malformed: the renderer has
		// the exact refusal for that, and it is the same refusal for one
		// attribute as for two.
		if ( $by_slug < 0 ) {
			return Form_Renderer::render( $slug, $args );
		}

		if ( $by_id > 0 && $by_slug > 0 && $by_id === $by_slug ) {
			$form_id = self::id_for_post( $by_id );
			return Form_Renderer::render( '' !== $form_id ? $form_id : $by_id, $args );
		}

		if ( $by_id > 0 && $by_slug > 0 ) {
			return Form_Renderer::render_diagnostic(
				new \WP_Error(
					'form_shortcode_ambiguous',
					sprintf(
						/* translators: 1: id attribute, 2: slug attribute */
						__( 'The “id” attribute (“%1$s”) and the “slug” attribute (“%2$s”) name different forms, so nothing was rendered.', 'agentshell-forms' ),
						$id,
						$slug
					)
				),
				$args
			);
		}

		// Exactly one side failed to resolve. Report the side that failed rather
		// than the side that worked: the working attribute is not the problem.
		if ( $by_id > 0 ) {
			return Form_Renderer::render_diagnostic(
				new \WP_Error(
					'form_not_found',
					sprintf(
						/* translators: %s: form slug */
						__( 'No form has the slug “%s”, so nothing was rendered.', 'agentshell-forms' ),
						$slug
					)
				),
				$args
			);
		}

		if ( $by_slug > 0 ) {
			return Form_Renderer::render_diagnostic(
				new \WP_Error(
					'form_not_found',
					sprintf(
						/* translators: %s: form identifier */
						__( 'No form has the identifier “%s”, so nothing was rendered.', 'agentshell-forms' ),
						$id
					)
				),
				$args
			);
		}

		// Neither resolves. Delegate so a visitor still sees nothing and an
		// editor sees the renderer's own message for the identifier, which is
		// the attribute the design calls authoritative.
		return Form_Renderer::render( $id, $args );
	}

	/**
	 * Turn shortcode attributes into renderer arguments.
	 *
	 * @param array  $atts  Shortcode attributes.
	 * @param string $class The `class` attribute.
	 * @return array
	 */
	private static function args( array $atts, $class ) {
		$args = array( 'context' => 'shortcode' );

		if ( '' !== $class ) {
			$args['class'] = $class;
		}

		if ( isset( $atts['title'] ) && is_scalar( $atts['title'] ) ) {
			// `title="hide"` and `title="0"` mean the same thing: the renderer's
			// own boolean parser decides, so the shortcode, the widget and the
			// API cannot disagree about what "hide" means.
			$args['title'] = (string) $atts['title'];
		}

		if ( isset( $atts['heading_level'] ) && is_scalar( $atts['heading_level'] ) ) {
			$args['heading_level'] = (int) $atts['heading_level'];
		}

		return $args;
	}

	/**
	 * A form post id for one attribute value, resolved by the documented rule.
	 *
	 * @param string $value  The attribute value.
	 * @param bool   $is_id  Whether the attribute was `id` (true) or `slug`.
	 * @return int A post id, 0 for "none", or -1 for "more than one form
	 *             claims this slug".
	 */
	private static function post_id_for( $value, $is_id ) {
		if ( $is_id ) {
			return preg_match( Form_Schema::FORM_ID_PATTERN, $value )
				? (int) Form_Definition::resolve_post_id( $value )
				: 0;
		}

		if ( preg_match( Form_Schema::FORM_ID_PATTERN, $value ) ) {
			// Identifier-shaped values are never slugs, by the validator's own
			// rule. A `slug="…"` attribute holding an identifier is therefore a
			// reference to *that identifier*, not a slug lookup.
			return (int) Form_Definition::resolve_post_id( $value );
		}

		if ( ! preg_match( Form_Schema::SLUG_PATTERN, $value ) ) {
			return 0;
		}

		$ids = Form_Definition::find_post_ids_by_meta( Form_Post_Types::META_SLUG, $value );

		if ( count( $ids ) > 1 ) {
			return -1;
		}

		return 1 === count( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * A post id's internal identifier, or ''.
	 *
	 * @param int $post_id Form post id.
	 * @return string
	 */
	private static function id_for_post( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id < 1 ) {
			return '';
		}

		$state = Form_Definition::get_by_post_id( $post_id );
		if ( is_wp_error( $state ) ) {
			return '';
		}

		return isset( $state['form_id'] ) ? (string) $state['form_id'] : '';
	}
}
