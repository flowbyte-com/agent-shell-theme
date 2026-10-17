<?php
/**
 * Runner for the draft-tools bootstrap regression test.
 * Verifies the require_once lines for class-update-draft.php and
 * class-claim-existing-draft.php are present and well-ordered in
 * agentshell-mcp.php, and that the class files are loadable.
 */
require_once __DIR__ . '/draft-tools-bootstrap-test.php';
exit( Agentshell_Test::summary() ? 0 : 1 );
