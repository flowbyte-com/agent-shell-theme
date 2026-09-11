<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Config history store: revisions, audit entries, snapshots, and theme profiles.
 *
 * All AgentShell configuration mutations converge on wp_options
 * (agentshell_config). The store keeps a bounded, ring-buffered history of
 * those mutations so agents can inspect, diff, and roll back.
 */
class Store {
    const CONFIG_KEY    = 'agentshell_config';
    const REVISIONS_KEY = 'agentshell_revisions';
    const AUDIT_KEY     = 'agentshell_audit_log';
    const SNAPSHOTS_KEY = 'agentshell_snapshots';
    const PROFILES_KEY  = 'agentshell_theme_profiles';

    const MAX_REVISIONS = 25;
    const MAX_AUDIT     = 100;
    const MAX_SNAPSHOTS = 20;

    /**
     * Set true around internal writes that must not self-record history.
     * @var bool
     */
    public static $suppress_history = false;

    /**
     * Optional human label for the next recorded mutation (consumed by the
     * pre_update_option hook that records history).
     * @var string
     */
    public static $operation_label = '';

    // ── Accessors ────────────────────────────────────────────────

    public static function revisions() {
        return get_option( self::REVISIONS_KEY, array() );
    }

    public static function audit_log() {
        return get_option( self::AUDIT_KEY, array() );
    }

    public static function snapshots() {
        return get_option( self::SNAPSHOTS_KEY, array() );
    }

    public static function profiles() {
        return get_option( self::PROFILES_KEY, array() );
    }

    // ── Recording ────────────────────────────────────────────────

    /**
     * Record one config mutation: one audit entry + one revision.
     */
    public static function record_mutation( $before, $after, $operation, $label = '' ) {
        if ( self::$suppress_history ) {
            return;
        }
        $actor  = self::actor();
        $change = self::diff_token_keys( $before, $after );
        $rid    = self::append_revision( $actor, $after, $label ?: $operation );
        self::append_audit( $actor, $operation, $change, $rid );
    }

    /**
     * Current actor: logged-in user login, or 'agent' for machine contexts.
     */
    public static function actor() {
        $user = wp_get_current_user();
        return ( $user instanceof \WP_User && $user->ID ) ? $user->user_login : 'agent';
    }

    private static function append_revision( $actor, $config, $label ) {
        $data = self::revisions();
        if ( ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
            $data['items'] = array();
        }
        $data['counter'] = (int) ( $data['counter'] ?? 0 ) + 1;
        $id   = 'r' . $data['counter'];

        $item = array(
            'id'        => $id,
            'parent'    => ! empty( $data['items'] ) ? $data['items'][0]['id'] : null,
            'timestamp' => time(),
            'actor'     => $actor,
            'label'     => $label,
            'config'    => $config,
        );

        array_unshift( $data['items'], $item );
        if ( count( $data['items'] ) > self::MAX_REVISIONS ) {
            $data['items'] = array_slice( $data['items'], 0, self::MAX_REVISIONS );
        }
        update_option( self::REVISIONS_KEY, $data, false );

        return $id;
    }

    private static function append_audit( $actor, $operation, $changed, $revision_id ) {
        $log   = self::audit_log();
        $entry = array(
            'timestamp'   => time(),
            'actor'       => $actor,
            'operation'   => $operation,
            'changed'     => $changed,
            'revision_id' => $revision_id,
        );
        array_unshift( $log, $entry );
        if ( count( $log ) > self::MAX_AUDIT ) {
            $log = array_slice( $log, 0, self::MAX_AUDIT );
        }
        update_option( self::AUDIT_KEY, $log, false );
    }

    // ── Snapshots ────────────────────────────────────────────────

    public static function create_snapshot( $name, $label = '', $config = null ) {
        if ( null === $config ) {
            $config = get_option( self::CONFIG_KEY, array() );
        }
        $snapshots = self::snapshots();
        $snapshots[ $name ] = array(
            'name'      => $name,
            'label'     => $label,
            'actor'     => self::actor(),
            'timestamp' => time(),
            'config'    => $config,
        );
        // Ring buffer by insertion order (oldest dropped first).
        if ( count( $snapshots ) > self::MAX_SNAPSHOTS ) {
            $snapshots = array_slice( $snapshots, -self::MAX_SNAPSHOTS, null, true );
        }
        update_option( self::SNAPSHOTS_KEY, $snapshots, false );
        return $snapshots[ $name ];
    }

    public static function get_snapshot( $name ) {
        $snapshots = self::snapshots();
        return $snapshots[ $name ] ?? null;
    }

    public static function delete_snapshot( $name ) {
        $snapshots = self::snapshots();
        if ( ! isset( $snapshots[ $name ] ) ) {
            return false;
        }
        unset( $snapshots[ $name ] );
        update_option( self::SNAPSHOTS_KEY, $snapshots, false );
        return true;
    }

    // ── Theme profiles ───────────────────────────────────────────

    public static function save_profile( $name, $design, $description = '' ) {
        $profiles = self::profiles();
        $profiles[ $name ] = array(
            'name'        => $name,
            'description' => $description,
            'actor'       => self::actor(),
            'timestamp'   => time(),
            'design'      => $design,
        );
        update_option( self::PROFILES_KEY, $profiles, false );
        return $profiles[ $name ];
    }

    public static function get_profile( $name ) {
        $profiles = self::profiles();
        return $profiles[ $name ] ?? null;
    }

    // ── Diffing ──────────────────────────────────────────────────

    /**
     * Diff two configs at the CSS-token level.
     *
     * @return array { added: [], removed: [], changed: [ { key, before, after } ], structural: [ { path, before, after } | { path, kind: 'added'|'removed', value } ] }
     */
    public static function diff_config( $before, $after ) {
        $flat_before = self::flatten( is_array( $before ) ? $before : array() );
        $flat_after  = self::flatten( is_array( $after ) ? $after : array() );

        $added   = array();
        $removed = array();
        $changed = array();

        foreach ( $flat_after as $key => $value ) {
            if ( ! array_key_exists( $key, $flat_before ) ) {
                $added[ $key ] = $value;
            } elseif ( $flat_before[ $key ] !== $value ) {
                $changed[] = array(
                    'key'    => $key,
                    'before' => $flat_before[ $key ],
                    'after'  => $value,
                );
            }
        }
        foreach ( $flat_before as $key => $value ) {
            if ( ! array_key_exists( $key, $flat_after ) ) {
                $removed[ $key ] = $value;
            }
        }

        return array(
            'added'      => $added,
            'removed'    => $removed,
            'changed'    => $changed,
            'structural' => self::diff_structural( $before, $after ),
        );
    }

    /**
     * Recursive structural diff of two configs. Captures every non-equal change
     * as a path-prefixed entry, including zone composition, widget additions,
     * custom_css / custom_js edits, and design subtrees — none of which appear
     * in the CSS-token diff at the top of diff_config.
     *
     * The CSS-token diff is the canonical "what will the rendered page change"
     * view; this is the "what changed in the config" view. They overlap by
     * design (design.colors / design.typography / design.layout / design.custom_css_vars
     * are both structurally visible AND token-visible) so the consumer can pick
     * whichever view fits the question. No keys are dropped.
     *
     * Path format: dotted JSON-like keys, e.g. 'zones.main.composition.0.type'.
     * For an array element, the index is a non-negative integer in the path.
     * Bracketed dict keys (a.b with `.` in the literal key) are not used; the
     * agent can `explode('.', $path)` safely for well-formed config keys.
     *
     * @return array<int, array> List of change entries.
     */
    public static function diff_structural( $before, $after ) {
        $diffs = array();
        self::_diff_structural_walk( '', is_array( $before ) ? $before : array(), is_array( $after ) ? $after : array(), $diffs );
        return $diffs;
    }

    private static function _diff_structural_walk( $path, $a, $b, array &$diffs ) {
        $a_is_array = is_array( $a );
        $b_is_array = is_array( $b );
        if ( $a_is_array !== $b_is_array ) {
            // Type change (array ↔ scalar). Treat as one change at this path.
            $diffs[] = $path === ''
                ? array( 'path' => '(root)', 'before' => $a, 'after' => $b )
                : array( 'path' => $path, 'before' => $a, 'after' => $b );
            return;
        }
        if ( ! $a_is_array ) {
            if ( $a !== $b ) {
                $diffs[] = $path === ''
                    ? array( 'path' => '(root)', 'before' => $a, 'after' => $b )
                    : array( 'path' => $path, 'before' => $a, 'after' => $b );
            }
            return;
        }
        $all_keys = array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) );
        sort( $all_keys ); // stable order so the same diffs produce stable output
        foreach ( $all_keys as $k ) {
            $sub_path  = $path === '' ? (string) $k : $path . '.' . $k;
            $a_has     = array_key_exists( $k, $a );
            $b_has     = array_key_exists( $k, $b );
            if ( $a_has && ! $b_has ) {
                $diffs[] = array( 'path' => $sub_path, 'kind' => 'removed', 'value' => $a[ $k ] );
            } elseif ( ! $a_has && $b_has ) {
                $diffs[] = array( 'path' => $sub_path, 'kind' => 'added',   'value' => $b[ $k ] );
            } else {
                self::_diff_structural_walk( $sub_path, $a[ $k ], $b[ $k ], $diffs );
            }
        }
    }

    /**
     * Names of tokens whose value differs between two configs.
     *
     * @return array
     */
    public static function diff_token_keys( $before, $after ) {
        $diff = self::diff_config( $before, $after );
        $keys = array_keys( $diff['added'] );
        $keys = array_merge( $keys, array_keys( $diff['removed'] ) );
        foreach ( $diff['changed'] as $change ) {
            $keys[] = $change['key'];
        }
        return $keys;
    }

    /**
     * Flatten a config to a CSS-token key space, mirroring the theme's
     * agentshell_flatten_config when available (fallback included).
     *
     * @param array $config
     * @return array
     */
    public static function flatten( array $config ) {
        if ( function_exists( 'agentshell_flatten_config' ) ) {
            return agentshell_flatten_config( $config );
        }

        $flat = array();
        array_walk_recursive( $config, function( $value, $key ) use ( &$flat ) {
            if ( strpos( (string) $key, '--' ) === 0 && is_string( $value ) ) {
                $flat[ $key ] = $value;
            }
        } );
        return $flat;
    }
}