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
     * The largest single history-deletion request.
     *
     * Deletion is an administrator action, not a bulk migration. A bound keeps
     * one mistyped request from rewriting the whole bounded history, and it is
     * the same bound the REST route validates.
     */
    const MAX_HISTORY_DELETE = 100;

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
        $actor       = self::actor();
        $change      = self::diff_token_keys( $before, $after );
        $description = self::describe_change( $before, $after );

        // A generic operation label ("transaction: configurator save",
        // "config_update") tells a reader nothing about which record this is.
        // When the diff gives a reliable principal change, the revision carries
        // that instead, and the original operation is retained alongside it.
        // A label the caller wrote deliberately is never replaced.
        $revision_label = $label ?: $operation;
        if ( '' !== $description && self::is_generic_label( $revision_label ) ) {
            $revision_label = $description;
        }

        $rid = self::append_revision( $actor, $after, $revision_label, $description, $operation );
        self::append_audit( $actor, $operation, $change, $rid );
    }

    private static function is_generic_label( $label ) {
        $label = (string) $label;
        if ( '' === $label ) {
            return true;
        }
        if ( 'config_update' === $label || 'transaction_commit' === $label ) {
            return true;
        }
        return (bool) preg_match( '/^transaction:\s*configurator(?:\s+(?:save|preview))?$/', $label );
    }

    /**
     * Record something the system did that is not a config mutation.
     *
     * append_audit() is private because it is a step inside record_mutation(),
     * and every step there needs a before/after pair to be meaningful. Some
     * events still belong in the same ring — the audit log is how an operator
     * finds out what happened when a session died — without such a pair.
     * Reclaimed transactions are the case that matters: nothing about the site
     * changed, so no revision exists, and an abandoned transaction discarded
     * with no trace would leave an operator with a wedge and no explanation.
     */
    public static function record_event( $actor, $operation, array $changed = array(), $revision_id = null ) {
        // Test seam: a conditional refusal keyed on operation name. The
        // lifecycle tests use this to verify that a failing audit write
        // inside the guarded SQL transaction prevents the cas_delete_row
        // from running, and that the option row is preserved for the
        // operator to retry.
        if ( ! empty( $GLOBALS['_agentshell_test_audit_fail_for'] )
            && $operation === $GLOBALS['_agentshell_test_audit_fail_for'] ) {
            throw new \RuntimeException(
                'audit write refused by test seam for operation: ' . $operation
            );
        }
        self::append_audit( $actor, $operation, $changed, $revision_id );
    }

    /**
     * Current actor: logged-in user login, or 'agent' for machine contexts.
     */
    public static function actor() {
        $user = wp_get_current_user();
        // Documented conflation (S-6, Stage 2c M5 audit): the
        // `actor` returned for any non-logged-in caller is the
        // literal string 'agent'. Multiple REST-token callers (each
        // without a logged-in user) share this single audit
        // identity. The audit log records one 'agent' row per
        // tool call regardless of which REST token authorised it;
        // the token's id is recorded separately on each audit row
        // (see the `token_id` field) and the actor field is
        // reserved for the agent's user_login when logged in. If
        // an operator needs to distinguish token callers, the
        // token_id column on the audit row is the place; the
        // actor field is the agent's identity, not the caller's.
        return ( $user instanceof \WP_User && $user->ID ) ? $user->user_login : 'agent';
    }

    private static function append_revision( $actor, $config, $label, $description = '', $operation = '' ) {
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
        if ( '' !== (string) $description ) {
            $item['description'] = (string) $description;
        }
        if ( '' !== (string) $operation ) {
            $item['operation'] = (string) $operation;
        }

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
        $result = self::delete_snapshots( array( $name ) );
        return in_array( (string) $name, $result['deleted'], true );
    }

    /**
     * Delete one or more snapshots, recording who asked and what went.
     *
     * A snapshot is a named copy; deleting it changes no committed
     * configuration and no revision. The audit row is the durable record that
     * the copy existed and who removed it, because the snapshot itself no
     * longer can be.
     *
     * @param array  $names
     * @param string $reason
     * @return array { deleted: string[], missing: string[], audit_recorded: bool }
     */
    public static function delete_snapshots( array $names, $reason = '' ) {
        $names = self::normalise_snapshot_names( $names );
        if ( empty( $names ) ) {
            throw new \InvalidArgumentException( 'No snapshot names were supplied.' );
        }

        for ( $attempt = 0; $attempt < 3; $attempt++ ) {
            $snapshots = self::snapshots();
            if ( ! is_array( $snapshots ) ) {
                $snapshots = array();
            }
            $deleted = array();
            $missing = array();
            $labels  = array();
            $next    = $snapshots;

            foreach ( $names as $name ) {
                if ( isset( $snapshots[ $name ] ) ) {
                    $deleted[] = $name;
                    $labels[ $name ] = (string) ( $snapshots[ $name ]['label'] ?? '' );
                    unset( $next[ $name ] );
                } else {
                    $missing[] = $name;
                }
            }
            if ( empty( $deleted ) ) {
                return array( 'deleted' => array(), 'missing' => $missing, 'audit_recorded' => false );
            }
            if ( ! self::cas_option( self::SNAPSHOTS_KEY, $snapshots, $next ) ) {
                continue;
            }

            self::record_event(
                self::actor(),
                'snapshot_deleted',
                array(
                    'names'   => $deleted,
                    'labels'  => $labels,
                    'count'   => count( $deleted ),
                    'reason'  => (string) $reason,
                )
            );
            return array( 'deleted' => $deleted, 'missing' => $missing, 'audit_recorded' => true );
        }

        throw new \InvalidArgumentException(
            'Configuration history changed while the snapshots were being deleted. Nothing was deleted; retry.'
        );
    }

    private static function normalise_snapshot_names( array $names ) {
        $out = array();
        foreach ( $names as $name ) {
            if ( ! is_string( $name ) ) {
                throw new \InvalidArgumentException( 'Snapshot names must be strings.' );
            }
            $clean = sanitize_key( $name );
            // A name that does not survive sanitisation unchanged would be a
            // request for a different record than the caller named.
            if ( '' === $clean || $clean !== $name ) {
                throw new \InvalidArgumentException( 'Invalid snapshot name: ' . $name );
            }
            $out[ $clean ] = true;
        }
        $out = array_keys( $out );
        if ( count( $out ) > self::MAX_HISTORY_DELETE ) {
            throw new \InvalidArgumentException(
                'At most ' . self::MAX_HISTORY_DELETE . ' snapshots may be deleted in one request.'
            );
        }
        return $out;
    }

    /**
     * Delete revisions, preserving the lineage of the ones that remain.
     *
     * The `parent` pointer on every remaining revision is left exactly as it
     * was recorded. Deleting a revision therefore does not silently rewrite a
     * child to point at a grandparent — that would falsify the history — and
     * it does not delete the audit rows that mention the removed id. Instead
     * the id is recorded in a tombstone map, the list reports
     * `parent_deleted`, and a new audit row names who removed what and why.
     *
     * @param array  $ids
     * @param string $reason
     * @return array { deleted: string[], missing: string[], tombstones: array, audit_recorded: bool }
     */
    public static function delete_revisions( array $ids, $reason = '' ) {
        $ids = self::normalise_revision_ids( $ids );
        if ( empty( $ids ) ) {
            throw new \InvalidArgumentException( 'No revision ids were supplied.' );
        }

        for ( $attempt = 0; $attempt < 3; $attempt++ ) {
            $data  = self::revisions();
            $items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
            $dead  = isset( $data['deleted'] ) && is_array( $data['deleted'] ) ? $data['deleted'] : array();

            $by_id  = array();
            foreach ( $items as $item ) {
                if ( is_array( $item ) && isset( $item['id'] ) ) {
                    $by_id[ (string) $item['id'] ] = $item;
                }
            }

            $deleted = array();
            $missing = array();
            $labels  = array();
            $stones  = array();
            $next    = array();

            foreach ( $items as $item ) {
                if ( is_array( $item ) && isset( $item['id'] ) && in_array( (string) $item['id'], $ids, true ) ) {
                    $id        = (string) $item['id'];
                    $deleted[] = $id;
                    $labels[ $id ] = (string) ( $item['label'] ?? '' );
                    $stones[ $id ] = array(
                        'at'        => time(),
                        'actor'     => self::actor(),
                        'label'     => (string) ( $item['label'] ?? '' ),
                        'timestamp' => (int) ( $item['timestamp'] ?? 0 ),
                        'parent'    => $item['parent'] ?? null,
                        'reason'    => (string) $reason,
                    );
                    continue;
                }
                $next[] = $item;
            }
            foreach ( $ids as $id ) {
                if ( ! isset( $by_id[ $id ] ) ) {
                    $missing[] = $id;
                }
            }

            if ( empty( $deleted ) ) {
                return array(
                    'deleted'        => array(),
                    'missing'        => $missing,
                    'tombstones'     => array(),
                    'audit_recorded' => false,
                );
            }

            foreach ( $stones as $id => $stone ) {
                $dead[ $id ] = $stone;
            }

            $row = $data;
            $row['items']   = array_values( $next );
            $row['deleted'] = $dead;

            if ( ! self::cas_option( self::REVISIONS_KEY, $data, $row ) ) {
                continue;
            }

            self::record_event(
                self::actor(),
                'revision_deleted',
                array(
                    'ids'      => $deleted,
                    'labels'   => $labels,
                    'count'    => count( $deleted ),
                    'reason'   => (string) $reason,
                    // The tombstones are the record of what no longer exists;
                    // the audit row is what survives the 25-revision ring.
                    'tombstones' => $stones,
                )
            );
            return array(
                'deleted'        => $deleted,
                'missing'        => $missing,
                'tombstones'     => $stones,
                'audit_recorded' => true,
            );
        }

        throw new \InvalidArgumentException(
            'Configuration history changed while the revisions were being deleted. Nothing was deleted; retry.'
        );
    }

    private static function normalise_revision_ids( array $ids ) {
        $out = array();
        foreach ( $ids as $id ) {
            if ( ! is_string( $id ) ) {
                throw new \InvalidArgumentException( 'Revision ids must be strings.' );
            }
            $id = trim( $id );
            if ( ! preg_match( '/^r[0-9]+$/', $id ) ) {
                throw new \InvalidArgumentException( 'Invalid revision id: ' . $id );
            }
            $out[ $id ] = true;
        }
        $out = array_keys( $out );
        if ( count( $out ) > self::MAX_HISTORY_DELETE ) {
            throw new \InvalidArgumentException(
                'At most ' . self::MAX_HISTORY_DELETE . ' revisions may be deleted in one request.'
            );
        }
        return $out;
    }

    /** Tombstoned revision ids, newest deletion last. */
    public static function revision_tombstones() {
        $data = self::revisions();
        return isset( $data['deleted'] ) && is_array( $data['deleted'] ) ? $data['deleted'] : array();
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

    // ── Describing a change ──────────────────────────────────────

    /**
     * A short, reliable description of what changed between two configs.
     *
     * This is deliberately conservative. It names a single changed token
     * ("Updated accent colour"), a zone whose blocks changed ("Changed footer
     * layout" or "Changed footer navigation"), or one of the two custom assets.
     * Anything it cannot identify with confidence returns the empty string, so
     * the caller keeps the label it already had rather than inventing one.
     *
     * @param array $before
     * @param array $after
     * @return string
     */
    public static function describe_change( $before, $after ) {
        $before = is_array( $before ) ? $before : array();
        $after  = is_array( $after )  ? $after  : array();

        $diff       = self::diff_config( $before, $after );
        $token_keys = array();
        foreach ( array_keys( $diff['added'] ) as $key ) { $token_keys[ $key ] = true; }
        foreach ( array_keys( $diff['removed'] ) as $key ) { $token_keys[ $key ] = true; }
        foreach ( $diff['changed'] as $change ) {
            if ( isset( $change['key'] ) ) { $token_keys[ $change['key'] ] = true; }
        }
        $token_keys = array_keys( $token_keys );

        $css_changed = (string) ( $before['custom_css'] ?? '' ) !== (string) ( $after['custom_css'] ?? '' );
        $js_changed  = (string) ( $before['custom_js'] ?? '' ) !== (string) ( $after['custom_js'] ?? '' );

        $zone_changes = self::describe_zone_changes( $before, $after );

        $parts = array();
        if ( $css_changed ) {
            $parts[] = 'Updated custom CSS';
        }
        if ( $js_changed ) {
            $parts[] = 'Updated custom JavaScript';
        }
        if ( ! empty( $token_keys ) ) {
            if ( 1 === count( $token_keys ) ) {
                $parts[] = 'Updated ' . self::token_description( $token_keys[0] );
            } else {
                $parts[] = 'Updated ' . count( $token_keys ) . ' design settings';
            }
        }
        if ( ! empty( $zone_changes ) ) {
            if ( 1 === count( $zone_changes ) ) {
                $zone = $zone_changes[0];
                $parts[] = 'Changed ' . $zone['label'] . ' ' . ( $zone['navigation'] ? 'navigation' : 'layout' );
            } else {
                $parts[] = 'Changed ' . count( $zone_changes ) . ' zone layouts';
            }
        }

        if ( empty( $parts ) ) {
            return '';
        }

        $sentence = array_shift( $parts );
        foreach ( $parts as $part ) {
            $sentence .= ' and ' . lcfirst( $part );
        }
        return $sentence;
    }

    /**
     * The description a stored revision should display.
     *
     * New revisions carry `description`. Older ones do not, so the description
     * is derived from the stored config and its parent's stored config when
     * both are still present. When the parent has been deleted, the revision's
     * own label is the only truthful answer.
     */
    public static function describe_revision( $item, array $items ) {
        if ( ! is_array( $item ) ) { return ''; }
        if ( ! empty( $item['description'] ) ) {
            return (string) $item['description'];
        }
        $parent = $item['parent'] ?? null;
        if ( null === $parent ) { return ''; }
        foreach ( $items as $candidate ) {
            if ( is_array( $candidate ) && ( $candidate['id'] ?? null ) === $parent && isset( $candidate['config'] ) ) {
                return self::describe_change( $candidate['config'], $item['config'] ?? array() );
            }
        }
        return '';
    }

    private static function describe_zone_changes( $before, $after ) {
        $before_zones = self::zone_map( $before );
        $after_zones  = self::zone_map( $after );
        $changed      = array();

        $ids = array_unique( array_merge( array_keys( $before_zones ), array_keys( $after_zones ) ) );
        foreach ( $ids as $id ) {
            $a = $before_zones[ $id ] ?? array();
            $b = $after_zones[ $id ] ?? array();
            if ( self::zone_signature( $a ) === self::zone_signature( $b ) ) {
                continue;
            }
            $changed[] = array(
                'id'         => $id,
                'label'      => (string) ( $b['label'] ?? $a['label'] ?? $id ),
                'navigation' => self::zone_has_navigation( $a ) !== self::zone_has_navigation( $b ),
            );
        }
        return $changed;
    }

    private static function zone_map( $config ) {
        $out = array();
        if ( isset( $config['zones'] ) && is_array( $config['zones'] ) ) {
            foreach ( $config['zones'] as $zone ) {
                if ( is_array( $zone ) && isset( $zone['id'] ) ) {
                    $out[ (string) $zone['id'] ] = $zone;
                }
            }
        }
        return $out;
    }

    private static function zone_signature( $zone ) {
        $slots = isset( $zone['slots'] ) && is_array( $zone['slots'] ) ? $zone['slots'] : null;
        if ( is_array( $slots ) ) {
            ksort( $slots );
            foreach ( $slots as $key => $blocks ) {
                if ( ! is_array( $blocks ) ) { $slots[ $key ] = array(); }
            }
        }
        $composition = isset( $zone['composition'] ) && is_array( $zone['composition'] ) ? $zone['composition'] : null;
        return (string) json_encode( array( 'slots' => $slots, 'composition' => $composition ) );
    }

    private static function zone_has_navigation( $zone ) {
        if ( ! is_array( $zone ) ) { return false; }
        $found = false;
        $walk = function ( $node ) use ( &$walk, &$found ) {
            if ( $found || ! is_array( $node ) ) { return; }
            if ( ( $node['type'] ?? '' ) === 'wp_core' && ( $node['id'] ?? '' ) === 'nav_menu' ) {
                $found = true;
                return;
            }
            foreach ( $node as $child ) {
                if ( is_array( $child ) ) { $walk( $child ); }
            }
        };
        $walk( $zone );
        return $found;
    }

    private static function token_description( $key ) {
        $known = array(
            '--theme-bg'             => 'background colour',
            '--theme-text'           => 'text colour',
            '--theme-accent'         => 'accent colour',
            '--theme-header-bg'      => 'header background colour',
            '--theme-header-text'    => 'header text colour',
            '--theme-header-accent'  => 'header accent colour',
            '--theme-footer-bg'      => 'footer background colour',
            '--theme-footer-text'    => 'footer text colour',
            '--theme-footer-accent'  => 'footer accent colour',
            '--spacing-base'         => 'base spacing',
            '--theme-shadow'         => 'shadow',
            '--theme-radius'         => 'corner radius',
            '--border-width'         => 'border width',
            '--border-style'         => 'border style',
        );
        if ( isset( $known[ $key ] ) ) {
            return $known[ $key ];
        }
        $name = preg_replace( '/^--(?:theme|zone)-?/', '', (string) $key );
        $name = str_replace( array( '-', '_' ), ' ', (string) $name );
        $name = trim( preg_replace( '/\s+/', ' ', $name ) );
        return '' !== $name ? $name : (string) $key;
    }

    // ── Guarded option writes ────────────────────────────────────

    /**
     * Write an option only if it still holds the bytes the caller read.
     *
     * Two administrators deleting history at the same moment must not lose one
     * deletion, and a revision recorded between the read and the write must not
     * be silently dropped. `update_option()` is a bare UPDATE by name, so the
     * same value guard the transaction row uses is applied here. When no
     * `$wpdb` is available (the unit harness), the ordinary option write is the
     * only path and its return value is the verdict.
     *
     * @param string $key
     * @param mixed  $expected
     * @param mixed  $next
     * @return bool
     */
    private static function cas_option( $key, $expected, $next ) {
        global $wpdb;
        if ( ! isset( $wpdb ) || empty( $wpdb->options ) || ! function_exists( 'maybe_serialize' ) ) {
            return (bool) update_option( $key, $next, false );
        }
        $expected_bytes = maybe_serialize( $expected );
        $next_bytes     = maybe_serialize( $next );
        $affected       = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                $next_bytes,
                $key,
                $expected_bytes
            )
        );
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( $key, 'options' );
            wp_cache_delete( 'notoptions', 'options' );
            wp_cache_delete( 'alloptions', 'options' );
        }
        if ( 1 === (int) $affected ) {
            return true;
        }
        if ( 0 === (int) $affected && $next_bytes === $expected_bytes ) {
            $current = $wpdb->get_var(
                $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key )
            );
            return null !== $current && (string) $current === (string) $expected_bytes;
        }
        return false;
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