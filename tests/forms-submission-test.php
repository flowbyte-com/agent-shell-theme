<?php
/**
 * The submission pipeline against a real WordPress.
 *
 * This is the destructive half of Phase 3. The unit suites pin the pieces
 * (Turnstile's branches, the notifier's templates, the store's escaping); this
 * suite proves they are wired together and that the answers a visitor and an
 * attacker receive are the answers the design promises.
 *
 * Every assertion below is a claim from §7.1, §6.3a, §10.2 or §11:
 *
 *   1. a valid submission is stored once, linked to its challenge, and mailed;
 *   2. a replay of the same challenge returns the original success and creates
 *      nothing;
 *   3. validation happens **before** the challenge is spent, so a mistyped
 *      field does not cost the visitor their token;
 *   4. a genuinely stale page is refused with `form_stale` and a reload, and
 *      is **never** reinterpreted against the newer schema;
 *   5. an expired, foreign or replayed challenge is refused, and a foreign one
 *      is not even consumed;
 *   6. the honeypot receives the ordinary success and stores nothing;
 *   7. the rate limit admits exactly its configured number and answers `429`
 *      with `Retry-After`;
 *   8. `required` Turnstile fails closed for a missing token, a failed token
 *      and an unreachable Cloudflare; `optional` stores an honest status;
 *   9. only declared fields are stored, and a hidden field's value comes from
 *      the definition, never from the request;
 *  10. submission access is per user, the administrator role does not hold it,
 *      and an account without it cannot read, export or delete a submission;
 *  11. no refusal names which anti-spam control fired.
 *
 * It writes forms, submissions, challenges and mail, and it removes every one
 * of them before it exits; the parent asserts that it did.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "forms submission pipeline (real WordPress)\n";

$config = agentshell_wp_live_config_destructive();

if ( null === $config ) {
    Agentshell_Test::run(
        'forms submission suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config (destructive suites require one)' );
        }
    );
    echo "  (skipped: destructive suites require a test-bed config)\n";
    return;
}

$report_file = sys_get_temp_dir() . '/as-forms-submission-' . getmypid() . '.json';
@unlink( $report_file );

$run = agentshell_wp_live_run(
    $config,
    __DIR__ . '/forms-submission-live-child.php',
    array( $config['wp_root'], $report_file )
);

$raw = @file_get_contents( $report_file );
@unlink( $report_file );

$f = json_decode( (string) $raw, true );

if ( ! is_array( $f ) ) {
    Agentshell_Test::run(
        'forms submission: the child reported',
        function () use ( $run ) {
            Agentshell_Test::assert_true( false, 'the child produced no report: ' . $run['output'] );
        }
    );
    return;
}

$p = isset( $f['probes'] ) && is_array( $f['probes'] ) ? $f['probes'] : array();

if ( isset( $p['fatal'] ) ) {
    Agentshell_Test::run(
        'forms submission: the child reached its subjects',
        function () use ( $p ) {
            Agentshell_Test::assert_true(
                false,
                'the child could not set up: ' . $p['fatal']['where'] . ' — ' . $p['fatal']['code'] . ': ' . $p['fatal']['message']
            );
        }
    );
    Agentshell_Test::summary();
    return;
}

/**
 * One probe's field, with an actionable failure when the child did not report.
 *
 * @param array  $probes All probes.
 * @param string $probe  Probe name.
 * @param string $key    Field.
 * @return mixed
 */
function asf3r( array $probes, $probe, $key ) {
    if ( ! isset( $probes[ $probe ] ) ) {
        throw new Exception( "the child did not report the probe \"{$probe}\"" );
    }

    if ( ! array_key_exists( $key, $probes[ $probe ] ) ) {
        throw new Exception( "the probe \"{$probe}\" has no field \"{$key}\"" );
    }

    return $probes[ $probe ][ $key ];
}

// ══ wiring ═══════════════════════════════════════════════════════════════════

Agentshell_Test::run(
    'the submission route, the entry type, the privacy hooks and both jobs are registered',
    function () use ( $p ) {
        Agentshell_Test::assert_true( asf3r( $p, 'environment', 'submit_route' ), 'the submission route must be registered' );
        Agentshell_Test::assert_true( asf3r( $p, 'environment', 'entry_type' ), 'the entry post type must be registered' );
        Agentshell_Test::assert_true( asf3r( $p, 'environment', 'exporter' ), 'the privacy exporter must be registered' );
        Agentshell_Test::assert_true( asf3r( $p, 'environment', 'eraser' ), 'the privacy eraser must be registered' );
        Agentshell_Test::assert_true( asf3r( $p, 'environment', 'admin_class' ), 'the submission screens must exist' );
        Agentshell_Test::assert_true( asf3r( $p, 'environment', 'cron_notifications' ), 'the notification worker must be scheduled' );
        Agentshell_Test::assert_true( asf3r( $p, 'environment', 'cron_retention' ), 'the retention purge must be scheduled' );
    }
);

// ══ the successful journey ═══════════════════════════════════════════════════

Agentshell_Test::run(
    'a valid submission is stored once, version-stamped, linked and mailed',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 200, asf3r( $p, 'success', 'status' ), 'a valid submission must be accepted' );
        Agentshell_Test::assert_true( asf3r( $p, 'success', 'ok' ), 'the response must say it succeeded' );
        Agentshell_Test::assert_equals( 'Thanks from the bed.', asf3r( $p, 'success', 'message' ), 'the form’s own success message is returned' );
        Agentshell_Test::assert_equals( 1, asf3r( $p, 'success', 'entries' ), 'exactly one submission must be stored' );
        Agentshell_Test::assert_equals( 1, asf3r( $p, 'success', 'version' ), 'the submission must be stamped with the version it was validated against' );
        Agentshell_Test::assert_true( '' !== asf3r( $p, 'success', 'form_id' ), 'the submission must record the internal form identifier' );
        Agentshell_Test::assert_true( asf3r( $p, 'success', 'hash_ok' ), 'the stored hash must cover the stored values and version' );
        Agentshell_Test::assert_equals(
            asf3r( $p, 'success', 'entry_id' ),
            asf3r( $p, 'success', 'challenge_linked' ),
            'the challenge must be linked to the submission it produced'
        );
        Agentshell_Test::assert_equals( 'clean', asf3r( $p, 'success', 'spam' ), 'a form with no Turnstile policy is clean' );
    }
);

Agentshell_Test::run(
    'the public success response does not disclose the stored record id',
    function () use ( $p ) {
        Agentshell_Test::assert_false(
            asf3r( $p, 'success', 'has_id' ),
            'the response must not hand an anonymous visitor the id of a stored record'
        );
    }
);

Agentshell_Test::run(
    'the submission response is uncacheable and not indexable',
    function () use ( $p ) {
        $cache = strtolower( (string) asf3r( $p, 'success', 'cache_control' ) );
        Agentshell_Test::assert_true( false !== strpos( $cache, 'no-store' ), 'a submission response must not be stored by any cache' );
        Agentshell_Test::assert_true( false !== strpos( $cache, 'private' ), 'a submission response must be private' );
        Agentshell_Test::assert_true(
            false !== strpos( strtolower( (string) asf3r( $p, 'success', 'robots' ) ), 'noindex' ),
            'a submission response must not be indexed'
        );
    }
);

Agentshell_Test::run(
    'the administrator notification is plain text, allowlisted and answered',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 1, asf3r( $p, 'success', 'mail_count' ), 'one administrator notification is attempted' );
        Agentshell_Test::assert_equals(
            asf3r( $p, 'success', 'admin_email' ),
            asf3r( $p, 'success', 'mail_to' ),
            '`site-admin` resolves to the site administration address'
        );
        Agentshell_Test::assert_true(
            false !== strpos( (string) asf3r( $p, 'success', 'mail_subject' ), 'New submission' ),
            'the configured subject template is rendered'
        );

        $body = (string) asf3r( $p, 'success', 'mail_body' );
        Agentshell_Test::assert_true( false !== strpos( $body, 'Ada Lovelace' ), 'the body carries the submitted values' );
        Agentshell_Test::assert_true( false !== strpos( $body, 'I agree: Yes' ), 'a consent is rendered as a readable value' );
        Agentshell_Test::assert_false( false !== strpos( $body, '<' ), 'the MVP notification is plain text, not HTML' );

        $headers = (array) asf3r( $p, 'success', 'mail_headers' );
        $joined  = implode( "\n", array_map( 'strval', $headers ) );

        Agentshell_Test::assert_true( false !== strpos( $joined, 'Message-ID: <' ), 'every message carries a Message-ID' );
        Agentshell_Test::assert_true(
            false !== strpos( $joined, 'Reply-To: test@example.invalid' ),
            'the configured email field supplies Reply-To'
        );
        Agentshell_Test::assert_false( false !== strpos( strtolower( $joined ), "\nfrom:" ), 'the submitter never becomes the From header' );
    }
);

Agentshell_Test::run(
    'the notification is recorded as accepted, and the record says what that means',
    function () use ( $p ) {
        $notification = asf3r( $p, 'success', 'notification' );

        Agentshell_Test::assert_true( is_array( $notification ), 'the submission must carry a notification record' );
        Agentshell_Test::assert_equals( 'accepted', $notification['status'], 'wp_mail’s acceptance is recorded as accepted' );
        Agentshell_Test::assert_equals( 1, $notification['attempts'], 'the first attempt is counted' );
        Agentshell_Test::assert_true( '' !== (string) $notification['accepted_at'], 'the acceptance time is recorded' );
        Agentshell_Test::assert_true( '' !== (string) $notification['message_id'], 'the message id is recorded for de-duplication' );
    }
);

Agentshell_Test::run(
    'a replayed challenge returns the original success and creates nothing',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 200, asf3r( $p, 'success', 'replay_status' ), 'a retry of the same request must be answered as success' );
        Agentshell_Test::assert_true( asf3r( $p, 'success', 'replay_ok' ), 'the replay says it succeeded' );
        Agentshell_Test::assert_equals( 1, asf3r( $p, 'success', 'replay_entries' ), 'a replay must not create a second submission' );
        Agentshell_Test::assert_equals( 1, asf3r( $p, 'success', 'replay_mail' ), 'a replay must not send a second notification' );
    }
);

// ══ validation before the claim ══════════════════════════════════════════════

Agentshell_Test::run(
    'a field error is reported without spending the visitor’s challenge',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 422, asf3r( $p, 'validation', 'status' ), 'a missing required field is a 422' );
        Agentshell_Test::assert_equals( 'form_invalid', asf3r( $p, 'validation', 'code' ), 'the refusal names the validation state' );

        $errors = (array) asf3r( $p, 'validation', 'errors' );
        Agentshell_Test::assert_true( isset( $errors['name'] ), 'the missing field is named' );
        Agentshell_Test::assert_true(
            false !== strpos( (string) $errors['name'], 'Your name' ),
            'the message uses the field’s label, not its key'
        );

        Agentshell_Test::assert_equals( 0, asf3r( $p, 'validation', 'entries' ), 'a rejected submission stores nothing' );
        Agentshell_Test::assert_false(
            asf3r( $p, 'validation', 'challenge_consumed' ),
            'validation happens before the claim, so the token is still usable'
        );
    }
);

Agentshell_Test::run(
    'the corrected submission succeeds with the same challenge',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 200, asf3r( $p, 'validation', 'corrected_status' ), 'the retry with the corrected field is accepted' );
        Agentshell_Test::assert_equals( 1, asf3r( $p, 'validation', 'corrected_entries' ), 'the corrected retry stores exactly one submission' );
    }
);

// ══ malformed requests ═══════════════════════════════════════════════════════

Agentshell_Test::run(
    'malformed, oversized, unknown and foreign requests are refused',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 400, asf3r( $p, 'malformed', 'list_fields_status' ), 'a JSON list is not a field map' );
        Agentshell_Test::assert_equals( 'form_rejected', asf3r( $p, 'malformed', 'list_fields_code' ), 'the malformed body is a generic refusal' );
        Agentshell_Test::assert_equals( 409, asf3r( $p, 'malformed', 'no_version_status' ), 'a body with no version cannot be verified, so it is stale' );
        Agentshell_Test::assert_equals( 'form_stale', asf3r( $p, 'malformed', 'no_version_code' ), 'the missing version is named as a stale page' );
        Agentshell_Test::assert_equals( 413, asf3r( $p, 'malformed', 'too_many_status' ), 'too many field keys is refused' );
        Agentshell_Test::assert_equals( 413, asf3r( $p, 'malformed', 'oversize_status' ), 'an oversized field value is refused' );
        Agentshell_Test::assert_equals( 422, asf3r( $p, 'malformed', 'unknown_status' ), 'an unknown form is refused' );
        Agentshell_Test::assert_equals( 422, asf3r( $p, 'malformed', 'bad_challenge_status' ), 'a malformed token is refused' );
        Agentshell_Test::assert_equals( 404, asf3r( $p, 'malformed', 'get_status' ), 'the submission route accepts POST only' );
        Agentshell_Test::assert_equals( 0, asf3r( $p, 'malformed', 'entries' ), 'none of the refused requests stored anything' );
    }
);

Agentshell_Test::run(
    'a refusal never names which anti-spam control fired',
    function () use ( $p ) {
        $message = strtolower( (string) asf3r( $p, 'malformed', 'unknown_message' ) );
        $bad     = strtolower( (string) asf3r( $p, 'malformed', 'bad_challenge_message' ) );

        foreach ( array( 'turnstile', 'honeypot', 'rate', 'challenge', 'spam', 'cloudflare' ) as $control ) {
            Agentshell_Test::assert_false( false !== strpos( $message, $control ), "an unknown-form refusal must not mention \"{$control}\"" );
            Agentshell_Test::assert_false( false !== strpos( $bad, $control ), "a bad-token refusal must not mention \"{$control}\"" );
        }
    }
);

// ══ a genuinely stale page ═══════════════════════════════════════════════════

Agentshell_Test::run(
    'a page rendered from an older version is refused as stale and not reinterpreted',
    function () use ( $p ) {
        Agentshell_Test::assert_true(
            (int) asf3r( $p, 'stale', 'new_version' ) > (int) asf3r( $p, 'stale', 'old_version' ),
            'the fixture must really publish a newer version'
        );
        Agentshell_Test::assert_equals( 409, asf3r( $p, 'stale', 'status' ), 'a stale page is a 409' );
        Agentshell_Test::assert_equals( 'form_stale', asf3r( $p, 'stale', 'code' ), 'the state is named' );
        Agentshell_Test::assert_true( asf3r( $p, 'stale', 'reload' ), 'the refusal offers a reload' );
        Agentshell_Test::assert_equals(
            asf3r( $p, 'stale', 'new_version' ),
            asf3r( $p, 'stale', 'body_version' ),
            'the refusal names the version the visitor must reload to'
        );
        Agentshell_Test::assert_equals( 0, asf3r( $p, 'stale', 'entries' ), 'a stale submission stores nothing' );
        Agentshell_Test::assert_false(
            asf3r( $p, 'stale', 'challenge_consumed' ),
            'a stale page does not spend its token either'
        );
    }
);

Agentshell_Test::run(
    'a page that claims the new version but holds an old token is refused as stale',
    function () use ( $p ) {
        Agentshell_Test::assert_equals(
            409,
            asf3r( $p, 'stale', 'lying_status' ),
            'a token minted for an older version must not be accepted because the body names the new one'
        );
        Agentshell_Test::assert_equals(
            'form_stale',
            asf3r( $p, 'stale', 'lying_code' ),
            'the honest answer is that the page is out of date'
        );
    }
);

// ══ challenge rules ══════════════════════════════════════════════════════════

Agentshell_Test::run(
    'another form’s challenge is refused and left unclaimed',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 422, asf3r( $p, 'challenge_rules', 'mismatch_status' ), 'a foreign challenge is refused' );
        Agentshell_Test::assert_false(
            asf3r( $p, 'challenge_rules', 'mismatch_consumed' ),
            'a token presented to the wrong form must not be spent'
        );
        Agentshell_Test::assert_equals( 0, asf3r( $p, 'challenge_rules', 'other_entries' ), 'nothing is stored under the wrong form' );
    }
);

Agentshell_Test::run(
    'an expired challenge is refused and recorded',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 422, asf3r( $p, 'challenge_rules', 'expired_status' ), 'an expired challenge is refused' );
        Agentshell_Test::assert_equals( 0, asf3r( $p, 'challenge_rules', 'expired_entries' ), 'an expired challenge stores nothing' );
        Agentshell_Test::assert_true( asf3r( $p, 'challenge_rules', 'expired_logged' ), 'the refusal is in the rejection log' );
    }
);

Agentshell_Test::run(
    'a claimed challenge awaiting its submission asks the client to wait, then offers a retry',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 'claimed', asf3r( $p, 'pending', 'claim_status' ), 'the fixture must really claim the token' );
        Agentshell_Test::assert_equals( 409, asf3r( $p, 'pending', 'pending_status' ), 'a recent claim is a 409' );
        Agentshell_Test::assert_equals( 'form_pending', asf3r( $p, 'pending', 'pending_code' ), 'the state is named' );
        Agentshell_Test::assert_equals( '2', asf3r( $p, 'pending', 'pending_retry_after' ), 'the client is told how long to wait' );
        Agentshell_Test::assert_equals( 0, asf3r( $p, 'pending', 'pending_entries' ), 'a pending attempt stores nothing' );

        Agentshell_Test::assert_equals( 409, asf3r( $p, 'pending', 'retry_status' ), 'an abandoned claim is a 409' );
        Agentshell_Test::assert_equals( 'form_retry', asf3r( $p, 'pending', 'retry_code' ), 'the state is named' );
        Agentshell_Test::assert_true( asf3r( $p, 'pending', 'retry_flag' ), 'the refusal says a retry is possible' );
        Agentshell_Test::assert_true( asf3r( $p, 'pending', 'retry_token' ), 'a fresh challenge is supplied so no reload is needed' );
        Agentshell_Test::assert_equals( 1, asf3r( $p, 'pending', 'retry_version' ), 'the fresh token is minted for the current version' );
        Agentshell_Test::assert_equals( 200, asf3r( $p, 'pending', 'after_status' ), 'the fresh token works' );
        Agentshell_Test::assert_equals( 1, asf3r( $p, 'pending', 'entries' ), 'the retry produces exactly one submission' );
    }
);

// ══ the honeypot ═════════════════════════════════════════════════════════════

Agentshell_Test::run(
    'a filled honeypot receives the ordinary success and stores nothing',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 200, asf3r( $p, 'honeypot', 'status' ), 'a trapped bot must not be told it was caught' );
        Agentshell_Test::assert_true( asf3r( $p, 'honeypot', 'ok' ), 'the fake success says it succeeded' );
        Agentshell_Test::assert_equals( 'Thanks from the bed.', asf3r( $p, 'honeypot', 'message' ), 'the message is the form’s real one' );
        Agentshell_Test::assert_equals( 0, asf3r( $p, 'honeypot', 'entries' ), 'nothing is stored for a trapped request' );
        Agentshell_Test::assert_equals( 0, asf3r( $p, 'honeypot', 'mail' ), 'nothing is mailed for a trapped request' );
        Agentshell_Test::assert_true( asf3r( $p, 'honeypot', 'logged' ), 'the operator can see the trap fired' );
    }
);

// ══ the rate limit ═══════════════════════════════════════════════════════════

Agentshell_Test::run(
    'the per-form rate limit admits its configured number and then refuses',
    function () use ( $p ) {
        Agentshell_Test::assert_equals(
            array( 200, 200, 429 ),
            asf3r( $p, 'rate_limit', 'statuses' ),
            'a form limited to two submissions must accept two and refuse the third'
        );
        Agentshell_Test::assert_equals( 2, asf3r( $p, 'rate_limit', 'entries' ), 'a refused submission stores nothing' );
        Agentshell_Test::assert_true( '' !== (string) asf3r( $p, 'rate_limit', 'retry_after' ), 'the refusal carries Retry-After' );
        Agentshell_Test::assert_true( asf3r( $p, 'rate_limit', 'logged' ), 'the refusal is in the rejection log' );
    }
);

Agentshell_Test::run(
    'obtaining a challenge does not spend the form’s submission quota',
    function () use ( $p ) {
        Agentshell_Test::assert_equals(
            array( 200, 200, 429 ),
            asf3r( $p, 'rate_limit', 'statuses' ),
            'each submission mints one token and spends one slot — not two'
        );
    }
);

// ══ Turnstile ════════════════════════════════════════════════════════════════

Agentshell_Test::run(
    'a required form accepts a verified token and refuses every failure',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 200, asf3r( $p, 'turnstile', 'ok_status' ), 'a verified token is accepted' );
        Agentshell_Test::assert_equals( 'clean', asf3r( $p, 'turnstile', 'ok_spam' ), 'a verified token is clean' );

        Agentshell_Test::assert_equals( 422, asf3r( $p, 'turnstile', 'failed_status' ), 'a failed token is refused' );
        Agentshell_Test::assert_true( asf3r( $p, 'turnstile', 'failed_logged' ), 'the failure is in the rejection log' );

        Agentshell_Test::assert_equals( 422, asf3r( $p, 'turnstile', 'down_status' ), 'an unreachable Cloudflare is refused by a required form' );
        Agentshell_Test::assert_true( asf3r( $p, 'turnstile', 'down_logged' ), 'the outage is in the rejection log' );

        Agentshell_Test::assert_equals( 422, asf3r( $p, 'turnstile', 'missing_status' ), 'a required form with no token is refused' );
        Agentshell_Test::assert_equals( 1, asf3r( $p, 'turnstile', 'required_entries' ), 'only the verified attempt was stored' );
    }
);

Agentshell_Test::run(
    'a required form refuses rather than pretending to be protected',
    function () use ( $p ) {
        Agentshell_Test::assert_equals(
            422,
            asf3r( $p, 'turnstile', 'misconfigured_status' ),
            'a required form with no secret cannot be protected, so it refuses'
        );
        Agentshell_Test::assert_true( asf3r( $p, 'turnstile', 'misconfigured_logged' ), 'the misconfiguration reaches the operator' );
        Agentshell_Test::assert_equals( 'production', asf3r( $p, 'turnstile', 'env_type' ), 'the bed must look like production' );
        Agentshell_Test::assert_equals(
            422,
            asf3r( $p, 'turnstile', 'test_credential_status' ),
            'Cloudflare’s published test credential must not protect a production site'
        );
    }
);

Agentshell_Test::run(
    'an optional form never blocks, and records how sure it is',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 200, asf3r( $p, 'turnstile', 'opt_failed_status' ), 'an optional form accepts a failed token' );
        Agentshell_Test::assert_equals( 'spam', asf3r( $p, 'turnstile', 'opt_failed_spam' ), 'a failed token is recorded as spam' );
        Agentshell_Test::assert_equals( 200, asf3r( $p, 'turnstile', 'opt_down_status' ), 'an optional form accepts during an outage' );
        Agentshell_Test::assert_equals(
            'unverified',
            asf3r( $p, 'turnstile', 'opt_down_spam' ),
            'an outage is no signal, not evidence of spam'
        );
    }
);

// ══ storage ══════════════════════════════════════════════════════════════════

Agentshell_Test::run(
    'only declared fields are stored, and a hidden field cannot be spoofed',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 200, asf3r( $p, 'storage', 'status' ), 'a submission naming an undeclared field is still accepted' );
        Agentshell_Test::assert_false(
            asf3r( $p, 'storage', 'has_other' ),
            'a value for an undeclared key must never be stored'
        );
        Agentshell_Test::assert_equals(
            'contact-page',
            asf3r( $p, 'storage', 'hidden_value' ),
            'a hidden field’s value comes from the definition, not from the request'
        );

        $expected = array( 'name', 'email', 'message', 'consent', 'source', 'topics' );
        $keys     = (array) asf3r( $p, 'storage', 'keys' );
        sort( $expected );
        sort( $keys );

        Agentshell_Test::assert_equals( $expected, $keys, 'the stored keys are exactly the declared ones' );
    }
);

// ══ permissions and export ═══════════════════════════════════════════════════

Agentshell_Test::run(
    'the administrator role does not hold submission access',
    function () use ( $p ) {
        Agentshell_Test::assert_true( asf3r( $p, 'permissions', 'admin_manage_options' ), 'the fixture account is a real administrator' );
        Agentshell_Test::assert_false(
            asf3r( $p, 'permissions', 'admin_role_has_cap' ),
            'the administrator role must never be granted the submissions capability automatically'
        );
        Agentshell_Test::assert_false(
            asf3r( $p, 'permissions', 'can_before' ),
            'an administrator who has not opted in cannot reach submission contents'
        );
    }
);

Agentshell_Test::run(
    'an account without submission access cannot delete a submission',
    function () use ( $p ) {
        Agentshell_Test::assert_true( asf3r( $p, 'permissions', 'editor_refused' ), 'the deletion must be refused' );
        Agentshell_Test::assert_equals(
            'form_submission_delete_forbidden',
            asf3r( $p, 'permissions', 'editor_code' ),
            'the refusal names the missing authority'
        );
        Agentshell_Test::assert_true( asf3r( $p, 'permissions', 'editor_left_it' ), 'a refused deletion leaves the submission in place' );
    }
);

Agentshell_Test::run(
    'the owner can grant themselves access, and revocation takes it away again',
    function () use ( $p ) {
        Agentshell_Test::assert_true( asf3r( $p, 'permissions', 'granted' ), 'the grant must succeed' );
        Agentshell_Test::assert_true( asf3r( $p, 'permissions', 'can_after' ), 'the grant takes effect immediately' );
        Agentshell_Test::assert_true(
            asf3r( $p, 'permissions', 'cap_user_can_delete' ),
            'with the capability, the per-post delete capability resolves too'
        );
        Agentshell_Test::assert_true( asf3r( $p, 'permissions', 'revoked' ), 'the revocation must succeed' );
        Agentshell_Test::assert_false( asf3r( $p, 'permissions', 'can_revoked' ), 'the revoked account loses access' );
    }
);

Agentshell_Test::run(
    'export works for an authorised actor and is refused without the capability',
    function () use ( $p ) {
        Agentshell_Test::assert_true( asf3r( $p, 'permissions', 'csv_is_array' ), 'the CSV export must be produced' );
        Agentshell_Test::assert_true( asf3r( $p, 'permissions', 'csv_rows' ) >= 1, 'the CSV export must contain the stored submission' );
        Agentshell_Test::assert_true( asf3r( $p, 'permissions', 'csv_has_email' ), 'the export contains the stored personal data' );
        Agentshell_Test::assert_true( asf3r( $p, 'permissions', 'json_is_array' ), 'the JSON export must be produced' );
        Agentshell_Test::assert_true(
            asf3r( $p, 'permissions', 'export_after_revoke_refused' ),
            'the export helper must refuse on its own after revocation, not only in the screen'
        );
    }
);

Agentshell_Test::run(
    'every grant, refusal and export is audited',
    function () use ( $p ) {
        $audit = (array) asf3r( $p, 'permissions', 'audit' );

        foreach ( array(
            'form_submission_access_granted',
            'form_submission_access_revoked',
            'form_submission_exported',
            'form_submission_access_refused',
        ) as $event ) {
            Agentshell_Test::assert_true( in_array( $event, $audit, true ), "the audit log must record {$event}" );
        }
    }
);

// ══ cleanup ══════════════════════════════════════════════════════════════════

Agentshell_Test::run(
    'the suite left nothing behind',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 0, asf3r( $p, 'cleanup', 'remaining_entries' ), 'no submission may outlive the suite' );
    }
);

Agentshell_Test::summary();
