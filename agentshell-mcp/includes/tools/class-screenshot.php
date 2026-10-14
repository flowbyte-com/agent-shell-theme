<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_screenshot — render the site and capture it as an image.
 *
 * Completes the agent visual feedback loop: inspect → change → render →
 * screenshot → evaluate → iterate. Requires a headless browser on the server
 * (google-chrome / chromium, or AGENTSHELL_CHROME_BIN in wp-config.php).
 */
class Screenshot extends Base_Tool {
    public function get_name() { return 'agentshell_screenshot'; }
    public function get_description() { return 'Capture a screenshot of the site (or a saved theme profile preview) at a given viewport using the headless browser installed on the server. By default the image is returned inline in the tool result so you can see it directly — no file is written and no URL is produced. Pass inline=false to store the capture in private storage instead and get back an id to pass to agentshell_get_screenshot. Backend: Chrome/Chromium headless; configure AGENTSHELL_CHROME_BIN if not on PATH.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'viewport' => array(
                    'type'        => 'string',
                    'enum'        => array( 'desktop', 'mobile', 'tablet' ),
                    'description' => 'Preset viewport (default desktop = 1280x800, mobile = 390x844, tablet = 768x1024)',
                ),
                'width'    => array( 'type' => 'integer', 'description' => 'Explicit viewport width in px (overrides viewport preset)' ),
                'height'   => array( 'type' => 'integer', 'description' => 'Explicit viewport height in px (overrides viewport preset)' ),
                'url'      => array( 'type' => 'string', 'description' => 'URL to capture (defaults to the site home page)' ),
                'profile'  => array( 'type' => 'string', 'description' => 'Optional theme profile name — the screenshot renders its design tokens as preview overrides (requires AGENTSHELL_REST_TOKEN)' ),
                'transaction' => array( 'type' => 'boolean', 'description' => 'Capture the open transaction\'s staged design instead of the committed one. Requires an open transaction; the staged config is rendered for this request only and is never persisted. Ordinary visitors continue to see the committed config until you commit.' ),
                'inline'   => array( 'type' => 'boolean', 'description' => 'Default true: return the image in this tool result and write nothing to disk. Set false to store the capture in private, expiring storage and receive an id instead.' ),
            ),
        );
    }

    /**
     * Maximum PNG size returned inline, in bytes.
     *
     * Clamped, never caller-controlled. base64 inflates by 4/3, so an
     * unvalidated limit here is how a single capture exhausts the PHP worker's
     * memory and then the client's context. 5 MB of PNG is roughly 200x the
     * largest real capture this produces and well past what a client can
     * usefully display; the 25 MB ceiling is about 33 MB of base64, which no
     * client can render anyway.
     */
    public static function max_inline_bytes() {
        $bytes = defined( 'AGENTSHELL_SCREENSHOT_MAX_BYTES' )
            ? (int) constant( 'AGENTSHELL_SCREENSHOT_MAX_BYTES' )
            : 5 * MB_IN_BYTES;
        return max( 1 * MB_IN_BYTES, min( 25 * MB_IN_BYTES, $bytes ) );
    }

    public function execute( array $arguments ) {
        $backend = \AgentShell_MCP\Screenshot::backend();
        if ( null === $backend ) {
            return array(
                'captured' => false,
                'reason'   => 'No headless browser available on this server. Install google-chrome or chromium, or define AGENTSHELL_CHROME_BIN in wp-config.php.',
                'tip'      => 'Until then, use agentshell_preview_theme to get a preview URL and view it in a browser.',
            );
        }

        // Resolve viewport.
        $viewport = $arguments['viewport'] ?? 'desktop';
        $preset   = \AgentShell_MCP\Screenshot::VIEWPORTS[ $viewport ] ?? \AgentShell_MCP\Screenshot::VIEWPORTS['desktop'];
        $width    = isset( $arguments['width'] ) ? (int) $arguments['width'] : $preset[0];
        $height   = isset( $arguments['height'] ) ? (int) $arguments['height'] : $preset[1];
        if ( $width < 320 || $width > 4096 || $height < 240 || $height > 4096 ) {
            throw new \InvalidArgumentException( 'Viewport must be within 320-4096px wide and 240-4096px tall.' );
        }

        // Resolve URL.
        $url = ! empty( $arguments['url'] ) ? esc_url_raw( $arguments['url'] ) : home_url( '/' );
        if ( empty( $url ) ) {
            throw new \InvalidArgumentException( 'url is not a valid URL.' );
        }

        // The caller does not supply preview credentials; this tool mints its
        // own just below. Strip anything that arrived in the URL so a key
        // pasted into a `url` argument cannot reach the audit log (which
        // records arguments) or ride along in the returned captured_url.
        $url = self::strip_preview_args( $url );

        // SSRF guard: a trusted agent could otherwise point Chrome at
        // http://169.254.169.254/ (cloud metadata), http://localhost:8080/admin, or any
        // internal host, and whatever that host renders would end up in the
        // capture. Reject any URL whose host differs from home_url() — the
        // legitimate use case (previewing theme profiles) is same-site by
        // construction. This host check is the whole control; it does not depend
        // on the browser sandbox, which capture() leaves enabled.
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        $target_host = wp_parse_url( $url, PHP_URL_HOST );
        if ( ! $target_host || strcasecmp( $target_host, (string) $site_host ) !== 0 ) {
            throw new \InvalidArgumentException(
                "url host '{$target_host}' does not match this site's host ('{$site_host}'); screenshots are scoped to this site to prevent SSRF."
            );
        }

        // Optional profile preview — sign it so the theme can render overrides
        // without a browser session.
        if ( ! empty( $arguments['profile'] ) ) {
            $profile = sanitize_key( $arguments['profile'] );
            if ( null === \AgentShell_MCP\Store::get_profile( $profile ) ) {
                throw new \InvalidArgumentException( "Theme profile not found: {$profile}. Use agentshell_list_theme_profiles." );
            }
            if ( ! defined( 'AGENTSHELL_REST_TOKEN' ) ) {
                throw new \InvalidArgumentException( 'AGENTSHELL_REST_TOKEN must be defined in wp-config.php to screenshot a profile preview (the preview key is derived from it).' );
            }
            $url = add_query_arg(
                array(
                    'agentshell_preview'     => $profile,
                    'agentshell_preview_key' => hash_hmac( 'sha256', $profile, AGENTSHELL_REST_TOKEN ),
                ),
                $url
            );
        }

        // Transaction preview — sign it so the theme renders the staged config
        // for this one request. Same shape as the profile preview above, but
        // scoped to one transaction, its owner, and a 15-minute window, and it
        // reads the staging buffer rather than a saved profile.
        $tx_preview_args = array();
        $transaction_preview = ! empty( $arguments['transaction'] );
        if ( $transaction_preview && ! empty( $arguments['profile'] ) ) {
            throw new \InvalidArgumentException(
                'transaction and profile cannot be combined: one renders the open transaction\'s staged '
                . 'config, the other a saved theme profile. Pick the one you mean.'
            );
        }
        if ( $transaction_preview ) {
            if ( ! class_exists( '\AgentShell_MCP\Transaction_Manager' ) ) {
                throw new \InvalidArgumentException(
                    'Transaction preview requires the agentshell-mcp plugin, which is not active.'
                );
            }
            // Throws when no transaction is open, or when there is no secret to
            // sign with — both are the caller asking for something that cannot
            // be granted, and both must fail rather than degrade to an ordinary
            // screenshot of the committed site.
            $tx_preview_args = \AgentShell_MCP\Transaction_Manager::preview_args();
            $url = add_query_arg( $tx_preview_args, $url );
        }

        // Inline is the default and the reason the feature exists: the point of
        // a screenshot is that the agent can see it. A stored capture is the
        // opt-in for an agent that wants a durable reference across turns.
        $inline = ! array_key_exists( 'inline', $arguments ) || (bool) $arguments['inline'];

        // Inline writes to a scratch directory outside every served path, and
        // the file is deleted the moment the bytes are read. Nothing durable is
        // created, so there is nothing to expire and nothing to leak.
        $dir = $inline
            ? \AgentShell_MCP\Screenshot_Store::scratch_dir()
            : \AgentShell_MCP\Screenshot_Store::screenshot_dir();

        // A busy lock is expected under concurrent agents, not a broken
        // install — report it as a structured, retryable result rather than an
        // exception, so the calling agent does not treat it as a site fault.
        try {
            $shot = \AgentShell_MCP\Screenshot::capture( $url, $width, $height, $dir );
        } catch ( \AgentShell_MCP\Screenshot_Busy_Exception $e ) {
            return array(
                'captured'  => false,
                'reason'    => 'busy',
                'message'   => $e->getMessage(),
                'retryable' => true,
                'tip'       => 'Retry once the in-flight capture finishes. Do not issue concurrent screenshot calls — they contend for the same PHP-FPM worker pool.',
            );
        }

        $file    = $shot['file'];
        $summary = array(
            'captured'  => true,
            'screenshot' => array(
                'width'   => $shot['width'],
                'height'  => $shot['height'],
                'backend' => $shot['backend'],
                'inline'  => $inline,
            ),
            'elapsed_ms' => $shot['elapsed_ms'],
            // Never the credential-bearing URL. This summary is returned to the
            // agent, written to transcripts, and recorded by callers — a
            // preview key in it is a preview capability in a log file.
            'captured_url' => self::redact_preview_credentials( $url ),
            'tip' => 'Evaluate the screenshot, then iterate: adjust design tokens, re-screenshot. Use agentshell_list_theme_profiles + agentshell_screenshot(profile=...) to compare profiles side by side.',
        );

        if ( $transaction_preview ) {
            $summary['screenshot']['previewed_transaction'] = $tx_preview_args[ \AgentShell_MCP\Transaction_Manager::PREVIEW_TX_ARG ];
            $summary['preview_note'] = 'Rendered the open transaction\'s staged design. Visitors are still '
                . 'seeing the committed config — the preview credential is scoped to this transaction, expires in '
                . \AgentShell_MCP\Transaction_Manager::PREVIEW_TTL_SECONDS
                . 's, and stops working the moment you commit or roll back.';
        }

        // Every path below deletes the PNG. Inline has no reason to keep it,
        // and a stored capture that turns out to be oversized must not leave a
        // file behind either.
        try {
            if ( ! $inline ) {
                // stat, not read: a stored capture never has to fit in memory,
                // and the size limit applies to inline delivery only.
                $stored = \AgentShell_MCP\Screenshot_Store::store(
                    $file,
                    \AgentShell_MCP\Store::actor()
                );

                $summary['screenshot']['id']         = $stored['id'];
                $summary['screenshot']['expires_at'] = $stored['expires_at'];
                $summary['screenshot']['bytes']      = (int) @filesize( $stored['path'] );
                $summary['screenshot']['retrieve']   = 'agentshell_get_screenshot';
                $summary['tip'] = 'The capture is stored privately and expires in '
                    . \AgentShell_MCP\Screenshot_Store::ttl_hours() . 'h. '
                    . 'Fetch it again with agentshell_get_screenshot(id=…).';

                return $summary;
            }

            $bytes = self::read_png( $file );
        } finally {
            // Success, failure, or thrown: the scratch PNG goes either way.
            @unlink( $file );
        }

        // An over-limit capture is rejected explicitly, with the number that was
        // exceeded. Truncating would hand the agent a plausible-looking image
        // of something other than the page it just captured, which is worse
        // than telling it the truth.
        $limit = self::max_inline_bytes();
        if ( strlen( $bytes ) > $limit ) {
            $summary['captured'] = false;
            $summary['reason']   = 'too_large';
            $summary['bytes']    = strlen( $bytes );
            $summary['limit_bytes'] = $limit;
            $summary['tip']      = sprintf(
                'The capture is %d bytes, over the %d-byte inline limit, so no image was returned. '
                . 'Re-capture a smaller region (lower width/height), or pass inline=false to store it in private '
                . 'storage and read it back with agentshell_get_screenshot. '
                . 'The limit is AGENTSHELL_SCREENSHOT_MAX_BYTES, clamped to 1-25 MB; raise it in wp-config.php if this site genuinely needs it.',
                strlen( $bytes ),
                $limit
            );
            return $summary;
        }

        $summary['screenshot']['bytes'] = strlen( $bytes );

        // Hand back an MCP image block, not base64 in a text block. A text block
        // carries a string the agent has to decode by hand; an image block is
        // what an MCP client renders natively.
        if ( ! method_exists( '\AgentShell_MCP\Server', 'normalize_content' ) ) {
            // Guard against a screenshot tool loaded alongside a Server that
            // predates multi-block results. Rather than emit an image block that
            // will be JSON-stringified into a text field, fall back to storage,
            // which every version can retrieve.
            $stored = \AgentShell_MCP\Screenshot_Store::store(
                $file,
                \AgentShell_MCP\Store::actor()
            );
            $summary['screenshot']['inline']       = false;
            $summary['screenshot']['id']           = $stored['id'];
            $summary['screenshot']['expires_at']   = $stored['expires_at'];
            $summary['screenshot']['retrieve']     = 'agentshell_get_screenshot';
            $summary['tip'] = 'This MCP server does not support inline image blocks, so the capture was stored '
                . 'in private storage instead. Read it back with agentshell_get_screenshot(id=…).';
            return $summary;
        }

        $summary['__mcp_content'] = array(
            array(
                'type'     => 'image',
                'data'     => base64_encode( $bytes ),
                'mimeType' => 'image/png',
            ),
        );

        return $summary;
    }

    /**
     * Strip any preview capability from a URL destined for a caller.
     *
     * Delegates to Transaction_Manager so the list of bearer parameters lives
     * next to the code that mints them, and degrades to the raw URL when the
     * class is not loaded — a tool loaded on its own should still work, and
     * with no preview support there is nothing to scrub.
     */
    private static function strip_preview_args( $url ) {
        if ( ! class_exists( '\AgentShell_MCP\Transaction_Manager' ) ) {
            return $url;
        }
        return \AgentShell_MCP\Transaction_Manager::strip_preview_args( $url );
    }

    /**
     * Strip any preview capability from a URL destined for a caller.
     *
     * The transaction id and expiry survive — they are useless without the
     * signature — so a returned URL still says which transaction it meant.
     */
    private static function redact_preview_credentials( $url ) {
        if ( ! class_exists( '\AgentShell_MCP\Transaction_Manager' ) ) {
            return $url;
        }
        return \AgentShell_MCP\Transaction_Manager::redact_preview_url( $url );
    }

    /**
     * Read a captured PNG into memory.
     *
     * Read once and reused for both the size check and the encode: the limit is
     * on raw bytes, and stat-then-read would leave a window where the file
     * could change between the check and the payload.
     */
    private static function read_png( $file ) {
        $bytes = @file_get_contents( $file );
        if ( ! is_string( $bytes ) || '' === $bytes ) {
            throw new \RuntimeException( 'Could not read the captured screenshot.' );
        }
        return $bytes;
    }
}