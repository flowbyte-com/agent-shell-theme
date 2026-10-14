<?php
/**
 * Mutation test for preview credential hygiene.
 *
 * The claim under test is narrow and falsifiable: a credential-authorised
 * preview never renders its own credentials, and never suppresses the admin bar
 * for anyone else. An assertion that passes for the wrong reason looks exactly
 * like one that passes for the right one, so each mechanism is removed in turn
 * and the suite has to notice.
 *
 * Killed means "an assertion failed", not "the mutation produced an error". A
 * parse error would satisfy a naive check and prove nothing, so each mutant is
 * linted before it counts, and reported separately if it does not parse.
 *
 * Note on the kill condition: standalone `*-test.php` files do not set an exit
 * status — run-all.php does — so the verdict keys on FAIL lines in the output.
 * Those lines are indented by the reporter, so the match is anchored at line
 * start; a plain "\nFAIL " count reads zero and reports every mutant surviving.
 *
 * Usage:
 *   php tests/preview-credential-hygiene-mutation.php
 */

$theme_file = dirname( __DIR__ ) . '/functions.php';
$suite_file = __DIR__ . '/preview-credential-hygiene-test.php';
$original   = file_get_contents( $theme_file );

/**
 * Mutants, each the minimal edit that removes one guarantee.
 */
$mutants = array(
    'no admin bar suppression at all' => array(
        "function agentshell_suppress_preview_admin_bar( \$show ) {\n    if ( ! agentshell_is_preview_request() ) {\n        return \$show;\n    }\n    return false;\n}\nadd_filter( 'show_admin_bar', 'agentshell_suppress_preview_admin_bar' );",
        "function agentshell_suppress_preview_admin_bar( \$show ) {\n    return \$show;\n}",
    ),
    'suppression keys off the arguments, not the authorisation' => array(
        "    return ! empty( \$GLOBALS['agentshell_post_preview'] )\n        || ! empty( \$GLOBALS['agentshell_transaction_preview'] );",
        "    return isset( \$_GET['agentshell_post_key'] ) || isset( \$_GET['agentshell_tx_key'] );",
    ),
    'suppression only covers the post preview' => array(
        "    return ! empty( \$GLOBALS['agentshell_post_preview'] )\n        || ! empty( \$GLOBALS['agentshell_transaction_preview'] );",
        "    return ! empty( \$GLOBALS['agentshell_post_preview'] );",
    ),
    'no scrub' => array(
        "add_action( 'template_redirect', 'agentshell_scrub_preview_credentials_from_request', 1 );",
        "// mutant: no scrub",
    ),
    'scrub removes only the post key' => array(
        "        'agentshell_post', 'agentshell_post_key', 'agentshell_post_exp', 'agentshell_post_user',\n        'agentshell_tx', 'agentshell_tx_key', 'agentshell_tx_exp',\n        'agentshell_preview', 'agentshell_preview_key',",
        "        'agentshell_post_key',",
    ),
    'scrub overreaches and clears \$_GET' => array(
        "    parse_str( \$parts[1], \$query );",
        "    foreach ( \$args as \$arg ) { unset( \$_GET[ \$arg ] ); }\n    parse_str( \$parts[1], \$query );",
    ),
    'scrub drops the query entirely' => array(
        "    \$rebuilt = http_build_query( \$query );\n    \$_SERVER['REQUEST_URI'] = '' === \$rebuilt ? \$parts[0] : \$parts[0] . '?' . \$rebuilt;",
        "    \$_SERVER['REQUEST_URI'] = \$parts[0];",
    ),
    'scrub runs before the gates decide' => array(
        "add_action( 'template_redirect', 'agentshell_scrub_preview_credentials_from_request', 1 );",
        "add_action( 'init', 'agentshell_scrub_preview_credentials_from_request', 0 );",
    ),
    'no transaction Referrer-Policy' => array(
        "    // same-origin would still hand the full URL — transaction key included — to\n    // same-origin subresources. This page has no reason to refer anywhere.\n    agentshell_emit_response_header( 'Referrer-Policy: no-referrer' );\n",
        "    // mutant: no Referrer-Policy on the transaction path\n",
    ),
);

/**
 * Run the hygiene suite and count its failures.
 */
function agentshell_hygiene_run( $suite_file ) {
    $cmd     = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $suite_file ) . ' 2>&1';
    $output  = (string) shell_exec( $cmd );
    return array(
        'output' => $output,
        'failed' => preg_match_all( '/^\s*FAIL /m', $output ),
    );
}

// Sanity: the unmutated suite must be green, or "killed" means nothing.
$baseline = agentshell_hygiene_run( $suite_file );
if ( $baseline['failed'] > 0 ) {
    echo "BASELINE FAILED — the suite is red before any mutation, so kill counts mean nothing.\n";
    echo $baseline['output'];
    exit( 1 );
}
echo "baseline: 0 failures\n\n";

$killed   = 0;
$survived = array();

foreach ( $mutants as $label => $mutation ) {
    list( $find, $replace ) = $mutation;

    if ( false === strpos( $original, $find ) ) {
        // A mutant that cannot be applied is not evidence about the suite.
        echo "SKIP   $label — the target text is no longer in the source\n";
        $survived[] = $label . ' (not applicable)';
        continue;
    }

    $mutated = str_replace( $find, $replace, $original, $count );
    if ( $count < 1 ) {
        echo "SKIP   $label — no replacement made\n";
        $survived[] = $label . ' (not applicable)';
        continue;
    }

    // Sanity: the mutant must still parse, or a syntax error would be counted
    // as "killed" for the wrong reason. tempnam() already creates the file it
    // returns, so appending '.php' names a second path — both need removing or
    // every mutant leaves an orphan in the temp dir.
    $tmp       = tempnam( sys_get_temp_dir(), 'as-hyg-mutant-' );
    $lint_file = $tmp . '.php';
    file_put_contents( $lint_file, $mutated );
    exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $lint_file ) . ' 2>&1', $lint, $lint_rc );
    @unlink( $lint_file );
    @unlink( $tmp );

    file_put_contents( $theme_file, $mutated );
    $result = agentshell_hygiene_run( $suite_file );
    file_put_contents( $theme_file, $original );

    if ( 0 !== $lint_rc ) {
        echo "ERROR  $label — mutant does not parse, so it proves nothing:\n";
        echo '       ' . implode( "\n       ", $lint ) . "\n";
        $survived[] = $label . ' (does not parse)';
        continue;
    }

    if ( $result['failed'] > 0 ) {
        $killed++;
        echo "killed $label ({$result['failed']} failing assertions)\n";
    } else {
        echo "SURVIVED $label — the suite did not notice\n";
        $survived[] = $label;
    }
}

$total = count( $mutants );
echo "\n=== MUTATION ===\n";
echo "$killed of $total killed\n";

if ( $survived ) {
    echo "Survivors:\n";
    foreach ( $survived as $label ) {
        echo "  - $label\n";
    }
}

// Restore unconditionally, so an interrupted run cannot leave the source mutated.
file_put_contents( $theme_file, $original );

exit( $survived ? 1 : 0 );