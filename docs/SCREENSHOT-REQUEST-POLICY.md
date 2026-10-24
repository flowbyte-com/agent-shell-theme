# The screenshot request policy

**Status:** record (not a contract). The normative behaviour lives in
[`AGENTS.md`](../AGENTS.md) §2, under `agentshell_screenshot`.

**Closes:** issue #17 — *"Screenshot captures follow a cross-host
redirect and return the other host's content."*

**Date:** 2026-10-24.

---

## 1. The defect

`agentshell_screenshot` validated the **initial** URL: its host had to
equal the host of `home_url()`. That check is about the request that was
*made*. It says nothing about the responses that came *back*.

Chromium then does as it likes: it follows redirects, it runs scripts,
it opens frames, it loads subresources, and none of those go through
the check. The independent review of PR #16 reproduced it against a
disposable site — a page on the site's own host containing
`location.href="http://127.0.0.1:8099/secret.php"` — and the capture
succeeded, with the **other host's content** in the image.

The issue's own requirements proposed reading the page's final URL
afterwards (`Runtime.evaluate("location.href")`, or
`Page.getNavigationHistory`) and refusing the capture when it was no
longer on the site's host.

**That is not enough, and the mission for this work said so
explicitly: *"A final-URL check alone cannot prevent such a request."***
By the time the final URL is known, the request has been made. The
disclosure is not only in the image; it is in the request itself — a
`GET` to an internal service, with whatever side effect that service
has. A check after the fact cannot undo either.

**So the check was moved to where it can decide something: before the
request is sent.**

## 2. The design

### 2.1 The unit of permission is the origin

The obvious policy — "the request's host must be the site's host" — is
not enough, and the reason is not academic:

```
the site      http://127.0.0.1:8080/
the secret    http://127.0.0.1:8099/secret.php
```

They share a host. They share nothing else. A host-only rule would let
the capture read the second one, which is *exactly* the reproduction
that opened this work — the reproduction used `127.0.0.1:8099` because
that is what the reviewer's disposable site had.

So the policy is decided on the **origin**: scheme, host and port.

A request is permitted when:

1. its host is one of the site's own hosts — the hosts of `home_url()`
   and `site_url()` — **and**
2. its port is the port of the matching site origin, **or** both the
   request's port and the site's port are their scheme's default (`80`
   for `http`/`ws`, `443` for `https`/`wss`).

Rule 2's second clause is what lets a site that redirects `http` to
`https` still be capturable. Rule 2's first clause is what stops
`127.0.0.1:8099` when the site is `127.0.0.1:8080`.

### 2.2 Two layers, one policy

| Layer | Mechanism | What it buys |
|---|---|---|
| **The interception** | the DevTools Protocol's **Fetch** domain: every request the page makes is paused before it is sent and decided here | precision (origin-level) and a **report**: the refusal can name the destination and say what kind of destination it was |
| **The resolver rules** | `--host-resolver-rules="MAP * ~NOTFOUND, EXCLUDE <host>"` | survival: every name the browser resolves other than a permitted one fails to resolve, so the request never leaves the browser even on a path that cannot intercept |

The resolver layer is host-level, because a resolver rule cannot express
a port. It is the coarser of the two. It is also the layer that stops a
request the interception never saw — a request made by a target the
interception was not attached to, or by a browser that cannot be driven
through the protocol at all.

The two together are at least as strict as the interception alone.

### 2.3 The interception, in detail

`Fetch.enable` with `[{urlPattern: '*', requestStage: 'Request'}]` is
installed on the page's session, and the capture answers every
`Fetch.requestPaused`:

* permitted → `Fetch.continueRequest`;
* not permitted → `Fetch.failRequest` with `BlockedByClient`.

**The answer must be given while the message carrying it is being
read.** The browser is holding the request until it is answered; an
answer given later is an answer given too late, because the request
would have to be *allowed* in order to be *refused*. That is why
`CDP_Connection` grew an event-handler mechanism: the handler is
invoked at the moment the message arrives, and it answers with a
write-only command (`notify()`), never with a read — a read inside a
handler would consume the answer to whatever command the reader is
waiting for.

**Frames.** An out-of-process iframe is a target of its own, with its
own session and its own requests. `Target.setAutoAttach` with
`waitForDebuggerOnStart: true` attaches to it and holds it *before* it
may make a request; the interception is installed on it and it is then
released. The main frame's id comes from `Page.getFrameTree`, which is
how a request for the *page* is told apart from a request for
something inside it.

**Browser errors, timeouts and cancelled requests.** A failed request
is a failed request: the capture reports the browser's own reason
(`net::ERR_CERT_AUTHORITY_INVALID`, `net::ERR_CONNECTION_REFUSED`) and
does not treat it as a policy refusal. A timeout kills the browser's
process group and publishes nothing. A request the browser cancels is
never seen by the policy at all — the policy does not need it to be.

### 2.4 What happens when the policy refuses

| What was refused | What the capture does |
|---|---|
| the **page** (a document request on the main frame) | the capture is **refused**, with the reason. There is nothing to photograph: the page is not this site. |
| something **inside** the page | the request is failed, the capture proceeds, and the refusal is **reported** — by host, by kind, and with no path and no query |

The report is what makes the policy legible. "Nothing was refused" is
only meaningful next to "these were the destinations that were
permitted", and a page that quietly lost a font or an image would
otherwise be indistinguishable from a page that never had one.

### 2.5 Credentials

A refused destination is reported by scheme and host, with the port. It
is **not** reported with its path or its query, because that is where a
preview credential rides. `Browser_Request_Policy::redact_url()` drops
both, and the existing credential-hygiene suites continue to cover the
tool's own reporting.

An *authenticated* preview — the draft, the transaction and the
published-edit previews — is a request to the site's own origin with a
credential in the query. The policy permits it, and the interception
continues it. The credential is in the permitted request, so nothing
about the policy needed to change for previews to keep working.

### 2.6 DNS, and what the policy does not do

The policy is a **name** policy, enforced before any name is resolved.

* A name that resolves to a private address is refused, because the
  refusal is decided on the name and not on the address. **DNS
  rebinding buys an attacker nothing**: the request is never made.
* The site's own host is trusted and is not resolved for. If the site's
  own DNS were poisoned, the capture would follow it. That is a
  property of trusting the site, not a gap in the policy.

The policy also **classifies** destinations — loopback, private,
link-local, cloud-metadata, carrier-grade NAT, unspecified, public —
so that a refusal can say *why* it refused. The classification is for
the operator's benefit; the decision does not depend on it. A host that
resolves to `127.0.0.1` is refused for being another host, and the
message says it is a loopback address.

### 2.7 The operator's allowlist

A site whose pages legitimately load from a third-party origin — a CDN,
a font host — would have those requests refused. The policy therefore
has an explicit, **empty by default** allowlist:

* the `AGENTSHELL_SCREENSHOT_ALLOWED_HOSTS` constant — a comma-separated
  list; and
* the `agentshell_screenshot_allowed_hosts` filter.

A declared host is permitted on any port; a declared `host:port` is an
origin and only that port is permitted. The default is empty, so the
policy is deny-by-default, and a site that needs an exception says so
in one visible place.

The AgentShell theme's own widget library map (`d3`, `mathjs`) loads
two CDN origins. The showcase does not use those widgets, so the
default needs no exception; a site that does will add them.

## 3. The legacy window capture

The window path hands a URL to Chrome and lets Chrome take the
picture. It follows redirects and runs scripts, and there was nothing
to stop it from doing either.

It now carries the **resolver rules**, so it is not an unprotected
path: every name it resolves other than a permitted one fails to
resolve. But those rules are host-level, where the interception is
origin-level, so it cannot enforce the same guarantee.

**So the tool does not use it.** The `auto` mode is now
DevTools-only, and when the DevTools path cannot be used the capture
**fails and says why**:

> Screenshot failed: *&lt;reason&gt;*. The legacy window-based capture is
> not used as a fallback, because it cannot enforce the request policy
> that keeps a capture on this site.

A capture that quietly changed its guarantee would be worse than a
capture that failed.

The window path is still in the code, and still reachable by name,
because the capture lock, the wall-clock deadline and the profile
isolation are all exercised through it, and because its result says
which path ran. It is a coarser guarantee and it is labelled as one.

## 4. What the tests prove

| Suite | What it proves | Speed |
|---|---|---|
| `tests/browser-request-policy-test.php` | the decision: every origin shape, every host classification, the disguise cases, the resolver rules, the redaction | fast |
| `tests/screenshot-interception-test.php` | the **answer**: a fake browser that pauses requests and records what the capture answered — continued, or failed with `BlockedByClient` | fast |
| `tests/screenshot-request-policy-live-test.php` | the **effect**: a real browser and two disposable sites. The destination receives **no request at all** — not "no content in the image" | slow |
| `tests/screenshot-request-policy-mutation.php` | that the fast suite **notices** when the mechanism is removed: seven mutants, seven kills | slow-ish |

### 4.1 The fourteen adversarial reproductions

The live suite starts two disposable sites on the machine: the *site*,
which is the origin the capture is pointed at, and the *secret*, which
is the destination. Every request the secret receives is logged. The
property under test is the strong one — **the secret receives no
request at all** — so a destination that logs the attempt sees nothing.

| # | How the page tries | | # | How the page tries |
|---|---|---|---|---|
| 1 | an HTTP 302 redirect | | 8 | a `<script src>` |
| 2 | an HTTP 301 redirect | | 9 | `fetch()` |
| 3 | a meta refresh | | 10 | the IPv6 loopback literal `[::1]` |
| 4 | `location.href` | | 11 | the cloud metadata address `169.254.169.254` |
| 5 | `location.replace` | | 12 | a private-network address `10.0.0.1` |
| 6 | `window.open` | | 13 | another host entirely |
| 7 | an `<img>` | | 14 | — |

Plus the two that must keep working: the site's own page, and the
site's own public asset — the latter asserted by checking that the
asset really *was* requested, because a policy that refused the site's
own resources would be a policy that broke the site.

**Scenario 10 is the one that separates the two layers.** The secret is
on the *same host* as the site and on a different port, so the
resolver rules — which match hosts, not ports — cannot stop it. Only
the origin-level interception can, and it does.

### 4.2 The mutation harness

Seven mutants, each removing exactly one mechanism, each judged by the
fast suite:

```
killed the interception is never installed (6 failing assertions)
killed every paused request is continued (5 failing assertions)
killed the refusal is not sent to the browser (2 failing assertions)
killed the page asking to leave is not refused (1 failing assertions)
killed the refusals are not reported (3 failing assertions)
killed the port is not compared (1 failing assertions)
killed the policy permits everything (5 failing assertions)
```

A regression test that cannot fail is a claim. This one can.

## 5. What changed, and what did not

**Changed:**

* `agentshell-mcp/includes/class-browser-request-policy.php` — new. The
  decision, the classification, the allowlist, the resolver rules, the
  redaction.
* `agentshell-mcp/includes/class-screenshot-cdp.php` — the Fetch
  interception, the auto-attach for out-of-process frames, the
  resolver rules, the reporting, the refusal.
* `agentshell-mcp/includes/class-cdp.php` — the event-handler
  mechanism, and the unmasked frame a server sends.
* `agentshell-mcp/includes/class-screenshot.php` — the policy is built
  and passed down; the window path carries the resolver rules; the
  automatic fallback is gone.
* `agentshell-mcp/includes/tools/class-screenshot.php` — the early
  refusal (a URL the policy does not permit never reaches a browser),
  and the report in the tool's own result.

**Not changed:**

* who may take a capture (`manage_options`, unchanged);
* the preview credential model — the credential never appears in a
  capture, a tool result, an error message or an audit record;
* the inline/stored delivery, the ownership model, the retention;
* the window path's own behaviour, except for the resolver rules it
  now carries.

## 6. The issue's acceptance criteria, and one of them that moved

The issue was written for a final-URL check, and its acceptance
criteria reflect that design. The mission for this work superseded the
issue's design — it asked for an *enforceable* policy and said that a
final-URL check alone could not be one — so one criterion changed with
it:

> *"A cross-host redirect that lands back on the site's own host is
> allowed — the check is about the final host, not about the path
> taken."*

Under pre-request enforcement the redirect chain is **cut at the first
off-site hop**: the request to the other host is never made, so the
chain cannot complete and the capture never shows the other host at
all. The criterion's *intent* — that the capture must not contain
another host's content — is satisfied more strongly than the criterion
asked. The behaviour is documented here and in `AGENTS.md` §2 rather
than left to be discovered.

The other criteria hold as written: a cross-host redirect is refused
and the refusal names the reason; a same-host capture is unchanged; and
the refusal appears in the tool's own result, not only in a log.
