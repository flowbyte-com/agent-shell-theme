<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Site identity and reading settings — a bounded, transactional subset of
 * WordPress's own options.
 *
 * Why this exists
 * ---------------
 * A site cannot be finished without a title, a tagline, a front page and a
 * posts page, and none of them could be set through MCP: the tools were
 * there for the *theme's* configuration and for content, but the ordinary
 * WordPress settings a reader sees were only reachable by hand. An agent
 * building a site had to stop and ask a human to click through wp-admin.
 *
 * Why it is bounded
 * -----------------
 * WordPress's options table holds the site URL, the administrator email,
 * the registration policy, the active plugins, the role assignments and
 * the salts. A tool that could set an arbitrary option would be an
 * unauthenticated-superuser API wearing an `agentshell_` prefix: one
 * mis-typed key could move the site to another domain, open registration,
 * or change the administrator's address. So the surface is an explicit
 * allowlist — twelve settings — and every key outside it is refused by
 * name rather than ignored.
 *
 * Why it is transactional
 * -----------------------
 * These settings live in the AgentShell configuration (`site` section),
 * which is the same bucket every other configuration change uses. That
 * buys the whole transaction contract for free: the change is staged, it
 * appears in the transaction preview, `agentshell_rollback_transaction`
 * discards it, and the snapshot and revision machinery covers it. A write
 * outside a transaction is immediate, exactly like every other
 * configuration write.
 *
 * Why the configuration projects onto WordPress
 * ---------------------------------------------
 * WordPress reads its site title from `wp_options`, not from a theme
 * configuration, so the configuration has to be projected onto the real
 * options or it would be a note nobody reads. The projection applies only
 * the settings whose values *changed* in the write that triggered it, and
 * it runs inside the same guarded SQL transaction as the configuration
 * write itself — so a configuration commit either applies the settings or
 * applies neither.
 *
 * The projection is deliberately one-directional and minimal: a human who
 * changes the site title in wp-admin keeps their change until an agent
 * sets the title again. The configuration does not fight the admin over
 * every write.
 */
class Site_Settings {
    /**
     * The configuration section these settings live in.
     */
    const CONFIG_SECTION = 'site';

    /**
     * The bounded surface: configuration key => option name and type.
     *
     * The types are deliberately small. `page` is a reference to an
     * existing published page; `enum` is one of a fixed set; `int` is
     * bounded; `bool` is a flag; `string` is free text with a length cap.
     *
     * @return array<string,array{option:string,type:string,args:array}>
     */
    public static function schema() {
        return array(
            'title'                  => array( 'option' => 'blogname',               'type' => 'string', 'args' => array( 'max' => 200 ) ),
            'tagline'                => array( 'option' => 'blogdescription',        'type' => 'string', 'args' => array( 'max' => 500 ) ),
            'show_on_front'          => array( 'option' => 'show_on_front',          'type' => 'enum',   'args' => array( 'values' => array( 'posts', 'page' ) ) ),
            'front_page'             => array( 'option' => 'page_on_front',          'type' => 'page',   'args' => array() ),
            'posts_page'             => array( 'option' => 'page_for_posts',         'type' => 'page',   'args' => array() ),
            'posts_per_page'         => array( 'option' => 'posts_per_page',         'type' => 'int',    'args' => array( 'min' => 1, 'max' => 100 ) ),
            'default_comment_status' => array( 'option' => 'default_comment_status', 'type' => 'enum',   'args' => array( 'values' => array( 'open', 'closed' ) ) ),
            'default_ping_status'    => array( 'option' => 'default_ping_status',    'type' => 'enum',   'args' => array( 'values' => array( 'open', 'closed' ) ) ),
            'comment_registration'   => array( 'option' => 'comment_registration',   'type' => 'bool',   'args' => array() ),
            'date_format'            => array( 'option' => 'date_format',            'type' => 'string', 'args' => array( 'max' => 60 ) ),
            'time_format'            => array( 'option' => 'time_format',            'type' => 'string', 'args' => array( 'max' => 60 ) ),
            'start_of_week'          => array( 'option' => 'start_of_week',          'type' => 'int',    'args' => array( 'min' => 0, 'max' => 6 ) ),
        );
    }

    /**
     * The configuration keys this surface accepts.
     *
     * @return array<int,string>
     */
    public static function allowed_keys() {
        return array_keys( self::schema() );
    }

    /**
     * Options that must never be writable through this surface.
     *
     * Listed so the refusal is a *test*, not a habit: the allowlist is
     * short enough to read, and this list is the reason the allowlist
     * stays short.
     *
     * @return array<int,string>
     */
    public static function protected_options() {
        return array(
            'siteurl',
            'home',
            'admin_email',
            'users_can_register',
            'default_role',
            'blog_public',
            'permalink_structure',
            'active_plugins',
            'template',
            'stylesheet',
            'rewrite_rules',
            'users_can_register',
            'default_password_nag',
            'admin_email_lifespan',
            'db_version',
            'cron',
            'recovery_keys',
            'nonce_key',
            'auth_key',
            'secure_auth_key',
            'logged_in_key',
            'logged_in_salt',
        );
    }

    /**
     * Validate an input map against the schema and the current state.
     *
     * Every problem is reported, not just the first: an agent that sets
     * four settings and gets one error back has to guess which of the
     * other three were accepted.
     *
     * @param array $input   The requested settings (partial).
     * @param array $current The settings already in force (for cross-checks).
     * @return array{ok:bool,values:array,errors:array<int,string>}
     */
    public static function validate( array $input, array $current = array() ) {
        $schema  = self::schema();
        $errors  = array();
        $values  = array();

        foreach ( $input as $key => $value ) {
            if ( ! isset( $schema[ $key ] ) ) {
                $errors[] = sprintf(
                    'Unknown setting "%s". This surface accepts: %s.',
                    (string) $key,
                    implode( ', ', self::allowed_keys() )
                );
                continue;
            }
            $rule = $schema[ $key ];

            switch ( $rule['type'] ) {
                case 'string':
                    if ( ! is_string( $value ) ) {
                        $errors[] = sprintf( 'Setting "%s" must be a string.', (string) $key );
                        continue 2;
                    }
                    $max = (int) ( $rule['args']['max'] ?? 0 );
                    if ( $max > 0 && strlen( $value ) > $max ) {
                        $errors[] = sprintf(
                            'Setting "%s" is %d bytes long; the maximum is %d.',
                            (string) $key,
                            strlen( $value ),
                            $max
                        );
                        continue 2;
                    }
                    $values[ $key ] = $value;
                    break;

                case 'enum':
                    if ( ! is_string( $value ) || ! in_array( $value, $rule['args']['values'], true ) ) {
                        $errors[] = sprintf(
                            'Setting "%s" must be one of: %s.',
                            (string) $key,
                            implode( ', ', $rule['args']['values'] )
                        );
                        continue 2;
                    }
                    $values[ $key ] = $value;
                    break;

                case 'bool':
                    $values[ $key ] = (bool) $value;
                    break;

                case 'int':
                    if ( ! is_numeric( $value ) || (int) $value != $value ) { // phpcs:ignore
                        $errors[] = sprintf( 'Setting "%s" must be a whole number.', (string) $key );
                        continue 2;
                    }
                    $int = (int) $value;
                    $min = (int) ( $rule['args']['min'] ?? PHP_INT_MIN );
                    $max = (int) ( $rule['args']['max'] ?? PHP_INT_MAX );
                    if ( $int < $min || $int > $max ) {
                        $errors[] = sprintf(
                            'Setting "%s" must be between %d and %d; %d was given.',
                            (string) $key,
                            $min,
                            $max,
                            $int
                        );
                        continue 2;
                    }
                    $values[ $key ] = $int;
                    break;

                case 'page':
                    // 0 (or null) clears the reference. Anything else must
                    // be an existing, published page: a front page that
                    // does not exist renders the site's index instead, and
                    // the agent would not learn why.
                    if ( null === $value || '' === $value || 0 === $value ) {
                        $values[ $key ] = 0;
                        break;
                    }
                    if ( ! is_numeric( $value ) ) {
                        $errors[] = sprintf( 'Setting "%s" must be a page id.', (string) $key );
                        continue 2;
                    }
                    $page_id = (int) $value;
                    $page    = get_post( $page_id );
                    if ( ! $page || 'page' !== $page->post_type ) {
                        $errors[] = sprintf( 'Setting "%s" refers to #%d, which is not a page.', (string) $key, $page_id );
                        continue 2;
                    }
                    if ( 'publish' !== $page->post_status ) {
                        $errors[] = sprintf(
                            'Setting "%s" refers to #%d, whose status is "%s"; only a published page can be a front page or a posts page.',
                            (string) $key,
                            $page_id,
                            (string) $page->post_status
                        );
                        continue 2;
                    }
                    $values[ $key ] = $page_id;
                    break;

                default:
                    $errors[] = sprintf( 'Setting "%s" has an unknown type.', (string) $key );
            }
        }

        // Cross-checks that only make sense once the individual values
        // are known.
        $effective = array_merge( $current, $values );

        if ( isset( $effective['show_on_front'] ) && 'page' === $effective['show_on_front'] ) {
            $front = (int) ( $effective['front_page'] ?? 0 );
            if ( $front <= 0 ) {
                $errors[] = 'Setting "show_on_front" is "page", so "front_page" must name a published page.';
            }
        }

        $front = (int) ( $effective['front_page'] ?? 0 );
        $posts = (int) ( $effective['posts_page'] ?? 0 );
        if ( $front > 0 && $front === $posts ) {
            $errors[] = 'The front page and the posts page must be different pages; WordPress cannot show one page as both.';
        }

        return array(
            'ok'     => empty( $errors ),
            'values' => $values,
            'errors' => $errors,
        );
    }

    /**
     * The values WordPress currently holds for the bounded surface.
     *
     * @return array<string,mixed>
     */
    public static function live_values() {
        $out = array();
        foreach ( self::schema() as $key => $rule ) {
            $out[ $key ] = get_option( $rule['option'] );
        }
        return $out;
    }

    /**
     * The values the configuration owns, if any.
     *
     * @param array|null $config The configuration to read (effective by default).
     * @return array<string,mixed>
     */
    public static function managed_values( $config = null ) {
        if ( null === $config ) {
            $config = function_exists( 'agentshell_get_config' )
                ? agentshell_get_config( 'effective' )
                : array();
        }
        $section = is_array( $config ) && isset( $config[ self::CONFIG_SECTION ] ) && is_array( $config[ self::CONFIG_SECTION ] )
            ? $config[ self::CONFIG_SECTION ]
            : array();
        $out = array();
        foreach ( self::schema() as $key => $rule ) {
            if ( array_key_exists( $key, $section ) ) {
                $out[ $key ] = $section[ $key ];
            }
        }
        return $out;
    }

    /**
     * The settings as they will be, once the configuration is committed.
     *
     * The configuration's value wins where it has one; WordPress's own
     * value is the fallback. That is what an agent asking "what is the
     * site called?" needs to hear while a transaction is open: the answer
     * it will get after the commit, not the answer the visitor sees now.
     *
     * @return array<string,mixed>
     */
    public static function effective_values() {
        return array_merge( self::live_values(), self::managed_values() );
    }

    /**
     * Stage a set of settings into the configuration.
     *
     * @param array $input The requested settings.
     * @return array{ok:bool,values:array,errors:array}
     */
    public static function stage( array $input ) {
        $current = self::managed_values();
        $check   = self::validate( $input, $current );
        if ( ! $check['ok'] ) {
            return $check;
        }
        return $check;
    }

    /**
     * The same projection, for the case where the configuration option is
     * created rather than updated.
     *
     * @param string $option The option name.
     * @param mixed  $value  The configuration being written.
     * @return array<string,mixed> The settings that were applied.
     */
    public static function project_added( $option, $value ) {
        return self::project( array(), $value );
    }

    /**
     * Project the configuration's site settings onto WordPress's options.
     *
     * Called from the `update_option_agentshell_config` action, with the
     * old and the new configuration. Only the settings whose value
     * actually changed are applied: a human's edit in wp-admin survives
     * until an agent touches that setting again, and an unrelated
     * configuration write does not rewrite the site title behind the
     * administrator's back.
     *
     * @param mixed $old_value The previous configuration.
     * @param mixed $new_value The configuration being written.
     * @return array<string,mixed> The settings that were applied.
     */
    public static function project( $old_value, $new_value ) {
        $old = is_array( $old_value ) && isset( $old_value[ self::CONFIG_SECTION ] ) && is_array( $old_value[ self::CONFIG_SECTION ] )
            ? $old_value[ self::CONFIG_SECTION ]
            : array();
        $new = is_array( $new_value ) && isset( $new_value[ self::CONFIG_SECTION ] ) && is_array( $new_value[ self::CONFIG_SECTION ] )
            ? $new_value[ self::CONFIG_SECTION ]
            : array();

        if ( empty( $new ) ) {
            return array();
        }

        $schema = self::schema();
        $applied = array();

        foreach ( $new as $key => $value ) {
            if ( ! isset( $schema[ $key ] ) ) {
                continue; // not part of the bounded surface
            }
            if ( array_key_exists( $key, $old ) && $old[ $key ] === $value ) {
                continue; // unchanged: nothing to project
            }
            $option = $schema[ $key ]['option'];
            $stored = get_option( $option );
            if ( $stored === $value ) {
                continue; // WordPress already holds this value
            }
            update_option( $option, $value );
            $applied[ $key ] = $value;
        }

        return $applied;
    }
}
