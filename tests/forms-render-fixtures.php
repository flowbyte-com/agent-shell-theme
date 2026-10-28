<?php
/**
 * Shared fixtures for the renderer suites.
 *
 * The renderer has a pure half (`render_definition()`) that needs no database,
 * and that is where the markup, the escaping and the accessibility contract
 * live. This file gives those tests the handful of WordPress functions the pure
 * half reads, and one definition containing all ten field types, so a renderer
 * change that breaks a type is caught without a test bed.
 *
 * The same fixture feeds the browser harness: `tests/forms-render-browser-fixture.php`
 * renders it into a page so Chrome can measure what a visitor would get.
 *
 * Nothing here is a WordPress stub *implementation* — `bootstrap.php` already
 * owns the shared ones. These are the few the renderer needs that no existing
 * suite had asked for, each defined only when WordPress has not already
 * provided it.
 *
 * @package AgentShell_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * WordPress's "escape for storage" variant. Real WordPress strips dangerous
	 * protocols here; the unit suite does not depend on that behaviour (the live
	 * suite proves it), only on a value being returned.
	 *
	 * @param string $url The URL.
	 * @return string
	 */
	function esc_url_raw( $url ) {
		return (string) $url;
	}
}

if ( ! function_exists( 'sanitize_html_class' ) ) {
	/**
	 * WordPress's class sanitiser: keep `A-Za-z0-9_-`, drop everything else.
	 *
	 * @param string $class   The class.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	function sanitize_html_class( $class, $fallback = '' ) {
		$sanitized = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class );

		return '' === $sanitized ? (string) $fallback : $sanitized;
	}
}

if ( ! function_exists( 'selected' ) ) {
	/**
	 * WordPress's `selected()` helper.
	 *
	 * @param mixed $selected  The stored value.
	 * @param mixed $current   The value being rendered.
	 * @param bool  $display   Whether to echo.
	 * @return string
	 */
	function selected( $selected, $current = true, $display = true ) {
		$result = ( (string) $selected === (string) $current || ( $selected && $current ) )
			? " selected='selected'"
			: '';

		if ( $display ) {
			echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		return $result;
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	/**
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return void
	 */
	function esc_html_e( $text, $domain = null ) {
		unset( $domain );
		echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * @param string $url       URL.
	 * @param int    $component Component.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * @param string $path Path.
	 * @return string
	 */
	function home_url( $path = '' ) {
		return 'https://example.test' . ( '' !== $path ? '/' . ltrim( (string) $path, '/' ) : '' );
	}
}

if ( ! defined( 'AGENTSHELL_FORMS_VERSION' ) ) {
	define( 'AGENTSHELL_FORMS_VERSION', '0.1.0' );
}

if ( ! defined( 'AGENTSHELL_FORMS_PLUGIN_DIR' ) ) {
	define( 'AGENTSHELL_FORMS_PLUGIN_DIR', dirname( __DIR__ ) . '/agentshell-forms/' );
}

if ( ! defined( 'AGENTSHELL_FORMS_PLUGIN_URL' ) ) {
	define( 'AGENTSHELL_FORMS_PLUGIN_URL', 'https://example.test/wp-content/plugins/agentshell-forms/' );
}

if ( ! defined( 'AGENTSHELL_FORMS_SCHEMA_VERSION' ) ) {
	define( 'AGENTSHELL_FORMS_SCHEMA_VERSION', 1 );
}

$agentshell_forms_fixture_dir = dirname( __DIR__ ) . '/agentshell-forms/includes/';

require_once $agentshell_forms_fixture_dir . 'class-form-field-types.php';
require_once $agentshell_forms_fixture_dir . 'class-form-schema.php';
require_once $agentshell_forms_fixture_dir . 'class-form-capabilities.php';
// Phase 3: the renderer asks the Turnstile client whether a form needs the
// widget, and emits the submission route as the form's action.
require_once $agentshell_forms_fixture_dir . 'class-form-rejections.php';
require_once $agentshell_forms_fixture_dir . 'class-turnstile.php';
require_once $agentshell_forms_fixture_dir . 'class-form-challenge-endpoint.php';
require_once $agentshell_forms_fixture_dir . 'class-submission-handler.php';
require_once $agentshell_forms_fixture_dir . 'class-form-renderer.php';

unset( $agentshell_forms_fixture_dir );

if ( ! function_exists( 'agentshell_forms_fixture_definition' ) ) {
	/**
	 * A definition containing every MVP field type.
	 *
	 * The option values and field keys are the shapes the validator accepts, so
	 * this document is also a worked example of the schema.
	 *
	 * @return array
	 */
	function agentshell_forms_fixture_definition() {
		return array(
			'schema_version' => 1,
			'form_id'        => '7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a',
			'slug'           => 'contact',
			'name'           => 'Contact us',
			'description'    => 'Tell us what you need and we will reply within two working days.',
			'version'        => 3,
			'status'         => 'published',
			'fields'         => array(
				array(
					'key'         => 'full_name',
					'type'        => 'text',
					'label'       => 'Your name',
					'description' => 'As you would like us to address you.',
					'placeholder' => 'Ada Lovelace',
					'required'    => true,
					'settings'    => array(
						'max_length'   => 80,
						'min_length'   => 2,
						'autocomplete' => 'name',
					),
				),
				array(
					'key'      => 'email',
					'type'     => 'email',
					'label'    => 'Email address',
					'required' => true,
					'settings' => array(
						'max_length'   => 254,
						'autocomplete' => 'email',
					),
				),
				array(
					'key'      => 'phone',
					'type'     => 'tel',
					'label'    => 'Phone',
					'required' => false,
					'settings' => array(
						'max_length'   => 32,
						'autocomplete' => 'tel',
					),
				),
				array(
					'key'      => 'budget',
					'type'     => 'number',
					'label'    => 'Budget (thousands)',
					'required' => false,
					'settings' => array(
						'min'      => 1,
						'max'      => 250,
						'decimals' => 1,
					),
				),
				array(
					'key'         => 'message',
					'type'        => 'textarea',
					'label'       => 'Message',
					'description' => 'A sentence or two is plenty.',
					'required'    => true,
					'settings'    => array(
						'rows'       => 5,
						'max_length' => 2000,
						'min_length' => 10,
					),
				),
				array(
					'key'      => 'topic',
					'type'     => 'select',
					'label'    => 'What is this about?',
					'required' => true,
					'options'  => array(
						array( 'value' => 'new-project', 'label' => 'A new project' ),
						array( 'value' => 'support', 'label' => 'Support' ),
						array( 'value' => 'other', 'label' => 'Something else' ),
					),
					'settings' => array(),
				),
				array(
					'key'      => 'contact_method',
					'type'     => 'radio',
					'label'    => 'How should we reply?',
					'required' => true,
					'options'  => array(
						array( 'value' => 'email', 'label' => 'By email' ),
						array( 'value' => 'phone', 'label' => 'By phone' ),
					),
					'settings' => array(),
				),
				array(
					'key'         => 'topics',
					'type'        => 'checkbox',
					'label'       => 'Which topics interest you?',
					'description' => 'Choose as many as you like.',
					'required'    => false,
					'options'     => array(
						array( 'value' => 'design', 'label' => 'Design' ),
						array( 'value' => 'development', 'label' => 'Development' ),
						array( 'value' => 'research', 'label' => 'Research' ),
					),
					'settings'    => array(),
				),
				array(
					'key'      => 'consent',
					'type'     => 'consent',
					'label'    => 'I agree to the privacy notice',
					'required' => true,
					'settings' => array( 'privacy_url' => 'https://example.test/privacy' ),
				),
				array(
					'key'      => 'source',
					'type'     => 'hidden',
					'label'    => 'Source',
					'required' => false,
					'settings' => array( 'value' => 'contact-page' ),
				),
			),
			'settings'       => array(
				'submit_label' => 'Send message',
			),
		);
	}
}

if ( ! function_exists( 'agentshell_forms_fixture_second_definition' ) ) {
	/**
	 * A second, deliberately different form.
	 *
	 * Two forms on one page is the case where a shared id prefix, a shared
	 * challenge or a shared instance counter would show up.
	 *
	 * @return array
	 */
	function agentshell_forms_fixture_second_definition() {
		return array(
			'schema_version' => 1,
			'form_id'        => 'abcdef0123456789abcdef0123456789',
			'slug'           => 'newsletter',
			'name'           => 'Newsletter',
			'description'    => '',
			'version'        => 1,
			'status'         => 'published',
			'fields'         => array(
				array(
					'key'      => 'email',
					'type'     => 'email',
					'label'    => 'Your email',
					'required' => true,
					'settings' => array( 'max_length' => 254, 'autocomplete' => 'email' ),
				),
			),
			'settings'       => array( 'submit_label' => 'Subscribe' ),
		);
	}
}
