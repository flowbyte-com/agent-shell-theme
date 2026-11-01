<?php
/**
 * The field-type registry: ten types, each declaring its own settings schema.
 *
 * ## Why the type declares its own settings
 *
 * A field type is not a name plus a hard-coded branch in the validator. It is a
 * registry entry that declares the settings it accepts, their types, their
 * bounds and their defaults. The plugin's generic validator **walks that
 * declaration**, so a type added through `agentshell_form_field_types` gets
 * validation, a default, a length bound and (in Phase 4) a builder control
 * without the validator, the builder and the renderer being edited in three
 * places and drifting apart.
 *
 * That is the property the design calls out in §5.2: "The validator, the
 * builder and the renderer cannot drift, because all three read the same
 * declaration."
 *
 * ## `multiple` is explicit
 *
 * A checkbox group stores an array; every other type stores a scalar. The
 * submission pipeline branches on the declaration, not on the type name, so a
 * new multiple type works without the pipeline knowing its name.
 *
 * ## What this build does not include
 *
 * `render` is deliberately absent — rendering is Phase 2. `sanitize` and
 * `validate` are here because they are part of the registry's contract and are
 * pure functions that belong with the type's declaration, but nothing in Phase
 * 1 calls them on a request: there is no public submission processing yet.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The field-type registry.
 */
class Form_Field_Types {

	/**
	 * The registered types, lazily built.
	 *
	 * @var array<string,array>|null
	 */
	private static $types = null;

	/**
	 * The `autocomplete` token set.
	 *
	 * WCAG 2.2's *Identify Input Purpose* wants a machine-readable purpose on
	 * fields that collect information about the user, which is what this token
	 * becomes in the rendered `autocomplete` attribute (Phase 2).
	 *
	 * @return array<int,string>
	 */
	private static function autocomplete_tokens() {
		return array(
			'off',
			'name',
			'given-name',
			'additional-name',
			'family-name',
			'organization',
			'email',
			'tel',
			'url',
			'street-address',
			'address-line1',
			'address-line2',
			'postal-code',
			'country',
			'country-name',
		);
	}

	/**
	 * The registry.
	 *
	 * Filterable, because the design's contract is that a new type is added by
	 * a filter rather than by editing the plugin. The filter runs once per
	 * process; a filter that returns something other than an array is ignored
	 * rather than allowed to break every form on the site.
	 *
	 * @return array<string,array>
	 */
	public static function all() {
		if ( null !== self::$types ) {
			return self::$types;
		}

		$types = self::defaults();

		/**
		 * Filter the form field-type registry.
		 *
		 * Each entry is an array with `label`, `multiple`, `has_options`,
		 * `settings`, `sanitize` and `validate`. The registry is a **trust
		 * boundary**: a type registered here is code that runs, and the
		 * validator will accept its declaration without second-guessing it.
		 *
		 * @param array<string,array> $types The registry.
		 */
		$filtered = apply_filters( 'agentshell_form_field_types', $types );

		if ( ! is_array( $filtered ) ) {
			self::$types = $types;
			return self::$types;
		}

		self::$types = $filtered;
		return self::$types;
	}

	/**
	 * One type's declaration, or null.
	 *
	 * @param string $type Type name.
	 * @return array|null
	 */
	public static function get( $type ) {
		$all = self::all();
		return isset( $all[ $type ] ) && is_array( $all[ $type ] ) ? $all[ $type ] : null;
	}

	/**
	 * The registered type names.
	 *
	 * @return array<int,string>
	 */
	public static function names() {
		return array_keys( self::all() );
	}

	/**
	 * Forget the cached registry.
	 *
	 * Used by tests that register a type through the filter.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$types = null;
	}

	/**
	 * The ten MVP types.
	 *
	 * Deliberately short: each earns its place by being something a contact
	 * form actually needs. A type that is not in this list can still be added
	 * by a filter; it is not in the default registry because a form is not a
	 * general-purpose data-entry system.
	 *
	 * @return array<string,array>
	 */
	private static function defaults() {
		$autocomplete = self::autocomplete_tokens();

		return array(
			'text'     => array(
				'label'       => __( 'Single-line text', 'agentshell-forms' ),
				'multiple'    => false,
				'has_options' => false,
				'settings'    => array(
					'max_length'   => array(
						'type'    => 'integer',
						'min'     => 1,
						'max'     => 1000,
						'default' => 120,
					),
					'min_length'   => array(
						'type'    => 'integer',
						'min'     => 0,
						'max'     => 1000,
						'default' => 0,
					),
					'autocomplete' => array(
						'type'    => 'string',
						'enum'    => $autocomplete,
						'default' => 'off',
					),
				),
				'sanitize'    => function ( $value ) {
					return is_scalar( $value ) ? trim( (string) $value ) : '';
				},
				'validate'    => function ( $value, array $settings ) {
					return self::check_length( $value, $settings, __( 'This field', 'agentshell-forms' ) );
				},
			),

			'textarea' => array(
				'label'       => __( 'Multi-line text', 'agentshell-forms' ),
				'multiple'    => false,
				'has_options' => false,
				'settings'    => array(
					'max_length' => array(
						'type'    => 'integer',
						'min'     => 1,
						'max'     => 20000,
						'default' => 4000,
					),
					'min_length' => array(
						'type'    => 'integer',
						'min'     => 0,
						'max'     => 20000,
						'default' => 0,
					),
					'rows'       => array(
						'type'    => 'integer',
						'min'     => 1,
						'max'     => 50,
						'default' => 6,
					),
				),
				'sanitize'    => function ( $value ) {
					return is_scalar( $value ) ? trim( (string) $value ) : '';
				},
				'validate'    => function ( $value, array $settings ) {
					return self::check_length( $value, $settings, __( 'This field', 'agentshell-forms' ) );
				},
			),

			'email'    => array(
				'label'       => __( 'Email address', 'agentshell-forms' ),
				'multiple'    => false,
				'has_options' => false,
				'settings'    => array(
					'max_length'   => array(
						'type'    => 'integer',
						'min'     => 1,
						'max'     => 320,
						'default' => 254,
					),
					'autocomplete' => array(
						'type'    => 'string',
						'enum'    => $autocomplete,
						'default' => 'email',
					),
				),
				'sanitize'    => function ( $value ) {
					return is_scalar( $value ) ? trim( (string) $value ) : '';
				},
				'validate'    => function ( $value, array $settings ) {
					$length = self::check_length( $value, $settings, __( 'This field', 'agentshell-forms' ) );
					if ( true !== $length ) {
						return $length;
					}
					if ( '' === (string) $value ) {
						return true;
					}
					if ( ! is_email( (string) $value ) ) {
						return new \WP_Error(
							'form_field_email_invalid',
							__( 'Please enter an email address we can reply to.', 'agentshell-forms' )
						);
					}
					return true;
				},
			),

			'tel'      => array(
				'label'       => __( 'Telephone number', 'agentshell-forms' ),
				'multiple'    => false,
				'has_options' => false,
				'settings'    => array(
					'max_length'   => array(
						'type'    => 'integer',
						'min'     => 1,
						'max'     => 40,
						'default' => 40,
					),
					'autocomplete' => array(
						'type'    => 'string',
						'enum'    => $autocomplete,
						'default' => 'tel',
					),
				),
				'sanitize'    => function ( $value ) {
					return is_scalar( $value ) ? trim( (string) $value ) : '';
				},
				'validate'    => function ( $value, array $settings ) {
					$length = self::check_length( $value, $settings, __( 'This field', 'agentshell-forms' ) );
					if ( true !== $length ) {
						return $length;
					}
					if ( '' === (string) $value ) {
						return true;
					}
					// Deliberately permissive. A strict E.164 pattern rejects
					// numbers that are perfectly valid in the country they are
					// written for, and a form that refuses a real telephone
					// number is worse than one that accepts an odd-looking one.
					if ( ! preg_match( '/^[0-9+()\-.\s]{3,40}$/', (string) $value ) ) {
						return new \WP_Error(
							'form_field_tel_invalid',
							__( 'Please enter a telephone number using digits, spaces and + ( ) - . only.', 'agentshell-forms' )
						);
					}
					return true;
				},
			),

			'number'   => array(
				'label'       => __( 'Number', 'agentshell-forms' ),
				'multiple'    => false,
				'has_options' => false,
				'settings'    => array(
					'min'      => array(
						'type'    => 'number',
						'default' => null,
					),
					'max'      => array(
						'type'    => 'number',
						'default' => null,
					),
					'decimals' => array(
						'type'    => 'integer',
						'min'     => 0,
						'max'     => 6,
						'default' => 0,
					),
				),
				'sanitize'    => function ( $value ) {
					// Stored as a string, on purpose: a number a visitor typed
					// should not silently change shape, and both post meta and a
					// CSV export prefer text.
					return is_scalar( $value ) ? trim( (string) $value ) : '';
				},
				'validate'    => function ( $value, array $settings ) {
					if ( '' === (string) $value ) {
						return true;
					}
					if ( ! is_numeric( (string) $value ) ) {
						return new \WP_Error(
							'form_field_number_invalid',
							__( 'Please enter a number.', 'agentshell-forms' )
						);
					}
					$number = (float) $value;
					if ( isset( $settings['min'] ) && null !== $settings['min'] && is_numeric( $settings['min'] ) && $number < (float) $settings['min'] ) {
						return new \WP_Error(
							'form_field_number_range',
							sprintf(
								/* translators: %s: minimum value */
								__( 'Please enter a number of at least %s.', 'agentshell-forms' ),
								(string) $settings['min']
							)
						);
					}
					if ( isset( $settings['max'] ) && null !== $settings['max'] && is_numeric( $settings['max'] ) && $number > (float) $settings['max'] ) {
						return new \WP_Error(
							'form_field_number_range',
							sprintf(
								/* translators: %s: maximum value */
								__( 'Please enter a number no greater than %s.', 'agentshell-forms' ),
								(string) $settings['max']
							)
						);
					}
					return true;
				},
			),

			'select'   => array(
				'label'       => __( 'Dropdown', 'agentshell-forms' ),
				'multiple'    => false,
				'has_options' => true,
				'settings'    => array(),
				'sanitize'    => function ( $value ) {
					return is_scalar( $value ) ? trim( (string) $value ) : '';
				},
				'validate'    => function ( $value, array $settings, array $options = array() ) {
					unset( $settings );
					return self::check_option_membership( $value, $options );
				},
			),

			'radio'    => array(
				'label'       => __( 'Radio group', 'agentshell-forms' ),
				'multiple'    => false,
				'has_options' => true,
				'settings'    => array(),
				'sanitize'    => function ( $value ) {
					return is_scalar( $value ) ? trim( (string) $value ) : '';
				},
				'validate'    => function ( $value, array $settings, array $options = array() ) {
					unset( $settings );
					return self::check_option_membership( $value, $options );
				},
			),

			'checkbox' => array(
				'label'       => __( 'Checkbox group', 'agentshell-forms' ),
				'multiple'    => true,
				'has_options' => true,
				'settings'    => array(),
				'sanitize'    => function ( $value ) {
					if ( ! is_array( $value ) ) {
						return array();
					}
					$out = array();
					foreach ( $value as $item ) {
						if ( is_scalar( $item ) ) {
							$out[] = trim( (string) $item );
						}
					}
					return array_values( array_unique( $out ) );
				},
				'validate'    => function ( $value, array $settings, array $options = array() ) {
					unset( $settings );
					if ( ! is_array( $value ) ) {
						return new \WP_Error(
							'form_field_checkbox_invalid',
							__( 'Please choose one or more options.', 'agentshell-forms' )
						);
					}
					foreach ( $value as $item ) {
						$membership = self::check_option_membership( $item, $options );
						if ( true !== $membership ) {
							return $membership;
						}
					}
					return true;
				},
			),

			'consent'  => array(
				'label'       => __( 'Consent checkbox', 'agentshell-forms' ),
				'multiple'    => false,
				'has_options' => false,
				// The submission pipeline normalises the request value through
				// this flag rather than by type name: a JSON `false` and the
				// string `"false"` must both mean "not consented", and casting
				// the string with `(bool)` would turn it into consent.
				'boolean'     => true,
				'settings'    => array(
					'privacy_url' => array(
						'type'    => 'url',
						'default' => '',
					),
				),
				'sanitize'    => function ( $value ) {
					// `false` is a stored, meaningful value, not an absence:
					// "the visitor did not consent" is data the operator needs.
					return (bool) $value;
				},
				'validate'    => function ( $value, array $settings ) {
					unset( $settings );
					if ( true !== $value ) {
						return new \WP_Error(
							'form_field_consent_required',
							__( 'Please confirm that you agree before submitting.', 'agentshell-forms' )
						);
					}
					return true;
				},
			),

			'hidden'   => array(
				'label'       => __( 'Hidden value', 'agentshell-forms' ),
				'multiple'    => false,
				'has_options' => false,
				// The stored value is the definition's, never the request's.
				// The pipeline reads this flag instead of the type name, so a
				// third-party type can declare the same property.
				'definition_value' => true,
				'settings'    => array(
					'value' => array(
						'type'      => 'string',
						'max'       => 1000,
						'default'   => '',
					),
				),
				'sanitize'    => function ( $value ) {
					unset( $value );
					// A hidden field's value is ALWAYS taken from the definition
					// (§5.4). Returning '' here rather than the request value is
					// what makes the field unspoofable: the pipeline never reads
					// what the request supplied for it.
					return '';
				},
				'validate'    => function ( $value, array $settings ) {
					unset( $settings );
					return true;
				},
			),
		);
	}

	/**
	 * Check a string against a declared min/max length.
	 *
	 * Multibyte-aware where `mb_strlen` exists, because a 120-character limit
	 * that counts bytes would refuse 40 characters of a language that uses
	 * three bytes each.
	 *
	 * @param mixed  $value    The value.
	 * @param array  $settings The field's settings.
	 * @param string $label    A human label for the error.
	 * @return true|\WP_Error
	 */
	private static function check_length( $value, array $settings, $label ) {
		$string = is_scalar( $value ) ? (string) $value : '';
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $string, 'UTF-8' ) : strlen( $string );

		if ( isset( $settings['max_length'] ) && is_numeric( $settings['max_length'] ) && $length > (int) $settings['max_length'] ) {
			return new \WP_Error(
				'form_field_too_long',
				sprintf(
					/* translators: 1: field label, 2: maximum length */
					__( '%1$s must be at most %2$d characters.', 'agentshell-forms' ),
					$label,
					(int) $settings['max_length']
				)
			);
		}

		if ( isset( $settings['min_length'] ) && is_numeric( $settings['min_length'] ) && $length > 0 && $length < (int) $settings['min_length'] ) {
			return new \WP_Error(
				'form_field_too_short',
				sprintf(
					/* translators: 1: field label, 2: minimum length */
					__( '%1$s must be at least %2$d characters.', 'agentshell-forms' ),
					$label,
					(int) $settings['min_length']
				)
			);
		}

		return true;
	}

	/**
	 * Check that a value is one of the declared option values.
	 *
	 * @param mixed $value   The value.
	 * @param array $options The field's options, each `{ value, label }`.
	 * @return true|\WP_Error
	 */
	private static function check_option_membership( $value, array $options ) {
		if ( '' === (string) $value ) {
			return true;
		}
		foreach ( $options as $option ) {
			if ( is_array( $option ) && isset( $option['value'] ) && (string) $option['value'] === (string) $value ) {
				return true;
			}
		}
		return new \WP_Error(
			'form_field_option_invalid',
			__( 'Please choose one of the available options.', 'agentshell-forms' )
		);
	}
}
