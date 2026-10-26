<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * A bounded SQL transaction around a single content write.
 *
 * ## Why this exists
 *
 * `wp_insert_post()` is not all-or-nothing. It is a sequence of independent
 * writes — the post row, then the slug, then categories, tags and other
 * default terms, then `meta_input`, then the GUID, then status transitions,
 * then `save_post` / `wp_after_insert_post` — and it can return a `WP_Error`
 * from a branch that runs *after* some of them have already been committed.
 * The measured case is `invalid_page_template`, which returns at the page
 * template check with the row, its categories and its metadata already
 * written:
 *
 *     wp_insert_post returned WP_Error: invalid_page_template — Invalid page template.
 *     did the post row survive the WP_Error? YES — #303 status=draft
 *     meta rows for it     : 1
 *
 * A caller that reads `WP_Error` as "that step did nothing" is wrong, and it
 * cannot repair itself afterwards either, because the failure path never
 * returns the post id. There is no pre-allocation API, so an id cannot be
 * reserved either; the only way to recover one afterwards is to guess from the
 * title and the clock, which is not identification.
 *
 * So the repair has to happen at the write boundary rather than after it.
 * Wrapping the single `wp_insert_post()` call in a short SQL transaction and
 * rolling back on any failure makes the whole class of partial write
 * impossible for everything the call wrote to the database:
 *
 *     $wpdb->query( 'START TRANSACTION' );
 *     $id = wp_insert_post( $postarr, true );   // returns WP_Error
 *     $wpdb->query( 'ROLLBACK' );
 *     // rows_after: []   meta_after: 0
 *
 * The transaction is bounded by one function call. It is never held open across
 * an MCP call, a screenshot, or agent reasoning, because this is ordinary PHP
 * request scope: the daemon makes one HTTP request, WordPress serves it in one
 * PHP request, and the connection is released when that request ends.
 *
 * ## What this does not promise
 *
 * `ROLLBACK` only undoes writes that went through *this connection* inside
 * *this transaction*. It cannot undo:
 *
 *   - side effects outside the database — email sent by a status transition,
 *     a file written by a plugin, an outbound HTTP request;
 *   - another plugin's database writes made during `save_post`, which are in
 *     the transaction and are therefore rolled back too. That is deliberate:
 *     a half-applied third-party write is worse than none — but it does mean a
 *     plugin cannot rely on a failed `agentshell_create_page` leaving its
 *     bookkeeping intact.
 *
 * These are reported to the caller in the tool result rather than assumed away,
 * so an agent is never told the write was all-or-nothing when it was not.
 *
 * ## Nested writes
 *
 * MySQL has no nested transactions and `START TRANSACTION` inside an open
 * transaction *commits* it. A `save_post` hook that creates its own post would
 * therefore silently commit the outer write if each call started its own
 * transaction. A depth counter makes a nested call join the transaction that
 * is already open instead.
 *
 * ## When protection is unavailable
 *
 * `ROLLBACK` is a no-op on a non-transactional engine (MyISAM). Rather than
 * claim a guarantee it cannot keep, the guard checks the engine once, runs
 * unguarded when the tables are not transactional or `START TRANSACTION`
 * itself fails, and says so in the result.
 */
class Content_Write_Guard {

    /**
     * Reentrancy depth. Zero means this call owns the transaction.
     *
     * @var int
     */
    private static $depth = 0;

    /**
     * Cached engine verdict for the content tables: 'InnoDB', or '' for
     * "not transactional". Null means "not looked up yet".
     *
     * @var string|null
     */
    private static $engine = null;

    /**
     * Cached engine verdicts, keyed by the table set they describe.
     *
     * The content tables and the options table are different questions, and
     * a site may answer them differently. Keying the verdict by its table set
     * is what keeps a write from inheriting a guarantee that was never made
     * about the table it used.
     *
     * @var array<string,string>
     */
    private static $engines = array();

    /**
     * Whether the most recent top-level run() actually held a transaction.
     *
     * @var bool
     */
    private static $last_was_transactional = false;

    /**
     * Run one content write so that a failure leaves no trace of it.
     *
     * The callable's return value is the write's result: a `WP_Error`, or
     * `false`/`null`/`0`/a negative id means the write failed, and anything
     * else means it succeeded. The same contract as `wp_insert_post()` itself,
     * so the caller does not have to learn a second one.
     *
     * If the callable throws, the transaction is rolled back and the exception
     * is rethrown unchanged — including AgentShell's own post-insert failures,
     * which is the point: a tool that reports failure must have written
     * nothing.
     *
     * @param callable $write The write to perform.
     * @return mixed Whatever $write returned.
     * @throws \Throwable Whatever $write threw, after rolling back.
     */
    public static function run( callable $write ) {
        return self::run_on( self::content_tables(), $write );
    }

    /**
     * Run one write against a named set of tables, so that the guarantee is
     * made about the tables the write actually touches.
     *
     * The content guard protects `wp_posts` and `wp_postmeta`. A write that
     * touches other tables — the options table, for instance — cannot inherit
     * that verdict: those tables may be transactional while the content
     * tables are not, or the other way round, and a rollback that does not
     * cover the table the write used is not a rollback.
     *
     * Passing the tables explicitly is what makes the answer honest. The
     * reentrancy depth counter, the engine probe, the cache discard on
     * rollback and the failure contract are all the same as for a content
     * write.
     *
     * @param array    $tables The table names the write touches.
     * @param callable $write  The write to perform.
     * @return mixed Whatever $write returned.
     * @throws \Throwable Whatever $write threw, after rolling back.
     */
    public static function run_on( array $tables, callable $write ) {
        global $wpdb;

        $engine = self::engine_for( $tables );

        // Either we are already inside a transaction we must not commit, or
        // the tables cannot honour one. In both cases the write simply runs.
        if ( self::$depth > 0 || '' === $engine ) {
            self::$depth++;
            try {
                return $write();
            } finally {
                self::$depth--;
            }
        }

        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
            self::$depth++;
            try {
                return $write();
            } finally {
                self::$depth--;
            }
        }

        $started = ( false !== @$wpdb->query( 'START TRANSACTION' ) );
        self::$last_was_transactional = $started;

        if ( ! $started ) {
            // The database refused to open one. Run the write anyway — refusing
            // to create content would be a worse failure than an unprotected
            // one — but record that it was unprotected.
            self::$depth++;
            try {
                return $write();
            } finally {
                self::$depth--;
            }
        }

        self::$depth++;
        try {
            $result = $write();
        } catch ( \Throwable $e ) {
            self::$depth--;
            $wpdb->query( 'ROLLBACK' );
            self::discard_caches();
            throw $e;
        }
        self::$depth--;

        if ( self::is_failure( $result ) ) {
            $wpdb->query( 'ROLLBACK' );
            self::discard_caches();
            return $result;
        }

        $wpdb->query( 'COMMIT' );
        return $result;
    }

    /**
     * Did the write fail?
     *
     * Mirrors `wp_insert_post()`'s own contract, and fails closed: anything
     * that is not an unambiguous success is treated as a failure, because the
     * cost of guessing wrong here is a tool reporting success over a rolled
     * back write.
     *
     * @param mixed $result
     * @return bool
     */
    public static function is_failure( $result ) {
        if ( is_wp_error( $result ) ) {
            return true;
        }
        if ( null === $result || false === $result ) {
            return true;
        }
        if ( is_numeric( $result ) && (int) $result < 1 ) {
            return true;
        }
        return false;
    }

    /**
     * The engine backing the content tables, or '' when they cannot roll back.
     *
     * @return string 'InnoDB' or ''
     */
    public static function engine() {
        global $wpdb;
        $tables = array();
        if ( isset( $wpdb ) && is_object( $wpdb ) ) {
            if ( ! empty( $wpdb->posts ) ) { $tables[] = $wpdb->posts; }
            if ( ! empty( $wpdb->postmeta ) ) { $tables[] = $wpdb->postmeta; }
        }
        return self::engine_for( $tables );
    }

    /**
     * The engine backing a named set of tables, or '' when they cannot roll
     * back.
     *
     * The verdict is per table set, not global: a site whose content tables
     * are InnoDB and whose options table is not has two different answers, and
     * a write to the options table must be told the one that applies to it.
     *
     * @param array $tables
     * @return string 'InnoDB' or ''
     */
    public static function engine_for( array $tables ) {
        $tables = array_values( array_filter( array_map( 'strval', $tables ) ) );
        if ( empty( $tables ) ) {
            return '';
        }
        $key = implode( ',', $tables );
        if ( array_key_exists( $key, self::$engines ) ) {
            return self::$engines[ $key ];
        }

        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
            self::$engines[ $key ] = '';
            return self::$engines[ $key ];
        }

        // Table names come from $wpdb, not from a caller, but they are still
        // interpolated into SQL, so they are quoted rather than trusted.
        $quoted = array();
        foreach ( $tables as $table ) {
            $quoted[] = "'" . addcslashes( $table, "\\'" ) . "'";
        }
        $sql = 'SHOW TABLE STATUS WHERE Name IN (' . implode( ',', $quoted ) . ')';

        $rows = @$wpdb->get_results( $sql );
        if ( ! is_array( $rows ) || count( $rows ) !== count( $tables ) ) {
            // Unknown: a restricted user, a replica, or a missing table. Do not
            // assume InnoDB just because it is the common answer.
            self::$engines[ $key ] = '';
            return self::$engines[ $key ];
        }
        foreach ( $rows as $row ) {
            if ( ! isset( $row->Engine ) || 'INNODB' !== strtoupper( (string) $row->Engine ) ) {
                self::$engines[ $key ] = '';
                return self::$engines[ $key ];
            }
        }

        self::$engines[ $key ] = 'InnoDB';
        return self::$engines[ $key ];
    }

    /**
     * What the caller is entitled to believe about the write that just ran.
     *
     * @return array
     */
    public static function report() {
        $report = self::report_for( self::content_tables() );
        // The content guard's own wording: a content write's side effects
        // include the third-party writes a `save_post` hook made, which this
        // transaction also rolls back.
        $report['not_covered'] = 'side effects outside the database (email, files, outbound HTTP) and any other plugin\'s database writes made during save_post, which this transaction also rolls back';
        return $report;
    }

    /** The tables a content write touches. */
    private static function content_tables() {
        global $wpdb;
        $tables = array();
        if ( isset( $wpdb ) && is_object( $wpdb ) ) {
            if ( ! empty( $wpdb->posts ) ) { $tables[] = $wpdb->posts; }
            if ( ! empty( $wpdb->postmeta ) ) { $tables[] = $wpdb->postmeta; }
        }
        return $tables;
    }

    /**
     * What the caller is entitled to believe about a write against a named
     * set of tables.
     *
     * @param array $tables
     * @return array
     */
    public static function report_for( array $tables ) {
        $engine = self::engine_for( $tables );
        $held   = $engine && self::$last_was_transactional;

        return array(
            'database_transaction' => (bool) $held,
            'engine'                => $held ? $engine : null,
            'on_failure'            => $held
                ? 'rolled back — nothing this call wrote remains in the database'
                : 'not rolled back — the tables could not be opened as a transaction, so a partial write is possible',
            'not_covered'           => 'side effects outside the database (email, files, outbound HTTP), and any other plugin\'s database writes made during the write, which this transaction also rolls back',
        );
    }

    /**
     * Forget the cached engine lookup. For tests, and after a table migration.
     */
    public static function reset() {
        self::$depth                 = 0;
        self::$engine                = null;
        self::$engines               = array();
        self::$last_was_transactional = false;
    }

    /**
     * Drop the caches a rolled-back write may have left behind.
     *
     * The id of a rolled-back post is not always known — the `invalid_page_template`
     * branch returns before any hook that would name it has fired — so there is
     * nothing narrower to invalidate. `wp_cache_flush()` is correct everywhere
     * and this path is rare by construction: it only runs when a write has
     * already failed.
     *
     * Without it the object cache keeps serving the rolled-back post, which is
     * the same lie in a different form: `get_post()` returns a post that is not
     * in the database and `get_permalink()` hands out a URL for it.
     */
    private static function discard_caches() {
        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }
    }
}