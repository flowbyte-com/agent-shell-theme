<?php
/**
 * The authoritative form-definition schema and its validator.
 *
 * ## One schema, one set of rules
 *
 * The builder, the MCP tools, the renderer, the submission pipeline and this
 * validator all read the same document and the same rules. There is no second
 * copy. A rule that lived in two places would eventually disagree with itself,
 * and the disagreement would show up as a form that saves and then fails.
 *
 * ## The validator is the boundary
 *
 * It refuses **by name**: every failure carries a machine-readable `code`, a
 * `path` into the document and a human `message`. Unknown keys are rejected
 * rather than ignored — a silently ignored key is a definition that says
 * something the system does not do, and the operator has no way to find out.
 *
 * ## What it does not do
 *
 * It performs no I/O and reads no site state directly. Everything that depends
 * on the site — whether a slug is already taken, which recipient addresses are
 * allowed, whether a form has been published — arrives through `$context`, so
 * the validator is a pure function and can be exercised without a database.
 * The store is what supplies those facts.
 *
 * ## Error codes
 *
 * The codes in §5.5 of the design are implemented verbatim. Four codes are
 * added, because the design requires a refusal but does not name one for the
 * case, and inventing a silent pass would be worse than adding a code:
 *
 *   - `form_unknown_key` — an unknown key at the top level or in `settings`.
 *   - `form_unknown_field_key` — an unknown key inside a field.
 *   - `form_field_text_too_long` — a field's `description` or `placeholder`
 *     exceeds its bound.
 *   - `form_description_too_long` — the form's `description` exceeds its bound.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema validation and normalisation.
 */
class Form_Schema {

	/**
	 * The internal identifier pattern: exactly 32 lowercase hex characters.
	 *
	 * No prefix, no dashes, no uppercase. A prefix such as `f_` would make the
	 * stored value 34 characters and would not fit the `char(32)` column the
	 * challenge table uses.
	 */
	const FORM_ID_PATTERN = '/^[a-f0-9]{32}$/';

	/**
	 * The slug pattern.
	 *
	 * A slug may not match `FORM_ID_PATTERN`: the two namespaces must not
	 * collide, so a value that looks like an internal identifier is always an
	 * internal identifier.
	 */
	const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/';

	/**
	 * The field-key pattern.
	 */
	const FIELD_KEY_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/';

	/**
	 * The option-value pattern.
	 */
	const OPTION_VALUE_PATTERN = '/^[a-z0-9_-]{1,64}$/';

	/**
	 * The lawful-basis vocabulary.
	 *
	 * @return array<int,string>
	 */
	public static function lawful_bases() {
		return array( 'consent', 'contract', 'legitimate_interests', 'legal_obligation' );
	}

	/**
	 * The Turnstile policy vocabulary.
	 *
	 * @return array<int,string>
	 */
	public static function turnstile_policies() {
		return array( 'disabled', 'optional', 'required' );
	}

	/**
	 * A complete, valid default definition for a new form.
	 *
	 * @param string $form_id The internal identifier to use.
	 * @param string $slug    The slug to use.
	 * @param string $name    The form's name.
	 * @return array
	 */
	public static function defaults( $form_id, $slug, $name ) {
		return array(
			'schema_version' => AGENTSHELL_FORMS_SCHEMA_VERSION,
			'form_id'        => (string) $form_id,
			'slug'           => (string) $slug,
			'name'           => (string) $name,
			'description'    => '',
			'version'        => 0,
			'status'         => 'draft',
			'fields'         => array(),
			'settings'       => self::settings_defaults(),
		);
	}

	/**
	 * The default `settings` block.
	 *
	 * @return array
	 */
	public static function settings_defaults() {
		return array(
			'submit_label'    => __( 'Send', 'agentshell-forms' ),
			'success_message' => __( 'Thank you — your message has been sent.', 'agentshell-forms' ),
			'redirect_url'    => '',
			'notification'    => array(
				'enabled'       => false,
				'recipients'    => array( 'site-admin' ),
				'subject'       => __( 'New submission: {form:name}', 'agentshell-forms' ),
				'body_template' => "{fields}\n",
				'reply_to_field' => '',
				'confirmation'  => array(
					'enabled'       => false,
					'subject'       => __( 'We received your message', 'agentshell-forms' ),
					'body_template' => '',
				),
			),
			'spam'            => array(
				'turnstile'  => 'disabled',
				'honeypot'   => true,
				'min_seconds' => 3,
				'rate_limit' => array(
					'max'    => 5,
					'window' => 300,
				),
			),
			'storage'         => array(
				'retain_days'      => 0,
				'store_ip'         => false,
				'store_user_agent' => false,
			),
			'privacy'         => array(
				'lawful_basis'      => 'legitimate_interests',
				'privacy_notice_url' => '',
			),
		);
	}

	/**
	 * Fill in every optional key a caller omitted.
	 *
	 * Only **known** keys are filled. An unknown key that the caller supplied is
	 * left alone so that `validate()` can refuse it — normalising it away would
	 * turn "the document says something the system does not do" into silence.
	 *
	 * @param array $form The document.
	 * @return array
	 */
	public static function normalise( array $form ) {
		if ( ! isset( $form['description'] ) ) {
			$form['description'] = '';
		}
		if ( ! isset( $form['schema_version'] ) ) {
			$form['schema_version'] = AGENTSHELL_FORMS_SCHEMA_VERSION;
		}
		if ( ! isset( $form['version'] ) ) {
			$form['version'] = 0;
		}
		if ( ! isset( $form['status'] ) ) {
			$form['status'] = 'draft';
		}
		if ( ! isset( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
			$form['fields'] = array();
		}

		$defaults = self::settings_defaults();
		$settings = isset( $form['settings'] ) && is_array( $form['settings'] ) ? $form['settings'] : array();

		foreach ( $defaults as $key => $default ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				$settings[ $key ] = $default;
				continue;
			}
			if ( is_array( $default ) && is_array( $settings[ $key ] ) ) {
				$settings[ $key ] = self::fill_recursive( $default, $settings[ $key ] );
			}
		}

		$form['settings'] = $settings;

		// Fill each field's declared setting defaults.
		$fields = array();
		foreach ( $form['fields'] as $field ) {
			if ( ! is_array( $field ) ) {
				$fields[] = $field;
				continue;
			}
			if ( ! isset( $field['description'] ) ) {
				$field['description'] = '';
			}
			if ( ! isset( $field['placeholder'] ) ) {
				$field['placeholder'] = '';
			}
			if ( ! isset( $field['required'] ) ) {
				$field['required'] = false;
			}
			if ( ! isset( $field['options'] ) ) {
				$field['options'] = array();
			}
			if ( ! isset( $field['settings'] ) || ! is_array( $field['settings'] ) ) {
				$field['settings'] = array();
			}
			$type = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : '';
			$decl = Form_Field_Types::get( $type );
			if ( null !== $decl && isset( $decl['settings'] ) && is_array( $decl['settings'] ) ) {
				$field['settings'] = self::apply_setting_defaults( $decl['settings'], $field['settings'] );
			}
			$fields[] = $field;
		}
		$form['fields'] = $fields;

		return $form;
	}

	/**
	 * Recursively fill an array's missing keys from a defaults array.
	 *
	 * @param array $defaults Defaults.
	 * @param array $value    Supplied value.
	 * @return array
	 */
	private static function fill_recursive( array $defaults, array $value ) {
		foreach ( $defaults as $key => $default ) {
			if ( ! array_key_exists( $key, $value ) ) {
				$value[ $key ] = $default;
				continue;
			}
			if ( is_array( $default ) && is_array( $value[ $key ] ) ) {
				$value[ $key ] = self::fill_recursive( $default, $value[ $key ] );
			}
		}
		return $value;
	}

	/**
	 * Fill a field's declared setting defaults.
	 *
	 * @param array $declared The type's declared settings.
	 * @param array $supplied The field's supplied settings.
	 * @return array
	 */
	private static function apply_setting_defaults( array $declared, array $supplied ) {
		foreach ( $declared as $name => $spec ) {
			if ( ! array_key_exists( $name, $supplied ) ) {
				$supplied[ $name ] = isset( $spec['default'] ) ? $spec['default'] : null;
			}
		}
		return $supplied;
	}

	/**
	 * Validate a definition.
	 *
	 * @param array $form    The document (normalise it first, or accept the
	 *                       extra errors a missing default would produce).
	 * @param array $context {
	 *     Optional. Facts the validator cannot know by itself.
	 *
	 *     @type string        $site_host          Host of the site, for URL and
	 *                                             recipient checks.
	 *     @type array         $allowed_recipients Extra allowed recipient emails.
	 *     @type callable|null $slug_taken         Callable receiving a slug,
	 *                                             returning true when another
	 *                                             form already uses it.
	 *     @type bool          $has_been_published Whether the form has ever been
	 *                                             published (freezes the slug).
	 *     @type string        $previous_slug      The stored slug, when it exists.
	 * }
	 * @return array<int,array{code:string,path:string,message:string}> Empty when valid.
	 */
	public static function validate( array $form, array $context = array() ) {
		$errors = array();

		$add = function ( $code, $path, $message ) use ( &$errors ) {
			$errors[] = array(
				'code'    => (string) $code,
				'path'    => (string) $path,
				'message' => (string) $message,
			);
		};

		/* ---- top-level keys ------------------------------------------- */

		$allowed_top = array(
			'schema_version',
			'form_id',
			'slug',
			'name',
			'description',
			'version',
			'status',
			'fields',
			'settings',
		);

		foreach ( array_keys( $form ) as $key ) {
			if ( ! in_array( $key, $allowed_top, true ) ) {
				$add(
					'form_unknown_key',
					(string) $key,
					sprintf(
						/* translators: %s: key name */
						__( 'Unknown key "%s". A form may not carry a field the system does not understand.', 'agentshell-forms' ),
						(string) $key
					)
				);
			}
		}

		/* ---- schema_version ------------------------------------------ */

		if ( ! isset( $form['schema_version'] ) || ! is_int( $form['schema_version'] ) || AGENTSHELL_FORMS_SCHEMA_VERSION !== $form['schema_version'] ) {
			$add(
				'form_schema_version_unsupported',
				'schema_version',
				sprintf(
					/* translators: %d: supported schema version */
					__( 'This build understands schema_version %d only.', 'agentshell-forms' ),
					AGENTSHELL_FORMS_SCHEMA_VERSION
				)
			);
		}

		/* ---- form_id -------------------------------------------------- */

		$form_id = isset( $form['form_id'] ) && is_string( $form['form_id'] ) ? $form['form_id'] : '';
		if ( ! preg_match( self::FORM_ID_PATTERN, $form_id ) ) {
			$add(
				'form_id_invalid',
				'form_id',
				__( 'form_id must be exactly 32 lowercase hexadecimal characters.', 'agentshell-forms' )
			);
		}

		/* ---- slug ----------------------------------------------------- */

		$slug = isset( $form['slug'] ) && is_string( $form['slug'] ) ? $form['slug'] : '';
		if ( ! preg_match( self::SLUG_PATTERN, $slug ) ) {
			$add(
				'form_slug_invalid',
				'slug',
				__( 'The slug must be 1–64 characters of lowercase letters, digits and hyphens, and may not start with a hyphen.', 'agentshell-forms' )
			);
		} elseif ( preg_match( self::FORM_ID_PATTERN, $slug ) ) {
			$add(
				'form_slug_invalid',
				'slug',
				__( 'A slug may not look like an internal identifier: 32 hexadecimal characters is reserved for form ids.', 'agentshell-forms' )
			);
		} else {
			if ( ! empty( $context['has_been_published'] )
				&& isset( $context['previous_slug'] )
				&& '' !== (string) $context['previous_slug']
				&& (string) $context['previous_slug'] !== $slug ) {
				$add(
					'form_slug_frozen',
					'slug',
					__( 'The slug is frozen after a form’s first publication, because its shortcode may already be in pages.', 'agentshell-forms' )
				);
			}

			if ( isset( $context['slug_taken'] ) && is_callable( $context['slug_taken'] ) ) {
				if ( call_user_func( $context['slug_taken'], $slug ) ) {
					$add(
						'form_slug_duplicate',
						'slug',
						__( 'Another form already uses this slug.', 'agentshell-forms' )
					);
				}
			}
		}

		/* ---- name / description --------------------------------------- */

		$name = isset( $form['name'] ) && is_string( $form['name'] ) ? $form['name'] : '';
		if ( '' === trim( $name ) || self::strlen( $name ) > 200 ) {
			$add(
				'form_name_invalid',
				'name',
				__( 'A form needs a name of 1–200 characters.', 'agentshell-forms' )
			);
		}

		$description = isset( $form['description'] ) && is_string( $form['description'] ) ? $form['description'] : '';
		if ( self::strlen( $description ) > 2000 ) {
			$add(
				'form_description_too_long',
				'description',
				__( 'The form description may be at most 2,000 characters.', 'agentshell-forms' )
			);
		}

		/* ---- version / status ----------------------------------------- */

		if ( isset( $form['version'] ) && ( ! is_int( $form['version'] ) || $form['version'] < 0 ) ) {
			$add( 'form_version_invalid', 'version', __( 'version must be a non-negative integer.', 'agentshell-forms' ) );
		}

		if ( isset( $form['status'] ) && ! in_array( $form['status'], array( 'draft', 'published' ), true ) ) {
			$add( 'form_status_invalid', 'status', __( 'status must be "draft" or "published".', 'agentshell-forms' ) );
		}

		/* ---- fields --------------------------------------------------- */

		$fields = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : null;

		if ( null === $fields ) {
			$add( 'form_fields_count_invalid', 'fields', __( 'fields must be a list.', 'agentshell-forms' ) );
		} elseif ( count( $fields ) < 1 || count( $fields ) > 200 ) {
			$add(
				'form_fields_count_invalid',
				'fields',
				__( 'A form needs between 1 and 200 fields.', 'agentshell-forms' )
			);
		} else {
			$seen_keys  = array();
			$email_keys = array();

			foreach ( array_values( $fields ) as $index => $field ) {
				$path = 'fields.' . $index;

				if ( ! is_array( $field ) ) {
					$add( 'form_field_invalid', $path, __( 'A field must be an object.', 'agentshell-forms' ) );
					continue;
				}

				$allowed_field = array( 'key', 'type', 'label', 'description', 'placeholder', 'required', 'settings', 'options' );
				foreach ( array_keys( $field ) as $field_key ) {
					if ( ! in_array( $field_key, $allowed_field, true ) ) {
						$add(
							'form_unknown_field_key',
							$path . '.' . $field_key,
							sprintf(
								/* translators: %s: key name */
								__( 'Unknown field key "%s".', 'agentshell-forms' ),
								(string) $field_key
							)
						);
					}
				}

				$key = isset( $field['key'] ) && is_string( $field['key'] ) ? $field['key'] : '';
				if ( ! preg_match( self::FIELD_KEY_PATTERN, $key ) ) {
					$add(
						'form_field_key_invalid',
						$path . '.key',
						__( 'A field key must start with a lowercase letter and contain only lowercase letters, digits and underscores (max 40).', 'agentshell-forms' )
					);
				} elseif ( isset( $seen_keys[ $key ] ) ) {
					$add(
						'form_field_key_duplicate',
						$path . '.key',
						sprintf(
							/* translators: %s: field key */
							__( 'The field key "%s" is used more than once.', 'agentshell-forms' ),
							$key
						)
					);
				} else {
					$seen_keys[ $key ] = true;
				}

				$type = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : '';
				$decl = Form_Field_Types::get( $type );
				if ( null === $decl ) {
					$add(
						'form_field_type_unknown',
						$path . '.type',
						sprintf(
							/* translators: %s: type name */
							__( 'Unknown field type "%s".', 'agentshell-forms' ),
							$type
						)
					);
				} elseif ( 'email' === $type ) {
					$email_keys[ $key ] = true;
				}

				$label = isset( $field['label'] ) && is_string( $field['label'] ) ? $field['label'] : '';
				if ( '' === trim( $label ) || self::strlen( $label ) > 200 ) {
					$add(
						'form_field_label_invalid',
						$path . '.label',
						__( 'A field needs a label of 1–200 characters.', 'agentshell-forms' )
					);
				}

				$field_description = isset( $field['description'] ) && is_string( $field['description'] ) ? $field['description'] : '';
				if ( self::strlen( $field_description ) > 1000 ) {
					$add(
						'form_field_text_too_long',
						$path . '.description',
						__( 'A field description may be at most 1,000 characters.', 'agentshell-forms' )
					);
				}

				$placeholder = isset( $field['placeholder'] ) && is_string( $field['placeholder'] ) ? $field['placeholder'] : '';
				if ( self::strlen( $placeholder ) > 200 ) {
					$add(
						'form_field_text_too_long',
						$path . '.placeholder',
						__( 'A placeholder may be at most 200 characters.', 'agentshell-forms' )
					);
				}

				if ( isset( $field['required'] ) && ! is_bool( $field['required'] ) ) {
					$add( 'form_field_invalid', $path . '.required', __( 'required must be true or false.', 'agentshell-forms' ) );
				}

				/* ---- settings walk ---------------------------------- */

				$settings = isset( $field['settings'] ) && is_array( $field['settings'] ) ? $field['settings'] : array();

				if ( null !== $decl ) {
					$declared = isset( $decl['settings'] ) && is_array( $decl['settings'] ) ? $decl['settings'] : array();

					foreach ( array_keys( $settings ) as $setting_name ) {
						if ( ! array_key_exists( $setting_name, $declared ) ) {
							$add(
								'form_field_setting_invalid',
								$path . '.settings.' . $setting_name,
								sprintf(
									/* translators: 1: setting name, 2: field type */
									__( 'The setting "%1$s" is not declared by field type "%2$s".', 'agentshell-forms' ),
									(string) $setting_name,
									$type
								)
							);
						}
					}

					foreach ( $declared as $setting_name => $spec ) {
						if ( ! array_key_exists( $setting_name, $settings ) ) {
							continue;
						}
						$setting_error = self::check_setting( $settings[ $setting_name ], $spec );
						if ( null !== $setting_error ) {
							$add(
								'form_field_setting_invalid',
								$path . '.settings.' . $setting_name,
								sprintf(
									/* translators: 1: setting name, 2: reason */
									__( 'The setting "%1$s" is invalid: %2$s', 'agentshell-forms' ),
									(string) $setting_name,
									$setting_error
								)
							);
						}
					}

					/* ---- options ------------------------------------ */

					$has_options = ! empty( $decl['has_options'] );
					$options     = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();

					if ( $has_options && count( $options ) < 1 ) {
						$add(
							'form_field_options_invalid',
							$path . '.options',
							__( 'A field of this type needs at least one option.', 'agentshell-forms' )
						);
					}
					if ( ! $has_options && count( $options ) > 0 ) {
						$add(
							'form_field_options_invalid',
							$path . '.options',
							__( 'A field of this type may not carry options.', 'agentshell-forms' )
						);
					}

					if ( $has_options ) {
						$seen_values = array();
						foreach ( array_values( $options ) as $option_index => $option ) {
							$option_path = $path . '.options.' . $option_index;
							if ( ! is_array( $option ) ) {
								$add( 'form_field_options_invalid', $option_path, __( 'An option must be an object.', 'agentshell-forms' ) );
								continue;
							}
							$value = isset( $option['value'] ) && is_string( $option['value'] ) ? $option['value'] : '';
							$text  = isset( $option['label'] ) && is_string( $option['label'] ) ? $option['label'] : '';

							if ( ! preg_match( self::OPTION_VALUE_PATTERN, $value ) ) {
								$add(
									'form_field_options_invalid',
									$option_path . '.value',
									__( 'An option value must be 1–64 characters of lowercase letters, digits, underscores and hyphens.', 'agentshell-forms' )
								);
							} elseif ( isset( $seen_values[ $value ] ) ) {
								$add(
									'form_field_options_invalid',
									$option_path . '.value',
									sprintf(
										/* translators: %s: option value */
										__( 'The option value "%s" is used more than once.', 'agentshell-forms' ),
										$value
									)
								);
							} else {
								$seen_values[ $value ] = true;
							}

							if ( '' === trim( $text ) || self::strlen( $text ) > 200 ) {
								$add(
									'form_field_options_invalid',
									$option_path . '.label',
									__( 'An option needs a label of 1–200 characters.', 'agentshell-forms' )
								);
							}
						}
					}
				}
			}

			/* ---- reply_to_field needs an email field ----------- */

			$reply_to = isset( $form['settings']['notification']['reply_to_field'] )
				? (string) $form['settings']['notification']['reply_to_field']
				: '';

			if ( '' !== $reply_to && ! isset( $email_keys[ $reply_to ] ) ) {
				$add(
					'form_notification_reply_to_invalid',
					'settings.notification.reply_to_field',
					__( 'reply_to_field must name a field of type "email".', 'agentshell-forms' )
				);
			}
		}

		/* ---- settings ------------------------------------------------- */

		$settings = isset( $form['settings'] ) && is_array( $form['settings'] ) ? $form['settings'] : array();

		$allowed_settings = array(
			'submit_label',
			'success_message',
			'redirect_url',
			'notification',
			'spam',
			'storage',
			'privacy',
		);
		foreach ( array_keys( $settings ) as $key ) {
			if ( ! in_array( $key, $allowed_settings, true ) ) {
				$add(
					'form_unknown_key',
					'settings.' . $key,
					sprintf(
						/* translators: %s: key name */
						__( 'Unknown settings key "%s".', 'agentshell-forms' ),
						(string) $key
					)
				);
			}
		}

		$submit_label = isset( $settings['submit_label'] ) && is_string( $settings['submit_label'] ) ? $settings['submit_label'] : '';
		if ( '' === trim( $submit_label ) || self::strlen( $submit_label ) > 100 ) {
			$add( 'form_submit_label_invalid', 'settings.submit_label', __( 'The submit label must be 1–100 characters.', 'agentshell-forms' ) );
		}

		$success_message = isset( $settings['success_message'] ) && is_string( $settings['success_message'] ) ? $settings['success_message'] : '';
		if ( self::strlen( $success_message ) > 1000 ) {
			$add( 'form_success_message_invalid', 'settings.success_message', __( 'The success message may be at most 1,000 characters.', 'agentshell-forms' ) );
		}

		$redirect = isset( $settings['redirect_url'] ) && is_string( $settings['redirect_url'] ) ? $settings['redirect_url'] : '';
		if ( '' !== $redirect && ! self::is_url_on_site( $redirect, isset( $context['site_host'] ) ? (string) $context['site_host'] : '' ) ) {
			$add(
				'form_redirect_url_invalid',
				'settings.redirect_url',
				__( 'The redirect must be empty, a path on this site, or an http(s) URL on this site.', 'agentshell-forms' )
			);
		}

		self::validate_notification( $settings, $context, $add );
		self::validate_spam( $settings, $add );
		self::validate_storage( $settings, $add );
		self::validate_privacy( $settings, $add );

		return $errors;
	}

	/**
	 * Validate the `notification` block.
	 *
	 * @param array    $settings Settings.
	 * @param array    $context  Validation context.
	 * @param callable $add      Error collector.
	 * @return void
	 */
	private static function validate_notification( array $settings, array $context, callable $add ) {
		$notification = isset( $settings['notification'] ) && is_array( $settings['notification'] ) ? $settings['notification'] : array();

		$allowed = array( 'enabled', 'recipients', 'subject', 'body_template', 'reply_to_field', 'confirmation' );
		foreach ( array_keys( $notification ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				$add( 'form_unknown_key', 'settings.notification.' . $key, __( 'Unknown notification key.', 'agentshell-forms' ) );
			}
		}

		if ( isset( $notification['enabled'] ) && ! is_bool( $notification['enabled'] ) ) {
			$add( 'form_notification_recipients_invalid', 'settings.notification.enabled', __( 'enabled must be true or false.', 'agentshell-forms' ) );
		}

		$recipients = isset( $notification['recipients'] ) && is_array( $notification['recipients'] ) ? $notification['recipients'] : array();
		$site_host  = isset( $context['site_host'] ) ? (string) $context['site_host'] : '';
		$extra      = isset( $context['allowed_recipients'] ) && is_array( $context['allowed_recipients'] ) ? $context['allowed_recipients'] : array();

		foreach ( $recipients as $index => $recipient ) {
			if ( ! is_string( $recipient ) ) {
				$add( 'form_notification_recipients_invalid', 'settings.notification.recipients.' . $index, __( 'A recipient must be a string.', 'agentshell-forms' ) );
				continue;
			}
			if ( 'site-admin' === $recipient ) {
				continue;
			}
			if ( in_array( $recipient, $extra, true ) ) {
				continue;
			}
			if ( self::is_email_on_site( $recipient, $site_host ) ) {
				continue;
			}
			$add(
				'form_notification_recipients_invalid',
				'settings.notification.recipients.' . $index,
				sprintf(
					/* translators: %s: email address */
					__( 'The recipient "%s" is not the site administrator and is not on this site’s domain.', 'agentshell-forms' ),
					$recipient
				)
			);
		}

		foreach ( array( 'subject', 'body_template' ) as $template_key ) {
			$template = isset( $notification[ $template_key ] ) && is_string( $notification[ $template_key ] ) ? $notification[ $template_key ] : '';
			if ( self::strlen( $template ) > 8000 ) {
				$add(
					'form_notification_template_too_large',
					'settings.notification.' . $template_key,
					__( 'A notification template may be at most 8,000 characters.', 'agentshell-forms' )
				);
			}
		}

		$confirmation = isset( $notification['confirmation'] ) && is_array( $notification['confirmation'] ) ? $notification['confirmation'] : array();
		foreach ( array_keys( $confirmation ) as $key ) {
			if ( ! in_array( $key, array( 'enabled', 'subject', 'body_template' ), true ) ) {
				$add( 'form_unknown_key', 'settings.notification.confirmation.' . $key, __( 'Unknown confirmation key.', 'agentshell-forms' ) );
			}
		}
		foreach ( array( 'subject', 'body_template' ) as $template_key ) {
			$template = isset( $confirmation[ $template_key ] ) && is_string( $confirmation[ $template_key ] ) ? $confirmation[ $template_key ] : '';
			if ( self::strlen( $template ) > 8000 ) {
				$add(
					'form_notification_template_too_large',
					'settings.notification.confirmation.' . $template_key,
					__( 'A notification template may be at most 8,000 characters.', 'agentshell-forms' )
				);
			}
		}
	}

	/**
	 * Validate the `spam` block.
	 *
	 * @param array    $settings Settings.
	 * @param callable $add      Error collector.
	 * @return void
	 */
	private static function validate_spam( array $settings, callable $add ) {
		$spam = isset( $settings['spam'] ) && is_array( $settings['spam'] ) ? $settings['spam'] : array();

		foreach ( array_keys( $spam ) as $key ) {
			if ( ! in_array( $key, array( 'turnstile', 'honeypot', 'min_seconds', 'rate_limit' ), true ) ) {
				$add( 'form_unknown_key', 'settings.spam.' . $key, __( 'Unknown spam key.', 'agentshell-forms' ) );
			}
		}

		$turnstile = isset( $spam['turnstile'] ) ? (string) $spam['turnstile'] : '';
		if ( ! in_array( $turnstile, self::turnstile_policies(), true ) ) {
			$add(
				'form_spam_turnstile_invalid',
				'settings.spam.turnstile',
				__( 'turnstile must be "disabled", "optional" or "required".', 'agentshell-forms' )
			);
		}

		if ( isset( $spam['honeypot'] ) && ! is_bool( $spam['honeypot'] ) ) {
			$add( 'form_spam_rate_limit_invalid', 'settings.spam.honeypot', __( 'honeypot must be true or false.', 'agentshell-forms' ) );
		}

		if ( isset( $spam['min_seconds'] ) ) {
			if ( ! is_int( $spam['min_seconds'] ) || $spam['min_seconds'] < 0 || $spam['min_seconds'] > 3600 ) {
				$add( 'form_spam_rate_limit_invalid', 'settings.spam.min_seconds', __( 'min_seconds must be an integer from 0 to 3,600.', 'agentshell-forms' ) );
			}
		}

		$rate = isset( $spam['rate_limit'] ) && is_array( $spam['rate_limit'] ) ? $spam['rate_limit'] : array();
		foreach ( array_keys( $rate ) as $key ) {
			if ( ! in_array( $key, array( 'max', 'window' ), true ) ) {
				$add( 'form_unknown_key', 'settings.spam.rate_limit.' . $key, __( 'Unknown rate-limit key.', 'agentshell-forms' ) );
			}
		}

		$max    = isset( $rate['max'] ) ? $rate['max'] : null;
		$window = isset( $rate['window'] ) ? $rate['window'] : null;

		if ( ! is_int( $max ) || $max < 1 || $max > 100 ) {
			$add( 'form_spam_rate_limit_invalid', 'settings.spam.rate_limit.max', __( 'rate_limit.max must be an integer from 1 to 100.', 'agentshell-forms' ) );
		}
		if ( ! is_int( $window ) || $window < 30 || $window > 86400 ) {
			$add( 'form_spam_rate_limit_invalid', 'settings.spam.rate_limit.window', __( 'rate_limit.window must be an integer from 30 to 86,400 seconds.', 'agentshell-forms' ) );
		}
	}

	/**
	 * Validate the `storage` block.
	 *
	 * @param array    $settings Settings.
	 * @param callable $add      Error collector.
	 * @return void
	 */
	private static function validate_storage( array $settings, callable $add ) {
		$storage = isset( $settings['storage'] ) && is_array( $settings['storage'] ) ? $settings['storage'] : array();

		foreach ( array_keys( $storage ) as $key ) {
			if ( ! in_array( $key, array( 'retain_days', 'store_ip', 'store_user_agent' ), true ) ) {
				$add( 'form_unknown_key', 'settings.storage.' . $key, __( 'Unknown storage key.', 'agentshell-forms' ) );
			}
		}

		if ( isset( $storage['retain_days'] ) ) {
			$days = $storage['retain_days'];
			if ( ! is_int( $days ) || ( 0 !== $days && ( $days < 1 || $days > 3650 ) ) ) {
				$add(
					'form_storage_retention_invalid',
					'settings.storage.retain_days',
					__( 'retain_days must be 0 (site default) or an integer from 1 to 3,650.', 'agentshell-forms' )
				);
			}
		}

		foreach ( array( 'store_ip', 'store_user_agent' ) as $flag ) {
			if ( isset( $storage[ $flag ] ) && ! is_bool( $storage[ $flag ] ) ) {
				$add( 'form_storage_retention_invalid', 'settings.storage.' . $flag, __( 'This setting must be true or false.', 'agentshell-forms' ) );
			}
		}
	}

	/**
	 * Validate the `privacy` block.
	 *
	 * @param array    $settings Settings.
	 * @param callable $add      Error collector.
	 * @return void
	 */
	private static function validate_privacy( array $settings, callable $add ) {
		$privacy = isset( $settings['privacy'] ) && is_array( $settings['privacy'] ) ? $settings['privacy'] : array();

		foreach ( array_keys( $privacy ) as $key ) {
			if ( ! in_array( $key, array( 'lawful_basis', 'privacy_notice_url' ), true ) ) {
				$add( 'form_unknown_key', 'settings.privacy.' . $key, __( 'Unknown privacy key.', 'agentshell-forms' ) );
			}
		}

		$basis = isset( $privacy['lawful_basis'] ) ? (string) $privacy['lawful_basis'] : '';
		if ( ! in_array( $basis, self::lawful_bases(), true ) ) {
			$add(
				'form_privacy_basis_invalid',
				'settings.privacy.lawful_basis',
				__( 'lawful_basis must be consent, contract, legitimate_interests or legal_obligation.', 'agentshell-forms' )
			);
		}

		$notice = isset( $privacy['privacy_notice_url'] ) && is_string( $privacy['privacy_notice_url'] ) ? $privacy['privacy_notice_url'] : '';
		if ( '' !== $notice && ! self::is_http_url( $notice ) ) {
			$add(
				'form_privacy_notice_url_invalid',
				'settings.privacy.privacy_notice_url',
				__( 'The privacy notice URL must be empty or an http(s) URL.', 'agentshell-forms' )
			);
		}
	}

	/**
	 * Check one declared setting value against its spec.
	 *
	 * @param mixed $value The value.
	 * @param array $spec  The declaration.
	 * @return string|null A reason, or null when valid.
	 */
	private static function check_setting( $value, array $spec ) {
		$type = isset( $spec['type'] ) ? (string) $spec['type'] : 'string';

		switch ( $type ) {
			case 'integer':
				if ( null === $value ) {
					return null;
				}
				if ( ! is_int( $value ) ) {
					return __( 'expected an integer.', 'agentshell-forms' );
				}
				if ( isset( $spec['min'] ) && $value < $spec['min'] ) {
					return sprintf(
						/* translators: %d: minimum */
						__( 'must be at least %d.', 'agentshell-forms' ),
						(int) $spec['min']
					);
				}
				if ( isset( $spec['max'] ) && $value > $spec['max'] ) {
					return sprintf(
						/* translators: %d: maximum */
						__( 'must be at most %d.', 'agentshell-forms' ),
						(int) $spec['max']
					);
				}
				return null;

			case 'number':
				if ( null === $value ) {
					return null;
				}
				if ( ! is_int( $value ) && ! is_float( $value ) ) {
					return __( 'expected a number.', 'agentshell-forms' );
				}
				return null;

			case 'boolean':
				if ( null !== $value && ! is_bool( $value ) ) {
					return __( 'expected true or false.', 'agentshell-forms' );
				}
				return null;

			case 'url':
				if ( null !== $value && '' !== $value && ! is_string( $value ) ) {
					return __( 'expected a URL string.', 'agentshell-forms' );
				}
				if ( is_string( $value ) && '' !== $value && ! self::is_http_url( $value ) ) {
					return __( 'expected an http(s) URL.', 'agentshell-forms' );
				}
				return null;

			case 'string':
			default:
				if ( null !== $value && ! is_string( $value ) ) {
					return __( 'expected a string.', 'agentshell-forms' );
				}
				if ( is_string( $value ) ) {
					if ( isset( $spec['max'] ) && self::strlen( $value ) > (int) $spec['max'] ) {
						return sprintf(
							/* translators: %d: maximum length */
							__( 'may be at most %d characters.', 'agentshell-forms' ),
							(int) $spec['max']
						);
					}
					if ( isset( $spec['enum'] ) && is_array( $spec['enum'] ) && ! in_array( $value, $spec['enum'], true ) ) {
						return __( 'is not one of the allowed values.', 'agentshell-forms' );
					}
				}
				return null;
		}
	}

	/**
	 * Is this an absolute http(s) URL?
	 *
	 * @param string $url The URL.
	 * @return bool
	 */
	private static function is_http_url( $url ) {
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( (string) $url ) : parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		return in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true );
	}

	/**
	 * Is this URL empty-safe and on this site?
	 *
	 * A path-only URL (`/thank-you/`) is on the site by definition.
	 *
	 * @param string $url       The URL.
	 * @param string $site_host The site's host.
	 * @return bool
	 */
	private static function is_url_on_site( $url, $site_host ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return true;
		}
		if ( '/' === $url[0] && ( ! isset( $url[1] ) || '/' !== $url[1] ) ) {
			return true;
		}
		if ( ! self::is_http_url( $url ) ) {
			return false;
		}
		if ( '' === $site_host ) {
			return false;
		}
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
		$host  = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$site  = strtolower( (string) $site_host );

		if ( $host === $site ) {
			return true;
		}
		if ( 'www.' . $host === $site || $host === 'www.' . $site ) {
			return true;
		}
		return false;
	}

	/**
	 * Is this email address on the site's domain?
	 *
	 * @param string $email     The address.
	 * @param string $site_host The site's host.
	 * @return bool
	 */
	private static function is_email_on_site( $email, $site_host ) {
		if ( ! is_email( (string) $email ) ) {
			return false;
		}
		if ( '' === (string) $site_host ) {
			return false;
		}
		$domain = strtolower( (string) substr( (string) $email, strrpos( (string) $email, '@' ) + 1 ) );
		$site   = strtolower( (string) $site_host );
		if ( 'www.' === substr( $site, 0, 4 ) ) {
			$site = substr( $site, 4 );
		}
		return $domain === $site;
	}

	/**
	 * Multibyte-aware length.
	 *
	 * @param string $value The string.
	 * @return int
	 */
	private static function strlen( $value ) {
		$value = (string) $value;
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}
}
