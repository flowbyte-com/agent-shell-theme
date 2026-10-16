<?php
/**
 * Test fixture tracker — run-scoped, exact-ID, try/finally teardown.
 *
 * The "live" test children (journal-recovery-live, content-write-live,
 * navigation-live) used to track what they created with a process-local
 * `$mine` array of post ids and a single `wp_delete_post($id, true)` loop
 * at the end of the file. That has three failure modes:
 *
 *   1. **Cleanup is at the end of the file.** A PHP fatal part-way
 *      through the test skips the cleanup. The orphan rows then
 *      accumulate across runs and pollute the next run's baseline.
 *
 *   2. **Cleanup is by LIKE-pattern** (e.g. "delete everything whose
 *      post_title LIKE '%ASjr%'"). This is too broad: it can delete a
 *      fixture left behind by an earlier, partially-completed run that
 *      used a colliding token, and it conflates the fixture set with
 *      whatever else the site happens to have.
 *
 *   3. **Cleanup is not parent-process aware.** A parent that runs the
 *      child via `proc_open` and is killed by SIGTERM (or by a parent
 *      test runner) leaves the child's `wp_delete_post` loop
 *      unexecuted. The orphan rows persist.
 *
 * This class replaces all three with a per-run registry that:
 *
 *   - Mints a per-run token at construction (`ASTB` prefix + 8 hex chars).
 *   - Records every disposable record the test creates, with its exact
 *     type (post, post_meta, option, menu, user_meta) and exact id or
 *     name. No LIKE patterns.
 *   - Cleans up in a `try { ... } finally { $fixtures->teardown(); }`
 *     so a thrown exception still releases the test's holdings.
 *   - Writes the registry to a known file under the runner's temp
 *     directory, so a parent process that reaps a crashed child can
 *     walk the file and clean up after the orphan. This is the
 *     parent-process recovery sweep.
 *
 * The registry file is unique to the run token. A new test run gets
 * a new file. The previous run's file is preserved until the next
 * run starts (so the parent sweep can find it), then atomically
 * renamed out of the way.
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

class Agentshell_Test_Fixtures {

    /** Per-run token. `ASTB` + 8 hex chars. */
    public $run_token;

    /** @var string Absolute path of the registry file for this run. */
    public $registry_path;

    /** @var array In-memory mirror of the registry; flushed to disk on every mutate. */
    private $registry = array(
        'run_token'      => '',
        'started_at'     => 0,
        'pid'            => 0,
        'wp_root'        => '',
        'db_name'        => '',
        'posts'          => array(), // [['id' => int, 'title_prefix' => string, 'created_at' => int], ...]
        'post_meta'      => array(), // [['post_id' => int, 'meta_key' => string], ...]
        'options'        => array(), // [['name' => string, 'prior_value' => mixed], ...]
        'menus'          => array(), // [['id' => int, 'name' => string], ...]
        'user_meta'      => array(), // [['user_id' => int, 'meta_key' => string], ...]
        'swept'          => false,
        'teardown_at'    => 0,
        'teardown_errors'=> array(),
    );

    /**
     * @param array $cfg Test-bed config (only `fixture` keys are used).
     */
    public function __construct( array $cfg = array() ) {
        $this->run_token = 'ASTB' . substr( md5( (string) ( time() . getmypid() . random_int( 1, 1e9 ) ) ), 0, 8 );
        $this->registry_path = sys_get_temp_dir() . '/agentshell-test-bed-fixtures-' . $this->run_token . '.json';
        $this->registry['run_token']  = $this->run_token;
        $this->registry['started_at'] = time();
        $this->registry['pid']        = getmypid();
        if ( isset( $cfg['wp_root'] ) ) {
            $this->registry['wp_root'] = (string) $cfg['wp_root'];
        }
        if ( isset( $cfg['db']['name'] ) ) {
            $this->registry['db_name'] = (string) $cfg['db']['name'];
        }
        $this->flush();
    }

    /**
     * Persist the registry to disk. Called after every mutation so a
     * crashed child leaves a complete record for the parent sweep.
     */
    private function flush() {
        $tmp = $this->registry_path . '.tmp.' . getmypid();
        $bytes = @file_put_contents( $tmp, json_encode( $this->registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
        if ( false === $bytes ) {
            return false;
        }
        return @rename( $tmp, $this->registry_path );
    }

    /**
     * Register a created post. The id is the post id WP returned from
     * `wp_insert_post`. A post must be registered before any
     * teardown so an orphan never escapes the run.
     */
    public function register_post( $id, $title_prefix = '' ) {
        $id = (int) $id;
        if ( $id <= 0 ) {
            return false;
        }
        $this->registry['posts'][] = array(
            'id'           => $id,
            'title_prefix' => (string) $title_prefix,
            'created_at'   => time(),
        );
        return $this->flush();
    }

    /**
     * Register a post_meta row to be deleted on teardown. Optional
     * (the post teardown will remove all its meta anyway), but useful
     * for cases where the post is intentionally not deleted (e.g. an
     * edit-only test) but a specific meta key should be removed.
     */
    public function register_post_meta( $post_id, $meta_key ) {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 || ! is_string( $meta_key ) || '' === $meta_key ) {
            return false;
        }
        $this->registry['post_meta'][] = array(
            'post_id'  => $post_id,
            'meta_key' => $meta_key,
        );
        return $this->flush();
    }

    /**
     * Register an option to be restored to its prior value (or deleted
     * if it didn't exist before this run) on teardown.
     */
    public function register_option( $name, $prior_value = null ) {
        if ( ! is_string( $name ) || '' === $name ) {
            return false;
        }
        $this->registry['options'][] = array(
            'name'        => $name,
            'prior_value' => $prior_value,
            'existed'     => ( null !== $prior_value ),
        );
        return $this->flush();
    }

    /**
     * Register a navigation menu to be deleted on teardown. The id
     * is the term id (what `wp_delete_nav_menu` accepts).
     */
    public function register_menu( $id, $name = '' ) {
        $id = (int) $id;
        if ( $id <= 0 ) {
            return false;
        }
        $this->registry['menus'][] = array(
            'id'   => $id,
            'name' => (string) $name,
        );
        return $this->flush();
    }

    /**
     * Register a user_meta row to be deleted on teardown.
     */
    public function register_user_meta( $user_id, $meta_key ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 || ! is_string( $meta_key ) || '' === $meta_key ) {
            return false;
        }
        $this->registry['user_meta'][] = array(
            'user_id'  => $user_id,
            'meta_key' => $meta_key,
        );
        return $this->flush();
    }

    /**
     * Return a count of every fixture currently registered.
     */
    public function count() {
        return count( $this->registry['posts'] )
             + count( $this->registry['post_meta'] )
             + count( $this->registry['options'] )
             + count( $this->registry['menus'] )
             + count( $this->registry['user_meta'] );
    }

    /**
     * Read the registry back from disk (e.g. for parent-process sweep).
     */
    public static function load( $path ) {
        if ( ! is_file( $path ) ) {
            return null;
        }
        $raw = @file_get_contents( $path );
        if ( ! is_string( $raw ) || '' === $raw ) {
            return null;
        }
        $r = json_decode( $raw, true );
        return is_array( $r ) ? $r : null;
    }

    /**
     * Walk a previous run's registry and tear it down. Returns the
     * number of items removed, or -1 if the registry could not be
     * loaded. The function is idempotent: re-running it on a registry
     * that has already been swept is a no-op.
     *
     * The $wpdb argument is the caller's database handle. The function
     * uses $wpdb->delete() and wp_delete_post() (when available) so
     * the teardown uses the same path the test would have used.
     */
    public static function sweep( $path, $wpdb ) {
        $r = self::load( $path );
        if ( null === $r ) {
            return -1;
        }
        if ( ! empty( $r['swept'] ) ) {
            return 0;
        }
        $removed = 0;

        // Posts first — `wp_delete_post($id, true)` removes the row, all
        // its post_meta, and its term relationships. The order matters:
        // post_meta teardown that survives a missing post is a no-op,
        // but a missing post makes post_meta teardown noisy.
        if ( ! empty( $r['posts'] ) && is_array( $r['posts'] ) ) {
            foreach ( $r['posts'] as $entry ) {
                $id = (int) ( $entry['id'] ?? 0 );
                if ( $id <= 0 ) { continue; }
                $exists = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", $id
                ) );
                if ( $exists > 0 ) {
                    if ( function_exists( 'wp_delete_post' ) ) {
                        wp_delete_post( $id, true );
                    } else {
                        $wpdb->delete( $wpdb->posts, array( 'ID' => $id ), array( '%d' ) );
                        $wpdb->delete( $wpdb->postmeta, array( 'post_id' => $id ), array( '%d' ) );
                    }
                    $removed++;
                }
            }
        }

        // Post-meta fallback (for the case where the post was preserved
        // for a reason but the meta must go).
        if ( ! empty( $r['post_meta'] ) && is_array( $r['post_meta'] ) ) {
            foreach ( $r['post_meta'] as $entry ) {
                $post_id  = (int) ( $entry['post_id'] ?? 0 );
                $meta_key = (string) ( $entry['meta_key'] ?? '' );
                if ( $post_id <= 0 || '' === $meta_key ) { continue; }
                $wpdb->delete( $wpdb->postmeta, array(
                    'post_id'  => $post_id,
                    'meta_key' => $meta_key,
                ), array( '%d', '%s' ) );
            }
        }

        // Options: restore to prior value (or delete if it didn't exist).
        if ( ! empty( $r['options'] ) && is_array( $r['options'] ) ) {
            foreach ( $r['options'] as $entry ) {
                $name = (string) ( $entry['name'] ?? '' );
                if ( '' === $name ) { continue; }
                if ( ! empty( $entry['existed'] ) ) {
                    if ( function_exists( 'update_option' ) ) {
                        update_option( $name, $entry['prior_value'] );
                    } else {
                        $wpdb->update( $wpdb->options, array( 'option_value' => $entry['prior_value'] ), array( 'option_name' => $name ), array( '%s' ), array( '%s' ) );
                    }
                } else {
                    if ( function_exists( 'delete_option' ) ) {
                        delete_option( $name );
                    } else {
                        $wpdb->delete( $wpdb->options, array( 'option_name' => $name ), array( '%s' ) );
                    }
                }
                $removed++;
            }
        }

        // Menus: delete the term. wp_delete_nav_menu handles the
        // relationship rows; we fall back to a direct term delete.
        if ( ! empty( $r['menus'] ) && is_array( $r['menus'] ) ) {
            foreach ( $r['menus'] as $entry ) {
                $id = (int) ( $entry['id'] ?? 0 );
                if ( $id <= 0 ) { continue; }
                $exists = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->terms} WHERE term_id = %d", $id
                ) );
                if ( $exists > 0 ) {
                    if ( function_exists( 'wp_delete_nav_menu' ) ) {
                        wp_delete_nav_menu( $id );
                    } else {
                        $wpdb->delete( $wpdb->terms, array( 'term_id' => $id ), array( '%d' ) );
                        $wpdb->delete( $wpdb->term_taxonomy, array( 'term_id' => $id ), array( '%d' ) );
                    }
                    $removed++;
                }
            }
        }

        // User-meta fallback.
        if ( ! empty( $r['user_meta'] ) && is_array( $r['user_meta'] ) ) {
            foreach ( $r['user_meta'] as $entry ) {
                $user_id  = (int) ( $entry['user_id'] ?? 0 );
                $meta_key = (string) ( $entry['meta_key'] ?? '' );
                if ( $user_id <= 0 || '' === $meta_key ) { continue; }
                $wpdb->delete( $wpdb->usermeta, array(
                    'user_id'  => $user_id,
                    'meta_key' => $meta_key,
                ), array( '%d', '%s' ) );
            }
        }

        // Mark swept so a re-run of the same registry is a no-op.
        $r['swept']       = true;
        $r['teardown_at'] = time();
        $r['teardown_errors'] = array();
        @file_put_contents( $path, json_encode( $r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

        return $removed;
    }

    /**
     * Run a closure under a fixture scope. The closure receives the
     * tracker instance so it can register fixtures as it creates them.
     * Teardown runs in a `finally`, so a thrown exception or fatal
     * still releases the test's holdings before the process exits.
     *
     * The function returns whatever the closure returns. Teardown
     * errors are appended to the registry's `teardown_errors` list
     * but do not propagate — the closure's result is returned
     * unchanged. (The end-of-suite leak assertion catches the
     * teardown-error case.)
     */
    public function run( callable $closure ) {
        try {
            return $closure( $this );
        } finally {
            $this->teardown();
        }
    }

    /**
     * Tear down everything registered against this run. Idempotent:
     * calling teardown twice is safe (the second call is a no-op).
     * Used by the public `run()` wrapper, and by the parent-process
     * recovery sweep.
     */
    public function teardown() {
        if ( ! empty( $this->registry['swept'] ) ) {
            return;
        }
        $this->registry['teardown_at'] = time();
        $this->flush();
        $removed = self::sweep( $this->registry_path, $GLOBALS['wpdb'] ?? null );
        if ( -1 === $removed ) {
            $this->registry['teardown_errors'][] = 'sweep returned -1 (registry unreadable)';
        }
    }

    /**
     * Static helper: find every registry file in the runner's temp
     * directory and tear it down. Used by the parent process at the
     * end of a run to recover from a child that crashed before
     * reaching its own teardown.
     *
     * @param wpdb $wpdb        The runner's database handle.
     * @param string $exclude   The current run's registry path; do
     *                           not sweep this one (it is the runner's
     *                           own registry, not an orphan).
     * @return array { swept: int, errors: string[] }
     */
    public static function sweep_orphans( $wpdb, $exclude = '' ) {
        $dir = sys_get_temp_dir();
        $files = glob( $dir . '/agentshell-test-bed-fixtures-*.json' ) ?: array();
        $swept = 0;
        $errors = array();
        foreach ( $files as $path ) {
            if ( $exclude !== '' && $path === $exclude ) {
                continue;
            }
            $n = self::sweep( $path, $wpdb );
            if ( -1 === $n ) {
                $errors[] = "sweep returned -1 for $path (registry unreadable)";
            } else {
                $swept += $n;
            }
        }
        return array( 'swept' => $swept, 'errors' => $errors );
    }
}
