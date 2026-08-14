<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Site Doctor — deterministic configuration validator.
 *
 * Produces machine-readable diagnostics with stable error codes. Hard errors
 * block transactions from committing; warnings are advisory.
 */
class Doctor {
    const ALLOWED_COLORS = array( 'background', 'surface', 'text', 'border', 'accent', 'primary', 'secondary' );
    const ALLOWED_TYPO   = array( 'fontFamily', 'mono', 'baseSize', 'scale' );
    const ALLOWED_LAYOUT = array( 'radius', 'headerHeight', 'footerHeight', 'borderWidth', 'borderStyle' );
    const ALLOWED_ZONES  = array( 'header', 'main', 'footer' );
    const ALLOWED_BLOCKS = array( 'wp_loop', 'wp_core', 'widget', 'json_block', 'wp_widget_area' );
    const ALLOWED_CORE   = array( 'site_title', 'site_tagline', 'site_logo', 'nav_menu', 'search_form' );
    const ALLOWED_LIBS   = array( 'd3', 'mathjs' );
    const SLOTS          = array( 'left', 'center', 'right' );

    /**
     * Run all checks against a config.
     *
     * @param array|null $config Config to check; defaults to stored config.
     * @return array { errors: [], warnings: [] }
     */
    public static function check( $config = null ) {
        if ( null === $config ) {
            $config = get_option( Store::CONFIG_KEY, array() );
        }
        $errors   = array();
        $warnings = array();

        self::check_environment( $errors, $warnings );
        self::check_config( $config, $errors, $warnings );
        self::check_design( $config, $errors, $warnings );
        self::check_zones( $config, $errors, $warnings );
        self::check_widgets( $config, $errors, $warnings );
        self::check_custom_assets( $config, $errors, $warnings );
        self::check_auth( $warnings );

        return array(
            'errors'   => $errors,
            'warnings' => $warnings,
        );
    }

    private static function err( &$errors, $code, $message, $extra = array() ) {
        $errors[] = array_merge( array( 'code' => $code, 'message' => $message ), $extra );
    }

    private static function warn( &$warnings, $code, $message, $extra = array() ) {
        $warnings[] = array_merge( array( 'code' => $code, 'message' => $message ), $extra );
    }

    // ── Environment ──────────────────────────────────────────────

    private static function check_environment( &$errors, &$warnings ) {
        if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
            self::err( $errors, 'PHP_VERSION_UNSUPPORTED', 'PHP ' . PHP_VERSION . ' is below the required 7.4.' );
        }
        global $wp_version;
        if ( version_compare( $wp_version, '6.0', '<' ) ) {
            self::err( $errors, 'WP_VERSION_UNSUPPORTED', 'WordPress ' . $wp_version . ' is below the required 6.0.' );
        }
        if ( ! function_exists( 'agentshell_get_config' ) ) {
            self::err( $errors, 'THEME_INACTIVE', 'AgentShell theme functions not found. Is the AgentShell theme active?' );
        }
    }

    // ── Top-level shape ──────────────────────────────────────────

    private static function check_config( $config, &$errors, &$warnings ) {
        if ( ! is_array( $config ) ) {
            self::err( $errors, 'CONFIG_NOT_ARRAY', 'Config is not an array.' );
            return;
        }
        if ( empty( $config ) ) {
            self::warn( $warnings, 'CONFIG_EMPTY', 'Config is empty — the theme will render with defaults.' );
        }
        if ( isset( $config['layout'] ) && ! is_array( $config['layout'] ) ) {
            self::err( $errors, 'LAYOUT_INVALID', 'layout must be an object.' );
        }
        if ( isset( $config['custom_css'] ) && ! is_string( $config['custom_css'] ) ) {
            self::err( $errors, 'CUSTOM_CSS_INVALID', 'custom_css must be a string.' );
        }
        if ( isset( $config['custom_js'] ) && ! is_string( $config['custom_js'] ) ) {
            self::err( $errors, 'CUSTOM_JS_INVALID', 'custom_js must be a string.' );
        }
        if ( isset( $config['custom_js'] ) && strlen( $config['custom_js'] ) > 10000 ) {
            self::warn( $warnings, 'CUSTOM_JS_LARGE', 'custom_js exceeds 10KB.' );
        }
    }

    // ── Design ───────────────────────────────────────────────────

    private static function check_design( $config, &$errors, &$warnings ) {
        $design = $config['design'] ?? array();
        if ( ! is_array( $design ) ) {
            self::err( $errors, 'DESIGN_INVALID', 'design must be an object.' );
            return;
        }

        foreach ( ( $design['colors'] ?? array() ) as $key => $value ) {
            if ( ! in_array( $key, self::ALLOWED_COLORS, true ) ) {
                self::err( $errors, 'INVALID_COLOR_KEY', "Unknown color key: {$key}", array( 'key' => $key ) );
            }
            if ( is_string( $value ) && ! preg_match( '/^#[0-9a-fA-F]{3,8}$/', $value ) ) {
                self::err( $errors, 'INVALID_COLOR_VALUE', "Invalid color value for {$key}: {$value}", array( 'key' => $key ) );
            }
        }
        foreach ( ( $design['typography'] ?? array() ) as $key => $value ) {
            if ( ! in_array( $key, self::ALLOWED_TYPO, true ) ) {
                self::err( $errors, 'INVALID_TYPO_KEY', "Unknown typography key: {$key}", array( 'key' => $key ) );
            }
        }
        foreach ( ( $design['layout'] ?? array() ) as $key => $value ) {
            if ( ! in_array( $key, self::ALLOWED_LAYOUT, true ) ) {
                self::err( $errors, 'INVALID_LAYOUT_KEY', "Unknown design.layout key: {$key}", array( 'key' => $key ) );
            }
        }
        foreach ( ( $design['custom_css_vars'] ?? array() ) as $key => $value ) {
            if ( strpos( (string) $key, '--' ) !== 0 ) {
                self::err( $errors, 'INVALID_CSS_VAR_NAME', "Custom CSS var keys must start with '--': {$key}", array( 'key' => $key ) );
            }
        }
    }

    // ── Zones & blocks ───────────────────────────────────────────

    private static function check_zones( $config, &$errors, &$warnings ) {
        $zones = $config['zones'] ?? array();
        if ( ! is_array( $zones ) ) {
            self::err( $errors, 'ZONES_INVALID', 'zones must be an array.' );
            return;
        }
        $seen = array();
        foreach ( $zones as $zone ) {
            $id = $zone['id'] ?? null;
            if ( ! $id || ! in_array( $id, self::ALLOWED_ZONES, true ) ) {
                self::err( $errors, 'INVALID_ZONE_ID', 'Unknown or missing zone id: ' . ( $id ?? 'null' ), array( 'zone' => $id ?? '' ) );
                continue;
            }
            if ( isset( $seen[ $id ] ) ) {
                self::err( $errors, 'DUPLICATE_ZONE_ID', "Duplicate zone id: {$id}", array( 'zone' => $id ) );
            }
            $seen[ $id ] = true;

            $blocks = array();
            if ( isset( $zone['slots'] ) && is_array( $zone['slots'] ) ) {
                foreach ( self::SLOTS as $slot ) {
                    $slot_blocks = $zone['slots'][ $slot ] ?? array();
                    if ( ! is_array( $slot_blocks ) ) {
                        self::err( $errors, 'SLOT_INVALID', "Zone {$id} slot {$slot} must be an array.", array( 'zone' => $id ) );
                        continue;
                    }
                    $blocks = array_merge( $blocks, $slot_blocks );
                }
            } elseif ( isset( $zone['composition'] ) ) {
                if ( isset( $zone['composition']['type'] ) ) {
                    $blocks = array( $zone['composition'] ); // legacy flattened block
                } else {
                    $blocks = $zone['composition'];
                }
            }

            foreach ( $blocks as $i => $block ) {
                if ( ! is_array( $block ) || empty( $block['type'] ) ) {
                    self::err( $errors, 'BLOCK_INVALID', "Zone {$id} block #{$i} has no type.", array( 'zone' => $id ) );
                    continue;
                }
                $type = $block['type'];
                if ( ! in_array( $type, self::ALLOWED_BLOCKS, true ) ) {
                    self::err( $errors, 'INVALID_BLOCK_TYPE', "Zone {$id} block #{$i}: unknown type {$type}", array( 'zone' => $id, 'block' => $i ) );
                    continue;
                }
                if ( 'wp_core' === $type ) {
                    if ( empty( $block['id'] ) ) {
                        self::err( $errors, 'WP_CORE_MISSING_ID', "Zone {$id} block #{$i}: wp_core requires an id.", array( 'zone' => $id ) );
                    } elseif ( ! in_array( $block['id'], self::ALLOWED_CORE, true ) ) {
                        self::err( $errors, 'INVALID_WP_CORE_ID', "Zone {$id} block #{$i}: unknown wp_core id {$block['id']}", array( 'zone' => $id, 'core_id' => $block['id'] ) );
                    }
                }
                if ( 'widget' === $type ) {
                    if ( empty( $block['id'] ) ) {
                        self::err( $errors, 'WIDGET_REFERENCE_MISSING_ID', "Zone {$id} block #{$i}: widget block requires an id.", array( 'zone' => $id ) );
                        continue;
                    }
                    $registry = function_exists( 'agentshell_get_widget_registry' ) ? agentshell_get_widget_registry() : array();
                    if ( ! isset( $registry[ $block['id'] ] ) ) {
                        self::err( $errors, 'WIDGET_REFERENCE_UNKNOWN', "Zone {$id} references unknown widget '{$block['id']}'.", array( 'zone' => $id, 'widget' => $block['id'] ) );
                    } elseif ( ( $registry[ $block['id'] ]['status'] ?? 'active' ) === 'disabled' ) {
                        self::warn( $warnings, 'WIDGET_REFERENCE_DISABLED', "Zone {$id} references disabled widget '{$block['id']}' — it renders nothing.", array( 'zone' => $id, 'widget' => $block['id'] ) );
                    }
                }
                if ( 'wp_widget_area' === $type && ! empty( $block['id'] ) && function_exists( 'is_active_sidebar' ) && ! is_active_sidebar( $block['id'] ) ) {
                    self::warn( $warnings, 'WIDGET_AREA_UNREGISTERED', "Widget area '{$block['id']}' is not registered or empty.", array( 'area' => $block['id'] ) );
                }
                if ( 'json_block' === $type && ! empty( $block['content'] ) && is_string( $block['content'] ) ) {
                    if ( preg_match( '/<script\b|<style\b|\son\w+\s*=/i', $block['content'] ) ) {
                        self::err( $errors, 'JSON_BLOCK_UNSAFE', "Zone {$id} block #{$i}: json_block contains script/style tags or inline event handlers, which the renderer strips.", array( 'zone' => $id ) );
                    }
                }
            }
        }
    }

    // ── Widgets ──────────────────────────────────────────────────

    private static function check_widgets( $config, &$errors, &$warnings ) {
        if ( ! function_exists( 'agentshell_get_widget_registry' ) ) {
            return;
        }
        $registry = agentshell_get_widget_registry();
        $config_widgets = $config['widgets'] ?? array();

        $ids = array();
        foreach ( $registry as $id => $widget ) {
            if ( isset( $ids[ $id ] ) ) {
                self::err( $errors, 'WIDGET_DUPLICATE_ID', "Duplicate widget id: {$id}", array( 'widget' => $id ) );
            }
            $ids[ $id ] = true;
            foreach ( (array) ( $widget['libs'] ?? array() ) as $lib ) {
                if ( ! in_array( $lib, self::ALLOWED_LIBS, true ) ) {
                    self::err( $errors, 'WIDGET_UNSUPPORTED_LIB', "Widget {$id} declares unsupported library '{$lib}'. Supported: " . implode( ', ', self::ALLOWED_LIBS ), array( 'widget' => $id, 'lib' => $lib ) );
                }
            }
        }

        // Referenced widget ids (from zones) to find unused widgets.
        $referenced = array();
        foreach ( ( $config['zones'] ?? array() ) as $zone ) {
            $blocks = array();
            foreach ( ( $zone['slots'] ?? array() ) as $slot_blocks ) {
                $blocks = array_merge( $blocks, (array) $slot_blocks );
            }
            $blocks = array_merge( $blocks, (array) ( $zone['composition'] ?? array() ) );
            foreach ( $blocks as $block ) {
                if ( is_array( $block ) && ( $block['type'] ?? '' ) === 'widget' && ! empty( $block['id'] ) ) {
                    $referenced[ $block['id'] ] = true;
                }
            }
        }
        foreach ( $registry as $id => $widget ) {
            if ( ! isset( $referenced[ $id ] ) ) {
                self::warn( $warnings, 'WIDGET_UNUSED', "Widget '{$id}' is registered but not referenced by any zone.", array( 'widget' => $id ) );
            }
        }

        foreach ( (array) $config_widgets as $widget ) {
            if ( empty( $widget['id'] ) ) {
                self::err( $errors, 'WIDGET_MISSING_ID', 'A config widget entry is missing its id.' );
            }
        }
        foreach ( ( $config['widget_overrides'] ?? array() ) as $id => $override ) {
            if ( ! isset( $registry[ $id ] ) ) {
                self::warn( $warnings, 'WIDGET_OVERRIDE_UNKNOWN', "Status override references unknown widget '{$id}'.", array( 'widget' => $id ) );
            }
        }
    }

    // ── Custom assets ────────────────────────────────────────────

    private static function check_custom_assets( $config, &$errors, &$warnings ) {
        // custom_css may legitimately contain @import or keyframes — only flag
        // obvious breakage: unbalanced braces or stray closing tags.
        if ( ! empty( $config['custom_css'] ) && substr_count( $config['custom_css'], '{' ) !== substr_count( $config['custom_css'], '}' ) ) {
            self::warn( $warnings, 'CUSTOM_CSS_UNBALANCED', 'custom_css has unbalanced braces.' );
        }
    }

    // ── Auth configuration ───────────────────────────────────────

    private static function check_auth( &$warnings ) {
        if ( ! defined( 'AGENTSHELL_REST_TOKEN' ) ) {
            self::warn( $warnings, 'AUTH_TOKEN_NOT_CONFIGURED', 'AGENTSHELL_REST_TOKEN is not defined in wp-config.php. Static-token auth is disabled; Application Passwords still work.' );
        }
        $app_pass = false;
        if ( function_exists( 'WP_Application_Passwords' ) || class_exists( '\WP_Application_Passwords' ) ) {
            $app_pass = true;
        }
        if ( ! $app_pass ) {
            self::warn( $warnings, 'APP_PASSWORDS_UNAVAILABLE', 'WP Application Passwords API not found; ensure Application Passwords are enabled.' );
        }
    }
}