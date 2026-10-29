<?php
/**
 * Unit regressions for the authoritative form-definition schema.
 *
 * The schema is the boundary every other consumer reads — the builder, the MCP
 * tools, the renderer, the submission pipeline. A rule that lived in two places
 * would eventually disagree with itself, and the disagreement would show up as
 * a form that saves and then fails. This suite pins the rule set itself: every
 * refusal in §5.5 of the design, by name, against the real validator.
 *
 * It runs on the stub bootstrap and needs no database. The storage behaviour —
 * meta rows, `meta_id` compare-and-swap, duplicate detection, publication — is
 * `tests/forms-storage-test.php`, which runs against a real WordPress.
 */

require_once __DIR__ . '/bootstrap.php';

// ── WordPress surface the schema and the field types call ─────────────────────
// Guarded so that defining them here cannot collide with a future suite.
if ( ! function_exists( 'is_email' ) ) {
    /**
     * A deliberately small RFC-ish check. WordPress's own is stricter about
     * some things and looser about others; what the validator's tests care
     * about is that a string with no `@`, or with no dot in the domain, is
     * refused and a plausible address is accepted.
     */
    function is_email( $email ) {
        $email = (string) $email;
        if ( false !== strpos( $email, '..' ) ) { return false; }
        if ( ! preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email ) ) { return false; }
        return $email;
    }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}

require_once dirname( __DIR__ ) . '/agentshell-forms/includes/class-form-post-types.php';
require_once dirname( __DIR__ ) . '/agentshell-forms/includes/class-form-field-types.php';
require_once dirname( __DIR__ ) . '/agentshell-forms/includes/class-form-schema.php';
require_once dirname( __DIR__ ) . '/agentshell-forms/includes/functions.php';

if ( ! defined( 'AGENTSHELL_FORMS_SCHEMA_VERSION' ) ) {
    define( 'AGENTSHELL_FORMS_SCHEMA_VERSION', 1 );
}

use AgentShell_Forms\Form_Field_Types;
use AgentShell_Forms\Form_Schema;

echo "forms schema (unit)\n";

/**
 * A minimal valid definition, so each test can break exactly one thing.
 *
 * @param array $overrides Top-level overrides.
 * @return array
 */
function fs_valid( array $overrides = array() ) {
    $form = Form_Schema::defaults(
        '7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a',
        'contact',
        'Contact'
    );
    $form['fields'] = array(
        array(
            'key'         => 'name',
            'type'        => 'text',
            'label'       => 'Your name',
            'description' => '',
            'placeholder' => '',
            'required'    => true,
            'settings'    => array(),
            'options'     => array(),
        ),
        array(
            'key'         => 'email',
            'type'        => 'email',
            'label'       => 'Your email',
            'description' => '',
            'placeholder' => '',
            'required'    => true,
            'settings'    => array(),
            'options'     => array(),
        ),
        array(
            'key'         => 'message',
            'type'        => 'textarea',
            'label'       => 'Message',
            'description' => '',
            'placeholder' => '',
            'required'    => true,
            'settings'    => array(),
            'options'     => array(),
        ),
    );
    $form = Form_Schema::normalise( $form );

    foreach ( $overrides as $key => $value ) {
        $form[ $key ] = $value;
    }
    return $form;
}

/**
 * The error codes a document produces.
 *
 * @param array $form    The document.
 * @param array $context Validation context.
 * @return array<int,string>
 */
function fs_codes( array $form, array $context = array() ) {
    $codes = array();
    foreach ( Form_Schema::validate( $form, $context ) as $error ) {
        $codes[] = (string) $error['code'];
    }
    return $codes;
}

/**
 * The `path` values a document's errors name.
 *
 * @param array $form The document.
 * @return array<int,string>
 */
function fs_paths( array $form ) {
    $paths = array();
    foreach ( Form_Schema::validate( $form ) as $error ) {
        $paths[] = (string) $error['path'];
    }
    return $paths;
}

/**
 * One field, valid for its type.
 *
 * @param string $type   Field type.
 * @param array  $extra  Extra keys.
 * @return array
 */
function fs_field( $type, array $extra = array() ) {
    $field = array(
        'key'      => 'f_' . $type,
        'type'     => $type,
        'label'    => 'A ' . $type . ' field',
        'required' => false,
        'settings' => array(),
        'options'  => array(),
    );
    if ( 'select' === $type || 'radio' === $type || 'checkbox' === $type ) {
        $field['options'] = array(
            array( 'value' => 'one', 'label' => 'One' ),
            array( 'value' => 'two', 'label' => 'Two' ),
        );
    }
    foreach ( $extra as $key => $value ) {
        $field[ $key ] = $value;
    }
    return Form_Schema::normalise( array( 'fields' => array( $field ) ) )['fields'][0];
}

/* ---------------------------------------------------------------------------
 * The registry
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms schema: the field-type registry is the ten MVP types, each complete',
    function () {
        $all = Form_Field_Types::all();

        Agentshell_Test::assert_equals(
            array( 'text', 'textarea', 'email', 'tel', 'number', 'select', 'radio', 'checkbox', 'consent', 'hidden' ),
            array_keys( $all ),
            'the registry must be exactly the ten documented MVP types, in order'
        );

        foreach ( $all as $name => $decl ) {
            Agentshell_Test::assert_true( is_array( $decl ), $name . ': the declaration must be an array' );
            foreach ( array( 'label', 'multiple', 'has_options', 'settings', 'sanitize', 'validate' ) as $key ) {
                Agentshell_Test::assert_true(
                    array_key_exists( $key, $decl ),
                    $name . ': the declaration must carry "' . $key . '"'
                );
            }
            Agentshell_Test::assert_true( is_callable( $decl['sanitize'] ), $name . ': sanitize must be callable' );
            Agentshell_Test::assert_true( is_callable( $decl['validate'] ), $name . ': validate must be callable' );
            Agentshell_Test::assert_true( is_array( $decl['settings'] ), $name . ': settings must be a declaration' );
        }

        // `multiple` is a declaration the pipeline branches on, not a name it
        // knows. Only the checkbox group stores an array.
        foreach ( $all as $name => $decl ) {
            Agentshell_Test::assert_equals(
                'checkbox' === $name,
                (bool) $decl['multiple'],
                $name . ': only the checkbox group is a multiple-value type'
            );
            Agentshell_Test::assert_equals(
                in_array( $name, array( 'select', 'radio', 'checkbox' ), true ),
                (bool) $decl['has_options'],
                $name . ': only the three choice types declare options'
            );
        }
    }
);

Agentshell_Test::run(
    'forms schema: every declared setting carries a type and a default',
    function () {
        foreach ( Form_Field_Types::all() as $name => $decl ) {
            foreach ( $decl['settings'] as $setting => $spec ) {
                Agentshell_Test::assert_true(
                    isset( $spec['type'] ),
                    $name . '.' . $setting . ': a declared setting must state its type'
                );
                Agentshell_Test::assert_true(
                    array_key_exists( 'default', $spec ),
                    $name . '.' . $setting . ': a declared setting must state its default'
                );
                Agentshell_Test::assert_true(
                    in_array( $spec['type'], array( 'integer', 'number', 'string', 'boolean', 'url' ), true ),
                    $name . '.' . $setting . ': the declared type must be one the validator walks'
                );
            }
        }
    }
);

/* ---------------------------------------------------------------------------
 * A valid document
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms schema: a well-formed definition validates cleanly',
    function () {
        Agentshell_Test::assert_equals(
            array(),
            fs_codes( fs_valid() ),
            'a definition built from the documented defaults must produce no errors'
        );
    }
);

Agentshell_Test::run(
    'forms schema: normalise fills the optional keys without erasing the supplied ones',
    function () {
        $form = Form_Schema::defaults( 'a1b2c3d4e5f60718293a4b5c6d7e8f90', 'contact', 'Contact' );
        $form['fields'] = array( array( 'key' => 'name', 'type' => 'text', 'label' => 'Name' ) );

        $normalised = Form_Schema::normalise( $form );

        Agentshell_Test::assert_equals( 1, $normalised['schema_version'], 'schema_version must be filled' );
        Agentshell_Test::assert_equals( 0, $normalised['version'], 'version must be filled' );
        Agentshell_Test::assert_equals( 'draft', $normalised['status'], 'status must default to draft' );
        Agentshell_Test::assert_true( is_array( $normalised['settings']['spam'] ), 'nested defaults must be filled' );
        Agentshell_Test::assert_equals(
            5,
            $normalised['settings']['spam']['rate_limit']['max'],
            'nested defaults must keep the documented value'
        );
        Agentshell_Test::assert_equals( 'off', $normalised['fields'][0]['settings']['autocomplete'], 'a type setting default must be applied' );
        Agentshell_Test::assert_equals( 120, $normalised['fields'][0]['settings']['max_length'], 'a type setting default must be applied' );
        Agentshell_Test::assert_equals( false, $normalised['fields'][0]['required'], 'required must default to false' );

        // An unknown key is PRESERVED by normalise so that validate() can name
        // it. Normalising it away would turn "the document says something the
        // system does not do" into silence.
        $form['nonsense'] = 'x';
        $normalised = Form_Schema::normalise( $form );
        Agentshell_Test::assert_true( array_key_exists( 'nonsense', $normalised ), 'normalise must not erase an unknown key' );
    }
);

/* ---------------------------------------------------------------------------
 * §5.5 — every top-level refusal, by name
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms schema: an unknown top-level key is refused by name',
    function () {
        $form = fs_valid();
        $form['surprise'] = true;
        Agentshell_Test::assert_true(
            in_array( 'form_unknown_key', fs_codes( $form ), true ),
            'an unknown top-level key must be refused'
        );
        Agentshell_Test::assert_true(
            in_array( 'surprise', fs_paths( $form ), true ),
            'the refusal must name the offending key'
        );
    }
);

Agentshell_Test::run(
    'forms schema: an unknown field key is refused by name',
    function () {
        $form = fs_valid();
        $form['fields'][0]['css_class'] = 'x';
        Agentshell_Test::assert_true(
            in_array( 'form_unknown_field_key', fs_codes( $form ), true ),
            'an unknown key inside a field must be refused'
        );
        Agentshell_Test::assert_true(
            in_array( 'fields.0.css_class', fs_paths( $form ), true ),
            'the refusal must point at the offending field key'
        );
    }
);

Agentshell_Test::run(
    'forms schema: schema_version, form_id and slug are refused when malformed',
    function () {
        $form = fs_valid();
        $form['schema_version'] = 2;
        Agentshell_Test::assert_true(
            in_array( 'form_schema_version_unsupported', fs_codes( $form ), true ),
            'an unsupported schema_version must be refused rather than guessed at'
        );

        foreach ( array( '', 'not-a-uuid', strtoupper( 'a1b2c3d4e5f60718293a4b5c6d7e8f90' ), 'a1b2c3d4e5f60718293a4b5c6d7e8f9' ) as $bad ) {
            $form = fs_valid();
            $form['form_id'] = $bad;
            Agentshell_Test::assert_true(
                in_array( 'form_id_invalid', fs_codes( $form ), true ),
                'form_id "' . $bad . '" must be refused'
            );
        }

        foreach ( array( '', '-leading', 'Has-Caps', 'has_underscore', 'a b' ) as $bad ) {
            $form = fs_valid();
            $form['slug'] = $bad;
            Agentshell_Test::assert_true(
                in_array( 'form_slug_invalid', fs_codes( $form ), true ),
                'slug "' . $bad . '" must be refused'
            );
        }
    }
);

Agentshell_Test::run(
    'forms schema: a slug may not collide with the internal-identifier namespace',
    function () {
        // 32 lowercase hex characters is a form id. A slug that matched it
        // would make the two namespaces ambiguous, and the shortcode resolver
        // could not tell which one a caller meant.
        $form = fs_valid();
        $form['slug'] = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
        Agentshell_Test::assert_true(
            in_array( 'form_slug_invalid', fs_codes( $form ), true ),
            'a slug that looks like a form id must be refused'
        );
    }
);

Agentshell_Test::run(
    'forms schema: a slug is frozen once the form has been published',
    function () {
        $form = fs_valid( array( 'slug' => 'renamed' ) );

        $codes = fs_codes(
            $form,
            array(
                'has_been_published' => true,
                'previous_slug'      => 'contact',
            )
        );
        Agentshell_Test::assert_true(
            in_array( 'form_slug_frozen', $codes, true ),
            'a different slug must be refused once the form has been published'
        );

        $codes = fs_codes(
            $form,
            array(
                'has_been_published' => true,
                'previous_slug'      => 'renamed',
            )
        );
        Agentshell_Test::assert_false(
            in_array( 'form_slug_frozen', $codes, true ),
            'the same slug must remain valid after publication'
        );

        $codes = fs_codes(
            $form,
            array(
                'has_been_published' => false,
                'previous_slug'      => 'contact',
            )
        );
        Agentshell_Test::assert_false(
            in_array( 'form_slug_frozen', $codes, true ),
            'a slug is not frozen before the first publication'
        );
    }
);

Agentshell_Test::run(
    'forms schema: a duplicate slug is refused when the store says it is taken',
    function () {
        $codes = fs_codes(
            fs_valid(),
            array(
                'slug_taken' => function ( $slug ) {
                    return 'contact' === $slug;
                },
            )
        );
        Agentshell_Test::assert_true(
            in_array( 'form_slug_duplicate', $codes, true ),
            'a slug the store reports as taken must be refused'
        );

        $codes = fs_codes(
            fs_valid(),
            array(
                'slug_taken' => function () {
                    return false;
                },
            )
        );
        Agentshell_Test::assert_false(
            in_array( 'form_slug_duplicate', $codes, true ),
            'a free slug must not be refused'
        );
    }
);

Agentshell_Test::run(
    'forms schema: name and description bounds are enforced',
    function () {
        foreach ( array( '', '   ', str_repeat( 'a', 201 ) ) as $bad ) {
            $form = fs_valid();
            $form['name'] = $bad;
            Agentshell_Test::assert_true(
                in_array( 'form_name_invalid', fs_codes( $form ), true ),
                'a name of ' . strlen( $bad ) . ' characters must be refused'
            );
        }

        $form = fs_valid();
        $form['description'] = str_repeat( 'd', 2001 );
        Agentshell_Test::assert_true(
            in_array( 'form_description_too_long', fs_codes( $form ), true ),
            'a 2,001-character description must be refused'
        );

        $form = fs_valid();
        $form['description'] = str_repeat( 'd', 2000 );
        Agentshell_Test::assert_false(
            in_array( 'form_description_too_long', fs_codes( $form ), true ),
            'a 2,000-character description must be accepted'
        );
    }
);

Agentshell_Test::run(
    'forms schema: version and status are refused when out of range',
    function () {
        $form = fs_valid();
        $form['version'] = -1;
        Agentshell_Test::assert_true( in_array( 'form_version_invalid', fs_codes( $form ), true ), 'a negative version must be refused' );

        $form = fs_valid();
        $form['status'] = 'archived';
        Agentshell_Test::assert_true( in_array( 'form_status_invalid', fs_codes( $form ), true ), 'an unknown status must be refused' );
    }
);

/* ---------------------------------------------------------------------------
 * Fields
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms schema: a form needs between one and two hundred fields',
    function () {
        $form = fs_valid();
        $form['fields'] = array();
        Agentshell_Test::assert_true(
            in_array( 'form_fields_count_invalid', fs_codes( $form ), true ),
            'a form with no fields must be refused'
        );

        $form = fs_valid();
        $form['fields'] = 'not a list';
        Agentshell_Test::assert_true(
            in_array( 'form_fields_count_invalid', fs_codes( $form ), true ),
            'a non-list fields value must be refused'
        );

        $many = array();
        for ( $i = 0; $i < 201; $i++ ) {
            $many[] = array( 'key' => 'f' . $i, 'type' => 'text', 'label' => 'Field ' . $i );
        }
        $form = fs_valid();
        $form['fields'] = $many;
        Agentshell_Test::assert_true(
            in_array( 'form_fields_count_invalid', fs_codes( $form ), true ),
            '201 fields must be refused'
        );

        $ok = array();
        for ( $i = 0; $i < 200; $i++ ) {
            $ok[] = array( 'key' => 'f' . $i, 'type' => 'text', 'label' => 'Field ' . $i );
        }
        $form = fs_valid();
        $form['fields'] = $ok;
        Agentshell_Test::assert_false(
            in_array( 'form_fields_count_invalid', fs_codes( $form ), true ),
            '200 fields must be accepted'
        );
    }
);

Agentshell_Test::run(
    'forms schema: field keys, labels and types are refused by name',
    function () {
        foreach ( array( '', 'Name', '1name', 'with space', str_repeat( 'a', 41 ) ) as $bad ) {
            $form = fs_valid();
            $form['fields'][0]['key'] = $bad;
            Agentshell_Test::assert_true(
                in_array( 'form_field_key_invalid', fs_codes( $form ), true ),
                'field key "' . $bad . '" must be refused'
            );
        }

        $form = fs_valid();
        $form['fields'][1]['key'] = 'name';
        Agentshell_Test::assert_true(
            in_array( 'form_field_key_duplicate', fs_codes( $form ), true ),
            'a duplicated field key must be refused'
        );

        foreach ( array( '', '   ', str_repeat( 'l', 201 ) ) as $bad ) {
            $form = fs_valid();
            $form['fields'][0]['label'] = $bad;
            Agentshell_Test::assert_true(
                in_array( 'form_field_label_invalid', fs_codes( $form ), true ),
                'a label of ' . strlen( $bad ) . ' characters must be refused'
            );
        }

        $form = fs_valid();
        $form['fields'][0]['type'] = 'richtext';
        Agentshell_Test::assert_true(
            in_array( 'form_field_type_unknown', fs_codes( $form ), true ),
            'an unregistered field type must be refused rather than rendered as nothing'
        );
    }
);

Agentshell_Test::run(
    'forms schema: field description and placeholder bounds are enforced',
    function () {
        $form = fs_valid();
        $form['fields'][0]['description'] = str_repeat( 'd', 1001 );
        Agentshell_Test::assert_true(
            in_array( 'form_field_text_too_long', fs_codes( $form ), true ),
            'a 1,001-character field description must be refused'
        );

        $form = fs_valid();
        $form['fields'][0]['placeholder'] = str_repeat( 'p', 201 );
        Agentshell_Test::assert_true(
            in_array( 'form_field_text_too_long', fs_codes( $form ), true ),
            'a 201-character placeholder must be refused'
        );
    }
);

Agentshell_Test::run(
    'forms schema: required must be a boolean, not a truthy string',
    function () {
        $form = fs_valid();
        $form['fields'][0]['required'] = 'yes';
        Agentshell_Test::assert_true(
            in_array( 'form_field_invalid', fs_codes( $form ), true ),
            'a non-boolean required must be refused'
        );
    }
);

/* ---------------------------------------------------------------------------
 * The settings walk — the property that stops the validator, the builder and
 * the renderer drifting apart
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms schema: a setting the type does not declare is refused',
    function () {
        $form = fs_valid();
        $form['fields'][0]['settings']['rows'] = 4; // text does not declare `rows`
        Agentshell_Test::assert_true(
            in_array( 'form_field_setting_invalid', fs_codes( $form ), true ),
            'an undeclared setting must be refused'
        );
        Agentshell_Test::assert_true(
            in_array( 'fields.0.settings.rows', fs_paths( $form ), true ),
            'the refusal must point at the offending setting'
        );

        // The same value IS declared by textarea.
        $form = fs_valid();
        $form['fields'][2]['settings']['rows'] = 4;
        Agentshell_Test::assert_false(
            in_array( 'form_field_setting_invalid', fs_codes( $form ), true ),
            'the same setting must be accepted by the type that declares it'
        );
    }
);

Agentshell_Test::run(
    'forms schema: a declared setting is checked against its bounds and its type',
    function () {
        $cases = array(
            array( 'max_length', 1001, 'an integer above max' ),
            array( 'max_length', 0, 'an integer below min' ),
            array( 'max_length', '120', 'a string where an integer is declared' ),
            array( 'autocomplete', 'favourite-colour', 'a value outside the enum' ),
        );

        foreach ( $cases as $case ) {
            $form = fs_valid();
            $form['fields'][0]['settings'][ $case[0] ] = $case[1];
            Agentshell_Test::assert_true(
                in_array( 'form_field_setting_invalid', fs_codes( $form ), true ),
                'text.' . $case[0] . ' with ' . $case[2] . ' must be refused'
            );
        }

        $form = fs_valid();
        $form['fields'][0]['settings']['max_length'] = 1000;
        Agentshell_Test::assert_false(
            in_array( 'form_field_setting_invalid', fs_codes( $form ), true ),
            'an integer at the declared maximum must be accepted'
        );
    }
);

Agentshell_Test::run(
    'forms schema: options are required exactly for the types that declare them',
    function () {
        $form = fs_valid();
        $form['fields'][0]['options'] = array( array( 'value' => 'a', 'label' => 'A' ) );
        Agentshell_Test::assert_true(
            in_array( 'form_field_options_invalid', fs_codes( $form ), true ),
            'a text field carrying options must be refused'
        );

        foreach ( array( 'select', 'radio', 'checkbox' ) as $type ) {
            $field = fs_field( $type );
            $field['options'] = array();
            $form  = fs_valid();
            $form['fields'][] = $field;
            Agentshell_Test::assert_true(
                in_array( 'form_field_options_invalid', fs_codes( $form ), true ),
                $type . ': a choice field with no options must be refused'
            );
        }
    }
);

Agentshell_Test::run(
    'forms schema: option values and labels are validated',
    function () {
        $cases = array(
            array( array( 'value' => 'Bad Value', 'label' => 'B' ), 'a value with a space and capitals' ),
            array( array( 'value' => '', 'label' => 'B' ), 'an empty value' ),
            array( array( 'value' => 'ok', 'label' => '' ), 'an empty label' ),
            array( array( 'value' => 'ok', 'label' => str_repeat( 'l', 201 ) ), 'a label over the bound' ),
        );

        foreach ( $cases as $case ) {
            $field = fs_field( 'select' );
            $field['options'] = array( $case[0] );
            $form  = fs_valid();
            $form['fields'][] = $field;
            Agentshell_Test::assert_true(
                in_array( 'form_field_options_invalid', fs_codes( $form ), true ),
                'an option with ' . $case[1] . ' must be refused'
            );
        }

        $field = fs_field( 'select' );
        $field['options'] = array(
            array( 'value' => 'dup', 'label' => 'One' ),
            array( 'value' => 'dup', 'label' => 'Two' ),
        );
        $form = fs_valid();
        $form['fields'][] = $field;
        Agentshell_Test::assert_true(
            in_array( 'form_field_options_invalid', fs_codes( $form ), true ),
            'a duplicated option value must be refused'
        );
    }
);

/* ---------------------------------------------------------------------------
 * Settings blocks
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms schema: the reply-to field must name an email field',
    function () {
        $form = fs_valid();
        $form['settings']['notification']['reply_to_field'] = 'name';
        Agentshell_Test::assert_true(
            in_array( 'form_notification_reply_to_invalid', fs_codes( $form ), true ),
            'reply_to_field naming a text field must be refused'
        );

        $form = fs_valid();
        $form['settings']['notification']['reply_to_field'] = 'email';
        Agentshell_Test::assert_false(
            in_array( 'form_notification_reply_to_invalid', fs_codes( $form ), true ),
            'reply_to_field naming an email field must be accepted'
        );

        $form = fs_valid();
        $form['settings']['notification']['reply_to_field'] = 'missing';
        Agentshell_Test::assert_true(
            in_array( 'form_notification_reply_to_invalid', fs_codes( $form ), true ),
            'reply_to_field naming a field that does not exist must be refused'
        );
    }
);

Agentshell_Test::run(
    'forms schema: a redirect must stay on this site',
    function () {
        $context = array( 'site_host' => 'example.test' );

        foreach ( array( '/thank-you/', 'http://example.test/thanks', 'https://www.example.test/thanks' ) as $good ) {
            $form = fs_valid();
            $form['settings']['redirect_url'] = $good;
            Agentshell_Test::assert_false(
                in_array( 'form_redirect_url_invalid', fs_codes( $form, $context ), true ),
                'the on-site redirect "' . $good . '" must be accepted'
            );
        }

        foreach ( array( 'http://evil.example/thanks', 'javascript:alert(1)', 'example.test/thanks' ) as $bad ) {
            $form = fs_valid();
            $form['settings']['redirect_url'] = $bad;
            Agentshell_Test::assert_true(
                in_array( 'form_redirect_url_invalid', fs_codes( $form, $context ), true ),
                'the off-site redirect "' . $bad . '" must be refused'
            );
        }
    }
);

Agentshell_Test::run(
    'forms schema: notification recipients are drawn from the allowed set',
    function () {
        $context = array( 'site_host' => 'example.test' );

        $form = fs_valid();
        $form['settings']['notification']['recipients'] = array( 'site-admin', 'desk@example.test' );
        Agentshell_Test::assert_false(
            in_array( 'form_notification_recipients_invalid', fs_codes( $form, $context ), true ),
            'the site administrator and an on-site address must be accepted'
        );

        $form = fs_valid();
        $form['settings']['notification']['recipients'] = array( 'someone@elsewhere.example' );
        Agentshell_Test::assert_true(
            in_array( 'form_notification_recipients_invalid', fs_codes( $form, $context ), true ),
            'an off-site recipient must be refused so a form is not an open relay'
        );

        $form = fs_valid();
        $form['settings']['notification']['recipients'] = array( 'someone@elsewhere.example' );
        Agentshell_Test::assert_false(
            in_array(
                'form_notification_recipients_invalid',
                fs_codes( $form, array( 'site_host' => 'example.test', 'allowed_recipients' => array( 'someone@elsewhere.example' ) ) ),
                true
            ),
            'a recipient explicitly allowed by the site must be accepted'
        );
    }
);

Agentshell_Test::run(
    'forms schema: notification templates are bounded',
    function () {
        $form = fs_valid();
        $form['settings']['notification']['body_template'] = str_repeat( 'x', 8001 );
        Agentshell_Test::assert_true(
            in_array( 'form_notification_template_too_large', fs_codes( $form ), true ),
            'an 8,001-character template must be refused'
        );

        $form = fs_valid();
        $form['settings']['notification']['body_template'] = str_repeat( 'x', 8000 );
        Agentshell_Test::assert_false(
            in_array( 'form_notification_template_too_large', fs_codes( $form ), true ),
            'an 8,000-character template must be accepted'
        );
    }
);

Agentshell_Test::run(
    'forms schema: the spam, storage and privacy blocks are bounded',
    function () {
        $cases = array(
            array( array( 'spam', 'turnstile' ), 'sometimes', 'form_spam_turnstile_invalid', 'an unknown turnstile policy' ),
            array( array( 'spam', 'rate_limit', 'max' ), 0, 'form_spam_rate_limit_invalid', 'a rate-limit max below 1' ),
            array( array( 'spam', 'rate_limit', 'max' ), 101, 'form_spam_rate_limit_invalid', 'a rate-limit max above 100' ),
            array( array( 'spam', 'rate_limit', 'window' ), 29, 'form_spam_rate_limit_invalid', 'a window below 30 seconds' ),
            array( array( 'spam', 'rate_limit', 'window' ), 86401, 'form_spam_rate_limit_invalid', 'a window above a day' ),
            array( array( 'spam', 'min_seconds' ), 3601, 'form_spam_rate_limit_invalid', 'a minimum fill time above an hour' ),
            array( array( 'storage', 'retain_days' ), 3651, 'form_storage_retention_invalid', 'a retention above ten years' ),
            array( array( 'storage', 'retain_days' ), -1, 'form_storage_retention_invalid', 'a negative retention' ),
            array( array( 'privacy', 'lawful_basis' ), 'vibes', 'form_privacy_basis_invalid', 'an unknown lawful basis' ),
            array( array( 'privacy', 'privacy_notice_url' ), 'not a url', 'form_privacy_notice_url_invalid', 'a malformed privacy URL' ),
            array( array( 'submit_label' ), '', 'form_submit_label_invalid', 'an empty submit label' ),
            array( array( 'success_message' ), str_repeat( 's', 1001 ), 'form_success_message_invalid', 'an over-long success message' ),
        );

        foreach ( $cases as $case ) {
            $form = fs_valid();
            $path = $case[0];
            if ( count( $path ) === 1 ) {
                $form['settings'][ $path[0] ] = $case[1];
            } elseif ( count( $path ) === 2 ) {
                $form['settings'][ $path[0] ][ $path[1] ] = $case[1];
            } else {
                $form['settings'][ $path[0] ][ $path[1] ][ $path[2] ] = $case[1];
            }
            Agentshell_Test::assert_true(
                in_array( $case[2], fs_codes( $form ), true ),
                $case[3] . ' must be refused (' . $case[2] . ')'
            );
        }

        // The documented bounds themselves must be accepted.
        $form = fs_valid();
        $form['settings']['spam']['rate_limit'] = array( 'max' => 100, 'window' => 86400 );
        $form['settings']['storage']['retain_days'] = 3650;
        $form['settings']['privacy']['lawful_basis'] = 'consent';
        Agentshell_Test::assert_equals(
            array(),
            fs_codes( $form ),
            'the documented bounds must be accepted at their limits'
        );
    }
);

Agentshell_Test::run(
    'forms schema: unknown keys inside the settings blocks are refused',
    function () {
        $cases = array(
            array( 'settings', 'unknown' ),
            array( 'settings.notification', 'unknown' ),
            array( 'settings.notification.confirmation', 'unknown' ),
            array( 'settings.spam', 'unknown' ),
            array( 'settings.spam.rate_limit', 'unknown' ),
            array( 'settings.storage', 'unknown' ),
            array( 'settings.privacy', 'unknown' ),
        );

        foreach ( $cases as $case ) {
            $form = fs_valid();
            if ( 'settings' === $case[0] ) {
                $form['settings'][ $case[1] ] = 1;
            } elseif ( 'settings.notification' === $case[0] ) {
                $form['settings']['notification'][ $case[1] ] = 1;
            } elseif ( 'settings.notification.confirmation' === $case[0] ) {
                $form['settings']['notification']['confirmation'][ $case[1] ] = 1;
            } elseif ( 'settings.spam' === $case[0] ) {
                $form['settings']['spam'][ $case[1] ] = 1;
            } elseif ( 'settings.spam.rate_limit' === $case[0] ) {
                $form['settings']['spam']['rate_limit'][ $case[1] ] = 1;
            } elseif ( 'settings.storage' === $case[0] ) {
                $form['settings']['storage'][ $case[1] ] = 1;
            } else {
                $form['settings']['privacy'][ $case[1] ] = 1;
            }

            Agentshell_Test::assert_true(
                in_array( 'form_unknown_key', fs_codes( $form ), true ),
                'an unknown key in ' . $case[0] . ' must be refused'
            );
        }
    }
);

/* ---------------------------------------------------------------------------
 * The per-type sanitise and validate contract
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms field types: text, textarea and email length and shape are enforced',
    function () {
        $text = Form_Field_Types::get( 'text' );

        Agentshell_Test::assert_equals( 'trimmed', call_user_func( $text['sanitize'], '  trimmed  ' ), 'text must be trimmed' );
        Agentshell_Test::assert_true(
            call_user_func( $text['validate'], str_repeat( 'a', 121 ), array( 'max_length' => 120, 'min_length' => 0 ) ) instanceof WP_Error,
            'text over max_length must be refused'
        );
        Agentshell_Test::assert_true(
            true === call_user_func( $text['validate'], str_repeat( 'a', 120 ), array( 'max_length' => 120, 'min_length' => 0 ) ),
            'text at max_length must be accepted'
        );

        $email = Form_Field_Types::get( 'email' );
        Agentshell_Test::assert_true(
            true === call_user_func( $email['validate'], 'person@example.test', array( 'max_length' => 254 ) ),
            'a plausible email must be accepted'
        );
        Agentshell_Test::assert_true(
            call_user_func( $email['validate'], 'not-an-email', array( 'max_length' => 254 ) ) instanceof WP_Error,
            'a string with no @ must be refused'
        );
        Agentshell_Test::assert_true(
            true === call_user_func( $email['validate'], '', array( 'max_length' => 254 ) ),
            'an empty value must be left to the required rule, not refused as malformed'
        );
    }
);

Agentshell_Test::run(
    'forms field types: tel and number are permissive about shape and strict about range',
    function () {
        $tel = Form_Field_Types::get( 'tel' );
        foreach ( array( '+44 20 7946 0000', '(555) 010-9999', '555.010.9999' ) as $good ) {
            Agentshell_Test::assert_true(
                true === call_user_func( $tel['validate'], $good, array( 'max_length' => 40 ) ),
                'the telephone number "' . $good . '" must be accepted'
            );
        }
        Agentshell_Test::assert_true(
            call_user_func( $tel['validate'], 'call me maybe', array( 'max_length' => 40 ) ) instanceof WP_Error,
            'a telephone value with letters must be refused'
        );

        $number = Form_Field_Types::get( 'number' );
        Agentshell_Test::assert_true(
            call_user_func( $number['validate'], 'many', array( 'min' => null, 'max' => null ) ) instanceof WP_Error,
            'a non-numeric value must be refused'
        );
        Agentshell_Test::assert_true(
            call_user_func( $number['validate'], '4', array( 'min' => 5, 'max' => 10 ) ) instanceof WP_Error,
            'a value below min must be refused'
        );
        Agentshell_Test::assert_true(
            call_user_func( $number['validate'], '11', array( 'min' => 5, 'max' => 10 ) ) instanceof WP_Error,
            'a value above max must be refused'
        );
        Agentshell_Test::assert_true(
            true === call_user_func( $number['validate'], '7.5', array( 'min' => 5, 'max' => 10 ) ),
            'a value inside the range must be accepted'
        );
    }
);

Agentshell_Test::run(
    'forms field types: choice types enforce option membership',
    function () {
        $options = array(
            array( 'value' => 'one', 'label' => 'One' ),
            array( 'value' => 'two', 'label' => 'Two' ),
        );

        foreach ( array( 'select', 'radio' ) as $type ) {
            $decl = Form_Field_Types::get( $type );
            Agentshell_Test::assert_true(
                true === call_user_func( $decl['validate'], 'one', array(), $options ),
                $type . ': a declared option must be accepted'
            );
            Agentshell_Test::assert_true(
                call_user_func( $decl['validate'], 'three', array(), $options ) instanceof WP_Error,
                $type . ': an undeclared option must be refused'
            );
            Agentshell_Test::assert_true(
                true === call_user_func( $decl['validate'], '', array(), $options ),
                $type . ': an empty value must be left to the required rule'
            );
        }

        $checkbox = Form_Field_Types::get( 'checkbox' );
        Agentshell_Test::assert_true(
            true === call_user_func( $checkbox['validate'], array( 'one', 'two' ), array(), $options ),
            'checkbox: declared options must be accepted'
        );
        Agentshell_Test::assert_true(
            call_user_func( $checkbox['validate'], array( 'one', 'three' ), array(), $options ) instanceof WP_Error,
            'checkbox: an undeclared option must be refused'
        );
        Agentshell_Test::assert_true(
            call_user_func( $checkbox['validate'], 'one', array(), $options ) instanceof WP_Error,
            'checkbox: a scalar where a list is declared must be refused'
        );
        Agentshell_Test::assert_equals(
            array( 'one', 'two' ),
            call_user_func( $checkbox['sanitize'], array( 'one', 'two', 'one' ) ),
            'checkbox: sanitise must remove duplicates and re-index'
        );
    }
);

Agentshell_Test::run(
    'forms field types: consent stores a boolean and refuses anything but true',
    function () {
        $consent = Form_Field_Types::get( 'consent' );

        Agentshell_Test::assert_equals( false, call_user_func( $consent['sanitize'], '' ), 'an absent consent must be stored as false, not as absence' );
        Agentshell_Test::assert_equals( true, call_user_func( $consent['sanitize'], '1' ), 'a supplied consent must be stored as true' );
        Agentshell_Test::assert_true(
            true === call_user_func( $consent['validate'], true, array() ),
            'a supplied consent must validate'
        );
        Agentshell_Test::assert_true(
            call_user_func( $consent['validate'], false, array() ) instanceof WP_Error,
            'an absent consent must be refused, because the basis is consent'
        );
    }
);

Agentshell_Test::run(
    'forms field types: a hidden field never takes the request value',
    function () {
        $hidden = Form_Field_Types::get( 'hidden' );

        // §5.4: a hidden field's value is always taken from the definition.
        // Returning the request's value here would be a spoofable field.
        Agentshell_Test::assert_equals(
            '',
            call_user_func( $hidden['sanitize'], 'attacker-supplied' ),
            'hidden: sanitise must discard the request value'
        );
    }
);

Agentshell_Test::run(
    'forms field types: a type registered through the filter is validated by the walk',
    function () {
        $filter = function ( $types ) {
            $types['rating'] = array(
                'label'       => 'Rating',
                'multiple'    => false,
                'has_options' => false,
                'settings'    => array(
                    'max' => array( 'type' => 'integer', 'min' => 1, 'max' => 10, 'default' => 5 ),
                ),
                'sanitize'    => function ( $value ) { return (int) $value; },
                'validate'    => function () { return true; },
            );
            return $types;
        };

        add_filter( 'agentshell_form_field_types', $filter );
        Form_Field_Types::reset();

        $names = Form_Field_Types::names();
        Agentshell_Test::assert_true( in_array( 'rating', $names, true ), 'a filtered type must appear in the registry' );

        // The generic validator walks the declaration, so the new type gets
        // its bounds checked without the validator being edited.
        $form = fs_valid();
        $form['fields'][] = array(
            'key'      => 'rating',
            'type'     => 'rating',
            'label'    => 'How was it?',
            'settings' => array( 'max' => 99 ),
            'options'  => array(),
        );
        Agentshell_Test::assert_true(
            in_array( 'form_field_setting_invalid', fs_codes( $form ), true ),
            'a filtered type must get its declared bounds enforced by the settings walk'
        );

        remove_filter( 'agentshell_form_field_types', $filter );
        Form_Field_Types::reset();

        Agentshell_Test::assert_false(
            in_array( 'rating', Form_Field_Types::names(), true ),
            'resetting the registry must remove the filtered type'
        );
    }
);

/* ---------------------------------------------------------------------------
 * The documented PHP API (§4.3)
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms api: the public surface reports the registry and the verdict',
    function () {
        Agentshell_Test::assert_equals(
            array_keys( Form_Field_Types::all() ),
            array_keys( agentshell_forms_field_types() ),
            'agentshell_forms_field_types() must be the filtered registry'
        );

        $good = agentshell_forms_validate( fs_valid() );
        Agentshell_Test::assert_true( true === ( $good['ok'] ?? false ), 'a valid definition must validate as ok' );
        Agentshell_Test::assert_equals( array(), $good['errors'] ?? null, 'a valid definition must carry no errors' );
        Agentshell_Test::assert_true( is_array( $good['warnings'] ?? null ), 'the verdict must always carry a warnings key' );

        $bad = agentshell_forms_validate( array( 'nonsense' => true ) );
        Agentshell_Test::assert_false( (bool) ( $bad['ok'] ?? true ), 'an invalid definition must not validate as ok' );
        Agentshell_Test::assert_true(
            in_array( 'form_unknown_key', array_map( function ( $e ) { return (string) $e['code']; }, (array) ( $bad['errors'] ?? array() ) ), true ),
            'the refusal must name the unknown key'
        );

        // The API normalises before validating, so a caller may supply a
        // partial document and still get a meaningful verdict.
        $partial = agentshell_forms_validate(
            array(
                'form_id' => '7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a',
                'slug'    => 'partial',
                'name'    => 'Partial',
                'fields'  => array( array( 'key' => 'name', 'type' => 'text', 'label' => 'Name' ) ),
            )
        );
        Agentshell_Test::assert_true( true === ( $partial['ok'] ?? false ), 'a partial but valid document must validate: ' . wp_json_encode( $partial['errors'] ?? array() ) );
    }
);

/* ---------------------------------------------------------------------------
 * Independence
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms independence: the plugin depends on nothing in AgentShell',
    function () {
        $root = dirname( __DIR__ ) . '/agentshell-forms';
        $files = array();

        $walk = function ( $dir ) use ( &$walk, &$files ) {
            foreach ( (array) scandir( $dir ) as $item ) {
                if ( '.' === $item || '..' === $item ) {
                    continue;
                }
                $path = $dir . '/' . $item;
                if ( is_dir( $path ) ) {
                    $walk( $path );
                    continue;
                }
                if ( 'php' === strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
                    $files[] = $path;
                }
            }
        };
        $walk( $root );

        Agentshell_Test::assert_true( count( $files ) >= 10, 'the plugin must have been found on disk' );

        // Symbols only. The plugin's docblocks talk about AgentShell on purpose —
        // independence is a property of what the code *calls*, not of what it
        // explains.
        $forbidden = array(
            'AgentShell_MCP',
            'AgentShell_Blocks',
            'agentshell_config',
            'agentshell_get_config',
            'agentshell_mcp_register_tools',
            'get_template_directory',
            'get_stylesheet_directory',
            'wp_get_theme(',
        );

        foreach ( $files as $file ) {
            $source = (string) file_get_contents( $file );
            foreach ( $forbidden as $needle ) {
                Agentshell_Test::assert_false(
                    false !== strpos( $source, $needle ),
                    basename( $file ) . ' must not reference ' . $needle
                );
            }
        }

        // The only global functions the plugin may define are its documented
        // API, and every one of them is prefixed. Everything else lives in the
        // AgentShell_Forms namespace, so an internal rename cannot break a
        // caller and a caller cannot reach an internal by accident.
        $documented = array(
            'agentshell_forms_field_types',
            'agentshell_forms_validate',
            'agentshell_forms_get_working',
            'agentshell_forms_get_published',
            // Phase 2's renderer, per §4.3 of the design.
            'agentshell_forms_render',
        );

        foreach ( $files as $file ) {
            $source = (string) file_get_contents( $file );
            if ( false !== preg_match( '/^namespace\s+/m', $source ) ) {
                continue; // namespaced: not global surface
            }
            preg_match_all( '/^function\s+([a-zA-Z0-9_]+)\s*\(/m', $source, $matches );
            foreach ( (array) ( $matches[1] ?? array() ) as $name ) {
                Agentshell_Test::assert_true(
                    in_array( $name, $documented, true ),
                    basename( $file ) . ' defines an undocumented global function: ' . $name
                );
            }
        }

        // The plugin registers no MCP tool. It renders for a visitor (Phase 2),
        // it accepts a submission and sends mail (Phase 3), and each of those
        // is a named, single place rather than something any file may do. A
        // second shortcode, a second mail call site or a REST route outside the
        // three named surfaces (mint, submission, builder) would be a public
        // surface nobody decided to add.
        $shortcode_files = array();
        $mail_files      = array();
        $route_files     = array();

        foreach ( $files as $file ) {
            $source = (string) file_get_contents( $file );

            // Comments are stripped first. The docblocks in this plugin talk
            // about `wp_mail()` and `wp_remote_post()` on purpose — explaining
            // why a call is in one place and not another is the documentation's
            // job — and a scan that counted a mention as a call would force the
            // documentation to stop being accurate.
            $code = (string) preg_replace( '#/\*.*?\*/#s', '', $source );
            $code = (string) preg_replace( '#^\s*//.*$#m', '', $code );

            if ( false !== strpos( $code, 'add_shortcode(' ) ) {
                $shortcode_files[] = basename( $file );
            }

            if ( false !== strpos( $code, 'wp_mail(' ) ) {
                $mail_files[] = basename( $file );
            }

            if ( false !== strpos( $code, 'register_rest_route(' ) ) {
                $route_files[] = basename( $file );
            }

            // The submission path must never reach the network directly: the
            // only outbound request the plugin may make is Turnstile's, and it
            // is made from one file against one hard-coded endpoint.
            if ( false !== strpos( $code, 'wp_remote_post(' ) ) {
                Agentshell_Test::assert_equals(
                    'class-turnstile.php',
                    basename( $file ),
                    basename( $file ) . ' must not make an outbound request outside the Turnstile client'
                );
            }
        }

        Agentshell_Test::assert_equals(
            array( 'class-form-shortcode.php' ),
            $shortcode_files,
            'exactly one file registers the shortcode'
        );

        Agentshell_Test::assert_equals(
            array( 'class-form-notifier.php' ),
            $mail_files,
            'exactly one file sends mail'
        );

        $expected_routes = array( 'class-form-challenge-endpoint.php', 'class-form-rest.php', 'class-submission-handler.php' );
        sort( $expected_routes );
        sort( $route_files );

        Agentshell_Test::assert_equals(
            $expected_routes,
            $route_files,
            'exactly three files register a REST route: the mint, the submission and the builder controller'
        );
    }
);
