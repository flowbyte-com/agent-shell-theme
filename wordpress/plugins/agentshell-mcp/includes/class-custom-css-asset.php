<?php
/**
 * The custom stylesheet as a static, cacheable file.
 *
 * @package AgentShell_MCP
 */

namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Materialise the committed custom stylesheet when the configuration changes.
 *
 * The configuration is the source of truth; the file is a cache of it. The
 * file is written by the theme's materialiser — the one function that knows
 * the bytes, the directory and the atomic write — and this class only decides
 * *when* to call it and what to report when it fails.
 *
 * Three properties matter and each of them is a consequence of where the
 * hook sits:
 *
 * 1. **A staged change never reaches this hook.** `update_option()` is
 *    intercepted while a transaction is open: the staged value is returned in
 *    place of the new one, core compares it with the stored value, finds them
 *    equal and skips the write. The action that fires here therefore only
 *    ever fires for a *committed* write. An uncommitted stylesheet is never
 *    written to a file, so it is never published to anyone who guesses the
 *    URL.
 *
 * 2. **Every committed write goes through one row.** The commit, the
 *    rollback's own writes, a snapshot restore, a revision restore, a theme
 *    import and the theme's own REST endpoint all write the same option, and
 *    WordPress fires one action for all of them.
 *
 * 3. **A failure is a failure to serve the file, not a failure to change the
 *    site.** The commit has already happened by the time this runs. A
 *    materialisation failure is reported in the audit log and the page falls
 *    back to inlining the stylesheet, which is exactly what the site did
 *    before this feature existed.
 */
class Custom_CSS_Asset {

    /**
     * Materialise the stylesheet after a committed configuration write.
     *
     * @param mixed $old_value The configuration before the write.
     * @param mixed $new_value The configuration that was written.
     * @return array The materialiser's result, or an empty array when the
     *               theme's materialiser is not available.
     */
    public static function project( $old_value = null, $new_value = null ) {
        if ( ! function_exists( 'agentshell_materialise_custom_css' )
            || ! function_exists( 'agentshell_custom_css_bytes' ) ) {
            // The theme that owns the stylesheet is not the one that is
            // loaded. The site inlines its stylesheet, which is correct
            // and unchanged; there is simply nothing to materialise.
            return array();
        }

        $before = is_array( $old_value ) ? agentshell_custom_css_bytes( $old_value['custom_css'] ?? '' ) : '';
        $after  = is_array( $new_value ) ? agentshell_custom_css_bytes( $new_value['custom_css'] ?? '' ) : '';

        if ( $before === $after ) {
            // The stylesheet did not change. The file that is already on
            // disk is still the right one; rewriting it would be a
            // pointless write on every configuration change.
            return array( 'skipped' => true );
        }

        $result = agentshell_materialise_custom_css( is_array( $new_value ) ? $new_value : null );
        if ( ! is_array( $result ) ) {
            $result = array( 'url' => '', 'hash' => '', 'path' => '', 'error' => 'the materialiser returned nothing' );
        }

        if ( ! empty( $result['error'] ) ) {
            Store::record_event(
                Store::actor(),
                'custom_css_materialisation_failed',
                array(
                    'error' => (string) $result['error'],
                    'hash'  => (string) ( $result['hash'] ?? '' ),
                    'note'  => 'the stylesheet is served inline until the next write succeeds',
                )
            );
        }

        return $result;
    }

    /**
     * The state of the materialised stylesheet, for the doctor.
     *
     * @param array|null $config The configuration. Null means the committed one.
     * @return array { inline, url, hash, path, expected_path, error }
     */
    public static function state( $config = null ) {
        $state = array(
            'inline'        => true,
            'url'           => '',
            'hash'          => '',
            'path'          => '',
            'expected_path' => '',
            'error'         => '',
        );

        if ( ! function_exists( 'agentshell_materialise_custom_css' )
            || ! function_exists( 'agentshell_custom_css_bytes' ) ) {
            $state['error'] = 'the theme materialiser is not loaded';
            return $state;
        }

        if ( ! is_array( $config ) ) {
            $config = function_exists( 'agentshell_get_config' ) ? agentshell_get_config() : array();
        }
        $bytes = agentshell_custom_css_bytes( $config['custom_css'] ?? '' );
        if ( '' === $bytes ) {
            // Nothing to serve: the site has no custom stylesheet.
            $state['inline'] = false;
            return $state;
        }

        $hash = agentshell_custom_css_hash( $bytes );
        $location = agentshell_custom_css_upload_location();
        if ( null === $location ) {
            $state['error'] = 'the uploads directory is not available';
            return $state;
        }

        $name = agentshell_custom_css_filename( $hash );
        $path = $location['dir'] . '/' . $name;
        $state['hash']          = $hash;
        $state['path']          = $path;
        $state['expected_path'] = $path;
        $state['inline']        = ! is_file( $path );

        if ( is_file( $path ) ) {
            $state['url'] = $location['url'] . '/' . $name;
        }

        return $state;
    }
}
