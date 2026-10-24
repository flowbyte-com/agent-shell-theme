<?php
/**
 * The browser request policy: what a capture is allowed to ask for.
 *
 * The defect this pins
 * --------------------
 * `agentshell_screenshot` checked that the *initial* URL was on the site's
 * own host, and then let the browser do as it liked: redirects, JavaScript
 * navigations, frames and subresources all went unchecked, and the content
 * of another host could end up in the image. Reading the page's final URL
 * afterwards does not close that, because the request has already been made
 * by then.
 *
 * The policy that replaces it is decided **before** the request is sent,
 * and it is decided per *origin* — scheme, host and port — because a host
 * alone cannot tell the site's own `127.0.0.1:8080` from another service
 * on `127.0.0.1:8099`.
 *
 * What is asserted here
 * ---------------------
 * 1. The site's own origin is permitted, and the http↔https upgrade of it
 *    is too (a site that redirects one to the other must stay capturable).
 * 2. Every other destination is refused, and the refusal says what kind of
 *    destination it was: loopback, private, link-local, cloud metadata,
 *    another host.
 * 3. IPv4 and IPv6 loopback are two different hosts, and an IPv4-mapped
 *    IPv6 address is the IPv4 address it names.
 * 4. The same host on a different port is refused.
 * 5. A URL whose host is disguised — userinfo, a backslash, a trailing
 *    dot, mixed case — is decided on the host the *browser* would see.
 * 6. `data:`, `blob:`, `about:` and `javascript:` are not network
 *    requests and are permitted.
 * 7. The resolver rules name every permitted host and nothing else.
 * 8. A reported destination never carries a path or a query, because that
 *    is where a preview credential would be.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-browser-request-policy.php';

use AgentShell_MCP\Browser_Request_Policy;

echo "browser request policy tests\n";

/**
 * A policy for a site at http://site.test (port 80).
 *
 * @return Browser_Request_Policy
 */
function brp_policy() {
    return new Browser_Request_Policy(
        array(
            array( 'scheme' => 'http', 'host' => 'site.test', 'port' => 80, 'raw' => 'http://site.test:80' ),
        ),
        array()
    );
}

/**
 * A policy for a site served from a non-default port.
 *
 * @return Browser_Request_Policy
 */
function brp_port_policy() {
    return new Browser_Request_Policy(
        array(
            array( 'scheme' => 'http', 'host' => '127.0.0.1', 'port' => 8080, 'raw' => 'http://127.0.0.1:8080' ),
        ),
        array()
    );
}

Agentshell_Test::run(
    'the site\'s own origin is permitted',
    function () {
        $policy = brp_policy();
        $decision = $policy->evaluate( 'http://site.test/hello' );
        Agentshell_Test::assert_true( $decision['allow'], 'the site\'s own page must be permitted' );
        Agentshell_Test::assert_equals( 'same_origin', $decision['category'] );
        Agentshell_Test::assert_equals( 'site.test', $decision['host'] );
    }
);

Agentshell_Test::run(
    'a query string on the site\'s own origin does not change the decision',
    function () {
        $policy = brp_policy();
        $decision = $policy->evaluate( 'http://site.test/?agentshell_preview_key=secret' );
        Agentshell_Test::assert_true( $decision['allow'], 'a preview credential rides in the query and must still work' );
    }
);

Agentshell_Test::run(
    'the http to https upgrade of the site\'s own origin is permitted',
    function () {
        $policy = brp_policy();
        Agentshell_Test::assert_true(
            $policy->evaluate( 'https://site.test/' )['allow'],
            'a site that redirects http to https must stay capturable'
        );
    }
);

Agentshell_Test::run(
    'the same host on a different port is refused',
    function () {
        $policy = brp_port_policy();
        $decision = $policy->evaluate( 'http://127.0.0.1:8099/secret.php' );
        Agentshell_Test::assert_false(
            $decision['allow'],
            'a second service on the same host is a different origin and must be refused'
        );
        Agentshell_Test::assert_equals( 'loopback', $decision['category'] );
    }
);

Agentshell_Test::run(
    'a loopback destination is refused and named as one',
    function () {
        $policy = brp_policy();
        $decision = $policy->evaluate( 'http://127.0.0.1:8099/secret.php' );
        Agentshell_Test::assert_false( $decision['allow'] );
        Agentshell_Test::assert_equals( 'loopback', $decision['category'] );
        Agentshell_Test::assert_true(
            false !== strpos( $decision['reason'], 'loopback' ),
            'the refusal must say the destination was a loopback address: ' . $decision['reason']
        );
    }
);

Agentshell_Test::run(
    'the IPv6 loopback is a different host from the IPv4 loopback',
    function () {
        $policy = brp_policy();
        $decision = $policy->evaluate( 'http://[::1]:8099/secret.php' );
        Agentshell_Test::assert_false( $decision['allow'], 'the IPv6 loopback must be refused' );
        Agentshell_Test::assert_equals( 'loopback', $decision['category'] );
        Agentshell_Test::assert_equals( '::1', $decision['host'] );
    }
);

Agentshell_Test::run(
    'an IPv4-mapped IPv6 address is the IPv4 address it names',
    function () {
        $policy = brp_policy();
        $decision = $policy->evaluate( 'http://[::ffff:127.0.0.1]:8099/secret.php' );
        Agentshell_Test::assert_false( $decision['allow'] );
        Agentshell_Test::assert_equals( '127.0.0.1', $decision['host'] );
        Agentshell_Test::assert_equals( 'loopback', $decision['category'] );
    }
);

Agentshell_Test::run(
    'a private destination is refused and named as one',
    function () {
        $policy = brp_policy();
        foreach ( array( '10.0.0.5', '172.16.4.4', '192.168.1.1' ) as $host ) {
            $decision = $policy->evaluate( 'http://' . $host . '/admin' );
            Agentshell_Test::assert_false( $decision['allow'], $host . ' must be refused' );
            Agentshell_Test::assert_equals( 'private', $decision['category'], $host . ' must be classified as private' );
        }
    }
);

Agentshell_Test::run(
    'a link-local destination is refused and named as one',
    function () {
        $policy = brp_policy();
        $decision = $policy->evaluate( 'http://169.254.10.1/' );
        Agentshell_Test::assert_false( $decision['allow'] );
        Agentshell_Test::assert_equals( 'link_local', $decision['category'] );
    }
);

Agentshell_Test::run(
    'the cloud metadata address is refused and named as one',
    function () {
        $policy = brp_policy();
        $decision = $policy->evaluate( 'http://169.254.169.254/latest/meta-data/' );
        Agentshell_Test::assert_false( $decision['allow'] );
        Agentshell_Test::assert_equals( 'metadata', $decision['category'] );
    }
);

Agentshell_Test::run(
    'another host is refused and named as one',
    function () {
        $policy = brp_policy();
        $decision = $policy->evaluate( 'https://example.com/page' );
        Agentshell_Test::assert_false( $decision['allow'] );
        Agentshell_Test::assert_equals( 'cross_host', $decision['category'] );
    }
);

Agentshell_Test::run(
    'a subdomain of the site is a different host and is refused',
    function () {
        $policy = brp_policy();
        Agentshell_Test::assert_false(
            $policy->evaluate( 'https://evil.site.test/' )['allow'],
            'a name that merely ends with the site\'s name is another host'
        );
    }
);

Agentshell_Test::run(
    'userinfo cannot disguise the host',
    function () {
        $policy = brp_policy();
        $decision = $policy->evaluate( 'http://site.test@evil.test/secret' );
        Agentshell_Test::assert_false( $decision['allow'], 'the host is evil.test, not site.test' );
        Agentshell_Test::assert_equals( 'evil.test', $decision['host'] );
    }
);

Agentshell_Test::run(
    'a backslash is a forward slash, as the browser treats it',
    function () {
        $policy = brp_policy();
        // The browser reads the host of `http://site.test\@evil.test/` as
        // `site.test` — the backslash ends the authority — so the request
        // is for this site and is permitted.
        $decision = $policy->evaluate( 'http://site.test\\@evil.test/secret' );
        Agentshell_Test::assert_true(
            $decision['allow'],
            'the browser\'s host here is site.test, so this is the site\'s own origin'
        );
        Agentshell_Test::assert_equals( 'site.test', $decision['host'] );
        // The other way round, the browser's host is evil.test.
        $decision = $policy->evaluate( 'http://evil.test\\@site.test/secret' );
        Agentshell_Test::assert_false( $decision['allow'], 'the browser\'s host here is evil.test' );
        Agentshell_Test::assert_equals( 'evil.test', $decision['host'] );
    }
);

Agentshell_Test::run(
    'a trailing dot and mixed case name the same host',
    function () {
        $policy = brp_policy();
        Agentshell_Test::assert_true(
            $policy->evaluate( 'http://SITE.TEST./' )['allow'],
            'case and a trailing root dot do not make a different host'
        );
    }
);

Agentshell_Test::run(
    'a URL with no scheme or no host is refused',
    function () {
        $policy = brp_policy();
        Agentshell_Test::assert_false( $policy->evaluate( 'site.test/page' )['allow'], 'a relative URL has no host' );
        Agentshell_Test::assert_false( $policy->evaluate( 'http:///page' )['allow'], 'a URL with no host is refused' );
        Agentshell_Test::assert_false( $policy->evaluate( '' )['allow'], 'an empty URL is refused' );
    }
);

Agentshell_Test::run(
    'a scheme that is not a network scheme is refused',
    function () {
        $policy = brp_policy();
        $decision = $policy->evaluate( 'file:///etc/passwd' );
        Agentshell_Test::assert_false( $decision['allow'], 'a file URL is not a capture' );
        Agentshell_Test::assert_equals( 'unsupported_scheme', $decision['category'] );
    }
);

Agentshell_Test::run(
    'the schemes that never reach the network are permitted',
    function () {
        $policy = brp_policy();
        foreach ( array( 'data:text/html,hello', 'blob:http://site.test/abc', 'about:blank' ) as $url ) {
            $decision = $policy->evaluate( $url );
            Agentshell_Test::assert_true( $decision['allow'], $url . ' does not reach the network' );
            Agentshell_Test::assert_equals( 'not_a_network_request', $decision['category'] );
        }
    }
);

Agentshell_Test::run(
    'an operator-declared host is permitted on any port',
    function () {
        $policy = new Browser_Request_Policy(
            array( array( 'scheme' => 'http', 'host' => 'site.test', 'port' => 80, 'raw' => 'http://site.test:80' ) ),
            array( 'cdn.example.com' )
        );
        Agentshell_Test::assert_true(
            $policy->evaluate( 'https://cdn.example.com/asset.js' )['allow'],
            'a declared host is permitted'
        );
        Agentshell_Test::assert_true(
            $policy->evaluate( 'https://cdn.example.com:8443/asset.js' )['allow'],
            'a declared host is permitted on any port'
        );
        Agentshell_Test::assert_false(
            $policy->evaluate( 'https://other.example.com/asset.js' )['allow'],
            'a host that was not declared is still refused'
        );
    }
);

Agentshell_Test::run(
    'the resolver rules name the permitted hosts and refuse everything else',
    function () {
        $policy = new Browser_Request_Policy(
            array( array( 'scheme' => 'http', 'host' => 'site.test', 'port' => 80, 'raw' => 'http://site.test:80' ) ),
            array( 'cdn.example.com' )
        );
        $rules = $policy->host_resolver_rules();
        Agentshell_Test::assert_true(
            0 === strpos( $rules, 'MAP * ~NOTFOUND' ),
            'every other name must fail to resolve: ' . $rules
        );
        Agentshell_Test::assert_true(
            false !== strpos( $rules, 'EXCLUDE site.test' ),
            'the site\'s own host must be excluded: ' . $rules
        );
        Agentshell_Test::assert_true(
            false !== strpos( $rules, 'EXCLUDE cdn.example.com' ),
            'a declared host must be excluded: ' . $rules
        );
    }
);

Agentshell_Test::run(
    'a reported destination carries no path and no query',
    function () {
        $redacted = Browser_Request_Policy::redact_url( 'http://site.test/private/path?agentshell_preview_key=secret#frag' );
        Agentshell_Test::assert_false(
            false !== strpos( $redacted, 'secret' ),
            'a preview credential must never be reported: ' . $redacted
        );
        Agentshell_Test::assert_false(
            false !== strpos( $redacted, 'private' ),
            'the path must not be reported: ' . $redacted
        );
        Agentshell_Test::assert_equals( 'http://site.test/', $redacted );
    }
);

Agentshell_Test::run(
    'host classification names every category the policy cares about',
    function () {
        $cases = array(
            '127.0.0.1'      => 'loopback',
            '::1'            => 'loopback',
            '10.1.2.3'       => 'private',
            '172.20.0.1'     => 'private',
            '192.168.0.1'    => 'private',
            '169.254.1.1'    => 'link_local',
            '169.254.169.254' => 'metadata',
            '0.0.0.0'        => 'unspecified',
            '8.8.8.8'        => 'public',
            'example.com'    => 'hostname',
        );
        foreach ( $cases as $host => $expected ) {
            Agentshell_Test::assert_equals(
                $expected,
                Browser_Request_Policy::classify_host( $host ),
                $host . ' must be classified as ' . $expected
            );
        }
    }
);

Agentshell_Test::run(
    'the site policy is built from the site\'s own URLs',
    function () {
        // The bootstrap has no home_url(), so the policy is built directly
        // here to prove the constructor's shape; for_site() is exercised by
        // the live suite, where WordPress really is loaded.
        $policy = new Browser_Request_Policy(
            array(
                array( 'scheme' => 'https', 'host' => 'flowbyte.com', 'port' => 443, 'raw' => 'https://flowbyte.com:443' ),
                array( 'scheme' => 'https', 'host' => 'www.flowbyte.com', 'port' => 443, 'raw' => 'https://www.flowbyte.com:443' ),
            ),
            array()
        );
        Agentshell_Test::assert_true( $policy->evaluate( 'https://flowbyte.com/x' )['allow'] );
        Agentshell_Test::assert_true( $policy->evaluate( 'https://www.flowbyte.com/x' )['allow'] );
        Agentshell_Test::assert_false( $policy->evaluate( 'https://other.com/x' )['allow'] );
    }
);
