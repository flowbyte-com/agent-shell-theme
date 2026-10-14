<?php
/**
 * Isolated-browser trust and temporary-file regressions.
 *
 * Redirecting the browser's HOME buys two things — a profile nobody else can
 * read, and a profile nobody else can reuse — and costs one thing nobody
 * noticed until a capture had to succeed: certificate trust. Chromium keeps
 * its trusted roots in `$HOME/.pki/nssdb`, and on a host with no system NSS
 * database there is nothing else to fall back on, so pointing HOME at a
 * throwaway directory silently removes every root the capture needs.
 *
 * The fix is not to stop isolating. It is to build a *fresh* trust store
 * inside the profile, containing only the certificates the operator named
 * explicitly — so a trusted local certificate works and an untrusted one still
 * fails, which is the distinction `--ignore-certificate-errors` throws away.
 *
 * The same environment redirection fixes a second leak. Chromium creates
 * scratch directories of its own (`com.google.Chrome.chrome_chrome_url_fetcher_*`)
 * in the *system* temp dir while rendering; because they sit outside the
 * profile they survived its removal, one per capture, on the success path too.
 * TMPDIR now points inside the profile.
 *
 * These are executable rather than source pins because the properties are about
 * what a real browser was handed and what is left on disk afterwards. The
 * first group runs against the fake browser and always runs; the last group
 * runs real Chrome against a real TLS endpoint this suite starts itself, which
 * is the only way to prove that an untrusted certificate is still rejected.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/fake-browser.php';

require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/class-screenshot.php';

use AgentShell_MCP\Screenshot;

// Deliberately short. Every case runs with this directory as TMPDIR, so the
// profile the browser ends up with sits at
// <root>/agentshell-chrome-<uid>/profile-<16 hex>/tmp — and that path is a
// Chromium socket path, which has a 107-byte cap. A test root with a long,
// self-describing name would fail every case for a reason that has nothing to
// do with what the case is testing.
define( 'AGENTSHELL_TRUST_TEST_ROOT', '/tmp/at' . substr( md5( uniqid( '', true ) ), 0, 6 ) );

/**
 * Child script.
 *
 * One process per case, for three reasons that all bite in practice:
 * AGENTSHELL_TRUSTED_CA_FILES is a constant and cannot be redefined; the
 * capture lock is a flock() across processes; and the profile base is derived
 * from sys_get_temp_dir(), which PHP resolves once per process and caches — so
 * only a process that sets TMPDIR before anything asks for it sees its own
 * fixtures rather than the real /tmp.
 *
 * argv: 1 class-file  2 workdir  3 json-op
 */
function agentshell_trust_child_source() {
    return <<<'PHP'
<?php
$class_file = $argv[1];
$workdir    = $argv[2];
$op         = json_decode( $argv[3], true );

putenv( 'TMPDIR=' . $workdir );
if ( ! empty( $op['home'] ) ) { putenv( 'HOME=' . $op['home'] ); }

if ( isset( $op['ca_files'] ) ) {
    define( 'AGENTSHELL_TRUSTED_CA_FILES', $op['ca_files'] );
}

define( 'ABSPATH', __DIR__ );
$GLOBALS['agentshell_test_uploads'] = array(
    'basedir' => $workdir . '/uploads',
    'baseurl' => 'https://example.test/wp-content/uploads',
);
function wp_upload_dir() {
    return array(
        'basedir' => $GLOBALS['agentshell_test_uploads']['basedir'],
        'baseurl' => $GLOBALS['agentshell_test_uploads']['baseurl'],
        'error'   => '',
    );
}
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0755, true ); }
function wp_generate_password( $len = 12, $special = true, $extra = false ) {
    return substr( bin2hex( random_bytes( (int) ceil( $len / 2 ) ) ), 0, $len );
}
function home_url( $p = '/' ) { return 'https://example.test' . $p; }

require $class_file;

use AgentShell_MCP\Screenshot;

if ( ! empty( $op['browser'] ) ) { define( 'AGENTSHELL_CHROME_BIN', $op['browser'] ); }
if ( ! empty( $op['timeout'] ) ) { define( 'AGENTSHELL_SCREENSHOT_TIMEOUT', (int) $op['timeout'] ); }

if ( ! empty( $op['fake_mode'] ) )  { putenv( 'AGENTSHELL_FAKE_CHROME_MODE=' . $op['fake_mode'] ); }
if ( ! empty( $op['argv_dump'] ) )  { putenv( 'AGENTSHELL_FAKE_CHROME_ARGV=' . $op['argv_dump'] ); }
if ( ! empty( $op['env_dump'] ) )   { putenv( 'AGENTSHELL_FAKE_CHROME_ENV=' . $op['env_dump'] ); }
if ( ! empty( $op['scratch'] ) )    { putenv( 'AGENTSHELL_FAKE_CHROME_SCRATCH=' . $op['scratch'] ); }

/** Every file below a directory, as paths relative to it. */
function agentshell_trust_tree( $base ) {
    $out = array();
    if ( ! is_dir( $base ) ) { return $out; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ( $it as $f ) {
        $out[] = substr( $f->getPathname(), strlen( $base ) + 1 )
            . ( $f->isDir() ? '/' : '' );
    }
    sort( $out );
    return $out;
}

/** Nicknames present in an NSS database, via certutil. */
function agentshell_trust_nicknames( $db ) {
    if ( ! is_dir( $db ) ) { return array(); }
    $certutil = trim( (string) @shell_exec( 'command -v certutil 2>/dev/null' ) );
    if ( '' === $certutil ) { return array( '<certutil-missing>' ); }
    $out = array();
    exec( escapeshellcmd( $certutil ) . ' -L -d ' . escapeshellarg( $db ) . ' 2>/dev/null', $lines );
    foreach ( $lines as $line ) {
        if ( preg_match( '/agentshell-ca-/', $line ) ) { $out[] = trim( $line ); }
    }
    return $out;
}

$out = array( 'ok' => false );

try {
    if ( 'profile' === $op['mode'] ) {
        $path = Screenshot::create_profile_dir();
        clearstatcache( true, $path );
        $out = array(
            'ok'      => true,
            'path'    => $path,
            'tree'    => agentshell_trust_tree( $path ),
            'mode'    => substr( sprintf( '%o', fileperms( $path ) ), -3 ),
            'db_mode' => is_dir( Screenshot::trust_db_dir( $path ) )
                ? substr( sprintf( '%o', fileperms( Screenshot::trust_db_dir( $path ) ) ), -3 )
                : '',
            'tmp_mode' => is_dir( Screenshot::profile_tmp_dir( $path ) )
                ? substr( sprintf( '%o', fileperms( Screenshot::profile_tmp_dir( $path ) ) ), -3 )
                : '',
            'nicknames' => agentshell_trust_nicknames( Screenshot::trust_db_dir( $path ) ),
            'kept'    => ! empty( $op['keep'] ),
        );
        if ( empty( $op['keep'] ) ) {
            Screenshot::destroy_profile_dir( $path );
            $out['destroyed'] = ! is_dir( $path );
        }
    } else {
        $shot = Screenshot::capture( $op['url'], 800, 600, $workdir . '/shots' );
        $out = array(
            'ok'     => true,
            'size'   => file_exists( $shot['file'] ) ? filesize( $shot['file'] ) : 0,
            'is_png' => file_exists( $shot['file'] )
                && substr( (string) file_get_contents( $shot['file'] ), 0, 8 ) === "\x89PNG\r\n\x1a\n",
        );
    }
} catch ( \Throwable $e ) {
    $out = array( 'ok' => false, 'kind' => 'error', 'message' => $e->getMessage() );
}

// What is still under the profile base while this process is still alive.
// create_profile_dir() also registers a shutdown handler, so checking only
// after the child exits could not tell "the finally removed it" from "the
// shutdown handler removed it on the way out".
$out['leftover'] = array();
$base = Screenshot::profile_base_dir();
if ( is_dir( $base ) ) {
    foreach ( (array) scandir( $base ) as $entry ) {
        if ( '.' !== $entry && '..' !== $entry ) { $out['leftover'][] = $entry; }
    }
    sort( $out['leftover'] );
}
echo json_encode( $out );
PHP;
}

/**
 * Run one case in a child process.
 *
 * @param array $op      Operation to run.
 * @param string $suffix Short name, used only to keep the case's generated
 *                       script names apart.
 * @return array Decoded child output.
 */
function agentshell_trust_run( array $op, $suffix ) {
    $root   = AGENTSHELL_TRUST_TEST_ROOT;
    $work   = $root;
    $script = $root . '/child-' . $suffix . '.php';
    file_put_contents( $script, agentshell_trust_child_source() );

    $argv_json = json_encode( array_values( array(
        $script,
        dirname( __DIR__ ) . '/agentshell-mcp/includes/class-screenshot.php',
        $work,
        json_encode( $op ),
    ) ) );
    // proc_open needs a real argv array, so pass it through a wrapper file that
    // splices the decoded list in as $argv — with the child script as argv[0],
    // so the child sees exactly the argv a direct `php child.php …` would give
    // it. Diagnostics go to stderr explicitly: the harness runs with
    // display_errors off, and a child that dies must say so rather than
    // reporting an empty result.
    $runner = $root . '/run-' . $suffix . '.php';
    file_put_contents(
        $runner,
        "<?php\nini_set('display_errors', 'stderr');\nerror_reporting(E_ALL);\n"
        . "\$argv = json_decode(\$argv[1], true);\nrequire \$argv[0];\n"
    );

    $descriptors = array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
    $pipes       = array();
    $process     = proc_open(
        array( PHP_BINARY, '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', $runner, $argv_json ),
        $descriptors,
        $pipes
    );
    $stdout = stream_get_contents( $pipes[1] );
    $stderr = stream_get_contents( $pipes[2] );
    fclose( $pipes[1] );
    fclose( $pipes[2] );
    $status = proc_close( $process );
    if ( '' === trim( $stdout ) && '' === trim( $stderr ) ) {
        $stderr .= ' [child exit ' . $status . ' with no output]';
    }

    $decoded = json_decode( trim( $stdout ), true );
    if ( ! is_array( $decoded ) ) {
        return array( 'ok' => false, 'kind' => 'harness', 'message' => trim( $stdout . ' ' . $stderr ) );
    }
    return $decoded;
}

/** Write a throwaway certificate file, so tests never depend on a real CA. */
function agentshell_trust_cert( $path, $cn = 'agentshell-trust-test' ) {
    @mkdir( dirname( $path ), 0700, true );
    $out = array();
    $rc  = 1;
    exec(
        'openssl req -x509 -newkey rsa:2048 -nodes -keyout ' . escapeshellarg( $path . '.key' )
        . ' -out ' . escapeshellarg( $path )
        . ' -days 2 -subj ' . escapeshellarg( '/CN=' . $cn )
        . ' -addext ' . escapeshellarg( 'subjectAltName=DNS:localhost,IP:127.0.0.1' )
        . ' 2>/dev/null',
        $out,
        $rc
    );
    return 0 === $rc && is_file( $path );
}

if ( ! function_exists( 'agentshell_rm_tree' ) ) {
    function agentshell_rm_tree( $path ) {
        if ( is_link( $path ) || ! is_dir( $path ) ) { return @unlink( $path ); }
        foreach ( (array) scandir( $path ) as $entry ) {
            if ( '.' !== $entry && '..' !== $entry ) { agentshell_rm_tree( $path . '/' . $entry ); }
        }
        return @rmdir( $path );
    }
}

/** Read a KEY=value dump the fake browser writes. */
function agentshell_test_read_kv( $file ) {
    $out = array();
    foreach ( (array) @file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
        $parts = explode( '=', $line, 2 );
        if ( 2 === count( $parts ) ) { $out[ $parts[0] ] = $parts[1]; }
    }
    return $out;
}

$root = AGENTSHELL_TRUST_TEST_ROOT;
@mkdir( $root, 0700, true );
$fake     = agentshell_fake_browser_path( $root );
$cert_a   = $root . '/fixtures/ca-a.pem';
$cert_b   = $root . '/fixtures/ca-b.pem';
$have_ca  = agentshell_trust_cert( $cert_a, 'agentshell-ca-a' ) && agentshell_trust_cert( $cert_b, 'agentshell-ca-b' );

// A stand-in for the operator's real NSS directory, holding something that
// must never appear inside a capture profile.
$operator_home = $root . '/operator-home';
@mkdir( $operator_home . '/.pki/nssdb', 0700, true );
file_put_contents( $operator_home . '/.pki/nssdb/key4.db', 'OPERATOR-PRIVATE-KEY-SENTINEL' );
file_put_contents( $operator_home . '/.pki/nssdb/session-history.db', 'OPERATOR-SESSION-SENTINEL' );

Agentshell_Test::run( 'trust: no configured authorities means no trust store is built', function () use ( $fake ) {
    $run = agentshell_trust_run(
        array( 'mode' => 'profile', 'browser' => $fake, 'ca_files' => array() ),
        'no-ca'
    );
    Agentshell_Test::assert_true( $run['ok'], 'profile creation: ' . json_encode( $run ) );
    $joined = implode( "\n", $run['tree'] );
    Agentshell_Test::assert_false(
        false !== strpos( $joined, 'nssdb' ),
        'an unconfigured capture must not build an NSS database'
    );
    Agentshell_Test::assert_true( $run['destroyed'], 'the profile is still removed afterwards' );
} );

Agentshell_Test::run( 'trust: a configured certificate is installed into a fresh profile-local database', function () use ( $fake, $cert_a, $have_ca ) {
    Agentshell_Test::assert_true( $have_ca, 'openssl is needed to build the fixture certificate' );
    $run = agentshell_trust_run(
        array( 'mode' => 'profile', 'browser' => $fake, 'ca_files' => array( $cert_a ) ),
        'one-ca'
    );
    Agentshell_Test::assert_true( $run['ok'], 'profile creation: ' . json_encode( $run ) );
    $joined = implode( "\n", $run['tree'] );
    Agentshell_Test::assert_true(
        false !== strpos( $joined, 'home/.pki/nssdb/' ),
        'the trust store must be at HOME/.pki/nssdb, where Chromium looks'
    );
    Agentshell_Test::assert_true(
        false !== strpos( $joined, 'home/.pki/nssdb/cert9.db' ),
        'certutil -N must have created the certificate database'
    );
    Agentshell_Test::assert_equals( 1, count( $run['nicknames'] ), 'exactly one certificate may be trusted' );
    Agentshell_Test::assert_true(
        false !== strpos( implode( ' ', $run['nicknames'] ), 'agentshell-ca-1' ),
        'the configured certificate must be present under our own nickname'
    );
    Agentshell_Test::assert_equals( '700', $run['db_mode'], 'the trust store must be private' );
} );

Agentshell_Test::run( 'trust: the operator\'s own NSS database is never copied or read', function () use ( $fake, $cert_a, $operator_home ) {
    $run = agentshell_trust_run(
        array(
            'mode'     => 'profile',
            'browser'  => $fake,
            'ca_files' => array( $cert_a ),
            'home'     => $operator_home,
        ),
        'operator-db'
    );
    Agentshell_Test::assert_true( $run['ok'], 'profile creation: ' . json_encode( $run ) );
    $joined = implode( "\n", $run['tree'] );
    Agentshell_Test::assert_false(
        false !== strpos( $joined, 'session-history.db' ),
        "the operator's browser session must not be carried into a capture profile"
    );
    Agentshell_Test::assert_false(
        false !== strpos( $joined, 'SENTINEL' ),
        'no operator sentinel may appear inside the profile'
    );
} );

Agentshell_Test::run( 'trust: an unreadable configured path fails loudly instead of silently', function () use ( $fake, $root ) {
    $missing = $root . '/fixtures/does-not-exist.pem';
    $run     = agentshell_trust_run(
        array( 'mode' => 'profile', 'browser' => $fake, 'ca_files' => array( $missing ) ),
        'bad-ca'
    );
    Agentshell_Test::assert_false( $run['ok'], 'a missing certificate must fail the capture' );
    Agentshell_Test::assert_true(
        false !== strpos( (string) $run['message'], 'AGENTSHELL_TRUSTED_CA_FILES' ),
        'the error must name the setting, or a typo is indistinguishable from an untrusted cert: '
        . json_encode( $run )
    );
} );

Agentshell_Test::run( 'trust: a configured directory contributes every certificate in it', function () use ( $fake, $root, $have_ca ) {
    Agentshell_Test::assert_true( $have_ca, 'openssl is needed to build the fixture certificates' );
    $run = agentshell_trust_run(
        array( 'mode' => 'profile', 'browser' => $fake, 'ca_files' => array( $root . '/fixtures' ) ),
        'dir-ca'
    );
    Agentshell_Test::assert_true( $run['ok'], 'profile creation: ' . json_encode( $run ) );
    Agentshell_Test::assert_equals( 2, count( $run['nicknames'] ), 'both certificates in the directory must be trusted' );
} );

Agentshell_Test::run( 'trust: the profile, trust store and temp dir are all private', function () use ( $fake, $cert_a ) {
    $run = agentshell_trust_run(
        array( 'mode' => 'profile', 'browser' => $fake, 'ca_files' => array( $cert_a ) ),
        'modes'
    );
    Agentshell_Test::assert_true( $run['ok'], 'profile creation: ' . json_encode( $run ) );
    Agentshell_Test::assert_equals( '700', $run['mode'], 'profile directory mode' );
    Agentshell_Test::assert_equals( '700', $run['db_mode'], 'trust store mode' );
    Agentshell_Test::assert_equals( '700', $run['tmp_mode'], 'TMPDIR mode' );
} );

Agentshell_Test::run( 'temp: the browser is handed a TMPDIR inside the throwaway profile', function () use ( $fake, $root ) {
    $env_dump = $root . '/tmpdir-env.txt';
    $run      = agentshell_trust_run(
        array(
            'mode'      => 'capture',
            'browser'   => $fake,
            'ca_files'  => array(),
            'url'       => 'https://example.test/',
            'env_dump'  => $env_dump,
            'scratch'   => 'com.google.Chrome.chrome_chrome_url_fetcher_.TEST',
        ),
        'tmpdir'
    );
    Agentshell_Test::assert_true( $run['ok'], 'capture must succeed: ' . json_encode( $run ) );
    $env = agentshell_test_read_kv( $env_dump );
    Agentshell_Test::assert_true(
        isset( $env['TMPDIR'] ) && $env['TMPDIR'] !== '<unset>',
        'TMPDIR must be set for the browser: ' . json_encode( $env )
    );
    Agentshell_Test::assert_true(
        0 === strpos( $env['TMPDIR'], $root ),
        'TMPDIR must point inside the suite\'s own profile base, not the shared temp dir: ' . $env['TMPDIR']
    );
    Agentshell_Test::assert_true(
        false !== strpos( $env['TMPDIR'], '/profile-' ),
        'TMPDIR must be inside a per-capture profile directory: ' . $env['TMPDIR']
    );
} );

Agentshell_Test::run( 'temp: a Chromium scratch directory does not outlive the capture', function () use ( $fake, $root ) {
    $env_dump = $root . '/scratch-env.txt';
    $run      = agentshell_trust_run(
        array(
            'mode'     => 'capture',
            'browser'  => $fake,
            'ca_files' => array(),
            'url'      => 'https://example.test/',
            'env_dump' => $env_dump,
            'scratch'  => 'com.google.Chrome.chrome_chrome_url_fetcher_.TEST',
        ),
        'scratch'
    );
    Agentshell_Test::assert_true( $run['ok'], 'capture must succeed: ' . json_encode( $run ) );

    $scratch = @file_get_contents( $env_dump . '.scratch' );
    Agentshell_Test::assert_true( is_string( $scratch ) && '' !== $scratch, 'the browser must have created a scratch directory' );
    Agentshell_Test::assert_true(
        0 === strpos( $scratch, $root ),
        'the scratch directory must be created inside the profile, not in the system temp dir: ' . $scratch
    );
    Agentshell_Test::assert_false(
        file_exists( $scratch ),
        "Chromium's scratch directory must be removed with the profile: {$scratch}"
    );
    Agentshell_Test::assert_equals( array(), $run['leftover'], 'no profile may be left behind' );
} );

Agentshell_Test::run( 'temp: the profile is removed after browser failure and after a timeout', function () use ( $fake, $root ) {
    foreach ( array( 'fail', 'hang' ) as $mode ) {
        $env_dump = $root . '/survival-' . $mode . '-env.txt';
        $run      = agentshell_trust_run(
            array(
                'mode'      => 'capture',
                'browser'   => $fake,
                'ca_files'  => array(),
                'url'       => 'https://example.test/',
                'fake_mode' => $mode,
                'timeout'   => 2,
                'env_dump'  => $env_dump,
                'scratch'   => 'com.google.Chrome.chrome_chrome_url_fetcher_.SURVIVE',
            ),
            'survival-' . $mode
        );
        Agentshell_Test::assert_false( $run['ok'], "a {$mode} browser must not report success" );
        Agentshell_Test::assert_equals( array(), $run['leftover'], "no profile may survive a {$mode}" );

        $scratch = @file_get_contents( $env_dump . '.scratch' );
        Agentshell_Test::assert_true( is_string( $scratch ) && '' !== $scratch, "the {$mode} browser must have run" );
        Agentshell_Test::assert_false(
            file_exists( $scratch ),
            "a scratch directory must not survive a {$mode}: {$scratch}"
        );
    }
} );

Agentshell_Test::run( 'isolation: two captures still use different profiles', function () use ( $fake, $root, $cert_a ) {
    $a = $root . '/argv-a.txt';
    $b = $root . '/argv-b.txt';
    foreach ( array( $a, $b ) as $dump ) {
        $run = agentshell_trust_run(
            array(
                'mode'      => 'capture',
                'browser'   => $fake,
                'ca_files'  => array( $cert_a ),
                'url'       => 'https://example.test/',
                'argv_dump' => $dump,
                'env_dump'  => $dump . '.env',
            ),
            'distinct-' . md5( $dump )
        );
        Agentshell_Test::assert_true( $run['ok'], 'capture must succeed: ' . json_encode( $run ) );
    }
    $profile_of = function ( $dump ) {
        foreach ( (array) file( $dump, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $arg ) {
            if ( 0 === strpos( $arg, '--user-data-dir=' ) ) { return substr( $arg, 17 ); }
        }
        return '';
    };
    $pa = $profile_of( $a );
    $pb = $profile_of( $b );
    Agentshell_Test::assert_true( '' !== $pa && '' !== $pb, 'both captures must carry --user-data-dir' );
    Agentshell_Test::assert_not_equals( $pa, $pb, 'each capture must get its own profile' );
} );

// ---------------------------------------------------------------------------
// Real Chrome, real TLS.
//
// A fake browser cannot show that Chromium accepts a pinned certificate, and
// it certainly cannot show that it still rejects an unpinned one — which is
// the property that separates "trust the operator's list" from "skip
// verification". So this group stands up its own TLS endpoint with a
// self-signed certificate this suite generated, and points real Chrome at it.
// ---------------------------------------------------------------------------

$openssl = trim( (string) @shell_exec( 'command -v openssl 2>/dev/null' ) );
$backend = Screenshot::backend();
// AGENTSHELL_TRUST_SKIP_CHROME lets the mutation run — which would otherwise
// spend a minute of real TLS per mutant — and a host without Chrome skip the
// two most expensive cases. Everything else in this suite still runs.
$tls_ok  = ( '' === (string) getenv( 'AGENTSHELL_TRUST_SKIP_CHROME' ) )
    && ( '' !== $openssl ) && ( null !== $backend ) && $have_ca;

/** A free TCP port on loopback. */
function agentshell_trust_free_port() {
    $sock = @stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
    if ( ! $sock ) { return 0; }
    $name = stream_socket_get_name( $sock, false );
    fclose( $sock );
    return (int) substr( $name, strrpos( $name, ':' ) + 1 );
}

Agentshell_Test::run( 'chrome: an explicitly trusted certificate permits an https capture', function () use ( $fake, $root, $tls_ok, $openssl ) {
    if ( ! $tls_ok ) {
        Agentshell_Test::assert_true( true, 'skipped: needs openssl and a real Chrome' );
        return;
    }

    $cert = $root . '/fixtures/tls-endpoint.pem';
    $key  = $cert . '.key';
    @unlink( $cert );
    $rc = 1;
    exec(
        'openssl req -x509 -newkey rsa:2048 -nodes -keyout ' . escapeshellarg( $key )
        . ' -out ' . escapeshellarg( $cert )
        . ' -days 2 -subj ' . escapeshellarg( '/CN=localhost' )
        . ' -addext ' . escapeshellarg( 'subjectAltName=DNS:localhost,IP:127.0.0.1' )
        . ' 2>/dev/null',
        $out,
        $rc
    );
    Agentshell_Test::assert_equals( 0, $rc, 'the TLS fixture certificate must be generated' );

    $port   = agentshell_trust_free_port();
    $url    = 'https://localhost:' . $port . '/';
    $server = proc_open(
        array( $openssl, 's_server', '-accept', (string) $port, '-cert', $cert, '-key', $key, '-www', '-quiet' ),
        array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', '/dev/null', 'a' ), 2 => array( 'file', '/dev/null', 'a' ) ),
        $pipes
    );

    // Wait for the listener rather than sleeping a guessed interval.
    $ready = false;
    for ( $i = 0; $i < 60; $i++ ) {
        $probe = @stream_socket_client( 'tcp://127.0.0.1:' . $port, $e1, $e2, 0.2 );
        if ( $probe ) { fclose( $probe ); $ready = true; break; }
        usleep( 100000 );
    }

    try {
        Agentshell_Test::assert_true( $ready, 'the TLS fixture must start listening' );

        $trusted = agentshell_trust_run(
            array( 'mode' => 'capture', 'browser' => '', 'ca_files' => array( $cert ), 'url' => $url, 'timeout' => 30 ),
            'chrome-trusted'
        );
        Agentshell_Test::assert_true( $trusted['ok'], 'a pinned certificate must allow the capture: ' . json_encode( $trusted ) );
        Agentshell_Test::assert_true( $trusted['is_png'], 'the trusted capture must be a real PNG' );

        $untrusted = agentshell_trust_run(
            array( 'mode' => 'capture', 'browser' => '', 'ca_files' => array(), 'url' => $url, 'timeout' => 30 ),
            'chrome-untrusted'
        );
        Agentshell_Test::assert_false(
            $untrusted['ok'],
            'an untrusted certificate must still fail: ' . json_encode( $untrusted )
        );
        Agentshell_Test::assert_true(
            false !== strpos( (string) $untrusted['message'], 'ERR_CERT' ),
            'the failure must be a certificate failure, not something incidental: ' . json_encode( $untrusted )
        );
    } finally {
        proc_terminate( $server, 9 );
        proc_close( $server );
    }
} );

Agentshell_Test::run( 'chrome: no Chromium scratch directory survives in the system temp dir', function () use ( $fake, $root, $tls_ok ) {
    if ( ! $tls_ok ) {
        Agentshell_Test::assert_true( true, 'skipped: needs openssl and a real Chrome' );
        return;
    }

    // Only count directories this suite can attribute: Chromium's own
    // url_fetcher prefix, created while we were not running anything else.
    $before = 0;
    foreach ( (array) glob( sys_get_temp_dir() . '/com.google.Chrome.chrome_chrome_url_fetcher_*' ) as $stray ) {
        $before++;
    }

    $cert = $root . '/fixtures/tls-endpoint.pem';
    $key  = $cert . '.key';
    $port = agentshell_trust_free_port();
    $url  = 'https://localhost:' . $port . '/';
    $server = proc_open(
        array(
            trim( (string) @shell_exec( 'command -v openssl 2>/dev/null' ) ),
            's_server', '-accept', (string) $port, '-cert', $cert, '-key', $key, '-www', '-quiet',
        ),
        array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', '/dev/null', 'a' ), 2 => array( 'file', '/dev/null', 'a' ) ),
        $pipes
    );
    $ready = false;
    for ( $i = 0; $i < 60; $i++ ) {
        $probe = @stream_socket_client( 'tcp://127.0.0.1:' . $port, $e1, $e2, 0.2 );
        if ( $probe ) { fclose( $probe ); $ready = true; break; }
        usleep( 100000 );
    }

    try {
        Agentshell_Test::assert_true( $ready, 'the TLS fixture must start listening' );
        $run = agentshell_trust_run(
            array( 'mode' => 'capture', 'browser' => '', 'ca_files' => array( $cert ), 'url' => $url, 'timeout' => 30 ),
            'chrome-stray'
        );
        Agentshell_Test::assert_true( $run['ok'], 'the capture must succeed: ' . json_encode( $run ) );
        Agentshell_Test::assert_equals( array(), $run['leftover'], 'no profile may survive' );
    } finally {
        proc_terminate( $server, 9 );
        proc_close( $server );
    }

    $after = 0;
    foreach ( (array) glob( sys_get_temp_dir() . '/com.google.Chrome.chrome_chrome_url_fetcher_*' ) as $stray ) {
        $after++;
    }
    Agentshell_Test::assert_equals(
        $before,
        $after,
        "real Chrome captures must leave no scratch directory in the system temp dir (before {$before}, after {$after})"
    );
} );

Agentshell_Test::run( 'temp: the browser temp dir fits Chromium\'s socket path limit', function () use ( $fake ) {
    // Pointing TMPDIR inside the profile puts the profile path on a unix socket
    // path, which is capped. This is the arithmetic that keeps a capture from
    // failing inside the browser with a FATAL it never explains; the two
    // real-Chrome tests above are what prove the limit is real.
    $run = agentshell_trust_run(
        array( 'mode' => 'profile', 'browser' => $fake, 'ca_files' => array() ),
        'budget'
    );
    Agentshell_Test::assert_true( $run['ok'], 'profile creation: ' . json_encode( $run ) );
    $tmp     = Screenshot::profile_tmp_dir( $run['path'] );
    $socket  = $tmp . '/com.google.Chrome.XXXXXX/SingletonSocket';
    Agentshell_Test::assert_true(
        strlen( $tmp ) <= Screenshot::MAX_TMPDIR_LENGTH,
        'TMPDIR is ' . strlen( $tmp ) . ' bytes, over the ' . Screenshot::MAX_TMPDIR_LENGTH . '-byte budget: ' . $tmp
    );
    Agentshell_Test::assert_true(
        strlen( $socket ) <= 107,
        'the socket path Chromium builds would be ' . strlen( $socket ) . ' bytes, over sockaddr_un: ' . $socket
    );
} );

Agentshell_Test::run( 'cleanup: the suite leaves no profile behind anywhere', function () use ( $root ) {
    Agentshell_Test::assert_true( is_dir( $root . '/fixtures' ), 'the fixture certificates were created' );
    // The children set TMPDIR to the suite root, so any profile they failed to
    // remove would still be visible here from outside them.
    $stray = array();
    foreach ( (array) glob( $root . '/*/agentshell-chrome-*/profile-*', GLOB_ONLYDIR ) as $dir ) { $stray[] = $dir; }
    foreach ( (array) glob( $root . '/agentshell-chrome-*/profile-*', GLOB_ONLYDIR ) as $dir ) { $stray[] = $dir; }
    Agentshell_Test::assert_equals( array(), $stray, 'no profile may survive: ' . implode( ', ', $stray ) );
    agentshell_rm_tree( $root );
    Agentshell_Test::assert_false( is_dir( $root ), 'the suite root is removed' );
} );

register_shutdown_function( function () use ( $root ) {
    if ( is_dir( $root ) ) { agentshell_rm_tree( $root ); }
} );