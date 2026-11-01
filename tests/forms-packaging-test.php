<?php
/**
 * The release archive carries a complete, activatable forms plugin — and the
 * checker refuses one that does not.
 *
 * What this suite defends
 * -----------------------
 * 1. **The plugin ships.** A built archive contains `agentshell-forms/`.
 * 2. **It is complete, not a bootstrap.** Every file the plugin's own
 *    bootstrap `require_once`s is present, plus the assets and the builder an
 *    operator needs. The list is *derived from the bootstrap*, so a new
 *    require cannot ship without its file — the failure mode a hand-written
 *    list would miss.
 * 3. **The checker is the gate.** A complete archive passes; an archive with
 *    the plugin removed is refused; an archive missing one critical class is
 *    refused. The evidence is the checker's own exit status and messages, not
 *    a reading of the script.
 * 4. **Nothing else changed.** The exclusions still hold (no tests, no
 *    tooling, no credentials) and the theme, MCP and blocks plugins are still
 *    required.
 *
 * The archive is built from a revision, so this runs against the committed
 * tree. It is skipped when `git` or `tar` is unavailable, and it never writes
 * inside the checkout: every artefact is created in the system temporary
 * directory and removed before the suite ends.
 */

require_once __DIR__ . '/bootstrap.php';

echo "forms release packaging tests\n";

$repo = dirname( __DIR__ );

/** Run a command, returning [exit code, combined output]. */
function fp_run( $command ) {
    $descriptors = array(
        1 => array( 'pipe', 'w' ),
        2 => array( 'pipe', 'w' ),
    );

    $process = @proc_open( $command, $descriptors, $pipes, null );

    if ( ! is_resource( $process ) ) {
        return array( -1, '' );
    }

    $out = (string) stream_get_contents( $pipes[1] );
    $err = (string) stream_get_contents( $pipes[2] );
    fclose( $pipes[1] );
    fclose( $pipes[2] );

    $status = proc_close( $process );

    return array( (int) $status, $out . $err );
}

/**
 * The plugin files its own bootstrap requires.
 *
 * Read from the bootstrap rather than written down, so the assertion cannot
 * go stale: a require with no file in the archive fails this test by
 * construction.
 *
 * @return array<int,string> Repository-relative paths.
 */
function fp_required_by_bootstrap() {
    $bootstrap = file_get_contents( dirname( __DIR__ ) . '/agentshell-forms/agentshell-forms.php' );

    if ( ! is_string( $bootstrap ) ) {
        return array();
    }

    preg_match_all( "/AGENTSHELL_FORMS_PLUGIN_DIR\\s*\\.\\s*'([^']+)'/", $bootstrap, $matches );

    $paths = array();
    foreach ( (array) ( $matches[1] ?? array() ) as $relative ) {
        $paths[] = 'agentshell-forms/' . $relative;
    }

    return array_values( array_unique( $paths ) );
}

$work = rtrim( sys_get_temp_dir(), '/' ) . '/as-forms-packaging-' . getmypid();
@mkdir( $work, 0777, true );

$archive = '';

register_shutdown_function(
    function () use ( $work ) {
        if ( '' === (string) $work || ! is_dir( $work ) ) {
            return;
        }
        // Remove the directory and everything under it, without shelling out
        // to a recursive command on a path built from a variable.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $work, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $iterator as $entry ) {
            if ( $entry->isDir() ) {
                @rmdir( $entry->getPathname() );
            } else {
                @unlink( $entry->getPathname() );
            }
        }
        @rmdir( $work );
    }
);

$have_git = '' !== trim( (string) shell_exec( 'command -v git 2>/dev/null' ) );
$have_tar = '' !== trim( (string) shell_exec( 'command -v tar 2>/dev/null' ) );

if ( ! $have_git || ! $have_tar ) {
    Agentshell_Test::skip( 'git or tar is unavailable' );
    Agentshell_Test::summary();
    return;
}

// The archive is built from the committed tree, which is what a release
// ships. Uncommitted work is deliberately not packaged.
list( $build_status, $build_output ) = fp_run( 'bash ' . escapeshellarg( $repo . '/tools/package-release.sh' ) . ' --out ' . escapeshellarg( $work ) . ' 2>&1' );

$archives = glob( $work . '/agentshell-*.tar.gz' );
if ( is_array( $archives ) && isset( $archives[0] ) ) {
    $archive = (string) $archives[0];
}

Agentshell_Test::run(
	'the packager builds an archive',
	function () use ( $build_status, $build_output, $archive ) {
		Agentshell_Test::assert_equals( 0, $build_status, 'the packager exits successfully: ' . $build_output );
		Agentshell_Test::assert_true( '' !== $archive && is_file( $archive ), 'an archive was produced' );
	}
);

if ( '' === $archive || ! is_file( $archive ) ) {
	Agentshell_Test::summary();
	return;
}

$tree = $work . '/tree';
@mkdir( $tree, 0777, true );
list( $extract_status, $extract_output ) = fp_run( 'tar -xzf ' . escapeshellarg( $archive ) . ' -C ' . escapeshellarg( $tree ) . ' 2>&1' );

$root = $tree . '/agentshell';

Agentshell_Test::run(
	'the archive extracts',
	function () use ( $extract_status, $extract_output, $root ) {
		Agentshell_Test::assert_equals( 0, $extract_status, 'the archive extracts: ' . $extract_output );
		Agentshell_Test::assert_true( is_dir( $root ), 'it carries the agentshell/ root' );
	}
);

Agentshell_Test::run(
	'the archive contains the forms plugin',
	function () use ( $root ) {
		Agentshell_Test::assert_true( is_dir( $root . '/agentshell-forms' ), 'agentshell-forms/ is present' );
		Agentshell_Test::assert_true( is_file( $root . '/agentshell-forms/agentshell-forms.php' ), 'the plugin bootstrap is present' );
		Agentshell_Test::assert_true( is_file( $root . '/agentshell-forms/uninstall.php' ), 'the uninstaller is present' );
	}
);

Agentshell_Test::run(
	'every file the plugin bootstrap requires is in the archive',
	function () use ( $root ) {
		$required = fp_required_by_bootstrap();

		Agentshell_Test::assert_true( count( $required ) >= 30, 'the bootstrap was read: ' . count( $required ) . ' required files' );

		$missing = array();
		foreach ( $required as $relative ) {
			if ( ! is_file( $root . '/' . $relative ) ) {
				$missing[] = $relative;
			}
		}

		Agentshell_Test::assert_equals( array(), $missing, 'a plugin that activated and then failed its first require would be worse than an absent one' );
	}
);

Agentshell_Test::run(
	'the archive carries the assets and the builder an operator needs',
	function () use ( $root ) {
		$expected = array(
			// The visitor-facing form: without these the form renders unstyled
			// or, worse, submits without its script.
			'agentshell-forms/assets/forms.css',
			'agentshell-forms/assets/forms.js',
			// The builder.
			'agentshell-forms/admin/js/forms-api.js',
			'agentshell-forms/admin/js/forms-app.js',
			'agentshell-forms/admin/js/forms-model.js',
			'agentshell-forms/admin/js/forms-ui.js',
			'agentshell-forms/admin/css/forms-admin.css',
			// The agent tools registered in Phase 5.
			'agentshell-forms/includes/tools/class-list-forms.php',
			'agentshell-forms/includes/tools/class-delete-submission.php',
			// The personal-data policy the tools read.
			'agentshell-forms/includes/class-form-policy.php',
		);

		$missing = array();
		foreach ( $expected as $relative ) {
			if ( ! is_file( $root . '/' . $relative ) ) {
				$missing[] = $relative;
			}
		}

		Agentshell_Test::assert_equals( array(), $missing, 'the archive is an activatable plugin, not a bootstrap' );
	}
);

Agentshell_Test::run(
	'the archive still carries the theme, the MCP plugin and the blocks plugin',
	function () use ( $root ) {
		$expected = array(
			'style.css',
			'functions.php',
			'template-parts/shell-render.php',
			'configurator/configurator-app.js',
			'agentshell-mcp/agentshell-mcp.php',
			'agentshell-blocks/agentshell-blocks.php',
			// The licence ships with the code that carries it.
			'LICENSE',
		);

		$missing = array();
		foreach ( $expected as $relative ) {
			if ( ! is_file( $root . '/' . $relative ) ) {
				$missing[] = $relative;
			}
		}

		Agentshell_Test::assert_equals( array(), $missing, 'nothing the previous release required was displaced' );
	}
);

Agentshell_Test::run(
	'the release exclusions still apply',
	function () use ( $root ) {
		$forbidden_dirs = array( 'tests', 'tools', '.github', 'docs', 'agentshell-mcp-daemon', '.git' );

		foreach ( $forbidden_dirs as $dir ) {
			Agentshell_Test::assert_false( is_dir( $root . '/' . $dir ), $dir . '/ must not ship' );
		}

		// The forms plugin has its own tests-free layout: nothing named
		// `tests` may appear at any depth, including inside the plugin.
		$offenders = array();
		$iterator  = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $entry ) {
			$name = $entry->getFilename();
			if ( $entry->isDir() && 'tests' === $name ) {
				$offenders[] = $entry->getPathname();
			}
			if ( $entry->isFile() && preg_match( '/\.(sql|log|pem|key)$/', $name ) ) {
				$offenders[] = $entry->getPathname();
			}
			if ( $entry->isFile() && 'test-bed.json' === $name ) {
				$offenders[] = $entry->getPathname();
			}
		}

		Agentshell_Test::assert_equals( array(), $offenders, 'no test suite, no credential and no database dump ships' );
	}
);

/* ── The checker: positive and negative ─────────────────────────────────── */

$checker = $repo . '/tools/check-release-archive.sh';

Agentshell_Test::run(
	'the checker accepts the complete archive',
	function () use ( $checker, $archive ) {
		list( $status, $output ) = fp_run( 'bash ' . escapeshellarg( $checker ) . ' --archive ' . escapeshellarg( $archive ) . ' 2>&1' );

		Agentshell_Test::assert_equals( 0, $status, 'the complete archive passes: ' . $output );
		Agentshell_Test::assert_true( false !== strpos( $output, 'nothing required is missing' ), 'the checker says the deployment is complete' );
	}
);

Agentshell_Test::run(
	'the checker refuses an archive with the forms plugin removed',
	function () use ( $checker, $root, $work ) {
		$copy = $work . '/without-plugin';
		fp_run( 'cp -a ' . escapeshellarg( $root ) . ' ' . escapeshellarg( $copy ) . ' 2>&1' );
		fp_run( 'rm -rf ' . escapeshellarg( $copy . '/agentshell-forms' ) . ' 2>&1' );

		Agentshell_Test::assert_false( is_dir( $copy . '/agentshell-forms' ), 'the copy has no forms plugin' );

		list( $status, $output ) = fp_run( 'bash ' . escapeshellarg( $checker ) . ' --tree ' . escapeshellarg( $copy ) . ' 2>&1' );

		Agentshell_Test::assert_true( 0 !== $status, 'the checker refuses a package without the plugin' );
		Agentshell_Test::assert_true(
			false !== strpos( $output, 'missing agentshell-forms/agentshell-forms.php' ),
			'the refusal names the plugin bootstrap: ' . $output
		);

		fp_run( 'rm -rf ' . escapeshellarg( $copy ) . ' 2>&1' );
	}
);

Agentshell_Test::run(
	'the checker refuses an archive missing one critical plugin class',
	function () use ( $checker, $root, $work ) {
		$copy = $work . '/missing-class';
		fp_run( 'cp -a ' . escapeshellarg( $root ) . ' ' . escapeshellarg( $copy ) . ' 2>&1' );

		$victim = $copy . '/agentshell-forms/includes/class-form-renderer.php';
		Agentshell_Test::assert_true( is_file( $victim ), 'the class is there to remove' );
		@unlink( $victim );

		list( $status, $output ) = fp_run( 'bash ' . escapeshellarg( $checker ) . ' --tree ' . escapeshellarg( $copy ) . ' 2>&1' );

		Agentshell_Test::assert_true( 0 !== $status, 'the checker refuses a package missing a runtime class' );
		Agentshell_Test::assert_true(
			false !== strpos( $output, 'missing agentshell-forms/includes/class-form-renderer.php' ),
			'the refusal names the missing class: ' . $output
		);

		fp_run( 'rm -rf ' . escapeshellarg( $copy ) . ' 2>&1' );
	}
);

Agentshell_Test::run(
	'the checker refuses an archive that still carries the test suite',
	function () use ( $checker, $root, $work ) {
		$copy = $work . '/with-tests';
		fp_run( 'cp -a ' . escapeshellarg( $root ) . ' ' . escapeshellarg( $copy ) . ' 2>&1' );
		@mkdir( $copy . '/tests', 0777, true );
		file_put_contents( $copy . '/tests/leaked.php', '<?php' );

		list( $status, $output ) = fp_run( 'bash ' . escapeshellarg( $checker ) . ' --tree ' . escapeshellarg( $copy ) . ' 2>&1' );

		Agentshell_Test::assert_true( 0 !== $status, 'the checker refuses a package carrying tests' );
		Agentshell_Test::assert_true(
			false !== strpos( $output, 'tests' ),
			'the refusal names the leaked directory: ' . $output
		);

		fp_run( 'rm -rf ' . escapeshellarg( $copy ) . ' 2>&1' );
	}
);

Agentshell_Test::run(
	'the packager and the checker name the same files',
	function () use ( $repo ) {
		// The two lists are the contract: the packager decides what is
		// staged, the checker decides what must be there. A required file the
		// packager never includes would fail every build; this asserts the
		// relationship directly.
		$package = file_get_contents( $repo . '/tools/package-release.sh' );
		$check   = file_get_contents( $repo . '/tools/check-release-archive.sh' );

		Agentshell_Test::assert_true( is_string( $package ) && is_string( $check ), 'both scripts are readable' );

		Agentshell_Test::assert_true(
			false !== strpos( $package, '"agentshell-forms"' ),
			'the packager includes the forms plugin'
		);

		Agentshell_Test::assert_true(
			false !== strpos( $check, '"agentshell-forms/agentshell-forms.php"' ),
			'the checker requires the forms plugin'
		);

		foreach ( array( 'agentshell-mcp', 'agentshell-blocks', 'configurator', 'widgets' ) as $still_included ) {
			Agentshell_Test::assert_true(
				false !== strpos( $package, '"' . $still_included . '"' ),
				'the packager still includes ' . $still_included
			);
		}
	}
);

Agentshell_Test::run(
	'the theme does not activate the forms plugin',
	function () use ( $repo ) {
		// Shipping a plugin is not activating one. An activation call in the
		// theme would make a form engine run on every site that installs the
		// theme, which is the operator's decision and not the theme's.
		$offenders = array();

		$files = array( 'functions.php', 'header.php', 'footer.php', 'template-parts/shell-render.php' );
		foreach ( $files as $relative ) {
			$source = @file_get_contents( $repo . '/' . $relative );
			if ( ! is_string( $source ) ) {
				continue;
			}
			if ( preg_match( '/activate_plugin\s*\(|activate_plugins\s*\(/', $source ) ) {
				$offenders[] = $relative;
			}
		}

		Agentshell_Test::assert_equals( array(), $offenders, 'the theme never activates a plugin' );
	}
);
