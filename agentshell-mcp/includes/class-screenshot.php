<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Screenshot backend — headless-browser page capture for the agent visual
 * feedback loop (inspect → change → render → screenshot → evaluate).
 *
 * Uses the Chrome/Chromium headless CLI (--screenshot), which is available on
 * most servers. Detection order:
 *   1. AGENTSHELL_CHROME_BIN constant (explicit path)
 *   2. $PATH: google-chrome, google-chrome-stable, chromium, chromium-browser, headless_shell
 *
 * Screenshots land in wp-content/uploads/agentshell-screenshots/.
 */
class Screenshot {
    const VIEWPORTS = array(
        'desktop' => array( 1280, 800 ),
        'mobile'  => array( 390, 844 ),
        'tablet'  => array( 768, 1024 ),
    );

    /**
     * Detect an available headless browser.
     *
     * @return array|null { command, label } or null when none available
     */
    public static function backend() {
        if ( ! function_exists( 'exec' ) ) {
            return null;
        }

        if ( defined( 'AGENTSHELL_CHROME_BIN' ) && is_string( AGENTSHELL_CHROME_BIN ) && AGENTSHELL_CHROME_BIN !== '' ) {
            if ( is_executable( AGENTSHELL_CHROME_BIN ) || file_exists( AGENTSHELL_CHROME_BIN ) ) {
                return array( 'command' => AGENTSHELL_CHROME_BIN, 'label' => 'AGENTSHELL_CHROME_BIN' );
            }
        }

        foreach ( array( 'google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser', 'headless_shell' ) as $binary ) {
            $path = self::which( $binary );
            if ( null !== $path ) {
                return array( 'command' => $path, 'label' => $binary );
            }
        }

        return null;
    }

    /**
     * Capture a screenshot of $url at $width x $height.
     *
     * @param string $url    Fully-qualified URL
     * @param int    $width  Viewport width in px
     * @param int    $height Viewport height in px
     * @return array { file, url, width, height, backend, label }
     * @throws \InvalidArgumentException
     */
    public static function capture( $url, $width, $height ) {
        $backend = self::backend();
        if ( null === $backend ) {
            throw new \InvalidArgumentException(
                'No headless browser found. Install google-chrome/chromium, or define AGENTSHELL_CHROME_BIN in wp-config.php.'
            );
        }

        $width  = max( 320, min( 4096, (int) $width ) );
        $height = max( 240, min( 4096, (int) $height ) );

        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            throw new \InvalidArgumentException( 'Upload directory is not writable: ' . $uploads['error'] );
        }
        $dir = $uploads['basedir'] . '/agentshell-screenshots';
        if ( ! wp_mkdir_p( $dir ) ) {
            throw new \InvalidArgumentException( 'Could not create screenshot directory: ' . $dir );
        }

        $file = $dir . '/shot-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) . '.png';

        $command = escapeshellarg( $backend['command'] )
            . ' --headless --disable-gpu --no-sandbox --hide-scrollbars'
            . ' --window-size=' . $width . ',' . $height
            . ' --virtual-time-budget=6000'
            . ' --screenshot=' . escapeshellarg( $file )
            . ' ' . escapeshellarg( $url )
            . ' 2>&1';

        $output = array();
        exec( $command, $output, $exit_code );

        if ( ! file_exists( $file ) || filesize( $file ) < 100 ) {
            throw new \InvalidArgumentException( 'Screenshot failed (exit ' . $exit_code . '): ' . implode( ' | ', array_slice( $output, -3 ) ) );
        }

        return array(
            'file'    => $file,
            'url'     => $uploads['baseurl'] . '/agentshell-screenshots/' . basename( $file ),
            'width'   => $width,
            'height'  => $height,
            'backend' => $backend['label'],
        );
    }

    private static function which( $binary ) {
        $paths = explode( PATH_SEPARATOR, (string) getenv( 'PATH' ) );
        foreach ( $paths as $path ) {
            if ( $path === '' ) {
                continue;
            }
            $candidate = rtrim( $path, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $binary;
            if ( is_executable( $candidate ) ) {
                return $candidate;
            }
        }
        // Fall back to shell lookup (covers aliases, snap, flatpak wrappers).
        $found = shell_exec( 'command -v ' . escapeshellarg( $binary ) . ' 2>/dev/null' );
        $found = is_string( $found ) ? trim( $found ) : '';
        return $found !== '' && is_executable( $found ) ? $found : null;
    }
}