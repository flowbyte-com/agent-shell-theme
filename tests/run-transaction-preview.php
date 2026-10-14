<?php
/**
 * Standalone runner for the transaction-preview suite.
 *
 *   php tests/run-transaction-preview.php
 */

require_once __DIR__ . '/transaction-preview-test.php';

exit( Agentshell_Test::summary() ? 0 : 1 );