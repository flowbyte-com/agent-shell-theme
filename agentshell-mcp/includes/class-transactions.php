<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Transaction manager: inspect → change → validate → preview → commit → rollback.
 *
 * While a transaction is open, every write to the agentshell_config option is
 * diverted to a staging buffer instead of the database. Committing validates
 * the staged config and applies it in one write; rolling back discards the
 * buffer, leaving the live site untouched. The transaction itself persists in
 * wp_options so it survives across MCP requests (each tool call is a separate
 * HTTP request).
 *
 * Reads inside an open transaction are answered from the staging buffer, so a
 * sequence of mutation tools composes correctly before anything is persisted.
 */
class Transaction_Manager {
    const KEY = 'agentshell_transaction';

    /**
     * Query arguments that authorise rendering of the staged config.
     *
     * A preview URL is a bearer capability: anyone holding it can see the
     * uncommitted design. So it is scoped to one transaction, one actor, and
     * a short window, and it stops working the moment the transaction closes.
     */
    const PREVIEW_TX_ARG  = 'agentshell_tx';
    const PREVIEW_KEY_ARG = 'agentshell_tx_key';
    const PREVIEW_EXP_ARG = 'agentshell_tx_exp';

    /**
     * The existing theme-profile preview key. Listed here so the same scrubber
     * covers every bearer capability a preview URL can carry.
     */
    const PREVIEW_PROFILE_KEY_ARG = 'agentshell_preview_key';

    /**
     * Preview credential lifetime.
     *
     * Long enough for an agent to capture and inspect several viewports in
     * one turn, short enough that a leaked URL is not a standing capability.
     * Expiry is enforced at render time, not merely baked into the signature,
     * so it fails closed rather than depending on the client honouring it.
     */
    const PREVIEW_TTL_SECONDS = 900;

    /**
     * How long an open transaction survives without activity.
     *
     * A transaction lives in exactly one option row, and before this lease
     * existed nothing removed that row except a commit() or a rollback() the
     * owner actually reached. A PHP process that died between begin() and
     * commit() therefore left the row behind forever: every later caller was
     * refused with "another actor has an open transaction", and nothing in the
     * tooling even reported the transaction as stale. One crashed turn wedged
     * the site, and the only exit was a database edit nobody was authorised to
     * make.
     *
     * Sixty minutes of inactivity. Long enough for an agent to work through a
     * full site-from-brief run — which opens one transaction and then makes
     * dozens of calls across it — and short enough that a wedge heals on its
     * own before anyone goes looking.
     */
    const LEASE_TTL_SECONDS = 3600;

    /**
     * Test seam: a frozen clock for the lease only.
     *
     * Every expiry rule below is otherwise untestable without a 60-minute
     * sleep, and the alternative — reaching into private methods and stubbing
     * time() — tests the stub as much as the code. Set by tests; production
     * never calls it, and see now() for the one thing it deliberately cannot
     * reach.
     */
    private static $now_override = null;

    /** Transaction reclaimed during this request, for the result to report. */
    private $reclaimed = null;

    /** Expired transaction seen by a read-only caller this request. */
    private $expired_seen = null;

    /** Expired transaction holding durable work — never reclaimed. */
    private $orphaned = null;

    private static $instance;

    /**
     * Whether the agentshell_config write hook is already attached.
     *
     * Per request, not per instance — see the constructor for why an instance
     * bound hook is not interchangeable with a correctly dispatched one.
     */
    private static $config_hook_registered = false;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Observe every write to agentshell_config: defer to staging while a
        // transaction is open, otherwise record history.
        //
        // Registered once per request against a static dispatcher rather than
        // against $this. Two reasons, and the second is the one that bites:
        //
        //   - a filter chain is not the same as one filter. apply_filters()
        //     threads each callback's return value into the next, so N
        //     registered callbacks each see the previous one's answer, not the
        //     value that was passed in.
        //   - the manager held on to its payload for the length of the
        //     request, so an instance that had been replaced kept believing a
        //     transaction was open and kept cancelling writes that were meant
        //     to land. The manager now re-reads on every access (see
        //     payload()), which closes that too, but the dispatcher is what
        //     makes the guarantee unconditional rather than incidental.
        //
        // The dispatcher always resolves the current singleton, so a replaced
        // instance cannot be reached through the hook.
        if ( ! self::$config_hook_registered ) {
            self::$config_hook_registered = true;
            add_filter( 'pre_update_option_' . Store::CONFIG_KEY, array( self::class, 'filter_config_update' ), 10, 2 );
        }
    }

    /**
     * Static entry point for the agentshell_config write hook.
     *
     * @param mixed $value     New value about to be written.
     * @param mixed $old_value Currently stored value.
     * @return mixed The value WordPress should store.
     */
    public static function filter_config_update( $value, $old_value ) {
        return self::instance()->on_config_update( $value, $old_value );
    }

    /**
     * The stored transaction row, or null. Always re-read.
     *
     * There is deliberately no memoised copy. An earlier version cached the
     * payload in $this->active for the length of the request, which meant the
     * manager could act on a value that had since been written by anything it
     * did not control — the theme, a test fixture, a second request sharing the
     * process. That turns the compare-and-swap below from a narrow race-window
     * guard into a general staleness detector, and it did: a transaction whose
     * row had been touched by another writer could no longer be closed at all,
     * because the swap correctly refused bytes that were not the ones on disk.
     *
     * Re-reading costs a lookup in the options object cache and removes the
     * whole class. WordPress invalidates that cache on its own writes, and this
     * manager flushes it after every guarded statement, so a fresh read is
     * genuinely fresh — and a caller that has just lost a race learns that from
     * the guard, which is the only place that information exists.
     */
    public function payload() {
        return get_option( self::KEY, null );
    }

    public function is_active() {
        $tx = $this->payload();
        return is_array( $tx ) && ! self::is_expired( $tx );
    }

    // ----------------------------------------------------------- the lease

    /**
     * The current time, as the lease sees it.
     *
     * Deliberately scoped: every method here and its callers in this file use
     * it, but the preview credential windows further down keep calling time()
     * directly. That is the point. A test seam that could move the clock the
     * signer reads would be a seam that could stretch a bearer capability's
     * window, so the two clocks are kept separate and only the one with no
     * security consequence is free to be frozen.
     */
    private static function now() {
        return null === self::$now_override ? time() : (int) self::$now_override;
    }

    public static function freeze_time( $timestamp ) {
        self::$now_override = (int) $timestamp;
    }

    public static function unfreeze_time() {
        self::$now_override = null;
    }

    /**
     * When the owning actor last did something to this transaction.
     *
     * A row written before leases existed has no such field, so its last
     * activity is its creation timestamp — which is the truth, since nothing
     * about it has been touched since.
     */
    public static function last_activity_at( $tx ) {
        if ( ! is_array( $tx ) ) { return 0; }
        if ( isset( $tx['last_activity'] ) ) { return (int) $tx['last_activity']; }
        return (int) ( $tx['timestamp'] ?? 0 );
    }

    /**
     * When this transaction's lease runs out.
     *
     * A row with no expires field gets one measured from its last activity,
     * rather than from whenever this code happened to be deployed. For the
     * rows this feature exists for — transactions abandoned by an interrupted
     * session — that is exactly right: they are already old, so they expire on
     * a clock the operator can reason about, and the first caller after the
     * upgrade finds them stale rather than finding them fresh for another hour.
     */
    public static function expires_at( $tx ) {
        if ( ! is_array( $tx ) ) { return 0; }
        if ( isset( $tx['expires'] ) ) { return (int) $tx['expires']; }
        return self::last_activity_at( $tx ) + self::LEASE_TTL_SECONDS;
    }

    public static function is_expired( $tx ) {
        return is_array( $tx ) && self::now() >= self::expires_at( $tx );
    }

    public static function seconds_remaining( $tx ) {
        return max( 0, self::expires_at( $tx ) - self::now() );
    }

    public static function idle_seconds( $tx ) {
        return is_array( $tx ) ? max( 0, self::now() - self::last_activity_at( $tx ) ) : 0;
    }

    /**
     * Does deleting this transaction's row destroy work that exists nowhere else?
     *
     * Today, never: an open transaction holds a staged config buffer and
     * nothing else, that buffer was never written to the database, and
     * discarding the row discards an intention rather than a fact. That is why
     * reclaiming an expired transaction is safe at all.
     *
     * The moment content transactions land, a transaction will hold a durable
     * pre-image journal for every post it created or modified, and for an
     * updated post that journal will be the only copy of the prior state.
     * Deleting such a row is not cleanup — it is unrecoverable data loss
     * performed by whoever happened to call begin() next, to a transaction
     * they know nothing about. So the check is written and consulted now,
     * while it is still trivially true, rather than bolted on by the change
     * that makes it load-bearing.
     */
    public static function has_durable_work( $tx ) {
        if ( ! is_array( $tx ) || empty( $tx['content'] ) || ! is_array( $tx['content'] ) ) {
            return false;
        }
        foreach ( $tx['content'] as $bucket ) {
            if ( self::journal_has_record( $bucket ) ) { return true; }
        }
        return false;
    }

    /**
     * Does this branch of the journal contain an actual journalled record?
     *
     * Recurses rather than testing one level. A journal is a nested structure —
     * posts → created → { id → pre-image } — and a transaction that created
     * nothing still has its bucket keys, so a one-level check would report a
     * transaction that journalled nothing as holding unrecovered work and would
     * then refuse to reclaim it. The safeguard has to distinguish "there is
     * something here" from "there is a labelled empty drawer here", because
     * getting that backwards turns the safeguard into its own permanent outage.
     */
    private static function journal_has_record( $node ) {
        if ( ! is_array( $node ) ) {
            return null !== $node && '' !== $node;
        }
        foreach ( $node as $child ) {
            if ( self::journal_has_record( $child ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Compare-and-swap the transaction row: write $next only if it still holds $expected.
     *
     * WordPress offers no atomic option write, and both of its APIs are unsafe
     * for a lease:
     *
     *   update_option() reads the old value and then issues
     *     UPDATE ... WHERE option_name = ?
     * with nothing but the name in the WHERE clause. Two concurrent writers
     * both succeed and the later one silently discards the earlier one's work.
     *
     *   delete_option() is worse here. It SELECTs the row's autoload setting
     *     and then deletes by name. A reaper that read the row a moment ago
     *     deletes whatever occupies that name *now* — including a brand new
     *     transaction somebody opened in between. Read-then-delete is not a
     *     lock, and the window between the two statements is exactly the window
     *     a second caller is going to use.
     *
     * wp_options has a UNIQUE key on option_name, and adding a value guard to
     * the WHERE clause turns the statement into the compare-and-swap that
     * update_option is not. Exactly one of N racing callers gets 1 row
     * affected; the rest get 0 and know to re-read rather than assume.
     *
     * The consequence worth stating: a stale *read* is harmless here. The
     * guard is on the write, so a caller acting on a value that has since moved
     * on cannot damage anything — it simply fails its swap and learns that the
     * world moved. That is why the read path above may keep using the ordinary
     * cached get_option().
     */
    private static function cas( array $expected, array $next ) {
        global $wpdb;
        if ( ! isset( $wpdb ) || empty( $wpdb->options ) ) { return false; }
        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                maybe_serialize( $next ),
                self::KEY,
                maybe_serialize( $expected )
            )
        );
        self::flush_option_cache();
        return 1 === (int) $affected;
    }

    /**
     * Delete the transaction row only if it still holds $expected. See cas().
     */
    private static function cas_delete( array $expected ) {
        global $wpdb;
        if ( ! isset( $wpdb ) || empty( $wpdb->options ) ) { return false; }
        $affected = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                self::KEY,
                maybe_serialize( $expected )
            )
        );
        self::flush_option_cache();
        return 1 === (int) $affected;
    }

    /**
     * Drop every cache that could still be serving a pre-swap value.
     *
     * The statements above bypass the options API, so WordPress never learns
     * the row moved and would happily keep serving the old copy from the object
     * cache for the rest of the request — including to the process that just
     * deleted it. On a reclaim that is the difference between "there is no
     * transaction" and "there very much is, and nothing can touch it". The
     * notoptions key matters too: delete_option() would have left the option
     * marked as known-missing, which answers get_option() without consulting
     * the database at all.
     */
    private static function flush_option_cache() {
        if ( ! function_exists( 'wp_cache_delete' ) ) { return; }
        wp_cache_delete( self::KEY, 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        wp_cache_delete( 'alloptions', 'options' );
    }

    /**
     * Resolve the lease for this request.
     *
     * Returns the live transaction, or null when there is none — whether
     * because the row is absent, because it has expired, or because it expired
     * and holds work that must not be thrown away.
     *
     * $may_write is a security boundary, not an optimisation. Reads reach this
     * manager from browsers: preview authorisation and the rendering theme both
     * consult the transaction on an anonymous request. If those could write,
     * an unauthenticated page view would be able to delete another actor's
     * transaction. So a read-only caller is told the truth and changes nothing;
     * only the authenticated mutation entry points — begin, stage, commit and
     * rollback — may reclaim.
     */
    private function settle( $may_write = false ) {
        // The two "you cannot use this" markers describe the state of the lease
        // as of *this* call, so they are cleared before it. Left to linger they
        // make a later call refuse on the strength of a transaction that is no
        // longer in the store at all — which is how an orphaned transaction
        // would come to block the whole site permanently.
        $this->expired_seen = null;
        $this->orphaned     = null;

        $tx = $this->payload();
        if ( ! is_array( $tx ) ) {
            return null;
        }
        if ( ! self::is_expired( $tx ) ) {
            return $tx;
        }
        if ( ! $may_write ) {
            $this->expired_seen = $tx;
            return null;
        }
        return $this->reclaim( $tx );
    }

    /**
     * Retire an expired transaction, if it is safe to retire it at all.
     *
     * Safe means: nothing durable is in it. A config-only transaction is
     * discarded with a single guarded delete, which is the whole recovery — the
     * staged buffer was never in the database, so removing the row removes an
     * intention and nothing else. A transaction carrying a content journal is
     * marked and kept; see has_durable_work() for why that is not a nicety.
     *
     * The loop re-reads rather than retrying against the same expected value.
     * A swap that failed means the row moved between the read and the write,
     * and the overwhelmingly ordinary reason is that the owner renewed a moment
     * earlier — in which case there is a live transaction to respect and
     * deleting it would be the exact failure this whole mechanism exists to
     * prevent. Bounded, because an option row cannot legitimately change that
     * many times in a few milliseconds; giving up is safer than spinning.
     */
    private function reclaim( array $tx ) {
        for ( $attempt = 0; $attempt < 3; $attempt++ ) {
            if ( $attempt > 0 ) {
                $tx           = get_option( self::KEY, null );
            }
            if ( ! is_array( $tx ) ) {
                return null;
            }
            if ( ! self::is_expired( $tx ) ) {
                return $tx; // the owner renewed it; it is live again
            }
            if ( self::has_durable_work( $tx ) ) {
                $this->orphaned = $tx;
                return null;
            }
            if ( self::cas_delete( $tx ) ) {
                $this->reclaimed    = $tx;
                // Also as "expired", so require_live() can tell a caller whose
                // lease just died *why* their commit was refused. Reporting
                // "no transaction is open" to the one actor whose transaction
                // was open a moment ago is the misleading message this
                // function exists to avoid.
                $this->expired_seen = $tx;
                Store::record_event(
                    'reclaimer',
                    'transaction_reclaimed',
                    array(
                        'transaction_id' => isset( $tx['id'] ) ? $tx['id'] : '',
                        'owner'          => isset( $tx['actor'] ) ? $tx['actor'] : '',
                        'label'          => isset( $tx['label'] ) ? $tx['label'] : '',
                        'idle_seconds'   => self::idle_seconds( $tx ),
                        'discarded_staged_changes' => is_array( $tx['staged'] ?? null ),
                    )
                );
                return null;
            }
        }
        // Contended beyond three swaps. Report no transaction rather than
        // force it: the next caller tries again, and a transaction that cannot
        // be read is still better than one that was deleted by a guess.
        return null;
    }

    /**
     * Extend the lease by LEASE_TTL_SECONDS. True when the renewal landed.
     *
     * Compare-and-swap on the exact row the caller decided on, so a renewal
     * that raced a reaper loses — and losing is the desired outcome. A renewal
     * that always succeeded would let an owner that had already been reclaimed
     * carry on writing into a transaction no other caller can see or commit,
     * which is a worse failure than the one it was trying to prevent.
     *
     * Renewal is driven by owner activity only. A peer calling preview or
     * validate is inspecting somebody else's work and must not keep that
     * work's lease alive; otherwise any reader anywhere could pin a
     * transaction open forever and the expiry would never mean anything.
     */
    private function renew( array $tx ) {
        $next = self::stamp_lease( $tx, self::now() );
        if ( ! self::cas( $tx, $next ) ) {
            return false;
        }
        return true;
    }

    private static function stamp_lease( array $tx, $now ) {
        $tx['created']       = isset( $tx['created'] ) ? (int) $tx['created'] : (int) ( $tx['timestamp'] ?? $now );
        $tx['last_activity'] = $now;
        $tx['expires']       = $now + self::LEASE_TTL_SECONDS;
        return $tx;
    }

    /**
     * The lease as a caller-facing block: what exists, when, and for how much
     * longer. Returned by begin() and get_transaction so a caller can see the
     * clock rather than having to infer it from a refusal.
     */
    public static function describe( $tx ) {
        if ( ! is_array( $tx ) ) { return null; }
        return array(
            'id'                => isset( $tx['id'] ) ? $tx['id'] : '',
            'label'             => isset( $tx['label'] ) ? $tx['label'] : '',
            'actor'             => isset( $tx['actor'] ) ? $tx['actor'] : '',
            'created_at'        => isset( $tx['created'] ) ? (int) $tx['created'] : (int) ( $tx['timestamp'] ?? 0 ),
            'last_activity_at'  => self::last_activity_at( $tx ),
            'expires_at'        => self::expires_at( $tx ),
            'seconds_remaining' => self::seconds_remaining( $tx ),
            'expired'           => self::is_expired( $tx ),
            'durable_work'      => self::has_durable_work( $tx ),
            'lease_ttl_seconds' => self::LEASE_TTL_SECONDS,
        );
    }

    /** The transaction this request reclaimed, if it reclaimed one. */
    public function reclaimed() {
        return is_array( $this->reclaimed ) ? $this->describe( $this->reclaimed ) : null;
    }

    /** The expired transaction this request found holding durable work. */
    public function orphaned() {
        return is_array( $this->orphaned ) ? $this->describe( $this->orphaned ) : null;
    }

    /**
     * Refuse an expired transaction with the reason, which is far more useful
     * than "no transaction is open" when the caller is the one whose
     * transaction just died underneath them.
     */
    private function require_live( $may_write = false ) {
        $live = $this->settle( $may_write );
        if ( is_array( $live ) ) {
            return $live;
        }
        if ( is_array( $this->orphaned ) ) {
            throw new \InvalidArgumentException(
                'Transaction ' . $this->orphaned['id'] . ' expired ' . self::idle_seconds( $this->orphaned )
                . 's ago but still holds unrecovered work, so it was not discarded. Its journal is the only '
                . 'record of work it has already done. Recover or commit it before opening another.'
            );
        }
        if ( is_array( $this->expired_seen ) ) {
            throw new \InvalidArgumentException( self::expired_message( $this->expired_seen ) );
        }
        throw new \InvalidArgumentException( 'No transaction is open. Call agentshell_begin_transaction first.' );
    }

    private static function expired_message( array $tx ) {
        return 'Transaction ' . ( $tx['id'] ?? '?' ) . ' opened by ' . ( $tx['actor'] ?? '?' )
            . ' expired at ' . gmdate( 'Y-m-d H:i:s', self::expires_at( $tx ) ) . ' UTC after '
            . self::idle_seconds( $tx ) . 's with no activity'
            . ( is_array( $tx['staged'] ?? null ) ? '. Its staged changes were never written to the database and are gone.' : '.' )
            . ' Call agentshell_begin_transaction to start again.';
    }

    public function begin( $label = '' ) {
        $existing = $this->settle( true );
        if ( is_array( $existing ) ) {
            $actor = Store::actor();
            if ( $existing['actor'] !== $actor ) {
                throw new \InvalidArgumentException(
                    'Another actor (' . $existing['actor'] . ') has an open transaction (' . $existing['id']
                    . ', started ' . gmdate( 'Y-m-d H:i:s', (int) $existing['timestamp'] ) . ', '
                    . self::seconds_remaining( $existing ) . 's of lease left). '
                    . 'Transactions are locked to the actor that opened them — wait for them to commit or roll back, '
                    . 'or coordinate directly.'
                );
            }
            // Same actor re-begins: idempotent, and the re-assertion counts as
            // activity — an agent that keeps declaring its transaction is
            // declaring it alive.
            $this->renew( $existing );
            return $this->payload();
        }
        if ( is_array( $this->orphaned ) ) {
            throw new \InvalidArgumentException(
                'Transaction ' . $this->orphaned['id'] . ' expired but still holds unrecovered work, so it was not '
                . 'discarded and cannot be replaced. Recover or commit it before opening another.'
            );
        }
        $tx = self::stamp_lease(
            array(
                // Uniquely, not merely 'tx_' . time(). Two transactions opened
                // in the same wall-clock second used to share an id, and the
                // preview credential is bound to that id — so a URL minted for
                // a transaction that has since been committed could have
                // matched a *different* transaction opened by the same actor in
                // the same second. The lease makes that window reachable, since
                // it is now normal for one transaction to be reclaimed and
                // another opened in its place moments later.
                'id'        => 'tx_' . self::now() . '_' . bin2hex( random_bytes( 4 ) ),
                'label'     => sanitize_text_field( $label ),
                'actor'     => Store::actor(),
                'timestamp' => self::now(),
                'before'    => get_option( Store::CONFIG_KEY, array() ),
                'staged'    => null,
            ),
            self::now()
        );
        // add_option(), not update_option(): this is an INSERT against a UNIQUE
        // key on option_name, so exactly one of two actors racing to open a
        // transaction creates it. update_option would let both "succeed" and
        // leave whichever wrote last holding a transaction the other actor
        // believes it owns.
        if ( ! add_option( self::KEY, $tx, '', false ) ) {
            $other        = $this->settle( true );
            throw new \InvalidArgumentException(
                'A transaction was opened by another actor while this call was in flight'
                . ( is_array( $other ) && isset( $other['id'] ) ? ' (' . $other['id'] . ', ' . $other['actor'] . ')' : '' )
                . '. Read agentshell_get_transaction and coordinate directly.'
            );
        }
        return $tx;
    }

    /**
     * Assert the calling actor owns the open transaction (multi-agent guard).
     * Read-only operations (preview/validate) may inspect any actor's staged
     * changes; mutations (stage/commit/rollback) are owner-only.
     *
     * Takes the live row rather than reading it again: the caller has already
     * settled the lease, and re-reading here would reintroduce the window in
     * which the row this check approved of could be replaced underneath it.
     */
    private function assert_owner( array $tx ) {
        $actor = Store::actor();
        if ( $tx['actor'] !== $actor ) {
            throw new \InvalidArgumentException(
                'Transaction ' . $tx['id'] . ' was opened by ' . $tx['actor'] . '; you are ' . $actor
                . '. Commit/rollback is locked to the opening actor — wait for them to finish, or coordinate directly.'
            );
        }
    }

    /**
     * Stage a full config array. Returns true if staged, false if no
     * transaction is open (caller falls back to a direct write).
     *
     * Settles the lease first, so a write that arrives after the owning
     * transaction's lease ran out lands in the database rather than in a
     * transaction nobody can commit any more. Returns false in that case
     * because for the caller the two are the same thing: no open transaction.
     */
    public function stage( array $config ) {
        $live = $this->settle( true );
        if ( ! is_array( $live ) ) {
            return false;
        }
        $this->assert_owner( $live );
        $next           = $live;
        $next['staged'] = $config;
        $next           = self::stamp_lease( $next, self::now() );
        if ( ! self::cas( $live, $next ) ) {
            throw new \InvalidArgumentException(
                'Transaction ' . $live['id'] . ' changed while this change was being staged, so it was not applied. '
                . 'Nothing was written. Read agentshell_get_transaction and retry.'
            );
        }
        return true;
    }

    /**
     * Config that tools should operate on: staged (if open) else persisted.
     */
    public function effective_config() {
        // One read, then decide. Reading twice would let the row change between
        // the question and the answer, and the two answers differ.
        $tx = $this->payload();
        if ( is_array( $tx ) && ! self::is_expired( $tx ) && is_array( $tx['staged'] ?? null ) ) {
            return $tx['staged'];
        }
        return get_option( Store::CONFIG_KEY, array() );
    }

    public function staged_config() {
        $tx = $this->payload();
        return ( is_array( $tx ) && ! self::is_expired( $tx ) ) ? ( $tx['staged'] ?? null ) : null;
    }

    /**
     * Interception point for ALL writes to agentshell_config (MCP tools, theme
     * REST endpoint, configurator). While a transaction is open, defer to
     * staging; otherwise record the mutation into history.
     *
     * Cancellation is done by returning $old_value, not false. WordPress's
     * pre_update_option_{$option} is a *transform* filter, not a veto: core
     * assigns whatever comes back to $value and then compares it to the stored
     * value, skipping the write only when they match (wp-includes/option.php,
     * the "If the new and old values are the same" check). Returning false
     * therefore did not cancel anything — it replaced the configuration with
     * the boolean, which agentshell_get_config() then quietly re-seeded from
     * default-config.json, so the agent's committed site silently reverted to
     * defaults. Returning the old value is what actually cancels.
     *
     * @param mixed $value     New value about to be written
     * @param mixed $old_value Currently stored value
     * @return mixed
     */
    public function on_config_update( $value, $old_value ) {
        // stage() settles the lease first, so a write arriving after the
        // owning transaction's lease ran out goes to the database instead of
        // into a transaction nobody can commit any more.
        if ( $this->stage( $value ) ) {
            return $old_value; // unchanged — WordPress skips the write
        }
        if ( $value !== $old_value && ! Store::$suppress_history ) {
            Store::record_mutation( $old_value, $value, 'config_update', Store::$operation_label );
            Store::$operation_label = '';
        }
        return $value;
    }

    /**
     * Diff staged vs before. Throws if no transaction is open.
     */
    public function preview() {
        $tx = $this->require_live( false );
        return Store::diff_config( $tx['before'], $tx['staged'] ?: $tx['before'] );
    }

    /**
     * Validate the staged config. Throws on hard errors; warnings are returned.
     */
    public function validate() {
        $tx     = $this->require_live( false );
        $issues = Doctor::check( $tx['staged'] ?: $tx['before'] );
        if ( ! empty( $issues['errors'] ) ) {
            throw new \InvalidArgumentException( 'Transaction has validation errors: ' . wp_json_encode( $issues['errors'] ) );
        }
        return $issues;
    }

    /**
     * Apply staged config in one write and close the transaction.
     *
     * @return array Closed transaction record
     */
    public function commit() {
        $tx = $this->require_live( true );
        $this->assert_owner( $tx );
        if ( ! is_array( $tx['staged'] ) ) {
            throw new \InvalidArgumentException( 'Transaction has no staged changes. Call a mutation tool first.' );
        }
        $issues = Doctor::check( $tx['staged'] );
        if ( ! empty( $issues['errors'] ) ) {
            throw new \InvalidArgumentException( 'Commit blocked by validation errors: ' . wp_json_encode( $issues['errors'] ) );
        }

        // Close the transaction BEFORE writing so on_config_update records
        // history with the transaction label instead of re-staging.
        //
        // A guarded delete, not delete_option(): commit is the one path that must
        // never remove a transaction that is no longer the one just validated.
        // Between the check above and the delete, a lease could have expired
        // and a reaper could have deleted this row and opened a new one — and
        // an unguarded delete by name would take that new transaction with it,
        // silently destroying whatever its owner had staged since.
        if ( ! self::cas_delete( $tx ) ) {
            throw new \InvalidArgumentException(
                'Transaction ' . $tx['id'] . ' changed while it was being committed, so nothing was applied. '
                . 'Read agentshell_get_transaction and retry; any staged changes are still there.'
            );
        }

        Store::$operation_label = $tx['label'] ? 'transaction: ' . $tx['label'] : 'transaction_commit';
        update_option( Store::CONFIG_KEY, $tx['staged'] ); // hook records the mutation
        Store::$operation_label = '';

        // The pre_update_option hook above recorded the mutation; stamp the
        // revision with the transaction id for traceability.
        self::stamp_last_revision( $tx['id'] );

        return array(
            'id'            => $tx['id'],
            'label'         => $tx['label'],
            'committed'     => true,
            'changed_tokens' => Store::diff_token_keys( $tx['before'], $tx['staged'] ),
        );
    }

    /**
     * Discard staged changes. The database was never touched, so there is
     * nothing to restore.
     *
     * @return array
     */
    public function rollback() {
        $tx = $this->require_live( true );
        $this->assert_owner( $tx );
        if ( ! self::cas_delete( $tx ) ) {
            throw new \InvalidArgumentException(
                'Transaction ' . $tx['id'] . ' changed while it was being rolled back, so it was not closed. '
                . 'Read agentshell_get_transaction; the staged changes are still there.'
            );
        }
        return array(
            'id'        => $tx['id'],
            'label'     => $tx['label'],
            'rolled_back' => true,
            'discarded_staged_changes' => is_array( $tx['staged'] ),
        );
    }

    private static function stamp_last_revision( $tx_id ) {
        $data = Store::revisions();
        if ( ! empty( $data['items'][0] ) ) {
            $data['items'][0]['transaction_id'] = $tx_id;
            update_option( Store::REVISIONS_KEY, $data, false );
        }
    }

    // ------------------------------------------------------- preview access

    /**
     * Secret used to sign preview credentials.
     *
     * AGENTSHELL_REST_TOKEN is preferred — it is already the agent-facing
     * secret and never leaves the server. AUTH_SALT is the documented
     * fallback, and is what wp_hash() itself uses for the same purpose.
     *
     * Returns '' when neither is defined, and every caller must fail closed
     * on that rather than falling back to something guessable.
     */
    public static function preview_secret() {
        if ( defined( 'AGENTSHELL_REST_TOKEN' ) && is_string( constant( 'AGENTSHELL_REST_TOKEN' ) )
            && '' !== constant( 'AGENTSHELL_REST_TOKEN' ) ) {
            return (string) constant( 'AGENTSHELL_REST_TOKEN' );
        }
        if ( defined( 'AUTH_SALT' ) && is_string( constant( 'AUTH_SALT' ) ) && '' !== constant( 'AUTH_SALT' ) ) {
            return (string) constant( 'AUTH_SALT' );
        }
        return '';
    }

    /**
     * Query arguments authorising a preview of the currently open transaction.
     *
     * @throws \InvalidArgumentException when no transaction is open, or when
     *         no signing secret exists. Refusing here is deliberate: minting a
     *         credential from a guessable secret would be worse than not
     *         offering the preview at all.
     */
    public static function preview_args() {
        $tx = self::instance()->payload();
        if ( ! is_array( $tx ) ) {
            throw new \InvalidArgumentException(
                'No transaction is open. Call agentshell_begin_transaction before previewing one.'
            );
        }
        // Refuse an expired transaction before signing anything. A preview of
        // an abandoned transaction is a render of work its owner is no longer
        // in the middle of, to a credential that outlives their intent.
        if ( self::is_expired( $tx ) ) {
            throw new \InvalidArgumentException( self::expired_message( $tx ) );
        }
        $secret = self::preview_secret();
        if ( '' === $secret ) {
            throw new \InvalidArgumentException(
                'Cannot authorise a transaction preview: neither AGENTSHELL_REST_TOKEN nor AUTH_SALT is defined.'
            );
        }

        $expires = time() + self::PREVIEW_TTL_SECONDS;
        return array(
            self::PREVIEW_TX_ARG  => (string) $tx['id'],
            self::PREVIEW_EXP_ARG => (string) $expires,
            self::PREVIEW_KEY_ARG => self::sign_preview( $tx['id'], $tx['actor'], $expires, $secret ),
        );
    }

    /**
     * HMAC over the transaction identity and the expiry window.
     *
     * Bound to the transaction id and its owner, so a credential issued for
     * one transaction cannot be replayed against another — including against
     * a later transaction that happens to reuse the id. Bound to the expiry,
     * so shortening the window changes the signature and an old credential
     * cannot be stretched by editing the timestamp parameter.
     */
    private static function sign_preview( $tx_id, $actor, $expires, $secret ) {
        return hash_hmac( 'sha256', 'agentshell-tx-preview|' . $tx_id . '|' . $actor . '|' . (int) $expires, $secret );
    }

    /**
     * True when these preview credentials may render the staged config now.
     *
     * Fails closed on every path: no open transaction, wrong transaction,
     * expired window, missing or non-numeric expiry, malformed id, or a
     * signature mismatch. A closed transaction fails because rollback and
     * commit both delete the option this reads, so a revoked preview stops
     * working on the very next request with no separate revocation list.
     */
    public static function authorize_preview( $tx_id, $key, $expires ) {
        $tx = self::instance()->payload();
        if ( ! is_array( $tx ) || empty( $tx['id'] ) ) {
            return false;
        }
        // An expired transaction is not a transaction. This runs *before* the
        // signature check so that an expired credential and a forged one are
        // indistinguishable to the caller, and it is needed because the two
        // windows are not the same: a credential minted in the first minute of
        // a 60-minute lease is still inside its own 900-second window when the
        // lease is dead. Without this, the tail of every abandoned transaction
        // stayed renderable by anyone still holding the URL. The credential's
        // own expiry check below keeps using real time(), so nothing here can
        // stretch it — the two gates shorten each other, and only the shorter
        // one that applies is ever consulted.
        if ( self::is_expired( $tx ) ) {
            return false;
        }
        $secret = self::preview_secret();
        if ( '' === $secret || ! is_string( $key ) || '' === $key || ! is_string( $tx_id ) || '' === $tx_id ) {
            return false;
        }
        // Reject anything that is not a plain positive integer before it is
        // used in arithmetic or string comparison.
        if ( ! is_string( $expires ) && ! is_int( $expires ) ) {
            return false;
        }
        $expires = (int) $expires;
        // A zero or non-numeric expiry casts to 0, which is always in the
        // past — so one comparison covers "expired", "zero", and "not a
        // number" alike. That is not an accident; adding a separate
        // `$expires <= 0` arm is a branch that can never fail differently,
        // and mutation testing showed as much.
        if ( $expires < time() ) {
            return false;
        }
        // Bind to the transaction that is actually open.
        if ( ! hash_equals( (string) $tx['id'], $tx_id ) ) {
            return false;
        }

        return hash_equals(
            self::sign_preview( $tx['id'], $tx['actor'], $expires, $secret ),
            $key
        );
    }

    /**
     * Strip preview credentials from a URL before it is shown or logged.
     *
     * The returned summary and the audit log must never carry a bearer
     * capability. The transaction id and expiry are kept — they are useless
     * without the signature — because they make the URL diagnosable.
     *
     * Both preview key parameters are removed, not just the transaction one:
     * the profile preview key has the same properties and the same exposure
     * path, and a scrubber that only knows about one of them is a scrubber
     * that eventually leaks the other.
     */
    public static function redact_preview_url( $url ) {
        return remove_query_arg(
            array( self::PREVIEW_KEY_ARG, self::PREVIEW_PROFILE_KEY_ARG ),
            (string) $url
        );
    }

    /**
     * Remove every preview parameter from a URL supplied by a caller.
     *
     * The screenshot tool mints its own credentials, so anything arriving in
     * a `url` argument is either stale or borrowed — and the audit log records
     * arguments verbatim. Stripping first means a capability cannot be
     * persisted there by being pasted into a URL.
     */
    public static function strip_preview_args( $url ) {
        return remove_query_arg(
            array(
                self::PREVIEW_TX_ARG,
                self::PREVIEW_KEY_ARG,
                self::PREVIEW_EXP_ARG,
                self::PREVIEW_PROFILE_KEY_ARG,
            ),
            (string) $url
        );
    }
}