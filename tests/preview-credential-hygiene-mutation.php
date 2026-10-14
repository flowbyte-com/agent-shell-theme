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
 * Isolation
 * ---------
 * Mutants are written into a throwaway copy of the repository under the system
 * temp directory, and the suite runs from that copy. This file used to mutate
 * `functions.php` in the working tree — a file the theme serves live — and
 * restore it afterwards, so a concurrent run could execute mutated theme code
 * and a killed harness could leave the checkout broken. Nothing here opens the
 * working tree for writing.
 *
 * Note on the kill condition: standalone `*-test.php` files do not set an exit
 * status — run-all.php does — so the verdict keys on FAIL lines in the output.
 * Those lines are indented by the reporter, so the match is anchored at line
 * start; a plain "\nFAIL " count reads zero and reports every mutant surviving.
 *
 * Usage:
 *   php tests/preview-credential-hygiene-mutation.php
 */

require_once __DIR__ . '/mutation-sandbox.php';

/** Paths are relative to the repository root, and identical in the sandbox. */
$THEME_REL = 'functions.php';
$SUITE_REL = 'tests/preview-credential-hygiene-test.php';

$original = file_get_contents( agentshell_repo_root() . '/' . $THEME_REL );

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

$killed   = 0;
$survived = array();

$repo_before = agentshell_repo_snapshot();
$sandbox     = agentshell_mutation_sandbox_create();

$sandbox_theme = $sandbox . '/' . $THEME_REL;
if ( ! is_file( $sandbox_theme ) ) {
    echo "SANDBOX FAILED — {$THEME_REL} is missing from the copy.\n";
    echo "Nothing was written to the checkout.\n";
    exit( 1 );
}

// Sanity: the unmutated suite must be green, or "killed" means nothing.
$baseline = agentshell_mutation_run_suite( $sandbox . '/' . $SUITE_REL );
if ( $baseline['failed'] > 0 ) {
    echo "BASELINE FAILED — the suite is red before any mutation, so kill counts mean nothing.\n";
    echo $baseline['output'];
    agentshell_mutation_sandbox_destroy( $sandbox );
    exit( 1 );
}
echo "baseline: 0 failures\n\n";

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

    $lint = agentshell_mutation_lint( $mutated );
    if ( 0 !== $lint['rc'] ) {
        echo "ERROR  $label — mutant does not parse, so it proves nothing:\n";
        echo '       ' . $lint['output'] . "\n";
        $survived[] = $label . ' (does not parse)';
        continue;
    }

    // The only write in the whole harness, and it is inside the sandbox.
    file_put_contents( $sandbox_theme, $mutated );
    $result = agentshell_mutation_run_suite( $sandbox . '/' . $SUITE_REL );
    file_put_contents( $sandbox_theme, $original );

    if ( $result['failed'] > 0 ) {
        $killed++;
        echo "killed $label ({$result['failed']} failing assertions)\n";
    } else {
        echo "SURVIVED $label — the suite did not notice\n";
        $survived[] = $label;
    }
}

agentshell_mutation_sandbox_destroy( $sandbox );

$total = count( $mutants );
echo "\n=== MUTATION ===\n";
echo "$killed of $total killed\n";

if ( $survived ) {
    echo "Survivors:\n";
    foreach ( $survived as $label ) {
        echo "  - $label\n";
    }
}

// The whole point of the sandbox: prove it, do not assume it.
$diff = agentshell_repo_diff( $repo_before, array( agentshell_repo_root() . '/' . $THEME_REL ) );
if ( $diff['changed'] || $diff['added'] || $diff['removed'] ) {
    echo "\nREPO INTEGRITY FAILURE — the harness modified the checkout:\n";
    foreach ( array( 'changed', 'added', 'removed' ) as $kind ) {
        foreach ( $diff[ $kind ] as $path ) {
            echo "  $kind: $path\n";
        }
    }
    exit( 1 );
}
echo "checkout untouched\n";

exit( $survived ? 1 : 0 );