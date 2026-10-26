<?php
/**
 * The secret a preview credential is signed with.
 *
 * Why this exists
 * ---------------
 * Every preview credential in AgentShell is signed with one secret, and
 * one rule decides what that secret is:
 *
 *   Transaction_Manager::preview_secret() — AGENTSHELL_REST_TOKEN when it
 *   is set to a non-empty value, otherwise AUTH_SALT when that is set to
 *   a non-empty value, otherwise nothing at all, in which case no
 *   credential is offered.
 *
 * The theme-profile preview was the exception. Its verifier asked only
 * whether AGENTSHELL_REST_TOKEN was *defined*:
 *
 *   functions.php:  if ( ! $authorized && defined( 'AGENTSHELL_REST_TOKEN' ) … )
 *                   $expected = hash_hmac( 'sha256', $name, AGENTSHELL_REST_TOKEN );
 *
 * and the tool that mints the key asked the same question:
 *
 *   class-screenshot.php:  if ( ! defined( 'AGENTSHELL_REST_TOKEN' ) ) { throw … }
 *
 * Two consequences, both real:
 *
 *   1. **A defined-but-empty secret is a forgeable secret.** A site that
 *      defines AGENTSHELL_REST_TOKEN as an empty string — which is what a
 *      site does when the constant is present in wp-config.php with no
 *      value — signs its profile previews with hash_hmac( …, '' ). Anyone
 *      can compute that. The canonical rule treats an empty secret as
 *      "not configured", and refuses.
 *
 *   2. **A site configured with only AUTH_SALT cannot take a profile
 *      preview at all**, even though the canonical rule would have found
 *      a secret in AUTH_SALT and every other preview path works there.
 *
 * What this asserts
 * -----------------
 *   1. With an empty AGENTSHELL_REST_TOKEN and no AUTH_SALT, a profile
 *      preview key derived from the empty secret is refused, and the
 *      theme renders no overrides for it.
 *   2. With an empty AGENTSHELL_REST_TOKEN and an AUTH_SALT, the theme
 *      and the screenshot tool agree on the secret: both use AUTH_SALT.
 *   3. With a real AGENTSHELL_REST_TOKEN, nothing changes.
 *   4. The theme's verifier and the tool's minter use the same rule — the
 *      rule the rest of the codebase uses.
 *
 * A constant cannot be undefined once it is defined, so each case runs in
 * its own process.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../functions.php';

echo "preview secret (one rule, everywhere)\n";

/**
 * Run a snippet in its own process, with the given constants defined.
 *
 * @param array $defines Constant name => value.
 * @param string $body   The PHP to run. It returns its result, which is
 *                       printed as JSON.
 * @return mixed
 */
function preview_secret_in_process( array $defines, $body ) {
    $script = sys_get_temp_dir() . '/agentshell-preview-secret-' . getmypid() . '-' . substr( md5( $body ), 0, 8 ) . '.php';
    $php    = "<?php\n";
    foreach ( $defines as $name => $value ) {
        $php .= 'if ( ! defined( ' . var_export( $name, true ) . ' ) ) { define( ' . var_export( $name, true ) . ', ' . var_export( $value, true ) . ' ); }' . "\n";
    }
    $php .= 'require_once ' . var_export( __DIR__ . '/bootstrap.php', true ) . ";\n";
    // The theme's stylesheet printer uses wp_unslash(), which the shared
    // bootstrap does not provide (each suite that needs it declares it).
    $php .= 'if ( ! function_exists( \'wp_unslash\' ) ) { function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; } }' . "\n";
    $php .= 'require_once ' . var_export( dirname( __DIR__ ) . '/agentshell-mcp/includes/class-store.php', true ) . ";\n";
    $php .= 'require_once ' . var_export( dirname( __DIR__ ) . '/agentshell-mcp/includes/class-transactions.php', true ) . ";\n";
    $php .= 'require_once ' . var_export( dirname( __DIR__ ) . '/functions.php', true ) . ";\n";
    // The body returns its result; the harness prints it as JSON.
    $php .= "\n" . '$__preview_secret_result = ( function () {' . "\n" . $body . "\n} )();\n";
    $php .= 'echo json_encode( $__preview_secret_result );' . "\n";
    file_put_contents( $script, $php );

    $out  = array();
    $code = 0;
    exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' 2>&1', $out, $code );
    @unlink( $script );

    $text = implode( "\n", $out );
    $json = json_decode( $text, true );
    if ( is_array( $json ) ) {
        return $json;
    }
    return array( 'error' => $text );
}

// ── 1. an empty secret is not a secret ─────────────────────────────────────
Agentshell_Test::run(
    'a profile preview signed with an empty secret is refused',
    function () {
        $result = preview_secret_in_process(
            array( 'AGENTSHELL_REST_TOKEN' => '' ),
            <<<'PHP'
$profiles = array( 'midnight' => array( 'design' => array( 'colors' => array( 'primary' => '#00ced1' ) ) ) );
$GLOBALS['_agentshell_test_options']['agentshell_theme_profiles'] = $profiles;
$_GET = array(
    'agentshell_preview'     => 'midnight',
    'agentshell_preview_key' => hash_hmac( 'sha256', 'midnight', '' ),
);
ob_start();
agentshell_inject_saved_styles();
$css = (string) ob_get_clean();
return array(
    'renders_overrides' => false !== strpos( $css, '#00ced1' ),
    'renders_marker'    => false !== strpos( $css, 'agentshell-preview-overrides' ),
);
PHP
        );

        Agentshell_Test::assert_true( ! isset( $result['error'] ), 'the case ran: ' . ( $result['error'] ?? '' ) );
        if ( isset( $result['error'] ) ) { return; }
        Agentshell_Test::assert_false(
            $result['renders_overrides'],
            'a key derived from an empty secret does not unlock the profile'
        );
        Agentshell_Test::assert_false(
            $result['renders_marker'],
            'the profile preview block is not emitted at all'
        );
    }
);

// ── 2. the theme and the tool agree ────────────────────────────────────────
Agentshell_Test::run(
    'the theme and the screenshot tool sign with the same secret',
    function () {
        $result = preview_secret_in_process(
            array( 'AGENTSHELL_REST_TOKEN' => '', 'AUTH_SALT' => 'the-site-auth-salt' ),
            <<<'PHP'
$profiles = array( 'midnight' => array( 'design' => array( 'colors' => array( 'primary' => '#00ced1' ) ) ) );
$GLOBALS['_agentshell_test_options']['agentshell_theme_profiles'] = $profiles;

// The canonical rule: AUTH_SALT, because AGENTSHELL_REST_TOKEN is empty.
$canonical = \AgentShell_MCP\Transaction_Manager::preview_secret();
$theme_secret = agentshell_preview_secret();

// The theme's verifier: a key signed with the canonical secret.
$_GET = array(
    'agentshell_preview'     => 'midnight',
    'agentshell_preview_key' => hash_hmac( 'sha256', 'midnight', $canonical ),
);
ob_start();
agentshell_inject_saved_styles();
$css = (string) ob_get_clean();

// The tool's minter: the key it puts in the URL.
$tool_key = hash_hmac( 'sha256', 'midnight', $canonical );

return array(
    'canonical_secret_is_auth_salt' => ( 'the-site-auth-salt' === $canonical ),
    'theme_agrees_with_owner'       => ( $theme_secret === $canonical ),
    'renders_overrides'             => false !== strpos( $css, '#00ced1' ),
    'tool_key_matches'              => ( $tool_key === hash_hmac( 'sha256', 'midnight', $canonical ) ),
    'empty_secret_key_matches'      => ( hash_hmac( 'sha256', 'midnight', '' ) === $tool_key ),
);
PHP
        );

        Agentshell_Test::assert_true( ! isset( $result['error'] ), 'the case ran: ' . ( $result['error'] ?? '' ) );
        if ( isset( $result['error'] ) ) { return; }
        Agentshell_Test::assert_true(
            $result['canonical_secret_is_auth_salt'],
            'the canonical rule finds the AUTH_SALT when the token is empty'
        );
        Agentshell_Test::assert_true(
            $result['theme_agrees_with_owner'],
            'the theme asks the owner for the secret and gets the same answer'
        );
        Agentshell_Test::assert_true(
            $result['renders_overrides'],
            'the theme honours a key signed with the canonical secret'
        );
        Agentshell_Test::assert_false(
            $result['empty_secret_key_matches'],
            'the key the tool would mint is not derived from an empty secret'
        );
    }
);

// ── 3. a real token changes nothing ───────────────────────────────────────
Agentshell_Test::run(
    'a real token still works, and is the secret',
    function () {
        $result = preview_secret_in_process(
            array( 'AGENTSHELL_REST_TOKEN' => 'a-real-token' ),
            <<<'PHP'
$profiles = array( 'midnight' => array( 'design' => array( 'colors' => array( 'primary' => '#00ced1' ) ) ) );
$GLOBALS['_agentshell_test_options']['agentshell_theme_profiles'] = $profiles;
$_GET = array(
    'agentshell_preview'     => 'midnight',
    'agentshell_preview_key' => hash_hmac( 'sha256', 'midnight', 'a-real-token' ),
);
ob_start();
agentshell_inject_saved_styles();
$css = (string) ob_get_clean();
return array(
    'renders_overrides' => false !== strpos( $css, '#00ced1' ),
);
PHP
        );

        Agentshell_Test::assert_true( ! isset( $result['error'] ), 'the case ran: ' . ( $result['error'] ?? '' ) );
        if ( isset( $result['error'] ) ) { return; }
        Agentshell_Test::assert_true(
            $result['renders_overrides'],
            'a key signed with the real token unlocks the profile'
        );
    }
);

// ── 4. one rule ────────────────────────────────────────────────────────────
Agentshell_Test::run(
    'the theme and the plugin ask the same question for the secret',
    function () {
        $source = (string) file_get_contents( dirname( __DIR__ ) . '/functions.php' );
        Agentshell_Test::assert_true(
            false !== strpos( $source, 'agentshell_preview_secret()' ),
            'the theme asks one function for the secret'
        );
        Agentshell_Test::assert_false(
            false !== strpos(
                $source,
                "defined( 'AGENTSHELL_REST_TOKEN' ) && isset( \$_GET['agentshell_preview_key'] )"
            ),
            'the theme no longer asks only whether the constant is defined'
        );

        $tool = (string) file_get_contents( dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-screenshot.php' );
        Agentshell_Test::assert_true(
            false !== strpos( $tool, 'Transaction_Manager::preview_secret()' ),
            'the tool asks the owner of the rule for the secret'
        );
        Agentshell_Test::assert_false(
            false !== strpos( $tool, "if ( ! defined( 'AGENTSHELL_REST_TOKEN' ) ) {" ),
            'the tool no longer asks only whether the constant is defined'
        );
        // The theme's helper must *be* the owner's answer when the plugin
        // is loaded, or the two would drift apart again.
        require_once __DIR__ . '/../agentshell-mcp/includes/class-store.php';
        require_once __DIR__ . '/../agentshell-mcp/includes/class-transactions.php';
        Agentshell_Test::assert_equals(
            (string) \AgentShell_MCP\Transaction_Manager::preview_secret(),
            (string) agentshell_preview_secret(),
            'the theme and the owner agree on the secret'
        );
    }
);

Agentshell_Test::summary();
