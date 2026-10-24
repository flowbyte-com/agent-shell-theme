<?php
/**
 * The deployment must not carry what must not be carried.
 *
 * The independent review of PR #16 found the test-bed profile readable
 * over HTTP at its path inside the theme, with a database password in
 * cleartext: the repository is a theme, and a theme is a directory a web
 * server serves. A gitignored file is still a file on a disk that may be
 * served.
 *
 * These tests lock the two halves of the answer in place:
 *
 *   1. the profile's default location is outside the repository;
 *   2. the release archive is built from an explicit list, and the
 *      checker says what the archive contains.
 *
 * The archive is built from the repository's own tree, so the test is
 * self-contained: it does not need a database, a web server, or a
 * network.
 *
 * @package AgentShell\Tests
 */

require_once __DIR__ . '/bootstrap.php';

Agentshell_Test::run(
    'the test-bed profile is looked for outside the repository first',
    function () {
        require_once __DIR__ . '/agentshell-test-bed.php';

        // The helper names the location; it must not be inside the tree.
        $home = agentshell_test_bed_home_path();
        Agentshell_Test::assert_true(
            is_string( $home ) && '' !== $home,
            'the helper must name a location'
        );
        Agentshell_Test::assert_false(
            0 === strpos( $home, dirname( __DIR__ ) . '/' ),
            'the default location must not be inside the repository: ' . $home
        );
        Agentshell_Test::assert_true(
            false !== strpos( $home, '.config/agentshell/test-bed.json' ),
            'the default location must be the operator\'s configuration directory: ' . $home
        );
    }
);

Agentshell_Test::run(
    'the in-tree profile is not shipped, and the checker says so',
    function () {
        $checker = dirname( __DIR__ ) . '/tools/check-release-archive.sh';
        Agentshell_Test::assert_true( is_file( $checker ), 'the checker must exist' );

        // A tree that contains what must not ship.
        $tree = sys_get_temp_dir() . '/agentshell-release-check-' . getmypid();
        @mkdir( $tree . '/tests/config', 0755, true );
        file_put_contents( $tree . '/tests/config/test-bed.json', '{"db":{"password":"not-a-real-password"}}' );
        file_put_contents( $tree . '/style.css', "/* theme */\n" );
        file_put_contents( $tree . '/functions.php', "<?php\n" );
        file_put_contents( $tree . '/header.php', "<?php\n" );
        file_put_contents( $tree . '/footer.php', "<?php\n" );
        file_put_contents( $tree . '/default-config.json', "{}\n" );

        $output = array();
        $status = 0;
        exec( 'bash ' . escapeshellarg( $checker ) . ' --tree ' . escapeshellarg( $tree ) . ' 2>&1', $output, $status );
        $text = implode( "\n", $output );

        // Clean up before asserting.
        @unlink( $tree . '/tests/config/test-bed.json' );
        @rmdir( $tree . '/tests/config' );
        @unlink( $tree . '/style.css' );
        @unlink( $tree . '/functions.php' );
        @unlink( $tree . '/header.php' );
        @unlink( $tree . '/footer.php' );
        @unlink( $tree . '/default-config.json' );
        @rmdir( $tree );

        Agentshell_Test::assert_true(
            0 !== $status,
            'the checker must fail on a tree that carries a test-bed profile'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $text, 'test-bed.json' ),
            "the checker must name the profile it found:\n" . $text
        );
    }
);

Agentshell_Test::run(
    'the release archive carries the product and none of the repository',
    function () {
        $packager = dirname( __DIR__ ) . '/tools/package-release.sh';
        $checker  = dirname( __DIR__ ) . '/tools/check-release-archive.sh';
        Agentshell_Test::assert_true( is_file( $packager ), 'the packager must exist' );

        $out = sys_get_temp_dir() . '/agentshell-dist-' . getmypid();
        @mkdir( $out, 0755, true );

        $output = array();
        $status = 0;
        exec( 'bash ' . escapeshellarg( $packager ) . ' --out ' . escapeshellarg( $out ) . ' 2>&1', $output, $status );
        $build = implode( "\n", $output );

        Agentshell_Test::assert_equals(
            0,
            $status,
            "the packager must succeed:\n" . $build
        );

        $archives = glob( $out . '/agentshell-*.tar.gz' );
        Agentshell_Test::assert_true(
            is_array( $archives ) && count( $archives ) === 1,
            "the packager must build exactly one archive:\n" . $build
        );

        $listing = array();
        exec( 'tar -tzf ' . escapeshellarg( $archives[0] ), $listing );
        $text = implode( "\n", $listing );

        // The licence travels with the work: the GNU Affero General
        // Public License v3 requires that a copy of the licence
        // accompanies every copy of the work, and the release archive is
        // a copy of the work. An archive without it is not a compliant
        // distribution.
        $listing = array();
        exec( 'tar -tzf ' . escapeshellarg( $archives[0] ), $listing );
        Agentshell_Test::assert_true(
            in_array( 'agentshell/LICENSE', $listing, true ),
            'the archive must carry the licence'
        );
        $head = '';
        foreach ( $listing as $entry ) {
            if ( 'agentshell/LICENSE' === $entry ) { $head = $entry; break; }
        }
        Agentshell_Test::assert_true( '' !== $head, 'the archive must carry the licence' );

        // The product is in it.
        foreach ( array(
            'style.css',
            'functions.php',
            'default-config.json',
            'agentshell-mcp/agentshell-mcp.php',
            'agentshell-mcp/includes/tools/class-inspect.php',
            'agentshell-blocks/includes/tools/class-get-widget.php',
            'widgets/.index.json',
        ) as $required ) {
            Agentshell_Test::assert_true(
                false !== strpos( $text, $required ),
                "the archive must carry $required"
            );
        }

        // The repository is not.
        foreach ( array( 'tests/', '.git/', '.github/', 'agentshell-mcp-daemon/', 'test-bed.json' ) as $forbidden ) {
            Agentshell_Test::assert_false(
                false !== strpos( $text, $forbidden ),
                "the archive must not carry $forbidden"
            );
        }

        // And the checker agrees with the packager.
        $check_output = array();
        $check_status = 0;
        exec( 'bash ' . escapeshellarg( $checker ) . ' --archive ' . escapeshellarg( $archives[0] ) . ' 2>&1', $check_output, $check_status );
        Agentshell_Test::assert_equals(
            0,
            $check_status,
            "the checker must pass on the archive the packager built:\n" . implode( "\n", $check_output )
        );

        @unlink( $archives[0] );
        @rmdir( $out );
    }
);

Agentshell_Test::run(
    'the packager and the checker share one list of exclusions',
    function () {
        // The corrective-diff review found that the two scripts kept two
        // copies of the exclusion list, and the checker's copy was
        // missing six of the packager's categories — which made the
        // claim that "a category excluded in one and forgotten in the
        // other fails the build" false.
        //
        // There is now one list, in tools/release-exclusions.sh, and both
        // scripts source it. This test asserts that they still do, and
        // that neither has grown a private list of its own.
        $shared = dirname( __DIR__ ) . '/tools/release-exclusions.sh';
        Agentshell_Test::assert_true( is_file( $shared ), 'the shared exclusion list must exist' );

        foreach ( array( 'tools/package-release.sh', 'tools/check-release-archive.sh' ) as $script ) {
            $source = (string) file_get_contents( dirname( __DIR__ ) . '/' . $script );
            Agentshell_Test::assert_true(
                false !== strpos( $source, 'release-exclusions.sh' ),
                $script . ' must source the shared exclusion list'
            );
            Agentshell_Test::assert_false(
                false !== strpos( $source, 'EXCLUDE_ROOT=(' ) || false !== strpos( $source, 'EXCLUDE_ANY=(' ),
                $script . ' must not keep a private copy of the exclusion list'
            );
        }

        // The shared list must actually define both kinds of rule.
        $shared_source = (string) file_get_contents( $shared );
        Agentshell_Test::assert_true(
            false !== strpos( $shared_source, 'EXCLUDE_ROOT=(' ),
            'the shared list must define the root categories'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $shared_source, 'EXCLUDE_ANY=(' ),
            'the shared list must define the depth categories'
        );
    }
);

Agentshell_Test::run(
    'the checker refuses every category the packager excludes',
    function () {
        // One category per kind, taken from the shared list itself, so
        // that adding a category to the list adds it to this test.
        $shared = (string) file_get_contents( dirname( __DIR__ ) . '/tools/release-exclusions.sh' );
        preg_match_all( '/^\s{4}"([^"]+)"/m', $shared, $m );
        $categories = $m[1];
        Agentshell_Test::assert_true( count( $categories ) > 10, 'the shared list must not be empty' );

        $checker = dirname( __DIR__ ) . '/tools/check-release-archive.sh';

        // A file whose *name* is each category, in a tree, must be
        // refused by the checker.
        $refused = 0;
        foreach ( $categories as $category ) {
            $name = str_replace( '*', 'probe', $category );
            if ( '' === $name || '.' === $name ) {
                continue;
            }
            $tree = sys_get_temp_dir() . '/agentshell-rel-' . getmypid() . '-' . md5( $category );
            @mkdir( $tree . '/probe-dir', 0755, true );
            file_put_contents( $tree . '/' . $name, "probe\n" );

            $output = array();
            $status = 0;
            exec( 'bash ' . escapeshellarg( $checker ) . ' --tree ' . escapeshellarg( $tree ) . ' 2>&1', $output, $status );
            $text = implode( "\n", $output );

            @unlink( $tree . '/' . $name );
            @rmdir( $tree . '/probe-dir' );
            @rmdir( $tree );

            if ( 0 !== $status && false !== strpos( $text, $name ) ) {
                $refused++;
            }
        }
        Agentshell_Test::assert_equals(
            count( $categories ),
            $refused,
            'the checker must refuse every category the shared list names'
        );
    }
);

Agentshell_Test::run(
    'the test suite itself is not in the release archive',
    function () {
        // The archive check above covers the shape; this asserts the
        // specific file the review found, by name, in the packager's own
        // exclusion list — which now lives in the shared file both
        // scripts source.
        $packager = dirname( __DIR__ ) . '/tools/package-release.sh';
        $source   = (string) file_get_contents( $packager );

        Agentshell_Test::assert_true(
            false !== strpos( $source, 'test-bed.json' ),
            'the packager must refuse a file that looks like a credential'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $source, 'release-exclusions.sh' ),
            'the packager must source the shared exclusion list'
        );

        $shared = (string) file_get_contents( dirname( __DIR__ ) . '/tools/release-exclusions.sh' );
        Agentshell_Test::assert_true(
            false !== strpos( $shared, '"tests"' ),
            'the shared exclusion list must exclude the test suite by name'
        );
    }
);

Agentshell_Test::run(
    'the runtime directories of the source tree carry no repository and no build output',
    function () {
        // The archive is clean because the packager builds it from an
        // explicit list. The *source tree* is a different thing: it is
        // what a deployment that copies the checkout gets, and it is
        // what the local site serves when the theme is a symlink into
        // it. A nested repository inside a runtime directory is the
        // shape that mistake takes — it is how a `.git` full of history
        // ends up inside a served directory.
        $root   = dirname( __DIR__ );
        $runtime = array(
            'agentshell-mcp',
            'agentshell-blocks',
            'agentshell-mcp-daemon',
            'widgets',
            'template-parts',
            'assets',
        );
        $forbidden = array( '.git', '.worktrees', 'node_modules', 'vendor', 'dist', 'build' );

        foreach ( $runtime as $directory ) {
            $path = $root . '/' . $directory;
            if ( ! is_dir( $path ) ) {
                continue;
            }
            foreach ( $forbidden as $name ) {
                $found = array();
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ( $it as $item ) {
                    if ( $item->isDir() && $item->getFilename() === $name ) {
                        $found[] = $item->getPathname();
                    }
                }
                Agentshell_Test::assert_equals(
                    array(),
                    $found,
                    $directory . ' must carry no ' . $name . ' directory: a deployment that copies the '
                        . 'checkout would serve it'
                );
            }
        }
    }
);
