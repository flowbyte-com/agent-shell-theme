<?php
/**
 * Standalone runner for the screenshot capture regressions, so this suite can
 * be run on its own (it forks real browser processes and takes ~60s) without
 * waiting on the full suite.
 */
require_once __DIR__ . '/screenshot-test.php';
exit( Agentshell_Test::summary() ? 0 : 1 );