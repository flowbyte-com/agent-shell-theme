<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * What the capture browser is allowed to ask for.
 *
 * ## The problem this exists to solve
 *
 * `agentshell_screenshot` used to check one thing: the URL it was about to
 * open had the same host as `home_url()`. That check is about the *request
 * that was made*, not about the *responses that came back*. Chromium then
 * follows redirects, runs JavaScript, opens frames and loads subresources,
 * and none of those go through the check. A page on the site's own host
 * could therefore send the browser to another host — including
 * `http://127.0.0.1:8099/` on the same machine — and the *other* host's
 * content would end up in the image returned to the caller.
 *
 * Reading the page's final URL afterwards does not fix this. The request
 * has already been made by then. The only check that closes the hole is a
 * check that runs *before* the request is sent.
 *
 * ## The policy
 *
 * A capture may ask for exactly one thing: the site's own origin. Every
 * other network destination is refused before a byte goes out.
 *
 * The unit of permission is the **origin** — scheme, host and port — not
 * the host alone. Host alone is not enough, and the difference is not
 * academic: a development site served from `http://127.0.0.1:8080/` and
 * another service on the same machine at `http://127.0.0.1:8099/` share a
 * host and share nothing else. A host-only rule would let the capture read
 * the second one, which is precisely the reproduction that opened this
 * work.
 *
 * A request is permitted when:
 *
 *   1. its host is one of the site's own hosts (the hosts of `home_url()`
 *      and `site_url()`), **and**
 *   2. its port is the port of the matching site origin — or both the
 *      request's port and the site's port are their scheme's default
 *      (`80` for `http`/`ws`, `443` for `https`/`wss`), which is what lets
 *      a site that redirects `http` to `https` still be captured.
 *
 * A site host may additionally be declared by the operator, either with the
 * `AGENTSHELL_SCREENSHOT_ALLOWED_HOSTS` constant or with the
 * `agentshell_screenshot_allowed_hosts` filter. An operator who declares a
 * host has declared an *origin* for it: any port on that host is permitted.
 * The default is empty, so the policy is deny-by-default.
 *
 * ## What is *not* a network request
 *
 * `data:`, `blob:`, `about:` and `javascript:` URLs never reach the
 * network. They are permitted, and permitting them is not a hole: a
 * `data:` URL is the page's own bytes, and a `blob:` URL is a URL the
 * page already holds.
 *
 * ## DNS
 *
 * The policy is a *name* policy, and it is enforced at the request, before
 * any name is resolved. That has two consequences worth stating plainly.
 *
 *   - A host name that resolves to a private address is refused, because
 *     the refusal is decided on the name, not on the address. DNS
 *     rebinding — a name that resolves to `127.0.0.1` — therefore buys an
 *     attacker nothing: the request is never made.
 *   - The site's *own* host is trusted, and no name resolution is
 *     performed for it. If the site's own DNS were poisoned, the capture
 *     would follow it. That is a property of trusting the site, not a gap
 *     in the policy: the site's host is the thing the capture exists to
 *     photograph.
 *
 * The class also *classifies* hosts — loopback, private, link-local,
 * cloud-metadata — so that a refusal can say **why** it refused. The
 * classification is for the operator's benefit; the decision does not
 * depend on it.
 *
 * ## Two layers, one policy
 *
 * The policy is enforced twice, and the second layer is not decorative.
 *
 *   1. **The Fetch domain** (DevTools Protocol). Every request the page
 *      makes is paused before it is sent and decided here. This is the
 *      layer that produces a *report*: it can name the destination that
 *      was refused and say what kind of destination it was.
 *   2. **The browser's resolver rules** (`--host-resolver-rules`). Every
 *      name the browser resolves other than a permitted one fails to
 *      resolve. This is the layer that survives a browser that cannot be
 *      driven through the DevTools Protocol, and it is the reason the
 *      legacy window-based capture is *not* an unconditional hole.
 *
 * The resolver layer is coarser than the Fetch layer: it matches hosts,
 * not ports, because that is all a resolver rule can express. The two
 * layers together are at least as strict as the Fetch layer alone.
 */
class Browser_Request_Policy {

    /**
     * The filter an operator uses to declare additional permitted hosts.
     *
     * The callback receives an array of host names and returns an array of
     * host names. A host may be a bare name (`cdn.example.com`), a name
     * with a port (`cdn.example.com:8443` — the port is then required to
     * match), or a URL (its host and port are taken).
     */
    const FILTER_ALLOWED_HOSTS = 'agentshell_screenshot_allowed_hosts';

    /**
     * The constant an operator uses to declare additional permitted hosts.
     *
     * A comma-separated list, in the same forms the filter accepts.
     */
    const CONSTANT_ALLOWED_HOSTS = 'AGENTSHELL_SCREENSHOT_ALLOWED_HOSTS';

    /**
     * The schemes that reach the network.
     */
    const NETWORK_SCHEMES = array( 'http', 'https', 'ws', 'wss' );

    /**
     * The schemes that never reach the network.
     */
    const LOCAL_SCHEMES = array( 'data', 'blob', 'about', 'javascript' );

    /** @var array<int,array{scheme:string,host:string,port:?int,raw:string}> */
    private $origins = array();

    /** @var string[] */
    private $extra_hosts = array();

    /**
     * @param array  $origins     Permitted origins, each `{scheme, host, port, raw}`.
     * @param string[] $extra_hosts Operator-declared hosts, permitted on any port.
     */
    public function __construct( array $origins = array(), array $extra_hosts = array() ) {
        foreach ( $origins as $origin ) {
            if ( ! is_array( $origin ) || empty( $origin['host'] ) ) {
                continue;
            }
            $this->origins[] = array(
                'scheme' => (string) ( $origin['scheme'] ?? '' ),
                'host'   => (string) $origin['host'],
                'port'   => isset( $origin['port'] ) && null !== $origin['port'] ? (int) $origin['port'] : null,
                'raw'    => (string) ( $origin['raw'] ?? $origin['host'] ),
            );
        }
        foreach ( $extra_hosts as $host ) {
            $normalized = self::normalize_host( (string) $host );
            if ( '' !== $normalized ) {
                $this->extra_hosts[] = $normalized;
            }
        }
        $this->extra_hosts = array_values( array_unique( $this->extra_hosts ) );
    }

    /**
     * The policy for this site.
     *
     * @return Browser_Request_Policy
     */
    public static function for_site() {
        $origins = array();
        foreach ( array( 'home_url', 'site_url' ) as $function ) {
            if ( ! function_exists( $function ) ) {
                continue;
            }
            $url = $function();
            if ( ! is_string( $url ) || '' === trim( $url ) ) {
                continue;
            }
            $origin = self::origin_from_url( $url );
            if ( null !== $origin ) {
                $origins[] = $origin;
            }
        }

        $extra = array();

        if ( defined( self::CONSTANT_ALLOWED_HOSTS ) ) {
            $configured = constant( self::CONSTANT_ALLOWED_HOSTS );
            if ( is_string( $configured ) ) {
                $configured = explode( ',', $configured );
            }
            if ( is_array( $configured ) ) {
                foreach ( $configured as $entry ) {
                    $extra[] = (string) $entry;
                }
            }
        }

        if ( function_exists( 'apply_filters' ) ) {
            $filtered = apply_filters( self::FILTER_ALLOWED_HOSTS, array() );
            if ( is_string( $filtered ) ) {
                $filtered = explode( ',', $filtered );
            }
            if ( is_array( $filtered ) ) {
                foreach ( $filtered as $entry ) {
                    $extra[] = (string) $entry;
                }
            }
        }

        // An operator-declared entry may carry a port. A host with a port
        // is an origin: only that port is permitted. A host without one is
        // permitted on any port.
        $hosts     = array();
        $origins_extra = array();
        foreach ( $extra as $entry ) {
            $entry = trim( $entry );
            if ( '' === $entry ) {
                continue;
            }
            if ( false !== strpos( $entry, '://' ) ) {
                $origin = self::origin_from_url( $entry );
                if ( null !== $origin ) {
                    $origins_extra[] = $origin;
                }
                continue;
            }
            $port = null;
            if ( preg_match( '/^(.*):(\d+)$/', $entry, $matches ) ) {
                $entry = $matches[1];
                $port  = (int) $matches[2];
            }
            $normalized = self::normalize_host( $entry );
            if ( '' === $normalized ) {
                continue;
            }
            if ( null !== $port ) {
                $origins_extra[] = array(
                    'scheme' => '',
                    'host'   => $normalized,
                    'port'   => $port,
                    'raw'    => $normalized . ':' . $port,
                );
            } else {
                $hosts[] = $normalized;
            }
        }

        return new self( array_merge( $origins, $origins_extra ), $hosts );
    }

    /**
     * The origins the policy permits.
     *
     * @return array<int,array{scheme:string,host:string,port:?int,raw:string}>
     */
    public function origins() {
        return $this->origins;
    }

    /**
     * The hosts the policy permits on any port.
     *
     * @return string[]
     */
    public function extra_hosts() {
        return $this->extra_hosts;
    }

    /**
     * Every permitted host, whatever the port rule.
     *
     * @return string[]
     */
    public function allowed_hosts() {
        $hosts = array();
        foreach ( $this->origins as $origin ) {
            $hosts[] = $origin['host'];
        }
        foreach ( $this->extra_hosts as $host ) {
            $hosts[] = $host;
        }
        return array_values( array_unique( $hosts ) );
    }

    /**
     * Decide what to do with a request.
     *
     * @param string $url     The URL the browser wants to fetch.
     * @param array  $context `resource_type`, `frame_id`, `is_main_frame`.
     * @return array{allow:bool,category:string,reason:string,host:string,scheme:string,port:?int}
     */
    public function evaluate( $url, array $context = array() ) {
        $url = is_string( $url ) ? trim( $url ) : '';
        if ( '' === $url ) {
            return $this->decision( false, 'unparseable', 'the request had no URL', '', '', null );
        }

        // A browser treats a backslash in a URL as a forward slash before
        // it decides what the host is. The decision here has to be made on
        // the same string the browser will act on, or `http://host\@other/`
        // would be two different hosts to the two of us.
        $parsed = @parse_url( str_replace( '\\', '/', $url ) );
        if ( ! is_array( $parsed ) ) {
            return $this->decision( false, 'unparseable', 'the request URL could not be parsed', '', '', null );
        }

        $scheme = strtolower( (string) ( $parsed['scheme'] ?? '' ) );
        if ( '' === $scheme ) {
            return $this->decision( false, 'unparseable', 'the request URL had no scheme', '', '', null );
        }

        if ( in_array( $scheme, self::LOCAL_SCHEMES, true ) ) {
            return $this->decision(
                true,
                'not_a_network_request',
                'the request does not reach the network',
                '',
                $scheme,
                null
            );
        }

        if ( ! in_array( $scheme, self::NETWORK_SCHEMES, true ) ) {
            return $this->decision(
                false,
                'unsupported_scheme',
                'the request used the ' . $scheme . ' scheme, which a capture does not permit',
                '',
                $scheme,
                null
            );
        }

        $host = self::normalize_host( (string) ( $parsed['host'] ?? '' ) );
        if ( '' === $host ) {
            return $this->decision( false, 'unparseable', 'the request URL had no host', '', $scheme, null );
        }

        $port = isset( $parsed['port'] ) ? (int) $parsed['port'] : self::default_port( $scheme );

        // An operator-declared host is permitted on any port.
        if ( in_array( $host, $this->extra_hosts, true ) ) {
            return $this->decision(
                true,
                'declared_host',
                'the host is one the operator declared as permitted',
                $host,
                $scheme,
                $port
            );
        }

        foreach ( $this->origins as $origin ) {
            if ( $origin['host'] !== $host ) {
                continue;
            }
            if ( null !== $origin['port'] && (int) $origin['port'] === $port ) {
                return $this->decision(
                    true,
                    'same_origin',
                    'the request is for this site',
                    $host,
                    $scheme,
                    $port
                );
            }
            // A scheme upgrade keeps the port: `http://site` to
            // `https://site` is the same endpoint, and a site that
            // redirects one to the other must still be capturable.
            if ( null !== $origin['port']
                && self::default_port( $scheme ) === $port
                && self::default_port( $origin['scheme'] ) === (int) $origin['port'] ) {
                return $this->decision(
                    true,
                    'same_origin',
                    'the request is for this site',
                    $host,
                    $scheme,
                    $port
                );
            }
        }

        $category = self::classify_host( $host );
        if ( 'hostname' === $category ) {
            $category = 'cross_host';
        }

        return $this->decision(
            false,
            $category,
            self::refusal_reason( $category, $host ),
            $host,
            $scheme,
            $port
        );
    }

    /**
     * The browser's resolver rules for this policy.
     *
     * Everything resolves to nothing except the permitted hosts. The rules
     * are a *host* rule, so they are coarser than the origin rule above;
     * they exist so that the protection survives in a browser that cannot
     * be driven through the DevTools Protocol.
     *
     * @return string
     */
    public function host_resolver_rules() {
        $rules = array( 'MAP * ~NOTFOUND' );
        foreach ( $this->allowed_hosts() as $host ) {
            $rules[] = 'EXCLUDE ' . $host;
        }
        return implode( ', ', $rules );
    }

    /**
     * A description of the policy, for a capture's result.
     *
     * @return array
     */
    public function describe() {
        $origins = array();
        foreach ( $this->origins as $origin ) {
            $origins[] = array(
                'scheme' => $origin['scheme'],
                'host'   => $origin['host'],
                'port'   => $origin['port'],
            );
        }
        return array(
            'allowed_origins' => $origins,
            'allowed_hosts'   => $this->allowed_hosts(),
            'extra_hosts'     => $this->extra_hosts,
        );
    }

    /**
     * Normalise a host for comparison.
     *
     * Case is folded, a trailing dot is dropped (it names the same host),
     * and IPv6 literals are reduced to their canonical form so that
     * `0:0:0:0:0:0:0:1` and `::1` are one host. An IPv4-mapped IPv6
     * address (`::ffff:127.0.0.1`) is reduced to the IPv4 address it
     * names, because that is the address it reaches.
     *
     * @param string $host The host.
     * @return string The normalised host, or '' when there is none.
     */
    public static function normalize_host( $host ) {
        $host = strtolower( trim( (string) $host ) );
        if ( '' === $host ) {
            return '';
        }
        // One trailing dot is the DNS root label. It names the same host.
        if ( '.' === substr( $host, -1 ) && strlen( $host ) > 1 ) {
            $host = substr( $host, 0, -1 );
        }
        if ( '[' === substr( $host, 0, 1 ) && ']' === substr( $host, -1 ) ) {
            $host = substr( $host, 1, -1 );
        }
        $packed = @inet_pton( $host );
        if ( false === $packed ) {
            return $host;
        }
        if ( 4 === strlen( $packed ) ) {
            return inet_ntop( $packed );
        }
        // An IPv4-mapped address reaches the IPv4 address it names.
        if ( "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" === substr( $packed, 0, 12 ) ) {
            return inet_ntop( substr( $packed, 12 ) );
        }
        return inet_ntop( $packed );
    }

    /**
     * What kind of destination a host is.
     *
     * The categories are for the operator: a refusal that says "that is a
     * link-local address" is a different piece of information from one
     * that says "that is another host".
     *
     * @param string $host The host.
     * @return string One of: hostname, loopback, private, link_local,
     *                metadata, unspecified, carrier_grade_nat, public.
     */
    public static function classify_host( $host ) {
        $host = self::normalize_host( $host );
        if ( '' === $host ) {
            return 'hostname';
        }

        $packed = @inet_pton( $host );
        if ( false === $packed ) {
            return 'hostname';
        }

        if ( 4 === strlen( $packed ) ) {
            $octets = array_map( 'intval', explode( '.', $host ) );
            if ( 4 !== count( $octets ) ) {
                return 'public';
            }
            if ( 169 === $octets[0] && 254 === $octets[1] && 169 === $octets[2] && 254 === $octets[3] ) {
                return 'metadata';
            }
            if ( 169 === $octets[0] && 254 === $octets[1] ) {
                return 'link_local';
            }
            if ( 127 === $octets[0] ) {
                return 'loopback';
            }
            if ( 0 === $octets[0] ) {
                return 'unspecified';
            }
            if ( 10 === $octets[0] ) {
                return 'private';
            }
            if ( 172 === $octets[0] && $octets[1] >= 16 && $octets[1] <= 31 ) {
                return 'private';
            }
            if ( 192 === $octets[0] && 168 === $octets[1] ) {
                return 'private';
            }
            if ( 100 === $octets[0] && $octets[1] >= 64 && $octets[1] <= 127 ) {
                return 'carrier_grade_nat';
            }
            return 'public';
        }

        // IPv6.
        $hex = bin2hex( $packed );
        if ( '00000000000000000000000000000001' === $hex ) {
            return 'loopback';
        }
        if ( '00000000000000000000000000000000' === $hex ) {
            return 'unspecified';
        }
        $first = hexdec( substr( $hex, 0, 2 ) );
        if ( 0xFE === ( $first & 0xFE ) ) {
            return 'link_local';
        }
        if ( 0xFC === ( $first & 0xFE ) ) {
            return 'private';
        }
        // An IPv4-mapped address is classified as the IPv4 address it is.
        if ( '00000000000000000000ffff' === substr( $hex, 0, 24 ) ) {
            return self::classify_host( inet_ntop( substr( $packed, 12 ) ) );
        }
        return 'public';
    }

    /**
     * The default port for a scheme.
     *
     * @param string $scheme The scheme.
     * @return int
     */
    public static function default_port( $scheme ) {
        $scheme = strtolower( (string) $scheme );
        if ( 'https' === $scheme || 'wss' === $scheme ) {
            return 443;
        }
        return 80;
    }

    /**
     * A URL with everything that could carry a secret removed.
     *
     * A refused destination is reported by host and scheme. Its path and
     * its query are not reported: a query string is where preview
     * credentials live, and a path is where a token would live.
     *
     * @param string $url The URL.
     * @return string
     */
    public static function redact_url( $url ) {
        $parsed = @parse_url( str_replace( '\\', '/', (string) $url ) );
        if ( ! is_array( $parsed ) ) {
            return '';
        }
        $scheme = strtolower( (string) ( $parsed['scheme'] ?? '' ) );
        $host   = self::normalize_host( (string) ( $parsed['host'] ?? '' ) );
        if ( '' === $host ) {
            return $scheme ? $scheme . ':' : '';
        }
        $out = '' !== $scheme ? $scheme . '://' . $host : $host;
        if ( ! empty( $parsed['port'] ) ) {
            $out .= ':' . (int) $parsed['port'];
        }
        return $out . '/';
    }

    /**
     * The reason a destination of a given category was refused.
     *
     * @param string $category The category.
     * @param string $host     The host.
     * @return string
     */
    public static function refusal_reason( $category, $host ) {
        switch ( $category ) {
            case 'loopback':
                return 'the destination ' . $host . ' is a loopback address on this machine';
            case 'private':
                return 'the destination ' . $host . ' is on a private network';
            case 'link_local':
                return 'the destination ' . $host . ' is a link-local address';
            case 'metadata':
                return 'the destination ' . $host . ' is a cloud metadata address';
            case 'unspecified':
                return 'the destination ' . $host . ' is the unspecified address';
            case 'carrier_grade_nat':
                return 'the destination ' . $host . ' is in a carrier-grade NAT range';
            default:
                return 'the destination ' . $host . ' is not this site';
        }
    }

    /**
     * Build a decision.
     *
     * @param bool   $allow    Whether the request may proceed.
     * @param string $category The category of the destination.
     * @param string $reason   A sentence saying why.
     * @param string $host     The host.
     * @param string $scheme   The scheme.
     * @param int|null $port  The port.
     * @return array
     */
    private function decision( $allow, $category, $reason, $host, $scheme, $port ) {
        return array(
            'allow'    => (bool) $allow,
            'category' => (string) $category,
            'reason'   => (string) $reason,
            'host'     => (string) $host,
            'scheme'   => (string) $scheme,
            'port'     => null === $port ? null : (int) $port,
        );
    }

    /**
     * Read an origin out of a URL.
     *
     * @param string $url The URL.
     * @return array{scheme:string,host:string,port:int,raw:string}|null
     */
    public static function origin_from_url( $url ) {
        $parsed = @parse_url( trim( (string) $url ) );
        if ( ! is_array( $parsed ) ) {
            return null;
        }
        $host = self::normalize_host( (string) ( $parsed['host'] ?? '' ) );
        if ( '' === $host ) {
            return null;
        }
        $scheme = strtolower( (string) ( $parsed['scheme'] ?? '' ) );
        $port   = isset( $parsed['port'] ) ? (int) $parsed['port'] : self::default_port( $scheme );
        return array(
            'scheme' => $scheme,
            'host'   => $host,
            'port'   => $port,
            'raw'    => $scheme . '://' . $host . ':' . $port,
        );
    }
}
