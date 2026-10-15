<?php
/**
 * Configuration-resolution consistency regressions.
 *
 * The split this pins shut, in one paragraph: `Base_Tool::get_agentshell_config()`
 * returned the transaction-effective config while `agentshell_get_config()` —
 * the theme's reader, and the basis of `agentshell_get_widget_registry()` and
 * `agentshell_get_zones()` — returned the committed one. Every tool that wrote
 * through the first and every tool that *guarded* through the second therefore
 * disagreed inside the same transaction. The documented sequence
 *
 *     begin_transaction → register_widget("hero") → enable_widget("hero")
 *
 * could not complete: enable_widget's existence guard asked a registry that had
 * never seen the widget the previous call staged, and threw "unknown widget".
 * The same split meant `agentshell_validate_transaction` validated a
 * configuration that excluded the staged widget, so the transaction previewed
 * clean and committed something validation never examined.
 *
 * A second, quieter half: a render read design tokens from the staged config
 * and zone composition from the committed one, so a transaction preview showed
 * the colours the agent had chosen and the layout it had not. Both halves are
 * the same mistake — a reader that chose its state implicitly.
 *
 * The contract these tests hold:
 *   - `agentshell_get_config( 'committed' )` is what visitors see and what
 *     survives a rollback. It is the default, and nothing changed for callers
 *     that pass nothing.
 *   - `agentshell_get_config( 'effective' )` is what a tool should act on.
 *   - `agentshell_get_widget_registry( $config )` and `agentshell_get_zones(
 *     $config )` take the config explicitly; passing null means committed.
 *   - Rendering resolves through `agentshell_config_for_render()`, which
 *     substitutes staged values only for a request that has already passed the
 *     transaction-preview credential gate.
 *
 * Most cases here are about a *refusal*: an ordinary request, or one without a
 * valid credential, must not see staged state. Those are the assertions that
 * fail loudly if the contract is later loosened for convenience.
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'AUTH_SALT' ) ) { define( 'AUTH_SALT', 'config-consistency-suite-auth-salt' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MB_IN_BYTES' ) ) { define( 'MB_IN_BYTES', 1048576 ); }

if ( ! function_exists( 'wp_mkdir_p' ) ) { function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0755, true ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
if ( ! function_exists( 'nocache_headers' ) ) { function nocache_headers() { $GLOBALS['_agentshell_test_captured_headers'][] = 'Cache-Control: no-cache'; } }
if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return false; } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $k ) ); } }

$GLOBALS['_agentshell_test_login'] = 'agent';
$GLOBALS['wp_version']              = '6.5';

require_once dirname( __DIR__ ) . '/functions.php';
foreach ( array(
    'class-store.php', 'class-doctor.php', 'class-content-write-guard.php',
    'class-content-journal.php', 'class-recovery-advisory-lock.php',
    'class-content-recovery.php',
    'class-transactions.php',
) as $inc ) {
    require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/' . $inc;
}
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-base-tool.php';
foreach ( array(
    'class-register-widget.php', 'class-enable-widget.php', 'class-disable-widget.php',
    'class-remove-widget.php', 'class-list-widgets.php', 'class-get-config.php',
    'class-list-zones.php', 'class-inspect.php', 'class-update-zone-composition.php',
) as $inc ) {
    require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/' . $inc;
}
require_once dirname( __DIR__ ) . '/agentshell-blocks/includes/class-widget-registry.php';
// agentshell_get_zone_config() is the resolver the shell itself uses, so the
// preview assertions go through it too — asserting only on the theme helper
// would leave the function that actually builds a page unexamined.
require_once dirname( __DIR__ ) . '/template-parts/shell-render.php';

use AgentShell_MCP\Transaction_Manager as Tx;
use AgentShell_Blocks\Widget_Registry as BlocksRegistry;

/**
 * What the shared option store looked like before this file ran.
 *
 * run-all.php runs the suites in one process, so the fixture globals this file
 * writes are still live for the next suite. A transaction left open at the end
 * of this file is not a tidy-up detail: agentshell_config writes are cancelled
 * while one is open, so the next suite would quietly see a site on which every
 * configuration write fails.
 */
$GLOBALS['_cc_options_on_entry'] = $GLOBALS['_agentshell_test_options'];
$GLOBALS['_cc_tx_on_entry']       = agentshell_transaction_open();

echo "configuration consistency regression tests\n";

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Reset to a known committed config and drop every preview credential.
 *
 * The transaction payload is cached on a singleton, and an option reset does
 * not clear it, so the instance has to be nulled explicitly — otherwise a
 * rolled-back transaction from the previous test is still "open".
 */
function cc_reset( array $config = null ) {
    agentshell_test_reset();
    $_GET = array();
    $GLOBALS['agentshell_transaction_preview'] = array();
    $GLOBALS['agentshell_post_preview']        = array();
    unset( $GLOBALS['_agentshell_test_options']['agentshell_widgets'] );
    if ( null === $config ) {
        $config = cc_baseline_config();
    }
    $GLOBALS['_agentshell_test_options']['agentshell_config'] = $config;
    cc_forget_transaction();
    cc_forget_blocks_registry();
}

/**
 * Drop the blocks plugin's memoised registry.
 *
 * It caches the widget list for the life of the request, so without this a test
 * that changes the config sees the previous test's answer — and would pass for
 * the wrong reason.
 */
function cc_forget_blocks_registry() {
    $singleton = new ReflectionProperty( BlocksRegistry::class, 'instance' );
    $singleton->setAccessible( true );
    $singleton->setValue( null, null );
}

function cc_baseline_config() {
    return array(
        'design'    => array( 'colors' => array( 'primary' => '#111111' ) ),
        'zones'     => array(
            array( 'id' => 'main', 'composition' => array( array( 'type' => 'wp_loop' ) ) ),
        ),
        'widgets'   => array(
            array( 'id' => 'existing', 'name' => 'Existing widget', 'template' => '<div>existing</div>' ),
        ),
        'widget_overrides' => array(),
    );
}

/**
 * Drop the transaction singleton.
 *
 * The manager's write hook is registered once per request against a static
 * dispatcher, so replacing the singleton leaves nothing to unhook — the hook
 * resolves the current instance each time. An instance bound hook *would* need
 * removing here: apply_filters() threads each callback's return value into the
 * next, so a stale callback sees the previous one's answer rather than the
 * value passed in, and the manager memoises its payload, so a replaced instance
 * believes a transaction is still open forever.
 */
function cc_forget_transaction() {
    $ref = new ReflectionProperty( Tx::class, 'instance' );
    $ref->setAccessible( true );
    $ref->setValue( null, null );
}

/**
 * Authorised preview credentials, signed with a distinct expiry each call.
 *
 * The expiry is varied deliberately: agentshell_authorize_transaction_preview()
 * memoises its verdict per credential, so two cases that reuse one expiry
 * would share a verdict and the second would prove nothing.
 */
function cc_preview_args( $offset = 0 ) {
    $tx    = Tx::instance()->payload();
    $exp   = time() + 900 + $offset;
    $sign  = new ReflectionMethod( Tx::class, 'sign_preview' );
    $sign->setAccessible( true );
    return array(
        'agentshell_tx'     => (string) $tx['id'],
        'agentshell_tx_exp' => (string) $exp,
        'agentshell_tx_key' => $sign->invoke( null, $tx['id'], $tx['actor'], $exp, Tx::preview_secret() ),
    );
}

function cc_tool( $class ) {
    return new $class();
}

/**
 * Widget ids from either shape a registry arrives in.
 *
 * The theme's registry is keyed by id; the blocks plugin re-indexes it with
 * array_values() before handing it out, which is exactly the difference between
 * "the widget is there" and "the widget is there under a name you cannot read".
 */
function cc_widget_ids( array $registry ) {
    $ids = array();
    foreach ( $registry as $key => $widget ) {
        $ids[] = is_array( $widget ) && isset( $widget['id'] ) ? $widget['id'] : $key;
    }
    return $ids;
}

// ---------------------------------------------------------------------------
// 1. register_widget → enable_widget inside one transaction.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'register then enable a widget in one transaction succeeds',
    function () {
        cc_reset();
        Tx::instance()->begin( 'widget lifecycle' );

        cc_tool( '\AgentShell_MCP\Tools\Register_Widget' )->execute(
            array( 'id' => 'hero', 'name' => 'Hero', 'template' => '<div>hero</div>' )
        );

        // The assertion the issue is about. Before the fix this threw
        // "Unknown widget 'hero'" because the guard read the committed
        // registry, which had never seen the widget staged a moment earlier.
        $result = cc_tool( '\AgentShell_MCP\Tools\Enable_Widget' )->execute( array( 'id' => 'hero' ) );

        Agentshell_Test::assert_equals( 'hero', $result['widget'] );
        Agentshell_Test::assert_equals( 'active', $result['status'] );
    }
);

Agentshell_Test::run(
    'the staged widget is visible to the next tool in the transaction',
    function () {
        cc_reset();
        Tx::instance()->begin( 'widget lifecycle' );
        cc_tool( '\AgentShell_MCP\Tools\Register_Widget' )->execute(
            array( 'id' => 'hero', 'name' => 'Hero', 'template' => '<div>hero</div>' )
        );

        $listed = cc_tool( '\AgentShell_MCP\Tools\List_Widgets' )->execute( array() );
        $ids    = array_column( $listed['widgets'], 'id' );

        Agentshell_Test::assert_true( in_array( 'hero', $ids, true ), 'list_widgets must see the staged widget' );
        Agentshell_Test::assert_true( in_array( 'existing', $ids, true ), 'and the pre-existing one' );
    }
);

Agentshell_Test::run(
    'get_config reports the staged registry, not the committed one',
    function () {
        cc_reset();
        Tx::instance()->begin( 'widget lifecycle' );
        cc_tool( '\AgentShell_MCP\Tools\Register_Widget' )->execute(
            array( 'id' => 'hero', 'name' => 'Hero', 'template' => '<div>hero</div>' )
        );

        $config = cc_tool( '\AgentShell_MCP\Tools\Get_Config' )->execute( array() );
        $ids    = array_column( $config['widgets'], 'id' );

        Agentshell_Test::assert_true(
            in_array( 'hero', $ids, true ),
            'the response would otherwise describe a different transaction than the one staged'
        );
    }
);

// ---------------------------------------------------------------------------
// 2. remove / disable before commit.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'a widget registered then disabled in one transaction stages disabled',
    function () {
        cc_reset();
        Tx::instance()->begin( 'widget lifecycle' );
        cc_tool( '\AgentShell_MCP\Tools\Register_Widget' )->execute(
            array( 'id' => 'hero', 'name' => 'Hero' )
        );
        cc_tool( '\AgentShell_MCP\Tools\Disable_Widget' )->execute( array( 'id' => 'hero' ) );

        $registry = agentshell_get_widget_registry( agentshell_get_config( 'effective' ) );
        Agentshell_Test::assert_equals( 'disabled', $registry['hero']['status'] );

        // ...and the committed state has not moved.
        $committed = agentshell_get_widget_registry();
        Agentshell_Test::assert_false( isset( $committed['hero'] ), 'nothing is committed yet' );
    }
);

Agentshell_Test::run(
    'a widget registered then removed in one transaction leaves no trace',
    function () {
        cc_reset();
        Tx::instance()->begin( 'widget lifecycle' );
        cc_tool( '\AgentShell_MCP\Tools\Register_Widget' )->execute(
            array( 'id' => 'hero', 'name' => 'Hero' )
        );
        $result = cc_tool( '\AgentShell_MCP\Tools\Remove_Widget' )->execute( array( 'id' => 'hero' ) );

        Agentshell_Test::assert_equals( 'hero', $result['removed'] );

        $registry = agentshell_get_widget_registry( agentshell_get_config( 'effective' ) );
        Agentshell_Test::assert_false( isset( $registry['hero'] ), 'the staged registry still has it' );
        Agentshell_Test::assert_true( isset( $registry['existing'] ), 'and must not have lost the others' );
    }
);

// ---------------------------------------------------------------------------
// 3. Validation sees the staged state.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'validate_transaction accepts a transaction that registered its own widget',
    function () {
        cc_reset();
        Tx::instance()->begin( 'widget lifecycle' );
        cc_tool( '\AgentShell_MCP\Tools\Register_Widget' )->execute(
            array( 'id' => 'hero', 'name' => 'Hero' )
        );
        // A zone referencing the staged widget, so validation has something to
        // be wrong about in both directions. validate() throws rather than
        // returning, so "no exception" is the assertion.
        cc_tool( '\AgentShell_MCP\Tools\Update_Zone_Composition' )->execute( array(
            'zone_id'     => 'main',
            'composition' => array( array( 'type' => 'widget', 'id' => 'hero' ) ),
        ) );

        $threw = null;
        try {
            Tx::instance()->validate();
        } catch ( \InvalidArgumentException $e ) {
            $threw = $e->getMessage();
        }

        Agentshell_Test::assert_true(
            null === $threw,
            'validation resolved the staged zone against the committed registry: ' . (string) $threw
        );
    }
);

Agentshell_Test::run(
    'validate_transaction still catches a zone referencing a widget that does not exist',
    function () {
        cc_reset();
        Tx::instance()->begin( 'widget lifecycle' );
        cc_tool( '\AgentShell_MCP\Tools\Update_Zone_Composition' )->execute( array(
            'zone_id'     => 'main',
            'composition' => array( array( 'type' => 'widget', 'id' => 'nowhere' ) ),
        ) );

        $message = null;
        try {
            Tx::instance()->validate();
        } catch ( \InvalidArgumentException $e ) {
            $message = $e->getMessage();
        }

        Agentshell_Test::assert_true(
            null !== $message && str_contains( $message, 'WIDGET_REFERENCE_UNKNOWN' ),
            'a resolver that ignored its argument would pass this'
        );
    }
);

Agentshell_Test::run(
    'commit is blocked by a staged zone referencing a widget that does not exist',
    function () {
        cc_reset();
        Tx::instance()->begin( 'widget lifecycle' );
        cc_tool( '\AgentShell_MCP\Tools\Update_Zone_Composition' )->execute( array(
            'zone_id'     => 'main',
            'composition' => array( array( 'type' => 'widget', 'id' => 'nowhere' ) ),
        ) );

        $blocked = false;
        try {
            Tx::instance()->commit();
        } catch ( \InvalidArgumentException $e ) {
            $blocked = true;
        }
        Agentshell_Test::assert_true( $blocked, 'commit must examine what it is about to write' );
    }
);

// ---------------------------------------------------------------------------
// 4. Ordinary rendering ignores uncommitted state.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'an ordinary render sees neither the staged widget nor the staged zone',
    function () {
        cc_reset();
        Tx::instance()->begin( 'widget lifecycle' );
        cc_tool( '\AgentShell_MCP\Tools\Register_Widget' )->execute(
            array( 'id' => 'hero', 'name' => 'Hero', 'template' => '<div>hero</div>' )
        );
        cc_tool( '\AgentShell_MCP\Tools\Update_Zone_Composition' )->execute( array(
            'zone_id'     => 'main',
            'composition' => array( array( 'type' => 'widget', 'id' => 'hero' ) ),
        ) );

        $registry = agentshell_widget_registry_for_render();
        $zones    = agentshell_zones_for_render();

        Agentshell_Test::assert_false( isset( $registry['hero'] ), 'a visitor must not see a staged widget' );

        $main = null;
        foreach ( $zones as $zone ) {
            if ( 'main' === ( $zone['id'] ?? '' ) ) { $main = $zone; }
        }
        Agentshell_Test::assert_equals(
            'wp_loop',
            $main['composition'][0]['type'],
            'a visitor must not see a staged composition'
        );
        Agentshell_Test::assert_equals(
            'wp_loop',
            agentshell_get_zone_config( 'main' )['composition'][0]['type'],
            'and the shell\'s own zone resolver agrees'
        );
    }
);

Agentshell_Test::run(
    'a render with no transaction open is the committed config',
    function () {
        cc_reset();
        Agentshell_Test::assert_equals(
            '#111111',
            agentshell_config_for_render()['design']['colors']['primary']
        );
    }
);

// ---------------------------------------------------------------------------
// 5. An authorised preview renders the staged widget and zone.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'an authorised preview renders the staged widget and the staged zone',
    function () {
        cc_reset();
        Tx::instance()->begin( 'widget lifecycle' );
        cc_tool( '\AgentShell_MCP\Tools\Register_Widget' )->execute(
            array( 'id' => 'hero', 'name' => 'Hero', 'template' => '<div>hero</div>' )
        );
        cc_tool( '\AgentShell_MCP\Tools\Update_Zone_Composition' )->execute( array(
            'zone_id'     => 'main',
            'composition' => array( array( 'type' => 'widget', 'id' => 'hero' ) ),
        ) );

        $_GET = cc_preview_args( 1 );

        $registry = agentshell_widget_registry_for_render();
        $zones    = agentshell_zones_for_render();

        Agentshell_Test::assert_true(
            isset( $registry['hero'] ),
            'the preview is the one request allowed to see staged widgets'
        );

        $main = null;
        foreach ( $zones as $zone ) {
            if ( 'main' === ( $zone['id'] ?? '' ) ) { $main = $zone; }
        }
        Agentshell_Test::assert_equals(
            'widget',
            $main['composition'][0]['type'],
            'a preview that showed staged colours but committed zones would fail here'
        );
        Agentshell_Test::assert_equals(
            'widget',
            agentshell_get_zone_config( 'main' )['composition'][0]['type'],
            'the shell resolver must resolve against the same effective config'
        );
    }
);

Agentshell_Test::run(
    'the widget renderer produces the staged widget under an authorised preview',
    function () {
        cc_reset();
        Tx::instance()->begin( 'widget lifecycle' );
        cc_tool( '\AgentShell_MCP\Tools\Register_Widget' )->execute(
            array( 'id' => 'hero', 'name' => 'Hero', 'template' => '<div>hero-preview</div>' )
        );

        $_GET = cc_preview_args( 2 );
        $html = agentshell_render_widget( 'hero' );

        $_GET = array();
        Agentshell_Test::assert_true(
            str_contains( $html, 'hero-preview' ),
            'got: ' . $html
        );
    }
);

/*
 * The test above calls the theme's agentshell_render_widget() directly, which
 * is NOT the path production takes: template-parts/shell-render.php fires
 * do_action( 'agentshell_render_widget', $id ) and the agentshell-blocks plugin
 * answers it. Calling the theme helper proved the theme resolver was
 * preview-aware and nothing more — it passed while the live preview rendered
 * "Widget not found" for the widget the page had just asked for, because the
 * plugin resolved the same id against the committed registry.
 *
 * These call Widget_Registry::render_widget(), which is exactly what that hook
 * handler calls. agentshell_render_block() cannot be used to get here: the test
 * bootstrap stubs do_action() as a no-op, so nothing is ever dispatched to the
 * handler. Calling the handler's target is the honest equivalent.
 */
Agentshell_Test::run(
    'the blocks plugin renders the staged widget under an authorised preview',
    function () {
        cc_reset();
        Tx::instance()->begin( 'widget lifecycle' );
        cc_tool( '\AgentShell_MCP\Tools\Register_Widget' )->execute(
            array( 'id' => 'hero', 'name' => 'Hero', 'template' => '<div>hero-plugin-preview</div>' )
        );

        $_GET = cc_preview_args( 3 );
        $html = BlocksRegistry::get_instance()->render_widget( 'hero' );
        $_GET = array();

        Agentshell_Test::assert_true(
            str_contains( $html, 'hero-plugin-preview' ),
            'the plugin renderer must see what the zone resolver staged; got: ' . $html
        );
        Agentshell_Test::assert_false(
            str_contains( $html, 'Widget not found' ),
            'a preview must never report a widget it just resolved as missing; got: ' . $html
        );
    }
);

Agentshell_Test::run(
    'the blocks plugin renders no staged widget for an ordinary visitor',
    function () {
        cc_reset();
        Tx::instance()->begin( 'widget lifecycle' );
        cc_tool( '\AgentShell_MCP\Tools\Register_Widget' )->execute(
            array( 'id' => 'hero', 'name' => 'Hero', 'template' => '<div>hero-plugin-preview</div>' )
        );

        // No credential at all.
        $_GET = array();
        $html = BlocksRegistry::get_instance()->render_widget( 'hero' );

        Agentshell_Test::assert_false(
            str_contains( $html, 'hero-plugin-preview' ),
            'staged markup must not reach an anonymous request; got: ' . $html
        );
    }
);

/*
 * A widget's scoped CSS and init_js travel with its markup. The theme's
 * agentshell_get_widget_assets() has always read them from the preview-aware
 * registry, so a staged widget's CSS appearing or not appearing is the same
 * "did this request see staged state" decision as the markup — one answer for
 * both halves.
 */
Agentshell_Test::run(
    "a staged widget's scoped CSS follows the same preview decision as its markup",
    function () {
        cc_reset();
        Tx::instance()->begin( 'widget lifecycle' );
        cc_tool( '\AgentShell_MCP\Tools\Register_Widget' )->execute(
            array(
                'id'       => 'hero',
                'name'     => 'Hero',
                'template' => '<div>hero-plugin-preview</div>',
                'css'      => '.hero-preview-probe { color: rebeccapurple; }',
            )
        );

        $_GET = cc_preview_args( 4 );
        $preview_css = BlocksRegistry::get_instance()->get_all_css();
        $_GET = array();
        $visitor_css = BlocksRegistry::get_instance()->get_all_css();

        Agentshell_Test::assert_true(
            str_contains( $preview_css, 'hero-preview-probe' ),
            'an authorised preview must style the widget it is allowed to render; got: ' . $preview_css
        );
        Agentshell_Test::assert_false(
            str_contains( $visitor_css, 'hero-preview-probe' ),
            'staged CSS must not reach an anonymous request; got: ' . $visitor_css
        );
    }
);

// ---------------------------------------------------------------------------
// 6/7. Rollback and commit.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'rollback restores committed behaviour and the staged widget never appears',
    function () {
        cc_reset();
        Tx::instance()->begin( 'widget lifecycle' );
        cc_tool( '\AgentShell_MCP\Tools\Register_Widget' )->execute(
            array( 'id' => 'hero', 'name' => 'Hero', 'template' => '<div>hero</div>' )
        );
        cc_tool( '\AgentShell_MCP\Tools\Update_Zone_Composition' )->execute( array(
            'zone_id'     => 'main',
            'composition' => array( array( 'type' => 'widget', 'id' => 'hero' ) ),
        ) );

        Tx::instance()->rollback();
        cc_forget_transaction();
        $_GET = array();

        Agentshell_Test::assert_false(
            isset( agentshell_widget_registry_for_render()['hero'] ),
            'the widget survived a rollback'
        );
        $zones = agentshell_zones_for_render();
        $main  = null;
        foreach ( $zones as $zone ) {
            if ( 'main' === ( $zone['id'] ?? '' ) ) { $main = $zone; }
        }
        Agentshell_Test::assert_equals( 'wp_loop', $main['composition'][0]['type'] );
    }
);

Agentshell_Test::run(
    'commit makes the staged widget visible to an ordinary render',
    function () {
        cc_reset();
        Tx::instance()->begin( 'widget lifecycle' );
        cc_tool( '\AgentShell_MCP\Tools\Register_Widget' )->execute(
            array( 'id' => 'hero', 'name' => 'Hero', 'template' => '<div>hero</div>' )
        );
        Tx::instance()->commit();
        cc_forget_transaction();
        $_GET = array();

        $registry = agentshell_widget_registry_for_render();
        Agentshell_Test::assert_true( isset( $registry['hero'] ), 'a committed widget must render normally' );
        Agentshell_Test::assert_true( isset( $registry['existing'] ), 'without losing the existing one' );
    }
);

// ---------------------------------------------------------------------------
// 8/9. Existing data.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'existing widgets survive a transaction that adds another',
    function () {
        cc_reset();
        Tx::instance()->begin( 'widget lifecycle' );
        cc_tool( '\AgentShell_MCP\Tools\Register_Widget' )->execute(
            array( 'id' => 'hero', 'name' => 'Hero' )
        );
        Tx::instance()->commit();
        cc_forget_transaction();

        $ids = cc_widget_ids( agentshell_get_widget_registry() );
        Agentshell_Test::assert_true( in_array( 'existing', $ids, true ) );
        Agentshell_Test::assert_true( in_array( 'hero', $ids, true ) );
        Agentshell_Test::assert_true( in_array( 'hello-world', $ids, true ), 'and the file widget' );
    }
);

Agentshell_Test::run(
    'legacy agentshell_widgets data is reconciled into the canonical store',
    function () {
        cc_reset();
        $GLOBALS['_agentshell_test_options']['agentshell_widgets'] = array(
            array( 'id' => 'legacy', 'name' => 'Legacy widget', 'template' => '<div>legacy</div>' ),
        );

        agentshell_migrate_legacy_widgets_option();

        $ids = cc_widget_ids( agentshell_get_widget_registry() );
        Agentshell_Test::assert_true( in_array( 'legacy', $ids, true ) );
        Agentshell_Test::assert_false( get_option( 'agentshell_widgets' ), 'the legacy option is retired' );
    }
);

Agentshell_Test::run(
    'the legacy option is NOT retired when the canonical write was staged away',
    function () {
        cc_reset();
        $legacy = array( array( 'id' => 'legacy', 'name' => 'Legacy widget' ) );
        $GLOBALS['_agentshell_test_options']['agentshell_widgets'] = $legacy;

        // While a transaction is open every agentshell_config write is staged,
        // so the canonical store is not actually updated. Deleting the legacy
        // option here would destroy the only copy of the data.
        Tx::instance()->begin( 'migration guard' );
        agentshell_migrate_legacy_widgets_option();
        Tx::instance()->rollback();
        cc_forget_transaction();

        Agentshell_Test::assert_equals(
            $legacy,
            get_option( 'agentshell_widgets' ),
            'the legacy option must survive a write that never landed'
        );
    }
);

Agentshell_Test::run(
    'a config write that bypasses the tools is staged, not committed',
    function () {
        cc_reset();
        Tx::instance()->begin( 'direct write' );

        // What agentshell_migrate_legacy_widgets_option() and the blocks plugin
        // do: write agentshell_config straight through the option API. Inside
        // a transaction this must be deferred, and — because
        // pre_update_option_{$option} is a transform filter, not a veto — the
        // stored value must still be exactly what it was. Returning false from
        // that filter does not cancel anything; it stores false.
        $write = cc_baseline_config();
        $write['design']['colors']['primary'] = '#ff0000';
        update_option( 'agentshell_config', $write );

        $committed = agentshell_get_config( 'committed' );
        Agentshell_Test::assert_equals(
            '#111111',
            $committed['design']['colors']['primary'],
            'the committed config moved while a transaction was open'
        );

        $effective = agentshell_get_config( 'effective' );
        Agentshell_Test::assert_equals(
            '#ff0000',
            $effective['design']['colors']['primary'],
            'and the write was lost rather than staged'
        );

        Tx::instance()->rollback();
        cc_forget_transaction();

        Agentshell_Test::assert_equals(
            '#111111',
            agentshell_get_config( 'committed' )['design']['colors']['primary']
        );
    }
);

Agentshell_Test::run(
    'the blocks plugin reads the canonical store, not its own option',
    function () {
        cc_reset();
        // The legacy option still holds something the canonical store does not.
        $GLOBALS['_agentshell_test_options']['agentshell_widgets'] = array(
            array( 'id' => 'ghost', 'name' => 'Ghost' ),
        );

        $ids = cc_widget_ids( BlocksRegistry::get_instance()->get_widgets() );

        Agentshell_Test::assert_true( in_array( 'existing', $ids, true ), 'canonical widgets are visible' );
        Agentshell_Test::assert_false(
            in_array( 'ghost', $ids, true ),
            'a second store is exactly what this replaces'
        );
    }
);

Agentshell_Test::run(
    'the blocks plugin renderer produces the real widget, not "not found"',
    function () {
        cc_reset();
        $html = BlocksRegistry::get_instance()->render_widget( 'existing' );
        Agentshell_Test::assert_true(
            str_contains( $html, '<div>existing</div>' ),
            "got: $html"
        );
    }
);

Agentshell_Test::run(
    'the blocks plugin renderer honours the disabled status',
    function () {
        cc_reset();
        $GLOBALS['_agentshell_test_options']['agentshell_config']['widget_overrides'] = array(
            'existing' => array( 'status' => 'disabled' ),
        );
        $html = BlocksRegistry::get_instance()->render_widget( 'existing' );
        Agentshell_Test::assert_equals( '', $html );
    }
);

// ---------------------------------------------------------------------------
// 10. No unauthorised preview, no cross-request leakage.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'a request carrying no credential does not see staged state',
    function () {
        cc_reset();
        Tx::instance()->begin( 'widget lifecycle' );
        cc_tool( '\AgentShell_MCP\Tools\Register_Widget' )->execute( array( 'id' => 'hero', 'name' => 'Hero' ) );

        $_GET = array();
        Agentshell_Test::assert_false( isset( agentshell_widget_registry_for_render()['hero'] ) );
    }
);

Agentshell_Test::run(
    'a request carrying a forged key does not see staged state',
    function () {
        cc_reset();
        Tx::instance()->begin( 'widget lifecycle' );
        cc_tool( '\AgentShell_MCP\Tools\Register_Widget' )->execute( array( 'id' => 'hero', 'name' => 'Hero' ) );

        $_GET = cc_preview_args( 3 );
        $_GET['agentshell_tx_key'] = str_repeat( '0', 64 );

        Agentshell_Test::assert_false(
            isset( agentshell_widget_registry_for_render()['hero'] ),
            'the credential gate is the only thing standing between staged state and the public'
        );
    }
);

Agentshell_Test::run(
    'a validly signed credential for a different transaction is refused',
    function () {
        cc_reset();
        Tx::instance()->begin( 'the open one' );
        cc_tool( '\AgentShell_MCP\Tools\Register_Widget' )->execute( array( 'id' => 'hero', 'name' => 'Hero' ) );

        // Signed with the real secret over the real actor — everything a
        // legitimate credential has — but naming a transaction that is not the
        // open one. The gate binds to the open transaction, so this must fail.
        $tx   = Tx::instance()->payload();
        $exp  = time() + 900;
        $sign = new ReflectionMethod( Tx::class, 'sign_preview' );
        $sign->setAccessible( true );

        $_GET = array(
            'agentshell_tx'     => 'tx_not_the_open_one',
            'agentshell_tx_exp' => (string) $exp,
            'agentshell_tx_key' => $sign->invoke( null, 'tx_not_the_open_one', $tx['actor'], $exp, Tx::preview_secret() ),
        );

        Agentshell_Test::assert_false(
            isset( agentshell_widget_registry_for_render()['hero'] ),
            'a correct signature over the wrong transaction is still the wrong transaction'
        );
    }
);

Agentshell_Test::run(
    'a closed transaction stops authorising immediately',
    function () {
        cc_reset();
        Tx::instance()->begin( 'widget lifecycle' );
        cc_tool( '\AgentShell_MCP\Tools\Register_Widget' )->execute( array( 'id' => 'hero', 'name' => 'Hero' ) );
        $args = cc_preview_args( 5 );

        Tx::instance()->rollback();
        cc_forget_transaction();

        $_GET = $args;
        Agentshell_Test::assert_false( isset( agentshell_widget_registry_for_render()['hero'] ) );
    }
);

// ---------------------------------------------------------------------------
// The contract itself.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'an unrecognised configuration source is refused, not silently defaulted',
    function () {
        cc_reset();
        $threw = false;
        try {
            agentshell_get_config( 'staged' );
        } catch ( \InvalidArgumentException $e ) {
            $threw = true;
        }
        Agentshell_Test::assert_true(
            $threw,
            'a typo that fell back to committed would restore the original defect silently'
        );
    }
);

Agentshell_Test::run(
    'passing no configuration to the registry or zones means committed',
    function () {
        cc_reset();
        Tx::instance()->begin( 'defaults' );
        cc_tool( '\AgentShell_MCP\Tools\Register_Widget' )->execute( array( 'id' => 'hero', 'name' => 'Hero' ) );

        Agentshell_Test::assert_false( isset( agentshell_get_widget_registry()['hero'] ) );
        Agentshell_Test::assert_true( isset( agentshell_get_widget_registry( agentshell_get_config( 'effective' ) )['hero'] ) );
    }
);

// ── teardown ────────────────────────────────────────────────────────────────
// Hand the shared fixtures back exactly as they were found, and prove it.
cc_forget_transaction();
$GLOBALS['_agentshell_test_options'] = $GLOBALS['_cc_options_on_entry'];
$_GET = array();

Agentshell_Test::run(
    'teardown: the shared fixtures are handed back untouched',
    function () {
        // Compared against what was here on entry, not against false: whether
        // an earlier suite left a transaction open is not this file's business.
        Agentshell_Test::assert_equals(
            $GLOBALS['_cc_tx_on_entry'],
            agentshell_transaction_open(),
            'a transaction left open here would cancel every config write in the next suite'
        );
        Agentshell_Test::assert_equals(
            $GLOBALS['_cc_options_on_entry'],
            $GLOBALS['_agentshell_test_options'],
            'the option store this file ran against is not the one it found'
        );
    }
);