<?php
/**
 * The LocalWP installation helper: what it links, and what it refuses.
 *
 * `tools/install-localwp.sh` is the one piece of tooling that writes into a
 * *site* rather than into the checkout, so its safety properties are the
 * product: never overwrite, never delete, touch nothing outside the four
 * destinations, and report exactly what happened.
 *
 * Every test here builds its own throwaway wp-content in the system temp
 * directory. Nothing touches a real WordPress installation — the helper is
 * only ever pointed at a fixture this file created and removes.
 *
 * The properties asserted:
 *
 *   1. A valid wp-content gets four links, pointing at the repository's
 *      components, and nothing else changes.
 *   2. A dry run creates nothing.
 *   3. Re-running is idempotent: it reports "already linked" and changes
 *      nothing.
 *   4. An existing real directory is a conflict: refused, left byte-identical,
 *      and the run exits non-zero.
 *   5. A link pointing somewhere else is a conflict: refused, left as it was.
 *   6. A path that is not a wp-content directory is refused before anything is
 *      created.
 *   7. The linked theme contains only theme files — no tests, no docs, no Git.
 */

require_once __DIR__ . '/bootstrap.php';

echo "install helper tests\n";

$repo   = dirname( __DIR__ );
$helper = $repo . '/tools/install-localwp.sh';

if ( ! is_file( $helper ) ) {
	Agentshell_Test::run(
		'the helper exists',
		function () {
			Agentshell_Test::assert_true( false, 'tools/install-localwp.sh is missing' );
		}
	);
	Agentshell_Test::summary();
	return;
}

$work = rtrim( sys_get_temp_dir(), '/' ) . '/as-install-' . getmypid() . '-' . bin2hex( random_bytes( 3 ) );
@mkdir( $work, 0777, true );

register_shutdown_function(
	function () use ( $work ) {
		if ( '' === $work || ! is_dir( $work ) ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $work, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $entry ) {
			if ( $entry->isLink() || $entry->isFile() ) {
				@unlink( $entry->getPathname() );
			} else {
				@rmdir( $entry->getPathname() );
			}
		}
		@rmdir( $work );
	}
);

/**
 * Build a fake wp-content and return its path.
 *
 * @param string $name Fixture name.
 * @return string
 */
function ih_site( $name ) {
	global $work;

	$root = $work . '/' . $name . '/app/public';
	@mkdir( $root . '/wp-content/themes', 0777, true );
	@mkdir( $root . '/wp-content/plugins', 0777, true );
	@mkdir( $root . '/wp-content/uploads', 0777, true );
	file_put_contents( $root . '/wp-config.php', "<?php // fixture\n" );
	file_put_contents( $root . '/wp-content/uploads/keep.txt', 'uploads survive' );

	return $root . '/wp-content';
}

/**
 * Run the helper and return [exit code, output].
 *
 * @param string $wp_content Path.
 * @param bool   $dry_run    Pass --dry-run.
 * @return array{0:int,1:string}
 */
function ih_run( $wp_content, $dry_run = false ) {
	global $helper;

	$cmd = 'bash ' . escapeshellarg( $helper ) . ' --wp-content ' . escapeshellarg( $wp_content );
	if ( $dry_run ) {
		$cmd .= ' --dry-run';
	}
	$cmd .= ' 2>&1';

	$output = array();
	$status = 0;
	exec( $cmd, $output, $status );

	return array( (int) $status, implode( "\n", $output ) );
}

/* ── 1. A clean install ─────────────────────────────────────────────────── */

Agentshell_Test::run(
	'a valid wp-content gets the theme and all three plugins, and nothing else',
	function () use ( $work ) {
		$site = ih_site( 'clean' );
		list( $status, $out ) = ih_run( $site );

		Agentshell_Test::assert_equals( 0, $status, 'the helper succeeds: ' . $out );
		Agentshell_Test::assert_true( is_link( $site . '/themes/agentshell' ), 'the theme is linked' );
		Agentshell_Test::assert_equals(
			dirname( __DIR__ ) . '/wordpress/themes/agentshell',
			readlink( $site . '/themes/agentshell' ),
			'the theme link points at the repository copy'
		);

		foreach ( array( 'agentshell-mcp', 'agentshell-blocks', 'agentshell-forms' ) as $plugin ) {
			Agentshell_Test::assert_true( is_link( $site . '/plugins/' . $plugin ), $plugin . ' is linked' );
			Agentshell_Test::assert_equals(
				dirname( __DIR__ ) . '/wordpress/plugins/' . $plugin,
				readlink( $site . '/plugins/' . $plugin ),
				$plugin . ' points at the repository copy'
			);
		}

		// Nothing else was created: two existing directories, four links.
		$entries = array_values( array_diff( (array) scandir( $site ), array( '.', '..' ) ) );
		sort( $entries );
		Agentshell_Test::assert_equals( array( 'plugins', 'themes', 'uploads' ), $entries, 'wp-content gained no other entry' );

		Agentshell_Test::assert_equals( 'uploads survive', file_get_contents( $site . '/uploads/keep.txt' ), 'uploads are untouched' );
	}
);

/* ── 2. Dry run ─────────────────────────────────────────────────────────── */

Agentshell_Test::run(
	'a dry run reports the links and creates nothing',
	function () use ( $work ) {
		$site = ih_site( 'dry' );
		list( $status, $out ) = ih_run( $site, true );

		Agentshell_Test::assert_equals( 0, $status, 'the dry run succeeds' );
		Agentshell_Test::assert_true( false !== strpos( $out, 'DRY RUN' ), 'it says it is a dry run' );
		Agentshell_Test::assert_true( false !== strpos( $out, 'would link' ), 'it reports what it would do' );
		Agentshell_Test::assert_false( is_link( $site . '/themes/agentshell' ), 'no theme link was created' );
		Agentshell_Test::assert_false( is_link( $site . '/plugins/agentshell-mcp' ), 'no plugin link was created' );
	}
);

/* ── 3. Idempotency ─────────────────────────────────────────────────────── */

Agentshell_Test::run(
	'a second run is idempotent and says so',
	function () use ( $work ) {
		$site = ih_site( 'twice' );
		ih_run( $site );
		list( $status, $out ) = ih_run( $site );

		Agentshell_Test::assert_equals( 0, $status, 'the second run succeeds' );
		Agentshell_Test::assert_true( false !== strpos( $out, 'already linked' ), 'it reports the links as unchanged' );
		Agentshell_Test::assert_true( false !== strpos( $out, '0 link(s) created, 4 already correct' ), 'nothing was created twice' );
	}
);

/* ── 4. A real directory is a conflict ──────────────────────────────────── */

Agentshell_Test::run(
	'an existing real directory is refused and left untouched',
	function () use ( $work ) {
		$site = ih_site( 'conflict' );
		@mkdir( $site . '/plugins/agentshell-blocks', 0777, true );
		file_put_contents( $site . '/plugins/agentshell-blocks/precious.txt', 'do not lose me' );

		list( $status, $out ) = ih_run( $site );

		Agentshell_Test::assert_equals( 1, $status, 'a conflict exits non-zero' );
		Agentshell_Test::assert_true( false !== strpos( $out, 'CONFLICT' ), 'the conflict is named' );
		Agentshell_Test::assert_false( is_link( $site . '/plugins/agentshell-blocks' ), 'the directory was not replaced by a link' );
		Agentshell_Test::assert_equals(
			'do not lose me',
			file_get_contents( $site . '/plugins/agentshell-blocks/precious.txt' ),
			'the existing directory still holds its file'
		);
		Agentshell_Test::assert_true( is_link( $site . '/themes/agentshell' ), 'the components without a conflict were still linked' );
	}
);

/* ── 5. A link elsewhere is a conflict ──────────────────────────────────── */

Agentshell_Test::run(
	'a link pointing somewhere else is refused and left as it was',
	function () use ( $work ) {
		$site = ih_site( 'otherlink' );
		$elsewhere = $work . '/elsewhere';
		@mkdir( $elsewhere, 0777, true );
		@symlink( $elsewhere, $site . '/plugins/agentshell-mcp' );

		list( $status, $out ) = ih_run( $site );

		Agentshell_Test::assert_equals( 1, $status, 'a foreign link exits non-zero' );
		Agentshell_Test::assert_true( false !== strpos( $out, 'is a link to' ), 'the conflict names what it points at' );
		Agentshell_Test::assert_equals( $elsewhere, readlink( $site . '/plugins/agentshell-mcp' ), 'the foreign link is unchanged' );
	}
);

/* ── 6. Not a wp-content directory ──────────────────────────────────────── */

Agentshell_Test::run(
	'a path that is not a wp-content directory is refused before anything is created',
	function () use ( $work ) {
		$not = $work . '/notwp';
		@mkdir( $not, 0777, true );

		list( $status, $out ) = ih_run( $not );

		Agentshell_Test::assert_equals( 2, $status, 'a wrong path is a usage error' );
		Agentshell_Test::assert_true( false !== strpos( $out, 'does not look like a wp-content' ), 'the refusal explains itself' );
		Agentshell_Test::assert_equals( array( '.', '..' ), (array) scandir( $not ), 'nothing was created in the wrong directory' );

		list( $missing_status ) = ih_run( $work . '/does-not-exist' );
		Agentshell_Test::assert_equals( 2, $missing_status, 'a missing path is refused' );
	}
);

/* ── 7. No development files reach the site ─────────────────────────────── */

Agentshell_Test::run(
	'the linked theme holds the theme and no development material',
	function () use ( $work ) {
		$site = ih_site( 'clean-view' );
		ih_run( $site );

		$theme = $site . '/themes/agentshell';

		foreach ( array( 'style.css', 'functions.php', 'template-parts', 'configurator', 'widgets', 'assets' ) as $present ) {
			Agentshell_Test::assert_true( file_exists( $theme . '/' . $present ), 'the theme has ' . $present );
		}

		foreach ( array( 'tests', 'docs', 'tools', 'skills', '.git', 'AGENTS.md' ) as $absent ) {
			Agentshell_Test::assert_false( file_exists( $theme . '/' . $absent ), 'the site sees no ' . $absent );
		}

		foreach ( array( 'agentshell-mcp', 'agentshell-blocks', 'agentshell-forms' ) as $plugin ) {
			Agentshell_Test::assert_true(
				file_exists( $site . '/plugins/' . $plugin . '/' . $plugin . '.php' ),
				$plugin . ' is activatable from the site'
			);
		}
	}
);
