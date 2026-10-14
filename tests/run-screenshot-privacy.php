<?php
/**
 * Run only the screenshot privacy suite.
 *
 *   php tests/run-screenshot-privacy.php
 */
require_once __DIR__ . '/screenshot-privacy-test.php';
exit( Agentshell_Test::summary() ? 0 : 1 );