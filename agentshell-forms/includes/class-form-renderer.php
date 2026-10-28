<?php
/**
 * The authoritative server-side renderer.
 *
 * ## One renderer, three front doors
 *
 * The shortcode, the widget and `agentshell_forms_render()` all call
 * `Form_Renderer::render()`. There is no second implementation of "what a form
 * looks like", so a widget cannot drift from a shortcode and an API caller
 * cannot get markup the operator never previewed.
 *
 * ## The published definition, and nothing else
 *
 * `render()` resolves a form, reads its **published** definition and renders
 * that. The working definition is never on this path — not filtered, not
 * merged, not preferred when the published one is missing. A form with no
 * published definition renders nothing, because it has never been published.
 * That is what makes an uncommitted edit *absent* from the public page rather
 * than merely hidden (§9.2).
 *
 * `render_definition()` is the pure half: it renders a document it is handed,
 * with no store access. It exists so the markup can be tested without a
 * database, and so Phase 3's error re-render and Phase 4's preview can use the
 * same code as the visitor-facing page instead of a look-alike.
 *
 * ## Escaping
 *
 * Everything the operator authored — the form name, every label, description
 * and option label, the submit label — is escaped for the context it lands in
 * and never passed through as HTML. There is no raw-HTML path in this class
 * and adding one would defeat the point of the class. Attributes are escaped
 * with `esc_attr()`, text with `esc_html()`, URLs with `esc_url()`. A label
 * containing `<script>` renders as the characters `<script>`.
 *
 * ## Unique ids, and why they are per instance
 *
 * A page may carry the same form twice (two shortcodes, a shortcode and a
 * widget, or the same shortcode in a reusable block). DOM ids must be unique,
 * or every `<label for>` on the page resolves to the first instance's control
 * and the rest are unusable with a screen reader. The renderer therefore gives
 * each *instance* a distinct id prefix and keeps the field `name` attributes
 * identical, because it is the ids that must be unique and the names that must
 * agree with the submission protocol.
 *
 * ## Cache safety
 *
 * Nothing in the output is per-visitor. No nonce, no timestamp, no challenge
 * token, no session value: two renders of the same published version produce
 * the same bytes, so a page cache may store and serve them to everybody. The
 * single-use submission challenge is provisioned by the browser from
 * `Form_Challenge_Endpoint`, which is the whole reason that endpoint exists
 * (§"Cache-safe challenge design" in the Phase 2 report).
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render a published form definition to HTML.
 */
class Form_Renderer {

	/**
	 * Per-request instance counter.
	 *
	 * A page with the same form twice gets `…-1-…` and `…-2-…`, so ids stay
	 * unique without the renderer having to know anything about the page.
	 *
	 * @var int
	 */
	private static $instances = 0;

	/**
	 * Whether the plugin's assets have been enqueued in this request.
	 *
	 * @var bool
	 */
	private static $styles_enqueued = false;

	/**
	 * The heading levels the renderer will emit.
	 *
	 * A form inside a widget needs a different level from one inside an
	 * article's body, and a page can contain both. Nothing outside this list is
	 * accepted: an arbitrary tag name from a shortcode attribute is an
	 * injection point, however harmless it looks.
	 *
	 * @var array<int,string>
	 */
	private static $heading_tags = array( 'h2', 'h3', 'h4', 'h5', 'h6' );

	/**
	 * Render a form by reference.
	 *
	 * `$reference` may be:
	 *
	 *   - the internal identifier (32 lowercase hex characters);
	 *   - a slug (anything else);
	 *   - a post id (numeric), which the documented API also accepts.
	 *
	 * A form that does not exist, has never been published, has been trashed,
	 * or whose published definition no longer validates renders `''`. An actor
	 * who can manage forms additionally gets a short diagnostic, so a broken
	 * shortcode is visible to the person who can fix it and invisible to
	 * everybody else.
	 *
	 * @param string|int $reference Internal id, slug or post id.
	 * @param array      $args      Rendering arguments; see `render_definition()`.
	 * @return string
	 */
	public static function render( $reference, array $args = array() ) {
		$resolved = self::resolve( $reference, $args );

		if ( is_wp_error( $resolved ) ) {
			return self::render_diagnostic( $resolved, $args );
		}

		$post_id = (int) $resolved['post_id'];
		$state   = Form_Definition::get_by_post_id( $post_id );

		if ( is_wp_error( $state ) ) {
			return self::render_diagnostic( $state, $args );
		}

		// A trashed form keeps its meta rows until it is deleted for good, but a
		// trashed form is not a published form. This is checked explicitly
		// because the published row alone would still render.
		if ( 'trash' === (string) get_post_status( $post_id ) ) {
			return self::render_diagnostic(
				new \WP_Error(
					'form_trashed',
					__( 'That form is in the Trash, so it is not rendered.', 'agentshell-forms' )
				),
				$args
			);
		}

		$definition = self::select_published( $state );

		if ( is_wp_error( $definition ) ) {
			return self::render_diagnostic( $definition, $args );
		}

		// A published definition is the stored one, but it is not trusted: a row
		// edited by hand, written by an older build or corrupted by a failed
		// write must not be rendered into a page as a broken form. Validating
		// here is cheap and pure, and it means the renderer's contract holds
		// for every row in the table rather than for the rows this build wrote.
		$errors = Form_Schema::validate(
			$definition,
			array(
				'site_host'          => self::site_host(),
				'slug_taken'         => function () {
					return false;
				},
				'has_been_published' => true,
				'previous_slug'      => (string) $state['slug'],
			)
		);

		if ( ! empty( $errors ) ) {
			return self::render_diagnostic(
				new \WP_Error(
					'form_published_invalid',
					__( 'That form’s published definition is not valid, so it is not rendered.', 'agentshell-forms' ),
					array( 'errors' => $errors )
				),
				$args
			);
		}

		return self::render_definition( $definition, $args );
	}

	/**
	 * The published definition from a stored form state, or a refusal.
	 *
	 * This is the published-only rule, isolated into one function so it can be
	 * tested against a state where the working and published definitions
	 * disagree — which is the exact shape the rule exists to handle, and the
	 * one that a database test would spend most of its effort constructing.
	 *
	 * `$state['working']` is deliberately never read. Not "preferred less", not
	 * "merged as a fallback": unread. A form with no published definition has
	 * never been published, and renders nothing — including when a complete
	 * working definition exists, because an uncommitted edit is not a form.
	 *
	 * @param array $state A form state, as `Form_Definition::get_by_post_id()` returns it.
	 * @return array|\WP_Error
	 */
	public static function select_published( array $state ) {
		if ( ! isset( $state['published'] ) || ! is_array( $state['published'] ) ) {
			return new \WP_Error(
				'form_not_published',
				__( 'That form has no published version yet, so it is not rendered.', 'agentshell-forms' )
			);
		}

		return $state['published'];
	}

	/**
	 * Render a definition document.
	 *
	 * This is the pure half of the renderer: no database, no options, no
	 * request state beyond the instance counter.
	 *
	 * Arguments, all optional:
	 *
	 * | Key | Default | Meaning |
	 * |---|---|---|
	 * | `title` | `show` | `show`/`hide`: render the form's name as a heading |
	 * | `heading_level` | `2` | `h2`…`h6` |
	 * | `class` | `''` | Extra classes on the `<form>`, sanitised token by token |
	 * | `instance` | auto | Force the instance number (used by tests and previews) |
	 * | `context` | `'render'` | `shortcode`, `widget`, `api` or `render` |
	 * | `values` | `array()` | Submitted values to re-populate (Phase 3) |
	 * | `errors` | `array()` | `field key => message`, rendered as a summary and per field |
	 * | `summary` | `''` | Text for the error summary's heading |
	 * | `status` | `''` | Text for the status region (Phase 3's success message) |
	 *
	 * @param array $definition The definition document.
	 * @param array $args       Rendering arguments.
	 * @return string
	 */
	public static function render_definition( array $definition, array $args = array() ) {
		$definition = Form_Schema::normalise( $definition );

		if ( empty( $definition['fields'] ) || ! is_array( $definition['fields'] ) ) {
			return '';
		}

		self::enqueue_assets();

		$form_id = (string) ( $definition['form_id'] ?? '' );
		$version = (int) ( $definition['version'] ?? 0 );

		$instance = isset( $args['instance'] ) && is_numeric( $args['instance'] )
			? max( 1, (int) $args['instance'] )
			: ++self::$instances;

		$prefix = 'agentshell-form-' . ( '' !== $form_id ? $form_id : 'unknown' ) . '-' . $instance;

		$context = isset( $args['context'] ) ? (string) $args['context'] : 'render';

		$classes = array( 'agentshell-forms' );
		if ( '' !== $form_id ) {
			$classes[] = 'agentshell-forms--' . substr( $form_id, 0, 8 );
		}
		$classes[] = 'agentshell-forms--instance-' . $instance;
		foreach ( self::class_tokens( isset( $args['class'] ) ? $args['class'] : '' ) as $token ) {
			$classes[] = $token;
		}

		$errors = isset( $args['errors'] ) && is_array( $args['errors'] ) ? $args['errors'] : array();
		$values = isset( $args['values'] ) && is_array( $args['values'] ) ? $args['values'] : array();
		$status = isset( $args['status'] ) ? (string) $args['status'] : '';

		$show_title = self::to_bool( isset( $args['title'] ) ? $args['title'] : 'show', true );
		$heading    = self::heading_tag( isset( $args['heading_level'] ) ? $args['heading_level'] : 2 );

		// There is deliberately **no `action` attribute** in Phase 2. The
		// submission endpoint is Phase 3's, and pointing a form at a route that
		// does not exist yet would turn "submission is not implemented" into a
		// 404 the visitor sees. With no action the native POST goes to the page
		// itself and changes nothing, which is the truthful behaviour of a build
		// that does not process submissions. Phase 3 sets the action.
		//
		// `novalidate` is deliberately not emitted either: in Phase 2 the
		// browser's own constraint validation is the only validation a visitor
		// gets, and disabling it would leave a form with no feedback at all.
		$attrs = array(
			'class'                   => implode( ' ', array_map( 'sanitize_html_class', $classes ) ),
			'method'                  => 'post',
			'data-agentshell-form'    => '1',
			'data-form-id'            => $form_id,
			'data-form-version'       => (string) $version,
			'data-form-instance'      => (string) $instance,
			'data-form-context'       => $context,
			'data-challenge-endpoint' => self::challenge_url(),
		);

		$html  = '<form' . self::attrs( $attrs ) . '>';
		$html .= self::hidden_inputs( $instance, $definition );

		if ( $show_title ) {
			$title = isset( $definition['name'] ) ? (string) $definition['name'] : '';
			if ( '' !== $title ) {
				$html .= '<' . $heading . ' class="agentshell-forms__title" id="' . esc_attr( $prefix . '-title' ) . '">'
					. esc_html( $title )
					. '</' . $heading . '>';
			}
		}

		$description = isset( $definition['description'] ) ? (string) $definition['description'] : '';
		if ( '' !== $description ) {
			$html .= '<p class="agentshell-forms__description" id="' . esc_attr( $prefix . '-form-description' ) . '">'
				. esc_html( $description )
				. '</p>';
		}

		$html .= self::error_summary( $prefix, $definition, $errors, $args );

		$html .= '<div class="agentshell-forms__fields">';
		foreach ( $definition['fields'] as $index => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$html .= self::render_field( $field, $prefix, $values, $errors );
		}
		$html .= '</div>';

		$html .= self::honeypot( $prefix, $definition );

		$submit_label = isset( $definition['settings']['submit_label'] )
			? (string) $definition['settings']['submit_label']
			: __( 'Send', 'agentshell-forms' );

		$html .= '<div class="agentshell-forms__actions">'
			. '<button type="submit" class="agentshell-forms__submit">' . esc_html( $submit_label ) . '</button>'
			. '</div>';

		// The status region exists from Phase 2 so a success message has
		// somewhere to go that assistive technology already knows about; it is
		// empty until something has a status to announce.
		$html .= '<p class="agentshell-forms__status" role="status" aria-live="polite" data-agentshell-forms-status>'
			. ( '' !== $status ? esc_html( $status ) : '' )
			. '</p>';

		$html .= '</form>';

		return $html;
	}

	/**
	 * One field's markup.
	 *
	 * Exposed because a custom field type — or an integration — may want the
	 * wrapper without re-implementing it. The control itself is chosen by type
	 * through `agentshell_forms_render_field`; a type that has no renderer
	 * falls back to a text input, which is a usable control rather than a
	 * broken form, and an editor is told about it in the field's description.
	 *
	 * @param array  $field  The field.
	 * @param string $prefix Instance id prefix.
	 * @param array  $values Submitted values (Phase 3).
	 * @param array  $errors Field key => message.
	 * @return string
	 */
	public static function render_field( array $field, $prefix, array $values = array(), array $errors = array() ) {
		$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';
		$key  = isset( $field['key'] ) ? (string) $field['key'] : '';

		if ( '' === $key ) {
			return '';
		}

		$id          = $prefix . '-' . $key;
		$label       = isset( $field['label'] ) ? (string) $field['label'] : $key;
		$description = isset( $field['description'] ) ? (string) $field['description'] : '';
		$required    = ! empty( $field['required'] );
		$error       = isset( $errors[ $key ] ) ? (string) $errors[ $key ] : '';
		$hint        = self::hint_for( $field );

		// A hidden field is not a labelled control: it has no visible box, no
		// label and nothing to describe. Rendering it inside the field grid
		// would leave an empty row and a `<label for>` pointing at something
		// nobody can reach.
		if ( 'hidden' === $type ) {
			return self::render_hidden_field( $field, $id );
		}

		$group   = self::is_group( $field );
		$wrapper = 'agentshell-forms__field agentshell-forms__field--' . sanitize_html_class( $type );
		if ( '' !== $error ) {
			$wrapper .= ' agentshell-forms__field--invalid';
		}

		$described_by = array();
		if ( '' !== $description ) {
			$described_by[] = $id . '-description';
		}
		if ( '' !== $hint ) {
			$described_by[] = $id . '-hint';
		}
		if ( '' !== $error ) {
			$described_by[] = $id . '-error';
		}

		$html  = '<div class="' . esc_attr( $wrapper ) . '" data-field-key="' . esc_attr( $key ) . '" data-field-type="' . esc_attr( $type ) . '">';

		if ( $group ) {
			$html .= '<fieldset class="agentshell-forms__group"'
				. ( $described_by ? ' aria-describedby="' . esc_attr( implode( ' ', $described_by ) ) . '"' : '' )
				. '>';
			$html .= '<legend class="agentshell-forms__label" id="' . esc_attr( $id . '-legend' ) . '">'
				. esc_html( $label )
				. self::required_marker( $required )
				. '</legend>';
		} else {
			$html .= '<label class="agentshell-forms__label" for="' . esc_attr( $id ) . '">'
				. esc_html( $label )
				. self::required_marker( $required )
				. '</label>';
		}

		if ( '' !== $description ) {
			$html .= '<p class="agentshell-forms__description" id="' . esc_attr( $id . '-description' ) . '">'
				. esc_html( $description )
				. '</p>';
		}

		$html .= self::control( $field, $id, $values, $required, $described_by, '' !== $error );

		if ( '' !== $hint ) {
			$html .= '<p class="agentshell-forms__hint" id="' . esc_attr( $id . '-hint' ) . '">' . esc_html( $hint ) . '</p>';
		}

		if ( '' !== $error ) {
			$html .= '<p class="agentshell-forms__error" id="' . esc_attr( $id . '-error' ) . '">' . esc_html( $error ) . '</p>';
		}

		if ( $group ) {
			$html .= '</fieldset>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * The control for one field.
	 *
	 * Split out so a type can replace it without replacing the label, the
	 * description, the hint, the required marker or the error — the parts that
	 * must not drift between types.
	 *
	 * @param array  $field        The field.
	 * @param string $id           The control's id (and id prefix).
	 * @param array  $values       Submitted values.
	 * @param bool   $required     Whether the field is required.
	 * @param array  $described_by Ids for `aria-describedby`.
	 * @param bool   $invalid      Whether the field carries an error.
	 * @return string
	 */
	private static function control( array $field, $id, array $values, $required, array $described_by, $invalid = false ) {
		$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';

		/**
		 * Filter one field's control markup.
		 *
		 * A field type registered through `agentshell_form_field_types` can
		 * supply its control without the renderer being edited. Returning null
		 * keeps the built-in behaviour; returning a string replaces the control
		 * for that field.
		 *
		 * The filter receives the field, the id, the wrapper's own context and
		 * the already-escaped attribute fragments. It must escape whatever it
		 * emits: the caller concatenates the result into the page unmodified.
		 *
		 * @param string|null $markup       Control markup, or null for built-in.
		 * @param array       $field        The field.
		 * @param string      $id           Control id.
		 * @param array       $values       Submitted values.
		 * @param bool        $required     Whether the field is required.
		 * @param array       $described_by Ids for `aria-describedby`.
		 */
		$custom = apply_filters( 'agentshell_forms_render_field', null, $field, $id, $values, $required, $described_by );

		if ( is_string( $custom ) && '' !== $custom ) {
			return $custom;
		}

		$name  = 'fields[' . ( isset( $field['key'] ) ? (string) $field['key'] : '' ) . ']';
		$value = self::value_for( $field, $values );

		$common = array(
			'class'            => 'agentshell-forms__control',
			'id'               => $id,
			'name'             => $name,
			'aria-describedby' => $described_by ? implode( ' ', $described_by ) : null,
			// WCAG 3.3.1: a field the server rejected is identified as invalid to
			// assistive technology, not only by a red border. Phase 3 produces
			// the errors; Phase 2 already renders them when a caller supplies
			// them, and the attribute must not be the thing Phase 3 forgets.
			'aria-invalid'     => $invalid ? 'true' : null,
		);

		$settings = isset( $field['settings'] ) && is_array( $field['settings'] ) ? $field['settings'] : array();

		switch ( $type ) {
			case 'textarea':
				$attrs = array_merge(
					$common,
					array(
						'rows'      => (string) max( 1, (int) ( $settings['rows'] ?? 6 ) ),
						'maxlength' => isset( $settings['max_length'] ) ? (string) (int) $settings['max_length'] : null,
						'minlength' => isset( $settings['min_length'] ) && (int) $settings['min_length'] > 0
							? (string) (int) $settings['min_length']
							: null,
						'required'  => $required ? '' : null,
					)
				);
				return '<textarea' . self::attrs( $attrs ) . '>' . esc_html( $value ) . '</textarea>';

			case 'email':
				$attrs = array_merge(
					$common,
					array(
						'type'         => 'email',
						'value'        => $value,
						'maxlength'    => isset( $settings['max_length'] ) ? (string) (int) $settings['max_length'] : null,
						'autocomplete' => self::autocomplete( $settings ),
						'required'     => $required ? '' : null,
					)
				);
				return '<input' . self::attrs( $attrs ) . '>';

			case 'tel':
				$attrs = array_merge(
					$common,
					array(
						'type'         => 'tel',
						'value'        => $value,
						'maxlength'    => isset( $settings['max_length'] ) ? (string) (int) $settings['max_length'] : null,
						'autocomplete' => self::autocomplete( $settings ),
						'required'     => $required ? '' : null,
					)
				);
				return '<input' . self::attrs( $attrs ) . '>';

			case 'number':
				$decimals = isset( $settings['decimals'] ) ? max( 0, min( 6, (int) $settings['decimals'] ) ) : 0;
				$attrs    = array_merge(
					$common,
					array(
						'type'     => 'number',
						'value'    => $value,
						'min'      => isset( $settings['min'] ) && null !== $settings['min'] ? (string) $settings['min'] : null,
						'max'      => isset( $settings['max'] ) && null !== $settings['max'] ? (string) $settings['max'] : null,
						'step'     => 0 === $decimals ? '1' : '0.' . str_repeat( '0', $decimals - 1 ) . '1',
						'required' => $required ? '' : null,
					)
				);
				return '<input' . self::attrs( $attrs ) . '>';

			case 'select':
				return self::render_select( $field, $common, $value, $required );

			case 'radio':
				return self::render_radios( $field, $id, $name, $value, $required, $described_by, $invalid );

			case 'checkbox':
				return self::render_checkboxes( $field, $id, $value, $required, $described_by, $invalid );

			case 'consent':
				return self::render_consent( $field, $common, $value, $required, $described_by );

			case 'text':
			default:
				$attrs = array_merge(
					$common,
					array(
						'type'         => 'text',
						'value'        => $value,
						'maxlength'    => isset( $settings['max_length'] ) ? (string) (int) $settings['max_length'] : null,
						'minlength'    => isset( $settings['min_length'] ) && (int) $settings['min_length'] > 0
							? (string) (int) $settings['min_length']
							: null,
						'autocomplete' => self::autocomplete( $settings ),
						'placeholder'  => isset( $field['placeholder'] ) && '' !== (string) $field['placeholder']
							? (string) $field['placeholder']
							: null,
						'required'     => $required ? '' : null,
					)
				);
				return '<input' . self::attrs( $attrs ) . '>';
		}
	}

	/**
	 * A `<select>`.
	 *
	 * @param array  $field        The field.
	 * @param array  $common       Attributes shared by controls.
	 * @param string $value        Current value.
	 * @param bool   $required     Whether a choice is required.
	 * @return string
	 */
	private static function render_select( array $field, array $common, $value, $required ) {
		$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();

		$attrs = array_merge(
			$common,
			array(
				'class'    => 'agentshell-forms__control agentshell-forms__select',
				'required' => $required ? '' : null,
			)
		);

		$html = '<select' . self::attrs( $attrs ) . '>';

		// The empty option is what makes a required dropdown refuse the default
		// state rather than silently submitting the first real option. It is
		// also a real choice when the field is optional.
		$html .= '<option value="">'
			. esc_html( $required ? __( 'Please choose…', 'agentshell-forms' ) : __( '—', 'agentshell-forms' ) )
			. '</option>';

		foreach ( $options as $option ) {
			if ( ! is_array( $option ) || ! isset( $option['value'] ) ) {
				continue;
			}
			$option_value = (string) $option['value'];
			$option_label = isset( $option['label'] ) ? (string) $option['label'] : $option_value;

			$html .= '<option value="' . esc_attr( $option_value ) . '"'
				. selected( $value, $option_value, false )
				. '>' . esc_html( $option_label ) . '</option>';
		}

		$html .= '</select>';

		return $html;
	}

	/**
	 * A radio group: a fieldset, a legend, and one labelled radio per option.
	 *
	 * @param array  $field        The field.
	 * @param string $id           Id prefix.
	 * @param string $name         Shared input name.
	 * @param string $value        Current value.
	 * @param bool   $required     Whether a choice is required.
	 * @param array  $described_by Ids for `aria-describedby`.
	 * @param bool   $invalid      Whether the group carries an error.
	 * @return string
	 */
	private static function render_radios( array $field, $id, $name, $value, $required, array $described_by, $invalid = false ) {
		$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();

		$html = '<div class="agentshell-forms__options agentshell-forms__options--radio">';

		foreach ( $options as $index => $option ) {
			if ( ! is_array( $option ) || ! isset( $option['value'] ) ) {
				continue;
			}
			$option_value = (string) $option['value'];
			$option_label = isset( $option['label'] ) ? (string) $option['label'] : $option_value;
			$option_id    = $id . '-' . $index;

			$attrs = array(
				'class'        => 'agentshell-forms__radio',
				'type'         => 'radio',
				'id'           => $option_id,
				'name'         => $name,
				'value'        => $option_value,
				'checked'      => ( $value === $option_value && '' !== $value ) ? '' : null,
				'required'     => $required ? '' : null,
				// `aria-invalid` belongs on the widget, not on the `<fieldset>`:
				// ARIA defines it for roles that support it, and a fieldset is
				// not one of them.
				'aria-invalid' => $invalid ? 'true' : null,
			);

			$html .= '<div class="agentshell-forms__option">';
			$html .= '<input' . self::attrs( $attrs ) . '>';
			$html .= '<label class="agentshell-forms__option-label" for="' . esc_attr( $option_id ) . '">'
				. esc_html( $option_label ) . '</label>';
			$html .= '</div>';
		}

		$html .= '</div>';

		// The group's description ids are on the fieldset, where the legend is;
		// repeating them on every input would make a screen reader announce the
		// same sentence once per option.
		unset( $described_by );

		return $html;
	}

	/**
	 * A checkbox group: a fieldset, a legend, and one labelled checkbox each.
	 *
	 * @param array  $field        The field.
	 * @param string $id           Id prefix.
	 * @param string $value        Current value (an array for this type).
	 * @param bool   $required     Whether at least one choice is required.
	 * @param array  $described_by Ids for `aria-describedby`.
	 * @param bool   $invalid      Whether the group carries an error.
	 * @return string
	 */
	private static function render_checkboxes( array $field, $id, $value, $required, array $described_by, $invalid = false ) {
		$options  = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
		$selected = is_array( $value ) ? array_map( 'strval', $value ) : array();
		$key      = isset( $field['key'] ) ? (string) $field['key'] : '';

		$html = '<div class="agentshell-forms__options agentshell-forms__options--checkbox">';

		foreach ( $options as $index => $option ) {
			if ( ! is_array( $option ) || ! isset( $option['value'] ) ) {
				continue;
			}
			$option_value = (string) $option['value'];
			$option_label = isset( $option['label'] ) ? (string) $option['label'] : $option_value;
			$option_id    = $id . '-' . $index;

			$attrs = array(
				'class'        => 'agentshell-forms__checkbox',
				'type'         => 'checkbox',
				'id'           => $option_id,
				// The `[]` is what makes a native form POST deliver an array;
				// without it a browser sends only the last checked box.
				'name'         => 'fields[' . $key . '][]',
				'value'        => $option_value,
				'checked'      => in_array( $option_value, $selected, true ) ? '' : null,
				'aria-invalid' => $invalid ? 'true' : null,
			);

			$html .= '<div class="agentshell-forms__option">';
			$html .= '<input' . self::attrs( $attrs ) . '>';
			$html .= '<label class="agentshell-forms__option-label" for="' . esc_attr( $option_id ) . '">'
				. esc_html( $option_label ) . '</label>';
			$html .= '</div>';
		}

		$html .= '</div>';

		unset( $described_by, $required );

		return $html;
	}

	/**
	 * A single consent checkbox.
	 *
	 * @param array  $field        The field.
	 * @param array  $common       Shared attributes.
	 * @param string $value        Current value.
	 * @param bool   $required     Whether consent is required.
	 * @param array  $described_by Ids for `aria-describedby`.
	 * @return string
	 */
	private static function render_consent( array $field, array $common, $value, $required, array $described_by ) {
		// The consent control's label is its own text, so the wrapper's label
		// would be a second, redundant one. The wrapper label is kept (it names
		// the field) and the control is associated with it by id; a screen
		// reader announces the wrapper label and then the control's state.
		$attrs = array_merge(
			$common,
			array(
				'class'   => 'agentshell-forms__control agentshell-forms__consent',
				'type'    => 'checkbox',
				'value'   => '1',
				'checked' => ( '1' === (string) $value || true === $value ) ? '' : null,
				'required' => $required ? '' : null,
			)
		);

		$html = '<input' . self::attrs( $attrs ) . '>';

		$settings = isset( $field['settings'] ) && is_array( $field['settings'] ) ? $field['settings'] : array();
		$privacy  = isset( $settings['privacy_url'] ) ? (string) $settings['privacy_url'] : '';

		if ( '' !== $privacy ) {
			$id = isset( $common['id'] ) ? (string) $common['id'] : '';

			$html .= '<p class="agentshell-forms__hint">'
				. '<a href="' . esc_url( $privacy ) . '" rel="noopener">'
				. esc_html__( 'Read the privacy notice', 'agentshell-forms' )
				. '</a></p>';

			unset( $id );
		}

		unset( $described_by );

		return $html;
	}

	/**
	 * A hidden field's input.
	 *
	 * The value comes from the definition and never from the request (§5.4).
	 *
	 * @param array  $field The field.
	 * @param string $id    The control id.
	 * @return string
	 */
	private static function render_hidden_field( array $field, $id ) {
		$key      = isset( $field['key'] ) ? (string) $field['key'] : '';
		$settings = isset( $field['settings'] ) && is_array( $field['settings'] ) ? $field['settings'] : array();
		$value    = isset( $settings['value'] ) ? (string) $settings['value'] : '';

		return '<input type="hidden" id="' . esc_attr( $id ) . '" name="fields[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" data-field-key="' . esc_attr( $key ) . '" data-field-type="hidden">';
	}

	/**
	 * The honeypot field, when the form asks for one.
	 *
	 * It must be invisible to a person, unreachable by keyboard and hidden from
	 * assistive technology — it exists to be filled by a bot and empty for
	 * everybody else. It is rendered inside an `aria-hidden` wrapper with a
	 * negative `tabindex`, and CSS moves it off screen rather than
	 * `display:none`, because some bots skip `display:none`.
	 *
	 * @param string $prefix     Instance prefix.
	 * @param array  $definition The definition.
	 * @return string
	 */
	private static function honeypot( $prefix, array $definition ) {
		$settings = isset( $definition['settings']['spam'] ) && is_array( $definition['settings']['spam'] )
			? $definition['settings']['spam']
			: array();

		if ( empty( $settings['honeypot'] ) ) {
			return '';
		}

		$id = $prefix . '-hp';

		return '<div class="agentshell-forms__hp" aria-hidden="true">'
			. '<label for="' . esc_attr( $id ) . '">' . esc_html__( 'Leave this field empty', 'agentshell-forms' ) . '</label>'
			. '<input type="text" id="' . esc_attr( $id ) . '" name="agentshell_forms_hp" value="" tabindex="-1" autocomplete="off">'
			. '</div>';
	}

	/**
	 * The hidden inputs the protocol needs.
	 *
	 * The challenge input is **empty on purpose**. A single-use token cannot be
	 * rendered into a cacheable page: the first visitor would consume it, and
	 * every later visitor served the same page would present a token that is
	 * already claimed. The browser provisions one per visitor from
	 * `Form_Challenge_Endpoint`; until then the field is empty.
	 *
	 * @param int   $instance   The instance number on this page.
	 * @param array $definition The definition.
	 * @return string
	 */
	private static function hidden_inputs( $instance, array $definition ) {
		$form_id = (string) ( $definition['form_id'] ?? '' );

		return '<input type="hidden" name="agentshell_form_id" value="' . esc_attr( $form_id ) . '">'
			. '<input type="hidden" name="agentshell_form_challenge" value="" data-agentshell-forms-challenge>'
			. '<input type="hidden" name="agentshell_form_instance" value="' . esc_attr( (string) (int) $instance ) . '">';
	}

	/**
	 * The error summary.
	 *
	 * Rendered empty and hidden in Phase 2: there is no submission result yet.
	 * It exists now because Phase 3 must be able to fill it without changing
	 * the markup a site's CSS targets, and because the acceptance criteria
	 * name it. A caller that does supply `errors` gets a real summary with one
	 * link per field, focused on demand by the caller.
	 *
	 * @param string $prefix     Instance prefix.
	 * @param array  $definition The definition.
	 * @param array  $errors     Field key => message.
	 * @param array  $args       Rendering arguments.
	 * @return string
	 */
	private static function error_summary( $prefix, array $definition, array $errors, array $args ) {
		if ( empty( $errors ) ) {
			return '<div class="agentshell-forms__summary" id="' . esc_attr( $prefix . '-summary' ) . '" tabindex="-1" role="alert" hidden></div>';
		}

		$by_key = array();
		foreach ( $definition['fields'] as $field ) {
			if ( is_array( $field ) && isset( $field['key'] ) ) {
				$by_key[ (string) $field['key'] ] = $field;
			}
		}

		$heading = isset( $args['summary'] ) && '' !== (string) $args['summary']
			? (string) $args['summary']
			: __( 'There is a problem', 'agentshell-forms' );

		$html = '<div class="agentshell-forms__summary agentshell-forms__summary--visible" id="' . esc_attr( $prefix . '-summary' ) . '" tabindex="-1" role="alert">'
			. '<p class="agentshell-forms__summary-title">' . esc_html( $heading ) . '</p>'
			. '<ul class="agentshell-forms__summary-list">';

		foreach ( $errors as $key => $message ) {
			if ( ! isset( $by_key[ (string) $key ] ) ) {
				continue;
			}
			$field = $by_key[ (string) $key ];
			$label = isset( $field['label'] ) && '' !== (string) $field['label']
				? (string) $field['label']
				: (string) $key;

			$html .= '<li><a href="#' . esc_attr( $prefix . '-' . $key ) . '">' . esc_html( $label ) . '</a>: '
				. esc_html( (string) $message ) . '</li>';
		}

		$html .= '</ul></div>';

		return $html;
	}

	/**
	 * The hint text for a field, derived from its declared settings.
	 *
	 * A hint states the rule in words next to the control, so a visitor knows
	 * what is wanted before they type — and so the rule is not carried by a
	 * colour or by a browser bubble alone (WCAG 3.3.2, 3.3.1).
	 *
	 * @param array $field The field.
	 * @return string
	 */
	private static function hint_for( array $field ) {
		$settings = isset( $field['settings'] ) && is_array( $field['settings'] ) ? $field['settings'] : array();
		$type     = isset( $field['type'] ) ? (string) $field['type'] : 'text';
		$hints    = array();

		if ( ! empty( $field['required'] ) ) {
			$hints[] = __( 'Required.', 'agentshell-forms' );
		}

		switch ( $type ) {
			case 'text':
			case 'textarea':
			case 'email':
			case 'tel':
				if ( isset( $settings['max_length'] ) && (int) $settings['max_length'] > 0 ) {
					$hints[] = sprintf(
						/* translators: %d: maximum number of characters */
						__( 'Up to %d characters.', 'agentshell-forms' ),
						(int) $settings['max_length']
					);
				}
				if ( isset( $settings['min_length'] ) && (int) $settings['min_length'] > 0 ) {
					$hints[] = sprintf(
						/* translators: %d: minimum number of characters */
						__( 'At least %d characters.', 'agentshell-forms' ),
						(int) $settings['min_length']
					);
				}
				break;

			case 'number':
				$min = isset( $settings['min'] ) && null !== $settings['min'] && is_numeric( $settings['min'] ) ? (string) $settings['min'] : '';
				$max = isset( $settings['max'] ) && null !== $settings['max'] && is_numeric( $settings['max'] ) ? (string) $settings['max'] : '';
				if ( '' !== $min && '' !== $max ) {
					$hints[] = sprintf(
						/* translators: 1: minimum, 2: maximum */
						__( 'A number from %1$s to %2$s.', 'agentshell-forms' ),
						$min,
						$max
					);
				} elseif ( '' !== $min ) {
					$hints[] = sprintf(
						/* translators: %s: minimum */
						__( 'A number of at least %s.', 'agentshell-forms' ),
						$min
					);
				} elseif ( '' !== $max ) {
					$hints[] = sprintf(
						/* translators: %s: maximum */
						__( 'A number no greater than %s.', 'agentshell-forms' ),
						$max
					);
				}
				break;

			case 'checkbox':
			case 'radio':
				if ( ! empty( $field['required'] ) ) {
					$hints[] = __( 'Choose one of the options.', 'agentshell-forms' );
				}
				break;
		}

		return implode( ' ', array_unique( array_filter( $hints ) ) );
	}

	/**
	 * The current value for a field.
	 *
	 * Phase 3 re-renders a rejected submission with what the visitor typed, so
	 * the renderer takes values as an argument rather than reading a request.
	 * A hidden field's value is never taken from the values array: its value is
	 * part of the definition (§5.4).
	 *
	 * @param array $field  The field.
	 * @param array $values Submitted values.
	 * @return mixed
	 */
	private static function value_for( array $field, array $values ) {
		$key  = isset( $field['key'] ) ? (string) $field['key'] : '';
		$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';

		if ( 'hidden' === $type ) {
			return '';
		}

		if ( ! array_key_exists( $key, $values ) ) {
			return self::is_multiple( $field ) ? array() : '';
		}

		$value = $values[ $key ];

		if ( self::is_multiple( $field ) ) {
			return is_array( $value ) ? $value : array();
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Whether a field's type declares multiple values.
	 *
	 * Read from the registry rather than from the type name, so a type added by
	 * a filter is treated correctly without the renderer being edited.
	 *
	 * @param array $field The field.
	 * @return bool
	 */
	private static function is_multiple( array $field ) {
		$type = isset( $field['type'] ) ? (string) $field['type'] : '';
		$decl = Form_Field_Types::get( $type );

		return is_array( $decl ) && ! empty( $decl['multiple'] );
	}

	/**
	 * Whether a field's type is rendered as a group.
	 *
	 * @param array $field The field.
	 * @return bool
	 */
	private static function is_group( array $field ) {
		$type = isset( $field['type'] ) ? (string) $field['type'] : '';

		return in_array( $type, array( 'radio', 'checkbox' ), true );
	}

	/**
	 * The `(required)` marker.
	 *
	 * Text, not colour and not an asterisk alone: an asterisk is not a label
	 * anybody can read out, and colour is not information (WCAG 1.3.1, 1.4.1,
	 * 3.3.2). The `required` attribute is carried by the control as well; this
	 * is the human-readable half.
	 *
	 * @param bool $required Whether the field is required.
	 * @return string
	 */
	private static function required_marker( $required ) {
		if ( ! $required ) {
			return '';
		}

		return ' <span class="agentshell-forms__required">' . esc_html__( '(required)', 'agentshell-forms' ) . '</span>';
	}

	/**
	 * A safe `autocomplete` token.
	 *
	 * @param array $settings The field's settings.
	 * @return string|null
	 */
	private static function autocomplete( array $settings ) {
		$token = isset( $settings['autocomplete'] ) ? (string) $settings['autocomplete'] : '';

		if ( '' === $token || 'off' === $token ) {
			return null;
		}

		// The validator already restricts this to a declared token, but the
		// renderer does not rely on another layer having run.
		return preg_match( '/^[a-z-]{1,32}$/', $token ) ? $token : null;
	}

	/**
	 * Resolve a reference to a form post.
	 *
	 * @param string|int $reference Internal id, slug or post id.
	 * @param array      $args      Arguments (unused today; kept for symmetry).
	 * @return array{post_id:int,form_id:string}|\\WP_Error
	 */
	private static function resolve( $reference, array $args = array() ) {
		unset( $args );

		$reference = is_scalar( $reference ) ? trim( (string) $reference ) : '';

		if ( '' === $reference ) {
			return new \WP_Error( 'form_reference_missing', __( 'No form was named.', 'agentshell-forms' ) );
		}

		if ( preg_match( Form_Schema::FORM_ID_PATTERN, $reference ) ) {
			$post_id = Form_Definition::resolve_post_id( $reference );
			if ( $post_id > 0 ) {
				return array( 'post_id' => $post_id, 'form_id' => $reference );
			}

			return new \WP_Error(
				'form_not_found',
				/* translators: %s: form identifier */
				sprintf( __( 'No form has the identifier “%s”.', 'agentshell-forms' ), $reference )
			);
		}

		if ( ctype_digit( $reference ) ) {
			$post_id = (int) $reference;
			if ( $post_id > 0 && Form_Post_Types::FORM === get_post_type( $post_id ) ) {
				return array( 'post_id' => $post_id, 'form_id' => '' );
			}

			return new \WP_Error(
				'form_not_found',
				/* translators: %s: post id */
				sprintf( __( 'No form has the post id %s.', 'agentshell-forms' ), $reference )
			);
		}

		$ids = Form_Definition::find_post_ids_by_meta( Form_Post_Types::META_SLUG, $reference );

		if ( 1 === count( $ids ) ) {
			return array( 'post_id' => (int) $ids[0], 'form_id' => '' );
		}

		if ( count( $ids ) > 1 ) {
			return new \WP_Error(
				'form_slug_not_unique',
				/* translators: %s: form slug */
				sprintf( __( 'More than one form claims the slug “%s”, so none was rendered.', 'agentshell-forms' ), $reference )
			);
		}

		return new \WP_Error(
			'form_not_found',
			/* translators: %s: form slug */
			sprintf( __( 'No form has the slug “%s”.', 'agentshell-forms' ), $reference )
		);
	}

	/**
	 * The visitor-facing message for a refusal, or '' for a visitor.
	 *
	 * An actor who can manage forms is told what is wrong; everybody else gets
	 * an empty string, so a broken shortcode is not a public error page and a
	 * missing form is not an information leak. When a diagnostic is rendered
	 * the request is also marked uncacheable, because a page cache that stores
	 * an editor's diagnostic would serve it to visitors.
	 *
	 * Public because the shortcode and the widget can fail before a form is
	 * resolved — an attribute pair that names two different forms, for
	 * instance — and they must fail the same way.
	 *
	 * @param \WP_Error $error The refusal.
	 * @param array     $args  Rendering arguments.
	 * @return string
	 */
	public static function render_diagnostic( \WP_Error $error, array $args = array() ) {
		unset( $args );

		if ( ! self::viewer_can_manage() ) {
			return '';
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		return '<p class="agentshell-forms__diagnostic" data-agentshell-forms-diagnostic="'
			. esc_attr( $error->get_error_code() ) . '">'
			. esc_html( $error->get_error_message() )
			. '</p>';
	}

	/**
	 * Whether the current viewer may see a rendering diagnostic.
	 *
	 * @return bool
	 */
	private static function viewer_can_manage() {
		return function_exists( 'current_user_can' ) && current_user_can( Form_Capabilities::CAP_FORMS );
	}

	/**
	 * The form's host, for validation context.
	 *
	 * @return string
	 */
	private static function site_host() {
		$url = function_exists( 'home_url' ) ? home_url( '/' ) : '';
		$host = is_string( $url ) ? wp_parse_url( $url, PHP_URL_HOST ) : '';

		return is_string( $host ) ? $host : '';
	}

	/**
	 * The REST URL the browser fetches a challenge from.
	 *
	 * Empty when the site has no REST API, which the provisioning script treats
	 * as "this form cannot be prepared" and announces, rather than failing
	 * silently.
	 *
	 * @return string
	 */
	private static function challenge_url() {
		return esc_url_raw( rest_url( Form_Challenge_Endpoint::REST_NAMESPACE . Form_Challenge_Endpoint::ROUTE ) );
	}

	/**
	 * A heading tag, from the allowed set.
	 *
	 * @param mixed $level Requested level.
	 * @return string
	 */
	private static function heading_tag( $level ) {
		$level = (int) $level;

		return isset( self::$heading_tags[ $level - 2 ] ) ? self::$heading_tags[ $level - 2 ] : 'h2';
	}

	/**
	 * Split a class argument into safe tokens.
	 *
	 * @param mixed $value The argument.
	 * @return array<int,string>
	 */
	private static function class_tokens( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return array();
		}

		$tokens = array();
		foreach ( preg_split( '/\s+/', $value ) as $token ) {
			$token = sanitize_html_class( $token );
			if ( '' !== $token ) {
				$tokens[] = $token;
			}
		}

		return array_values( array_unique( $tokens ) );
	}

	/**
	 * Interpret a boolean-ish argument.
	 *
	 * @param mixed $value   The value.
	 * @param bool  $default Default when the value is not recognisable.
	 * @return bool
	 */
	private static function to_bool( $value, $default ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			if ( in_array( $value, array( 'show', 'true', '1', 'yes', 'on' ), true ) ) {
				return true;
			}
			if ( in_array( $value, array( 'hide', 'false', '0', 'no', 'off', 'none' ), true ) ) {
				return false;
			}
		}

		return $default;
	}

	/**
	 * Attributes that are meaningful without a value.
	 *
	 * HTML calls these boolean attributes: their presence is the value, and
	 * `required=""` is the same as `required`. Nothing else may be emitted bare —
	 * `value` rendered as a bare attribute is not an empty string, it is a
	 * missing value, and a control that should have been empty would arrive
	 * with the browser's default instead.
	 *
	 * @var array<int,string>
	 */
	private static $boolean_attributes = array(
		'required',
		'checked',
		'selected',
		'disabled',
		'readonly',
		'multiple',
		'autofocus',
		'novalidate',
		'hidden',
	);

	/**
	 * Render an attribute map.
	 *
	 * A `null` value omits the attribute; `''` emits a bare attribute for a
	 * boolean attribute and `name=""` for everything else. Every name and value
	 * is escaped here rather than at each call site, so there is one place where
	 * an attribute can go wrong.
	 *
	 * @param array $attrs Attributes.
	 * @return string
	 */
	private static function attrs( array $attrs ) {
		$html = '';

		foreach ( $attrs as $name => $value ) {
			if ( null === $value ) {
				continue;
			}

			$name = (string) $name;
			if ( ! preg_match( '/^[a-zA-Z][a-zA-Z0-9_:.-]*$/', $name ) ) {
				continue;
			}

			if ( '' === $value && in_array( strtolower( $name ), self::$boolean_attributes, true ) ) {
				$html .= ' ' . $name;
				continue;
			}

			$html .= ' ' . $name . '="' . esc_attr( (string) $value ) . '"';
		}

		return $html;
	}

	/**
	 * Enqueue the plugin's stylesheet and provisioning script, once.
	 *
	 * Enqueued at render time, so a page with no form downloads nothing.
	 * WordPress prints an asset enqueued after `wp_head` in the footer, which is
	 * why a form in a widget area still gets its stylesheet and its script.
	 *
	 * The script is what makes the cached page work: it fills the empty
	 * challenge input per visitor. A page whose script never runs is a page
	 * where the form cannot be submitted — which is a visible failure, not a
	 * silent one, because the script announces it in the form's status region.
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		if ( self::$styles_enqueued ) {
			return;
		}

		self::$styles_enqueued = true;

		if ( ! function_exists( 'wp_enqueue_style' ) ) {
			return;
		}

		wp_enqueue_style( 'agentshell-forms', AGENTSHELL_FORMS_PLUGIN_URL . 'assets/forms.css', array(), AGENTSHELL_FORMS_VERSION );

		if ( ! function_exists( 'wp_enqueue_script' ) ) {
			return;
		}

		wp_enqueue_script(
			'agentshell-forms',
			AGENTSHELL_FORMS_PLUGIN_URL . 'assets/forms.js',
			array(),
			AGENTSHELL_FORMS_VERSION,
			true
		);

		if ( function_exists( 'wp_localize_script' ) ) {
			wp_localize_script(
				'agentshell-forms',
				'agentshellFormsL10n',
				array(
					'error'       => __( 'This form could not be prepared. Please reload the page and try again.', 'agentshell-forms' ),
					'unsupported' => __( 'This form cannot be started in this browser.', 'agentshell-forms' ),
				)
			);
		}
	}
}
