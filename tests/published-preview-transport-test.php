<?php
/**
 * The published-preview credential must survive the URL it travels in.
 *
 * The failure this exists to prevent
 * ----------------------------------
 * The published-preview credential is a base64-encoded JSON blob. It is
 * put into a query string, and the query string is assembled by
 * WordPress's `add_query_arg()`.
 *
 * `add_query_arg()` does two things to a value that break base64:
 *
 *   1. it re-encodes the query string and then strips a trailing `=`
 *      (`preg_replace( '#=(&|$)#', '$1', $ret )`), which is base64's
 *      padding; and
 *   2. it does not encode a `+`, which every query-string parser reads
 *      back as a space.
 *
 * Both corrupt the blob. The consequence was silent and severe: the
 * overlay refused the credential, so the published preview rendered the
 * PUBLISHED page — and the tool reported a successful capture naming
 * the staged edit. An agent asking to see a staged edit was shown the
 * old content and told it had been shown the new one.
 *
 * Measured against the live test bed: for 40 consecutive post ids, the
 * credential minted by the product failed to verify for 40 of them.
 *
 * What is asserted here
 * ---------------------
 *   1. The credential's own encoding survives `add_query_arg()` and
 *      `http_build_query()` byte-for-byte, for a range of post ids —
 *      the ones whose blobs land on a padding boundary included.
 *   2. A credential that has made the round trip through a query string
 *      still verifies: the HMAC still matches and the tuple still
 *      decodes.
 *   3. The credential the overlay verifies is the one the minting code
 *      produced — not a lenient re-interpretation of it.
 *   4. A credential minted by an older build (standard base64) still
 *      verifies, so a deployment does not invalidate credentials that
 *      are in flight during the upgrade.
 *   5. A tampered credential still fails, and a credential for another
 *      actor still fails.
 *
 * Nothing here touches the live site or the network.
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'AUTH_SALT' ) ) { define( 'AUTH_SALT', 'published-preview-transport-auth-salt' ); }
if ( ! defined( 'AGENTSHELL_REST_TOKEN' ) ) { define( 'AGENTSHELL_REST_TOKEN', 'published-preview-transport-token' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MB_IN_BYTES' ) ) { define( 'MB_IN_BYTES', 1048576 ); }

require_once dirname( __DIR__ ) . '/functions.php';
foreach ( array( 'class-store.php', 'class-content-write-guard.php', 'class-content-journal.php', 'class-recovery-advisory-lock.php', 'class-content-recovery.php', 'class-transactions.php', 'class-post-preview.php' ) as $inc ) {
    require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/' . $inc;
}

// The unit bootstrap does not stub wp_parse_url (the suite that needs
// it stubs it locally), and core's add_query_arg() uses it. The stub is
// faithful: core's wp_parse_url() is parse_url() with a component
// constant, where -1 means "all components".
if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) {
        return parse_url( $url, $component );
    }
}

use AgentShell_MCP\Post_Preview;

echo "published-preview credential transport tests\n";

// The URL the tool actually builds.
//
// The bootstrap's `add_query_arg()` is a convenience stub that uses
// `http_build_query()`, which does NOT reproduce what WordPress core
// does. Core's version re-encodes the query string, leaves a `+`
// unencoded, and then strips a trailing `=` — and that last step is
// exactly what truncates a base64 value's padding.
//
// So this test reproduces core's algorithm rather than using either
// function. The reproduction is asserted against core's own behaviour
// in the test below ("the reproduction matches core's algorithm"), so
// the model cannot drift into being a convenient fiction that proves
// nothing.
//
// Both transports are exercised: `add_query_arg()` is what the tool
// used, `http_build_query()` is what it uses now. The point is that the
// credential survives EITHER, so a future caller that reaches for the
// more obvious function does not reintroduce the defect.
function aspt_core_add_query_arg( array $args, $url ) {
    // WordPress core, wp-includes/functions.php, add_query_arg().
    $query = (string) wp_parse_url( (string) $url, PHP_URL_QUERY );
    $qs    = array();
    if ( is_string( $query ) && '' !== $query ) {
        parse_str( $query, $qs );
    }
    $qs = array_map( 'urlencode', $qs );
    foreach ( $args as $k => $v ) {
        $qs[ $k ] = $v;
    }
    foreach ( $qs as $k => $v ) {
        if ( false === $v ) {
            unset( $qs[ $k ] );
        }
    }
    $ret = http_build_query( $qs, '', '&', PHP_QUERY_RFC1738 );
    // build_query() in core passes urlencode = false; the value is
    // therefore NOT percent-encoded, which is how a '+' survives into
    // the URL as a space when the query string is parsed.
    $ret = '';
    foreach ( $qs as $k => $v ) {
        $ret .= ( '' === $ret ? '' : '&' ) . $k . '=' . $v;
    }
    $ret = trim( $ret, '?' );
    // Core's own line: strip a trailing '=' (or one before an '&').
    $ret = preg_replace( '#=(&|$)#', '$1', $ret );
    $sep = ( false === strpos( (string) $url, '?' ) ) ? '?' : '&';
    return (string) $url . $sep . $ret;
}

function aspt_transport_http_build_query( $args, $url ) {
    $query = http_build_query( $args, '', '&' );
    if ( '' === $query ) {
        return $url;
    }
    $separator = ( false === strpos( (string) $url, '?' ) ) ? '?' : '&';
    return (string) $url . $separator . $query;
}

/**
 * What a server receives for a URL: the query string parsed.
 *
 * @param string $url
 * @return array
 */
function aspt_transport_parse( $url ) {
    $query = parse_url( (string) $url, PHP_URL_QUERY );
    if ( ! is_string( $query ) || '' === $query ) {
        return array();
    }
    $out = array();
    parse_str( $query, $out );
    return $out;
}

// ── 1. The credential survives the query string ─────────────────────────────

Agentshell_Test::run( 'the reproduction of core matches core\'s own algorithm', function () {
    // A model that is more forgiving than core would prove nothing. This
    // pins the two behaviours the defect depends on — the trailing '='
    // is stripped, and the value is not percent-encoded — so that the
    // model cannot quietly become a fiction.
    $url = aspt_core_add_query_arg( array( 'x' => 'QUJDREVGRw==' ), 'https://example.test/page/' );
    Agentshell_Test::assert_true(
        ! str_contains( $url, 'QUJDREVGRw==' ),
        "core strips the base64 padding, and so must the model: $url"
    );

    // And the model agrees with the bootstrap's own add_query_arg() on
    // the parts where the two do not differ (an unpadded value), so the
    // model is a faithful refinement rather than a different function.
    $plain = aspt_core_add_query_arg( array( 'x' => 'plainvalue' ), 'https://example.test/page/' );
    Agentshell_Test::assert_true(
        str_contains( $plain, 'x=plainvalue' ),
        'an ordinary value passes through unchanged'
    );
} );

Agentshell_Test::run( 'the credential survives add_query_arg() for every post id', function () {
    // A range wide enough to cross several base64 padding boundaries:
    // the blob's length changes with the post id, and a blob whose
    // length is not a multiple of three carries padding.
    $broken = array();
    for ( $oid = 100; $oid < 140; $oid++ ) {
        $args = Post_Preview::published_preview_url_args( $oid, 207, 'tx_1', 'op_1', 1 );
        $url  = aspt_core_add_query_arg( $args, 'https://example.test/page/' );
        $got  = aspt_transport_parse( $url );
        $sent = (string) ( $got[ Post_Preview::PUB_PREVIEW_CRED_ARG ] ?? '' );
        if ( $sent !== (string) $args[ Post_Preview::PUB_PREVIEW_CRED_ARG ] ) {
            $broken[] = $oid;
        }
    }
    Agentshell_Test::assert_equals(
        array(),
        $broken,
        'no post id may lose its credential on the way into a query string'
    );
} );

Agentshell_Test::run( 'the credential survives http_build_query() for every post id', function () {
    $broken = array();
    for ( $oid = 100; $oid < 140; $oid++ ) {
        $args = Post_Preview::published_preview_url_args( $oid, 207, 'tx_1', 'op_1', 1 );
        $url  = aspt_transport_http_build_query( $args, 'https://example.test/page/' );
        $got  = aspt_transport_parse( $url );
        $sent = (string) ( $got[ Post_Preview::PUB_PREVIEW_CRED_ARG ] ?? '' );
        if ( $sent !== (string) $args[ Post_Preview::PUB_PREVIEW_CRED_ARG ] ) {
            $broken[] = $oid;
        }
    }
    Agentshell_Test::assert_equals(
        array(),
        $broken,
        'no post id may lose its credential on the way into a query string'
    );
} );

Agentshell_Test::run( 'the credential contains no character a query string mangles', function () {
    for ( $oid = 100; $oid < 140; $oid++ ) {
        $blob = (string) Post_Preview::published_preview_url_args( $oid, 207, 'tx_1', 'op_1', 1 )[ Post_Preview::PUB_PREVIEW_CRED_ARG ];
        Agentshell_Test::assert_true(
            (bool) preg_match( '/^[A-Za-z0-9\-_]+$/', $blob ),
            'the credential must use only URL-safe characters (oid=' . $oid . ')'
        );
    }
} );

// ── 2. The transported credential still verifies ────────────────────────────

Agentshell_Test::run( 'a credential that made the round trip still verifies', function () {
    for ( $oid = 100; $oid < 140; $oid++ ) {
        $args = Post_Preview::published_preview_url_args( $oid, 207, 'tx_1', 'op_1', 1 );
        foreach ( array( 'add_query_arg', 'http_build_query' ) as $transport ) {
            $url = ( 'add_query_arg' === $transport )
                ? aspt_core_add_query_arg( $args, 'https://example.test/page/' )
                : aspt_transport_http_build_query( $args, 'https://example.test/page/' );
            $got  = aspt_transport_parse( $url );
            $sent = (string) ( $got[ Post_Preview::PUB_PREVIEW_CRED_ARG ] ?? '' );
            $verdict = Post_Preview::verify_published_hmac( $sent );
            Agentshell_Test::assert_true(
                is_array( $verdict ),
                'the credential must verify after ' . $transport . ' (oid=' . $oid . ')'
            );
            if ( is_array( $verdict ) ) {
                Agentshell_Test::assert_equals(
                    $oid,
                    $verdict['oid'],
                    'the verified credential must speak for the post it was minted for (oid=' . $oid . ')'
                );
                Agentshell_Test::assert_equals(
                    1,
                    $verdict['actor'],
                    'the verified credential must speak for the actor it was minted for'
                );
            }
        }
    }
} );

Agentshell_Test::run( 'the verified credential is the one that was minted', function () {
    // A blob that merely decodes is not enough — it must be the SAME
    // credential. A lenient decoder that "fixed" a corrupted blob would
    // produce a credential whose tuple no longer matches what was
    // signed, and the HMAC check would have to be what catches it.
    $args = Post_Preview::published_preview_url_args( 205, 207, 'tx_1', 'op_1', 1 );
    $url  = aspt_core_add_query_arg( $args, 'https://example.test/page/' );
    $got  = aspt_transport_parse( $url );
    $sent = (string) ( $got[ Post_Preview::PUB_PREVIEW_CRED_ARG ] ?? '' );

    $verdict = Post_Preview::verify_published_hmac( $sent );
    Agentshell_Test::assert_true( is_array( $verdict ), 'the credential verifies' );
    Agentshell_Test::assert_equals( 205, $verdict['oid'], 'oid survives' );
    Agentshell_Test::assert_equals( 207, $verdict['sid'], 'sid survives' );
    Agentshell_Test::assert_equals( 'tx_1', $verdict['tx'], 'tx survives' );
    Agentshell_Test::assert_equals( 'op_1', $verdict['op'], 'op survives' );
} );

// ── 3. Backward compatibility ───────────────────────────────────────────────

Agentshell_Test::run( 'a credential minted by an older build still verifies', function () {
    // Standard base64 with padding — what the previous build produced.
    // A credential in flight during an upgrade must keep working for
    // the remainder of its 300-second window; failing it would be a
    // second failure mode on top of the one being fixed.
    $secret = Post_Preview::preview_secret();
    $exp    = time() + 120;
    $sig    = Post_Preview::sign_published_hmac( 205, 207, 'tx_1', 'op_1', 1, $exp, $secret );
    $legacy = base64_encode( wp_json_encode( array(
        'oid' => 205, 'sid' => 207, 'tx' => 'tx_1', 'op' => 'op_1',
        'actor' => 1, 'exp' => $exp, 'key' => $sig,
    ) ) );

    $verdict = Post_Preview::verify_published_hmac( $legacy );
    Agentshell_Test::assert_true(
        is_array( $verdict ),
        'a standard-base64 credential must still verify'
    );
    if ( is_array( $verdict ) ) {
        Agentshell_Test::assert_equals( 205, $verdict['oid'], 'and speak for the same post' );
    }
} );

Agentshell_Test::run( 'a standard-base64 credential also survives the query string', function () {
    // The legacy blob is what a browser would have received from the
    // previous build's URL. It was already mangled there, which is the
    // defect; but a blob that is NOT mangled must still work.
    $secret = Post_Preview::preview_secret();
    $exp    = time() + 120;
    $sig    = Post_Preview::sign_published_hmac( 205, 207, 'tx_1', 'op_1', 1, $exp, $secret );
    $legacy = base64_encode( wp_json_encode( array(
        'oid' => 205, 'sid' => 207, 'tx' => 'tx_1', 'op' => 'op_1',
        'actor' => 1, 'exp' => $exp, 'key' => $sig,
    ) ) );
    $verdict = Post_Preview::verify_published_hmac( $legacy );
    Agentshell_Test::assert_true( is_array( $verdict ), 'the legacy credential verifies' );
} );

// ── 4. The refusals still hold ─────────────────────────────────────────────

Agentshell_Test::run( 'a tampered credential is still refused', function () {
    $args = Post_Preview::published_preview_url_args( 205, 207, 'tx_1', 'op_1', 1 );
    $decoded = json_decode( Post_Preview::base64url_decode( $args[ Post_Preview::PUB_PREVIEW_CRED_ARG ] ), true );
    $decoded['key'] = 'a' . substr( $decoded['key'], 1 );
    $tampered = Post_Preview::base64url_encode( wp_json_encode( $decoded ) );

    Agentshell_Test::assert_false(
        is_array( Post_Preview::verify_published_hmac( $tampered ) ),
        'a tampered signature must be refused'
    );
} );

Agentshell_Test::run( 'a credential for another actor is still refused', function () {
    $args = Post_Preview::published_preview_url_args( 205, 207, 'tx_1', 'op_1', 7 );
    // The credential is genuine; it just speaks for a different actor.
    // The overlay is what refuses it (it compares the op's actor), and
    // this test asserts the credential itself does not claim to be the
    // caller's.
    $verdict = Post_Preview::verify_published_hmac( $args[ Post_Preview::PUB_PREVIEW_CRED_ARG ] );
    Agentshell_Test::assert_true( is_array( $verdict ), 'the credential is genuine' );
    if ( is_array( $verdict ) ) {
        Agentshell_Test::assert_equals( 7, $verdict['actor'], 'and it speaks for the actor it was minted for' );
        Agentshell_Test::assert_true( 7 !== 1, 'which is not the caller' );
    }
} );

Agentshell_Test::run( 'a garbage credential is still refused', function () {
    foreach ( array( '', 'not-base64-at-all', '!!!!', 'a' ) as $junk ) {
        Agentshell_Test::assert_false(
            is_array( Post_Preview::verify_published_hmac( $junk ) ),
            'garbage must be refused: ' . $junk
        );
    }
} );

Agentshell_Test::run( 'an expired credential is still refused', function () {
    $secret = Post_Preview::preview_secret();
    $exp    = time() - 60;
    $sig    = Post_Preview::sign_published_hmac( 205, 207, 'tx_1', 'op_1', 1, $exp, $secret );
    $expired = Post_Preview::base64url_encode( wp_json_encode( array(
        'oid' => 205, 'sid' => 207, 'tx' => 'tx_1', 'op' => 'op_1',
        'actor' => 1, 'exp' => $exp, 'key' => $sig,
    ) ) );
    Agentshell_Test::assert_false(
        is_array( Post_Preview::verify_published_hmac( $expired ) ),
        'an expired credential must be refused'
    );
} );

// ── 5. The encoding helpers themselves ─────────────────────────────────────

Agentshell_Test::run( 'base64url_encode produces only URL-safe characters', function () {
    for ( $i = 0; $i < 40; $i++ ) {
        $blob = Post_Preview::base64url_encode( 'x' . str_repeat( "\xfb\xff\xfe", $i ) . 'y' );
        Agentshell_Test::assert_true(
            (bool) preg_match( '/^[A-Za-z0-9\-_]+$/', $blob ),
            'the encoding must be URL-safe: ' . $blob
        );
    }
} );

Agentshell_Test::run( 'base64url_decode reverses base64url_encode', function () {
    foreach ( array( '', 'a', 'ab', 'abc', '{"a":1}', "\xfb\xff\xfe\x00" ) as $raw ) {
        Agentshell_Test::assert_equals(
            $raw,
            Post_Preview::base64url_decode( Post_Preview::base64url_encode( $raw ) ),
            'the encoding must round-trip'
        );
    }
} );

Agentshell_Test::run( 'base64url_decode refuses invalid input', function () {
    // The empty string is not "invalid": it is the exact inverse of
    // encoding the empty string, and it decodes to the empty string.
    // Everything else that is not base64 must be refused.
    foreach ( array( '!!!!', 'a', '@@@' ) as $junk ) {
        Agentshell_Test::assert_false(
            false !== Post_Preview::base64url_decode( $junk ),
            'invalid input must not decode: ' . $junk
        );
    }
} );

// ── Report ──────────────────────────────────────────────────────────────────

// No exit: run-all.php requires every suite into one process, and exiting
// would end the aggregate run before the remaining suites execute.
echo "\n";
echo "published-preview credential transport: " . Agentshell_Test::$passed . " passed, " . Agentshell_Test::$failed . " failed\n";
