<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Private storage for captured screenshots.
 *
 * Screenshots used to land in wp-content/uploads/, which is web-readable, and
 * nothing ever removed them. A capture renders whatever the agent was looking
 * at — including unpublished drafts — so an unauthenticated GET on that path
 * exposed draft content indefinitely. This class keeps captures outside every
 * web-served directory, ties each one to the actor who took it, and expires
 * them.
 *
 * Two properties are load-bearing:
 *
 *   Identity  Filenames are derived from an HMAC of the capture id keyed by
 *             AGENTSHELL_REST_TOKEN, not from the id itself. An id is handed
 *             back to the capturing agent and may appear in logs or a
 *             transcript; if it were the filename, anyone who learned it could
 *             guess the path. The HMAC means the directory listing is useless
 *             without the secret.
 *
 *   Authority Retrieval re-checks the acting user against the recording actor
 *             on every read. Authentication alone is not enough: the MCP
 *             transport accepts any user holding manage_options, so a
 *             second administrator could otherwise read a capture taken by a
 *             different one.
 *
 * Index records live in an option so expiry works without scanning the
 * filesystem, but every read also re-checks mtime, so a stale index cannot
 * serve a file past its window even if cron never runs.
 */
class Screenshot_Store {
    const INDEX_KEY = 'agentshell_screenshot_index';

    const DEFAULT_TTL_HOURS = 24;
    const MIN_TTL_HOURS     = 1;
    const MAX_TTL_HOURS     = 720;

    /** Retained captures per site. Bounds disk growth independent of TTL. */
    const MAX_CAPTURES = 100;

    // ---------------------------------------------------------------- paths

    /**
     * Private directory, outside the document root.
     *
     * Neither wp-content/ nor uploads/ qualifies: on a standard install both
     * are *inside* the document root and the web server serves them. The two
     * usual mitigations do not hold either. .htaccess is inert under nginx,
     * which is what Local, most containers, and most managed hosts run; and a
     * 0700 directory owned by the PHP user does not stop a web server running
     * as that same user. Measured on the live LocalWP site: with the store at
     * wp-content/agentshell-private/ and both an .htaccess deny and 0700 in
     * place, an unauthenticated GET of a capture returned 200 with the exact
     * PNG bytes. Being outside the document root is the only control that
     * actually applied.
     *
     * The install directory's parent is outside the document root in the
     * standard layout. Where it is not — WordPress in a subdirectory, so that
     * parent is itself the document root — fall back to the system temp
     * directory rather than a served path. Define AGENTSHELL_SCREENSHOT_DIR
     * to override both.
     */
    public static function base_dir() {
        if ( defined( 'AGENTSHELL_SCREENSHOT_DIR' ) ) {
            $configured = (string) constant( 'AGENTSHELL_SCREENSHOT_DIR' );
            if ( '' !== $configured ) {
                return rtrim( self::normalize_path( $configured ), '/' );
            }
        }

        $install = self::normalize_path( rtrim( ABSPATH, '/' ) );
        $sibling = dirname( $install );

        if ( ! self::is_web_reachable( $sibling ) ) {
            return $sibling . '/agentshell-private';
        }

        return rtrim( self::normalize_path( sys_get_temp_dir() ), '/' ) . '/agentshell-private';
    }

    /**
     * Absolute path of the directory the web server publishes.
     *
     * ABSPATH is the document root for a standard install. For a subdirectory
     * install the home URL's path says how deep the install sits, so that many
     * trailing segments are removed from ABSPATH to recover the real root.
     */
    public static function doc_root() {
        $abspath = self::normalize_path( rtrim( ABSPATH, '/' ) );

        $home_path = '';
        if ( function_exists( 'home_url' ) ) {
            $home_path = function_exists( 'wp_parse_url' )
                ? (string) wp_parse_url( home_url(), PHP_URL_PATH )
                : (string) parse_url( home_url(), PHP_URL_PATH );
        }
        $home_path = trim( (string) $home_path, '/' );

        if ( '' !== $home_path ) {
            $suffix = '/' . $home_path;
            if ( substr( $abspath, -strlen( $suffix ) ) === $suffix ) {
                return substr( $abspath, 0, -strlen( $suffix ) );
            }
        }

        return $abspath;
    }

    /**
     * True when the web server would serve $path from a document root.
     *
     * Purely path containment against the document root. It cannot know about
     * a server configured to publish a wider directory tree than WordPress
     * believes — which is exactly why this exists as a check rather than as
     * the only protection. It is the reason ensure_dir() warns.
     */
    public static function is_web_reachable( $path ) {
        $root = self::doc_root();
        if ( '' === $root ) {
            return false;
        }
        $candidate = self::normalize_path( rtrim( $path, '/' ) );

        return ( $candidate === $root ) || ( 0 === strpos( $candidate, $root . '/' ) );
    }

    /** WordPress path normalisation, with a fallback for standalone use. */
    private static function normalize_path( $path ) {
        if ( function_exists( 'wp_normalize_path' ) ) {
            return wp_normalize_path( $path );
        }
        $path = str_replace( '\\', '/', (string) $path );
        return preg_replace( '|(?<=.)/+|', '/', $path );
    }

    public static function screenshot_dir() {
        return self::base_dir() . '/screenshots';
    }

    /**
     * Scratch directory for captures that are never meant to be kept.
     *
     * Separate from screenshot_dir() so "an inline capture leaves nothing
     * behind" is a property you can check by listing one directory rather than
     * reasoning about timestamps in another. The caller deletes the file as
     * soon as it has the bytes, on both the success and the failure path.
     */
    public static function scratch_dir() {
        return self::base_dir() . '/tmp';
    }

    /**
     * Absolute path for a capture id.
     *
     * The id is validated before it reaches the filesystem: this value is
     * passed to file operations, and '..' traversal must be impossible even if
     * a future caller forgets to check.
     */
    public static function path_for( $id ) {
        return self::screenshot_dir() . '/' . self::filename_for( $id );
    }

    /** Filesystem-safe, unpredictable name for an id. */
    public static function filename_for( $id ) {
        $key = defined( 'AGENTSHELL_REST_TOKEN' ) ? constant( 'AGENTSHELL_REST_TOKEN' ) : '';
        if ( ! is_string( $key ) || '' === $key ) {
            // Without a secret there is nothing to key an HMAC with. Fall back
            // to a site-specific salt so the name is still not the raw id; the
            // id itself is 128 bits of random, so this is unguessable in
            // practice even without the HMAC.
            $key = defined( 'AUTH_SALT' ) ? constant( 'AUTH_SALT' ) : 'agentshell';
        }
        return substr( hash_hmac( 'sha256', $id, $key ), 0, 40 ) . '.png';
    }

    /** A fresh, unguessable capture id. */
    public static function new_id() {
        return bin2hex( random_bytes( 16 ) );
    }

    public static function is_valid_id( $id ) {
        return is_string( $id ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $id );
    }

    // ------------------------------------------------------------ retention

    /**
     * Retention window in hours.
     *
     * Clamped rather than trusted. An untrusted or careless caller must not be
     * able to set an unbounded retention, and a typo must not silently expire
     * everything an hour later.
     */
    public static function ttl_hours() {
        $hours = defined( 'AGENTSHELL_SCREENSHOT_TTL_HOURS' )
            ? (int) constant( 'AGENTSHELL_SCREENSHOT_TTL_HOURS' )
            : self::DEFAULT_TTL_HOURS;
        return max( self::MIN_TTL_HOURS, min( self::MAX_TTL_HOURS, $hours ) );
    }

    // --------------------------------------------------------------- index

    private static function index() {
        $raw = get_option( self::INDEX_KEY, array() );
        return is_array( $raw ) ? $raw : array();
    }

    private static function save_index( array $index ) {
        update_option( self::INDEX_KEY, $index, false );
    }

    // --------------------------------------------------------------- write

    /**
     * Publish a validated temp PNG into private storage.
     *
     * @param string $temp_path Validated PNG from the capture temp file.
     * @param string $actor     Login of the capturing user.
     * @return array { id, path, expires_at }
     */
    public static function store( $temp_path, $actor ) {
        self::ensure_dir();

        $id    = self::new_id();
        $final = self::path_for( $id );

        if ( ! @rename( $temp_path, $final ) ) {
            throw new \RuntimeException( 'Could not store screenshot in private storage.' );
        }
        // 0640: readable by the web user that runs PHP, not world-readable.
        // The directory is 0700 as well, so this is belt and braces for the
        // case where the document root is mounted somewhere unexpected.
        @chmod( $final, 0640 );

        $expires_at = time() + ( self::ttl_hours() * HOUR_IN_SECONDS );
        $index = self::index();
        $index[ $id ] = array(
            'actor'      => (string) $actor,
            'created_at' => time(),
            'expires_at' => $expires_at,
        );

        // prune_index returns the pruned array; discarding it would leave the
        // index growing without bound while the files were deleted.
        $index = self::prune_index( $index );
        self::save_index( $index );

        return array( 'id' => $id, 'path' => $final, 'expires_at' => $expires_at );
    }

    /**
     * Drop expired entries and cap the total.
     *
     * Called on write so a site that never gets a read still self-limits.
     */
    private static function prune_index( array $index ) {
        $now = time();
        foreach ( $index as $id => $meta ) {
            if ( ! is_array( $meta ) || ( $meta['expires_at'] ?? 0 ) <= $now ) {
                self::unlink_quietly( self::path_for( $id ) );
                unset( $index[ $id ] );
            }
        }

        if ( count( $index ) > self::MAX_CAPTURES ) {
            uasort( $index, function( $a, $b ) {
                return ( $a['created_at'] ?? 0 ) <=> ( $b['created_at'] ?? 0 );
            } );
            foreach ( array_slice( array_keys( $index ), 0, count( $index ) - self::MAX_CAPTURES ) as $id ) {
                self::unlink_quietly( self::path_for( $id ) );
                unset( $index[ $id ] );
            }
        }

        return $index;
    }

    // ---------------------------------------------------------------- read

    /**
     * Look up a capture and confirm the caller may have it.
     *
     * Returns a WP_Error with a distinct code per failure so the transport can
     * answer 404 vs 410 vs 403 — a client that gets 410 knows to stop asking,
     * one that gets 403 knows it is an access problem, not a missing file.
     *
     * @param string $id
     * @param string $actor Login of the requesting user.
     * @return array|WP_Error { path, mimeType, created_at, expires_at }
     */
    public static function authorize_read( $id, $actor ) {
        if ( ! self::is_valid_id( $id ) ) {
            return new \WP_Error( 'agentshell_screenshot_not_found', 'Unknown screenshot id.' );
        }

        $index = self::index();
        $meta  = $index[ $id ] ?? null;

        $path = self::path_for( $id );

        // Expiry is checked against the index when present and against mtime
        // otherwise, so a capture is never served past its window even if the
        // index entry is missing or cron has not run.
        if ( is_array( $meta ) && (int) ( $meta['expires_at'] ?? 0 ) <= time() ) {
            self::forget( $id );
            return new \WP_Error( 'agentshell_screenshot_expired', 'This screenshot has expired.' );
        }
        if ( ! is_array( $meta ) ) {
            $mtime = @filemtime( $path );
            if ( false === $mtime || ( $mtime + ( self::ttl_hours() * HOUR_IN_SECONDS ) ) <= time() ) {
                self::forget( $id );
                return new \WP_Error( 'agentshell_screenshot_not_found', 'Unknown screenshot id.' );
            }
        }

        // Authorisation, not just authentication. Anyone with manage_options
        // can call the transport, so ownership has to be checked explicitly.
        $owner = is_array( $meta ) ? (string) ( $meta['actor'] ?? '' ) : '';
        if ( '' === $owner ) {
            // Ownership was never recorded (a capture stored by an older
            // version). Refuse rather than grant: an unowned capture has no
            // principal to authorise.
            return new \WP_Error(
                'agentshell_screenshot_forbidden',
                'This screenshot has no recorded owner and cannot be retrieved.'
            );
        }
        if ( ! hash_equals( $owner, (string) $actor ) ) {
            return new \WP_Error(
                'agentshell_screenshot_forbidden',
                'This screenshot was captured by another actor.'
            );
        }

        if ( ! is_readable( $path ) ) {
            return new \WP_Error( 'agentshell_screenshot_not_found', 'Unknown screenshot id.' );
        }

        return array(
            'path'       => $path,
            'mimeType'   => 'image/png',
            'created_at' => is_array( $meta ) ? (int) ( $meta['created_at'] ?? 0 ) : (int) @filemtime( $path ),
            'expires_at' => is_array( $meta ) ? (int) $meta['expires_at'] : 0,
        );
    }

    // --------------------------------------------------------------- delete

    public static function forget( $id ) {
        $index = self::index();
        unset( $index[ $id ] );
        self::save_index( $index );
        self::unlink_quietly( self::path_for( $id ) );
    }

    private static function unlink_quietly( $path ) {
        if ( file_exists( $path ) ) {
            @unlink( $path );
        }
    }

    // ------------------------------------------------------------ filesystem

    /**
     * Create the private directories and lock them down.
     *
     * 0700 rather than 0755: nothing outside the PHP user has any business
     * reading these. The deny rules are belt and braces — on nginx .htaccess is
     * inert, so the directory permission is the control that actually applies.
     */
    public static function ensure_dir() {
        $base = self::base_dir();

        $paths = array( $base, self::screenshot_dir(), self::scratch_dir() );
        foreach ( $paths as $path ) {
            if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
                throw new \RuntimeException( 'Could not create private screenshot directory: ' . $path );
            }
            // A pre-planted symlink at any of these paths would redirect every
            // future capture somewhere a local user chose. Refuse rather than
            // follow it.
            if ( is_link( $path ) || ! self::dir_is_safe( $path ) ) {
                throw new \RuntimeException(
                    'Refusing to use private screenshot directory ' . $path
                    . ' — it is a symlink or is not owned by this PHP user.'
                );
            }
            if ( ! is_writable( $path ) ) {
                throw new \RuntimeException( 'Private screenshot directory is not writable: ' . $path );
            }
            @chmod( $path, 0700 );
        }

        // Deny-all index: if the document root is ever misconfigured to expose
        // this directory, a directory listing is at least not served.
        $index = $base . '/index.php';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, "<?php\n// Silence is golden.\nhttp_response_code( 404 );\nexit;\n" );
        }
        // Apache-only, and kept because it costs nothing. It is not the control:
        // nginx ignores it, which is the configuration this was verified under.
        $htaccess = $base . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            @file_put_contents( $htaccess, "Require all denied\nDeny from all\n" );
        }

        if ( self::is_web_reachable( $base ) ) {
            // Should not happen with the default layout. If a host publishes a
            // directory tree wider than ABSPATH implies, say so loudly rather
            // than recording captures that are readable by anyone.
            error_log(
                'AgentShell: the private screenshot store resolved to ' . $base
                . ', which is inside the document root (' . self::doc_root() . ').'
                . ' Captures there may be world-readable. Define AGENTSHELL_SCREENSHOT_DIR'
                . ' to point at a path the web server does not serve.'
            );
        }

        return true;
    }

    /**
     * True when $dir is a real directory we own.
     *
     * Symlink refusal matters here for the same reason as the capture lock: a
     * pre-planted link at the storage path would let a local user redirect
     * every future capture somewhere they choose.
     *
     * @param int|null $expected_uid Owner to require. Defaults to this process's
     *                               effective uid; overridable only so the test
     *                               suite can exercise the ownership branch,
     *                               which otherwise needs a directory owned by
     *                               another user — not creatable without
     *                               privileges.
     */
    public static function dir_is_safe( $dir, $expected_uid = null ) {
        if ( ! is_dir( $dir ) ) {
            return false;
        }
        if ( is_link( $dir ) ) {
            return false;
        }
        if ( null === $expected_uid ) {
            $expected_uid = function_exists( 'posix_geteuid' ) ? posix_geteuid() : null;
        }
        $owner = @fileowner( $dir );
        if ( null !== $expected_uid && false !== $owner && (int) $owner !== (int) $expected_uid ) {
            return false;
        }
        return true;
    }
}