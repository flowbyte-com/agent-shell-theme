<?php
/**
 * Isolation helpers for the mutation harnesses.
 *
 * Why this exists
 * ---------------
 * The mutation harnesses used to write their mutants straight into the
 * production checkout — `agentshell-mcp/includes/class-screenshot.php` and
 * `functions.php` — and restore them afterwards. That works right up until it
 * does not: a second test run started while a mutant was in place reads
 * mutated production code and reports failures that belong to nobody, and a
 * harness that is killed between the write and the restore leaves the
 * checkout modified. Both happened. A test that can damage the thing it is
 * testing is not a test.
 *
 * What this provides
 * ------------------
 *  - `agentshell_mutation_sandbox_create()` — a throwaway copy of the
 *    repository in the system temp directory. Mutants are written there and
 *    the suites run from there, so the working tree is never opened for
 *    writing. Suites resolve their sources through `dirname( __DIR__ )`, so a
 *    copied tree is a drop-in: no suite had to learn about isolation.
 *  - `agentshell_mutation_sandbox_destroy()` — recursive removal, wired to a
 *    shutdown function so a fatal error, a signal, or `exit()` still cleans up.
 *  - `agentshell_repo_snapshot()` / `agentshell_repo_diff()` — a content
 *    fingerprint of the checkout, so a harness can prove it left the tree
 *    alone instead of assuming it did.
 *
 * Nothing in this file is used by the ordinary test suites. It is test
 * infrastructure for test infrastructure.
 *
 * Usage:
 *   require_once __DIR__ . '/mutation-sandbox.php';
 *   $sandbox = agentshell_mutation_sandbox_create();
 *   file_put_contents( $sandbox . '/agentshell-mcp/includes/class-screenshot.php', $mutant );
 *   // ... run the suite from $sandbox ...
 *   agentshell_mutation_sandbox_destroy( $sandbox );
 */

if ( ! function_exists( 'agentshell_repo_root' ) ) {
    /** Absolute path of the checkout these helpers protect. */
    function agentshell_repo_root() {
        return dirname( __DIR__ );
    }
}

/**
 * Directory names never copied into a sandbox and never fingerprinted.
 *
 * `.git` is the checkout's history, not its source: copying it would triple
 * the cost of every mutant for no benefit, and hashing it would report noise
 * from every concurrent git operation. `node_modules` is the same idea.
 */
function agentshell_repo_skip_dirs() {
    return array( '.git', 'node_modules', '.venv' );
}

/**
 * Recursive copy used to build a sandbox.
 *
 * Symlinks are copied as symlinks, so a sandboxed tree behaves like the real
 * one for anything that resolves paths; a link pointing outside the sandbox
 * still points where it always did, which is the honest result.
 *
 * @param string   $src      Directory to copy.
 * @param string   $dst      Destination directory (created if absent).
 * @param string[] $skip_dir Absolute directory names to omit.
 * @return int Number of files copied.
 */
function agentshell_rcopy( $src, $dst, array $skip_dir = array() ) {
    $copied = 0;
    if ( ! is_dir( $dst ) && ! @mkdir( $dst, 0700, true ) && ! is_dir( $dst ) ) {
        return 0;
    }
    $items = scandir( $src );
    if ( false === $items ) {
        return 0;
    }
    foreach ( $items as $item ) {
        if ( '.' === $item || '..' === $item || in_array( $item, $skip_dir, true ) ) {
            continue;
        }
        $from = $src . '/' . $item;
        $to   = $dst . '/' . $item;

        if ( is_link( $from ) ) {
            $target = readlink( $from );
            @unlink( $to );
            @symlink( $target, $to );
            continue;
        }
        if ( is_dir( $from ) ) {
            $copied += agentshell_rcopy( $from, $to, $skip_dir );
            continue;
        }
        if ( @copy( $from, $to ) ) {
            $copied++;
        }
    }
    return $copied;
}

/**
 * Remove a directory tree. Safe to call on a path that does not exist.
 */
function agentshell_rmtree( $path ) {
    if ( is_link( $path ) ) {
        @unlink( $path );
        return;
    }
    if ( ! is_dir( $path ) ) {
        @unlink( $path );
        return;
    }
    foreach ( (array) scandir( $path ) as $item ) {
        if ( '.' === $item || '..' === $item ) {
            continue;
        }
        agentshell_rmtree( $path . '/' . $item );
    }
    @rmdir( $path );
}

/**
 * Build a throwaway copy of the checkout.
 *
 * The copy is registered for shutdown cleanup before any of it is made, so a
 * failure part way through still leaves nothing behind.
 *
 * @return string Absolute path of the sandbox root.
 */
function agentshell_mutation_sandbox_create() {
    $base = rtrim( sys_get_temp_dir(), '/' ) . '/as-mutant-sandbox-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
    $root = $base . '/repo';

    register_shutdown_function( function () use ( $base ) {
        // Runs on normal exit, on exit() from a fatal handler, and on a signal
        // PHP handles. Best effort: if this cannot remove the tree, the
        // fingerprint check below is what notices.
        if ( is_dir( $base ) ) {
            agentshell_rmtree( $base );
        }
    } );

    @mkdir( $root, 0700, true );
    agentshell_rcopy( agentshell_repo_root(), $root, agentshell_repo_skip_dirs() );

    return $root;
}

/**
 * Remove a sandbox created by agentshell_mutation_sandbox_create().
 */
function agentshell_mutation_sandbox_destroy( $root ) {
    $base = dirname( $root );
    if ( is_dir( $base ) ) {
        agentshell_rmtree( $base );
    }
}

/**
 * Fingerprint the checkout: every regular file's path => sha256.
 *
 * @param string[] $extra Absolute paths to include even if the walk skipped
 *                        them (not currently needed, but keeps the signature
 *                        open for callers that watch one specific file).
 * @return array<string,string>
 */
function agentshell_repo_snapshot( array $extra = array() ) {
    $skip   = agentshell_repo_skip_dirs();
    $root   = agentshell_repo_root();
    $result = array();

    $walk = function ( $dir ) use ( &$walk, &$result, $skip ) {
        foreach ( (array) scandir( $dir ) as $item ) {
            if ( '.' === $item || '..' === $item || in_array( $item, $skip, true ) ) {
                continue;
            }
            $path = $dir . '/' . $item;
            if ( is_link( $path ) ) {
                $result[ $path ] = 'link:' . readlink( $path );
                continue;
            }
            if ( is_dir( $path ) ) {
                $walk( $path );
                continue;
            }
            $hash = @hash_file( 'sha256', $path );
            if ( false !== $hash ) {
                $result[ $path ] = $hash;
            }
        }
    };
    $walk( $root );

    foreach ( $extra as $path ) {
        $hash = @hash_file( 'sha256', $path );
        $result[ $path ] = false === $hash ? 'missing' : $hash;
    }

    ksort( $result );
    return $result;
}

/**
 * Compare a fingerprint against the checkout as it is now.
 *
 * @param array    $before Fingerprint from agentshell_repo_snapshot().
 * @param string[] $watch  Optional subset of paths to report on.
 * @return array{changed:string[],added:string[],removed:string[]} Empty arrays
 *         when the checkout is untouched.
 */
function agentshell_repo_diff( array $before, array $watch = array() ) {
    $after   = agentshell_repo_snapshot();
    $changed = array();
    $added   = array();
    $removed = array();

    foreach ( $after as $path => $hash ) {
        if ( ! array_key_exists( $path, $before ) ) {
            $added[] = $path;
        } elseif ( $before[ $path ] !== $hash ) {
            $changed[] = $path;
        }
    }
    foreach ( $before as $path => $hash ) {
        if ( ! array_key_exists( $path, $after ) ) {
            $removed[] = $path;
        }
    }

    if ( $watch ) {
        $keep = function ( array $list ) use ( $watch ) {
            $out = array();
            foreach ( $list as $path ) {
                foreach ( $watch as $w ) {
                    if ( $path === $w ) {
                        $out[] = $path;
                        break;
                    }
                }
            }
            return $out;
        };
        $changed = $keep( $changed );
        $added   = $keep( $added );
        $removed = $keep( $removed );
    }

    return array( 'changed' => $changed, 'added' => $added, 'removed' => $removed );
}

/**
 * Run a suite in a child process and count its failing assertions.
 *
 * The count keys on FAIL lines at line start, because the reporter indents
 * them: a plain "\nFAIL " substring count reads zero and would report every
 * mutant as surviving.
 *
 * @param string   $suite_file Absolute path of the suite to run.
 * @param string[] $env        Environment variables for the child.
 * @return array{output:string,failed:int,rc:int}
 */
function agentshell_mutation_run_suite( $suite_file, array $env = array() ) {
    $cmd = '';
    foreach ( $env as $k => $v ) {
        $cmd .= $k . '=' . escapeshellarg( $v ) . ' ';
    }
    $cmd .= escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $suite_file ) . ' 2>&1';

    $output = array();
    $rc     = 0;
    exec( $cmd, $output, $rc );
    $text = implode( "\n", $output );

    return array(
        'output' => $text,
        'failed' => preg_match_all( '/^\s*FAIL /m', $text ),
        'rc'     => $rc,
    );
}

/**
 * Lint a mutant before it is allowed to count as evidence.
 *
 * A parse error makes every assertion fail, which a naive kill check would
 * score as a kill for the wrong reason. Returns the lint output and status.
 *
 * @return array{output:string,rc:int}
 */
function agentshell_mutation_lint( $code ) {
    // tempnam() already creates the file it returns, so appending '.php' names
    // a *second* path. Both have to be removed or every mutant leaves an
    // orphan behind.
    $tmp       = tempnam( sys_get_temp_dir(), 'as-mutant-lint-' );
    $lint_file = $tmp . '.php';
    file_put_contents( $lint_file, $code );

    $out = array();
    $rc  = 0;
    exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $lint_file ) . ' 2>&1', $out, $rc );

    @unlink( $lint_file );
    @unlink( $tmp );

    return array( 'output' => implode( "\n", $out ), 'rc' => $rc );
}