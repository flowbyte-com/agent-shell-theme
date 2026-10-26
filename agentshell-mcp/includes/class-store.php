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
     * The deletion and its audit record are one operation. See
     * delete_history() for what that means and what it guarantees.
     *
     * @param array  $names
     * @param string $reason
     * @return array { deleted: string[], missing: string[], outcome: string, audit_recorded: bool, audit_state: string, atomicity: array }
     */
    public static function delete_snapshots( array $names, $reason = '' ) {
        $names = self::normalise_snapshot_names( $names );
        if ( empty( $names ) ) {
            throw new \InvalidArgumentException( 'No snapshot names were supplied.' );
        }
        return self::delete_history( 'snapshots', $names, $reason );
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
     * @return array { deleted: string[], missing: string[], tombstones: array, outcome: string, audit_recorded: bool, audit_state: string, atomicity: array }
     */
    public static function delete_revisions( array $ids, $reason = '' ) {
        $ids = self::normalise_revision_ids( $ids );
        if ( empty( $ids ) ) {
            throw new \InvalidArgumentException( 'No revision ids were supplied.' );
        }
        return self::delete_history( 'revisions', $ids, $reason );
    }

    // ── Deleting history, and its audit record, as one operation ──────

    /**
     * Delete history records and record the deletion, as one operation.
     *
     * The problem this solves: deleting the records and writing the audit row
     * are two writes. If the first succeeds and the second fails, the history
     * is gone and nobody can find out who removed it — and the caller, told
     * the write failed, is left believing the records are still there.
     *
     * The mechanism is two layers, and the second one is what makes the
     * guarantee hold when the first is unavailable:
     *
     * 1. **One transaction.** The audit intent, the history write and the
     *    audit confirmation run inside a single SQL transaction over the
     *    options table (`Content_Write_Guard::run_on()`). Either all three
     *    land or none does.
     * 2. **The intent comes first.** The audit row is written *before* the
     *    history, and it is finalised after. So even where the options table
     *    cannot be transactional — an old MyISAM table, a unit harness with
     *    no database — a failure to write the audit record stops the
     *    deletion before it starts. There is no ordering in which the history
     *    disappears and no audit row exists to say so.
     *
     * A failure between the deletion and the audit confirmation leaves the
     * deletion real and the audit row marked `pending`. That is reconciled,
     * not hidden: the server re-reads both the history and the audit log and
     * reports what it found.
     *
     * The outcome is one of:
     *
     *   - `deleted`    the records are gone and the audit log says so;
     *   - `unchanged`  nothing matched — the records were already gone;
     *   - `failed`     nothing was deleted, and the reason is known;
     *   - `uncertain`  the write may or may not have landed, and the server
     *                  says so rather than guessing.
     *
     * @param string $kind   'snapshots' or 'revisions'.
     * @param array  $ids    The names or ids the caller asked to delete.
     * @param string $reason
     * @return array
     */
    private static function delete_history( $kind, array $ids, $reason ) {
        $history_key     = ( 'snapshots' === $kind ) ? self::SNAPSHOTS_KEY : self::REVISIONS_KEY;
        $audit_operation = ( 'snapshots' === $kind ) ? 'snapshot_deleted' : 'revision_deleted';
        $id_field        = ( 'snapshots' === $kind ) ? 'names' : 'ids';
        $delete_id       = 'del_' . bin2hex( random_bytes( 8 ) );
        $actor           = self::actor();
        $last_error      = 'The history changed while the deletion was being applied.';

        for ( $attempt = 0; $attempt < 3; $attempt++ ) {
            $history_current = self::read_history_option( $history_key );
            $plan            = self::plan_history_deletion( $kind, $history_current, $ids, $reason );

            if ( empty( $plan['deleted'] ) ) {
                // Nothing matched. There is nothing to delete and nothing to
                // record: a deletion that removed nothing did not happen.
                return self::history_result(
                    'unchanged',
                    array(),
                    $plan['missing'],
                    'none',
                    false,
                    array(),
                    $reason,
                    'Those ' . ( 'snapshots' === $kind ? 'snapshots are' : 'revisions are' ) . ' already gone.'
                );
            }

            $audit_current = self::audit_log();
            $pending_row   = self::history_audit_row( $audit_operation, $actor, $plan, $reason, $delete_id, 'pending', $id_field );
            $final_row     = $pending_row;
            $final_row['changed']['state'] = 'deleted';
            $audit_pending = self::audit_with_row( $audit_current, $pending_row );
            $audit_final   = self::audit_with_row( $audit_current, $final_row );

            $result = self::attempt_history_deletion(
                $history_key,
                $history_current,
                $plan,
                $audit_current,
                $audit_pending,
                $audit_final,
                $delete_id,
                $kind
            );

            if ( 'conflict' === ( $result['outcome'] ?? '' ) ) {
                // Another writer moved the row under us. Re-read and try
                // again; the record they wrote is part of the new state.
                $last_error = $result['reason'];
                continue;
            }

            return $result;
        }

        return self::history_result(
            'failed',
            array(),
            $ids,
            'none',
            false,
            array(),
            $reason,
            $last_error . ' Nothing was deleted; try again.'
        );
    }

    /**
     * What deleting these ids would do, given the history as it is now.
     *
     * @return array { deleted: string[], missing: string[], next: mixed, labels: array, stones: array }
     */
    private static function plan_history_deletion( $kind, $history_current, array $ids, $reason = '' ) {
        $deleted = array();
        $missing = array();
        $labels  = array();
        $stones  = array();

        if ( 'snapshots' === $kind ) {
            $current = is_array( $history_current ) ? $history_current : array();
            $next    = $current;
            foreach ( $ids as $id ) {
                if ( ! isset( $current[ $id ] ) ) {
                    $missing[] = $id;
                    continue;
                }
                $deleted[] = $id;
                $labels[ $id ] = (string) ( $current[ $id ]['label'] ?? '' );
                unset( $next[ $id ] );
            }
            return array(
                'deleted' => $deleted,
                'missing' => $missing,
                'next'    => $next,
                'labels'  => $labels,
                'stones'  => array(),
            );
        }

        $current = is_array( $history_current ) ? $history_current : array();
        $items   = isset( $current['items'] ) && is_array( $current['items'] ) ? $current['items'] : array();
        $dead    = isset( $current['deleted'] ) && is_array( $current['deleted'] ) ? $current['deleted'] : array();

        $by_id = array();
        foreach ( $items as $item ) {
            if ( is_array( $item ) && isset( $item['id'] ) ) {
                $by_id[ (string) $item['id'] ] = $item;
            }
        }

        $next_items = array();
        foreach ( $items as $item ) {
            if ( is_array( $item ) && isset( $item['id'] ) && in_array( (string) $item['id'], $ids, true ) ) {
                $id         = (string) $item['id'];
                $deleted[]  = $id;
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
            $next_items[] = $item;
        }
        foreach ( $ids as $id ) {
            if ( ! isset( $by_id[ $id ] ) ) {
                $missing[] = $id;
            }
        }
        foreach ( $stones as $id => $stone ) {
            $dead[ $id ] = $stone;
        }

        $next         = $current;
        $next['items']   = array_values( $next_items );
        $next['deleted'] = $dead;

        return array(
            'deleted' => $deleted,
            'missing' => $missing,
            'next'    => $next,
            'labels'  => $labels,
            'stones'  => $stones,
        );
    }

    /**
     * The audit row for a deletion.
     */
    private static function history_audit_row( $operation, $actor, array $plan, $reason, $delete_id, $state, $id_field ) {
        $changed = array(
            'count'     => count( $plan['deleted'] ),
            'labels'    => $plan['labels'],
            'reason'    => (string) $reason,
            'delete_id' => $delete_id,
            'state'     => $state,
        );
        $changed[ $id_field ] = $plan['deleted'];
        if ( ! empty( $plan['stones'] ) ) {
            $changed['tombstones'] = $plan['stones'];
        }
        return array(
            'timestamp'   => time(),
            'actor'       => $actor,
            'operation'   => $operation,
            'changed'     => $changed,
            'revision_id' => null,
        );
    }

    /** The audit log with one row at the front. */
    private static function audit_with_row( array $log, array $row ) {
        array_unshift( $log, $row );
        if ( count( $log ) > self::MAX_AUDIT ) {
            $log = array_slice( $log, 0, self::MAX_AUDIT );
        }
        return $log;
    }

    /**
     * Perform one attempt at the deletion, and say exactly what happened.
     *
     * @return array
     */
    private static function attempt_history_deletion( $history_key, $history_current, array $plan, $audit_current, $audit_pending, $audit_final, $delete_id, $kind ) {
        global $wpdb;
        $tables = ( isset( $wpdb ) && is_object( $wpdb ) && ! empty( $wpdb->options ) ) ? array( $wpdb->options ) : array();

        // Test seams: a failure injected at each of the three writes, so the
        // guarantee is proved at the points where it can be broken.
        $fail_audit   = ! empty( $GLOBALS['_agentshell_test_history_audit_fail'] );
        $fail_history = ! empty( $GLOBALS['_agentshell_test_history_cas_fail'] );
        $fail_final   = ! empty( $GLOBALS['_agentshell_test_history_finalise_fail'] );

        try {
            $write = function () use ( $history_key, $history_current, $plan, $audit_current, $audit_pending, $audit_final, $fail_audit, $fail_history, $fail_final ) {
                // 1. The intent, before anything is removed.
                if ( $fail_audit || ! self::cas_option( self::AUDIT_KEY, $audit_current, $audit_pending, false ) ) {
                    throw new \RuntimeException( 'history_conflict:audit' );
                }
                // 2. The deletion itself.
                if ( $fail_history || ! self::cas_option( $history_key, $history_current, $plan['next'], false ) ) {
                    throw new \RuntimeException( 'history_conflict:history' );
                }
                // 3. The confirmation that the deletion happened.
                if ( $fail_final || ! self::cas_option( self::AUDIT_KEY, $audit_pending, $audit_final, false ) ) {
                    throw new \RuntimeException( 'history_conflict:finalise' );
                }
                return true;
            };

            if ( class_exists( '\AgentShell_MCP\Content_Write_Guard' ) ) {
                Content_Write_Guard::run_on( $tables, $write );
            } else {
                // The guard is part of this plugin, so this is a belt-and-braces
                // path rather than an expected one. The write-ahead ordering
                // below still holds: the audit record is written first.
                $write();
            }

            self::flush_option_cache( $history_key );
            self::flush_option_cache( self::AUDIT_KEY );

            return self::history_result(
                'deleted',
                $plan['deleted'],
                $plan['missing'],
                'deleted',
                true,
                $plan['stones'],
                '',
                '',
                self::history_atomicity_report( $tables )
            );
        } catch ( \Throwable $e ) {
            self::flush_option_cache( $history_key );
            self::flush_option_cache( self::AUDIT_KEY );

            if ( 0 === strpos( (string) $e->getMessage(), 'history_conflict' ) ) {
                // Another writer moved a row, or a seam refused a write. The
                // records may or may not have moved; only a re-read can say.
                $state = self::reconcile_history_deletion( $history_key, $kind, $plan, $delete_id );
                if ( 'gone' === $state['records'] ) {
                    // The records are gone. Was it us? The audit row says so
                    // if it was written; if it is still `pending`, the
                    // deletion happened and its confirmation did not.
                    if ( $state['audit_row'] ) {
                        $repaired = self::repair_history_audit_row( $delete_id, $state['audit_row'] );
                        return self::history_result(
                            'deleted',
                            $plan['deleted'],
                            $plan['missing'],
                            $repaired ? 'deleted' : 'pending',
                            (bool) $state['audit_row'],
                            $plan['stones'],
                            '',
                            $repaired ? '' : 'The deletion was recorded, but the audit log could not be updated to say so.',
                            self::history_atomicity_report( $tables )
                        );
                    }
                    if ( $state['other_row'] ) {
                        // Another request deleted the same records, and its
                        // audit row says so. This request removed nothing:
                        // the record is gone, but not because of this call,
                        // and a second deletion row would misdescribe one
                        // removal as two.
                        return self::history_result(
                            'unchanged',
                            array(),
                            $plan['deleted'],
                            'none',
                            false,
                            array(),
                            '',
                            'Another request had already deleted those records.',
                            self::history_atomicity_report( $tables )
                        );
                    }
                    // The records are gone and no deletion of them was
                    // recorded at all. The deletion is real and the record is
                    // missing; write the record now if that is possible.
                    $repaired = self::repair_history_audit_row( $delete_id, null, $plan, $kind );
                    if ( $repaired ) {
                        return self::history_result(
                            'deleted',
                            $plan['deleted'],
                            $plan['missing'],
                            'deleted',
                            true,
                            $plan['stones'],
                            '',
                            'The audit record for this deletion was written after the fact.',
                            self::history_atomicity_report( $tables )
                        );
                    }
                    return self::history_result(
                        'uncertain',
                        $plan['deleted'],
                        $plan['missing'],
                        'none',
                        false,
                        $plan['stones'],
                        '',
                        'The records were removed but the audit record for the deletion could not be written, and whether this deletion caused it could not be established.',
                        self::history_atomicity_report( $tables )
                    );
                }

                // The records are still there: nothing was deleted. The
                // intent row, if it survived, is marked as not completed.
                self::mark_history_audit_failed( $delete_id );
                return array(
                    'outcome' => 'conflict',
                    'reason'  => 'The history changed while the deletion was being applied.',
                );
            }

            // A failure that is not a conflict — a database error, most
            // likely. Reconcile before saying anything about the outcome.
            $state = self::reconcile_history_deletion( $history_key, $kind, $plan, $delete_id );
            if ( 'gone' === $state['records'] ) {
                if ( ! $state['audit_row'] && $state['other_row'] ) {
                    return self::history_result(
                        'unchanged',
                        array(),
                        $plan['deleted'],
                        'none',
                        false,
                        array(),
                        '',
                        'Another request had already deleted those records.',
                        self::history_atomicity_report( $tables )
                    );
                }
                $repaired = self::repair_history_audit_row( $delete_id, $state['audit_row'] );
                return self::history_result(
                    'deleted',
                    $plan['deleted'],
                    $plan['missing'],
                    $repaired ? 'deleted' : ( $state['audit_row'] ? 'pending' : 'none' ),
                    (bool) $state['audit_row'],
                    $plan['stones'],
                    '',
                    $repaired ? '' : 'The deletion happened, but its audit record could not be completed.',
                    self::history_atomicity_report( $tables )
                );
            }
            self::mark_history_audit_failed( $delete_id );
            return self::history_result(
                'failed',
                array(),
                $plan['missing'],
                'none',
                false,
                array(),
                '',
                'Nothing was deleted: ' . $e->getMessage(),
                self::history_atomicity_report( $tables )
            );
        }
    }

    /**
     * Re-read the history and the audit log, and say what is actually there.
     *
     * The read bypasses the object cache, because the question is what is in
     * the database — a cached value would answer the wrong question.
     *
     * @return array { records: 'gone'|'present', audit_row: array|null }
     */
    private static function reconcile_history_deletion( $history_key, $kind, array $plan, $delete_id ) {
        $history = self::read_option_direct( $history_key );
        $present = array();
        if ( 'snapshots' === $kind ) {
            $history = is_array( $history ) ? $history : array();
            foreach ( $plan['deleted'] as $id ) {
                if ( isset( $history[ $id ] ) ) { $present[] = $id; }
            }
        } else {
            $history = is_array( $history ) ? $history : array();
            $items   = isset( $history['items'] ) && is_array( $history['items'] ) ? $history['items'] : array();
            $ids     = array();
            foreach ( $items as $item ) {
                if ( is_array( $item ) && isset( $item['id'] ) ) { $ids[] = (string) $item['id']; }
            }
            foreach ( $plan['deleted'] as $id ) {
                if ( in_array( $id, $ids, true ) ) { $present[] = $id; }
            }
        }

        $log = (array) self::read_option_direct( self::AUDIT_KEY );
        $row = null;
        foreach ( $log as $entry ) {
            if ( is_array( $entry ) && ( $entry['changed']['delete_id'] ?? null ) === $delete_id ) {
                $row = $entry;
                break;
            }
        }

        // Another request may have deleted the same records. Its audit row
        // says so, and the difference matters: the record is gone, but this
        // request did not delete it, and saying otherwise would put a second
        // deletion in the log for one removal.
        $other = null;
        if ( null === $row ) {
            foreach ( $log as $entry ) {
                if ( ! is_array( $entry ) ) { continue; }
                $changed = isset( $entry['changed'] ) && is_array( $entry['changed'] ) ? $entry['changed'] : array();
                $ids = (array) ( $changed['ids'] ?? $changed['names'] ?? array() );
                if ( array_intersect( $ids, $plan['deleted'] ) ) {
                    $other = $entry;
                    break;
                }
            }
        }

        return array(
            'records'   => empty( $present ) ? 'gone' : 'present',
            'audit_row' => $row,
            'other_row' => $other,
        );
    }

    /**
     * Mark a deletion's audit row as not completed.
     *
     * Best effort: the row is there to say what was attempted, and a row that
     * says "pending" when the attempt failed is a lie of a different kind.
     */
    private static function mark_history_audit_failed( $delete_id ) {
        $log = self::read_option_direct( self::AUDIT_KEY );
        if ( ! is_array( $log ) ) { return false; }
        $next = $log;
        $hit  = false;
        foreach ( $next as $index => $entry ) {
            if ( is_array( $entry ) && ( $entry['changed']['delete_id'] ?? null ) === $delete_id ) {
                if ( 'pending' !== ( $entry['changed']['state'] ?? '' ) ) { return true; }
                $next[ $index ]['changed']['state'] = 'failed';
                $next[ $index ]['changed']['note']  = 'The deletion did not complete.';
                $hit = true;
                break;
            }
        }
        if ( ! $hit ) { return false; }
        $ok = self::cas_option( self::AUDIT_KEY, $log, $next, false );
        self::flush_option_cache( self::AUDIT_KEY );
        return $ok;
    }

    /**
     * Complete (or write) the audit record for a deletion that happened.
     *
     * @param string      $delete_id
     * @param array|null  $row     The existing row, when there is one.
     * @param array|null  $plan    The deletion plan, when a row must be written.
     * @param string      $kind
     * @return bool Whether the record now says the deletion happened.
     */
    private static function repair_history_audit_row( $delete_id, $row, $plan = null, $kind = 'revisions' ) {
        $log = self::read_option_direct( self::AUDIT_KEY );
        if ( ! is_array( $log ) ) { $log = array(); }

        if ( is_array( $row ) ) {
            $next = $log;
            foreach ( $next as $index => $entry ) {
                if ( is_array( $entry ) && ( $entry['changed']['delete_id'] ?? null ) === $delete_id ) {
                    $next[ $index ]['changed']['state'] = 'deleted';
                    $next[ $index ]['changed']['note']  = 'The audit record was completed after the deletion.';
                    $ok = self::cas_option( self::AUDIT_KEY, $log, $next, false );
                    self::flush_option_cache( self::AUDIT_KEY );
                    return $ok;
                }
            }
        }

        if ( null === $plan ) { return false; }

        $operation = ( 'snapshots' === $kind ) ? 'snapshot_deleted' : 'revision_deleted';
        $id_field  = ( 'snapshots' === $kind ) ? 'names' : 'ids';
        $new_row   = self::history_audit_row( $operation, self::actor(), $plan, '', $delete_id, 'deleted', $id_field );
        $new_row['changed']['note'] = 'The audit record for this deletion was written after the deletion.';
        $next = self::audit_with_row( $log, $new_row );
        $ok   = self::cas_option( self::AUDIT_KEY, $log, $next, false );
        self::flush_option_cache( self::AUDIT_KEY );
        return $ok;
    }

    /** The result shape every history deletion answers with. */
    private static function history_result( $outcome, array $deleted, array $missing, $audit_state, $audit_recorded, array $stones, $reason, $note, $atomicity = null ) {
        return array(
            'deleted'        => array_values( $deleted ),
            'missing'        => array_values( $missing ),
            'tombstones'     => $stones,
            'outcome'        => $outcome,
            'audit_recorded' => (bool) $audit_recorded,
            'audit_state'    => $audit_state,
            'reason'         => (string) $reason,
            'note'           => (string) $note,
            'atomicity'      => is_array( $atomicity ) ? $atomicity : array(
                'database_transaction' => false,
                'engine'                => null,
                'on_failure'            => 'not rolled back — the options table could not be opened as a transaction, so a partial write is possible',
                'not_covered'           => 'the audit record is written before the deletion, so a failure to write it stops the deletion rather than losing it',
            ),
        );
    }

    /**
     * What the caller may believe about the write that just ran.
     *
     * The guard's own report when it is available; otherwise the honest
     * answer for an unprotected write, which the write-ahead ordering still
     * makes safe in the only way that matters — a failure to write the audit
     * record stops the deletion instead of losing it.
     */
    private static function history_atomicity_report( array $tables ) {
        if ( class_exists( '\AgentShell_MCP\Content_Write_Guard' ) ) {
            return Content_Write_Guard::report_for( $tables );
        }
        return array(
            'database_transaction' => false,
            'engine'                => null,
            'on_failure'            => 'not rolled back — the options table could not be opened as a transaction, so a partial write is possible',
            'not_covered'           => 'the audit record is written before the deletion, so a failure to write it stops the deletion rather than losing it',
        );
    }

    /** The history option as it is now. */
    private static function read_history_option( $key ) {
        $value = get_option( $key, array() );
        return is_array( $value ) ? $value : array();
    }

    /**
     * Read an option from the database, bypassing the object cache.
     *
     * Used only when reconciling: the question is what the database holds,
     * and a cached answer would be a stale one.
     */
    private static function read_option_direct( $key ) {
        global $wpdb;
        if ( isset( $wpdb ) && is_object( $wpdb ) && ! empty( $wpdb->options ) && method_exists( $wpdb, 'get_var' ) ) {
            $raw = $wpdb->get_var(
                $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key )
            );
            if ( null === $raw ) { return null; }
            return function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $raw ) : $raw;
        }
        return get_option( $key, null );
    }

    /** Drop the cached copies of one option. */
    private static function flush_option_cache( $key ) {
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( $key, 'options' );
            wp_cache_delete( 'notoptions', 'options' );
            wp_cache_delete( 'alloptions', 'options' );
        }
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
     * The cache flush is optional because a write inside a transaction must
     * not be published to the object cache before it commits: another request
     * would cache the uncommitted value and the rollback would leave the
     * cache holding something the database never had. The caller flushes once
     * the transaction is over.
     *
     * @param string $key
     * @param mixed  $expected
     * @param mixed  $next
     * @param bool   $flush_cache
     * @return bool
     */
    private static function cas_option( $key, $expected, $next, $flush_cache = true ) {
        global $wpdb;
        if ( ! isset( $wpdb ) || empty( $wpdb->options ) || ! function_exists( 'maybe_serialize' ) ) {
            $ok = (bool) update_option( $key, $next, false );
            if ( $flush_cache ) {
                self::flush_option_cache( $key );
            }
            return $ok;
        }

        // A row that does not exist yet cannot be guarded by a comparison
        // with its current bytes: there are none. The write is an INSERT
        // then, and `add_option()` refuses to overwrite a row another
        // writer created in the meantime — the same "exactly one writer
        // wins" rule, expressed for a row that has no value to compare.
        if ( ! self::option_row_exists( $key ) ) {
            $ok = (bool) add_option( $key, $next, '', false );
            if ( $flush_cache ) {
                self::flush_option_cache( $key );
            }
            return $ok;
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
        if ( $flush_cache ) {
            self::flush_option_cache( $key );
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

    /**
     * Does the options table hold a row for this key?
     *
     * Read through the database rather than the object cache: the question
     * is what the row store holds, and a cached answer would be a stale one.
     */
    private static function option_row_exists( $key ) {
        global $wpdb;
        if ( ! isset( $wpdb ) || empty( $wpdb->options ) || ! method_exists( $wpdb, 'get_var' ) ) {
            return null !== get_option( $key, null );
        }
        $raw = $wpdb->get_var(
            $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key )
        );
        return null !== $raw;
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