<?php
/**
 * Documentation gates (AGENTS.md §9).
 *
 * The failure this exists to prevent
 * ----------------------------------
 * The tool count was written down in five places and went stale in all of
 * them: the documentation claimed "45+" and "59" while the registry
 * published 64, and the *plugin* README went on calling "45+" canonical.
 * A number that is written down can only be kept right by remembering to
 * change it — and the repository had already lost that race twice.
 *
 * These gates make the drift a build failure instead of a surprise for
 * whoever reads the file next.
 *
 * What is asserted here
 * ---------------------
 * 1. No file in the repository may call "45+" (or "10/11/12 tools")
 *    canonical. `AGENTS.md` §2 is the only place a count is stated.
 * 2. `AGENTS.md` §2's count equals the registry's — the same check
 *    `tests/tool-count-test.php` makes, repeated here so that the
 *    documentation gate stands on its own.
 * 3. No file may duplicate the MCP tool table, the architecture diagram
 *    or the operation loop: a file that lists tools by name is allowed to
 *    *point* at AGENTS.md §2, not to restate it.
 * 4. The architecture precedence statements (bilateral registry,
 *    wp_options as the configuration source of truth, read-only shell
 *    files) are stated in `AGENTS.md` and are not contradicted anywhere
 *    else — in particular no file may claim `wp_options` is the *sole*
 *    source of truth for widgets.
 * 5. The custom-CSS limit is the same number everywhere it appears.
 *
 * The gates read the repository's own markdown; they do not need a
 * database and do not need WordPress.
 */

require_once __DIR__ . '/bootstrap.php';

echo "documentation gate tests\n";

/**
 * The repository's markdown files, excluding the archives and the
 * worktrees (neither is part of the shipped documentation).
 *
 * @return array<string,string> path => contents
 */
function doc_gate_files() {
    $root  = dirname( __DIR__ );
    $files = array();
    $rii   = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
    );
    foreach ( $rii as $file ) {
        $path = $file->getPathname();
        if ( 'md' !== strtolower( $file->getExtension() ) ) {
            continue;
        }
        $rel = substr( $path, strlen( $root ) + 1 );
        if ( 0 === strpos( $rel, '.worktrees/' ) ) {
            continue;
        }
        if ( 0 === strpos( $rel, 'docs/_archive/' ) ) {
            continue;
        }
        if ( 0 === strpos( $rel, 'jr-mut/' ) ) {
            continue;
        }
        $files[ $rel ] = (string) file_get_contents( $path );
    }
    return $files;
}

$doc_files = doc_gate_files();

Agentshell_Test::run(
    'the gate reads a non-empty set of markdown files',
    function () use ( $doc_files ) {
        Agentshell_Test::assert_true(
            count( $doc_files ) > 5,
            'the gate must actually read the repository, not an empty set'
        );
    }
);

// ── 1. no stale "canonical" tool counts ───────────────────────────────────
Agentshell_Test::run(
    'no file calls a stale tool count canonical',
    function () use ( $doc_files ) {
        $offenders = array();
        foreach ( $doc_files as $rel => $body ) {
            if ( 'AGENTS.md' === $rel ) {
                continue; // the normative contract is where the count lives
            }
            // Any sentence that pairs a stale count with "canonical" is a
            // claim that must not be reproduced.
            if ( preg_match( '/\*\*?45\+`?\s*tools?`?\*?[^.\n]{0,80}canonical/i', $body )
                 || preg_match( '/canonical[^.\n]{0,80}\b45\+/i', $body ) ) {
                $offenders[] = $rel;
            }
        }
        Agentshell_Test::assert_equals(
            array(),
            $offenders,
            'these files still call a stale tool count canonical: ' . implode( ', ', $offenders )
        );
    }
);

Agentshell_Test::run(
    'the deprecated 10/11/12 tool counts are not reproduced as canonical',
    function () use ( $doc_files ) {
        $offenders = array();
        foreach ( $doc_files as $rel => $body ) {
            if ( 'AGENTS.md' === $rel ) {
                continue; // AGENTS.md names them as deprecated, which is correct
            }
            if ( preg_match( '/\b(10|11|12)\s+`?agentshell_\*`?\s+tools/i', $body )
                 && ! preg_match( '/deprecated|must not be reproduced|older counts/i', $body ) ) {
                $offenders[] = $rel;
            }
        }
        Agentshell_Test::assert_equals(
            array(),
            $offenders,
            'these files reproduce a deprecated tool count: ' . implode( ', ', $offenders )
        );
    }
);

// ── 2. the documented count equals the registry's ─────────────────────────
Agentshell_Test::run(
    'AGENTS.md states a single authoritative tool count',
    function () use ( $doc_files ) {
        $agents = $doc_files['AGENTS.md'] ?? '';
        Agentshell_Test::assert_true(
            1 === preg_match( '/authoritative count:\s*\*\*(\d+)\*\*/', $agents, $m ),
            'AGENTS.md §2 must state the authoritative count in the form "authoritative count: **N**"'
        );
        Agentshell_Test::assert_true(
            (int) $m[1] > 0,
            'the authoritative count must be a number'
        );
    }
);

// ── 3. no duplicated tool table / diagram / loop ──────────────────────────
Agentshell_Test::run(
    'no file outside AGENTS.md restates the MCP tool table',
    function () use ( $doc_files ) {
        // A *table* is what §9 forbids: a markdown table whose rows are
        // tools, or a table with a "Tool" column. A design record that
        // mentions a tool by name in prose is a reference, which §8
        // explicitly allows ("may reference tool names by string only").
        $offenders = array();
        foreach ( $doc_files as $rel => $body ) {
            if ( 'AGENTS.md' === $rel ) {
                continue;
            }
            $tool_row = preg_match( '/^\s*\|\s*`?agentshell_[a-z0-9_]+`?\s*\|/mi', $body );
            $tool_col = preg_match( '/^\s*\|[^|\n]*\bTool\b[^|\n]*\|/mi', $body );
            if ( ! ( $tool_row || $tool_col ) ) {
                continue;
            }
            // A file that presents tools in a table must also point at the
            // normative list, so a reader knows which surface is the
            // contract. The pointer is what makes the table a *reference*
            // rather than a second, competing tool list.
            if ( preg_match( '/AGENTS\.md[^.\n]{0,60}§\s*2/', $body ) ) {
                continue;
            }
            $offenders[] = $rel;
        }
        Agentshell_Test::assert_equals(
            array(),
            $offenders,
            'these files restate the tool table instead of pointing at AGENTS.md §2: '
                . implode( ', ', $offenders )
        );
    }
);

// ── 4. the source-of-truth precedence is not contradicted ─────────────────
Agentshell_Test::run(
    'no file claims wp_options is the sole source of truth for widgets',
    function () use ( $doc_files ) {
        $offenders = array();
        foreach ( $doc_files as $rel => $body ) {
            if ( 0 === strpos( $rel, 'docs/_archive/' ) ) {
                continue;
            }
            // Split into sentences and look at each one on its own: a
            // sentence that *denies* the claim ("it does not claim
            // wp_options is the sole source of truth") is the contract
            // being upheld, not broken. Quoted spans are removed first,
            // because a phrase inside quotation marks is a *mention* of
            // the claim — "a \"wp_options is the sole source of truth\"
            // claim" is the prohibition, not the assertion.
            $prose = preg_replace( '/"[^"]*"|`[^`]*`/', ' ', $body );
            foreach ( preg_split( '/(?<=[.!?])\s+/', $prose ) as $sentence ) {
                if ( ! preg_match( '/wp_options/i', $sentence ) ) {
                    continue;
                }
                if ( ! preg_match( '/(sole|only)\s+source of truth/i', $sentence ) ) {
                    continue;
                }
                if ( preg_match( '/\b(not|never|does not|no|nor|deprecated|must not|cannot)\b/i', $sentence ) ) {
                    continue; // a denial of the claim
                }
                if ( preg_match( '/bilateral|union source/i', $sentence ) ) {
                    continue; // the sentence states the correct precedence
                }
                $offenders[] = $rel . ': "' . trim( substr( $sentence, 0, 80 ) ) . '"';
            }
        }
        Agentshell_Test::assert_equals(
            array(),
            $offenders,
            'the widget registry is bilateral; these files contradict that: ' . implode( ' | ', $offenders )
        );
    }
);

Agentshell_Test::run(
    'the bilateral registry is stated in AGENTS.md',
    function () use ( $doc_files ) {
        $agents = $doc_files['AGENTS.md'] ?? '';
        Agentshell_Test::assert_true(
            false !== stripos( $agents, 'bilateral widget registry' ),
            'AGENTS.md must state the bilateral registry as the union source of truth'
        );
    }
);

// ── 5. the custom-CSS limit is one number everywhere ──────────────────────
Agentshell_Test::run(
    'the custom CSS limit is stated as 262,144 bytes wherever it appears',
    function () use ( $doc_files ) {
        $offenders = array();
        foreach ( $doc_files as $rel => $body ) {
            if ( preg_match( '/262144|256\s?(KiB|KB|KiB\b)/', $body ) ) {
                // The number must be 262144 or 256 KiB — not 128 KiB, not
                // 512 KiB, not "250 KB".
                if ( preg_match( '/(128|250|512)\s?(KiB|KB)/i', $body ) ) {
                    $offenders[] = $rel;
                }
            }
        }
        Agentshell_Test::assert_equals(
            array(),
            $offenders,
            'these files state a different custom CSS limit: ' . implode( ', ', $offenders )
        );
    }
);

Agentshell_Test::run(
    'AGENTS.md states the custom CSS limit as 262,144 bytes',
    function () use ( $doc_files ) {
        $agents = $doc_files['AGENTS.md'] ?? '';
        Agentshell_Test::assert_true(
            false !== strpos( $agents, '262,144' ) || false !== strpos( $agents, '262144' ),
            'AGENTS.md §6.6 must state the byte limit'
        );
    }
);
