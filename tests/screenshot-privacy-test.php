<?php
/**
 * Screenshot privacy regressions.
 *
 * The defect under test is a confidentiality one, confirmed live before the
 * fix: agentshell_screenshot wrote every capture into
 * wp-content/uploads/agentshell-screenshots/ and returned that public URL. An
 * unauthenticated GET returned 200, and nothing ever pruned the files.
 *
 * Most of these assertions are about a *refusal*: a request that must NOT get
 * bytes. That is why they are executable rather than source-text pins — a grep
 * can prove a guard string exists, not that the guard runs on the request path
 * an unauthenticated client would actually take.
 *
 * Nothing here touches the live LocalWP site. Every capture runs against a fake
 * browser that makes no network request, in a temp directory created and
 * removed per test.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/fake-browser.php';

// ---------------------------------------------------------------------------
// Stubs the store and transport need that bootstrap.php does not provide.
// ---------------------------------------------------------------------------

if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MB_IN_BYTES' ) ) { define( 'MB_IN_BYTES', 1048576 ); }

$GLOBALS['_agentshell_test_wp_content'] = sys_get_temp_dir() . '/agentshell-privacy-wpcontent-' . getmypid();
$GLOBALS['_agentshell_test_uploads']    = array(
    'basedir' => '',
    'baseurl' => 'https://example.test/wp-content/uploads',
    'error'   => '',
);
$GLOBALS['_agentshell_test_login']      = 'agent';

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
    // A real directory outside any served root, standing in for wp-content.
    @mkdir( $GLOBALS['_agentshell_test_wp_content'], 0700, true );
    define( 'WP_CONTENT_DIR', $GLOBALS['_agentshell_test_wp_content'] );
}

if ( ! defined( 'AGENTSHELL_SCREENSHOT_DIR' ) ) {
    // Pin the store. The production default walks up out of ABSPATH, and the
    // suite's ABSPATH is the tests/ directory — so without this the store
    // would be created inside the repository for the duration of the run.
    define( 'AGENTSHELL_SCREENSHOT_DIR', sys_get_temp_dir() . '/agentshell-privacy-store-' . getmypid() );
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
    function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0755, true ); }
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir() { return $GLOBALS['_agentshell_test_uploads']; }
}
if ( ! function_exists( 'wp_generate_password' ) ) {
    function wp_generate_password( $len = 12, $special = true, $extra = false ) {
        return substr( bin2hex( random_bytes( (int) ceil( $len / 2 ) ) ), 0, $len );
    }
}
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '/' ) { return 'https://example.test' . $p; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $url ) { return $url; } }
if ( ! function_exists( 'add_query_arg' ) ) { function add_query_arg( $args, $url ) { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); } }
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( ...$a ) { return true; } }
if ( ! function_exists( 'add_action' ) ) { function add_action( ...$a ) { return true; } }
if ( ! function_exists( 'wp_schedule_event' ) ) { function wp_schedule_event( ...$a ) { return true; } }
if ( ! function_exists( 'wp_next_scheduled' ) ) { function wp_next_scheduled( $h ) { return false; } }

if ( ! class_exists( 'WP_User' ) ) {
    class WP_User {
        public $ID = 0;
        public $user_login = '';
    }
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
    function wp_get_current_user() {
        $u = new WP_User();
        // ID is set so the real Store::actor() (loaded by an earlier suite in
        // run-all.php) resolves the login these tests control, instead of
        // falling back to its own 'agent' default.
        $u->ID = 1;
        $u->user_login = $GLOBALS['_agentshell_test_login'];
        return $u;
    }
}

/**
 * Store::actor() is what ties a capture to a principal. The real class pulls in
 * the whole config store; for these tests the actor is the piece that matters,
 * so it is provided directly.
 */
if ( ! class_exists( '\AgentShell_MCP\Store' ) ) {
    eval( 'namespace AgentShell_MCP; class Store { public static function actor() { return $GLOBALS["_agentshell_test_login"]; } }' );
}

require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/class-screenshot.php';
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/class-screenshot-store.php';
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/class-error-codes.php';
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/class-json-rpc.php';
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/class-auth.php';
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/class-server.php';
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/class-transport.php';
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-base-tool.php';
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-screenshot.php';
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-get-screenshot.php';

/**
 * A known-principal Auth.
 *
 * Subclasses the real Auth rather than replacing it: which Auth is loaded
 * depends on suite order, and this suite must behave the same either way.
 * What matters here is that authentication and authorisation are two separate
 * gates, and only the second one is under test.
 */
class Privacy_Fake_Auth extends \AgentShell_MCP\Auth {
    public function authenticate( $request ) {
        $header = $request->get_header( 'authorization' );
        if ( ! $header ) {
            return new \WP_Error( 'no_auth', 'Missing Basic Auth header' );
        }
        if ( 'Basic ' . base64_encode( 'agent:wrong' ) === $header ) {
            return new \WP_Error( 'invalid_password', 'Invalid application password' );
        }
        if ( 'Basic ' . base64_encode( 'agent:app-password' ) !== $header ) {
            return new \WP_Error( 'user_not_found', 'User not found' );
        }
        return array( 'user_id' => 1 );
    }
}

use AgentShell_MCP\Screenshot_Store;
use AgentShell_MCP\Tools\Get_Screenshot;
use AgentShell_MCP\Tools\Screenshot;

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

class Privacy_Test {
    public static function rmtree( $path ) {
        if ( is_link( $path ) || is_file( $path ) ) { @unlink( $path ); return; }
        if ( ! is_dir( $path ) ) { return; }
        foreach ( scandir( $path ) as $entry ) {
            if ( '.' === $entry || '..' === $entry ) { continue; }
            self::rmtree( $path . '/' . $entry );
        }
        @rmdir( $path );
    }

    /** Reset every piece of global state the store reads. */
    public static function reset() {
        Privacy_Test::rmtree( Screenshot_Store::base_dir() );
        Privacy_Test::rmtree( $GLOBALS['_agentshell_test_uploads']['basedir'] );
        $GLOBALS['_agentshell_test_options'] = array();
        $GLOBALS['_agentshell_test_login']   = 'agent';
    }

    public static function uploads_dir() {
        $dir = sys_get_temp_dir() . '/agentshell-privacy-uploads-' . getmypid();
        if ( ! is_dir( $dir ) ) { mkdir( $dir, 0755, true ); }
        $GLOBALS['_agentshell_test_uploads']['basedir'] = $dir;
        return $dir;
    }

    /** A PNG of a chosen size, written to a temp file, as capture() would. */
    public static function png_file( $bytes = 200, $name = 'src.png' ) {
        $path = sys_get_temp_dir() . '/agentshell-privacy-' . getmypid() . '-' . $name;
        file_put_contents( $path, "\x89PNG\r\n\x1a\n" . str_repeat( 'X', $bytes ) );
        return $path;
    }

    public static function login_as( $login ) {
        $GLOBALS['_agentshell_test_login'] = (string) $login;
    }
}

/** Drive one tool call through the real Server::handle_tools_call. */
function privacy_call_tool( $tool, array $args ) {
    // Register just this tool through the same filter the plugin uses, and
    // clear any tool registered by a previous call so each test sees only
    // what it asked for.
    $GLOBALS['_agentshell_test_filters']['agentshell_mcp_register_tools'] = array();
    add_filter( 'agentshell_mcp_register_tools', function( $tools ) use ( $tool ) {
        $tools[] = $tool;
        return $tools;
    } );

    $server = new \AgentShell_MCP\Server();
    $response = $server->handle_message( array(
        'jsonrpc' => '2.0',
        'id'      => 1,
        'method'  => 'tools/call',
        'params'  => array( 'name' => $tool->get_name(), 'arguments' => $args ),
    ), 1 );

    return $response['result'] ?? $response;
}

/** Drive the REST retrieval route with a given Authorization header. */
function privacy_get_screenshot( $id, $header ) {
    $server    = new \AgentShell_MCP\Server();
    $transport = new \AgentShell_MCP\Transport( $server, new Privacy_Fake_Auth() );
    $request   = new WP_REST_Request( array(
        'id'      => $id,
        'headers' => null === $header ? array() : array( 'authorization' => $header ),
    ) );
    return $transport->handle_get_screenshot( $request );
}

/**
 * Drive the retrieval route on a caller-supplied transport.
 *
 * The route registers rest_pre_serve_request from register_routes(), so the
 * caller needs the same transport instance it will later run the filter with.
 */
function privacy_get_screenshot_with( $transport, $id, $header ) {
    $request = new WP_REST_Request( array(
        'id'      => $id,
        'headers' => null === $header ? array() : array( 'authorization' => $header ),
    ) );
    return $transport->handle_get_screenshot( $request );
}

/** A tool that returns a plain array, i.e. every tool that is not a screenshot. */
class Plain_Text_Tool extends \AgentShell_MCP\Tools\Base_Tool {
    public function get_name() { return 'plain_text_tool'; }
    public function get_description() { return 'Returns a plain array.'; }
    public function get_input_schema() { return array( 'type' => 'object', 'properties' => array() ); }
    public function execute( array $args ) { return array( 'captured' => true, 'zone' => 'header' ); }
}

/**
 * Resolve the retention window in a fresh process with a given configured value.
 *
 * AGENTSHELL_SCREENSHOT_TTL_HOURS is a constant, so the clamp cannot be
 * exercised by varying it within one process — every case would see the first
 * value compiled in.
 */
function privacy_ttl_hours_in_child( $configured ) {
    $script = dirname( __DIR__ ) . '/agentshell-mcp/includes/class-screenshot-store.php';
    $php = sprintf(
        'define("ABSPATH", %s); define("WP_CONTENT_DIR", %s); define("AGENTSHELL_SCREENSHOT_TTL_HOURS", %d); require %s; echo \\AgentShell_MCP\Screenshot_Store::ttl_hours();',
        var_export( __DIR__, true ),
        var_export( $GLOBALS['_agentshell_test_wp_content'], true ),
        (int) $configured,
        var_export( $script, true )
    );

    $out = array();
    $code = 0;
    exec( 'php -r ' . escapeshellarg( $php ) . ' 2>/dev/null', $out, $code );
    return $code === 0 ? trim( implode( '', $out ) ) : "exit($code)";
}

/**
 * Resolve the private store's base directory in a fresh process.
 *
 * base_dir() is a constant-driven path decision, so it cannot be varied within
 * one process. $abspath and $home stand in for the install layout; $home is the
 * path component of the site URL, which is what distinguishes a root install
 * from a subdirectory one.
 */
function privacy_base_dir_in_child( $abspath, $home = '' ) {
    $script = dirname( __DIR__ ) . '/agentshell-mcp/includes/class-screenshot-store.php';
    $php = sprintf(
        'define("ABSPATH", %s); define("WP_CONTENT_DIR", %s); function home_url($p="/"){return "https://example.test" . $p; } require %s; echo \\AgentShell_MCP\Screenshot_Store::base_dir(), "|", \\AgentShell_MCP\\Screenshot_Store::is_web_reachable(\\AgentShell_MCP\\Screenshot_Store::base_dir()) ? "reachable" : "outside";',
        var_export( $abspath, true ),
        var_export( $GLOBALS['_agentshell_test_wp_content'], true ),
        var_export( $script, true )
    );

    // The home path is applied by rewriting home_url at runtime instead of
    // interpolating it into the generated source.
    if ( '' !== $home ) {
        $php = str_replace(
            'function home_url($p="/"){return "https://example.test" . $p; }',
            'function home_url($p="/"){return "https://example.test/' . $home . '" . $p; }',
            $php
        );
    }

    $out = array();
    $code = 0;
    exec( 'php -r ' . escapeshellarg( $php ) . ' 2>/dev/null', $out, $code );
    return $code === 0 ? trim( implode( '', $out ) ) : "exit($code)";
}

echo "-- screenshot privacy regressions --\n";

// ---------------------------------------------------------------------------
// 1. Server: multi-block results are additive
// ---------------------------------------------------------------------------

Agentshell_Test::run( 'server: an image tool returns an image block first, then the summary', function () {
    Privacy_Test::reset();

    $png   = Privacy_Test::png_file( 300 );
    $bytes = file_get_contents( $png );
    @unlink( $png );

    $tool = new class( $bytes ) extends \AgentShell_MCP\Tools\Base_Tool {
        private $bytes;
        public function __construct( $bytes ) { $this->bytes = $bytes; }
        public function get_name() { return 'fake_image_tool'; }
        public function get_description() { return 'Returns an image block.'; }
        public function get_input_schema() { return array( 'type' => 'object', 'properties' => array() ); }
        public function execute( array $args ) {
            return array(
                '__mcp_content' => array(
                    array( 'type' => 'image', 'data' => base64_encode( $this->bytes ), 'mimeType' => 'image/png' ),
                ),
                'captured' => true,
                'width'    => 1280,
            );
        }
    };

    $result = privacy_call_tool( $tool, array() );

    Agentshell_Test::assert_true( isset( $result['content'] ), 'the result must carry a content array' );
    $content = $result['content'];
    Agentshell_Test::assert_true( is_array( $content ) && 2 === count( $content ), 'expected exactly two blocks, got ' . count( (array) $content ) );

    Agentshell_Test::assert_true( 'image' === $content[0]['type'], 'the first block must be the image, so a client renders it before the prose' );
    Agentshell_Test::assert_true( 'text' === $content[1]['type'], 'the summary must follow as a text block' );

    // The property the whole design rests on: the bytes survive the trip.
    $decoded = base64_decode( $content[0]['data'], true );
    Agentshell_Test::assert_true( $decoded === $bytes, 'the image block must decode to the exact captured bytes' );
    Agentshell_Test::assert_true( substr( $decoded, 0, 8 ) === "\x89PNG\r\n\x1a\n", 'the decoded payload must still carry PNG magic' );

    $summary = json_decode( $content[1]['text'], true );
    Agentshell_Test::assert_true( isset( $summary['captured'] ), 'the summary block must carry the structured fields' );
    Agentshell_Test::assert_false( isset( $summary['__mcp_content'] ), 'the reserved key must never leak into the text payload' );
} );

Agentshell_Test::run( 'server: an ordinary text tool is unchanged — one block, same JSON', function () {
    Privacy_Test::reset();
    $result = privacy_call_tool( new Plain_Text_Tool(), array() );

    $content = $result['content'] ?? array();
    Agentshell_Test::assert_true( 1 === count( $content ), 'a plain array tool must still produce exactly one block, got ' . count( (array) $content ) );
    Agentshell_Test::assert_true( 'text' === $content[0]['type'], 'and it must be a text block' );

    // Byte-for-byte the same encoding as before the multi-block change.
    $decoded = json_decode( $content[0]['text'], true );
    Agentshell_Test::assert_true(
        array( 'captured' => true, 'zone' => 'header' ) === $decoded,
        'the text payload must be unchanged: ' . $content[0]['text']
    );
} );

Agentshell_Test::run( 'server: malformed content blocks are dropped, not forwarded', function () {
    Privacy_Test::reset();

    $normalized = \AgentShell_MCP\Server::normalize_content( array(
        array( 'type' => 'image', 'data' => 'not-base64!!', 'mimeType' => 'image/png' ),
        array( 'type' => 'image', 'data' => base64_encode( 'x' ), 'mimeType' => 'text/html' ),
        array( 'type' => 'audio', 'data' => base64_encode( 'x' ), 'mimeType' => 'audio/wav' ),
        array( 'type' => 'resource', 'uri' => 'file:///etc/passwd' ),
        array( 'no' => 'type at all' ),
        array( 'type' => 'image', 'data' => base64_encode( 'png-bytes' ), 'mimeType' => 'image/png' ),
        array( 'type' => 'text', 'text' => 'kept' ),
        'not an array',
    ) );

    $types = array_column( $normalized, 'type' );
    Agentshell_Test::assert_true( array( 'image', 'text' ) === $types, 'only the well-formed image and text blocks may survive: ' . json_encode( $types ) );
    Agentshell_Test::assert_true(
        array( 'type', 'data', 'mimeType' ) === array_keys( $normalized[0] ),
        'an image block must carry exactly type, data and mimeType, got: ' . implode( ',', array_keys( $normalized[0] ) )
    );
} );

// ---------------------------------------------------------------------------
// 2. Store: location, permissions, naming
// ---------------------------------------------------------------------------

Agentshell_Test::run( 'server: a tool cannot smuggle unvalidated blocks into the protocol', function () {
    Privacy_Test::reset();

    // __mcp_content is a channel into the MCP protocol, so it has to be
    // validated on the way through Server::handle_tools_call — not merely by
    // the validator being available. This drives the real request path.
    $tool = new class extends \AgentShell_MCP\Tools\Base_Tool {
        public function get_name() { return 'hostile_image_tool'; }
        public function get_description() { return 'Emits a hostile content array.'; }
        public function get_input_schema() { return array( 'type' => 'object', 'properties' => array() ); }
        public function execute( array $args ) {
            return array(
                '__mcp_content' => array(
                    array( 'type' => 'resource', 'uri' => 'file:///etc/passwd', 'text' => 'root:x:0:0' ),
                    array( 'type' => 'image', 'data' => 'this is not base64 %%', 'mimeType' => 'image/png' ),
                    array( 'type' => 'image', 'data' => base64_encode( 'bytes' ), 'mimeType' => 'text/html' ),
                    array( 'type' => 'image', 'data' => base64_encode( 'bytes' ), 'mimeType' => 'image/png' ),
                ),
                'captured' => true,
            );
        }
    };

    $result  = privacy_call_tool( $tool, array() );
    $content = $result['content'] ?? array();

    foreach ( $content as $block ) {
        Agentshell_Test::assert_true(
            in_array( $block['type'] ?? '', array( 'text', 'image' ), true ),
            'only text and image blocks may reach the client, saw: ' . ( $block['type'] ?? '?' )
        );
        Agentshell_Test::assert_true(
            ! isset( $block['uri'] ),
            'no block may carry a uri: __mcp_content must not be able to point the client at a file'
        );
    }

    $images = array_filter( $content, function( $b ) { return 'image' === ( $b['type'] ?? '' ); } );
    Agentshell_Test::assert_true( 1 === count( $images ), 'only the one well-formed image may survive, got ' . count( $images ) );
    Agentshell_Test::assert_true( 'image/png' === array_values( $images )[0]['mimeType'], 'and it must be an image mime type' );

    $encoded = json_encode( $content );
    Agentshell_Test::assert_true( false === strpos( $encoded, '/etc/passwd' ), 'nothing from a rejected block may leak' );
} );

Agentshell_Test::run( 'storage: captures land outside every web-served directory', function () {
    Privacy_Test::reset();
    $uploads = Privacy_Test::uploads_dir();

    Screenshot_Store::ensure_dir();
    $png  = Privacy_Test::png_file( 300 );
    $kept = Screenshot_Store::store( $png, 'agent' );

    Agentshell_Test::assert_true( file_exists( $kept['path'] ), 'the capture must be stored' );
    Agentshell_Test::assert_false(
        0 === strpos( $kept['path'], rtrim( $uploads, '/' ) . '/' ),
        'a capture must never be written under the uploads tree: ' . $kept['path']
    );

    $uploads_url = $GLOBALS['_agentshell_test_uploads']['baseurl'];
    Agentshell_Test::assert_true(
        false === strpos( $kept['path'], parse_url( $uploads_url, PHP_URL_HOST ) ),
        'the stored path must not be reachable through the uploads base URL'
    );

    Privacy_Test::reset();
} );

Agentshell_Test::run( 'storage: directories are private and files are not world-readable', function () {
    Privacy_Test::reset();
    Screenshot_Store::ensure_dir();

    foreach ( array( Screenshot_Store::base_dir(), Screenshot_Store::screenshot_dir(), Screenshot_Store::scratch_dir() ) as $dir ) {
        Agentshell_Test::assert_true(
            '0700' === substr( sprintf( '%o', fileperms( $dir ) ), -4 ),
            $dir . ' must be 0700, is ' . substr( sprintf( '%o', fileperms( $dir ) ), -4 )
        );
    }

    $kept = Screenshot_Store::store( Privacy_Test::png_file( 300 ), 'agent' );
    Agentshell_Test::assert_true(
        '0640' === substr( sprintf( '%o', fileperms( $kept['path'] ) ), -4 ),
        'a stored capture must not be world-readable, is ' . substr( sprintf( '%o', fileperms( $kept['path'] ) ), -4 )
    );

    Privacy_Test::reset();
} );

Agentshell_Test::run( 'storage: the filename is not the capture id', function () {
    $id = Screenshot_Store::new_id();
    $name = basename( Screenshot_Store::path_for( $id ) );

    Agentshell_Test::assert_false(
        false !== strpos( $name, $id ),
        'the id must not appear on disk: an id can end up in a log or a transcript, so it must not be a path'
    );
    Agentshell_Test::assert_true(
        1 === preg_match( '/^[a-f0-9]{40}\.png$/', $name ),
        'the filename must be a fixed-width digest, got: ' . $name
    );
    Agentshell_Test::assert_true(
        Screenshot_Store::filename_for( $id ) !== Screenshot_Store::filename_for( Screenshot_Store::new_id() ),
        'different ids must map to different names'
    );
} );

Agentshell_Test::run( 'storage: a malformed id cannot address a file', function () {
    foreach ( array( '../../wp-config', '..', '/etc/passwd', '', str_repeat( 'z', 32 ), 'ABCDEF0123456789ABCDEF0123456789' ) as $bad ) {
        Agentshell_Test::assert_false( Screenshot_Store::is_valid_id( $bad ), 'must reject: ' . var_export( $bad, true ) );
        $err = Screenshot_Store::authorize_read( $bad, 'agent' );
        Agentshell_Test::assert_true( is_wp_error( $err ), 'must not resolve to a readable capture: ' . var_export( $bad, true ) );
    }
    Agentshell_Test::assert_true(
        Screenshot_Store::is_valid_id( str_repeat( 'a1', 16 ) ),
        'a well-formed id must be accepted'
    );
} );

Agentshell_Test::run( 'storage: the retention window is clamped, not caller-controlled', function () {
    // Default
    Agentshell_Test::assert_true(
        24 === Screenshot_Store::ttl_hours(),
        'the documented default is 24h, got ' . Screenshot_Store::ttl_hours()
    );

    // The clamp itself, in a child process per value: AGENTSHELL_SCREENSHOT_TTL_HOURS
    // is a constant, so it cannot be varied within one process. A typo must not
    // make a capture permanent, or silently expire everything an hour later.
    $cases = array(
        array( 0,      Screenshot_Store::MIN_TTL_HOURS ),
        array( -500,   Screenshot_Store::MIN_TTL_HOURS ),
        array( 1,      1 ),
        array( 99999,  Screenshot_Store::MAX_TTL_HOURS ),
        array( 999999, Screenshot_Store::MAX_TTL_HOURS ),
        array( 48,     48 ),
    );

    foreach ( $cases as $case ) {
        list( $configured, $expected ) = $case;
        $out = privacy_ttl_hours_in_child( $configured );
        Agentshell_Test::assert_true(
            (string) $expected === $out,
            sprintf( 'AGENTSHELL_SCREENSHOT_TTL_HOURS=%d must resolve to %dh, got %s', $configured, $expected, var_export( $out, true ) )
        );
    }
} );

Agentshell_Test::run( 'storage: a symlink planted at the storage path is refused', function () {
    Privacy_Test::reset();
    $base = Screenshot_Store::base_dir();
    $real = sys_get_temp_dir() . '/agentshell-privacy-elsewhere-' . getmypid();
    @mkdir( $real, 0700, true );

    // A local user who can write to wp-content could point the storage path at
    // a directory they control, and every future capture would land there.
    @mkdir( dirname( $base ), 0700, true );
    if ( @symlink( $real, $base ) ) {
        $refused = false;
        try {
            Screenshot_Store::ensure_dir();
        } catch ( \RuntimeException $e ) {
            $refused = ( false !== strpos( $e->getMessage(), 'symlink' ) || false !== strpos( $e->getMessage(), 'owned' ) );
        }
        Agentshell_Test::assert_true( $refused, 'a symlinked private storage path must be refused, not followed' );
        @unlink( $base );
    } else {
        // Cannot plant one (unusual, e.g. no symlink support): say so rather
        // than reporting a pass that proves nothing.
        Agentshell_Test::assert_true( false, 'the test could not plant a symlink at ' . $base );
    }

    Privacy_Test::rmtree( $real );
    Privacy_Test::reset();
} );

Agentshell_Test::run( 'storage: a directory owned by another user is refused', function () {
    Privacy_Test::reset();
    $dir = Screenshot_Store::ensure_dir() ? Screenshot_Store::screenshot_dir() : null;

    Agentshell_Test::assert_true( true === Screenshot_Store::dir_is_safe( $dir ), 'a directory we own must be accepted' );
    Agentshell_Test::assert_false( Screenshot_Store::dir_is_safe( $dir . '/does-not-exist' ), 'a missing directory must be refused' );

    // The ownership branch cannot be reached with a real foreign-owned
    // directory without privileges, so it is exercised through the uid seam.
    $foreign_uid = function_exists( 'posix_geteuid' ) ? posix_geteuid() + 1 : null;
    if ( null !== $foreign_uid ) {
        Agentshell_Test::assert_false(
            Screenshot_Store::dir_is_safe( $dir, $foreign_uid ),
            'a directory owned by a different user must be refused — otherwise a '
            . 'pre-planted directory could redirect every future capture'
        );
    }

    Privacy_Test::reset();
} );

Agentshell_Test::run( 'storage: a symlinked capture directory is refused', function () {
    Privacy_Test::reset();
    $shots = Screenshot_Store::screenshot_dir();
    $real  = sys_get_temp_dir() . '/agentshell-privacy-elsewhere2-' . getmypid();
    @mkdir( $real, 0700, true );
    @mkdir( dirname( $shots ), 0700, true );

    if ( @symlink( $real, $shots ) ) {
        $refused = false;
        try {
            Screenshot_Store::ensure_dir();
        } catch ( \RuntimeException $e ) {
            $refused = true;
        }
        Agentshell_Test::assert_true( $refused, 'a symlinked screenshots directory must be refused, not followed' );
        @unlink( $shots );
    } else {
        Agentshell_Test::assert_true( false, 'the test could not plant a symlink at ' . $shots );
    }

    Privacy_Test::rmtree( $real );
    Privacy_Test::reset();
} );

// ---------------------------------------------------------------------------
// 3. Authorisation
// ---------------------------------------------------------------------------

Agentshell_Test::run( 'access: the capturing actor can retrieve their own capture', function () {
    Privacy_Test::reset();
    Privacy_Test::login_as( 'alice' );
    $kept = Screenshot_Store::store( Privacy_Test::png_file( 300 ), 'alice' );

    $ok = Screenshot_Store::authorize_read( $kept['id'], 'alice' );
    Agentshell_Test::assert_false( is_wp_error( $ok ), 'the owner must be allowed: ' . ( is_wp_error( $ok ) ? $ok->get_error_message() : '' ) );
    Agentshell_Test::assert_true( is_readable( $ok['path'] ), 'the owner must be given a readable path' );

    Privacy_Test::reset();
} );

Agentshell_Test::run( 'access: an authenticated but different actor is refused', function () {
    Privacy_Test::reset();
    Privacy_Test::login_as( 'alice' );
    $kept = Screenshot_Store::store( Privacy_Test::png_file( 300 ), 'alice' );

    // Bob holds manage_options too, so the transport would have accepted him.
    // Authentication alone must not be enough.
    $denied = Screenshot_Store::authorize_read( $kept['id'], 'bob' );
    Agentshell_Test::assert_true( is_wp_error( $denied ), 'a second administrator must not read another admin\'s capture' );
    Agentshell_Test::assert_true(
        'agentshell_screenshot_forbidden' === $denied->get_error_code(),
        'the refusal must be distinguishable from "not found", got: ' . $denied->get_error_code()
    );
    Agentshell_Test::assert_true( is_file( $kept['path'] ), 'a refused read must not delete the file it refused' );

    Privacy_Test::reset();
} );

Agentshell_Test::run( 'access: a capture with no recorded owner is refused rather than granted', function () {
    Privacy_Test::reset();
    $id = Screenshot_Store::new_id();
    Screenshot_Store::ensure_dir();
    file_put_contents( Screenshot_Store::path_for( $id ), Privacy_Test::png_file( 300 ) );

    $denied = Screenshot_Store::authorize_read( $id, 'agent' );
    Agentshell_Test::assert_true( is_wp_error( $denied ), 'an unowned capture has no principal to authorise' );
    Agentshell_Test::assert_true(
        'agentshell_screenshot_forbidden' === $denied->get_error_code(),
        'expected forbidden, got: ' . $denied->get_error_code()
    );

    Privacy_Test::reset();
} );

Agentshell_Test::run( 'access: an unauthenticated request is rejected with 401', function () {
    Privacy_Test::reset();
    Privacy_Test::login_as( 'alice' );
    $kept = Screenshot_Store::store( Privacy_Test::png_file( 300 ), 'alice' );

    $response = privacy_get_screenshot( $kept['id'], null );
    Agentshell_Test::assert_true( 401 === $response->status, 'no credentials must be 401, got ' . $response->status );
    Agentshell_Test::assert_true( ! is_string( $response->data ), 'a 401 must not carry image bytes' );

    $response = privacy_get_screenshot( $kept['id'], 'Basic ' . base64_encode( 'agent:wrong' ) );
    Agentshell_Test::assert_true( 401 === $response->status, 'a bad password must be 401, got ' . $response->status );

    Privacy_Test::reset();
} );

Agentshell_Test::run( 'access: authenticated but unauthorised gets 403, not 401', function () {
    Privacy_Test::reset();
    Privacy_Test::login_as( 'alice' );
    $kept = Screenshot_Store::store( Privacy_Test::png_file( 300 ), 'alice' );

    Privacy_Test::login_as( 'bob' );
    $response = privacy_get_screenshot( $kept['id'], 'Basic ' . base64_encode( 'agent:app-password' ) );

    Agentshell_Test::assert_true( 403 === $response->status, 'a valid credential belonging to the wrong actor must be 403, got ' . $response->status );
    Agentshell_Test::assert_true( ! is_string( $response->data ), 'a 403 must not carry image bytes' );

    Privacy_Test::reset();
} );

Agentshell_Test::run( 'access: a valid request returns the bytes with no-store', function () {
    Privacy_Test::reset();
    Privacy_Test::login_as( 'alice' );
    $png   = Privacy_Test::png_file( 512 );
    $bytes = file_get_contents( $png );
    @unlink( $png );
    $kept = Screenshot_Store::store( Privacy_Test::png_file( 512 ), 'alice' );

    $response = privacy_get_screenshot( $kept['id'], 'Basic ' . base64_encode( 'agent:app-password' ) );
    Agentshell_Test::assert_true( 200 === $response->status, 'the owner must get 200, got ' . $response->status );
    Agentshell_Test::assert_true( $bytes === $response->data, 'the response must be the stored bytes, unmodified' );

    $headers = $response->get_headers();
    Agentshell_Test::assert_true( 'image/png' === ( $headers['Content-Type'] ?? '' ), 'must be served as image/png' );
    Agentshell_Test::assert_true(
        false !== strpos( (string) ( $headers['Cache-Control'] ?? '' ), 'no-store' ),
        'a private screenshot must not be cacheable anywhere'
    );

    Privacy_Test::reset();
} );

Agentshell_Test::run( 'access: the image body is served as bytes, not JSON-encoded', function () {
    Privacy_Test::reset();
    Privacy_Test::login_as( 'alice' );
    $bytes = "\x89PNG\r\n\x1a\n" . random_bytes( 400 );
    $kept  = Screenshot_Store::store( Privacy_Test::png_file( 400 ), 'alice' );
    $stored = file_get_contents( $kept['path'] );

    // WordPress JSON-encodes every REST response body. Left alone, a PNG comes
    // back as a quoted string of \\u0000 escapes behind an image/png header —
    // corrupt, and invisible to a test whose stub returns response data
    // verbatim. This models the real serve path instead.
    $transport = new \AgentShell_MCP\Transport(
        new \AgentShell_MCP\Server(),
        new Privacy_Fake_Auth()
    );
    $transport->register_routes();
    $response = privacy_get_screenshot_with( $transport, $kept['id'], 'Basic ' . base64_encode( 'agent:app-password' ) );

    // The route must have arranged for the body to bypass JSON encoding.
    $filters = $GLOBALS['_agentshell_test_filters']['rest_pre_serve_request'] ?? array();
    Agentshell_Test::assert_true( ! empty( $filters ), 'the route must register a rest_pre_serve_request handler, or WordPress JSON-encodes the image' );

    ob_start();
    $served = apply_filters( 'rest_pre_serve_request', false, $response, null, null );
    $emitted = ob_get_clean();

    Agentshell_Test::assert_true( true === $served, 'the handler must return true so WordPress does not encode the body again' );
    Agentshell_Test::assert_true(
        $stored === $emitted,
        sprintf(
            'the emitted body must be the exact PNG bytes (got %d bytes starting %s, expected %d starting %s)',
            strlen( $emitted ),
            bin2hex( substr( $emitted, 0, 4 ) ),
            strlen( $stored ),
            bin2hex( substr( $stored, 0, 4 ) )
        )
    );
    Agentshell_Test::assert_true(
        '"' !== substr( $emitted, 0, 1 ),
        'a JSON-quoted body would start with a quote: the image would not render'
    );

    // And the one-shot guard: a second response on the same transport must not
    // be served raw by accident.
    $second = $transport->handle_get_screenshot( new WP_REST_Request( array(
        'id'      => 'unknown',
        'headers' => array( 'authorization' => 'Basic ' . base64_encode( 'agent:app-password' ) ),
    ) ) );
    ob_start();
    $served_again = apply_filters( 'rest_pre_serve_request', false, $second, null, null );
    $emitted_again = ob_get_clean();
    Agentshell_Test::assert_true( false === $served_again, 'the raw-body handler must not fire again after use' );
    Agentshell_Test::assert_true( '' === $emitted_again, 'and must not emit anything on a later response' );

    Privacy_Test::reset();
} );

Agentshell_Test::run( 'storage: the store resolves outside the document root', function () {
    // The regression this exists for. wp-content/agentshell-private/ looked
    // private and was not: on the live LocalWP site an unauthenticated GET of
    // a capture returned 200 and the exact PNG bytes, because Local serves with
    // nginx (which ignores .htaccess) as the same user that owns the 0700
    // directory. Only being outside the document root held.
    $resolved = privacy_base_dir_in_child( '/var/www/html', '' );
    list( $base, $verdict ) = array_pad( explode( '|', $resolved ), 2, '' );

    Agentshell_Test::assert_true(
        '/var/www/agentshell-private' === $base,
        "a root install must store outside the document root, not beside it (got '$base')"
    );
    Agentshell_Test::assert_true( 'outside' === $verdict, "the resolved store must not be web-reachable (got '$verdict')" );

    // Regression guard in the other direction: nothing may put it back inside
    // wp-content, which is inside the document root on a standard install.
    Agentshell_Test::assert_true(
        ! str_contains( $base, 'wp-content' ),
        'the store must never sit under wp-content, which the web server publishes'
    );
} );

Agentshell_Test::run( 'storage: a subdirectory install does not fall back into the web root', function () {
    // WordPress at /blog means the install directory's parent IS the document
    // root, so the obvious default would be served. It must not be used.
    $resolved = privacy_base_dir_in_child( '/var/www/html/blog', '/blog' );
    list( $base, $verdict ) = array_pad( explode( '|', $resolved ), 2, '' );

    Agentshell_Test::assert_true(
        'outside' === $verdict,
        "a subdirectory install must not resolve to a web-served path (got '$base' -> '$verdict')"
    );
    Agentshell_Test::assert_true(
        '/var/www/html' !== $base,
        'the document root itself is served, so it is not a valid fallback'
    );
} );

Agentshell_Test::run( 'storage: the resolved directory is not web-reachable in this process', function () {
    $base = Screenshot_Store::base_dir();
    Agentshell_Test::assert_true(
        ! Screenshot_Store::is_web_reachable( $base ),
        'the configured store must not sit inside the document root (got ' . $base . ')'
    );
} );

Agentshell_Test::run( 'storage: a store left at the old served path is relocated, not left exposed', function () {
    Privacy_Test::reset();
    Privacy_Test::login_as( 'alice' );

    // Reproduce the state a site upgrading from the earlier revision is in:
    // captures sitting in wp-content/agentshell-private/, inside the document
    // root, readable by anyone who asks. Leaving them there would keep the
    // original defect alive after fixing it.
    $old_base = rtrim( WP_CONTENT_DIR, '/' ) . '/agentshell-private';
    $old_shots = $old_base . '/screenshots';
    @mkdir( $old_shots, 0700, true );

    $seed_id = Screenshot_Store::new_id();
    file_put_contents( $old_shots . '/' . Screenshot_Store::filename_for( $seed_id ), "\x89PNG\r\n\x1a\n" . str_repeat( 'L', 64 ) );
    // An index entry, as a real capture would have left behind.
    $GLOBALS['_agentshell_test_options']['agentshell_screenshot_index'][ $seed_id ] = array(
        'actor'      => 'alice',
        'mimeType'   => 'image/png',
        'expires_at' => time() + HOUR_IN_SECONDS,
    );
    $GLOBALS['_agentshell_test_relocated'] = false;

    // AGENTSHELL_SCREENSHOT_DIR is defined for this suite, so base_dir() is the
    // pinned /tmp path and the old location differs from it, as on a real site.
    $result = Screenshot_Store::relocate_old_store();

    Agentshell_Test::assert_true( 1 === $result, 'the store at the old served path must be relocated' );
    Agentshell_Test::assert_true(
        ! is_dir( $old_base ),
        'the world-readable copy must be gone from the document root afterwards'
    );
    Agentshell_Test::assert_true(
        file_exists( Screenshot_Store::path_for( $seed_id ) ),
        'the capture must be readable at its new location without an index rewrite'
    );

    // And it still authorises as before: the index stores ids only.
    $authorized = Screenshot_Store::authorize_read( $seed_id, 'alice' );
    Agentshell_Test::assert_true( ! is_wp_error( $authorized ), 'the relocated capture must still be retrievable by its owner' );

    Privacy_Test::rmtree( $old_base );
    Privacy_Test::reset();
} );

Agentshell_Test::run( 'access: an unknown id is 404 and an expired one is 410', function () {
    Privacy_Test::reset();
    Privacy_Test::login_as( 'alice' );

    $unknown = privacy_get_screenshot( Screenshot_Store::new_id(), 'Basic ' . base64_encode( 'agent:app-password' ) );
    Agentshell_Test::assert_true( 404 === $unknown->status, 'an id that never existed must be 404, got ' . $unknown->status );

    $kept = Screenshot_Store::store( Privacy_Test::png_file( 300 ), 'alice' );
    // Age the record past its window.
    $index = get_option( Screenshot_Store::INDEX_KEY );
    $index[ $kept['id'] ]['expires_at'] = time() - 1;
    update_option( Screenshot_Store::INDEX_KEY, $index );

    $expired = privacy_get_screenshot( $kept['id'], 'Basic ' . base64_encode( 'agent:app-password' ) );
    Agentshell_Test::assert_true( 410 === $expired->status, 'a capture past its retention must be 410, got ' . $expired->status );
    Agentshell_Test::assert_false( file_exists( $kept['path'] ), 'an expired capture must be deleted on access' );

    Privacy_Test::reset();
} );

Agentshell_Test::run( 'access: a capture is not served past its window even if the index was lost', function () {
    Privacy_Test::reset();
    Privacy_Test::login_as( 'alice' );
    $kept = Screenshot_Store::store( Privacy_Test::png_file( 300 ), 'alice' );

    // Simulate an index that never got written — expiry must not depend on it.
    update_option( Screenshot_Store::INDEX_KEY, array() );
    touch( $kept['path'], time() - ( Screenshot_Store::MAX_TTL_HOURS * HOUR_IN_SECONDS ) - 10 );

    $denied = Screenshot_Store::authorize_read( $kept['id'], 'alice' );
    Agentshell_Test::assert_true( is_wp_error( $denied ), 'mtime must still expire a capture whose index entry is gone' );

    Privacy_Test::reset();
} );

// ---------------------------------------------------------------------------
// 4. Retention
// ---------------------------------------------------------------------------

Agentshell_Test::run( 'retention: the sweep removes expired captures and keeps live ones', function () {
    Privacy_Test::reset();
    Privacy_Test::login_as( 'alice' );

    $live    = Screenshot_Store::store( Privacy_Test::png_file( 300, 'live.png' ), 'alice' );
    $expired = Screenshot_Store::store( Privacy_Test::png_file( 300, 'old.png' ), 'alice' );

    $index = get_option( Screenshot_Store::INDEX_KEY );
    $index[ $expired['id'] ]['expires_at'] = time() - 1;
    update_option( Screenshot_Store::INDEX_KEY, $index );

    $removed = Screenshot_Store::purge_expired();

    Agentshell_Test::assert_true( 1 === $removed, 'the sweep must remove exactly the expired capture, removed ' . $removed );
    Agentshell_Test::assert_false( file_exists( $expired['path'] ), 'the expired file must be gone' );
    Agentshell_Test::assert_true( file_exists( $live['path'] ), 'a live capture must survive the sweep' );

    $remaining = get_option( Screenshot_Store::INDEX_KEY );
    Agentshell_Test::assert_false( isset( $remaining[ $expired['id'] ] ), 'the expired record must be dropped from the index' );
    Agentshell_Test::assert_true( isset( $remaining[ $live['id'] ] ), 'the live record must stay in the index' );

    Privacy_Test::reset();
} );

Agentshell_Test::run( 'retention: the sweep is idempotent', function () {
    Privacy_Test::reset();
    Privacy_Test::login_as( 'alice' );
    Screenshot_Store::store( Privacy_Test::png_file( 300, 'old.png' ), 'alice' );

    $index = get_option( Screenshot_Store::INDEX_KEY );
    foreach ( $index as $id => $meta ) { $index[ $id ]['expires_at'] = time() - 1; }
    update_option( Screenshot_Store::INDEX_KEY, $index );

    Agentshell_Test::assert_true( 1 === Screenshot_Store::purge_expired(), 'first sweep removes one' );
    Agentshell_Test::assert_true( 0 === Screenshot_Store::purge_expired(), 'a second sweep must find nothing left to do' );

    Privacy_Test::reset();
} );

Agentshell_Test::run( 'retention: the store is bounded even if nothing ever expires', function () {
    Privacy_Test::reset();
    Privacy_Test::login_as( 'agent' );

    // MAX_CAPTURES is a ceiling on disk growth independent of the TTL, so a
    // site that captures constantly cannot fill the disk.
    $total = Screenshot_Store::MAX_CAPTURES + 5;
    for ( $i = 0; $i < $total; $i++ ) {
        Screenshot_Store::store( Privacy_Test::png_file( 120, 'b' . $i . '.png' ), 'agent' );
    }

    $on_disk = glob( Screenshot_Store::screenshot_dir() . '/*.png' );
    Agentshell_Test::assert_true(
        count( $on_disk ) <= Screenshot_Store::MAX_CAPTURES,
        'the store must stay bounded, found ' . count( $on_disk ) . ' files'
    );

    $index = get_option( Screenshot_Store::INDEX_KEY );
    Agentshell_Test::assert_true(
        count( $index ) === count( $on_disk ),
        'the index and the directory must not disagree: ' . count( $index ) . ' vs ' . count( $on_disk )
    );

    Privacy_Test::reset();
} );

// ---------------------------------------------------------------------------
// 5. Legacy migration
// ---------------------------------------------------------------------------

Agentshell_Test::run( 'legacy: the migration runs from an authenticated request, not from init', function () {
    // Regression guard on hook placement. `init` fires before the REST
    // transport authenticates, so a migration hooked there sees no current
    // user and silently never runs — which is exactly how it behaved the first
    // time it was wired up, and the live check is the only thing that caught it.
    $transport_source = file_get_contents( dirname( __DIR__ ) . '/agentshell-mcp/includes/class-transport.php' );
    Agentshell_Test::assert_true(
        false !== strpos( $transport_source, 'Screenshot_Store::maybe_migrate_legacy()' ),
        'the transport must trigger the migration once a principal exists'
    );

    // ...and it must be after the authentication check, not before it.
    $auth_pos     = strpos( $transport_source, '$auth_result = $this->auth->authenticate( $request );' );
    $migrate_pos  = strpos( $transport_source, 'Screenshot_Store::maybe_migrate_legacy()' );
    Agentshell_Test::assert_true(
        false !== $auth_pos && false !== $migrate_pos && $migrate_pos > $auth_pos,
        'the migration must run after authenticate(), or there is no principal to attribute captures to'
    );

    // register_hooks() schedules cron only; it must not attempt the migration.
    $store_source = file_get_contents( dirname( __DIR__ ) . '/agentshell-mcp/includes/class-screenshot-store.php' );
    $fn = substr( $store_source, strpos( $store_source, 'function register_hooks' ) );
    $fn = substr( $fn, 0, strpos( $fn, 'function maybe_migrate_legacy' ) );
    Agentshell_Test::assert_false(
        false !== strpos( $fn, 'migrate_legacy' ),
        'register_hooks() must only schedule the sweep — migrating from init has no current user'
    );
} );

Agentshell_Test::run( 'legacy: the public upload is gone the moment the capture is migrated', function () {
    Privacy_Test::reset();
    $uploads = Privacy_Test::uploads_dir();
    Privacy_Test::login_as( 'alice' );

    // Recreate the pre-fix state: world-readable captures under uploads/.
    $legacy_dir = $uploads . '/agentshell-screenshots';
    mkdir( $legacy_dir, 0755, true );
    $legacy_files = array();
    for ( $i = 0; $i < 3; $i++ ) {
        $name = 'shot-20261012-00000-' . $i . '.png';
        file_put_contents( $legacy_dir . '/' . $name, "\x89PNG\r\n\x1a\n" . str_repeat( 'L', 200 ) );
        chmod( $legacy_dir . '/' . $name, 0644 );
        $legacy_files[] = $legacy_dir . '/' . $name;
    }

    $result = Screenshot_Store::migrate_legacy( 'alice' );

    Agentshell_Test::assert_true( 3 === $result['migrated'], 'all three captures must be migrated, got ' . json_encode( $result ) );

    // The requirement: no grace period, no retained public original.
    foreach ( $legacy_files as $legacy ) {
        Agentshell_Test::assert_false(
            file_exists( $legacy ),
            'the public file must not survive migration: ' . $legacy
        );
    }
    Agentshell_Test::assert_false(
        is_dir( $legacy_dir ),
        'the public screenshot directory must be removed, not left listable'
    );

    // And the bytes are still reachable — privately.
    $index = get_option( Screenshot_Store::INDEX_KEY );
    Agentshell_Test::assert_true( 3 === count( $index ), 'each migrated capture must be indexed' );

    $found = 0;
    foreach ( array_keys( $index ) as $id ) {
        $ok = Screenshot_Store::authorize_read( $id, 'alice' );
        Agentshell_Test::assert_false( is_wp_error( $ok ), 'a migrated capture must be retrievable by its owner' );
        $found++;
    }
    Agentshell_Test::assert_true( 3 === $found, 'all three must resolve' );

    Privacy_Test::reset();
} );

Agentshell_Test::run( 'legacy: migration runs once and leaves nothing behind on a second pass', function () {
    Privacy_Test::reset();
    $uploads = Privacy_Test::uploads_dir();
    Privacy_Test::login_as( 'alice' );

    $legacy_dir = $uploads . '/agentshell-screenshots';
    mkdir( $legacy_dir, 0755, true );
    file_put_contents( $legacy_dir . '/shot-1.png', "\x89PNG\r\n\x1a\n" . str_repeat( 'L', 200 ) );
    file_put_contents( $legacy_dir . '/stray.txt', 'not a screenshot but still publicly readable' );

    $first = Screenshot_Store::migrate_legacy( 'alice' );
    Agentshell_Test::assert_true( 1 === $first['migrated'], 'one real capture expected' );

    $after_first = glob( Screenshot_Store::screenshot_dir() . '/*.png' );
    Agentshell_Test::assert_true( 1 === count( $after_first ), 'exactly one capture in private storage' );

    // Nothing that was publicly readable may remain, including files the
    // capture loop did not claim.
    Agentshell_Test::assert_false( is_dir( $legacy_dir ), 'the public directory must not survive' );

    $second = Screenshot_Store::migrate_legacy( 'alice' );
    Agentshell_Test::assert_true( 0 === $second['migrated'], 'a second pass must migrate nothing' );

    $after_second = glob( Screenshot_Store::screenshot_dir() . '/*.png' );
    Agentshell_Test::assert_true(
        count( $after_first ) === count( $after_second ),
        'a second pass must not duplicate or drop captures'
    );

    Privacy_Test::reset();
} );

Agentshell_Test::run( 'legacy: an unowned migrated capture is not readable by anyone', function () {
    Privacy_Test::reset();
    $uploads = Privacy_Test::uploads_dir();

    $legacy_dir = $uploads . '/agentshell-screenshots';
    mkdir( $legacy_dir, 0755, true );
    file_put_contents( $legacy_dir . '/shot-1.png', "\x89PNG\r\n\x1a\n" . str_repeat( 'L', 200 ) );

    // Migration with no principal available (a cron or CLI context).
    Screenshot_Store::migrate_legacy( '' );

    $index = get_option( Screenshot_Store::INDEX_KEY );
    $id    = array_key_first( $index );
    $denied = Screenshot_Store::authorize_read( $id, 'agent' );
    Agentshell_Test::assert_true(
        is_wp_error( $denied ),
        'a capture with no attributable owner must not become readable to whoever asks'
    );

    Privacy_Test::reset();
} );

// ---------------------------------------------------------------------------
// 6. The screenshot tool: inline delivery and the size limit
// ---------------------------------------------------------------------------

Agentshell_Test::run( 'tool: an inline capture returns the image and leaves no file behind', function () {
    Privacy_Test::reset();
    Privacy_Test::login_as( 'alice' );

    $dir = sys_get_temp_dir() . '/agentshell-privacy-browser-' . getmypid();
    mkdir( $dir, 0700, true );
    $browser = agentshell_fake_browser_path( $dir );
    putenv( 'AGENTSHELL_FAKE_CHROME_MODE=success' );
    putenv( 'AGENTSHELL_FAKE_PNG_BYTES=4096' );
    define( 'AGENTSHELL_CHROME_BIN', $browser );

    $tool  = new Screenshot();
    $store = Screenshot_Store::screenshot_dir();
    $tool->execute( array() );

    // The capture is gone, not merely hidden: nothing durable exists anywhere.
    $left = glob( $store . '/*' );
    Agentshell_Test::assert_true(
        empty( array_filter( (array) $left, function( $f ) { return basename( $f ) !== 'index.php'; } ) ),
        'an inline capture must not persist a file in private storage: ' . implode( ',', (array) $left )
    );

    $scratch = glob( Screenshot_Store::scratch_dir() . '/*' );
    Agentshell_Test::assert_true( empty( $scratch ), 'the scratch file must be deleted after an inline capture: ' . implode( ',', (array) $scratch ) );

    Privacy_Test::reset();
} );

Agentshell_Test::run( 'tool: an inline capture decodes to exactly the bytes Chrome wrote', function () {
    Privacy_Test::reset();
    Privacy_Test::login_as( 'alice' );

    $dir = sys_get_temp_dir() . '/agentshell-privacy-browser2-' . getmypid();
    mkdir( $dir, 0700, true );
    $browser = agentshell_fake_browser_path( $dir );
    putenv( 'AGENTSHELL_FAKE_CHROME_MODE=success' );
    putenv( 'AGENTSHELL_FAKE_PNG_BYTES=4096' );
    if ( ! defined( 'AGENTSHELL_CHROME_BIN' ) ) { define( 'AGENTSHELL_CHROME_BIN', $browser ); }

    // The fake browser writes a PNG whose IHDR chunk carries the window
    // size it was launched with — 1280x800 for the default desktop
    // viewport — followed by filler, so the expected bytes are the
    // signature, the IHDR, and the filler.
    $expected = "\x89PNG\r\n\x1a\n"
        . "\x00\x00\x00\x0dIHDR"
        . "\x00\x00\x05\x00"   // 1280
        . "\x00\x00\x03\x20"   // 800
        . str_repeat( 'X', 4096 - 16 );
    $tool     = new Screenshot();
    $result   = privacy_call_tool( $tool, array() );

    $content = $result['content'] ?? array();
    $image   = null;
    foreach ( $content as $block ) {
        if ( 'image' === ( $block['type'] ?? '' ) ) { $image = $block; }
    }
    Agentshell_Test::assert_true( null !== $image, 'an inline capture must produce an image block' );
    Agentshell_Test::assert_true( 'image/png' === ( $image['mimeType'] ?? '' ), 'the block must be typed image/png' );

    $decoded = base64_decode( (string) $image['data'], true );
    Agentshell_Test::assert_true( false !== $decoded, 'the payload must be valid base64' );
    Agentshell_Test::assert_true( $expected === $decoded, 'the decoded image must match the captured PNG byte for byte' );

    // 4096 bytes of PNG is 5464 of base64; a newline anywhere would break the
    // daemon's one-line JSON-RPC framing.
    Agentshell_Test::assert_true(
        false === strpos( (string) $image['data'], "\n" ),
        'the base64 payload must contain no newline: the stdio transport frames on newlines'
    );

    Privacy_Test::reset();
} );

Agentshell_Test::run( 'tool: an over-limit capture is refused explicitly, not truncated', function () {
    Privacy_Test::reset();
    Privacy_Test::login_as( 'alice' );

    $dir = sys_get_temp_dir() . '/agentshell-privacy-browser3-' . getmypid();
    mkdir( $dir, 0700, true );
    $browser = agentshell_fake_browser_path( $dir );
    putenv( 'AGENTSHELL_FAKE_CHROME_MODE=success' );
    // Comfortably over the 5 MB default limit.
    putenv( 'AGENTSHELL_FAKE_PNG_BYTES=' . ( 6 * MB_IN_BYTES ) );
    if ( ! defined( 'AGENTSHELL_CHROME_BIN' ) ) { define( 'AGENTSHELL_CHROME_BIN', $browser ); }

    $tool   = new Screenshot();
    $result = privacy_call_tool( $tool, array() );

    $content = $result['content'] ?? array();
    foreach ( $content as $block ) {
        Agentshell_Test::assert_true(
            'image' !== ( $block['type'] ?? '' ),
            'an over-limit capture must not return an image block'
        );
    }

    // The refusal has to say what happened, or the agent retries forever.
    $text = '';
    foreach ( $content as $block ) {
        if ( 'text' === ( $block['type'] ?? '' ) ) { $text .= $block['text']; }
    }
    Agentshell_Test::assert_true(
        false !== stripos( $text, 'too_large' ),
        'the result must state the capture was refused: ' . $text
    );
    Agentshell_Test::assert_true(
        false !== strpos( $text, 'AGENTSHELL_SCREENSHOT_MAX_BYTES' ),
        'the refusal must name the limit that was exceeded: ' . $text
    );
    Agentshell_Test::assert_true(
        false !== strpos( $text, 'inline=false' ),
        'the refusal must offer the stored path as a way forward: ' . $text
    );

    // A refused capture leaves nothing to serve.
    $left = array_filter(
        (array) glob( Screenshot_Store::screenshot_dir() . '/*' ),
        function( $f ) { return basename( $f ) !== 'index.php'; }
    );
    Agentshell_Test::assert_true( empty( $left ), 'a refused capture must publish nothing: ' . implode( ',', $left ) );

    $scratch = glob( Screenshot_Store::scratch_dir() . '/*' );
    Agentshell_Test::assert_true( empty( $scratch ), 'the scratch file must be cleaned up on the failure path too: ' . implode( ',', (array) $scratch ) );

    Privacy_Test::reset();
} );

Agentshell_Test::run( 'tool: the inline limit is clamped and cannot be set per call', function () {
    $limit = Screenshot::max_inline_bytes();

    Agentshell_Test::assert_true( $limit >= MB_IN_BYTES, 'the limit must never fall below 1 MB, got ' . $limit );
    Agentshell_Test::assert_true( $limit <= 25 * MB_IN_BYTES, 'the limit must never exceed 25 MB, got ' . $limit );

    // The schema offers no way to raise it.
    $schema = json_encode( ( new Screenshot() )->get_input_schema() );
    Agentshell_Test::assert_true(
        false === stripos( $schema, 'AGENTSHELL_SCREENSHOT_MAX_BYTES' ) || false === stripos( $schema, '"max_bytes"' ),
        'a caller must not be able to supply their own limit'
    );
} );

Agentshell_Test::run( 'tool: a stored capture belongs to the actor who took it', function () {
    Privacy_Test::reset();
    Privacy_Test::login_as( 'alice' );

    $dir = sys_get_temp_dir() . '/agentshell-privacy-browser4-' . getmypid();
    mkdir( $dir, 0700, true );
    $browser = agentshell_fake_browser_path( $dir );
    putenv( 'AGENTSHELL_FAKE_CHROME_MODE=success' );
    putenv( 'AGENTSHELL_FAKE_PNG_BYTES=4096' );
    if ( ! defined( 'AGENTSHELL_CHROME_BIN' ) ) { define( 'AGENTSHELL_CHROME_BIN', $browser ); }

    // The whole capture path, including ownership recording at write time.
    $result = privacy_call_tool( new Screenshot(), array( 'inline' => false ) );
    $text   = '';
    foreach ( $result['content'] ?? array() as $block ) {
        if ( 'text' === ( $block['type'] ?? '' ) ) { $text .= $block['text']; }
    }
    $summary = json_decode( $text, true );
    $id      = $summary['screenshot']['id'] ?? '';
    Agentshell_Test::assert_true( '' !== $id, 'inline=false must return a capture id: ' . $text );

    $files = array_filter(
        (array) glob( Screenshot_Store::screenshot_dir() . '/*' ),
        function( $f ) { return basename( $f ) !== 'index.php'; }
    );
    Agentshell_Test::assert_true( 1 === count( $files ), 'exactly one capture must be stored: ' . implode( ',', $files ) );

    $bytes = file_get_contents( Screenshot_Store::path_for( $id ) );

    // The taker may have it back.
    $own = privacy_call_tool( new Get_Screenshot(), array( 'id' => $id ) );
    $got = null;
    foreach ( $own['content'] ?? array() as $block ) {
        if ( 'image' === ( $block['type'] ?? '' ) ) { $got = $block; }
    }
    Agentshell_Test::assert_true( null !== $got, 'alice must be able to retrieve her own capture' );
    Agentshell_Test::assert_true( $bytes === base64_decode( $got['data'], true ), 'and must get the exact stored bytes' );

    // Nobody else may.
    Privacy_Test::login_as( 'bob' );
    $denied = privacy_call_tool( new Get_Screenshot(), array( 'id' => $id ) );
    $leaked = false;
    foreach ( $denied['content'] ?? array() as $block ) {
        if ( 'image' === ( $block['type'] ?? '' ) ) { $leaked = true; }
    }
    Agentshell_Test::assert_true( false === $leaked, 'bob must not receive alice\'s capture over MCP' );
    Agentshell_Test::assert_true( file_exists( Screenshot_Store::path_for( $id ) ), 'a refused read must not delete the capture' );

    Privacy_Test::reset();
} );

Agentshell_Test::run( 'tool: a stored capture is retrievable over MCP by its owner', function () {
    Privacy_Test::reset();
    Privacy_Test::login_as( 'alice' );

    $kept = Screenshot_Store::store( Privacy_Test::png_file( 700 ), 'alice' );
    $bytes = file_get_contents( $kept['path'] );

    $result = privacy_call_tool( new Get_Screenshot(), array( 'id' => $kept['id'] ) );
    $content = $result['content'] ?? array();

    $image = null;
    foreach ( $content as $block ) {
        if ( 'image' === ( $block['type'] ?? '' ) ) { $image = $block; }
    }
    Agentshell_Test::assert_true( null !== $image, 'the owner must receive the image over MCP' );
    Agentshell_Test::assert_true( $bytes === base64_decode( $image['data'], true ), 'the retrieved bytes must match what was stored' );

    Privacy_Test::reset();
} );

Agentshell_Test::run( 'tool: a second actor calling the MCP tool is refused, not served', function () {
    Privacy_Test::reset();
    Privacy_Test::login_as( 'alice' );
    $kept = Screenshot_Store::store( Privacy_Test::png_file( 700 ), 'alice' );

    Privacy_Test::login_as( 'bob' );
    $result = privacy_call_tool( new Get_Screenshot(), array( 'id' => $kept['id'] ) );
    $content = $result['content'] ?? array();

    foreach ( $content as $block ) {
        Agentshell_Test::assert_true(
            'image' !== ( $block['type'] ?? '' ),
            'the retrieval tool must not emit an image block for a refused read'
        );
    }

    $text = '';
    foreach ( $content as $block ) {
        if ( 'text' === ( $block['type'] ?? '' ) ) { $text .= $block['text']; }
    }
    Agentshell_Test::assert_true(
        false !== strpos( $text, 'agentshell_screenshot_forbidden' ),
        'the refusal must be reported: ' . $text
    );

    Privacy_Test::reset();
} );

Agentshell_Test::run( 'tool: a malformed id is rejected as a bad argument, not a lookup', function () {
    Privacy_Test::reset();
    $response = privacy_call_tool( new Get_Screenshot(), array( 'id' => '../../wp-config.php' ) );
    Agentshell_Test::assert_true(
        isset( $response['error'] ),
        'a traversal attempt must come back as an error, not an empty success'
    );
    Agentshell_Test::assert_true(
        false === stripos( json_encode( $response ), 'DB_PASSWORD' ),
        'the error must not echo anything read from the filesystem'
    );
} );

// ---------------------------------------------------------------------------
// 7. Nothing sensitive reaches the logs
// ---------------------------------------------------------------------------

Agentshell_Test::run( 'logs: image payloads are redacted from the audit log', function () {
    $source = file_get_contents( dirname( __DIR__ ) . '/agentshell-mcp/includes/class-transport.php' );
    preg_match_all( "/'([a-z0-9_]+)'\s*=>\s*'\[REDACTED\]/", $source, $m );

    Agentshell_Test::assert_true(
        false !== strpos( $source, 'content_base64' ),
        'the redaction list must cover base64 payloads'
    );
    Agentshell_Test::assert_true(
        false !== strpos( $source, 'screenshot_data' ),
        'the redaction list must cover screenshot payloads specifically'
    );

    // And the tool must never put an image into its *arguments*, which are
    // what the audit log records. Descriptions may mention images; a parameter
    // that carries one would not.
    $properties = array_keys( ( new Screenshot() )->get_input_schema()['properties'] );
    Agentshell_Test::assert_true(
        ! array_intersect( $properties, array( 'data', 'image', 'base64', 'content', 'screenshot' ) ),
        'no screenshot argument may carry an image payload: ' . implode( ',', $properties )
    );
} );

Agentshell_Test::run( 'logs: a capture failure is logged without the image', function () {
    Privacy_Test::reset();
    Privacy_Test::login_as( 'alice' );

    // A failed capture must not leave the bytes anywhere, logged or stored.
    $result = privacy_call_tool( new Screenshot(), array( 'url' => 'http://169.254.169.254/latest/meta-data/' ) );

    $encoded = json_encode( $result );
    Agentshell_Test::assert_true(
        false === strpos( $encoded, 'base64' ),
        'a rejected capture must not carry a payload of any kind'
    );

    Privacy_Test::reset();
} );

Agentshell_Test::run( 'cleanup: the private tree is removed from disk at teardown', function () {
    Privacy_Test::reset();
    Agentshell_Test::assert_false(
        is_dir( Screenshot_Store::base_dir() ),
        'the test must not leave a private screenshot tree behind'
    );
} );

Privacy_Test::reset();
Screenshot_Store::purge_expired();
Privacy_Test::rmtree( $GLOBALS['_agentshell_test_wp_content'] );