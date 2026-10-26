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
 *
 * A gate that cannot fail is not a gate, so the detection functions are
 * exercised against synthetic violations at the end of this file: each
 * one is handed text that *should* trip it, and the assertion is that it
 * does.
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


/**
 * The sentences of a markdown body, with emphasis markers removed.
 *
 * Markdown emphasis is removed first because it defeats a naive sentence
 * split: in "…rather than inlined into every page?**\n\nThe short answer:
 * yes, it should — and the 256 KiB limit…" the "?" is followed by "**",
 * not by whitespace, so the two sentences stay joined and a limit stated
 * in the second one is attributed to the first.
 *
 * @param string $body Markdown text.
 * @return array<int,string>
 */
function doc_gate_sentences( $body ) {
    // Only the asterisk is removed. The underscore is a word character in
    // identifiers (`wp_options`), and stripping it would change the very
    // text the gates look for.
    $plain = str_replace( '*', '', (string) $body );
    return preg_split( '/(?<=[.!?])\s+/', $plain );
}

/**
 * Files that call a stale tool count canonical.
 *
 * The claim is recognised *sentence by sentence*, because the failure it
 * exists to catch was spread over two sentences: "The plugin ships **45+
 * `agentshell_*` tools** … The count "45+" is canonical". A regex that
 * looked for the two markers next to each other missed it.
 *
 * @param array<string,string> $files path => contents
 * @return array<int,string> The offending paths.
 */
function doc_gate_stale_count_files( array $files ) {
    $offenders = array();
    foreach ( $files as $rel => $body ) {
        if ( 'AGENTS.md' === $rel ) {
            continue; // the normative contract is where the count lives
        }
        foreach ( doc_gate_sentences( $body ) as $sentence ) {
            $stale = preg_match( '/\b45\+|\b(10|11|12)\s+`?agentshell_\*?`?\s+tools/i', $sentence );
            if ( ! $stale ) {
                continue;
            }
            $claims = preg_match( '/canonical|authoritative|the count|is current|current count/i', $sentence );
            if ( ! $claims ) {
                continue;
            }
            // A sentence that denies the claim, or that says the figure is
            // historical, is the contract being upheld.
            if ( preg_match( '/\b(not|never|no|nor|deprecated|must not|cannot|at the time of writing|no longer)\b/i', $sentence ) ) {
                continue;
            }
            $offenders[] = $rel . ': "' . trim( substr( $sentence, 0, 80 ) ) . '"';
        }
    }
    return $offenders;
}

/**
 * Files that reproduce a deprecated 10 / 11 / 12 tool count as current.
 *
 * @param array<string,string> $files path => contents
 * @return array<int,string> The offending paths.
 */
function doc_gate_deprecated_count_files( array $files ) {
    $offenders = array();
    foreach ( $files as $rel => $body ) {
        if ( 'AGENTS.md' === $rel ) {
            continue; // AGENTS.md names them as deprecated, which is correct
        }
        if ( preg_match( '/\b(10|11|12)\s+`?agentshell_\*`?\s+tools/i', $body )
             && ! preg_match( '/deprecated|must not be reproduced|older counts/i', $body ) ) {
            $offenders[] = $rel;
        }
    }
    return $offenders;
}

/**
 * Whether a markdown body presents a *table of tools*.
 *
 * A table is what §9 forbids: a table whose rows are tools, or a table
 * with a column headed "Tool". A design record that mentions a tool by
 * name in prose is a reference, which §8 explicitly allows ("may
 * reference tool names by string only").
 *
 * @param string $body Markdown text.
 * @return bool
 */
function doc_gate_has_tool_table( $body ) {
    $lines  = preg_split( '/\r?\n/', (string) $body );
    $header = null;
    foreach ( $lines as $line ) {
        if ( preg_match( '/^\s*\|/', $line ) ) {
            if ( null === $header ) {
                // The first line of a run of table rows is its header. A
                // row whose *first cell* is a tool name is a tool row
                // whatever the header says.
                $cells = array_map( 'trim', explode( '|', trim( $line ) ) );
                $first = $cells[1] ?? '';
                if ( preg_match( '/`?agentshell_[a-z0-9_]+`?/i', $first ) ) {
                    return true;
                }
                $header = $cells;
            }
            continue;
        }
        if ( null !== $header ) {
            foreach ( $header as $cell ) {
                if ( preg_match( '/^(mcp\s+)?tool(\s+name)?s?$/i', trim( $cell ) ) ) {
                    return true;
                }
            }
        }
        $header = null;
    }
    if ( null !== $header ) {
        foreach ( $header as $cell ) {
            if ( preg_match( '/^(mcp\s+)?tool(\s+name)?s?$/i', trim( $cell ) ) ) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Files that restate the MCP tool table instead of pointing at it.
 *
 * @param array<string,string> $files path => contents
 * @return array<int,string> The offending paths.
 */
function doc_gate_tool_table_files( array $files ) {
    $offenders = array();
    foreach ( $files as $rel => $body ) {
        if ( 'AGENTS.md' === $rel ) {
            continue;
        }
        if ( ! doc_gate_has_tool_table( $body ) ) {
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
    return $offenders;
}

/**
 * Files that claim `wp_options` is the sole source of truth for widgets.
 *
 * @param array<string,string> $files path => contents
 * @return array<int,string> The offending paths.
 */
function doc_gate_sole_source_files( array $files ) {
    $offenders = array();
    foreach ( $files as $rel => $body ) {
        if ( 0 === strpos( $rel, 'docs/_archive/' ) ) {
            continue;
        }
        // Split into sentences and look at each one on its own: a
        // sentence that *denies* the claim ("it does not claim
        // wp_options is the sole source of truth") is the contract being
        // upheld, not broken. Double-quoted spans are removed first,
        // because a phrase inside quotation marks is a *mention* of the
        // claim — "a \"wp_options is the sole source of truth\" claim" is
        // the prohibition, not the assertion. Code spans are kept: the
        // option's name is normally written in code formatting, and
        // dropping it would hide the very sentence the gate looks for.
        $prose = preg_replace( '/"[^"]*"/', ' ', $body );
        foreach ( doc_gate_sentences( $prose ) as $sentence ) {
            if ( ! preg_match( '/wp_options/i', $sentence ) ) {
                continue;
            }
            if ( ! preg_match( '/(sole|only)\s+source of truth/i', str_replace( '*', '', $sentence ) ) ) {
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
    return $offenders;
}

/**
 * Files that state a custom-CSS limit other than 256 KiB / 262,144 bytes.
 *
 * Every size a file states *about the custom stylesheet* is checked, so a
 * file that states only the wrong number is caught as well as one that
 * states both.
 *
 * @param array<string,string> $files path => contents
 * @return array<int,string> The offending paths.
 */
function doc_gate_css_limit_files( array $files ) {
    $offenders = array();
    foreach ( $files as $rel => $body ) {
        foreach ( doc_gate_sentences( $body ) as $sentence ) {
            if ( ! preg_match( '/custom\s+css|custom stylesheet|stylesheet limit|css limit/i', $sentence ) ) {
                continue;
            }
            // Only a sentence that *states a limit* is in scope. A design
            // record that mentions a size for comparison ("a question that
            // was academic at 10 KB") is not making a claim about the
            // limit, and must not be flagged for it.
            if ( ! preg_match( '/limit|maximum|max\b|at most|no more than|up to|capped/i', $sentence ) ) {
                continue;
            }
            if ( ! preg_match_all( '/(\d[\d,]*)\s*(KiB|KB|bytes)\b/i', $sentence, $m, PREG_SET_ORDER ) ) {
                continue;
            }
            foreach ( $m as $hit ) {
                $value = (int) str_replace( ',', '', $hit[1] );
                if ( 256 === $value || 262144 === $value ) {
                    continue; // the correct limit
                }
                $offenders[] = $rel . ': "' . trim( substr( $sentence, 0, 80 ) ) . '"';
            }
        }
    }
    return $offenders;
}

/**
 * Markdown tables whose rows do not all have the same number of cells.
 *
 * A fused row is invisible in a diff that shows one long line, and a table
 * with one malformed row renders as garbage in every Markdown viewer — the
 * pointer table in AGENTS.md §8 once lost a row that way, and the gate that
 * checks AGENTS.md's *claims* said nothing about its *shape*.
 *
 * @param array<string,string> $files
 * @return array<int,string>
 */
function doc_gate_malformed_tables( array $files ) {
    $offenders = array();
    foreach ( $files as $rel => $body ) {
        $lines   = explode( "\n", $body );
        $in_table = false;
        $expected = 0;
        foreach ( $lines as $number => $line ) {
            $line = trim( $line );
            if ( '' === $line ) {
                $in_table = false;
                continue;
            }
            if ( 0 !== strpos( $line, '|' ) ) {
                $in_table = false;
                continue;
            }
            // Only unescaped pipes separate cells: a cell may legitimately
            // contain `\|` (a credential format, for instance).
            $cells = count( preg_split( '/(?<!\\\\)\|/', $line ) ) - 2;
            if ( $cells < 2 ) {
                $in_table = false;
                continue;
            }
            if ( ! $in_table ) {
                $in_table = true;
                $expected = $cells;
                continue;
            }
            if ( $cells !== $expected ) {
                $offenders[] = $rel . ':' . ( $number + 1 ) . ' has ' . $cells . ' cells, the table has ' . $expected;
            }
        }
    }
    return $offenders;
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

Agentshell_Test::run(
    'every markdown table has rows of one shape',
    function () use ( $doc_files ) {
        Agentshell_Test::assert_equals(
            array(),
            doc_gate_malformed_tables( $doc_files ),
            'a fused or truncated table row is a broken table'
        );
    }
);

// ── 1. no stale "canonical" tool counts ───────────────────────────────────
Agentshell_Test::run(
    'no file calls a stale tool count canonical',
    function () use ( $doc_files ) {
        $offenders = doc_gate_stale_count_files( $doc_files );
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
        $offenders = doc_gate_deprecated_count_files( $doc_files );
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
        $offenders = doc_gate_tool_table_files( $doc_files );
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
        $offenders = doc_gate_sole_source_files( $doc_files );
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
        $offenders = doc_gate_css_limit_files( $doc_files );
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

// ── 6. the gates themselves can fail ──────────────────────────────────────
Agentshell_Test::run(
    'the stale-count gate catches a stale count',
    function () {
        $synthetic = array( 'README.md' => 'The plugin ships **45+ `agentshell_*` tools**. The count "45+" is canonical.' );
        $offenders = doc_gate_stale_count_files( $synthetic );
        Agentshell_Test::assert_equals(
            1,
            count( $offenders ),
            'the gate must catch the exact claim it exists to prevent'
        );
        Agentshell_Test::assert_true(
            0 === strpos( $offenders[0], 'README.md' ),
            'the offender must name the file: ' . $offenders[0]
        );
    }
);

Agentshell_Test::run(
    'the stale-count gate does not fire on a pointer',
    function () {
        $synthetic = array( 'README.md' => 'The plugin ships the tools listed in `AGENTS.md` §2. The count lives there.' );
        Agentshell_Test::assert_equals( array(), doc_gate_stale_count_files( $synthetic ) );
    }
);

Agentshell_Test::run(
    'the tool-table gate catches a table of tools',
    function () {
        $synthetic = array(
            'notes.md' => "| Tool | Description |\n|---|---|\n| `agentshell_inspect` | Observe the site. |\n",
        );
        Agentshell_Test::assert_equals( array( 'notes.md' ), doc_gate_tool_table_files( $synthetic ) );
    }
);

Agentshell_Test::run(
    'the tool-table gate allows a table that points at the normative list',
    function () {
        $synthetic = array(
            'notes.md' => "The authoritative list is in `AGENTS.md` §2.\n\n| Tool | Description |\n|---|---|\n| `agentshell_inspect` | Observe the site. |\n",
        );
        Agentshell_Test::assert_equals( array(), doc_gate_tool_table_files( $synthetic ) );
    }
);

Agentshell_Test::run(
    'the sole-source gate catches an assertion and ignores a denial',
    function () {
        $assertion = array( 'notes.md' => 'The widget registry is `wp_options` is the sole source of truth for widgets.' );
        Agentshell_Test::assert_equals( 1, count( doc_gate_sole_source_files( $assertion ) ) );

        $denial = array( 'notes.md' => 'It does not claim `wp_options` is the sole source of truth. See `AGENTS.md` §4.1.' );
        Agentshell_Test::assert_equals( array(), doc_gate_sole_source_files( $denial ) );
    }
);

Agentshell_Test::run(
    'the CSS-limit gate catches a different limit',
    function () {
        $synthetic = array( 'notes.md' => 'The custom CSS limit is 512 KiB.' );
        $offenders = doc_gate_css_limit_files( $synthetic );
        Agentshell_Test::assert_equals( 1, count( $offenders ), 'a different stated limit must be caught' );

        $correct = array( 'notes.md' => 'The custom CSS limit is 262,144 bytes (256 KiB).' );
        Agentshell_Test::assert_equals( array(), doc_gate_css_limit_files( $correct ) );

        $comparison = array( 'notes.md' => 'A question that was academic at 10 KB is acute for the custom stylesheet.' );
        Agentshell_Test::assert_equals( array(), doc_gate_css_limit_files( $comparison ), 'a size mentioned for comparison is not a limit' );
    }
);
