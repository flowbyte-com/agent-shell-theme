<?php
/**
 * Test runner — security suite.
 * Run with: php tests/run-security.php
 */
require_once __DIR__ . '/security-test.php';
exit( Agentshell_Test::summary() ? 0 : 1 );