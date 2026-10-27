<?php
/**
 * The bounded-transaction contract, driven by statement-level failures.
 *
 * `Form_DB::run()` is where "the write is atomic" is either true or a claim.
 * Its failure modes are not reproducible against a real database without
 * killing connections, so this suite replaces `$wpdb` with a recorder whose
 * answers are scripted: `START TRANSACTION`, `COMMIT` and `ROLLBACK` can each
 * be made to fail, and the suite asserts what the caller is told — and, just as
 * importantly, whether the callback ran at all.
 *
 * The behaviours pinned here:
 *
 *   - a required transaction that cannot start **refuses to run the write**,
 *     for all three reasons it can fail (no handle, non-transactional engine,
 *     refused BEGIN);
 *   - the old "run it unguarded anyway" fallback survives only when the caller
 *     asks for it by name (`TRANSACTION_OPTIONAL`);
 *   - a failed `COMMIT` is reported as an **unknown outcome**, never as
 *     success;
 *   - a failed `ROLLBACK` is reported as an unknown outcome too, rather than
 *     as "nothing was written";
 *   - a nested call joins the open transaction instead of opening a second one
 *     (`START TRANSACTION` inside a transaction implicitly commits it);
 *   - `reconcile_writes()` distinguishes applied, not-applied and unprovable.
 *
 * The real-database behaviour — real rows, real locks, real rollback — is
 * `tests/forms-storage-test.php`; this suite is about the decisions `run()`
 * makes when the database says no.
 */

require_once __DIR__ . '/bootstrap.php';

require_once dirname( __DIR__ ) . '/agentshell-forms/includes/class-form-db.php';

use AgentShell_Forms\Form_DB;

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/' );
}

echo "forms transaction (unit, scripted database)\n";

/**
 * A `$wpdb` whose answers are scripted per statement.
 */
class AS_TX_Fake_WPDB {

    /** @var string */
    public $posts = 'wp_posts';
    /** @var string */
    public $postmeta = 'wp_postmeta';
    /** @var string */
    public $options = 'wp_options';
    /** @var string */
    public $prefix = 'wp_';
    /** @var int */
    public $insert_id = 0;

    /** @var array<int,string> Every statement issued, in order. */
    public $queries = array();

    /** @var array<string,mixed> begin/commit/rollback/engine/tables. */
    public $scenario = array();

    /** @var array<string,array<int,array{meta_id:int,meta_value:string}>> Rows by meta key. */
    public $rows_by_key = array();

    /** @var bool */
    public $suppressed = false;

    /**
     * Substitute placeholders the way `$wpdb->prepare()` does, closely enough
     * for the SQL to be parseable.
     *
     * @param string $sql  SQL.
     * @param mixed  $args Values.
     * @return string
     */
    public function prepare( $sql, ...$args ) {
        $out = '';
        $i   = 0;
        $len = strlen( (string) $sql );

        for ( $pos = 0; $pos < $len; $pos++ ) {
            $char = $sql[ $pos ];
            if ( '%' === $char && $pos + 1 < $len ) {
                $next = $sql[ $pos + 1 ];
                if ( 's' === $next || 'd' === $next ) {
                    $value = isset( $args[ $i ] ) ? $args[ $i ] : '';
                    $i++;
                    $out .= 's' === $next ? "'" . str_replace( "'", "''", (string) $value ) . "'" : (string) (int) $value;
                    $pos++;
                    continue;
                }
                if ( '%' === $next ) {
                    $out .= '%';
                    $pos++;
                    continue;
                }
            }
            $out .= $char;
        }

        return $out;
    }

    /**
     * Record and answer one statement.
     *
     * @param string $sql SQL.
     * @return mixed
     */
    public function query( $sql ) {
        $this->queries[] = (string) $sql;
        $s = trim( (string) $sql );

        if ( 0 === strcasecmp( $s, 'START TRANSACTION' ) ) {
            return array_key_exists( 'begin', $this->scenario ) ? $this->scenario['begin'] : true;
        }
        if ( 0 === strcasecmp( $s, 'COMMIT' ) ) {
            return array_key_exists( 'commit', $this->scenario ) ? $this->scenario['commit'] : true;
        }
        if ( 0 === strcasecmp( $s, 'ROLLBACK' ) ) {
            return array_key_exists( 'rollback', $this->scenario ) ? $this->scenario['rollback'] : true;
        }

        return 1;
    }

    /**
     * The engine probe.
     *
     * @param string $sql SQL.
     * @return array<int,string>
     */
    public function get_col( $sql ) {
        $this->queries[] = (string) $sql;
        $engine = isset( $this->scenario['engine'] ) ? (string) $this->scenario['engine'] : 'InnoDB';
        $count  = isset( $this->scenario['tables'] ) ? (int) $this->scenario['tables'] : 1;

        if ( '' === $engine ) {
            return array();
        }

        return array_fill( 0, max( 1, $count ), $engine );
    }

    /**
     * @param string $sql SQL.
     * @return mixed
     */
    public function get_var( $sql ) {
        $this->queries[] = (string) $sql;
        return 1;
    }

    /**
     * @param string $sql    SQL.
     * @param mixed  $output Output format.
     * @return mixed
     */
    public function get_row( $sql, $output = null ) {
        $this->queries[] = (string) $sql;
        return null;
    }

    /**
     * Rows for one meta key, from the scripted map.
     *
     * @param string $sql    SQL.
     * @param mixed  $output Output format.
     * @return array
     */
    public function get_results( $sql, $output = null ) {
        $this->queries[] = (string) $sql;

        if ( ! preg_match( "/meta_key = '([^']*)'/", (string) $sql, $m ) ) {
            return array();
        }

        $key = $m[1];

        $rows = array();
        foreach ( (array) ( $this->rows_by_key[ $key ] ?? array() ) as $row ) {
            $rows[] = array(
                'meta_id'    => (int) $row['meta_id'],
                'meta_value' => (string) $row['meta_value'],
            );
        }

        return $rows;
    }

    /**
     * @param string $table  Table.
     * @param array  $data   Data.
     * @param mixed  $format Format.
     * @return bool
     */
    public function insert( $table, $data, $format = null ) {
        $this->queries[] = 'INSERT INTO ' . $table;
        return false;
    }

    /**
     * @param bool $suppress Whether to suppress.
     * @return bool The previous value.
     */
    public function suppress_errors( $suppress = true ) {
        $previous         = $this->suppressed;
        $this->suppressed = (bool) $suppress;
        return $previous;
    }
}

/**
 * Install a fresh fake database with a scripted scenario.
 *
 * @param array $scenario Scenario.
 * @return AS_TX_Fake_WPDB
 */
function as_tx_db( array $scenario = array() ) {
    $db           = new AS_TX_Fake_WPDB();
    $db->scenario = $scenario;
    $GLOBALS['wpdb'] = $db;
    return $db;
}

/**
 * The statements the fake recorded that match a word.
 *
 * @param AS_TX_Fake_WPDB $db   The database.
 * @param string          $word Statement to look for (case-insensitive, trimmed).
 * @return int
 */
function as_tx_count( AS_TX_Fake_WPDB $db, $word ) {
    $count = 0;
    foreach ( $db->queries as $sql ) {
        if ( 0 === strcasecmp( trim( $sql ), $word ) ) {
            $count++;
        }
    }
    return $count;
}

/* ---------------------------------------------------------------------------
 * The happy path, and what it records
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms transaction: a required transaction opens, runs and commits in that order',
    function () {
        $db = as_tx_db( array( 'engine' => 'InnoDB' ) );

        $ran    = 0;
        $result = Form_DB::run( array( 'as_tx_happy' ), function () use ( &$ran ) {
            $ran++;
            return 'written';
        } );

        Agentshell_Test::assert_equals( 'written', $result, 'the callback result must be returned' );
        Agentshell_Test::assert_equals( 1, $ran, 'the callback must run exactly once' );
        Agentshell_Test::assert_equals( 1, as_tx_count( $db, 'START TRANSACTION' ), 'one transaction must be opened' );
        Agentshell_Test::assert_equals( 1, as_tx_count( $db, 'COMMIT' ), 'the transaction must be committed' );
        Agentshell_Test::assert_equals( 0, as_tx_count( $db, 'ROLLBACK' ), 'nothing must be rolled back' );
        Agentshell_Test::assert_true( true === Form_DB::last_was_transactional(), 'the caller must be told it was transactional' );
    }
);

/* ---------------------------------------------------------------------------
 * Fail closed: the three ways a transaction cannot start
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms transaction: a refused BEGIN refuses the write instead of running it unguarded',
    function () {
        $db = as_tx_db( array( 'engine' => 'InnoDB', 'begin' => false ) );

        $ran    = false;
        $result = Form_DB::run( array( 'as_tx_begin_fail' ), function () use ( &$ran ) {
            $ran = true;
            return 'written';
        } );

        Agentshell_Test::assert_true( $ran === false, 'the callback must NOT run when a required transaction cannot start' );
        Agentshell_Test::assert_true( is_wp_error( $result ), 'a refusal must be returned' );
        Agentshell_Test::assert_equals( 'form_transaction_unavailable', $result->get_error_code(), 'the refusal must be named' );

        $data = $result->get_error_data();
        Agentshell_Test::assert_equals( 'begin_failed', $data['reason'] ?? null, 'the refusal must say the transaction could not start' );
        Agentshell_Test::assert_equals( 'not_attempted', $data['transaction_outcome'] ?? null, 'the outcome must be "not attempted"' );
        Agentshell_Test::assert_equals( 0, as_tx_count( $db, 'COMMIT' ), 'a transaction that never started must not be committed' );
        Agentshell_Test::assert_false( Form_DB::last_was_transactional(), 'the caller must not be told it was transactional' );
    }
);

Agentshell_Test::run(
    'forms transaction: a non-transactional engine refuses a required write',
    function () {
        $db = as_tx_db( array( 'engine' => 'MyISAM' ) );

        $ran    = false;
        $result = Form_DB::run( array( 'as_tx_myisam' ), function () use ( &$ran ) {
            $ran = true;
            return 'written';
        } );

        Agentshell_Test::assert_false( $ran, 'the callback must not run on a table set that cannot roll back' );
        Agentshell_Test::assert_equals( 'form_transaction_unavailable', $result->get_error_code(), 'the refusal must be named' );
        Agentshell_Test::assert_equals(
            'non_transactional_engine',
            $result->get_error_data()['reason'] ?? null,
            'the refusal must name the engine as the reason'
        );
        Agentshell_Test::assert_equals( 0, as_tx_count( $db, 'START TRANSACTION' ), 'no transaction must be attempted' );
    }
);

Agentshell_Test::run(
    'forms transaction: without a database handle a required write is refused, not run',
    function () {
        $GLOBALS['wpdb'] = null;

        $ran    = false;
        $result = Form_DB::run( array( 'as_tx_nohandle' ), function () use ( &$ran ) {
            $ran = true;
            return 'written';
        } );

        Agentshell_Test::assert_false( $ran, 'the callback must not run without a database' );
        Agentshell_Test::assert_equals( 'form_transaction_unavailable', $result->get_error_code(), 'the refusal must be named' );
        Agentshell_Test::assert_equals(
            'no_database_handle',
            $result->get_error_data()['reason'] ?? null,
            'the refusal must say the handle was unusable'
        );

        // And optional mode still runs, because that is what "explicitly
        // permits it" means.
        $ran_optional = false;
        $optional     = Form_DB::run(
            array( 'as_tx_nohandle_opt' ),
            function () use ( &$ran_optional ) {
                $ran_optional = true;
                return 'written';
            },
            Form_DB::TRANSACTION_OPTIONAL
        );

        Agentshell_Test::assert_true( $ran_optional, 'an optional write must still run without a handle' );
        Agentshell_Test::assert_equals( 'written', $optional, 'an optional write must return its result' );
    }
);

Agentshell_Test::run(
    'forms transaction: only an explicit opt-in keeps the unprotected fallback',
    function () {
        $db = as_tx_db( array( 'engine' => 'InnoDB', 'begin' => false ) );

        $ran    = false;
        $result = Form_DB::run(
            array( 'as_tx_optional' ),
            function () use ( &$ran ) {
                $ran = true;
                return 'written';
            },
            Form_DB::TRANSACTION_OPTIONAL
        );

        Agentshell_Test::assert_true( $ran, 'an optional write must run even when the transaction was refused' );
        Agentshell_Test::assert_equals( 'written', $result, 'the result must be returned unchanged' );
        Agentshell_Test::assert_false( Form_DB::last_was_transactional(), 'the caller must be told it was NOT transactional' );
    }
);

/* ---------------------------------------------------------------------------
 * COMMIT is checked, and it is not the same as success
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms transaction: a failed COMMIT is an unknown outcome, never a success',
    function () {
        $db = as_tx_db( array( 'engine' => 'InnoDB', 'commit' => false ) );

        $result = Form_DB::run( array( 'as_tx_commit_fail' ), function () {
            return 'written';
        } );

        Agentshell_Test::assert_true( is_wp_error( $result ), 'a failed commit must not be reported as success' );
        Agentshell_Test::assert_equals( 'form_commit_uncertain', $result->get_error_code(), 'the outcome must be named as unknown' );

        $data = $result->get_error_data();
        Agentshell_Test::assert_equals( 'unknown', $data['transaction_outcome'] ?? null, 'the outcome must be recorded as unknown' );
        Agentshell_Test::assert_true(
            null !== Form_DB::last_error(),
            'the failure must be available to a caller that cannot see the return value'
        );

        // A rollback is NOT attempted after a failed commit: the transaction
        // may already be gone, and issuing ROLLBACK would suggest a certainty
        // this path does not have.
        Agentshell_Test::assert_equals( 0, as_tx_count( $db, 'ROLLBACK' ), 'a failed commit must not be followed by a rollback' );
    }
);

/* ---------------------------------------------------------------------------
 * ROLLBACK is checked too
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms transaction: a failure result is rolled back, and the rollback is confirmed',
    function () {
        $db = as_tx_db( array( 'engine' => 'InnoDB', 'rollback' => true ) );

        $error  = new WP_Error( 'form_write_failed', 'nope' );
        $result = Form_DB::run( array( 'as_tx_rollback_ok' ), function () use ( $error ) {
            return $error;
        } );

        Agentshell_Test::assert_true( $result === $error, 'the original failure must be returned when the rollback succeeded' );
        Agentshell_Test::assert_equals( 1, as_tx_count( $db, 'ROLLBACK' ), 'the failed write must be rolled back' );
        Agentshell_Test::assert_equals( 0, as_tx_count( $db, 'COMMIT' ), 'a failed write must not be committed' );
    }
);

Agentshell_Test::run(
    'forms transaction: a failed ROLLBACK reports the outcome as unknown and keeps the cause',
    function () {
        as_tx_db( array( 'engine' => 'InnoDB', 'rollback' => false ) );

        $error  = new WP_Error( 'form_write_failed', 'nope' );
        $result = Form_DB::run( array( 'as_tx_rollback_fail' ), function () use ( $error ) {
            return $error;
        } );

        Agentshell_Test::assert_true( is_wp_error( $result ), 'the caller must be told something went wrong' );
        Agentshell_Test::assert_equals( 'form_rollback_failed', $result->get_error_code(), 'the refusal must name the rollback' );

        $data = $result->get_error_data();
        Agentshell_Test::assert_equals( 'unknown', $data['transaction_outcome'] ?? null, 'the outcome must not be claimed as "nothing was written"' );
        Agentshell_Test::assert_true(
            isset( $data['cause'] ) && $data['cause'] === $error,
            'the original failure must be attached to the report'
        );
    }
);

Agentshell_Test::run(
    'forms transaction: an exception is rolled back and re-thrown, and a failed rollback is recorded',
    function () {
        as_tx_db( array( 'engine' => 'InnoDB', 'rollback' => true ) );

        $caught = '';
        try {
            Form_DB::run( array( 'as_tx_throw' ), function () {
                throw new RuntimeException( 'boom' );
            } );
        } catch ( RuntimeException $e ) {
            $caught = $e->getMessage();
        }
        Agentshell_Test::assert_equals( 'boom', $caught, 'the exception must propagate' );

        $db = as_tx_db( array( 'engine' => 'InnoDB', 'rollback' => false ) );
        $caught = '';
        try {
            Form_DB::run( array( 'as_tx_throw_rollback_fail' ), function () {
                throw new RuntimeException( 'boom again' );
            } );
        } catch ( RuntimeException $e ) {
            $caught = $e->getMessage();
        }

        Agentshell_Test::assert_equals( 'boom again', $caught, 'the exception must still propagate' );
        Agentshell_Test::assert_equals( 1, as_tx_count( $db, 'ROLLBACK' ), 'a rollback must have been attempted' );
        Agentshell_Test::assert_true( null !== Form_DB::last_error(), 'the failed rollback must be recorded for the catcher' );
        Agentshell_Test::assert_equals(
            'form_rollback_failed',
            Form_DB::last_error()->get_error_code(),
            'the recorded failure must be the rollback, not the original error'
        );
    }
);

/* ---------------------------------------------------------------------------
 * Nested calls join the open transaction
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms transaction: a nested call joins the open transaction instead of opening another',
    function () {
        $db = as_tx_db( array( 'engine' => 'InnoDB' ) );

        $inner = null;

        $outer = Form_DB::run( array( 'as_tx_nested' ), function () use ( &$inner ) {
            $inner = Form_DB::run( array( 'as_tx_nested' ), function () {
                return 'inner';
            } );
            return 'outer';
        } );

        Agentshell_Test::assert_equals( 'outer', $outer, 'the outer result must come back' );
        Agentshell_Test::assert_equals( 'inner', $inner, 'the nested result must come back' );
        Agentshell_Test::assert_equals(
            1,
            as_tx_count( $db, 'START TRANSACTION' ),
            'a nested call must not open a second transaction (which would implicitly commit the first)'
        );
        Agentshell_Test::assert_equals( 1, as_tx_count( $db, 'COMMIT' ), 'only the outer call may commit' );
        Agentshell_Test::assert_true( false === Form_DB::in_transaction(), 'the depth must return to zero' );
    }
);

Agentshell_Test::run(
    'forms transaction: in_transaction() reports a real transaction, not merely a callback',
    function () {
        // A required transaction that really opened: true inside, false after.
        as_tx_db( array( 'engine' => 'InnoDB' ) );

        $inside = null;
        $nested_inside = null;

        Form_DB::run(
            array( 'as_tx_flag_required' ),
            function () use ( &$inside, &$nested_inside ) {
                $inside = Form_DB::in_transaction();
                Form_DB::run(
                    array( 'as_tx_flag_required' ),
                    function () use ( &$nested_inside ) {
                        $nested_inside = Form_DB::in_transaction();
                        return 'nested';
                    }
                );
                return 'outer';
            }
        );

        Agentshell_Test::assert_true( true === $inside, 'inside a real transaction the answer must be true' );
        Agentshell_Test::assert_true( true === $nested_inside, 'a nested call must still be inside the same transaction' );
        Agentshell_Test::assert_false( Form_DB::in_transaction(), 'after the transaction ends the answer must be false' );

        // An optional write that got no transaction: false, because there is
        // nothing a rollback could undo.
        as_tx_db( array( 'engine' => 'InnoDB', 'begin' => false ) );

        $inside_optional = null;

        Form_DB::run(
            array( 'as_tx_flag_optional' ),
            function () use ( &$inside_optional ) {
                $inside_optional = Form_DB::in_transaction();
                return 'written';
            },
            Form_DB::TRANSACTION_OPTIONAL
        );

        Agentshell_Test::assert_false(
            (bool) $inside_optional,
            'a callback that is running without a transaction must not be reported as being inside one'
        );
    }
);

/* ---------------------------------------------------------------------------
 * Reconciliation: what an unknown commit actually did
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms transaction: reconciliation tells applied, not-applied and unprovable apart',
    function () {
        $state = array(
            'raw' => array(
                '_k_applied'     => array( 'status' => 'one', 'value' => 'old', 'meta_id' => 11 ),
                '_k_not_applied' => array( 'status' => 'one', 'value' => 'old', 'meta_id' => 12 ),
                '_k_uncertain'   => array( 'status' => 'one', 'value' => 'old', 'meta_id' => 13 ),
                '_k_noop'        => array( 'status' => 'one', 'value' => 'same', 'meta_id' => 14 ),
            ),
        );

        // Everything holds the intended value: the commit landed.
        $db = as_tx_db();
        $db->rows_by_key = array(
            '_k_applied'     => array( array( 'meta_id' => 11, 'meta_value' => 'new' ) ),
            '_k_not_applied' => array( array( 'meta_id' => 12, 'meta_value' => 'old' ) ),
            '_k_uncertain'   => array( array( 'meta_id' => 13, 'meta_value' => 'old' ) ),
            '_k_noop'        => array( array( 'meta_id' => 14, 'meta_value' => 'same' ) ),
        );

        $one = Form_DB::reconcile_writes(
            5,
            array( array( 'key' => '_k_applied', 'value' => 'new' ) ),
            $state
        );
        Agentshell_Test::assert_equals( 'applied', $one['outcome'], 'a row holding the intended value proves the commit landed' );

        $two = Form_DB::reconcile_writes(
            5,
            array( array( 'key' => '_k_not_applied', 'value' => 'new' ) ),
            $state
        );
        Agentshell_Test::assert_equals( 'not_applied', $two['outcome'], 'a row still holding the observed value proves it did not' );

        // A write that cannot discriminate — intended equals observed, and the
        // row is unchanged — proves nothing either way.
        $three = Form_DB::reconcile_writes(
            5,
            array( array( 'key' => '_k_noop', 'value' => 'same' ) ),
            $state
        );
        Agentshell_Test::assert_equals( 'uncertain', $three['outcome'], 'a no-op write is not evidence of anything' );

        // A mix proves the rows are not all from one outcome.
        $four = Form_DB::reconcile_writes(
            5,
            array(
                array( 'key' => '_k_applied', 'value' => 'new' ),
                array( 'key' => '_k_not_applied', 'value' => 'new' ),
            ),
            $state
        );
        Agentshell_Test::assert_equals( 'uncertain', $four['outcome'], 'a mix of outcomes cannot be called either one' );
    }
);
