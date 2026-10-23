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
                'post_id'    => array( 'type' => 'integer', 'description' => 'Capture an unpublished post (draft, pending, private or scheduled) without publishing it. Requires permission to read or edit that post. WordPress\' own per-post capability rules stay authoritative; the credential only restores your identity for that one request. Combine with transaction=true to see uncommitted design on a draft.' ),
                'published_preview' => array( 'type' => 'boolean', 'description' => 'Capture the STAGED edit of a published page (post_id required): the page is served with the staged title, body, excerpt and slug, so an agent can see the edit before it is published. Requires an open transaction holding a staged edit for that page. The credential is bound to that staging, expires, and stops working the moment the transaction is committed or rolled back. Visitors still see the published page.' ),
                'inline'   => array( 'type' => 'boolean', 'description' => 'Default true: return the image in this tool result and write nothing to disk. Set false to store the capture in private, expiring storage and receive an id instead.' ),
            ),
        );
    }

    /**
     * The open transaction's staged edit of a published page.
     *
     * Returns null when there is no open transaction, when it holds no
     * OP_EDIT_PUBLISHED for that page, or when the op is in a state that
     * is no longer a previewable staging (published, compensated,
     * conflicted). A credential for a settled op would preview nothing,
     * and one for a conflicted op would preview something the transaction
     * has already given up on.
     *
     * @param int $original_id
     * @return array{tx_id:string, op_id:string, shadow_id:int}|null
     */
    private static function published_staging_for( $original_id ) {
        $tx_mgr = \AgentShell_MCP\Transaction_Manager::instance();
        $row    = $tx_mgr->payload();
        if ( ! is_array( $row ) ) {
            return null;
        }
        $content = \AgentShell_MCP\Content_Journal::of( $row );
        foreach ( (array) ( $content['ops'] ?? array() ) as $op ) {
            if ( ! is_array( $op ) ) { continue; }
            $target = isset( $op['target']['original_id'] ) ? (int) $op['target']['original_id'] : 0;
            if ( $target !== (int) $original_id ) { continue; }
            if ( \AgentShell_MCP\Content_Journal::OP_EDIT_PUBLISHED !== (string) ( $op['op'] ?? '' ) ) { continue; }
            $state = (string) ( $op['state'] ?? '' );
            if ( ! in_array( $state, array( 'staged', 'intended', 'applied', 'partial-applied' ), true ) ) {
                continue;
            }
            $shadow_id = (int) ( $op['target']['shadow_id'] ?? 0 );
            if ( $shadow_id <= 0 ) {
                continue;
            }
            return array(
                'tx_id'     => (string) ( $row['id'] ?? '' ),
                'op_id'     => (string) ( $op['id'] ?? '' ),
                'shadow_id' => $shadow_id,
            );
        }
        return null;
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
        $url = self::strip_post_preview_args( $url );

        // Unpublished post preview — restore the caller's own identity for one
        // request so WordPress's existing per-post preview check can run.
        //
        // This replaces the URL rather than decorating it. The credential is
        // therefore aimed by this tool rather than by the caller: it cannot be
        // pointed at an arbitrary path, and any url argument the caller passed
        // is irrelevant to where the capture happens. The theme independently
        // re-checks that the request resolved to this post and 404s it if not,
        // so a mismatch fails closed rather than rendering something else under
        // the restored identity.
        $post_preview_args = array();
        $post_preview = isset( $arguments['post_id'] ) && '' !== $arguments['post_id'] && null !== $arguments['post_id'];

        // The staged edit of a published page.
        //
        // A published page cannot be previewed through the ordinary post
        // credential — it is already public, and the credential exists for
        // content that is not. The staged edit of a published page is a
        // different thing: the page is public and stays public, and what
        // the caller wants to see is the edit that has not been published
        // yet. The published-preview overlay serves exactly that, and the
        // credential for it is bound to the staging — it expires, and it
        // stops working the moment the transaction is committed or rolled
        // back — so it is a capability and not a published URL.
        if ( ! empty( $arguments['published_preview'] ) ) {
            if ( ! $post_preview ) {
                throw new \InvalidArgumentException(
                    'published_preview requires post_id: it previews the staged edit of a specific published page.'
                );
            }
            if ( ! class_exists( '\AgentShell_MCP\Content_Journal' ) || ! class_exists( '\AgentShell_MCP\Post_Preview' ) ) {
                throw new \InvalidArgumentException(
                    'A published-content preview requires the agentshell-mcp plugin, which is not active.'
                );
            }
            $published_id = (int) $arguments['post_id'];
            $post         = get_post( $published_id );
            if ( ! $post || 'publish' !== $post->post_status ) {
                throw new \InvalidArgumentException(
                    "Post {$published_id} is not published, so there is no published edit to preview. "
                    . 'Use post_id alone to preview an unpublished post.'
                );
            }
            if ( ! current_user_can( 'edit_post', $published_id ) ) {
                throw new \InvalidArgumentException(
                    "You do not have permission to edit post {$published_id}, so its staged edit cannot be previewed."
                );
            }
            $staging = self::published_staging_for( $published_id );
            if ( null === $staging ) {
                throw new \InvalidArgumentException(
                    "There is no open transaction staging an edit for post {$published_id}. "
                    . 'Stage one with agentshell_edit_published, then capture it.'
                );
            }
            $url = get_permalink( $published_id );
            if ( ! is_string( $url ) || '' === $url ) {
                throw new \InvalidArgumentException( "Post {$published_id} has no permalink to capture." );
            }
            $url = add_query_arg(
                \AgentShell_MCP\Post_Preview::published_preview_url_args(
                    $published_id,
                    (int) $staging['shadow_id'],
                    (string) $staging['tx_id'],
                    (string) $staging['op_id'],
                    (int) get_current_user_id()
                ),
                $url
            );
            $post_preview = false;
            $published_preview_args = array(
                'original_id' => $published_id,
                'shadow_id'   => (int) $staging['shadow_id'],
                'op_id'       => (string) $staging['op_id'],
            );
        }

        if ( $post_preview ) {
            if ( ! class_exists( '\AgentShell_MCP\Post_Preview' ) ) {
                throw new \InvalidArgumentException(
                    'Post preview requires the agentshell-mcp plugin, which is not active.'
                );
            }
            $post_id = (int) $arguments['post_id'];
            // Throws rather than degrading to a screenshot of a 404 page, which
            // an agent would reasonably read as a successful capture of the draft.
            $post_preview_args = \AgentShell_MCP\Post_Preview::preview_args( $post_id );

            $link = get_preview_post_link( $post_id );
            if ( ! is_string( $link ) || '' === $link ) {
                throw new \InvalidArgumentException(
                    "Post {$post_id} has no previewable permalink, so it cannot be captured."
                );
            }
            $url = $link;
            $url = add_query_arg( $post_preview_args, $url );
        }

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
                // The dimensions the image actually has, not the ones that
                // were requested: headless Chrome enforces a minimum window
                // width, so a narrow viewport is laid out wider than it was
                // asked for. The requested dimensions are reported beside
                // them, and a note says so when they differ.
                'width'   => $shot['width'],
                'height'  => $shot['height'],
                'requested_width'  => $shot['requested_width'] ?? $shot['width'],
                'requested_height' => $shot['requested_height'] ?? $shot['height'],
                'backend' => $shot['backend'],
                'inline'  => $inline,
            ),
            'elapsed_ms' => $shot['elapsed_ms'],
            // Never the credential-bearing URL. This summary is returned to the
            // agent, written to transcripts, and recorded by callers — a
            // preview key in it is a preview capability in a log file.
            'captured_url' => self::redact_post_preview_credentials(
                self::redact_preview_credentials( $url )
            ),
            'tip' => 'Evaluate the screenshot, then iterate: adjust design tokens, re-screenshot. Use agentshell_list_theme_profiles + agentshell_screenshot(profile=...) to compare profiles side by side.',
        );

        if ( isset( $published_preview_args ) ) {
            $summary['screenshot']['previewed_published_edit'] = $published_preview_args;
            $summary['preview_note'] = 'Rendered the STAGED edit of a published page: the page was served with the '
                . 'staged title, body, excerpt and slug. Visitors still see the published page, and the credential '
                . 'stops working the moment the transaction is committed or rolled back.';
        }

        if ( ! empty( $shot['viewport_adjusted'] ) ) {
            $summary['screenshot']['viewport_adjusted'] = true;
            $summary['screenshot']['note'] = (string) $shot['note'];
        }

        if ( $transaction_preview ) {
            $summary['screenshot']['previewed_transaction'] = $tx_preview_args[ \AgentShell_MCP\Transaction_Manager::PREVIEW_TX_ARG ];
            $summary['preview_note'] = 'Rendered the open transaction\'s staged design. Visitors are still '
                . 'seeing the committed config — the preview credential is scoped to this transaction, expires in '
                . \AgentShell_MCP\Transaction_Manager::PREVIEW_TTL_SECONDS
                . 's, and stops working the moment you commit or roll back.';
        }

        if ( $post_preview ) {
            $summary['screenshot']['previewed_post'] = $post_preview_args[ \AgentShell_MCP\Post_Preview::POST_ARG ];
            $summary['preview_note'] = trim(
                ( isset( $summary['preview_note'] ) ? $summary['preview_note'] . ' ' : '' )
                . 'Rendered an unpublished post without publishing it. The post stays unpublished and no visitor '
                . 'can reach it; the credential restores your own WordPress identity for this one request and '
                . 'expires in ' . \AgentShell_MCP\Post_Preview::TTL_SECONDS . 's.'
            );
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
     * Strip any post-preview capability from a URL supplied by a caller.
     *
     * Same reasoning as strip_preview_args(): this tool mints its own, and the
     * audit log records arguments verbatim.
     */
    private static function strip_post_preview_args( $url ) {
        if ( ! class_exists( '\AgentShell_MCP\Post_Preview' ) ) {
            return $url;
        }
        return \AgentShell_MCP\Post_Preview::strip_args( $url );
    }

    /**
     * Strip the post-preview key from a URL destined for a caller.
     */
    private static function redact_post_preview_credentials( $url ) {
        if ( ! class_exists( '\AgentShell_MCP\Post_Preview' ) ) {
            return $url;
        }
        return \AgentShell_MCP\Post_Preview::redact( $url );
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