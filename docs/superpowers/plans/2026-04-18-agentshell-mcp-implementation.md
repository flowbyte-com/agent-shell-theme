# AgentShell MCP Plugin — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build two standalone components — a WordPress plugin exposing AgentShell as MCP tools via REST, and a PHP CLI daemon that proxies stdio ↔ HTTP so Claude Code can connect.

**Architecture:** WP Plugin is a thin MCP-over-HTTP server: it registers a REST endpoint at `/wp-json/agentshell-mcp/v1/mcp` that handles JSON-RPC messages. The PHP Daemon is a stdio MCP client launched by the connecting agent — it reads JSON-RPC messages from stdin, forwards them over HTTP to WordPress, and writes responses to stdout.

**Tech Stack:** PHP 7.4+ (plugin), PHP CLI 7.4+ (daemon, no external dependencies)

**Files created at:**
- `/home/v/workspace/projects/agentshell-mcp/` — WP plugin
- `/home/v/workspace/projects/agentshell-mcp-daemon/` — PHP daemon

---

## Phase 1: WordPress Plugin (agentshell-mcp)

### File Map

```
agentshell-mcp/
├── agentshell-mcp.php              # Plugin bootstrap, activation hook, rest_api_init
├── includes/
│   ├── class-json-rpc.php         # parse_request(), success_response(), error_response()
│   ├── class-error-codes.php      # JSON_RPC error code constants
│   ├── class-server.php            # MCP protocol: initialize, ping, tools/list, tools/call
│   ├── class-transport.php         # REST route + request handling
│   ├── class-auth.php             # WP Application Password Basic Auth
│   ├── class-activator.php        # DB table creation on activation
│   └── tools/
│       ├── class-base-tool.php     # Abstract base + validation helpers
│       ├── class-registry.php     # Tool registration + lookup
│       ├── class-get-config.php    # agentshell_get_config
│       ├── class-set-css-var.php   # agentshell_set_css_var
│       ├── class-set-design.php    # agentshell_set_design
│       ├── class-list-zones.php    # agentshell_list_zones
│       ├── class-set-zone-source.php # agentshell_set_zone_source
│       ├── class-inject-json-block.php # agentshell_inject_json_block
│       ├── class-list-widgets.php  # agentshell_list_widgets
│       ├── class-register-widget.php # agentshell_register_widget
│       ├── class-set-layout.php   # agentshell_set_layout
│       └── class-get-site-info.php # agentshell_get_site_info
└── README.md
```

---

### Task 1: Plugin Bootstrap and Activation

**Files:**
- Create: `agentshell-mcp/agentshell-mcp.php`
- Create: `agentshell-mcp/includes/class-activator.php`

- [ ] **Step 1: Create plugin bootstrap**

```php
<?php
/**
 * Plugin Name: AgentShell MCP
 * Description: MCP server for AgentShell — exposes AgentShell zones, CSS vars, widgets, and layout as JSON-RPC tools.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'AGENTSHELL_MCP_VERSION', '1.0.0' );
define( 'AGENTSHELL_MCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGENTSHELL_MCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-activator.php';
require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-error-codes.php';
require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-json-rpc.php';
require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-auth.php';
require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-base-tool.php';
require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-registry.php';

register_activation_hook( __FILE__, array( 'AgentShell_MCP\\Activator', 'activate' ) );

add_action( 'rest_api_init', function() {
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-server.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-transport.php';

    $registry = new AgentShell_MCP\Tools\Registry();
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-get-config.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-set-css-var.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-set-design.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-list-zones.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-set-zone-source.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-inject-json-block.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-list-widgets.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-register-widget.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-set-layout.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-get-site-info.php';

    $registry->register( new AgentShell_MCP\Tools\Get_Config() );
    $registry->register( new AgentShell_MCP\Tools\Set_Css_Var() );
    $registry->register( new AgentShell_MCP\Tools\Set_Design() );
    $registry->register( new AgentShell_MCP\Tools\List_Zones() );
    $registry->register( new AgentShell_MCP\Tools\Set_Zone_Source() );
    $registry->register( new AgentShell_MCP\Tools\Inject_Json_Block() );
    $registry->register( new AgentShell_MCP\Tools\List_Widgets() );
    $registry->register( new AgentShell_MCP\Tools\Register_Widget() );
    $registry->register( new AgentShell_MCP\Tools\Set_Layout() );
    $registry->register( new AgentShell_MCP\Tools\Get_Site_Info() );

    $server    = new AgentShell_MCP\Server( $registry );
    $transport = new AgentShell_MCP\Transport( $server );
    $transport->register_routes();
} );
```

- [ ] **Step 2: Create class-activator.php (audit log table creation)**

```php
<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Activator {
    public static function activate() {
        global $wpdb;
        $table = $wpdb->prefix . 'agentshell_mcp_audit_log';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token_id bigint(20) unsigned NOT NULL DEFAULT 0,
            wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            tool_name varchar(100) NOT NULL DEFAULT '',
            arguments longtext NOT NULL,
            result_status varchar(20) NOT NULL DEFAULT '',
            ip_address varchar(45) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY tool_name (tool_name),
            KEY created_at (created_at)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'agentshell_mcp_version', AGENTSHELL_MCP_VERSION );
    }
}
```

- [ ] **Step 3: Test plugin activates without errors**

Run: `wp plugin activate agentshell-mcp --allow-root` (if WP-CLI available) or activate via admin UI.

- [ ] **Step 4: Verify audit log table was created**

Run: `mysql -u root -p -e "DESCRIBE $(wp db prefix --allow-root)agentshell_mcp_audit_log;"` or check via phpMyAdmin.

- [ ] **Step 5: Commit**

```bash
git init agentshell-mcp && cd agentshell-mcp
git add agentshell-mcp.php includes/class-activator.php
git commit -m "feat: plugin bootstrap and activation hook with audit log table"
```

---

### Task 2: JSON-RPC Core and Error Codes

**Files:**
- Create: `agentshell-mcp/includes/class-error-codes.php`
- Create: `agentshell-mcp/includes/class-json-rpc.php`

- [ ] **Step 1: Create error codes class**

```php
<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Error_Codes {
    const PARSE_ERROR           = -32700;
    const INVALID_REQUEST       = -32600;
    const METHOD_NOT_FOUND      = -32601;
    const INVALID_PARAMS        = -32602;
    const INTERNAL_ERROR        = -32603;
    // Transport-specific codes (range -32000 to -32099)
    const CONNECTION_ERROR      = -32000;
    const AUTH_ERROR            = -32001;
    const TIMEOUT_ERROR         = -32002;
    const RATE_LIMITED          = -32003;
}
```

- [ ] **Step 2: Create JSON-RPC class**

```php
<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class JSON_RPC {
    public static function parse_request( $body ) {
        if ( empty( $body ) ) {
            return new \WP_Error( 'empty_body', 'Request body is empty' );
        }

        $decoded = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new \WP_Error( 'parse_error', 'Invalid JSON: ' . json_last_error_msg() );
        }

        return $decoded;
    }

    public static function success_response( $id, $result ) {
        return array(
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => $result,
        );
    }

    public static function error_response( $id, $code, $message ) {
        return array(
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => array(
                'code'    => $code,
                'message' => $message,
            ),
        );
    }

    public static function is_notification( $message ) {
        return isset( $message['id'] ) && $message['id'] === null;
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add includes/class-error-codes.php includes/class-json-rpc.php
git commit -m "feat: JSON-RPC message handling and error codes"
```

---

### Task 3: Auth and Transport

**Files:**
- Create: `agentshell-mcp/includes/class-auth.php`
- Create: `agentshell-mcp/includes/class-transport.php`

- [ ] **Step 1: Create auth class (WP Application Password Basic Auth)**

```php
<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Auth {
    private $token_manager;

    public function __construct() {
        // WP Application Passwords handle auth — we just validate the user has manage_options
    }

    public function authenticate( \WP_REST_Request $request ) {
        $auth_header = $request->get_header( 'authorization' );
        if ( ! $auth_header || ! str_starts_with( strtolower( $auth_header ), 'basic ' ) ) {
            return new \WP_Error( 'no_auth', 'Missing Basic Auth header' );
        }

        $creds = base64_decode( substr( $auth_header, 6 ), true );
        if ( ! $creds || ! str_contains( $creds, ':' ) ) {
            return new \WP_Error( 'bad_creds', 'Invalid Basic Auth format' );
        }

        $parts        = explode( ':', $creds, 2 );
        $username     = trim( $parts[0] );
        $app_password = $parts[1];

        $user = get_user_by( 'login', $username )
             ?: get_user_by( 'email', $username );

        if ( ! $user ) {
            return new \WP_Error( 'user_not_found', 'User not found' );
        }

        $stored = \WP_Application_Passwords::get_user_application_passwords( $user->ID );
        foreach ( $stored as $item ) {
            if ( \WP_Application_Passwords::check_password( $app_password, $item['password'] ) ) {
                if ( ! user_can( $user, 'manage_options' ) ) {
                    return new \WP_Error( 'forbidden', 'User must have manage_options capability' );
                }
                wp_set_current_user( $user->ID );
                return array( 'user_id' => $user->ID );
            }
        }

        return new \WP_Error( 'invalid_password', 'Invalid application password' );
    }
}
```

- [ ] **Step 2: Create transport class (REST endpoint)**

```php
<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Transport {
    const NAMESPACE = 'agentshell-mcp/v1';
    const ROUTE    = '/mcp';

    private $server;
    private $auth;

    public function __construct( Server $server ) {
        $this->server = $server;
        $this->auth  = new Auth();
    }

    public function register_routes() {
        register_rest_route( self::NAMESPACE, self::ROUTE, array(
            'methods'             => 'POST',
            'callback'           => array( $this, 'handle_post' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( self::NAMESPACE, self::ROUTE, array(
            'methods'             => 'OPTIONS',
            'callback'           => function() { return null; },
            'permission_callback' => '__return_true',
        ) );
    }

    public function handle_post( \WP_REST_Request $request ) {
        // Authenticate
        $auth_result = $this->auth->authenticate( $request );
        if ( is_wp_error( $auth_result ) ) {
            return new \WP_REST_Response(
                JSON_RPC::error_response( null, Error_Codes::AUTH_ERROR, $auth_result->get_error_message() ),
                401
            );
        }

        // Parse JSON-RPC body
        $parsed = JSON_RPC::parse_request( $request->get_body() );
        if ( is_wp_error( $parsed ) ) {
            return new \WP_REST_Response(
                JSON_RPC::error_response( null, Error_Codes::PARSE_ERROR, $parsed->get_error_message() ),
                400
            );
        }

        // Handle batch
        if ( is_array( $parsed ) && isset( $parsed[0] ) ) {
            return $this->handle_batch( $parsed, $auth_result['user_id'] );
        }

        // Handle single message
        $result = $this->server->handle_message( $parsed, $auth_result['user_id'] );

        // Log tool call if applicable
        if ( isset( $parsed['method'] ) && $parsed['method'] === 'tools/call' ) {
            $this->log_tool_call( $auth_result['user_id'], $parsed['params']['name'] ?? '', $parsed['params']['arguments'] ?? array(), 'success' );
        }

        return new \WP_REST_Response( $result, 200 );
    }

    private function handle_batch( array $messages, $user_id ) {
        $responses = array();
        foreach ( $messages as $message ) {
            $result = $this->server->handle_message( $message, $user_id );
            if ( ! JSON_RPC::is_notification( $message ) ) {
                $responses[] = $result;
            }
        }
        return new \WP_REST_Response( $responses, 200 );
    }

    private function log_tool_call( $user_id, $tool_name, $arguments, $status ) {
        global $wpdb;
        $table = $wpdb->prefix . 'agentshell_mcp_audit_log';

        $safe_args = self::redact_sensitive_args( $arguments );

        $wpdb->insert( $table, array(
            'wp_user_id'     => $user_id,
            'tool_name'      => $tool_name,
            'arguments'      => wp_json_encode( $safe_args ),
            'result_status'  => $status,
            'ip_address'    => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
            'created_at'    => current_time( 'mysql', true ),
        ), array( '%d', '%s', '%s', '%s', '%s', '%s' ) );
    }

    private static function redact_sensitive_args( $args ) {
        if ( ! is_array( $args ) ) { return $args; }
        $sensitive = '/^(password|pass|secret|token|api[_\-]?key|authorization|content_base64)$/i';
        $result = array();
        foreach ( $args as $key => $value ) {
            if ( preg_match( $sensitive, $key ) ) {
                $result[ $key ] = '[REDACTED]';
            } elseif ( is_array( $value ) ) {
                $result[ $key ] = self::redact_sensitive_args( $value );
            } else {
                $result[ $key ] = $value;
            }
        }
        return $result;
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add includes/class-auth.php includes/class-transport.php
git commit -m "feat: WP Application Password auth and REST transport"
```

---

### Task 4: MCP Server (initialize, ping, tools/list, tools/call)

**Files:**
- Create: `agentshell-mcp/includes/class-server.php`

- [ ] **Step 1: Create the MCP server class**

```php
<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Server {
    const PROTOCOL_VERSION = '2025-03-26';
    const SERVER_NAME      = 'agentshell-mcp';

    private $registry;

    public function __construct( Tools\Registry $registry ) {
        $this->registry = $registry;
    }

    public function handle_message( $message, $user_id ) {
        $method = $message['method'] ?? '';
        $id     = $message['id'] ?? null;
        $params = $message['params'] ?? array();

        switch ( $method ) {
            case 'initialize':
                return $this->handle_initialize( $id, $params );
            case 'ping':
                return JSON_RPC::success_response( $id, (object) array() );
            case 'tools/list':
                return $this->handle_tools_list( $id );
            case 'tools/call':
                return $this->handle_tools_call( $id, $params );
            case 'notifications/initialized':
                return null; // No response for notifications
            default:
                return JSON_RPC::error_response( $id, Error_Codes::METHOD_NOT_FOUND, "Method not found: $method" );
        }
    }

    private function handle_initialize( $id, $params ) {
        return JSON_RPC::success_response( $id, array(
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities'   => array(
                'tools'     => (object) array(),
                'resources' => (object) array(),
            ),
            'serverInfo'     => array(
                'name'    => self::SERVER_NAME,
                'version' => AGENTSHELL_MCP_VERSION,
            ),
            'instructions'   => 'AgentShell MCP Server. Use tools to manage the AgentShell theme configuration including zones, CSS variables, design, widgets, and layout.',
        ) );
    }

    private function handle_tools_list( $id ) {
        return JSON_RPC::success_response( $id, array(
            'tools' => $this->registry->get_all_definitions(),
        ) );
    }

    private function handle_tools_call( $id, $params ) {
        $tool_name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? array();

        if ( empty( $tool_name ) ) {
            return JSON_RPC::error_response( $id, Error_Codes::INVALID_PARAMS, 'Missing tool name' );
        }

        $tool = $this->registry->get_tool( $tool_name );
        if ( null === $tool ) {
            return JSON_RPC::error_response( $id, Error_Codes::METHOD_NOT_FOUND, "Unknown tool: $tool_name" );
        }

        try {
            $result = $tool->execute( $arguments );
            $text = is_string( $result ) ? $result : wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
            return JSON_RPC::success_response( $id, array(
                'content' => array( array( 'type' => 'text', 'text' => $text ) ),
            ) );
        } catch ( \InvalidArgumentException $e ) {
            return JSON_RPC::success_response( $id, array(
                'content' => array( array( 'type' => 'text', 'text' => 'Error: ' . $e->getMessage() ) ),
                'isError' => true,
            ) );
        } catch ( \Exception $e ) {
            return JSON_RPC::success_response( $id, array(
                'content' => array( array( 'type' => 'text', 'text' => 'Error: ' . $e->getMessage() ) ),
                'isError' => true,
            ) );
        }
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/class-server.php
git commit -m "feat: MCP server with initialize, ping, tools/list, tools/call"
```

---

### Task 5: Base Tool and Registry

**Files:**
- Create: `agentshell-mcp/includes/tools/class-base-tool.php`
- Create: `agentshell-mcp/includes/tools/class-registry.php`

- [ ] **Step 1: Create base tool abstract class**

```php
<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

abstract class Base_Tool {
    abstract public function get_name();
    abstract public function get_description();
    abstract public function get_input_schema();
    abstract public function execute( array $arguments );

    public function get_required_capability() {
        return 'manage_options';
    }

    public function get_definition() {
        return array(
            'name'        => $this->get_name(),
            'description' => $this->get_description(),
            'inputSchema' => $this->get_input_schema(),
        );
    }

    protected function validate_required( array $arguments, array $required_keys ) {
        $missing = array();
        foreach ( $required_keys as $key ) {
            if ( ! isset( $arguments[ $key ] ) || '' === $arguments[ $key ] ) {
                $missing[] = $key;
            }
        }
        if ( ! empty( $missing ) ) {
            throw new \InvalidArgumentException( 'Missing required parameters: ' . implode( ', ', $missing ) );
        }
    }

    protected function get_agentshell_config() {
        return get_option( 'agentshell_config', array() );
    }

    protected function update_agentshell_config( array $config ) {
        return update_option( 'agentshell_config', $config );
    }
}
```

- [ ] **Step 2: Create tool registry class**

```php
<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Registry {
    private $tools = array();

    public function register( Base_Tool $tool ) {
        $this->tools[ $tool->get_name() ] = $tool;
    }

    public function get_tool( $name ) {
        return $this->tools[ $name ] ?? null;
    }

    public function get_all_definitions() {
        return array_values( array_map( function( $tool ) {
            return $tool->get_definition();
        }, $this->tools ) );
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add includes/tools/class-base-tool.php includes/tools/class-registry.php
git commit -m "feat: base tool abstract class and tool registry"
```

---

### Task 6: Tool Implementations (Get_Config, Set_Css_Var, Set_Design, List_Zones, Set_Zone_Source)

**Files:**
- Create: `agentshell-mcp/includes/tools/class-get-config.php`
- Create: `agentshell-mcp/includes/tools/class-set-css-var.php`
- Create: `agentshell-mcp/includes/tools/class-set-design.php`
- Create: `agentshell-mcp/includes/tools/class-list-zones.php`
- Create: `agentshell-mcp/includes/tools/class-set-zone-source.php`

- [ ] **Step 1: Create class-get-config.php**

```php
<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Get_Config extends Base_Tool {
    public function get_name() { return 'agentshell_get_config'; }
    public function get_description() { return 'Get the full AgentShell configuration including zones, design, layout, widgets, and CSS variables.'; }
    public function get_input_schema() { return array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ); }

    public function execute( array $arguments ) {
        $config = $this->get_agentshell_config();
        if ( function_exists( 'agentshell_flatten_config' ) ) {
            return agentshell_flatten_config( $config );
        }
        return $config;
    }
}
```

- [ ] **Step 2: Create class-set-css-var.php**

```php
<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Set_Css_Var extends Base_Tool {
    public function get_name() { return 'agentshell_set_css_var'; }
    public function get_description() { return 'Set a single CSS custom property (variable) on the theme.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'name'  => array( 'type' => 'string', 'description' => 'CSS variable name (must start with --)', 'pattern' => '^--' ),
                'value' => array( 'type' => 'string', 'description' => 'CSS value', 'maxLength' => 500 ),
            ),
            'required'   => array( 'name', 'value' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'name', 'value' ) );

        $name = trim( $arguments['name'] );
        if ( ! str_starts_with( $name, '--' ) ) {
            throw new \InvalidArgumentException( 'CSS variable name must start with --' );
        }
        if ( strlen( $arguments['value'] ) > 500 ) {
            throw new \InvalidArgumentException( 'CSS value must be 500 characters or less' );
        }

        $config = $this->get_agentshell_config();
        if ( ! isset( $config['design'] ) ) { $config['design'] = array(); }
        if ( ! isset( $config['design']['custom_css_vars'] ) ) { $config['design']['custom_css_vars'] = array(); }
        $config['design']['custom_css_vars'][ $name ] = $arguments['value'];
        $this->update_agentshell_config( $config );

        return array( 'name' => $name, 'value' => $arguments['value'] );
    }
}
```

- [ ] **Step 3: Create class-set-design.php**

```php
<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Set_Design extends Base_Tool {
    public function get_name() { return 'agentshell_set_design'; }
    public function get_description() { return 'Update design system values (colors, typography). All fields optional — only provided fields are updated.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'colors'     => array(
                    'type'       => 'object',
                    'properties' => array(
                        'background' => array( 'type' => 'string' ),
                        'surface'    => array( 'type' => 'string' ),
                        'text'       => array( 'type' => 'string' ),
                        'accent'     => array( 'type' => 'string' ),
                        'border'     => array( 'type' => 'string' ),
                    ),
                ),
                'typography' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'fontFamily' => array( 'type' => 'string' ),
                        'baseSize'   => array( 'type' => 'string' ),
                    ),
                ),
            ),
        );
    }

    public function execute( array $arguments ) {
        $config = $this->get_agentshell_config();
        if ( ! isset( $config['design'] ) ) { $config['design'] = array(); }

        if ( ! empty( $arguments['colors'] ) ) {
            if ( ! isset( $config['design']['colors'] ) ) { $config['design']['colors'] = array(); }
            foreach ( $arguments['colors'] as $k => $v ) {
                $config['design']['colors'][ $k ] = $v;
            }
        }

        if ( ! empty( $arguments['typography'] ) ) {
            if ( ! isset( $config['design']['typography'] ) ) { $config['design']['typography'] = array(); }
            foreach ( $arguments['typography'] as $k => $v ) {
                $config['design']['typography'][ $k ] = $v;
            }
        }

        $this->update_agentshell_config( $config );
        return $config['design'];
    }
}
```

- [ ] **Step 4: Create class-list-zones.php**

```php
<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class List_Zones extends Base_Tool {
    public function get_name() { return 'agentshell_list_zones'; }
    public function get_description() { return 'List all declared zones with their current source type and configuration.'; }
    public function get_input_schema() { return array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ); }

    public function execute( array $arguments ) {
        if ( function_exists( 'agentshell_get_zones' ) ) {
            return array( 'zones' => agentshell_get_zones() );
        }
        $config = $this->get_agentshell_config();
        return array( 'zones' => $config['zones'] ?? array(
            array( 'id' => 'header',  'label' => 'Header',  'source' => 'wp_loop' ),
            array( 'id' => 'main',    'label' => 'Main',    'source' => 'wp_loop' ),
            array( 'id' => 'sidebar', 'label' => 'Sidebar', 'source' => 'wp_widget_area', 'widget_area_id' => 'primary-sidebar' ),
            array( 'id' => 'footer',  'label' => 'Footer',  'source' => 'wp_loop' ),
        ) );
    }
}
```

- [ ] **Step 5: Create class-set-zone-source.php**

```php
<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Set_Zone_Source extends Base_Tool {
    private $valid_sources = array( 'wp_loop', 'wp_widget_area', 'json_block', 'widget' );

    public function get_name() { return 'agentshell_set_zone_source'; }
    public function get_description() { return 'Change a zone content source type (wp_loop, wp_widget_area, json_block, widget).'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'zone_id'       => array( 'type' => 'string' ),
                'source'        => array( 'type' => 'string', 'enum' => array( 'wp_loop', 'wp_widget_area', 'json_block', 'widget' ) ),
                'widget_area_id' => array( 'type' => 'string' ),
            ),
            'required'   => array( 'zone_id', 'source' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'zone_id', 'source' ) );

        if ( ! in_array( $arguments['source'], $this->valid_sources, true ) ) {
            throw new \InvalidArgumentException( 'Invalid source. Must be one of: ' . implode( ', ', $this->valid_sources ) );
        }
        if ( $arguments['source'] === 'wp_widget_area' && empty( $arguments['widget_area_id'] ) ) {
            throw new \InvalidArgumentException( 'widget_area_id required when source is wp_widget_area' );
        }

        $config = $this->get_agentshell_config();
        $zones  = $config['zones'] ?? array();

        $found = false;
        foreach ( $zones as &$zone ) {
            if ( $zone['id'] === $arguments['zone_id'] ) {
                $zone['source'] = $arguments['source'];
                if ( isset( $arguments['widget_area_id'] ) ) {
                    $zone['widget_area_id'] = $arguments['widget_area_id'];
                }
                $found = true;
                break;
            }
        }
        unset( $zone );

        if ( ! $found ) {
            throw new \InvalidArgumentException( "Zone not found: {$arguments['zone_id']}" );
        }

        $config['zones'] = $zones;
        $this->update_agentshell_config( $config );

        return array( 'zone_id' => $arguments['zone_id'], 'source' => $arguments['source'] );
    }
}
```

- [ ] **Step 6: Commit**

```bash
git add includes/tools/class-get-config.php \
        includes/tools/class-set-css-var.php \
        includes/tools/class-set-design.php \
        includes/tools/class-list-zones.php \
        includes/tools/class-set-zone-source.php
git commit -m "feat: agentshell_get_config, set_css_var, set_design, list_zones, set_zone_source tools"
```

---

### Task 7: Tool Implementations (Inject_Json_Block, List_Widgets, Register_Widget, Set_Layout, Get_Site_Info)

**Files:**
- Create: `agentshell-mcp/includes/tools/class-inject-json-block.php`
- Create: `agentshell-mcp/includes/tools/class-list-widgets.php`
- Create: `agentshell-mcp/includes/tools/class-register-widget.php`
- Create: `agentshell-mcp/includes/tools/class-set-layout.php`
- Create: `agentshell-mcp/includes/tools/class-get-site-info.php`

- [ ] **Step 1: Create class-inject-json-block.php**

```php
<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Inject_Json_Block extends Base_Tool {
    public function get_name() { return 'agentshell_inject_json_block'; }
    public function get_description() { return 'Inject raw HTML into a zone via json_block source. Script tags and style attributes are stripped server-side.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'zone_id' => array( 'type' => 'string', 'description' => 'Target zone ID' ),
                'html'    => array( 'type' => 'string', 'description' => 'HTML content (max 10000 chars)', 'maxLength' => 10000 ),
            ),
            'required'   => array( 'zone_id', 'html' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'zone_id', 'html' ) );

        $html = $arguments['html'];
        if ( strlen( $html ) > 10000 ) {
            throw new \InvalidArgumentException( 'html must be 10000 characters or less' );
        }

        // Strip script tags and style attributes (security)
        $html = preg_replace( '/<\/?script\b[^>]*>/i', '', $html );
        $html = preg_replace( '/\s+style\s*=\s*["\'][^"\']*["\']/i', '', $html );
        $html = wp_kses_post( $html );

        $config = $this->get_agentshell_config();
        $zones  = $config['zones'] ?? array();

        $found = false;
        foreach ( $zones as &$zone ) {
            if ( $zone['id'] === $arguments['zone_id'] ) {
                $zone['source']       = 'json_block';
                $zone['json_content'] = $html;
                $found = true;
                break;
            }
        }
        unset( $zone );

        if ( ! $found ) {
            throw new \InvalidArgumentException( "Zone not found: {$arguments['zone_id']}" );
        }

        $config['zones'] = $zones;
        $this->update_agentshell_config( $config );

        return array(
            'zone_id'     => $arguments['zone_id'],
            'source'      => 'json_block',
            'html_length' => strlen( $html ),
        );
    }
}
```

- [ ] **Step 2: Create class-list-widgets.php**

```php
<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class List_Widgets extends Base_Tool {
    public function get_name() { return 'agentshell_list_widgets'; }
    public function get_description() { return 'List all registered widgets — stable (file-based) and agent-defined.'; }
    public function get_input_schema() { return array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ); }

    public function execute( array $arguments ) {
        $widgets = array();

        // Stable widgets from file system
        $stable_index = get_template_directory() . '/widgets/.index.json';
        if ( file_exists( $stable_index ) ) {
            $index = json_decode( file_get_contents( $stable_index ), true );
            foreach ( $index['stable'] ?? array() as $entry ) {
                $widgets[] = array(
                    'id'      => $entry['id'] ?? '',
                    'name'    => $entry['name'] ?? '',
                    'source'  => 'stable',
                    'version' => $entry['version'] ?? '',
                );
            }
        }

        // Agent-defined widgets from config
        $config = $this->get_agentshell_config();
        foreach ( $config['widgets'] ?? array() as $w ) {
            $widgets[] = array(
                'id'     => $w['id'] ?? '',
                'name'   => $w['name'] ?? '',
                'source' => 'agent',
            );
        }

        return array( 'widgets' => $widgets );
    }
}
```

- [ ] **Step 3: Create class-register-widget.php**

```php
<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Register_Widget extends Base_Tool {
    public function get_name() { return 'agentshell_register_widget'; }
    public function get_description() { return 'Register or update an agent-defined widget.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'id'       => array( 'type' => 'string', 'description' => 'Widget ID (alphanumeric + dash/underscore, max 50)', 'pattern' => '^[a-zA-Z0-9_-]+$', 'maxLength' => 50 ),
                'name'     => array( 'type' => 'string', 'maxLength' => 100 ),
                'init_js'  => array( 'type' => 'string', 'maxLength' => 5000 ),
                'css'      => array( 'type' => 'string', 'maxLength' => 5000 ),
                'template' => array( 'type' => 'string', 'maxLength' => 2000 ),
            ),
            'required'   => array( 'id', 'name' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'id', 'name' ) );

        if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $arguments['id'] ) ) {
            throw new \InvalidArgumentException( 'Widget ID must be alphanumeric with dashes/underscores only' );
        }
        if ( strlen( $arguments['id'] ) > 50 ) {
            throw new \InvalidArgumentException( 'Widget ID must be 50 characters or less' );
        }

        $widget = array(
            'id'   => $arguments['id'],
            'name' => $arguments['name'],
        );
        if ( ! empty( $arguments['init_js'] ) )  { $widget['init_js']  = $arguments['init_js']; }
        if ( ! empty( $arguments['css'] ) )       { $widget['css']       = $arguments['css']; }
        if ( ! empty( $arguments['template'] ) )  { $widget['template']  = $arguments['template']; }

        $config = $this->get_agentshell_config();
        if ( ! isset( $config['widgets'] ) ) { $config['widgets'] = array(); }

        $found = false;
        foreach ( $config['widgets'] as &$w ) {
            if ( $w['id'] === $widget['id'] ) {
                $w = array_merge( $w, $widget );
                $found = true;
                break;
            }
        }
        unset( $w );

        if ( ! $found ) {
            $config['widgets'][] = $widget;
        }

        $this->update_agentshell_config( $config );
        return $widget;
    }
}
```

- [ ] **Step 4: Create class-set-layout.php**

```php
<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Set_Layout extends Base_Tool {
    public function get_name() { return 'agentshell_set_layout'; }
    public function get_description() { return 'Update layout grid areas per breakpoint, gap, and padding.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'breakpoints' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'mobile'  => array( 'type' => 'string' ),
                        'tablet'  => array( 'type' => 'string' ),
                        'desktop' => array( 'type' => 'string' ),
                    ),
                ),
                'grid_areas'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'mobile'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                        'tablet'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                        'desktop' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                    ),
                ),
                'grid_gap'    => array( 'type' => 'string' ),
                'grid_padding'=> array( 'type' => 'string' ),
            ),
        );
    }

    public function execute( array $arguments ) {
        $config = $this->get_agentshell_config();
        if ( ! isset( $config['layout'] ) ) { $config['layout'] = array(); }

        if ( ! empty( $arguments['breakpoints'] ) ) {
            $config['layout']['breakpoints'] = $arguments['breakpoints'];
        }
        if ( ! empty( $arguments['grid_areas'] ) ) {
            $config['layout']['grid_areas'] = $arguments['grid_areas'];
        }
        if ( isset( $arguments['grid_gap'] ) ) {
            $config['layout']['grid_gap'] = $arguments['grid_gap'];
        }
        if ( isset( $arguments['grid_padding'] ) ) {
            $config['layout']['grid_padding'] = $arguments['grid_padding'];
        }

        $this->update_agentshell_config( $config );
        return $config['layout'];
    }
}
```

- [ ] **Step 5: Create class-get-site-info.php**

```php
<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Get_Site_Info extends Base_Tool {
    public function get_name() { return 'agentshell_get_site_info'; }
    public function get_description() { return 'Get basic site information for agent context.'; }
    public function get_input_schema() { return array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ); }

    public function execute( array $arguments ) {
        return array(
            'name'               => get_bloginfo( 'name' ),
            'url'                => get_bloginfo( 'url' ),
            'agentshell_version' => AGENTSHELL_MCP_VERSION,
            'theme_name'         => wp_get_theme()->get( 'Name' ),
            'plugin_url'         => AGENTSHELL_MCP_PLUGIN_URL,
        );
    }
}
```

- [ ] **Step 6: Commit**

```bash
git add includes/tools/class-inject-json-block.php \
        includes/tools/class-list-widgets.php \
        includes/tools/class-register-widget.php \
        includes/tools/class-set-layout.php \
        includes/tools/class-get-site-info.php
git commit -m "feat: remaining 5 tool implementations"
```

---

### Task 8: WP Plugin End-to-End Test

- [ ] **Step 1: Verify REST endpoint responds**

```bash
curl -s -X POST http://localhost:10003/wp-json/agentshell-mcp/v1/mcp \
  -H "Content-Type: application/json" \
  -u "user:app_password" \
  -d '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2025-03-26"},"id":1}'
```

Expected: JSON-RPC response with serverInfo.name = "agentshell-mcp"

- [ ] **Step 2: Verify tools/list returns all 10 tools**

```bash
curl -s -X POST http://localhost:10003/wp-json/agentshell-mcp/v1/mcp \
  -H "Content-Type: application/json" \
  -u "user:app_password" \
  -d '{"jsonrpc":"2.0","method":"tools/list","params":{},"id":2}'
```

Expected: 10 tools including agentshell_get_config, agentshell_set_css_var, etc.

- [ ] **Step 3: Verify agentshell_get_config returns current config**

```bash
curl -s -X POST http://localhost:10003/wp-json/agentshell-mcp/v1/mcp \
  -H "Content-Type: application/json" \
  -u "user:app_password" \
  -d '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"agentshell_get_config","arguments":{}},"id":3}'
```

- [ ] **Step 4: Commit remaining files**

```bash
git add .
git commit -m "feat: complete agentshell-mcp WordPress plugin"
```

---

## Phase 2: PHP Daemon (agentshell-mcp-daemon)

### File Map

```
agentshell-mcp-daemon/
├── daemon.php               # Main entry point
├── src/
│   ├── JsonRpc.php         # JSON-RPC message builder/parser
│   ├── Client.php           # HTTP client (PHP stream_context)
│   └── Transport.php       # stdio read/write
└── README.md
```

---

### Task 9: Daemon Bootstrap and JSON-RPC

**Files:**
- Create: `agentshell-mcp-daemon/daemon.php`
- Create: `agentshell-mcp-daemon/src/JsonRpc.php`

- [ ] **Step 1: Create src/JsonRpc.php**

```php
<?php
namespace AgentShellMCPDaemon;

class JsonRpc {
    public static function build_request( string $method, array $params = array(), $id = null ) {
        if ( $id === null ) { $id = mt_rand( 1, PHP_INT_MAX ); }
        return json_encode( array(
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => $params,
            'id'      => $id,
        ) );
    }

    public static function parse_response( string $json ) {
        $decoded = json_decode( $json, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            throw new \RuntimeException( 'Invalid JSON from server: ' . json_last_error_msg() );
        }
        return $decoded;
    }

    public static function is_error( array $response ) {
        return isset( $response['error'] );
    }
}
```

- [ ] **Step 2: Create daemon.php**

```php
<?php
namespace AgentShellMCPDaemon;

require_once __DIR__ . '/src/JsonRpc.php';
require_once __DIR__ . '/src/Client.php';
require_once __DIR__ . '/src/Transport.php';

class Daemon {
    private $config;
    private $client;
    private $transport;
    private $verbose = false;
    private $pending_requests = array();
    private $next_id = 1;

    public function __construct( array $config, bool $verbose = false ) {
        $this->config    = $config;
        $this->verbose   = $verbose;
        $this->client    = new Client( $config['url'], $config['user'], $config['pass'], $config['timeout'] ?? 30 );
        $this->transport = new Transport();
    }

    public function run() {
        $this->send_initialize();

        while ( true ) {
            $line = $this->transport->read_line();
            if ( $line === null ) { break; } // EOF

            $line = trim( $line );
            if ( $line === '' ) { continue; }

            if ( $this->verbose ) { fwrite( STDERR, "IN: $line\n" ); }

            $messages = $this->parse_messages( $line );
            foreach ( $messages as $message ) {
                $this->handle_client_message( $message );
            }
        }
    }

    private function send_initialize() {
        $request = JsonRpc::build_request( 'initialize', array(
            'protocolVersion' => '2025-03-26',
            'clientInfo'      => array( 'name' => 'agentshell-mcp-daemon', 'version' => '1.0.0' ),
            'capabilities'    => array(),
        ), $this->next_id++ );

        if ( $this->verbose ) { fwrite( STDERR, "OUT: $request\n" ); }

        $response = $this->client->send( $request );
        $this->transport->write_line( $response );
    }

    private function handle_client_message( array $message ) {
        $method = $message['method'] ?? '';
        $id     = $message['id'] ?? null;

        // Notifications: forward directly, no response expected
        if ( $id === null || ( isset( $message['id'] ) && $message['id'] === null ) ) {
            $request = JsonRpc::build_request( $method, $message['params'] ?? array() );
            if ( $this->verbose ) { fwrite( STDERR, "OUT(notification): $request\n" ); }
            $this->client->send( $request );
            return;
        }

        $request = JsonRpc::build_request( $method, $message['params'] ?? array(), $id );

        if ( $this->verbose ) { fwrite( STDERR, "OUT: $request\n" ); }

        try {
            $response = $this->client->send( $request );
            if ( $this->verbose ) { fwrite( STDERR, "IN: $response\n" ); }
            $this->transport->write_line( $response );
        } catch ( \Exception $e ) {
            $error_response = json_encode( array(
                'jsonrpc' => '2.0',
                'id'      => $id,
                'error'   => array( 'code' => -32000, 'message' => $e->getMessage() ),
            ) );
            $this->transport->write_line( $error_response );
        }
    }

    private function parse_messages( string $line ) {
        $decoded = json_decode( $line, true );
        if ( json_last_error() === JSON_ERROR_NONE ) {
            if ( is_array( $decoded ) && isset( $decoded['jsonrpc'] ) ) {
                return isset( $decoded[0] ) ? $decoded : array( $decoded );
            }
        }
        // If it's a batch but JSON parse failed on individual message, skip
        fwrite( STDERR, "Skipping malformed JSON-RPC message\n" );
        return array();
    }
}

// CLI entry point
$options = getopt( '', array( 'config:', 'verbose' ) );
$config_path = $options['config'] ?? ( getenv( 'HOME' ) . '/.agentshell-mcp.json' );

if ( ! file_exists( $config_path ) ) {
    fwrite( STDERR, "Config file not found: $config_path\n" );
    exit( 1 );
}

$config = json_decode( file_get_contents( $config_path ), true );
if ( ! $config || empty( $config['url'] ) || empty( $config['user'] ) || empty( $config['pass'] ) ) {
    fwrite( STDERR, "Invalid config: must contain url, user, and pass\n" );
    exit( 1 );
}

$verbose = isset( $options['verbose'] );
$daemon = new Daemon( $config, $verbose );
$daemon->run();
```

- [ ] **Step 3: Commit**

```bash
git init agentshell-mcp-daemon && cd agentshell-mcp-daemon
git add daemon.php src/JsonRpc.php
git commit -m "feat: daemon bootstrap and JSON-RPC core"
```

---

### Task 10: Daemon HTTP Client and Transport

**Files:**
- Create: `agentshell-mcp-daemon/src/Client.php`
- Create: `agentshell-mcp-daemon/src/Transport.php`

- [ ] **Step 1: Create src/Client.php**

```php
<?php
namespace AgentShellMCPDaemon;

class Client {
    private $url;
    private $user;
    private $pass;
    private $timeout;

    public function __construct( string $url, string $user, string $pass, int $timeout = 30 ) {
        $this->url     = $url;
        $this->user    = $user;
        $this->pass    = $pass;
        $this->timeout = $timeout;
    }

    public function send( string $json_rpc_request ) : string {
        $auth   = base64_encode( "$this->user:$this->pass" );
        $context = stream_context_create( array(
            'http' => array(
                'method'           => 'POST',
                'header'           => array(
                    "Content-Type: application/json",
                    "Authorization: Basic $auth",
                    "Accept: application/json",
                ),
                'content'          => $json_rpc_request,
                'timeout'          => $this->timeout,
                'ignore_errors'    => true,
                'follow_location'  => 1,
            ),
        ) );

        $response = @file_get_contents( $this->url, false, $context );

        if ( $response === false ) {
            $error = error_get_last();
            throw new \RuntimeException( 'HTTP request failed: ' . ( $error['message'] ?? 'Unknown error' ) );
        }

        // Check for auth errors (401)
        if ( isset( $http_response_header[0] ) && strpos( $http_response_header[0], '401' ) !== false ) {
            throw new \RuntimeException( 'Authentication failed — check user and application password' );
        }

        return $response;
    }
}
```

- [ ] **Step 2: Create src/Transport.php**

```php
<?php
namespace AgentShellMCPDaemon;

class Transport {
    public function read_line() : ?string {
        $line = fgets( STDIN );
        if ( $line === false ) { return null; }
        return $line;
    }

    public function write_line( string $line ) {
        fwrite( STDOUT, $line . "\n" );
        fflush( STDOUT );
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add src/Client.php src/Transport.php
git commit -m "feat: HTTP client and stdio transport"
```

---

### Task 11: Daemon End-to-End Test

- [ ] **Step 1: Create config file**

```bash
echo '{"url":"http://localhost:10003/wp-json/agentshell-mcp/v1/mcp","user":"your_user","pass":"XXXX XXXX XXXX XXXX","timeout":30}' > ~/.agentshell-mcp.json
chmod 0600 ~/.agentshell-mcp.json
```

- [ ] **Step 2: Run daemon with a test initialize**

```bash
echo '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2025-03-26"},"id":1}' | php daemon.php --config ~/.agentshell-mcp.json 2>/dev/null
```

Expected: JSON-RPC response with serverInfo.name = "agentshell-mcp"

- [ ] **Step 3: Test tools/list through the daemon**

```bash
(echo '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2025-03-26"},"id":1}';
 echo '{"jsonrpc":"2.0","method":"tools/list","params":{},"id":2}';
 echo '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"agentshell_get_site_info","arguments":{}},"id":3}') \
 | php daemon.php --config ~/.agentshell-mcp.json 2>/dev/null
```

Expected: Three JSON-RPC responses for initialize, tools/list, and agentshell_get_site_info

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -m "feat: complete agentshell-mcp-daemon"
```

---

## Task 12: Write README files

- [ ] **Step 1: Create agentshell-mcp/README.md**

Installation and usage instructions for the WordPress plugin.

- [ ] **Step 2: Create agentshell-mcp-daemon/README.md**

Installation, config file format, and Claude Code MCP config examples.

- [ ] **Step 3: Commit**

```bash
git add README.md && git commit -m "docs: README files for plugin and daemon"
```

---

## Self-Review Checklist

- [ ] All 10 tool classes implemented with correct names and schemas
- [ ] Plugin activates without errors, audit log table created
- [ ] REST endpoint returns correct JSON-RPC responses for initialize, tools/list, tools/call
- [ ] Daemon correctly proxies stdio ↔ HTTP with auth headers
- [ ] No dependency on Easy MCP AI — fully standalone
- [ ] Error codes match spec (CONNECTION_ERROR -32000, AUTH_ERROR -32001, TIMEOUT_ERROR -32002)
- [ ] Sensitive arguments redacted in audit log
- [ ] Installation instructions in both READMEs
