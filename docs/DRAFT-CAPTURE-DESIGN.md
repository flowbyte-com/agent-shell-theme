# Phase 2 — screenshotting drafts without publishing them

**Status: implemented.** Phase 1 is
[`TRANSACTION-PREVIEW.md`](./TRANSACTION-PREVIEW.md). The design below was
written first and settled the approach; the sections marked *As built* record
what implementation and live measurement actually showed, including the two
vulnerabilities that surfaced only under negative testing.

## The problem

`agentshell_screenshot` drives headless Chrome against a front-end URL. Chrome
carries no credentials. So a draft is unreachable, and the current workarounds
are all worse than the problem:

- **publish, capture, unpublish** — during the window the draft is public,
  indexable, in the REST API, and in the audit log as a real publish;
- **give Chrome a login** — hands the browser a real session;
- **bypass the status check** — turns every draft on the site public.

## What core already does

Not from memory — read in `wp-includes/class-wp-query.php` and confirmed with a
live authenticated probe.

`WP_Query::get_posts()` already authorises front-end previews
(`class-wp-query.php:3494-3528`):

```php
if ( ! $post_status_obj->public ) {
    if ( ! is_user_logged_in() ) {
        $this->posts = array();                 // anonymous: refused outright
    } else {
        if ( $post_status_obj->protected ) {    // draft, pending, future
            if ( ! current_user_can( 'edit_post', $this->posts[0]->ID ) ) { … }
        } elseif ( $post_status_obj->private ) { // private
            if ( ! current_user_can( 'read_post', $this->posts[0]->ID ) ) { … }
        }
    }
}
```

So the enforcement point already exists and is already per-post. The only
missing piece is an identity for the browser's request. Two operational details,
both verified:

- **The link shape.** `get_preview_post_link()` builds
  `?page_id=N&preview=true` for pages. `?p=N` does **not** resolve to a page —
  confirmed live: `?p=8` returns 404 while `?page_id=8&preview=true` returns 200
  with a session.
- **The timing.** `wp()` → `WP::main()` → `query_posts()` runs the main query,
  and `wp()` is called from `wp-blog-header.php` — *after* `init`, *before*
  `template_redirect`. **A draft-preview credential must therefore be verified
  on `init`, not `template_redirect`.** This is the opposite of the Phase 1
  credential, which can sit at `template_redirect` precisely because it does
  not change query results. Getting this wrong yields a 404 that looks like a
  broken credential.

## Recommendation — a bearer that restores the real principal, once

A short-lived, per-post, per-actor credential. On verification it calls
`wp_set_current_user()` **for that one request** and then gets out of the way,
letting core's own preview path decide.

```
screenshot(post_id=N)          tool mints, never receives
  → agentshell_screenshot attaches  agentshell_post=N
                                     agentshell_post_exp=…
                                     agentshell_post_key=HMAC(post|actor|exp|nonce)
  → Chrome GET /?page_id=N&preview=true&…
  → init: verify HMAC, expiry, and that the queried post is N
          wp_set_current_user( <the MCP caller's WP user> )
  → WP::main(): core checks current_user_can('edit_post'|'read_post', N)
  → render
```

Why this one:

1. **WordPress keeps ownership and capabilities.** Nothing is bypassed. Core's
   check runs as written, against the *actual* user's real capabilities. A user
   who may not read post 9 still gets a 404 on post 9, even holding a valid
   credential for post 8.
2. **The credential is not authority, it is identity.** It asserts *who is
   asking*, and the authorisation question is still answered by WordPress. A
   bearer that instead flipped a status check would be authority.
3. **Reuses the shape already reviewed.** Minting, expiry, signing-key choice
   and the scrubber are the ones from Phase 1, already covered by 11 mutations.

### Rejected alternatives

| | Why not |
|---|---|
| **Inject real cookies into Chrome** | Hands the browser a live session. The SSRF guard is a same-host check, but anything that *is* on that host — a redirect, a plugin endpoint, a compromised dependency — now executes as the agent. It also breaks the moment the site sets `SameSite=Strict`, which it should. |
| **A `map_meta_cap` / status-bypass filter** | Global, unauthenticated, and it publishes *every* draft to *everyone*. Its only security property is "the URL is hard to guess". |
| **Publish, capture, unpublish** | Real publication: indexed, in the REST API, in sitemaps, in the audit log. It also produces two spurious revisions per capture. |

## Security implications, stated plainly

1. **This is a real privilege grant for one request.** `wp_set_current_user()`
   is not a scoped flag — during that request the principal is the user, with
   everything they can do. Containment must come from *where it is called* and
   *what is checked*, not from the mechanism.

2. **Therefore bind it to the post, not just the principal.** The credential
   carries the post id and verification must confirm the post the query
   resolved is that same post. Without this, a bearer for post 8 becomes a
   general "be any user" token for every private post that user can read —
   which is exactly the failure the task forbids.

3. **Single-use needs server-side state, and single-use is hard.** A nonce must
   live somewhere (option or transient), which is new persistent state and
   needs a cleanup story. Worse: Chrome may legitimately fetch a page more than
   once — speculation rules, prefetch, favicon. A strict one-shot token can be
   burned by a prefetch and break the render it was minted for. **This should be
   settled before implementation, not during it.** Recommend: consume on first
   *successful authorised render*, keyed by (post, actor, exp), with a short
   grace window.

4. **The credential travels in a URL.** It lands in access logs, `Referer`
   headers and browser history — the same exposure as the Phase 1 credential but
   strictly worse, because it carries a person's authority rather than a
   design. Short TTL, single use, never echoed in a summary, and it must go
   through the same `strip_preview_args` scrubber so it cannot be pasted into a
   `url` argument and land in the audit log.

5. **It must be refused, loudly, when it cannot be honoured** — no signing
   secret, no transaction-appropriate user, unresolvable post. A degraded
   "captured the 404 page instead" result would read to an agent as a successful
   capture of the draft, and it is worse than an error.

6. **Chrome's own caching** must be checked with a real profile, not just an
   in-memory one: a private draft rendered from a warm cache is a disclosure on
   the developer's own machine.

## Before implementing

- Measure how many requests Chrome actually makes per capture, with speculation
  rules enabled. This decides the single-use design.
- Confirm the caller-identity path: the MCP transport authenticates by
  application password over HTTP, but Chrome's request authenticates by
  nothing. Where the WP user id comes from — carried in the signature, looked up
  by actor name — is an unresolved choice, and it decides whether item 2 above
  can be enforced.
- Re-run the Phase 2 core probe against a *revision* and an *auto-draft*, not
  only `draft` and `private`.

## As built

The open questions above are answered by measurement, not assumption.

### Chrome makes one request per capture

Measured with a logging mu-plugin rather than inferred. Chrome fetches the
preview document exactly once, so single-use would have been technically
feasible. It was not adopted: the decision was that the credential need not be
single-use, and the cost of that is paid back with a **300-second TTL** instead
of the transaction preview's 900 — the exposure that argues for single-use is
the URL travelling in access logs, and a short window makes a log line a dead
end rather than a standing capability.

### The caller identity travels as a signed argument, not in the signature

An HMAC is one-way, and Chrome's request has no identity to read the actor back
out of. So `agentshell_post_user` travels as its own query argument, in the
clear — a user id is not a secret — and the signature is what makes it
trustworthy, since any edit to it invalidates the match.

This is what makes item 2 above enforceable: the post id *and* the actor id are
both inside the signed string.

### The naive version was measured, and it is why the gates exist

A deliberately unrestricted `?asprobe_user=1` impersonation was built and
measured. It opened **both** unrelated private posts **and** the entire REST
API, including user enumeration — because REST is served on `parse_request`,
which runs *after* `init`, so an identity restored at `init` is already in place
when the REST server authorises. That measurement is the justification for four
independent gates rather than one:

| Gate | Refuses |
|---|---|
| Route (`agentshell_post_preview_route_allowed()`) | anything that is not a plain front-end page GET — REST, admin, admin-ajax, XML-RPC, wp-login, wp-cron, non-GET |
| Signature (`Post_Preview::authenticate()`) | missing, forged, replayed, expired, or actor-less credentials |
| Post binding (`agentshell_bind_post_preview_to_query()`) | a valid credential whose request resolved to some other post |
| Capability | core's own check, untouched — `edit_post` for protected statuses, `read_post` for private |

### Two vulnerabilities found by negative testing, both fixed

Neither was visible by reading the code.

1. **Cross-post render leak.** `WP_Query::set_404()` raises a flag and changes
   nothing else: `$wp_query->posts` and the global `$post` still hold the
   resolved object. So a credential for post 10 aimed at private post 11
   returned **post 11's content with a 404 behind it**. Fix:
   `agentshell_refuse_post_preview()` empties the query and clears the global.
2. **Credential propagation through a redirect.** `redirect_canonical()`
   preserves unknown query arguments, so a request carrying a preview key was
   answered `301` with the full key in the `Location` header. Found with
   `curl -I` — a HEAD request, which the route gate refuses, so it was invisible
   from the browser path. Fix: `agentshell_block_preview_redirects()` suppresses
   the redirect, parsing the query rather than substring-matching it so an
   unrelated `?my_agentshell_post=1` still redirects normally.

### Timing

As predicted and confirmed against core source: the credential is verified on
`init` priority 0 and the post binding is checked on `template_redirect`
priority 0. Checking the target post *before* the main query is not merely
insufficient — it cannot work, because before the query nobody knows what the
request resolves to, and a request can reach the bound post through several
aliases.

### `agentshell_authorize_post_preview()` memoises per credential

Keyed on the credential, not on a bare flag, matching
`agentshell_authorize_transaction_preview()` beside it. In production one
request carries one credential, so this changes no behaviour; it is what lets
the decision be tested at all, since a single-process test run evaluates many
credentials in sequence.

### Browser profile isolation

Item 6 of the original analysis — whether a rendered draft reaches a persistent
browser profile — is now closed, and closed by measurement.

`Screenshot::capture()` gives every capture its own throwaway browser profile:
a fresh `profile-<32 hex>` name (unpredictable, because `/tmp` is
world-writable), created `0700` under a per-uid base directory, passed
explicitly as `--user-data-dir`, and removed when the capture ends. `HOME` and
the `XDG_*` paths are pointed inside it too, so a component that resolves its
own profile location still lands in the directory that is about to be deleted.
Chrome's sandbox is untouched, and no TLS or SSRF control was relaxed.

Cleanup runs in a `finally` *inside* the capture lock, so it happens only after
`run_browser()` has returned or thrown — the point at which the browser's
process tree is dead. A `register_shutdown_function` backstop covers a fatal
error or an early exit, and a narrow stale sweep reclaims what a `SIGKILL` left
behind. The recursive delete tests `is_link()` before `is_dir()`, and refuses
any path that is not a direct `profile-<32 hex>` child of the base — so a
planted symlink is unlinked as itself and never descended into.

Verified against real Chrome under `strace`, which recorded the actual `execve`
rather than trusting the code:

```
execve("/opt/google/chrome/chrome", ["--headless", ...,
    "--user-data-dir=/tmp/agentshell-chrome-1000/profile-cdc2d25ba…",
    "--screenshot=…", "http://agentshell.local/?page_id=14&preview=true&…"])
```

Every zygote child inherited the same profile. Afterwards: zero profile
directories remained, and the developer's real Chrome profile gained **no
paths at all** across a capture (identical sorted path set before and after),
with zero occurrences of the draft's marker text anywhere in it.

## Verification

- `tests/post-preview-test.php` — 62 assertions, 0 failures. Positive and
  negative cases: cross-post misuse, unauthorised actors, expired and stretched
  expiries, deleted accounts, REST/admin/login/cron/POST routes, query aliases,
  redirect suppression, credential hygiene.
- `tests/post-preview-child.php` — the branches that cannot share a process
  (`REST_REQUEST` / `XMLRPC_REQUEST` constants, and the unconfigured-secret
  path), since a constant cannot be undefined once set.
- 11 of 11 hand-written mutants of the four gates are killed by the suite.
- `tests/browser-isolation-test.php` — 28 tests over browser profile isolation:
  the flag reaching Chrome, `0700` permissions, per-capture uniqueness,
  removal on success/failure/invalid render/timeout, descendants dead before
  cleanup, the pre-existing profile untouched, and no cached draft content or
  credential surviving anywhere.
- `tests/browser-isolation-mutation.php` — **14 of 14 mutants killed**, including
  the removal of `--user-data-dir` itself (7 failing assertions).
- Full suite: **355 passed, 0 failed**.

## Known limitations

- **The credential is a bearer in a URL.** It reaches web server access logs.
  The 300-second TTL bounds this; it does not eliminate it. Log scrubbing for
  `agentshell_post_key` is the operator's responsibility.
- **The rendered page contains the credential.** WordPress's admin bar builds
  the "Customise" link from the current request URL, so a preview response
  embeds `?agentshell_post_key=…` in a live `href` in its own body. Measured
  live, not inferred. It is visible only to a request that already holds a
  valid credential — an anonymous request to the same URL gets a bare 404 with
  nothing in it — and the key expires with the TTL, but it does mean the
  credential is in the HTML, the browser DOM, and anything that saves the page.
  Stripping it from the admin bar was not attempted: it means filtering core's
  admin-bar output, which is a wider change than this work should make.
- **Revisions and auto-drafts are untested against core's check.** The status
  list covers `draft`, `pending`, `private` and `future`; core's own behaviour
  for `auto-draft` and revision previews was not probed.
- **The binding gate trusts `get_queried_object_id()`.** A WordPress plugin
  that rewrites query results after `template_redirect` priority 0 could
  reintroduce the cross-post leak it closes. Nothing in this theme does.
- **A SIGKILL leaves a profile behind.** No in-process mechanism can prevent
  that. The stale sweep reclaims such a directory once it is an hour old, so the
  exposure is bounded by that interval rather than being immediate — a killed
  capture can leave a rendered draft readable at a known path for up to an hour.
  `SIGKILL`-proof cleanup would need a separate process or an OS-level sandbox.