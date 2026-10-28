<?php
/**
 * Notification composition, in the stub bootstrap.
 *
 * The lifecycle — claims, retries, exhaustion, an uncertain outcome — needs
 * real rows and real `wp_mail()` behaviour, and is
 * `tests/forms-notification-live-test.php`. This suite pins the half that can
 * be decided from a definition and a set of values: what a message says, who
 * it may go to, and which header it may carry.
 *
 * The properties worth reading the assertions for:
 *
 *   - a subject can never carry a header, however the visitor types;
 *   - the body is plain text, and a control character is removed;
 *   - `Reply-To` is only ever a submitted address that is genuinely an email
 *     address, and only from a field the form declares as one;
 *   - recipients are an allowlist — the site's administration address, or an
 *     address on the site's own domain — and never an arbitrary visitor value;
 *   - the attempt budget can never be longer than the claim lifetime, because
 *     that is the window in which two workers could both send.
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '' ) {
        return 'https://example.test' . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
    }
}

if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) {
        return parse_url( $url, $component );
    }
}

if ( ! function_exists( 'is_email' ) ) {
    /**
     * Deliberately strict about the two things this suite asserts: an address
     * has one `@` with something on each side, and no whitespace or control
     * character anywhere. WordPress's own check is stricter still; what
     * matters here is that a header-injection attempt is refused.
     */
    function is_email( $email ) {
        $email = (string) $email;

        if ( '' === $email || preg_match( '/[\s\x00-\x1F\x7F]/', $email ) ) {
            return false;
        }

        return 1 === preg_match( '/^[^@]+@[^@.]+(\.[^@.]+)+$/', $email );
    }
}

require_once dirname( __DIR__ ) . '/agentshell-forms/includes/class-form-notifier.php';

use AgentShell_Forms\Form_Notifier;

echo "forms notifications (unit)\n";

/**
 * A definition with notification settings.
 *
 * @param array $notification Overrides.
 * @param array $fields       Fields, or null for an email field.
 * @return array
 */
function fnu_definition( array $notification = array(), $fields = null ) {
    $base = array(
        'form_id'  => '7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a',
        'name'     => 'Contact',
        'version'  => 3,
        'fields'   => null === $fields ? fnu_fields() : $fields,
        'settings' => array(
            'notification' => array_merge(
                array(
                    'enabled'         => true,
                    'recipients'      => array( 'site-admin' ),
                    'subject'         => 'New submission: {form:name}',
                    'body_template'   => "From {field:name}\n\n{fields}",
                    'reply_to_field'  => 'email',
                    'confirmation'    => array( 'enabled' => false, 'subject' => '', 'body_template' => '' ),
                ),
                $notification
            ),
        ),
    );

    return $base;
}

/**
 * The field list: one email, one text, one checkbox group.
 *
 * @return array
 */
function fnu_fields() {
    return array(
        array(
            'key'      => 'name',
            'type'     => 'text',
            'label'    => 'Your name',
            'required' => true,
            'settings' => array( 'max_length' => 120, 'min_length' => 0, 'autocomplete' => 'name' ),
            'options'  => array(),
        ),
        array(
            'key'      => 'email',
            'type'     => 'email',
            'label'    => 'Your email',
            'required' => true,
            'settings' => array( 'max_length' => 254, 'autocomplete' => 'email' ),
            'options'  => array(),
        ),
        array(
            'key'      => 'topics',
            'type'     => 'checkbox',
            'label'    => 'Topics',
            'required' => false,
            'settings' => array(),
            'options'  => array(
                array( 'value' => 'design', 'label' => 'Design' ),
                array( 'value' => 'build', 'label' => 'Build' ),
            ),
        ),
    );
}

/**
 * Values for the field list.
 *
 * @param array $overrides Overrides.
 * @return array
 */
function fnu_values( array $overrides = array() ) {
    return array_merge(
        array(
            'name'   => "Ada Lovelace",
            'email'  => 'ada@example.invalid',
            'topics' => array( 'design', 'build' ),
        ),
        $overrides
    );
}

// ══ composition ══════════════════════════════════════════════════════════════

Agentshell_Test::run(
    'a composed notification carries the rendered subject, every field and a Message-ID',
    function () {
        update_option( 'admin_email', 'owner@example.test' );

        $message = Form_Notifier::compose( 'admin', fnu_definition(), 42, fnu_values() );

        Agentshell_Test::assert_true( is_array( $message ), 'the message must compose' );
        Agentshell_Test::assert_equals( array( 'owner@example.test' ), $message['to'], '`site-admin` resolves to the administration address' );
        Agentshell_Test::assert_equals( 'New submission: Contact', $message['subject'], 'the subject template is rendered' );
        Agentshell_Test::assert_true( false !== strpos( $message['body'], 'Ada Lovelace' ), 'the body carries a submitted value' );
        Agentshell_Test::assert_true( false !== strpos( $message['body'], 'Your name: Ada Lovelace' ), '`{fields}` renders a labelled line per field' );
        Agentshell_Test::assert_true( false !== strpos( $message['body'], 'Topics: design, build' ), 'a multiple value is joined' );
        Agentshell_Test::assert_true(
            1 === preg_match( '/^<agentshell-forms-42-admin-[a-f0-9]+@example\.test>$/', $message['message_id'] ),
            'the Message-ID names the submission, the kind and the site'
        );
        Agentshell_Test::assert_equals(
            'Message-ID: ' . $message['message_id'],
            $message['headers'][0],
            'the first header is the Message-ID'
        );
    }
);

Agentshell_Test::run(
    'a subject cannot carry a header, however it was typed',
    function () {
        $definition = fnu_definition( array( 'subject' => 'Hello {field:name}' ) );

        $message = Form_Notifier::compose(
            'admin',
            $definition,
            1,
            fnu_values( array( 'name' => "Ada\r\nBcc: victim@example.org" ) )
        );

        Agentshell_Test::assert_false( false !== strpos( $message['subject'], "\r" ), 'a carriage return is removed from the subject' );
        Agentshell_Test::assert_false( false !== strpos( $message['subject'], "\n" ), 'a line feed is removed from the subject' );
        Agentshell_Test::assert_false(
            0 === stripos( $message['subject'], 'Bcc' ),
            'no injected header can begin the subject'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $message['subject'], 'Bcc: victim@example.org' ),
            'the text survives as text, which is the honest treatment'
        );
    }
);

Agentshell_Test::run(
    'the body is plain text and loses control characters but keeps its structure',
    function () {
        $definition = fnu_definition( array( 'body_template' => "Line one\nLine two\n{field:name}" ) );

        $message = Form_Notifier::compose(
            'admin',
            $definition,
            1,
            fnu_values( array( 'name' => "Ada\x00\x07 Lovelace" ) )
        );

        Agentshell_Test::assert_true( false !== strpos( $message['body'], "Line one\nLine two" ), 'newlines are preserved' );
        Agentshell_Test::assert_false( false !== strpos( $message['body'], "\x00" ), 'a NUL is removed' );
        Agentshell_Test::assert_false( false !== strpos( $message['body'], "\x07" ), 'a bell is removed' );
        Agentshell_Test::assert_true( false !== strpos( $message['body'], 'Ada Lovelace' ), 'the rest of the value survives' );
        Agentshell_Test::assert_false( false !== strpos( $message['body'], '<' ), 'no HTML is emitted' );
    }
);

Agentshell_Test::run(
    'an unknown placeholder renders as nothing rather than as its own name',
    function () {
        $definition = fnu_definition( array( 'body_template' => 'A[{field:missing}]B[{field:not a key}]C' ) );

        $message = Form_Notifier::compose( 'admin', $definition, 1, fnu_values() );

        Agentshell_Test::assert_equals( 'A[]B[{field:not a key}]C', $message['body'], 'a known-shaped key renders empty; a non-key is left alone' );
    }
);

// ══ recipients ═══════════════════════════════════════════════════════════════

Agentshell_Test::run(
    'recipients are an allowlist: the site address or the site’s own domain',
    function () {
        update_option( 'admin_email', 'owner@example.test' );

        $definition = fnu_definition(
            array(
                'recipients' => array(
                    'site-admin',
                    'team@example.test',
                    'desk@mail.example.test',
                    'attacker@evil.example',
                    'not-an-address',
                    '',
                ),
            )
        );

        Agentshell_Test::assert_equals(
            array( 'owner@example.test', 'team@example.test', 'desk@mail.example.test' ),
            Form_Notifier::recipients( $definition ),
            'only the site address and addresses on the site’s own domain survive'
        );
    }
);

Agentshell_Test::run(
    'a recipient list is bounded, and duplicates collapse',
    function () {
        $many = array( 'site-admin' );

        for ( $i = 0; $i < 20; $i++ ) {
            $many[] = 'person' . $i . '@example.test';
        }

        $many[] = 'person0@example.test';

        $definition = fnu_definition( array( 'recipients' => $many ) );
        $resolved   = Form_Notifier::recipients( $definition );

        Agentshell_Test::assert_true( count( $resolved ) <= 10, 'no notification may name more than ten recipients' );
        Agentshell_Test::assert_equals(
            count( $resolved ),
            count( array_unique( $resolved ) ),
            'a duplicated recipient appears once'
        );
    }
);

Agentshell_Test::run(
    'an enabled notification with no usable recipient is a composition failure, not a send',
    function () {
        $definition = fnu_definition( array( 'recipients' => array( 'attacker@evil.example' ) ) );
        $message    = Form_Notifier::compose( 'admin', $definition, 1, fnu_values() );

        Agentshell_Test::assert_true( is_wp_error( $message ), 'the message must not compose' );
        Agentshell_Test::assert_equals( 'form_notification_no_recipient', $message->get_error_code(), 'the reason is named' );
    }
);

// ══ Reply-To ═════════════════════════════════════════════════════════════════

Agentshell_Test::run(
    'Reply-To is a submitted address only when the field is an email field',
    function () {
        update_option( 'admin_email', 'owner@example.test' );

        $message = Form_Notifier::compose( 'admin', fnu_definition(), 1, fnu_values() );
        Agentshell_Test::assert_true(
            in_array( 'Reply-To: ada@example.invalid', $message['headers'], true ),
            'the declared email field supplies Reply-To'
        );

        // A field that is not an email field never supplies it, even if its
        // value looks like an address.
        $definition = fnu_definition( array( 'reply_to_field' => 'name' ) );
        $message    = Form_Notifier::compose( 'admin', $definition, 1, fnu_values( array( 'name' => 'ada@example.invalid' ) ) );

        foreach ( $message['headers'] as $header ) {
            Agentshell_Test::assert_false(
                0 === stripos( $header, 'Reply-To' ),
                'a non-email field must never supply Reply-To'
            );
        }
    }
);

Agentshell_Test::run(
    'an invalid submitted address never reaches a header',
    function () {
        update_option( 'admin_email', 'owner@example.test' );

        foreach ( array(
            "ada@example.invalid\r\nBcc: victim@example.org",
            "ada@exa\rmple.invalid",
            'ada@@example.invalid',
            'ada',
            '',
        ) as $bad ) {
            $message = Form_Notifier::compose( 'admin', fnu_definition(), 1, fnu_values( array( 'email' => $bad ) ) );

            foreach ( $message['headers'] as $header ) {
                Agentshell_Test::assert_false(
                    0 === stripos( $header, 'Reply-To' ),
                    'an invalid address must not become a Reply-To header'
                );
            }
        }
    }
);

Agentshell_Test::run(
    'a surrounding space is trimmed, and no control character survives into a header',
    function () {
        update_option( 'admin_email', 'owner@example.test' );

        $message = Form_Notifier::compose( 'admin', fnu_definition(), 1, fnu_values( array( 'email' => '  ada@example.invalid  ' ) ) );

        $headers = implode( "\n", $message['headers'] );

        Agentshell_Test::assert_true( false !== strpos( $headers, 'Reply-To: ada@example.invalid' ), 'surrounding whitespace is trimmed' );
        Agentshell_Test::assert_false( false !== strpos( $headers, "\r" ), 'no carriage return reaches a header' );
        Agentshell_Test::assert_equals( 1, substr_count( $headers, "\n" ), 'one header per line, and no injected line' );
    }
);

// ══ the confirmation ═════════════════════════════════════════════════════════

Agentshell_Test::run(
    'a confirmation goes to the declared email field, and nowhere without one',
    function () {
        $with_email = fnu_definition();
        Agentshell_Test::assert_equals(
            'ada@example.invalid',
            Form_Notifier::confirmation_recipient( $with_email, fnu_values() ),
            'the email field’s value is the recipient'
        );

        $no_email_fields = array(
            array(
                'key'      => 'name',
                'type'     => 'text',
                'label'    => 'Name',
                'required' => true,
                'settings' => array( 'max_length' => 120, 'min_length' => 0, 'autocomplete' => 'name' ),
                'options'  => array(),
            ),
        );

        Agentshell_Test::assert_equals(
            '',
            Form_Notifier::confirmation_recipient( fnu_definition( array(), $no_email_fields ), array( 'name' => 'Ada' ) ),
            'no email field means no confirmation recipient'
        );

        Agentshell_Test::assert_equals(
            '',
            Form_Notifier::confirmation_recipient( fnu_definition(), fnu_values( array( 'email' => 'not-an-address' ) ) ),
            'an invalid address is no recipient'
        );
    }
);

Agentshell_Test::run(
    'a confirmation composes only when it is enabled and has a recipient',
    function () {
        $disabled = Form_Notifier::compose( 'confirmation', fnu_definition(), 1, fnu_values() );
        Agentshell_Test::assert_true( is_wp_error( $disabled ), 'a disabled confirmation does not compose' );
        Agentshell_Test::assert_equals(
            'form_notification_disabled',
            $disabled->get_error_code(),
            'the refusal names the switch somebody turned off'
        );

        $enabled = fnu_definition(
            array(
                'confirmation' => array(
                    'enabled'       => true,
                    'subject'       => 'We received your message',
                    'body_template' => 'Thanks {field:name}.',
                ),
            )
        );

        $message = Form_Notifier::compose( 'confirmation', $enabled, 7, fnu_values() );

        Agentshell_Test::assert_true( is_array( $message ), 'an enabled confirmation composes' );
        Agentshell_Test::assert_equals( array( 'ada@example.invalid' ), $message['to'], 'it goes to the submitter' );
        Agentshell_Test::assert_equals( 'We received your message', $message['subject'], 'it uses its own subject' );
        Agentshell_Test::assert_true( false !== strpos( $message['body'], 'Thanks Ada Lovelace.' ), 'it renders its own template' );
        Agentshell_Test::assert_true(
            1 === preg_match( '/^<agentshell-forms-7-confirmation-/', $message['message_id'] ),
            'its Message-ID is distinguishable from the administrator’s'
        );
    }
);

// ══ the clock invariants ═════════════════════════════════════════════════════

Agentshell_Test::run(
    'the backoff is bounded, monotonic and long enough to matter',
    function () {
        $backoff = Form_Notifier::backoff();

        Agentshell_Test::assert_equals( 5, count( $backoff ), 'there is a delay for each of the five attempts' );
        Agentshell_Test::assert_equals( Form_Notifier::MAX_ATTEMPTS, count( $backoff ), 'the attempt limit and the backoff agree' );

        $previous = 0;

        foreach ( $backoff as $attempt => $delay ) {
            Agentshell_Test::assert_true( $delay > $previous, "attempt {$attempt} waits longer than the one before" );
            Agentshell_Test::assert_true( $delay >= 60, "attempt {$attempt} waits at least a minute" );
            $previous = $delay;
        }
    }
);

Agentshell_Test::run(
    'the attempt budget is always shorter than the claim lifetime',
    function () {
        Agentshell_Test::assert_true(
            Form_Notifier::budget() < Form_Notifier::claim_ttl(),
            'the shipped budget is shorter than the shipped TTL'
        );

        // A filter that tries to make the budget longer is clamped: two
        // workers sending at once is worse than a refused attempt.
        add_filter(
            'agentshell_forms_notification_attempt_budget',
            function () {
                return 100000;
            }
        );

        Agentshell_Test::assert_true(
            Form_Notifier::budget() < Form_Notifier::claim_ttl(),
            'a budget filter longer than the TTL is clamped below it'
        );

        add_filter(
            'agentshell_forms_notification_claim_ttl',
            function () {
                return 1;
            }
        );

        Agentshell_Test::assert_true( Form_Notifier::claim_ttl() >= 2, 'a nonsensical TTL is refused rather than obeyed' );
        Agentshell_Test::assert_true( Form_Notifier::budget() >= 1, 'the clamped budget is still a usable number' );
    }
);

Agentshell_Test::run(
    'a fresh record is pending, unclaimed and due now',
    function () {
        $record = Form_Notifier::initial_record( 'admin' );

        Agentshell_Test::assert_equals( 'pending', $record['status'] );
        Agentshell_Test::assert_equals( 0, $record['attempts'] );
        Agentshell_Test::assert_equals( null, $record['claimed_at'] );
        Agentshell_Test::assert_equals( null, $record['accepted_at'] );
        Agentshell_Test::assert_true( '' !== (string) $record['next_attempt_at'], 'the first attempt is due immediately' );

        $confirmation = Form_Notifier::initial_record( 'confirmation' );
        Agentshell_Test::assert_equals( 'confirmation', $confirmation['kind'], 'the kind is recorded' );
    }
);
