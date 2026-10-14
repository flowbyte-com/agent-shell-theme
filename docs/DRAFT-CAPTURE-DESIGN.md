# Phase 2 — screenshotting drafts without publishing them

**Status: proposal. Not implemented.** Phase 1 is
[`TRANSACTION-PREVIEW.md`](./TRANSACTION-PREVIEW.md); this is the next piece,
designed and stopped at for review.

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

## Status

Design only. Nothing in this document is implemented, and no MCP tool exposes
draft capture yet.