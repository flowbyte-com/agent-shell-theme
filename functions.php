<?php
/**
 * AgentShell theme functions
 */

// PHP 7.4 compat: polyfills for PHP 8.0 string helpers used below.
if ( ! function_exists( 'str_starts_with' ) ) {
    function str_starts_with( $haystack, $needle ) {
        return (string) $needle !== '' && strncmp( $haystack, $needle, strlen( $needle ) ) === 0;
    }
}
if ( ! function_exists( 'str_contains' ) ) {
    function str_contains( $haystack, $needle ) {
        return '' === $needle || false !== strpos( $haystack, $needle );
    }
}

// Disable automatic formatting for agent payloads
// This prevents WordPress from wrapping content in <p>, <br>, etc.
remove_filter( 'the_content', 'wpautop' );
remove_filter( 'the_excerpt', 'wpautop' );
remove_filter( 'the_content', 'wptexturize' );
remove_filter( 'the_content', 'convert_smilies' );
remove_filter( 'the_content', 'convert_chars' );
remove_filter( 'the_content', 'wp_make_content_images_responsive' );
remove_filter( 'the_excerpt', 'wptexturize' );
remove_filter( 'the_excerpt', 'convert_smilies' );
remove_filter( 'the_excerpt', 'convert_chars' );

// Remove Emoji Scripts
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');
remove_action('admin_print_scripts', 'print_emoji_detection_script');
remove_action('admin_print_styles', 'print_emoji_styles');

// Remove Head Bloat
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'wp_generator');
remove_action('wp_head', 'wp_shortlink_wp_head');
remove_action('wp_head', 'rest_output_link_wp_head', 10);
remove_action('wp_head', 'wp_oembed_add_discovery_links', 10);
remove_action('wp_head', 'wp_oembed_add_host_js');

// Prevent WordPress from adding rel="noopener" or other auto-attributes to links
// that could interfere with agent-built interactive widgets
remove_filter( 'the_content', 'wp_targeted_link_rel' );
/**
 * Register agentshell_config option on theme activation.
 */
function agentshell_theme_activation() {
    if ( get_option( 'agentshell_config' ) !== false ) {
        return;
    }
    $seed_file = get_template_directory() . '/default-config.json';
    if ( ! file_exists( $seed_file ) ) {
        return;
    }
    $raw_seed = file_get_contents( $seed_file );
    if ( ! is_string( $raw_seed ) ) {
        return;
    }
    $seed = json_decode( $raw_seed, true );
    if ( ! is_array( $seed ) ) {
        return;
    }
    add_option( 'agentshell_config', $seed );
}
add_action( 'after_switch_theme', 'agentshell_theme_activation' );

/**
 * Enqueue theme assets
 */
function agentshell_enqueue_assets() {
    // Deregister only the admin-only styles whose CSS is not for the
    // front end. WordPress core decides which front-end block-library
    // styles to load (wp-block-library, wp-block-library-theme,
    // wp-block-style, wp-emoji-styles, wp-img-autosizes,
    // classic-theme-styles, global-styles) — we no longer pre-empt
    // core's loading, so Gutenberg content picks up its WP-default
    // styling while custom theme tokens (loaded after, via
    // agentshell_inject_saved_styles) still win the cascade.
    wp_deregister_style( 'wp-components' );
    wp_deregister_style( 'wp-editor' );
    wp_deregister_style( 'wp-format-library' );
    wp_deregister_style( 'wp-nux' );
    wp_deregister_style( 'wp-list-reusable-blocks' );

    // Legacy deprecated printer that double-loads older stylesheets.
    // Front-end block / global / template actions are no longer
    // suppressed — let core decide what is needed.
    remove_action( 'wp_enqueue_scripts', 'wp_print_styles' );
    add_filter( 'wp_resource_hints', function( $urls, $relation_type ) {
        if ( 'dns-prefetch' === $relation_type ) {
            $urls = array_filter( $urls, function( $url ) {
                return ! strpos( $url['href'] ?? '', 'wp-includes' );
            } );
        }
        return $urls;
    }, 10, 2 );

    // Main theme stylesheet — always enqueued with filemtime cache busting
    wp_enqueue_style(
        'agentshell-style',
        get_template_directory_uri() . '/style.css',
        array(),
        filemtime( get_template_directory() . '/style.css' )
    );

    // Theme JS — mobile nav toggle, always enqueued with filemtime cache busting
    wp_enqueue_script(
        'agentshell-theme',
        get_template_directory_uri() . '/assets/theme.js',
        array(),
        filemtime( get_template_directory() . '/assets/theme.js' ),
        true
    );

    // Configurator assets: theme-managers only. The configurator exposes the
    // full PUT surface for custom_js / custom_css / raw content; gating it
    // on edit_theme_options matches the REST permission callback and ensures
    // a subscriber never sees the trigger button. The REST endpoint itself
    // is independently gated by agentshell_rest_auth_callback — this is
    // defense in depth so the UI matches the API.
    if ( ! is_user_logged_in() || ! current_user_can( 'edit_theme_options' ) ) {
        return;
    }
    wp_enqueue_style( 'agentshell-configurator', get_template_directory_uri() . '/configurator/configurator.css', array( 'agentshell-style' ), filemtime( get_template_directory() . '/configurator/configurator.css' ) );
    wp_enqueue_script( 'agentshell-configurator', get_template_directory_uri() . '/configurator/configurator.js', array(), filemtime( get_template_directory() . '/configurator/configurator.js' ), true );
    wp_localize_script( 'agentshell-configurator', 'AgentShellConfig', array(
        'adminUrl'   => admin_url( 'admin-ajax.php' ),
        'restUrl'    => rest_url(),
        'nonce'      => wp_create_nonce( 'wp_rest' ),
    ) );
}
add_action( 'wp_enqueue_scripts', 'agentshell_enqueue_assets' );

/**
 * Authorise this request's transaction preview, once, before any output.
 *
 * Runs on template_redirect priority 0 — the last point before WordPress
 * renders, and the only one where headers can still be changed. Its job is
 * twofold:
 *
 *  1. Decide whether the staged config may be rendered at all.
 *  2. If so, mark the response uncacheable.
 *
 * (2) is not optional. Without an explicit Cache-Control, a host with a shared
 * page cache — nginx proxy_cache, a CDN, Varnish — is free to store a
 * response carrying uncommitted design and replay it to ordinary visitors. The
 * same bytes, served to the wrong person, are exactly the disclosure the
 * transaction exists to prevent. WordPress sends no cache headers on ordinary
 * front-end pages, so this has to be set here rather than inherited.
 *
 * The authorisation decision is memoised per credential so the later render-time
 * call in agentshell_config_for_render() cannot disagree with it. The header
 * emission deliberately sits *outside* that memo: it is idempotent, and folding
 * it in would let a repeat call report "authorised" without ever having marked
 * the response uncacheable.
 *
 * @return bool Whether the staged config is authorised for this request.
 */
function agentshell_authorize_transaction_preview() {
    static $decided = array();

    $tx_id = isset( $_GET['agentshell_tx'] ) ? sanitize_text_field( wp_unslash( $_GET['agentshell_tx'] ) ) : '';
    $key   = isset( $_GET['agentshell_tx_key'] ) ? sanitize_text_field( wp_unslash( $_GET['agentshell_tx_key'] ) ) : '';
    $exp   = isset( $_GET['agentshell_tx_exp'] ) ? sanitize_text_field( wp_unslash( $_GET['agentshell_tx_exp'] ) ) : '';

    // Keyed on the credential rather than a bare flag, so a different
    // credential is decided on its own merits rather than inheriting a verdict.
    $fingerprint = $tx_id . '|' . $key . '|' . $exp;

    if ( ! array_key_exists( $fingerprint, $decided ) ) {
        $decided[ $fingerprint ] = false;
        if ( '' !== $tx_id && '' !== $key && '' !== $exp
            && class_exists( '\AgentShell_MCP\Transaction_Manager' )
            && \AgentShell_MCP\Transaction_Manager::authorize_preview( $tx_id, $key, $exp )
        ) {
            $decided[ $fingerprint ] = true;
        }
    }

    if ( ! $decided[ $fingerprint ] ) {
        return false;
    }

    // Authorised — so the response must be marked uncacheable before it is
    // built. If headers have already gone we cannot do that, and a staged
    // render we cannot protect from a shared cache is worse than no render at
    // all: it costs a screenshot to refuse, and a visitor's privacy to allow.
    if ( agentshell_response_headers_sent() ) {
        return false;
    }
    nocache_headers();
    agentshell_emit_response_header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
    // same-origin would still hand the full URL — transaction key included — to
    // same-origin subresources. This page has no reason to refer anywhere.
    agentshell_emit_response_header( 'Referrer-Policy: no-referrer' );

    // Record the authorisation for the render-time checks in
    // agentshell_is_preview_request(). Set only after the credential has actually
    // verified, never merely because the arguments are present, so it cannot be
    // used as a "is this a preview" oracle by an anonymous request.
    $GLOBALS['agentshell_transaction_preview'] = array(
        'tx_id' => $tx_id,
        'key'   => $key,
        'exp'   => $exp,
    );

    return true;
}
add_action( 'template_redirect', 'agentshell_authorize_transaction_preview', 0 );

/**
 * Whether the current request may carry a post-preview credential at all.
 *
 * Separated from verification because it is a different question and a
 * different answer: a signature can verify perfectly for a request that has no
 * business impersonating anyone. Measured on a naive implementation, an
 * identity restored at `init` also authorised unrelated private posts and the
 * entire REST API — including user enumeration — because REST is served on
 * `parse_request`, which runs after `init`. The credential is therefore only
 * honoured for a plain front-end page GET, and only when the user cannot act
 * on the request.
 *
 * @return bool
 */
function agentshell_post_preview_route_allowed() {
    // Anything but a GET. A GET is idempotent by construction; anything that
    // changes state should never run under a temporarily restored identity.
    $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : 'GET';
    if ( 'GET' !== $method ) {
        return false;
    }

    // wp-admin, and anything routing through it (admin-ajax, admin-post).
    if ( is_admin() ) {
        return false;
    }

    // The REST server runs on `parse_request`, i.e. after `init`, so an identity
    // restored here would otherwise authorise the whole API for this request.
    if ( defined( 'REST_REQUEST' ) || isset( $_GET['rest_route'] ) ) {
        return false;
    }
    // XML-RPC authenticates independently and has no business here.
    if ( defined( 'XMLRPC_REQUEST' ) ) {
        return false;
    }

    // The permalink-prefix route to REST, for the case where REST_REQUEST is not
    // yet defined at `init` priority 0.
    $uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = wp_parse_url( $uri, PHP_URL_PATH );
    if ( is_string( $path ) && function_exists( 'rest_get_url_prefix' ) ) {
        $prefix = trim( (string) rest_get_url_prefix(), '/' );
        if ( '' !== $prefix && preg_match( '#(^|/)' . preg_quote( $prefix, '#' ) . '(/|$)#', $path ) ) {
            return false;
        }
    }

    // wp-login.php serves its own auth flows; a restored identity there is
    // pointless at best.
    if ( is_string( $path ) && preg_match('#/wp-login\.php$#', $path ) ) {
        return false;
    }

    // Cron runs unauthenticated maintenance; do not lend it an identity.
    if ( is_string( $path ) && preg_match('#/wp-cron\.php$#', $path ) ) {
        return false;
    }

    return true;
}

/**
 * Restore the previewing caller's identity for this request, if authorised.
 *
 * Runs on `init` priority 0, and that timing is not negotiable: WordPress
 * evaluates the capability check for an unpublished post inside the main query
 * (`wp()` -> `WP::main()` -> `query_posts()`), which happens after `init`.
 * Verifying later — `template_redirect`, say, where the transaction preview is
 * verified — is too late, and produces a 404 that looks like a bad credential.
 *
 * What it does *not* do is decide the request is a preview of this post. That
 * is `agentshell_bind_post_preview_to_query()`'s job, and the separation is the
 * point: identity and post binding are independent gates, so satisfying one
 * never implies the other.
 *
 * @return bool Whether an identity was restored.
 */
function agentshell_authorize_post_preview() {
    // Keyed on the credential rather than a bare flag, for the same reason as
    // agentshell_authorize_transaction_preview(): a different credential must be
    // decided on its own merits rather than inheriting the previous verdict.
    // In production one request carries one credential, so this is not a
    // behaviour change — it is what lets the decision be tested at all, since a
    // single-process test run evaluates many credentials in sequence.
    static $decided = array();

    $post_id = isset( $_GET[ 'agentshell_post' ] ) ? sanitize_text_field( wp_unslash( $_GET['agentshell_post'] ) ) : '';
    $user_id = isset( $_GET[ 'agentshell_post_user' ] ) ? sanitize_text_field( wp_unslash( $_GET['agentshell_post_user'] ) ) : '';
    $key     = isset( $_GET[ 'agentshell_post_key' ] ) ? sanitize_text_field( wp_unslash( $_GET['agentshell_post_key'] ) ) : '';
    $exp     = isset( $_GET[ 'agentshell_post_exp' ] ) ? sanitize_text_field( wp_unslash( $_GET['agentshell_post_exp'] ) ) : '';

    $fingerprint = $post_id . '|' . $user_id . '|' . $key . '|' . $exp;
    if ( array_key_exists( $fingerprint, $decided ) ) {
        return $decided[ $fingerprint ];
    }
    $decided[ $fingerprint ] = false;

    if ( ! class_exists( '\AgentShell_MCP\Post_Preview' ) ) {
        return false;
    }

    // Nothing to authorise: an ordinary request. This is the overwhelmingly
    // common path and must stay free of side effects.
    if ( '' === $post_id || '' === $user_id || '' === $key || '' === $exp ) {
        return false;
    }

    if ( ! agentshell_post_preview_route_allowed() ) {
        return false;
    }

    $resolved = \AgentShell_MCP\Post_Preview::authenticate( $post_id, $user_id, $key, $exp );
    if ( ! $resolved ) {
        return false;
    }

    wp_set_current_user( $resolved );
    $GLOBALS['agentshell_post_preview'] = array(
        'post_id' => (int) $post_id,
        'user_id' => $resolved,
        'exp'     => (int) $exp,
    );
    $decided[ $fingerprint ] = true;
    return true;
}
add_action( 'init', 'agentshell_authorize_post_preview', 0 );

/**
 * Turn an authorised-but-mismatched post preview into the ordinary 404.
 *
 * `WP_Query::set_404()` alone is not enough, and this is worth stating because
 * it looks sufficient: it raises the `is_404` flag and nothing else. The query
 * keeps its posts and the global `$post` still points at the resolved object, so
 * a singular template goes on to render exactly the content that was just
 * refused. Measured: a credential minted for post 10, pointed at private post
 * 11, returned post 11's content with a 404 flag set behind it.
 *
 * So the query is emptied as well, and the request is turned into the same 404
 * an anonymous visitor receives — same status, same template, same body.
 *
 * @param string $reason Recorded for diagnosis; never rendered.
 */
function agentshell_refuse_post_preview( $reason = 'post-binding-mismatch' ) {
    if ( ! agentshell_response_headers_sent() ) {
        status_header( 404 );
        nocache_headers();
        agentshell_emit_response_header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
    }
    $GLOBALS['agentshell_post_preview'] = null;
    $GLOBALS['agentshell_post_preview_refused'] = $reason;

    $query = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null;
    if ( is_object( $query ) ) {
        if ( method_exists( $query, 'set_404' ) ) {
            $query->set_404();
        }
        // The flag is not the refusal; emptying the query is. Both, because a
        // template that reads the global $post rather than looping needs the
        // global cleared too.
        $query->posts         = array();
        $query->post          = null;
        $query->post_count    = 0;
        $query->found_posts   = 0;
        $query->is_singular   = false;
        $query->is_page       = false;
        $query->is_single     = false;
    }
    // Cleared so a template cannot reach the refused post through it.
    $GLOBALS['post'] = null;
    if ( function_exists( 'wp_reset_postdata' ) ) {
        @wp_reset_postdata();
    }
    // Nothing should redirect from here: a Location header carrying the
    // credential to some other path would both leak it and hand the browser a
    // second chance at the same request.
    if ( ! agentshell_response_headers_sent() ) {
        remove_action( 'template_redirect', 'redirect_canonical' );
    }
}

/**
 * Confirm the authorised request actually resolved to the bound post, and mark
 * the response so it cannot be cached, indexed or referred.
 *
 * Runs on `template_redirect` priority 0 — after `wp()` has run the main query
 * and its capability check, but before any template output. Checking before and
 * after the query is not belt-and-braces here; the after-check is the only one
 * that works, because before the query nobody knows what the request resolves
 * to — and a request can resolve to the bound post through several aliases.
 */
function agentshell_bind_post_preview_to_query() {
    $preview = isset( $GLOBALS['agentshell_post_preview'] ) ? $GLOBALS['agentshell_post_preview'] : null;
    if ( ! is_array( $preview ) ) {
        return;
    }

    // Whatever happens next, this response must not be stored or indexed: it is
    // one person's unpublished content.
    if ( ! agentshell_response_headers_sent() ) {
        nocache_headers();
        agentshell_emit_response_header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
        // same-origin would still hand the full URL — credential included — to
        // same-origin subresources. This page has no reason to refer anywhere.
        agentshell_emit_response_header( 'Referrer-Policy: no-referrer' );
    }

    $resolved_id = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
    $is_singular = function_exists( 'is_singular' ) ? is_singular() : false;

    if ( $resolved_id !== (int) $preview['post_id'] || ! $is_singular ) {
        // The credential is valid but this request is not that post's preview.
        // Refuse quietly, and identically to how an unauthorised request is
        // refused, so this cannot be used to probe which posts a user can read.
        agentshell_refuse_post_preview();
        return;
    }
}
add_action( 'template_redirect', 'agentshell_bind_post_preview_to_query', 0 );

/**
 * Whether this request is rendering an authorised preview.
 *
 * Both gates set their global only after a credential has actually verified, and
 * never merely because credential-shaped arguments are present. That distinction
 * is load-bearing: if this keyed off the *arguments*, an anonymous visitor could
 * suppress the admin bar on any page just by appending `?agentshell_post_key=x`,
 * which is both an information leak and a griefing primitive.
 *
 * Both gates run on `template_redirect` at priority 0; the scrub below runs at
 * priority 1 on the same hook, so by the time anything asks, both globals have
 * their final value.
 *
 * @return bool
 */
function agentshell_is_preview_request() {
    return ! empty( $GLOBALS['agentshell_post_preview'] )
        || ! empty( $GLOBALS['agentshell_transaction_preview'] );
}

/**
 * Hide the admin bar on a credential-authorised preview.
 *
 * Measured, not hypothesised: with the bar present, the preview response carried
 * both credentials in a single element. WordPress builds the bar's "Customise"
 * link from `$_SERVER['REQUEST_URI']`, so the full request URL — credential
 * arguments included — was written into an `href` in the rendered HTML, URL-encoded.
 * That puts the post key and the transaction key into the page body, the DOM, and
 * anything that saves the page, all of which outlive the 300-second TTL that is
 * supposed to be their whole life.
 *
 * The bar is the whole fix rather than patching the one link, because it is the
 * only place either credential appeared: every occurrence in the response came
 * from that single `ab-item` link. It is also the right answer on the merits — an
 * admin bar is a control surface for editing, and a headless capture has no use
 * for it. Nothing navigates it, so removing it costs the capture nothing.
 *
 * Scoped deliberately. This returns false only for a request that has already
 * passed a credential gate; every other request — logged-in or not — keeps its
 * admin bar exactly as before.
 *
 * Timing is safe without any extra bookkeeping. Core builds the bar lazily:
 * `_wp_admin_bar_init()` (on `template_redirect`, priority 0) merely instantiates
 * the object, and the nodes are assembled by `admin_bar_menu` inside
 * `wp_admin_bar_render()`, which runs much later — on `wp_body_open`, and failing
 * that `wp_footer`. Both call `is_admin_bar_showing()`, which consults this filter,
 * so by the time the credential-bearing href would be built the bar has already
 * been ruled out. It also returns early before `admin_bar_menu` fires, so no
 * callback runs and nothing is built from the request URL at all.
 *
 * @param bool $show Whether core wants to show the admin bar.
 * @return bool
 */
function agentshell_suppress_preview_admin_bar( $show ) {
    if ( ! agentshell_is_preview_request() ) {
        return $show;
    }
    return false;
}
add_filter( 'show_admin_bar', 'agentshell_suppress_preview_admin_bar' );

/**
 * Remove preview credential arguments from the request, once they are spent.
 *
 * Defence in depth behind the admin bar suppression, and it is deliberately not
 * the primary control: the credential has to be read from `$_GET` before the
 * gates can verify it, and several parts of core read the request URL later on.
 * Clearing it here means that whatever else reproduces `REQUEST_URI` — a
 * canonical link, a shortlink, a plugin, a template — finds nothing to reproduce.
 *
 * Scoped to `$_SERVER['REQUEST_URI']` and nothing else, deliberately. `$_GET` is
 * the credential's transport and has to survive: `agentshell_config_for_render()`
 * calls the transaction verifier *again* at render time, and that verifier reads
 * `$_GET`. Clearing it here would make the second call see an empty credential and
 * quietly drop the staged design from the render — a correctness bug traded for a
 * leak that the admin-bar filter already prevents.
 *
 * `REQUEST_URI` is where the leak was actually measured: core's
 * `wp_admin_bar_customize_menu()` builds its href from `$_SERVER['REQUEST_URI']`,
 * so any code that reproduces the current URL — the admin bar, and any plugin or
 * template doing the same — finds nothing to reproduce once this runs.
 *
 * Runs only once a credential has verified, so it cannot interfere with
 * verification, and only for a preview, so an ordinary request is never modified.
 *
 * Hooked separately from the post gate, rather than inside it, because a
 * transaction-only preview carries `agentshell_tx_key` and has no post credential
 * to bind to — folding this into the post gate would leave that key in the HTML.
 */
function agentshell_scrub_preview_credentials_from_request() {
    if ( ! agentshell_is_preview_request() ) {
        return;
    }

    $args = array(
        'agentshell_post', 'agentshell_post_key', 'agentshell_post_exp', 'agentshell_post_user',
        'agentshell_tx', 'agentshell_tx_key', 'agentshell_tx_exp',
        'agentshell_preview', 'agentshell_preview_key',
    );

    if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
        return;
    }

    $uri   = (string) $_SERVER['REQUEST_URI'];
    $parts = explode( '?', $uri, 2 );
    if ( ! isset( $parts[1] ) || '' === $parts[1] ) {
        return;
    }

    // Keep the path, and every unrelated argument, byte-identical apart from the
    // credentials — so nothing downstream changes except the disappearance of the
    // secrets. parse_str/http_build_query rather than a regex so an encoded or
    // oddly-ordered argument is handled by the same parser that produced it.
    parse_str( $parts[1], $query );

    $removed = false;
    foreach ( $args as $arg ) {
        if ( array_key_exists( $arg, $query ) ) {
            unset( $query[ $arg ] );
            $removed = true;
        }
    }
    if ( ! $removed ) {
        return;
    }

    $rebuilt = http_build_query( $query );
    $_SERVER['REQUEST_URI'] = '' === $rebuilt ? $parts[0] : $parts[0] . '?' . $rebuilt;

    // WP_HOME/WP_SITEURL are unaffected — this is a request-local value only. The
    // original URL is deliberately not restored afterwards: restoring it would
    // reintroduce the leak for any hook that runs after this one.
}
// Priority 1: strictly after both credential gates (priority 0), so the globals
// are final and no gate can be confused by having had its inputs removed.
add_action( 'template_redirect', 'agentshell_scrub_preview_credentials_from_request', 1 );

/**
 * Never let a canonical redirect propagate a preview credential.
 *
 * `redirect_canonical()` preserves unknown query arguments, so a request
 * carrying a preview key is redirected *with the key still attached* — the
 * credential then travels to a path the theme never vetted, and would be
 * written to whatever logs sit in front of the site. Measured: a published
 * page requested with a post-preview credential answered `301` with the full
 * credential in the `Location` header.
 *
 * Returning false suppresses the redirect entirely. That is the safe direction
 * to fail: the worst outcome is an un-canonicalised URL, which costs a redirect
 * nobody relies on, rather than a bearer credential relayed to an unreviewed
 * destination.
 *
 * @param string|false $redirect_url  Target, or false/'' to stop.
 * @param string       $requested_url URL as requested.
 * @return string|false
 */
function agentshell_block_preview_redirects( $redirect_url, $requested_url = '' ) {
    if ( ! $redirect_url ) {
        return $redirect_url;
    }

    // Parse the query rather than substring-matching it. Matching the literal
    // "name=" is over-broad in a way that changes unrelated behaviour: a site
    // with its own `?my_agentshell_post=1` would silently lose canonical
    // redirects. Checking the parsed argument names matches exactly the
    // arguments that actually carry a credential.
    $query = wp_parse_url( (string) $requested_url, PHP_URL_QUERY );
    if ( ! is_string( $query ) || '' === $query ) {
        return $redirect_url;
    }
    parse_str( $query, $params );
    foreach ( array(
        'agentshell_tx', 'agentshell_tx_key', 'agentshell_tx_exp',
        'agentshell_post', 'agentshell_post_key', 'agentshell_post_exp', 'agentshell_post_user',
        'agentshell_preview', 'agentshell_preview_key',
    ) as $arg ) {
        if ( array_key_exists( $arg, $params ) ) {
            return false;
        }
    }
    return $redirect_url;
}
add_filter( 'redirect_canonical', 'agentshell_block_preview_redirects', 0, 2 );

/**
 * Config to render for the current request.
 *
 * Normally this is the committed config, read straight from the option. When
 * the caller presents a valid transaction-preview credential it is the staged
 * config instead — substituted here, in memory, for this one request only.
 *
 * Why substitution rather than an extra :root block appended after the saved
 * config: a commit rewrites the whole option, so a preview has to reflect the
 * whole rewrite. Tokens the transaction *removes* must disappear, and an
 * override block cannot express that — the stale value from the committed block
 * would still win. Substituting before rendering makes the preview byte-for-byte
 * what the page will look like after the commit.
 *
 * Nothing is persisted on this path. `get_option` above is never written, no
 * object cache is primed with the staged array, and no filter is left attached
 * — so the staged values exist for exactly as long as this response is built.
 * Every failure path returns the committed config unchanged.
 *
 * This is the *only* function that decides what a render sees, and it covers
 * the whole config — design tokens, zones and widgets alike. It used to be
 * consulted only by the stylesheet, while zone composition and the widget
 * registry read the committed option directly, so a transaction preview showed
 * the staged colours and not the staged layout. The composition the preview
 * showed was therefore never the composition that would be committed.
 */
function agentshell_config_for_render() {
    $config = agentshell_get_config();

    if ( ! agentshell_authorize_transaction_preview() ) {
        return $config;
    }

    $staged = \AgentShell_MCP\Transaction_Manager::instance()->staged_config();
    return is_array( $staged ) ? $staged : $config;
}

/**
 * Widget registry for the current render.
 *
 * Exists so a render path cannot quietly read the committed registry while the
 * stylesheet next to it reads the staged one — the preview showed widgets and
 * zones from two different states at once. Never call this from a tool; a tool
 * wants agentshell_get_widget_registry( agentshell_get_config( 'effective' ) ).
 */
function agentshell_widget_registry_for_render() {
    return agentshell_get_widget_registry( agentshell_config_for_render() );
}

/**
 * Zones for the current render. Same contract as
 * agentshell_widget_registry_for_render().
 */
function agentshell_zones_for_render() {
    return agentshell_get_zones( agentshell_config_for_render() );
}

/**
 * 1. Inject Saved CSS Variables, custom_css, and structural prohibition.
 * Injects all -- vars from flattened config, custom_css if present,
 * and a grid-fix rule that prevents agents from breaking layout with
 * position: fixed/absolute on zone containers.
 */
function agentshell_inject_saved_styles() {
    $config = agentshell_config_for_render();

    if ( function_exists( 'agentshell_flatten_config' ) ) {
        $flat = agentshell_flatten_config( $config );
    } else {
        $flat = $config;
    }

    if ( empty( $flat ) ) return;

    echo "<style id='agentshell-saved-config'>\n:root {\n";
    foreach ( $flat as $key => $value ) {
        if ( strpos( $key, '--' ) === 0 && ! empty( $value ) ) {
            echo '    ' . esc_attr( $key ) . ': ' . esc_attr( $value ) . ";\n";
        }
    }
    echo "}\n</style>\n";

    // custom_css — trusted author context. We strip pre-existing <style>
    // tags from the user payload so the value can be safely wrapped in our
    // own <style id='agentshell-custom-css'>; any literal </style> in the
    // remaining content is neutralised by agentshell_harden_custom_css so
    // it cannot close the wrapping element early.
    if ( ! empty( $config['custom_css'] ) ) {
        $css = preg_replace( '/<\/?style\b[^>]*>/i', '', $config['custom_css'] );
        $css = agentshell_harden_custom_css( trim( $css ) );
        echo "<style id='agentshell-custom-css'>\n" . $css . "\n</style>\n";
    }

    // Configurable layout — only emitted when agentshell_set_layout has
    // actually stored something the renderer should respect (grid_areas,
    // grid_gap, or grid_padding). Without this gate, the original
    // behaviour — flex-column default from style.css Section 3 — is
    // preserved verbatim. This is the fix for the false-success defect:
    // the tool used to return success without any CSS being emitted; the
    // root cause was that nothing read config['layout'].
    $layout_css = agentshell_layout_css();
    if ( '' !== $layout_css ) {
        echo "<style id='agentshell-layout-css'>\n" . $layout_css . "</style>\n";
    }

    // Theme profile preview: ?agentshell_preview=<profile> renders a saved
    // profile's design tokens as :root overrides — read-only and never
    // persisted. Authorized either by a logged-in browser session, or by an
    // HMAC key derived from AGENTSHELL_REST_TOKEN (used by the headless
    // screenshot tool, which has no session).
    if ( isset( $_GET['agentshell_preview'] ) ) {
        $profiles = get_option( 'agentshell_theme_profiles', array() );
        $name     = sanitize_key( wp_unslash( $_GET['agentshell_preview'] ) );
        if ( $name && isset( $profiles[ $name ]['design'] ) && is_array( $profiles[ $name ]['design'] ) ) {
            $authorized = is_user_logged_in();
            if ( ! $authorized && defined( 'AGENTSHELL_REST_TOKEN' ) && isset( $_GET['agentshell_preview_key'] ) ) {
                $expected  = hash_hmac( 'sha256', $name, AGENTSHELL_REST_TOKEN );
                $provided  = sanitize_text_field( wp_unslash( $_GET['agentshell_preview_key'] ) );
                $authorized = hash_equals( $expected, $provided );
            }
            if ( $authorized ) {
                $preview = array( 'design' => $profiles[ $name ]['design'] );
                if ( function_exists( 'agentshell_flatten_config' ) ) {
                    $overrides = agentshell_flatten_config( $preview );
                } else {
                    $overrides = array();
                    array_walk_recursive( $preview, function( $value, $key ) use ( &$overrides ) {
                        if ( strpos( (string) $key, '--' ) === 0 && is_string( $value ) ) {
                            $overrides[ $key ] = $value;
                        }
                    } );
                }
                if ( ! empty( $overrides ) ) {
                    echo "<style id='agentshell-preview-overrides'>\n:root {\n";
                    foreach ( $overrides as $key => $value ) {
                        echo '    ' . esc_attr( $key ) . ': ' . esc_attr( $value ) . ";\n";
                    }
                    echo "}\n</style>\n";
                }
            }
        }
    }

    // Structural prohibition: prevent agents from breaking the FSE layout
    // with position: fixed or absolute on zone containers.
    // Also collapse empty zone slots so an empty registered widget area
    // (header-extra / footer-extra / primary-sidebar) does not paint an
    // empty decorative container. Replaces the plan's edit to
    // template-parts/shell-render.php (protected file) with a CSS rule
    // that achieves the same outcome.
    echo "<style id='agentshell-grid-fix'>
#zone-header, #zone-main, #zone-footer {
    position: relative !important;
    top: auto !important;
    left: auto !important;
    right: auto !important;
    bottom: auto !important;
    z-index: auto !important;
}
.zone-slot:empty {
    display: none;
}
</style>\n";
}
// Priority 100 ensures this prints AFTER style.css
add_action( 'wp_head', 'agentshell_inject_saved_styles', 100 );

/**
 * Register the default WordPress widget areas available to AgentShell slots.
 *
 * Three areas ship by default — header-extra, footer-extra, and primary-sidebar.
 * They are *available*, not auto-assigned: the default header keeps its
 * site_logo / nav_menu / search_form arrangement and the default footer keeps
 * its site_title / nav_menu / empty arrangement. Operators (or a transaction)
 * place an area into a slot through the existing configurator. An empty
 * registered area renders no visible content — the slot wrapper is collapsed
 * by the `:empty` rule in agentshell-grid-fix below.
 *
 * The `agentshell_widget_areas` filter lets child themes and plugins add,
 * remove, or reorder areas. The default list is the only starting point;
 * removing an entry from the filtered list is the supported way to omit
 * an area. A buggy filter that returns a non-array falls back to the
 * defaults — silent failure of widget registration is unacceptable.
 * A valid empty array `[]` is intentional and is respected (zero areas
 * register). Each entry must be an array with a scalar id and name;
 * malformed entries are silently skipped. Duplicate ids keep the first.
 *
 * @return void
 */
function agentshell_register_widget_areas() {
    $defaults = array(
        array(
            'id'            => 'header-extra',
            'name'          => __( 'Header — Extra', 'agentshell' ),
            'description'   => __( 'Optional header slot. Operator assigns via the AgentShell configurator.', 'agentshell' ),
            'before_widget' => '<div id="%1$s" class="widget %2$s">',
            'after_widget'  => '</div>',
            'before_title'  => '<h2 class="widget-title">',
            'after_title'   => '</h2>',
        ),
        array(
            'id'            => 'footer-extra',
            'name'          => __( 'Footer — Extra', 'agentshell' ),
            'description'   => __( 'Optional footer slot. Operator assigns via the AgentShell configurator.', 'agentshell' ),
            'before_widget' => '<div id="%1$s" class="widget %2$s">',
            'after_widget'  => '</div>',
            'before_title'  => '<h2 class="widget-title">',
            'after_title'   => '</h2>',
        ),
        array(
            'id'            => 'primary-sidebar',
            'name'          => __( 'Primary Sidebar', 'agentshell' ),
            'description'   => __( 'Standard primary sidebar. Operator assigns via the AgentShell configurator.', 'agentshell' ),
            'before_widget' => '<aside id="%1$s" class="widget %2$s">',
            'after_widget'  => '</aside>',
            'before_title'  => '<h2 class="widget-title">',
            'after_title'   => '</h2>',
        ),
    );
    /** Allow child themes and plugins to add / remove / reorder widget areas. */
    $areas = apply_filters( 'agentshell_widget_areas', $defaults );

    // A buggy filter that returns a non-array must NOT silently disable
    // widget registration — fall back to the defaults. A valid empty array
    // is intentional and is respected: zero areas will register.
    if ( ! is_array( $areas ) ) {
        $areas = $defaults;
    }

    // Defensive validation: each entry must be an array with scalar id
    // and name. Bad entries are silently skipped, no PHP warning.
    // Duplicate IDs keep the first.
    $seen_ids = array();
    foreach ( $areas as $area ) {
        if ( ! is_array( $area ) ) {
            continue;
        }
        $id   = isset( $area['id'] )   && is_scalar( $area['id'] )   ? sanitize_key( (string) $area['id'] ) : '';
        $name = isset( $area['name'] ) && is_scalar( $area['name'] ) ? (string) $area['name']               : '';
        if ( '' === $id || '' === $name ) {
            continue;
        }
        if ( isset( $seen_ids[ $id ] ) ) {
            continue;
        }
        $seen_ids[ $id ] = true;
        $area['id']      = $id;
        register_sidebar( $area );
    }
}
add_action( 'widgets_init', 'agentshell_register_widget_areas' );

// Strip unwanted WordPress inline styles from head - runs last at wp_head
function agentshell_cleanup_wp_head() {
    // The deprecated wp_print_styles printer double-loads legacy styles.
    // wp_print_font_measure_styles is also a redundant printer.
    // The wp_enqueue_global_styles and wp_enqueue_block_template_styles
    // actions are no longer suppressed — they are the supported
    // mechanism for theme.json and block-template CSS, and WordPress
    // core decides when each fires.
    remove_action( 'wp_head', 'wp_print_styles' );
    remove_action( 'wp_head', 'wp_print_font_measure_styles' );
}
add_action( 'init', 'agentshell_cleanup_wp_head', 100 );

// Output buffer to strip persistent inline styles that bypass deregister.
// By wp_head (priority 99999), template rendering is complete so there should
// be exactly one buffer (ours). We use ob_end_flush to close it properly
// instead of ob_get_clean which only retrieves content without closing.
function agentshell_ob_strip_inline_styles() {
    if ( is_admin() || did_action( 'wp_head' ) ) {
        return;
    }
    ob_start();
}
add_action( 'wp', 'agentshell_ob_strip_inline_styles', 1 );

function agentshell_ob_flush() {
    if ( ob_get_level() ) {
        $html = ob_get_clean();
        $html = preg_replace( '/<style id=["\']wp-img-auto-sizes[^>]*>.*?<\/style>\s*/is', '', $html );
        echo $html;
    }
}
add_action( 'wp_head', 'agentshell_ob_flush', 99999 );


/**
 * @deprecated v2.0 — sidebar removed. Keeping stub to prevent fatals on upgrade.
 */
function agentshell_persist_sidebar( $classes ) {
    return $classes;
}

/**
 * Register nav menus.
 */
/**
 * Tiny dispatch wrapper around header() that tests can intercept
 * without needing to override PHP's built-in (which cannot be
 * redeclared). Production code calls header(); tests define
 * agentshell_test_capture_header first to capture emitted headers.
 */
function agentshell_emit_response_header( $header ) {
    if ( function_exists( 'agentshell_test_capture_header' ) ) {
        agentshell_test_capture_header( $header );
        return;
    }
    header( $header );
}

/**
 * Tiny dispatch wrapper around headers_sent(). Production code calls
 * headers_sent(); tests define agentshell_test_headers_sent() first to
 * control the return value.
 */
function agentshell_response_headers_sent() {
    if ( function_exists( 'agentshell_test_headers_sent' ) ) {
        return (bool) agentshell_test_headers_sent();
    }
    return headers_sent();
}

function agentshell_send_security_headers() {
    if ( agentshell_response_headers_sent() ) {
        return;
    }
    agentshell_emit_response_header( 'X-Content-Type-Options: nosniff' );
    agentshell_emit_response_header( 'Referrer-Policy: same-origin' );
    agentshell_emit_response_header( 'X-Frame-Options: SAMEORIGIN' );
}
add_action( 'send_headers', 'agentshell_send_security_headers' );

function agentshell_setup() {
    locate_template( 'template-parts/shell-render.php', true, false );

    // Core theme supports — required for wp_core components to work:
    // custom-logo powers the site_logo block, title-tag replaces deprecated wp_title().
    add_theme_support( 'custom-logo', array(
        'height'      => 60,
        'width'       => 200,
        'flex-height' => true,
        'flex-width'  => true,
    ) );
    add_theme_support( 'title-tag' );
    add_theme_support( 'post-thumbnails' );
    add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );

    register_nav_menus( array(
        'primary' => esc_html__( 'Primary Navigation', 'agentshell' ),
    ) );
}
add_action( 'after_setup_theme', 'agentshell_setup' );

/**
 * Load pre-approved libraries for agent-built Web Component widgets.
 * Libraries are loaded ONLY when at least one registered widget declares them
 * in its 'libs' array, e.g. 'libs' => array( 'd3' ) or array( 'd3', 'mathjs' ).
 * This keeps D3 (~280KB) and Math.js (~700KB) off pages that don't use them.
 *
 * Available: d3 (window.d3), mathjs (window.math)
 * To add a library: register the dependency map below and document it.
 */
function agentshell_enqueue_widget_libs() {
    $wanted = array();
    foreach ( agentshell_widget_registry_for_render() as $widget ) {
        foreach ( (array) ( $widget['libs'] ?? array() ) as $lib ) {
            $wanted[ $lib ] = true;
        }
    }
    if ( empty( $wanted ) ) {
        return;
    }

    $libs = array(
        'd3'     => array(
            'src'       => 'https://d3js.org/d3.v7.min.js',
            'integrity' => 'sha384-CjloA8y00+1SDAUkjs099PVfnY2KmDC2BZnws9kh8D/lX1s46w6EPhpXdqMfjK6i',
        ),
        'mathjs' => array(
            'src'       => 'https://cdnjs.cloudflare.com/ajax/libs/mathjs/11.8.0/math.min.js',
            'integrity' => 'sha384-gJKcVYwVxispLQAFHIwGBvbEmMm4WableABNGVE9B88zRUh5ce+GMZJQ6ZU2dfcN',
        ),
    );

    foreach ( $libs as $slug => $lib ) {
        if ( empty( $wanted[ $slug ] ) ) {
            continue;
        }
        $handle = 'agentshell-' . $slug;
        wp_enqueue_script( $handle, $lib['src'], array(), null, true );
        wp_script_add_data( $handle, 'defer', true );
        wp_script_add_data( $handle, 'integrity', $lib['integrity'] );
        wp_script_add_data( $handle, 'crossorigin', 'anonymous' );
    }
}
add_action( 'wp_enqueue_scripts', 'agentshell_enqueue_widget_libs' );

/**
 * Whether a transaction is currently open.
 *
 * For readers that must decide whether a write they are about to make is safe.
 * Deliberately the narrow question — "is there an open transaction?" — and not a
 * configuration accessor: a caller that wants the staged values asks
 * agentshell_get_config( 'effective' ), and a caller that wants what visitors
 * see asks agentshell_get_config( 'committed' ). Nothing here returns config.
 *
 * Returns false when the MCP plugin is not loaded, which is every page render
 * that is not an agent request.
 *
 * @return bool
 */
function agentshell_transaction_open() {
    if ( ! class_exists( '\AgentShell_MCP\Transaction_Manager' ) ) {
        return false;
    }
    return \AgentShell_MCP\Transaction_Manager::instance()->is_active();
}

/**
 * The configuration contract.
 *
 * There are exactly two states, and which one a caller gets is always an
 * explicit decision:
 *
 *   'committed'  What is stored. What an ordinary visitor sees, what the theme
 *                renders, and what survives a rollback. Default.
 *   'effective'  What an authorised tool should act on: the staged config when
 *                a transaction is open, otherwise the committed config. This is
 *                what lets register → enable → validate compose inside one
 *                transaction.
 *
 * The source is a required argument to every *other* reader
 * (agentshell_get_widget_registry, agentshell_get_zones), so a caller cannot
 * reach the wrong state by forgetting — and an unrecognised source throws
 * rather than defaulting, because a typo that silently fell back to
 * 'committed' would reproduce exactly the split this contract exists to remove.
 *
 * Rendering does not call this with 'effective'. A render path calls
 * agentshell_config_for_render(), which substitutes staged values only for a
 * request that has already passed the transaction-preview credential gate.
 * That keeps "an agent sees its staged work" and "a visitor sees only what is
 * committed" from being the same decision made in two places.
 *
 * @param string $source 'committed' or 'effective'.
 * @return array
 * @throws InvalidArgumentException on an unrecognised source.
 */
function agentshell_get_config( $source = 'committed' ) {
    if ( 'effective' === $source ) {
        // Staged first, committed second — not the other way round. The
        // committed path re-seeds an empty option, and a write during an open
        // transaction is intercepted into staging, which would replace the
        // agent's staged work with a default config on a read.
        if ( class_exists( '\AgentShell_MCP\Transaction_Manager' ) ) {
            $staged = \AgentShell_MCP\Transaction_Manager::instance()->staged_config();
            // staged_config() is null unless a transaction is open, so every
            // other case — including the very first call, before the plugin
            // has loaded — falls through to the committed config.
            if ( is_array( $staged ) ) {
                return $staged;
            }
        }
        return agentshell_get_config( 'committed' );
    }

    if ( 'committed' !== $source ) {
        throw new \InvalidArgumentException(
            "agentshell_get_config(): unknown source '{$source}'. "
            . "Use 'committed' (stored, what visitors see) or 'effective' (staged when a transaction is open)."
        );
    }

    $config = get_option( 'agentshell_config' );

    // Re-seed from default-config.json if the DB option is missing or empty.
    // Handles the case where get_option returns false (option not set yet).
    if ( $config === false || ! is_array( $config ) || empty( $config ) ) {
        $seed_file = get_template_directory() . '/default-config.json';
        if ( file_exists( $seed_file ) ) {
            $raw_seed = file_get_contents( $seed_file );
            if ( is_string( $raw_seed ) ) {
                $seed = json_decode( $raw_seed, true );
                if ( is_array( $seed ) ) {
                    // Do not persist the re-seed while a transaction is open.
                    // A write here is staged, and staging replaces the buffer
                    // wholesale rather than merging into it — so a *read* that
                    // happened to find an empty option would silently discard
                    // everything the agent had staged. A read must not be able
                    // to do that.
                    if ( ! agentshell_transaction_open() ) {
                        update_option( 'agentshell_config', $seed );
                    }
                    $config = $seed;
                }
            }
        }
    }

    // Schema self-healing: migrate flat 'composition' zones to 'slots' for header/footer
    if ( isset( $config['zones'] ) && is_array( $config['zones'] ) ) {
        foreach ( $config['zones'] as &$zone ) {
            if ( in_array( $zone['id'] ?? '', array( 'header', 'footer' ), true ) && ! isset( $zone['slots'] ) ) {
                $zone['slots'] = array(
                    'left'   => array(),
                    'center' => $zone['composition'] ?? array(),
                    'right'  => array(),
                );
                unset( $zone['composition'] );
            }
        }
    }

    return is_array( $config ) ? $config : array();
}

/**
 * Update the shell config atomically
 */
function agentshell_update_config( array $config ) {
    return update_option( 'agentshell_config', $config );
}

/**
 * Get the declared zones from config.
 * Falls back to default zones for existing installs that predate the zone registry.
 *
 * @param array|null $config Config to read. Null means committed — the state a
 *        visitor sees. Pass an explicit array (from agentshell_get_config(
 *        'effective' ) or agentshell_config_for_render()) whenever the caller is
 *        a tool working inside a transaction, or a render under an authorised
 *        preview. Left implicit on purpose: this used to reach for the config
 *        itself, so a staged zone was invisible to everything that asked here.
 *
 * @return array
 */
function agentshell_get_zones( $config = null ) {
    $defaults = array(
        array( 'id' => 'header', 'label' => 'Header', 'composition' => array( array( 'type' => 'wp_loop' ) ) ),
        array( 'id' => 'main',   'label' => 'Main',   'composition' => array( array( 'type' => 'wp_loop' ) ) ),
        array( 'id' => 'footer', 'label' => 'Footer', 'composition' => array( array( 'type' => 'wp_loop' ) ) ),
    );
    $config = is_array( $config ) ? $config : agentshell_get_config();
    $saved  = $config['zones'] ?? array();

    // Merge saved zone configs over defaults (saved overrides take precedence)
    $by_id = array();
    foreach ( $defaults as $d ) {
        $by_id[ $d['id'] ] = $d;
    }
    foreach ( $saved as $s ) {
        $id = $s['id'] ?? null;
        if ( $id && isset( $by_id[ $id ] ) ) {
            $by_id[ $id ] = array_merge( $by_id[ $id ], $s );
        } elseif ( $id ) {
            $by_id[ $id ] = $s;
        }
    }

    return array_values( $by_id );
}

/**
 * Merge stable widgets (from /widgets/ files) with agent-defined widgets (from wp_options).
 * JSON-defined widgets override file-defined widgets with the same ID.
 *
 * Every merged entry carries an explicit `source` field ('file' or 'config')
 * so consumers (the configurator, list_widgets, get_widget) do not have to
 * infer provenance by fragile heuristics.
 *
 * @param array|null $config Config to read. Null means committed — the state a
 *        visitor sees. Pass an explicit array whenever the caller is a tool
 *        working inside a transaction, or a render under an authorised preview.
 *        This is the parameter that fixes register → enable inside one
 *        transaction: enable_widget's existence guard used to ask here with no
 *        argument, so it read the committed registry, had never seen the widget
 *        the previous call staged, and rejected it as unknown.
 *
 * @return array Merged widget registry keyed by widget ID
 */
function agentshell_get_widget_registry( $config = null ) {
    $registry = array();

    // 1. Load stable widgets from /widgets/ directory
    $stable_index = get_template_directory() . '/widgets/.index.json';
    if ( file_exists( $stable_index ) ) {
        $raw_index = file_get_contents( $stable_index );
        $index     = is_string( $raw_index ) ? json_decode( $raw_index, true ) : null;
        if ( ! is_array( $index ) ) {
            $index = array();
        }
        foreach ( $index['stable'] ?? array() as $entry ) {
            if ( empty( $entry['id'] ) || empty( $entry['file'] ) ) {
                continue;
            }
            $widget_file = get_template_directory() . '/widgets/' . $entry['file'];
            if ( file_exists( $widget_file ) ) {
                $widget = include $widget_file;
                if ( is_array( $widget ) && ! empty( $widget['id'] ) ) {
                    // Stamp source explicitly so consumers don't infer.
                    $widget['source'] = 'file';
                    $registry[ $widget['id'] ] = $widget;
                }
            }
        }
    }

    // 2. Merge agent-defined widgets from config (these override stable with same ID)
    $config = is_array( $config ) ? $config : agentshell_get_config();
    foreach ( $config['widgets'] ?? array() as $widget ) {
        if ( empty( $widget['id'] ) ) {
            continue;
        }
        // Stamp source so downstream consumers can distinguish without
        // re-implementing the heuristic.
        $widget['source'] = isset( $widget['source'] ) ? $widget['source'] : 'config';
        $registry[ $widget['id'] ] = $widget;
    }

    // 3. Apply lifecycle status overrides (enable/disable via MCP tools)
    foreach ( $config['widget_overrides'] ?? array() as $widget_id => $override ) {
        if ( isset( $registry[ $widget_id ] ) && isset( $override['status'] ) ) {
            $registry[ $widget_id ]['status'] = $override['status'];
        }
    }

    // Default status for widgets that don't declare one
    foreach ( $registry as $widget_id => $widget ) {
        if ( ! isset( $widget['status'] ) ) {
            $registry[ $widget_id ]['status'] = 'active';
        }
    }

    return $registry;
}

/**
 * Migrate the legacy agentshell_widgets option (BILock-Blocks standalone
 * store) into the canonical agentshell_config.widgets. Idempotent: a
 * second invocation finds an empty legacy option and is a no-op.
 *
 * Called from the blocks plugin's activation hook so existing installations
 * do not silently lose widgets that were written via the legacy path.
 */
function agentshell_migrate_legacy_widgets_option() {
    $legacy = get_option( 'agentshell_widgets', array() );
    if ( ! is_array( $legacy ) || empty( $legacy ) ) {
        return; // nothing to migrate
    }

    $config         = agentshell_get_config();
    $existing_ids   = array();
    foreach ( (array) ( $config['widgets'] ?? array() ) as $w ) {
        if ( ! empty( $w['id'] ) ) {
            $existing_ids[ $w['id'] ] = true;
        }
    }

    $merged = $config['widgets'] ?? array();
    foreach ( $legacy as $widget ) {
        if ( empty( $widget['id'] ) || isset( $existing_ids[ $widget['id'] ] ) ) {
            continue; // skip entries without id, or already represented in config
        }
        $widget['source']       = 'config';
        $widget['_migrated_from'] = 'agentshell_widgets';
        $merged[ $widget['id'] ] = $widget;
    }

    $config['widgets'] = $merged;

    // Persist (or stage) through the same path mutations use, so history
    // is recorded and transaction staging still works.
    update_option( 'agentshell_config', $config );

    // Retire the legacy option only once the widgets are genuinely readable
    // from the canonical store. `update_option` is intercepted while a
    // transaction is open — the write is staged and never lands — so deleting
    // unconditionally would destroy the only surviving copy of the data on
    // exactly the path that did not save it. This helper runs on every
    // admin_init, so the next request retries.
    $persisted = get_option( 'agentshell_config' );
    $migrated  = array();
    foreach ( $legacy as $widget ) {
        if ( empty( $widget['id'] ) || isset( $existing_ids[ $widget['id'] ] ) ) {
            continue; // nothing to carry over from the legacy option
        }
        $migrated[ $widget['id'] ] = true;
    }
    foreach ( array_keys( $migrated ) as $id ) {
        if ( ! is_array( $persisted ) || ! isset( $persisted['widgets'][ $id ] ) ) {
            return; // not written — leave the legacy option alone
        }
    }

    delete_option( 'agentshell_widgets' );
}

/**
 * Remove a config-registered widget from the registry and clean up any
 * zone composition / slot entries that referenced it. File widgets cannot
 * be removed; the caller must use disable_widget for those.
 *
 * Pure data transformation; usable from tests without a live WP runtime.
 *
 * @param array  $config Existing agentshell_config array.
 * @param string $id     Widget id to remove.
 * @return array  Updated config.
 * @throws \InvalidArgumentException if the widget does not exist or is a
 *         stable file widget (use disable_widget instead).
 */
function agentshell_remove_widget_from_config( array $config, $id ) {
    $config_widgets = $config['widgets'] ?? array();
    if ( ! is_array( $config_widgets ) ) {
        $config_widgets = array();
    }

    $found_index = null;
    foreach ( $config_widgets as $i => $w ) {
        if ( ( $w['id'] ?? null ) === $id ) {
            $found_index = $i;
            break;
        }
    }

    if ( null === $found_index ) {
        // Not a config-registered widget. Could be a stable file widget —
        // refuse to remove.
        throw new \InvalidArgumentException(
            "Widget '{$id}' is a stable file widget and cannot be removed. Use agentshell_disable_widget instead."
        );
    }

    // Drop the entry, preserving the sequential shape that the rest of
    // the codebase (the merge function, the configurator GET) expects.
    unset( $config_widgets[ $found_index ] );
    $config['widgets'] = array_values( $config_widgets );

    if ( isset( $config['widget_overrides'][ $id ] ) ) {
        unset( $config['widget_overrides'][ $id ] );
    }

    // Strip dangling zone references (composition + each tri-slot side).
    if ( isset( $config['zones'] ) && is_array( $config['zones'] ) ) {
        foreach ( $config['zones'] as &$zone ) {
            if ( ! empty( $zone['composition'] ) && is_array( $zone['composition'] ) ) {
                $zone['composition'] = array_values( array_filter(
                    $zone['composition'],
                    function ( $block ) use ( $id ) {
                        return ! ( ( $block['type'] ?? '' ) === 'widget' && ( $block['id'] ?? '' ) === $id );
                    }
                ) );
            }
            if ( ! empty( $zone['slots'] ) && is_array( $zone['slots'] ) ) {
                foreach ( array( 'left', 'center', 'right' ) as $slot_key ) {
                    if ( ! empty( $zone['slots'][ $slot_key ] ) && is_array( $zone['slots'][ $slot_key ] ) ) {
                        $zone['slots'][ $slot_key ] = array_values( array_filter(
                            $zone['slots'][ $slot_key ],
                            function ( $block ) use ( $id ) {
                                return ! ( ( $block['type'] ?? '' ) === 'widget' && ( $block['id'] ?? '' ) === $id );
                            }
                        ) );
                    }
                }
            }
        }
        unset( $zone );
    }

    return $config;
}

/**
 * Return supported WordPress core components as composable blocks.
 *
 * @return array
 */
function agentshell_get_core_components() {
    return array(
        array( 'id' => 'site_title',    'name' => 'Site Title' ),
        array( 'id' => 'site_tagline', 'name' => 'Site Tagline' ),
        array( 'id' => 'site_logo',    'name' => 'Site Logo' ),
        array( 'id' => 'nav_menu',     'name' => 'Primary Navigation Menu' ),
        array( 'id' => 'search_form',  'name' => 'Search Form' ),
    );
}

/**
 * Render a single WordPress core component by ID.
 *
 * @param string $id Component ID (site_title, nav_menu, etc.)
 * @return string HTML
 */
function agentshell_render_core_component( $id ) {
    switch ( $id ) {
        case 'site_title':
            return get_bloginfo( 'name' ) ?: '';
        case 'site_tagline':
            return get_bloginfo( 'description' ) ?: '';
        case 'site_logo':
            ob_start();
            the_custom_logo();
            return ob_get_clean();
        case 'nav_menu':
            $nav = wp_nav_menu(array(
                'echo'           => false,
                'theme_location'=> 'primary',
                'container'     => 'nav',
                'container_class'=> 'wp-core-nav',
            )) ?: '<p class="wp-core-empty">' . esc_html__( 'No menu assigned to Primary Location', 'agentshell' ) . '</p>';
            // wp_nav_menu() has no container aria-label arg — add it directly.
            return str_replace(
                '<nav class="wp-core-nav">',
                '<nav class="wp-core-nav" aria-label="' . esc_attr__( 'Primary', 'agentshell' ) . '">',
                $nav
            );
        case 'search_form':
            return get_search_form( array( 'echo' => false ) );
        default:
            return '<!-- wp_core not found: ' . esc_html( $id ) . ' -->';
    }
}

/**
 * Render a single widget by ID.
 * Returns empty string if widget not found.
 *
 * @param string $widget_id
 * @return string HTML
 */
function agentshell_render_widget( $widget_id ) {
    $registry = agentshell_widget_registry_for_render();
    if ( ! isset( $registry[ $widget_id ] ) ) {
        return '';
    }
    $widget = $registry[ $widget_id ];

    // Disabled widgets render nothing (lifecycle management via MCP tools)
    if ( ( $widget['status'] ?? 'active' ) === 'disabled' ) {
        return '';
    }

    // Render template (optional HTML skeleton)
    $html = '';
    if ( ! empty( $widget['template'] ) ) {
        $html = wp_kses_post( $widget['template'] );
    } else {
        $html = "<div data-widget-id=\"{$widget_id}\"></div>";
    }

    return $html;
}

/**
 * Get all widget init scripts and scoped CSS for footer injection.
 * Returns array with 'init_js' and 'css' keys.
 *
 * @return array
 */
function agentshell_get_widget_assets() {
    $registry = agentshell_widget_registry_for_render();
    $init_js  = '';
    $css      = '';

    foreach ( $registry as $widget ) {
        if ( ! empty( $widget['init_js'] ) ) {
            $init_js .= "\n/* Widget: {$widget['id']} */\n";
            $init_js .= "(function() {\ntry {\n" . $widget['init_js'] . "\n} catch(e) {\nconsole.error('Agentshell widget init error (" . esc_js( $widget['id'] ) . "):', e);\n}\n})();\n";
        }
        if ( ! empty( $widget['css'] ) ) {
            $css .= "\n/* Widget: {$widget['id']} */\n" . $widget['css'];
        }
    }

    return array( 'init_js' => $init_js, 'css' => $css );
}

/**
 * Build the <style id='agentshell-widget-css'> block for the page head.
 *
 * Pure function returning a CSS string. The bilateral widget registry
 * (file-based + config-registered) declares per-widget `css` and
 * `init_js`; the renderer was never wired up to actually emit them, so
 * `agentshell_register_widget` with `css` was silently inert. This
 * function returns the concatenation of all widget CSS, ready to drop
 * into <head> via the wp_head hook registered just below.
 *
 * Returns an empty string when no widget has CSS, so the wp_head hook
 * can skip emission and not bloat the page with an empty <style>.
 */
function agentshell_widget_assets_head_css() {
    return agentshell_get_widget_assets()['css'];
}

/**
 * Build the <script id='agentshell-widget-init-js'> block for the footer.
 *
 * Same bilateral-registry pipeline as the head CSS, but for init_js.
 * The original `template-parts/widgets.php` claimed footer.php renders
 * the JS, but footer.php never did; this function plus the wp_footer
 * hook restores the intended behaviour without touching the protected
 * footer template.
 */
function agentshell_widget_assets_footer_js() {
    return agentshell_get_widget_assets()['init_js'];
}

/**
 * wp_head hook: emit the widget CSS block.
 *
 * The <style id='agentshell-widget-css'> wrapper matches the original
 * `template-parts/widgets.php` markup so existing widget CSS continues
 * to scope correctly. No edits to header.php / footer.php / the
 * widgets template are required — the emission moves into functions.php
 * (which is not on the protected list) and is dispatched by WordPress's
 * own wp_head / wp_footer pipeline.
 */
function agentshell_emit_widget_assets_head() {
    $css = agentshell_widget_assets_head_css();
    if ( '' !== $css ) {
        echo "<style id='agentshell-widget-css'>\n" . $css . "\n</style>\n";
    }
}
add_action( 'wp_head', 'agentshell_emit_widget_assets_head', 102 );

/**
 * wp_footer hook: emit the widget init JS block.
 */
function agentshell_emit_widget_assets_footer() {
    $js = agentshell_widget_assets_footer_js();
    if ( '' !== $js ) {
        echo "<script id='agentshell-widget-init-js'>\n" . $js . "\n</script>\n";
    }
}
add_action( 'wp_footer', 'agentshell_emit_widget_assets_footer', 102 );

/**
 * Get layout configuration (breakpoints, grid areas, gap, padding).
 * Deep-merges user config over hard defaults so partial configs work.
 *
 * grid_areas, grid_gap, grid_padding pass through what the user has set —
 * there is no sensible default for layout areas. breakspoints default to
 * mobile-first values matching the existing 768px / 1024px breakpoints in
 * style.css Section 9.
 *
 * @return array
 */
function agentshell_get_layout_config() {
    $config = agentshell_config_for_render();
    $layout = $config['layout'] ?? array();

    $defaults = array(
        'breakpoints' => array(
            'mobile'  => '0px',
            'tablet'  => '768px',
            'desktop' => '1024px',
        ),
    );

    // Deep-merge user config over defaults for the keys with defaults.
    foreach ( $defaults as $key => $default ) {
        if ( ! isset( $layout[ $key ] ) ) {
            $layout[ $key ] = $default;
        } elseif ( is_array( $default ) && is_array( $layout[ $key ] ) ) {
            $layout[ $key ] = array_merge( $default, $layout[ $key ] );
        }
    }

    return $layout;
}

/**
 * Build the layout CSS string for the current config['layout'].
 *
 * Pure function: returns the CSS that, when emitted into <head>, makes
 * agentshell_set_layout's stored values actually take effect on the page.
 * Before this function existed, the MCP tool reported success but no
 * renderer read its output — a UI lie that the layout-versatility
 * assessment surfaced. The grid-template-areas rules honor the AGENTS.md
 * §5 quoted-rows protocol: each row of the agent-supplied array becomes
 * one "row" cell in the grid-template-areas value, so a 3-row array
 * like ["hd hd", "mn mn", "ft ft"] is emitted verbatim as
 * "hd hd" "mn mn" "ft ft".
 *
 * Returns an empty string when no layout-affecting key is set, so the
 * caller can skip emission and the existing flex-column default in
 * style.css Section 3 stays the rendered behaviour.
 *
 * @return string
 */
function agentshell_layout_css() {
    $layout = agentshell_get_layout_config();

    // Per-breakpoint: grid-template-areas (mobile is the base; tablet and
    // desktop wrap in @media). grid_areas.mobile applies to all viewports
    // until the first matching @media overrides it.
    $areas_by_bp = array();
    foreach ( array( 'mobile', 'tablet', 'desktop' ) as $bp ) {
        $rows = $layout['grid_areas'][ $bp ] ?? null;
        if ( is_array( $rows ) && ! empty( $rows ) ) {
            $quoted = array();
            foreach ( $rows as $row ) {
                if ( is_string( $row ) && '' !== trim( $row ) ) {
                    $quoted[] = '"' . $row . '"';
                }
            }
            if ( ! empty( $quoted ) ) {
                $areas_by_bp[ $bp ] = $quoted;
            }
        }
    }

    // Global: grid_gap and grid_padding are not breakpoint-scoped.
    $global_rules = array();
    if ( ! empty( $layout['grid_gap'] ) && is_string( $layout['grid_gap'] ) ) {
        $global_rules[] = 'gap: ' . $layout['grid_gap'] . ';';
    }
    if ( ! empty( $layout['grid_padding'] ) && is_string( $layout['grid_padding'] ) ) {
        $global_rules[] = 'padding: ' . $layout['grid_padding'] . ';';
    }

    // No configurable layout at all → no emission; the existing flex-column
    // default in style.css Section 3 stays the rendered behaviour.
    if ( empty( $areas_by_bp ) && empty( $global_rules ) ) {
        return '';
    }

    $css = '';

    if ( ! empty( $areas_by_bp ) ) {
        // Per-breakpoint grid-template-areas forces display: grid, which
        // overrides the flex-column default in style.css Section 3. The
        // override is intentional: an agent that sets grid_areas is opting
        // into grid layout for the root container.
        $css .= "#agentshell-root {\n";
        $css .= "    display: grid;\n";
        $css .= "    grid-template-rows: auto 1fr auto;\n";
        if ( isset( $areas_by_bp['mobile'] ) ) {
            $css .= "    grid-template-areas: " . implode( ' ', $areas_by_bp['mobile'] ) . ";\n";
        }
        foreach ( $global_rules as $rule ) {
            $css .= '    ' . $rule . "\n";
        }
        $css .= "}\n";
        // Map the three shell zones onto the named grid areas.
        $css .= "#zone-header { grid-area: header; }\n";
        $css .= "#zone-main { grid-area: main; }\n";
        $css .= "#zone-footer { grid-area: footer; }\n";

        foreach ( array( 'tablet', 'desktop' ) as $bp ) {
            if ( ! isset( $areas_by_bp[ $bp ] ) ) { continue; }
            $bp_value = $layout['breakpoints'][ $bp ] ?? ( 'tablet' === $bp ? '768px' : '1024px' );
            $css .= "@media (min-width: " . $bp_value . ") {\n";
            $css .= "    #agentshell-root {\n";
            $css .= "        grid-template-areas: " . implode( ' ', $areas_by_bp[ $bp ] ) . ";\n";
            $css .= "    }\n";
            $css .= "}\n";
        }
    } else {
        // Only global rules (gap / padding) — apply to whichever display
        // mode style.css Section 3 already chose. Do not force grid.
        $css .= "#agentshell-root {\n";
        foreach ( $global_rules as $rule ) {
            $css .= '    ' . $rule . "\n";
        }
        $css .= "}\n";
    }

    return $css;
}

/**
 * Check a static-token authentication attempt.
 *
 * Pure function. Tests in tests/static-token-auth-test.php exercise
 * every branch without needing a live WP runtime.
 *
 * @param string $provided              Token presented in the request.
 * @param string $static_token          Expected static token (from wp-config).
 * @param int    $configured_user_id   Explicitly configured actor id.
 * @param bool   $user_exists           Whether the configured user exists in DB.
 * @return int|false   User id on success; false on any failure.
 */
function agentshell_check_static_token( $provided, $static_token, $configured_user_id, $user_exists ) {
    if ( ! is_string( $provided )     || $provided === '' )     { return false; }
    if ( ! is_string( $static_token ) || $static_token === '' ) { return false; }
    if ( $configured_user_id <= 0 )                              { return false; }
    if ( ! $user_exists )                                        { return false; }
    if ( ! hash_equals( $static_token, $provided ) )             { return false; }
    return $configured_user_id;
}

/**
 * Normalize an application password to the form WordPress hashes.
 *
 * Core (wp_authenticate_application_password in wp-includes/user.php) strips
 * every non-alphanumeric character before hashing, so the grouped display
 * form ("abcd efgh ijkl ...") and the bare form are the same credential.
 * Any re-implementation of application-password auth must mirror this, or
 * the standard copy-pasted password is rejected.
 *
 * @param string $password
 * @return string Alphanumeric-only password.
 */
function agentshell_normalize_app_password( $password ) {
    return preg_replace( '/[^a-z\d]/i', '', (string) $password );
}

/**
 * Check a supplied application password against a user's stored passwords.
 *
 * Normalizes first (see agentshell_normalize_app_password), then compares
 * with WordPress's own verifier. Returns false for an empty password so a
 * blank credential can never match.
 *
 * @param string $password  Raw password as supplied by the client.
 * @param array  $stored    Array of application password records.
 * @return bool
 */
function agentshell_check_app_password( $password, $stored ) {
    if ( ! is_array( $stored ) ) { return false; }
    $normalized = agentshell_normalize_app_password( $password );
    if ( '' === $normalized ) { return false; }
    foreach ( $stored as $item ) {
        if ( isset( $item['password'] ) && WP_Application_Passwords::check_password( $normalized, $item['password'] ) ) {
            return true;
        }
    }
    return false;
}

/**
 * Authenticate REST requests via X-AgentShell-Token header.
 *
 * Supports two methods:
 * 1. Static token: X-AgentShell-Token: <token>
 *    - Set AGENTSHELL_REST_TOKEN in wp-config.php (the secret).
 *    - Set AGENTSHELL_REST_TOKEN_USER_ID to the explicit actor id (e.g. a
 *      dedicated "agent" account) that the token resolves to. The token
 *      no longer silently becomes database user ID 1 — the configured
 *      actor must exist and authentication fails closed if it does not.
 *    - Simplest for headless / programmatic clients.
 *
 * 2. Basic Auth (WP Application Passwords):
 *    - Authorization: Basic <base64(username:app_password)>
 *    - Standard WP REST auth method
 *
 * Note: X-WP-Nonce (cookie auth) is NOT supported for headless clients
 * because WP 5.7+ changed wp_verify_nonce() to use wp_validate_auth_cookie()
 * which requires session cookies that headless clients don't send.
 *
 * @param WP_Error|null $errors
 * @return WP_Error|null
 */
add_filter( 'rest_authentication_errors', function( $errors ) {
    // If another auth method already set an error, don't override
    if ( is_wp_error( $errors ) && $errors->get_error_code() ) {
        return $errors;
    }

    // Method 1: Static token auth — fail closed.
    //
    // Required configuration:
    //   AGENTSHELL_REST_TOKEN          the shared secret
    //   AGENTSHELL_REST_TOKEN_USER_ID  the explicit actor id
    //
    // If only the secret is defined (legacy setups) we refuse auth
    // loudly; the configured actor must be explicit going forward. We
    // do not silently fall back to user 1 — that path was a privilege
    // escalation hazard (any operator who knew the token became the
    // site's admin by definition).
    $static_token = defined( 'AGENTSHELL_REST_TOKEN' ) ? AGENTSHELL_REST_TOKEN : '';
    $provided     = isset( $_SERVER['HTTP_X_AGENTSHELL_TOKEN'] )
        ? $_SERVER['HTTP_X_AGENTSHELL_TOKEN']
        : ( isset( $_REQUEST['_agent_token'] ) ? $_REQUEST['_agent_token'] : '' );

    if ( $provided && ! $static_token ) {
        error_log( 'AgentShell: static token provided but AGENTSHELL_REST_TOKEN is not defined in wp-config.php; ignoring.' );
    }

    if ( $provided && $static_token ) {
        $configured_user_id = defined( 'AGENTSHELL_REST_TOKEN_USER_ID' )
            ? (int) AGENTSHELL_REST_TOKEN_USER_ID
            : 0;

        if ( $configured_user_id <= 0 ) {
            error_log( 'AgentShell: AGENTSHELL_REST_TOKEN is configured but AGENTSHELL_REST_TOKEN_USER_ID is not; refusing auth. Set the user id in wp-config.php to bind the token to an explicit WordPress actor.' );
        } else {
            $user_exists = (bool) get_user_by( 'ID', $configured_user_id );
            $user_id     = agentshell_check_static_token(
                $provided,
                $static_token,
                $configured_user_id,
                $user_exists
            );
            if ( $user_id !== false ) {
                wp_set_current_user( $user_id );
                return null;
            }
            if ( ! $user_exists ) {
                error_log( "AgentShell: AGENTSHELL_REST_TOKEN_USER_ID={$configured_user_id} does not exist; refusing auth." );
            }
        }
    }

    // Method 2: Basic Auth (Application Passwords)
    // Supports both Authorization: Basic header and X-App-Password header.
    $app_password = isset( $_SERVER['HTTP_X_APP_PASSWORD'] ) ? $_SERVER['HTTP_X_APP_PASSWORD'] : '';
    $auth_header  = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? $_SERVER['HTTP_AUTHORIZATION'] : '';

    if ( $auth_header && str_starts_with( strtolower( $auth_header ), 'basic ' ) ) {
        $creds = base64_decode( substr( $auth_header, 6 ), true );
        if ( $creds && str_contains( $creds, ':' ) ) {
            $parts       = explode( ':', $creds, 2 );
            $username    = trim( $parts[0] );
            $app_password = $parts[1];
        }
    }

    if ( ! empty( $app_password ) ) {
        $user = get_user_by( 'slug', $username ?? '' )
              ?: get_user_by( 'login', $username ?? '' )
              ?: get_user_by( 'email', $username ?? '' );
        if ( $user ) {
            $stored = WP_Application_Passwords::get_user_application_passwords( $user->ID );
            // Shared by the Basic-auth and X-App-Password branches. Core does
            // not normalize application passwords itself (its own caller in
            // wp_authenticate_application_password does), so this layer has
            // to. Note the X-App-Password branch still cannot identify a user
            // on its own — $username is only populated by the Basic branch
            // above, which also overwrites $app_password. Documented as a
            // known limitation; Basic auth is the supported path.
            if ( agentshell_check_app_password( $app_password, $stored ) ) {
                wp_set_current_user( $user->ID );
                return null; // Auth succeeded
            }
        }
    }

    return $errors; // No recognized auth — let WP's defaults handle it
}, 1 );

/**
 * REST auth callback — validates X-AgentShell-Token or Basic Auth (App Password),
 * then enforces a capability boundary.
 *
 * The capability check is the security boundary: it ensures that any caller
 * (cookie auth, app password, static token, MCP transport) who reaches the
 * PUT /wp/v2/agentshell/config or related endpoints actually holds the
 * WordPress capability to manage theme settings. Without this, any logged-in
 * user — including subscriber-level accounts — could persist arbitrary
 * custom_js / custom_css / raw content via the configurator and the posts
 * REST endpoint. See tests/security-test.php for the privilege matrix.
 *
 * @param WP_REST_Request $request
 * @return true|WP_Error
 */
function agentshell_rest_auth_callback( WP_REST_Request $request ) {
    if ( ! get_current_user_id() ) {
        return new WP_Error(
            'rest_not_logged_in',
            'Authentication required. Provide X-AgentShell-Token header or Basic Auth.',
            array( 'status' => 401 )
        );
    }

    if ( ! current_user_can( 'edit_theme_options' ) ) {
        return new WP_Error(
            'rest_forbidden',
            'Editing AgentShell configuration requires the edit_theme_options capability.',
            array( 'status' => 403 )
        );
    }

    return true;
}

/**
 * Flatten nested config to CSS-variable key space for configurator form.
 * e.g. { design: { colors: { bg: "#fff" } } } → { "--theme-bg": "#fff" }
 *
 * Wildcard: any key starting with -- is a CSS variable, written directly —
 * agents can add custom CSS vars without touching path mapping.
 *
 * @param array $config
 * @return array
 */
function agentshell_flatten_config( array $config ) {
    $flat = array();

    // Wildcard: any key starting with -- is a CSS variable, write directly
    array_walk_recursive( $config, function( $value, $key ) use ( &$flat ) {
        if ( strpos( $key, '--' ) === 0 && is_string( $value ) ) {
            $flat[ $key ] = $value;
        }
    } );

    // Legacy map: still include these so older configs flatten correctly
    $map = array(
        '--theme-bg'        => array( 'design', 'colors', 'background' ),
        '--theme-surface'   => array( 'design', 'colors', 'surface' ),
        '--theme-text'      => array( 'design', 'colors', 'text' ),
        '--theme-accent'    => array( 'design', 'colors', 'accent' ),
        '--theme-border'    => array( 'design', 'colors', 'border' ),
        '--theme-header-bg' => array( 'design', 'colors', 'primary' ),
        '--theme-footer-bg' => array( 'design', 'colors', 'secondary' ),
        '--font-base'       => array( 'design', 'typography', 'fontFamily' ),
        '--font-mono'       => array( 'design', 'typography', 'mono' ),
        '--spacing-base'    => array( 'design', 'typography', 'baseSize' ),
        '--radius-base'     => array( 'design', 'layout', 'radius' ),
        '--zone-header-height' => array( 'design', 'layout', 'headerHeight' ),
        '--zone-footer-height' => array( 'design', 'layout', 'footerHeight' ),
    );

    foreach ( $map as $var => $path ) {
        $val = $config;
        foreach ( $path as $k ) {
            $val = is_array( $val ) ? ( $val[ $k ] ?? null ) : null;
            if ( $val === null ) break;
        }
        // Wildcard pass above already wrote the value from design.custom_css_vars[$var]
        // if the user set it via agentshell_set_css_var. Skip the legacy overwrite so
        // an explicit set_css_var sticks; the user can clear it again by writing the
        // legacy value through agentshell_set_palette (which writes design.colors.*).
        // array_key_exists (not isset) preserves the "user wrote empty string" case —
        // a zero-length string is still an explicit override.
        if ( $val !== null && ! array_key_exists( $var, $flat ) ) {
            $flat[ $var ] = $val;
        }
    }

    return $flat;
}

/**
 * Expand flat CSS-variable keys back into nested config structure,
 * then merge into the existing stored config so no keys are lost.
 *
 * Wildcard: any key starting with -- that's not in the legacy map is
 * written directly to design.custom_css_vars so agents can add custom vars.
 *
 * @param array $flat   Flat key-value pairs from configurator
 * @param array $existing Existing stored config to merge into
 * @return array Merged nested config
 */
function agentshell_unflatten_config( array $flat, array $existing ) {
    $css_to_path = array(
        '--theme-bg'        => array( 'design', 'colors', 'background' ),
        '--theme-surface'   => array( 'design', 'colors', 'surface' ),
        '--theme-text'      => array( 'design', 'colors', 'text' ),
        '--theme-accent'    => array( 'design', 'colors', 'accent' ),
        '--theme-border'    => array( 'design', 'colors', 'border' ),
        '--theme-header-bg' => array( 'design', 'colors', 'primary' ),
        '--theme-footer-bg' => array( 'design', 'colors', 'secondary' ),
        '--font-base'       => array( 'design', 'typography', 'fontFamily' ),
        '--font-mono'       => array( 'design', 'typography', 'mono' ),
        '--spacing-base'    => array( 'design', 'typography', 'baseSize' ),
        '--radius-base'     => array( 'design', 'layout', 'radius' ),
        '--zone-header-height' => array( 'design', 'layout', 'headerHeight' ),
        '--zone-footer-height' => array( 'design', 'layout', 'footerHeight' ),
    );

    // Deep-merge $flat into a copy of $existing
    $merged = $existing;
    foreach ( $flat as $key => $value ) {
        if ( $key === 'custom_css' ) {
            $merged['custom_css'] = is_string( $value ) ? $value : '';
            continue;
        }
        if ( $key === 'custom_js' ) {
            $merged['custom_js'] = is_string( $value ) ? $value : '';
            continue;
        }
        if ( $key === 'zones' ) {
            $merged['zones'] = is_array( $value ) ? $value : array();
            continue;
        }
        if ( $key === 'widgets' ) {
            $merged['widgets'] = is_array( $value ) ? $value : array();
            continue;
        }
        if ( $key === 'layout' ) {
            $merged['layout'] = is_array( $value ) ? $value : array();
            continue;
        }
        if ( isset( $css_to_path[ $key ] ) ) {
            $path = $css_to_path[ $key ];
            $ref  = &$merged;
            foreach ( $path as $i => $k ) {
                if ( $i === count( $path ) - 1 ) {
                    $ref[ $k ] = is_string( $value ) ? $value : $ref[ $k ];
                } else {
                    if ( ! isset( $ref[ $k ] ) || ! is_array( $ref[ $k ] ) ) {
                        $ref[ $k ] = array();
                    }
                    $ref = &$ref[ $k ];
                }
            }
            unset( $ref );
        } elseif ( strpos( $key, '--' ) === 0 ) {
            // Wildcard: any --key not in legacy map goes to custom_css_vars
            if ( ! isset( $merged['design'] ) ) {
                $merged['design'] = array();
            }
            if ( ! isset( $merged['design']['custom_css_vars'] ) ) {
                $merged['design']['custom_css_vars'] = array();
            }
            $merged['design']['custom_css_vars'][ $key ] = $value;
        }
    }

    return $merged;
}

/**
 * Validate config structure against allowed schema.
 * Returns WP_Error on failure, validated config array on success.
 *
 * @param array $config
 * @return array|WP_Error
 */
function agentshell_validate_config( $config ) {
    $allowed_colors = array(
        'background', 'surface', 'text', 'border', 'accent', 'primary', 'secondary',
    );
    $allowed_typography = array(
        'fontFamily', 'mono', 'baseSize', 'scale',
    );
    $allowed_layout = array(
        'radius', 'headerHeight', 'footerHeight',
    );
    $allowed_zone_ids = array( 'header', 'main', 'footer' );
    $allowed_block_types = array(
        'wp_loop', 'wp_core', 'widget', 'json_block', 'wp_widget_area',
    );
    $allowed_wp_core_ids = array(
        'site_title', 'nav_menu', 'search_form', 'site_logo', 'site_tagline',
    );

    if ( ! is_array( $config ) ) {
        return new WP_Error( 'invalid_config', 'Config must be an array.' );
    }

    if ( isset( $config['design']['colors'] ) ) {
        foreach ( $config['design']['colors'] as $key => $val ) {
            if ( ! in_array( $key, $allowed_colors, true ) ) {
                return new WP_Error( 'invalid_color_key', "Unknown color key: {$key}" );
            }
            if ( ! is_string( $val ) || ! preg_match( '/^#[0-9a-fA-F]{3,8}$/', $val ) ) {
                return new WP_Error( 'invalid_color_value', "Invalid color value for {$key}: {$val}" );
            }
        }
    }

    if ( isset( $config['design']['typography'] ) ) {
        foreach ( $config['design']['typography'] as $key => $val ) {
            if ( ! in_array( $key, $allowed_typography, true ) ) {
                return new WP_Error( 'invalid_typography_key', "Unknown typography key: {$key}" );
            }
        }
    }

    if ( isset( $config['design']['layout'] ) ) {
        foreach ( $config['design']['layout'] as $key => $val ) {
            if ( ! in_array( $key, $allowed_layout, true ) ) {
                return new WP_Error( 'invalid_layout_key', "Unknown layout key: {$key}" );
            }
        }
    }

    if ( isset( $config['zones'] ) && is_array( $config['zones'] ) ) {
        foreach ( $config['zones'] as $zone ) {
            if ( ! isset( $zone['id'] ) || ! in_array( $zone['id'], $allowed_zone_ids, true ) ) {
                return new WP_Error( 'invalid_zone_id', "Unknown or missing zone id: " . ( $zone['id'] ?? 'null' ) );
            }
            if ( isset( $zone['composition'] ) && is_array( $zone['composition'] ) ) {
                foreach ( $zone['composition'] as $block ) {
                    if ( ! isset( $block['type'] ) || ! in_array( $block['type'], $allowed_block_types, true ) ) {
                        return new WP_Error( 'invalid_block_type', "Unknown block type: " . ( $block['type'] ?? 'null' ) );
                    }
                    if ( $block['type'] === 'wp_core' && isset( $block['id'] ) && ! in_array( $block['id'], $allowed_wp_core_ids, true ) ) {
                        return new WP_Error( 'invalid_wp_core_id', "Unknown wp_core id: {$block['id']}" );
                    }
                }
            }
            if ( isset( $zone['slots'] ) && is_array( $zone['slots'] ) ) {
                foreach ( array( 'left', 'center', 'right' ) as $slot ) {
                    if ( isset( $zone['slots'][ $slot ] ) && is_array( $zone['slots'][ $slot ] ) ) {
                        foreach ( $zone['slots'][ $slot ] as $block ) {
                            if ( ! isset( $block['type'] ) || ! in_array( $block['type'], $allowed_block_types, true ) ) {
                                return new WP_Error( 'invalid_slot_block_type', "Unknown slot block type: " . ( $block['type'] ?? 'null' ) );
                            }
                            if ( $block['type'] === 'wp_core' && isset( $block['id'] ) && ! in_array( $block['id'], $allowed_wp_core_ids, true ) ) {
                                return new WP_Error( 'invalid_wp_core_id', "Unknown wp_core id: {$block['id']}" );
                            }
                        }
                    }
                }
            }
        }
    }

    if ( isset( $config['layout'] ) && ! is_array( $config['layout'] ) ) {
        return new WP_Error( 'invalid_layout', 'Layout must be an array.' );
    }

    // Top-level config['layout'] is the configurable grid system surfaced
    // by agentshell_set_layout. Allowlist the four known keys so a typo
    // or future-incompatible payload fails explicitly rather than silently
    // landing in storage and never being read.
    if ( isset( $config['layout'] ) && is_array( $config['layout'] ) ) {
        $allowed_layout_top = array( 'breakpoints', 'grid_areas', 'grid_gap', 'grid_padding' );
        foreach ( $config['layout'] as $key => $val ) {
            if ( ! in_array( $key, $allowed_layout_top, true ) ) {
                return new WP_Error( 'invalid_layout_key', "Unknown layout key: {$key}" );
            }
        }
    }

    if ( isset( $config['custom_css'] ) && ! is_string( $config['custom_css'] ) ) {
        return new WP_Error( 'invalid_custom_css', 'custom_css must be a string.' );
    }

    if ( isset( $config['custom_css'] ) && strlen( $config['custom_css'] ) > 10000 ) {
        return new WP_Error( 'custom_css_too_large', 'custom_css exceeds 10KB.' );
    }

    if ( isset( $config['custom_js'] ) && ! is_string( $config['custom_js'] ) ) {
        return new WP_Error( 'invalid_custom_js', 'custom_js must be a string.' );
    }

    if ( isset( $config['custom_js'] ) && strlen( $config['custom_js'] ) > 10000 ) {
        return new WP_Error( 'custom_js_too_large', 'custom_js exceeds 10KB.' );
    }

    return $config;
}

/**
 * Sanitise a custom_js value for safe embedding inside a <script> tag.
 *
 * Trusted administrators intentionally write arbitrary JavaScript into
 * custom_js; we preserve the bytes verbatim. The only hardening applied
 * is preventing a literal </script> (or </SCRIPT>, etc.) from prematurely
 * closing the wrapping <script> element and breaking out into a sibling
 * tag. The standard mitigation is to rewrite </ as <\/ — JavaScript treats
 * the two sequences as identical but the browser does not, so the wrapping
 * element stays intact.
 */
function agentshell_harden_custom_js( $value ) {
    if ( ! is_string( $value ) ) { return ''; }
    return preg_replace( '/<\//', '<\\/', $value );
}

/**
 * Sanitise a custom_css value for safe embedding inside a <style> tag.
 *
 * Same rationale as agentshell_harden_custom_js: trusted authors write
 * arbitrary CSS, we only prevent a literal </style> from closing the
 * wrapping <style> element early.
 */
function agentshell_harden_custom_css( $value ) {
    if ( ! is_string( $value ) ) { return ''; }
    return preg_replace( '/<\//', '<\\/', $value );
}

/**
 * Build the response payload for GET /wp/v2/agentshell/config.
 *
 * Extracted from the rest_api_init closure so it can be unit-tested
 * without spinning up a full WP REST stack. The fix for the
 * custom_css round-trip defect lives here: prior to the fix, the GET
 * response omitted custom_css and custom_js, so an agent reading the
 * current config via GET could not see what was stored, and an
 * agent updating only some other field (e.g. zones) had no way to
 * confirm its custom_css was not silently lost. Surfacing the
 * strings plus a byte counter closes that gap.
 *
 * Layout is also surfaced: the agentshell_set_layout tool writes to
 * config['layout'] and the agent may want to read the stored value
 * back (e.g. to verify a tool call landed before re-emitting CSS).
 */
function agentshell_build_config_get_response() {
    $config = agentshell_get_config();
    $flat   = agentshell_flatten_config( $config );

    // Pull widgets from the merged bilateral registry, not the
    // legacy agentshell_widgets option. This means the configurator
    // sees widgets written via agentshell_register_widget (main
    // plugin) as well as widgets written via the blocks plugin's
    // legacy tool. Migration is handled by
    // agentshell_migrate_legacy_widgets_option() on plugin activation.
    $available_widgets = array();
    $registry          = agentshell_get_widget_registry();
    foreach ( $registry as $widget ) {
        $available_widgets[] = array(
            'id'     => $widget['id'],
            'name'   => $widget['name'] ?? $widget['id'],
            'source' => $widget['source'] ?? 'config',
            'status' => $widget['status'] ?? 'active',
        );
    }

    // Core WP components — now served by the same function used at render time
    $core_components = agentshell_get_core_components();

    // Available widget areas — snapshot of $wp_registered_sidebars at
    // request time. Surfaces child-theme and mu-plugin-registered
    // areas alongside AgentShell's defaults so the configurator
    // can present a single dropdown. The configurator preserves
    // saved ids even when the area is no longer registered.
    //
    // Read the global directly: WP core exposes the global but
    // older releases (e.g. 6.5) do not export a wrapper function.
    // Newer releases add wp_registered_sidebars() — fall through
    // to that if present.
    $available_widget_areas = array();
    $registered = ( function_exists( 'wp_registered_sidebars' ) )
        ? wp_registered_sidebars()
        : ( $GLOBALS['wp_registered_sidebars'] ?? array() );
    if ( ! is_array( $registered ) ) {
        $registered = array();
    }
    foreach ( $registered as $area_id => $area ) {
        if ( ! is_string( $area_id ) ) {
            continue;
        }
        $available_widget_areas[] = array(
            'id'   => $area_id,
            'name' => ( is_array( $area ) && isset( $area['name'] ) ) ? (string) $area['name'] : $area_id,
        );
    }

    $schema = array(
        'zones'           => array(
            'type'  => 'array',
            'items' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'          => array( 'type' => 'string' ),
                    'label'       => array( 'type' => 'string' ),
                    'composition' => array( 'type' => 'array' ),
                    'slots'      => array(
                        'type'       => 'object',
                        'properties' => array(
                            'left'   => array( 'type' => 'array' ),
                            'center' => array( 'type' => 'array' ),
                            'right'  => array( 'type' => 'array' ),
                        ),
                    ),
                ),
            ),
        ),
        'custom_css' => array( 'type' => 'string', 'maxLength' => 10000 ),
        'custom_js'  => array( 'type' => 'string', 'maxLength' => 10000 ),
        'design'     => array( 'type' => 'object' ),
        'layout'     => array( 'type' => 'object' ),
    );

    // Ensure zones and compositions are always proper sequential arrays
    $zones = $config['zones'] ?? array();
    if ( is_array( $zones ) ) {
        $zones = array_values( $zones );
        foreach ( $zones as &$zone ) {
            if ( isset( $zone['composition'] ) && is_array( $zone['composition'] ) ) {
                $zone['composition'] = array_values( $zone['composition'] );
            }
            // Normalize slot arrays to sequential arrays
            if ( isset( $zone['slots'] ) && is_array( $zone['slots'] ) ) {
                foreach ( array( 'left', 'center', 'right' ) as $slot_key ) {
                    if ( isset( $zone['slots'][ $slot_key ] ) && is_array( $zone['slots'][ $slot_key ] ) ) {
                        $zone['slots'][ $slot_key ] = array_values( $zone['slots'][ $slot_key ] );
                    } else {
                        $zone['slots'][ $slot_key ] = array();
                    }
                }
            }
        }
        unset( $zone );
    } else {
        $zones = array();
    }

    $custom_css = (string) ( $config['custom_css'] ?? '' );
    $custom_js  = (string) ( $config['custom_js']  ?? '' );

    return array(
        'schema'                => $schema,
        'defaults'              => $flat,
        'config'                => $flat,
        'zones'                 => $zones,
        'available_widgets'     => $available_widgets,
        'core_components'       => $core_components,
        'available_widget_areas'=> $available_widget_areas,
        'custom_css'            => $custom_css,
        'custom_js'             => $custom_js,
        'custom_css_bytes'      => strlen( $custom_css ),
        'custom_js_bytes'       => strlen( $custom_js ),
        'layout'                => is_array( $config['layout'] ?? null ) ? $config['layout'] : array(),
    );
}

/**
 * REST API: GET/PUT /wp/v2/agentshell/config
 * GET returns schema metadata + defaults + config for agent introspection.
 * PUT accepts flat key-value pairs, merges into nested config, persists.
 */
add_action( 'rest_api_init', function() {
    register_rest_route( 'wp/v2', '/agentshell/config', array(
        'methods'  => 'GET',
        'callback' => 'agentshell_build_config_get_response',
        'permission_callback' => 'agentshell_rest_auth_callback',
    ) );

    register_rest_route( 'wp/v2', '/agentshell/config', array(
        'methods'  => 'PUT',
        'callback' => function( WP_REST_Request $request ) {
            $flat = $request->get_json_params();
            if ( ! is_array( $flat ) ) {
                return new WP_Error( 'invalid_config', 'Config must be a valid JSON object', array( 'status' => 400 ) );
            }
            $existing = agentshell_get_config();
            $merged   = agentshell_unflatten_config( $flat, $existing );
            $validated = agentshell_validate_config( $merged );
            if ( is_wp_error( $validated ) ) {
                return $validated;
            }
            update_option( 'agentshell_config', $validated );
            return agentshell_flatten_config( $validated );
        },
        'permission_callback' => 'agentshell_rest_auth_callback',
    ) );

    // Preserve raw content format for agents — prevents WordPress from
    // auto-formatting when content is saved via REST API.
    // Agents should send { "content": { "raw": "..." } }
    //
    // Security boundary: the sanitization bypass is gated on unfiltered_html.
    // Without this, any logged-in user who can reach /wp/v2/posts/{id} can
    // persist arbitrary HTML/JS into wp_posts.post_content (XSS sink via
    // the_content()). The standard WordPress capability for "may write raw
    // HTML without KSES" is unfiltered_html, granted by default only to
    // administrators and multisite super admins.
    add_filter( 'rest_pre_insert_post', function( $post, $request ) {
        $content = $request->get_param( 'content' );
        if ( is_array( $content ) && isset( $content['raw'] ) && is_string( $content['raw'] ) ) {
            if ( ! current_user_can( 'unfiltered_html' ) ) {
                return $post; // trusted-author only; standard KSES remains in effect
            }
            // Remove all content filtering hooks for raw agent content
            remove_filter( 'content_save_pre', 'wp_filter_post_kses', 10 );
            remove_filter( 'content_save_pre', 'wp_filter_kses', 10 );
            remove_filter( 'content_save_pre', 'balanceTags', 10 );
            remove_filter( 'content_save_pre', 'wpautop', 10 );
            remove_filter( 'content_save_pre', 'wptexturize', 10 );
            remove_filter( 'content_save_pre', 'convert_smilies', 10 );
            remove_filter( 'content_save_pre', 'convert_chars', 10 );
            remove_filter( 'content_save_pre', 'wp_slash', 10 );
            remove_filter( 'content_save_pre', 'force_balance_tags', 10 );

            // Directly set post_content to raw value, bypassing sanitization
            $post['post_content'] = $content['raw'];
        }
        return $post;
    }, 10, 2 );

    add_filter( 'rest_pre_update_post', function( $post, $request ) {
        $content = $request->get_param( 'content' );
        if ( is_array( $content ) && isset( $content['raw'] ) && is_string( $content['raw'] ) ) {
            if ( ! current_user_can( 'unfiltered_html' ) ) {
                return $post; // trusted-author only; standard KSES remains in effect
            }
            remove_filter( 'content_save_pre', 'wp_filter_post_kses', 10 );
            remove_filter( 'content_save_pre', 'wp_filter_kses', 10 );
            remove_filter( 'content_save_pre', 'balanceTags', 10 );
            remove_filter( 'content_save_pre', 'wpautop', 10 );
            remove_filter( 'content_save_pre', 'wptexturize', 10 );
            remove_filter( 'content_save_pre', 'convert_smilies', 10 );
            remove_filter( 'content_save_pre', 'convert_chars', 10 );
            remove_filter( 'content_save_pre', 'wp_slash', 10 );
            remove_filter( 'content_save_pre', 'force_balance_tags', 10 );

            $post['post_content'] = $content['raw'];
        }
        return $post;
    }, 10, 2 );

    // ── Configurator v2 phase 1: transaction + snapshot REST endpoints ──
    //
    // These mirror the agentshell_begin_transaction / commit / rollback
    // MCP tools but in REST form for the browser configurator. The
    // capability gate is agentshell_rest_auth_callback (edit_theme_options).
    // The transaction manager itself enforces per-actor ownership; the
    // configurator never takes over a foreign transaction.
    register_rest_route( 'wp/v2', '/agentshell/transaction', array(
        'methods'             => 'GET',
        'callback'            => function() {
            $tx = \AgentShell_MCP\Transaction_Manager::instance()->payload();
            if ( ! is_array( $tx ) ) {
                return array(
                    'open'          => false,
                    'id'            => null,
                    'actor'         => null,
                    'label'         => null,
                    'owned_by_us'   => false,
                    'started_at'    => null,
                    'has_staged'    => false,
                    'can_take_over' => true,
                );
            }
            $actor    = \AgentShell_MCP\Store::actor();
            $staged   = \AgentShell_MCP\Transaction_Manager::instance()->staged_config();
            return array(
                'open'        => true,
                'id'          => $tx['id']        ?? null,
                'actor'       => $tx['actor']     ?? null,
                'label'       => $tx['label']     ?? null,
                'owned_by_us' => ( $tx['actor'] ?? null ) === $actor,
                'started_at'  => isset( $tx['timestamp'] ) ? (int) $tx['timestamp'] : null,
                'has_staged'  => is_array( $staged ) && ! empty( $staged ),
                'can_take_over' => false,
            );
        },
        'permission_callback' => 'agentshell_rest_auth_callback',
    ) );

    register_rest_route( 'wp/v2', '/agentshell/transaction', array(
        'methods'             => 'POST',
        'callback'            => function( WP_REST_Request $request ) {
            $tx = \AgentShell_MCP\Transaction_Manager::instance();
            // Refuse to take over a foreign transaction.
            if ( $tx->is_active() && ( $tx->payload()['actor'] ?? null ) !== \AgentShell_MCP\Store::actor() ) {
                return new WP_Error(
                    'transaction_locked_by_other',
                    'Another actor owns the open transaction; the configurator cannot take it over.',
                    array( 'status' => 409 )
                );
            }
            try {
                $opened = $tx->begin( (string) $request->get_param( 'label' ) );
            } catch ( \Throwable $e ) {
                return new WP_Error( 'transaction_begin_failed', $e->getMessage(), array( 'status' => 500 ) );
            }
            return array( 'id' => $opened['id'], 'label' => $opened['label'] );
        },
        'permission_callback' => 'agentshell_rest_auth_callback',
    ) );

    register_rest_route( 'wp/v2', '/agentshell/transaction', array(
        'methods'             => 'DELETE',
        'callback'            => function() {
            try {
                \AgentShell_MCP\Transaction_Manager::instance()->rollback();
                return array( 'rolled_back' => true );
            } catch ( \Throwable $e ) {
                return new WP_Error( 'transaction_rollback_failed', $e->getMessage(), array( 'status' => 500 ) );
            }
        },
        'permission_callback' => 'agentshell_rest_auth_callback',
    ) );

    register_rest_route( 'wp/v2', '/agentshell/transaction/commit', array(
        'methods'             => 'POST',
        'callback'            => function() {
            try {
                $result = \AgentShell_MCP\Transaction_Manager::instance()->commit();
                return array( 'committed' => true, 'id' => $result['id'] ?? null );
            } catch ( \Throwable $e ) {
                return new WP_Error( 'transaction_commit_failed', $e->getMessage(), array( 'status' => 500 ) );
            }
        },
        'permission_callback' => 'agentshell_rest_auth_callback',
    ) );

    // Snapshot — create on demand. Reuses Store::create_snapshot
    // which is the same path the MCP toolshell_create_snapshot uses.
    register_rest_route( 'wp/v2', '/agentshell/snapshot', array(
        'methods'             => 'POST',
        'callback'            => function( WP_REST_Request $request ) {
            $name  = sanitize_key( (string) $request->get_param( 'name' ) );
            $label = sanitize_text_field( (string) $request->get_param( 'label' ) );
            if ( $name === '' ) {
                return new WP_Error( 'snapshot_name_required', 'name is required', array( 'status' => 400 ) );
            }
            try {
                $snap = \AgentShell_MCP\Store::create_snapshot( $name, $label );
                return array(
                    'name'      => $snap['name']      ?? $name,
                    'label'     => $snap['label']     ?? $label,
                    'timestamp' => $snap['timestamp'] ?? time(),
                );
            } catch ( \Throwable $e ) {
                return new WP_Error( 'snapshot_failed', $e->getMessage(), array( 'status' => 500 ) );
            }
        },
        'permission_callback' => 'agentshell_rest_auth_callback',
    ) );
} );
