<?php
/**
 * Refuse a tree that contains external-sync conflicted copies.
 *
 * ## What this protects against
 *
 * A desktop sync client (Dropbox, Nextcloud, iCloud Drive) resolves a
 * concurrent edit by writing a second file — `name [conflicted].php`,
 * `name [conflicted 2].php` — and, in the failure mode this guard exists for,
 * **removes the original name**. The working tree is then missing a tracked
 * file and contains an untracked lookalike.
 *
 * `git add -A` records exactly that: the tracked file as deleted, the
 * lookalike as added. The next CI run fails somewhere far away (a
 * `require_once` that cannot open a file), and the real cause — a sync
 * conflict — is invisible in the diff.
 *
 * The guard refuses to proceed while any such copy exists, and it refuses
 * when a copy stands where a tracked file should be. It never deletes
 * anything: a conflicted copy may be the only copy of somebody's work.
 *
 * Usage:
 *   php tools/check-sync-conflicts.php          # exit 1 when the tree is unsafe
 */

$root = dirname( __DIR__ );

$skip = array(
    '.git',
    '.worktrees',
    'node_modules',
    'vendor',
);

$found = array();

$walk = function ( $dir ) use ( &$walk, &$found, $skip, $root ) {
    $entries = @scandir( $dir );

    if ( ! is_array( $entries ) ) {
        return;
    }

    foreach ( $entries as $entry ) {
        if ( '.' === $entry || '..' === $entry ) {
            continue;
        }

        $path = $dir . '/' . $entry;
        $relative = ltrim( substr( $path, strlen( $root ) ), '/' );

        if ( in_array( $relative, $skip, true ) ) {
            continue;
        }

        if ( is_dir( $path ) ) {
            $walk( $path );
            continue;
        }

        if ( false !== strpos( $entry, '[conflicted' ) ) {
            $found[] = $relative;
        }
    }
};

$walk( $root );

if ( empty( $found ) ) {
    echo "sync-conflict guard: ok (no conflicted copies)\n";
    exit( 0 );
}

$bad = false;

foreach ( $found as $relative ) {
    // The name the copy shadows: strip " [conflicted...]" and any extension
    // the sync client added after it.
    $target = preg_replace( '/ \[conflicted[^\]]*\](?=(\.[^.]+)?$)/', '', $relative );

    $target_exists = file_exists( $root . '/' . $target );
    $target_tracked = false;

    exec( 'git -C ' . escapeshellarg( $root ) . ' cat-file -e ' . escapeshellarg( 'HEAD:' . $target ) . ' 2>/dev/null', $out, $status );
    $target_tracked = 0 === $status;

    if ( ! $target_exists && $target_tracked ) {
        echo "sync-conflict guard: REFUSED — {$relative} stands where the tracked file {$target} should be.\n";
        echo "  The tracked file is missing. Restore it before staging:\n";
        echo "    git checkout HEAD -- " . escapeshellarg( $target ) . "\n";
        echo "  Then decide what to do with the conflicted copy (it may hold work).\n";
        $bad = true;
        continue;
    }

    echo "sync-conflict guard: REFUSED — unresolved conflicted copy: {$relative}\n";
    $bad = true;
}

if ( $bad ) {
    exit( 1 );
}

echo "sync-conflict guard: ok\n";
exit( 0 );
