<?php
/**
 * Documentation reference gates.
 *
 * The failure this exists to prevent
 * ----------------------------------
 * The repository-layout move and the naming migration left the maintained
 * documentation pointing at files that no longer exist. Three of the broken
 * references were links to `AGENTS.md` itself — the canonical contract —
 * from files that had moved down the tree, so the one document every other
 * document points at was unreachable from three of them. Seven more were
 * screenshots in a design record whose paths had never been right.
 *
 * A broken reference is invisible in a diff (the text changes, the file does
 * not) and it is the kind of defect that survives review, because the
 * sentence around it reads fine. The only thing that catches it is a check.
 *
 * What is asserted here
 * ---------------------
 * 1. Every **local** markdown link in the maintained documentation resolves
 *    to a file or directory that exists. External links are skipped: the
 *    check must not need the network.
 * 2. Every local link that carries an **anchor** names a heading that exists
 *    in the target file.
 * 3. Every tool name the plugins actually register appears in `AGENTS.md` §2.
 *    §2 states that its list is exhaustive; this is what keeps that claim
 *    true. Four registered tools were missing from it while the *count* was
 *    correct — the count test could not see them, because it counts, and a
 *    correct count over an incomplete list is still an incomplete list.
 *
 * Scope, and why
 * --------------
 * The checked set is the maintained documentation: the repository root, `docs/`,
 * `skills/`, `agentshell-mcp-daemon/` and the plugin directories. Three trees
 * are excluded, each for a stated reason:
 *
 *   - `docs/_archive/` — a historical archive. Its links describe the
 *     repository as it was, and rewriting them would falsify the record.
 *   - `.worktrees/` — other checkouts, not part of this repository's
 *     documentation.
 *   - `jr-mut/` — untracked local material that is not part of the project.
 *
 * `outputs/` is *not* excluded: it is tracked, and it is documentation of a
 * past run. It happens to carry no links today, and if it ever does, they
 * should be real.
 *
 * A path that is clearly a placeholder — it contains `<`, `>`, `{`, `}` or
 * `*` — is illustrative, not a reference, and is skipped. So is a target that
 * is not a path at all (`mailto:`, a bare `#anchor`, an `http(s)` URL).
 *
 * The gates read the repository's own markdown and the plugins' own
 * registration sites. They need no database and no WordPress.
 *
 * A gate that cannot fail is not a gate, so the detection functions are
 * exercised against synthetic violations at the end of this file.
 */

require_once __DIR__ . '/bootstrap.php';

echo "documentation reference tests\n";

/**
 * The repository's markdown files that are maintained documentation.
 *
 * @return array<string,string> path (repo-relative) => contents
 */
function doc_ref_files() {
    $root  = dirname( __DIR__ );
    $files = array();
    $rii   = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
    );
    foreach ( $rii as $file ) {
        if ( 'md' !== strtolower( $file->getExtension() ) ) {
            continue;
        }
        $rel = substr( $file->getPathname(), strlen( $root ) + 1 );
        foreach ( array( '.worktrees/', 'docs/_archive/', 'jr-mut/' ) as $skip ) {
            if ( 0 === strpos( $rel, $skip ) ) {
                continue 2;
            }
        }
        $files[ $rel ] = (string) file_get_contents( $file->getPathname() );
    }
    ksort( $files );
    return $files;
}

/**
 * The local markdown links in a body, with their offsets.
 *
 * Inline links and images only — `[text](target)` and `![alt](target)`. A
 * reference-style link (`[text][ref]`) is not used anywhere in this
 * repository, and a link whose target is wrapped in angle brackets is a
 * placeholder rather than a path.
 *
 * @param string $body Markdown text.
 * @return array<int,array{target:string,offset:int}>
 */
function doc_ref_links( $body ) {
    $found = array();
    if ( ! preg_match_all( '/!?\[[^\]]*\]\(\s*([^)\s]+)(?:\s+"[^"]*")?\s*\)/', $body, $m, PREG_OFFSET_CAPTURE ) ) {
        return $found;
    }
    foreach ( $m[1] as $hit ) {
        $found[] = array(
            'target' => $hit[0],
            'offset' => $hit[1],
        );
    }
    return $found;
}

/**
 * Whether a link target is something this gate should resolve.
 *
 * External schemes, protocol-relative URLs, bare anchors and placeholder
 * paths are all out of scope.
 *
 * @param string $target
 * @return bool
 */
function doc_ref_is_local_path( $target ) {
    if ( '' === $target ) {
        return false;
    }
    if ( preg_match( '#^(https?:|mailto:|tel:|ftp:|data:|//)#i', $target ) ) {
        return false;
    }
    if ( '#' === $target[0] ) {
        return false; // a bare in-page anchor
    }
    if ( preg_match( '/[<>{}*]/', $target ) ) {
        return false; // a placeholder, not a reference
    }
    return true;
}

/**
 * The anchors a markdown file defines.
 *
 * @param string $body
 * @return array<int,string>
 */
function doc_ref_anchors( $body ) {
    $anchors = array();
    foreach ( explode( "\n", (string) $body ) as $line ) {
        if ( ! preg_match( '/^#{1,6}\s+(.*?)\s*#*$/', $line, $m ) ) {
            continue;
        }
        $slug = strtolower( $m[1] );
        $slug = preg_replace( '/[^\p{L}\p{N}\- ]+/u', '', $slug );
        $slug = trim( preg_replace( '/\s+/u', ' ', $slug ) );
        $anchors[] = str_replace( ' ', '-', $slug );
    }
    return $anchors;
}

/**
 * Resolve a link's path to a location on disk.
 *
 * A relative path is resolved against the directory of the document that
 * holds the link. A path that begins with `/` is tried twice: first as
 * repository-root-relative (`/docs/x.md`), then as a literal absolute path
 * (`/home/user/x.md`) — both spellings occur in the wild, and guessing one
 * of them would produce a false failure.
 *
 * @param string $root Repository root.
 * @param string $rel  The document that holds the link (repo-relative).
 * @param string $path The link's path, without anchor or query.
 * @return string The candidate path.
 */
function doc_ref_resolve( $root, $rel, $path ) {
    if ( '/' !== $path[0] ) {
        return dirname( $root . '/' . $rel ) . '/' . $path;
    }
    $from_root = $root . $path;
    if ( file_exists( $from_root ) ) {
        return $from_root;
    }
    return $path;
}

/**
 * Local links whose target does not exist.
 *
 * @param array<string,string> $files path => contents
 * @return array<int,string> one entry per broken reference
 */
function doc_ref_broken_targets( array $files ) {
    $root    = dirname( __DIR__ );
    $offenders = array();
    foreach ( $files as $rel => $body ) {
        foreach ( doc_ref_links( $body ) as $link ) {
            $target = $link['target'];
            if ( ! doc_ref_is_local_path( $target ) ) {
                continue;
            }
            $path = $target;
            if ( false !== strpos( $path, '#' ) ) {
                $path = substr( $path, 0, strpos( $path, '#' ) );
            }
            if ( false !== strpos( $path, '?' ) ) {
                $path = strtok( $path, '?' );
            }
            if ( '' === $path ) {
                continue;
            }
            $resolved = doc_ref_resolve( $root, $rel, $path );
            if ( ! file_exists( $resolved ) ) {
                $line = substr_count( substr( $body, 0, $link['offset'] ), "\n" ) + 1;
                $offenders[] = sprintf( '%s:%d links to %s, which does not exist', $rel, $line, $target );
            }
        }
    }
    return $offenders;
}

/**
 * Local links whose anchor does not name a heading in the target file.
 *
 * @param array<string,string> $files path => contents
 * @return array<int,string>
 */
function doc_ref_broken_anchors( array $files ) {
    $root    = dirname( __DIR__ );
    $offenders = array();
    foreach ( $files as $rel => $body ) {
        foreach ( doc_ref_links( $body ) as $link ) {
            $target = $link['target'];
            if ( ! doc_ref_is_local_path( $target ) ) {
                continue;
            }
            $anchor = '';
            if ( false !== strpos( $target, '#' ) ) {
                list( , $anchor ) = explode( '#', $target, 2 );
            }
            if ( '' === $anchor ) {
                continue;
            }
            $path = substr( $target, 0, strpos( $target, '#' ) );
            if ( '' === $path ) {
                continue;
            }
            $resolved = doc_ref_resolve( $root, $rel, $path );
            if ( ! is_file( $resolved ) ) {
                continue; // the target itself is reported by the other gate
            }
            $want = strtolower( $anchor );
            $want = preg_replace( '/[^\p{L}\p{N}\- ]+/u', '', $want );
            $want = str_replace( ' ', '-', trim( $want ) );
            if ( ! in_array( $want, doc_ref_anchors( file_get_contents( $resolved ) ), true ) ) {
                $line = substr_count( substr( $body, 0, $link['offset'] ), "\n" ) + 1;
                $offenders[] = sprintf( '%s:%d links to %s, whose anchor #%s is not a heading in that file', $rel, $line, $target, $anchor );
            }
        }
    }
    return $offenders;
}

/**
 * The tool names the plugins register.
 *
 * Counted from the plugins' own registration sites — the same method
 * `tests/tool-count-test.php` uses — so the two gates cannot disagree about
 * what the surface is.
 *
 * @return array<int,string>
 */
function doc_ref_registered_tools() {
    $root = dirname( __DIR__ );
    $plugins = array(
        'agentshell-mcp'    => 'AgentShell_MCP',
        'agentshell-blocks' => 'AgentShell_Blocks',
        'agentshell-forms'  => 'AgentShell_Forms',
    );
    $names = array();
    foreach ( $plugins as $dir => $namespace ) {
        foreach ( glob( $root . "/wordpress/plugins/$dir/*.php" ) as $file ) {
            $source = file_get_contents( $file );
            $pattern = '/new\s+' . preg_quote( $namespace, '/' ) . '\\\\Tools\\\\([A-Za-z0-9_]+)\s*\(/';
            if ( ! preg_match_all( $pattern, $source, $m ) ) {
                continue;
            }
            foreach ( array_unique( $m[1] ) as $class ) {
                $class_file = $root . '/wordpress/plugins/' . $dir . '/includes/tools/class-'
                    . strtolower( str_replace( '_', '-', $class ) ) . '.php';
                if ( ! is_file( $class_file ) ) {
                    continue;
                }
                $class_source = file_get_contents( $class_file );
                if ( preg_match( "/function\s+get_name\s*\(\s*\)\s*\{\s*return\s+'([^']+)'/", $class_source, $nm ) ) {
                    $names[ $nm[1] ] = true;
                }
            }
        }
    }
    $names = array_keys( $names );
    sort( $names );
    return $names;
}

/**
 * Registered tools that `AGENTS.md` §2 does not name.
 *
 * @param array<int,string> $tools
 * @return array<int,string>
 */
function doc_ref_undocumented_tools( array $tools ) {
    $agents = file_get_contents( dirname( __DIR__ ) . '/AGENTS.md' );
    if ( ! preg_match( '/##\s*2\.\s*MCP tools.*?(?=^##\s*3\.)/ms', $agents, $m ) ) {
        return array( 'AGENTS.md §2 could not be located' );
    }
    $section = $m[0];
    $missing = array();
    foreach ( $tools as $tool ) {
        if ( false === strpos( $section, $tool ) ) {
            $missing[] = $tool;
        }
    }
    return $missing;
}

$doc_ref_files = doc_ref_files();

// ── 1. local links resolve ────────────────────────────────────────────────
Agentshell_Test::run(
    'the gate reads a non-empty set of markdown files',
    function () use ( $doc_ref_files ) {
        Agentshell_Test::assert_true(
            count( $doc_ref_files ) > 5,
            'the gate must actually read the repository, not an empty set'
        );
    }
);

Agentshell_Test::run(
    'every local markdown link resolves to something that exists',
    function () use ( $doc_ref_files ) {
        $offenders = doc_ref_broken_targets( $doc_ref_files );
        Agentshell_Test::assert_equals(
            array(),
            $offenders,
            "these documentation references point at nothing:\n  " . implode( "\n  ", $offenders )
        );
    }
);

// ── 2. anchors name real headings ─────────────────────────────────────────
Agentshell_Test::run(
    'every local markdown link with an anchor names a real heading',
    function () use ( $doc_ref_files ) {
        $offenders = doc_ref_broken_anchors( $doc_ref_files );
        Agentshell_Test::assert_equals(
            array(),
            $offenders,
            "these documentation anchors do not exist:\n  " . implode( "\n  ", $offenders )
        );
    }
);

// ── 3. AGENTS.md §2 names every registered tool ───────────────────────────
Agentshell_Test::run(
    'AGENTS.md §2 names every tool the plugins register',
    function () {
        $tools   = doc_ref_registered_tools();
        $missing = doc_ref_undocumented_tools( $tools );
        Agentshell_Test::assert_equals(
            array(),
            $missing,
            'these registered tools are not named in AGENTS.md §2: ' . implode( ', ', $missing )
        );
    }
);

Agentshell_Test::run(
    'the registry this gate reads is the same one the count test reads',
    function () {
        $tools = doc_ref_registered_tools();
        Agentshell_Test::assert_true(
            count( $tools ) > 50,
            'the gate must find the real tool surface, not an empty one'
        );
        Agentshell_Test::assert_true(
            in_array( 'agentshell_inspect', $tools, true ),
            'a known tool must be found'
        );
    }
);

// ── 4. the gates themselves can fail ──────────────────────────────────────
Agentshell_Test::run(
    'the broken-target gate catches a missing file',
    function () {
        $synthetic = array(
            'notes.md' => "See [the contract](./AGENTS.md) and [a gap](./nope.md).\n",
        );
        $offenders = doc_ref_broken_targets( $synthetic );
        Agentshell_Test::assert_equals(
            1,
            count( $offenders ),
            'a link to a file that does not exist must be caught'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $offenders[0], 'nope.md' ),
            'the offender must name the broken target: ' . $offenders[0]
        );
    }
);

Agentshell_Test::run(
    'the broken-target gate ignores external and placeholder links',
    function () {
        $synthetic = array(
            'notes.md' => "An [external](https://example.com/x), a [mail](mailto:a@b.c), "
                . "a [placeholder](<REPO_PATH>/thing.md) and a [glob](*). "
                . "A [bare anchor](#somewhere).\n",
        );
        Agentshell_Test::assert_equals(
            array(),
            doc_ref_broken_targets( $synthetic ),
            'external, placeholder and in-page links are not repository references'
        );
    }
);

Agentshell_Test::run(
    'the broken-anchor gate catches an anchor that is not a heading',
    function () {
        $root = dirname( __DIR__ );
        $target = $root . '/AGENTS.md';
        $synthetic = array(
            'notes.md' => "A [real](#2-mcp-tools-authoritative-count-81) and a [fake](#no-such-heading) anchor.\n",
        );
        // Point the link at a file that certainly exists.
        $synthetic['notes.md'] = str_replace( '#', $target . '#', $synthetic['notes.md'] );
        $offenders = doc_ref_broken_anchors( $synthetic );
        Agentshell_Test::assert_equals(
            1,
            count( $offenders ),
            'an anchor that names no heading must be caught'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $offenders[0], 'no-such-heading' ),
            'the offender must name the broken anchor: ' . $offenders[0]
        );
    }
);

Agentshell_Test::run(
    'the undocumented-tool gate catches a tool §2 does not name',
    function () {
        $missing = doc_ref_undocumented_tools( array( 'agentshell_inspect', 'agentshell_not_a_real_tool' ) );
        Agentshell_Test::assert_equals(
            array( 'agentshell_not_a_real_tool' ),
            $missing,
            'a registered tool missing from §2 must be caught, and only that tool'
        );
    }
);
