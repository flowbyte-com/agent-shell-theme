# Screenshot privacy — design

**Status:** implemented. See "What changed from the original design" for the
two decisions that were reversed during review.
**Supersedes the recommendation in** [`SCREENSHOT-STORAGE.md`](./SCREENSHOT-STORAGE.md),
which proposed an authenticated retrieval endpoint. That recommendation is
wrong for AgentShell's topology — see "Why not a retrieval URL".

**Defect being fixed:** `agentshell_screenshot` writes every capture to
`wp-content/uploads/agentshell-screenshots/` and returns that public URL.
Confirmed live: 9 PNGs, world-readable, unauthenticated `GET` → `200`, never
pruned. Filenames are `shot-<timestamp>-<6 chars>`, so the URL must be treated
as effectively public.

---

## Decision: native MCP image content block

The tool returns the PNG as an MCP **image content block** in the same
`tools/call` response:

```json
{"jsonrpc":"2.0","id":1,"result":{
  "content":[
    {"type":"image","data":"iVBORw0KGgo…","mimeType":"image/png"},
    {"type":"text","text":"{\"captured\":true,\"width\":1280,…}"}
  ]}}
```

Not base64 dumped into a text block. The distinction matters: a text block
carries a string an agent must decode by hand, whereas an image block is what
an MCP client renders natively.

### Why not a retrieval URL

An authenticated endpoint is the obvious design, and it is what I first
recommended. It does not work here.

The MCP daemon holds the WordPress application password and attaches it when
forwarding tool calls. Nothing propagates those credentials to Claude Code's
image viewer, a browser, or any other MCP client. A protected endpoint would
be genuinely secure *and* unusable — the agent could not fetch the bytes it
was just told about. Worse, it fails silently: the capture "succeeds" and the
agent simply never sees an image.

Delivering the image inside the tool result keeps the screenshot inside the
channel that is already authenticated. There is no second request, no
credential handoff, and no publicly reachable URL.

### Feasibility verified, not assumed

The request was to confirm the WordPress→stdio proxy preserves image blocks.
Verified against the running stack, not by reading code:

| Check | Result |
|---|---|
| Server content shape | `class-server.php:115` hardcodes exactly one `type: text` block — **this is the blocker, see below** |
| Daemon HTTP client | `src/Client.php` returns the raw response body; no reshaping, no content filtering |
| Daemon stdio framing | `src/Transport.php` writes one line + `\n`. A 34,209-byte image block round-tripped **byte-identical** with no embedded newlines breaking framing |
| WordPress body handling | `rest_pre_serve_request` not filtering; no truncation observed |
| nginx | `client_max_body_size 1000M` (`conf/nginx/nginx.conf.hbs:12`) — not a constraint |
| Live site | responds to image-bearing payloads correctly today |

**The only incompatibility is on the producing side**, and it is a small one:
`Server::handle_tools_call()` stringifies any array return value into a single
text block. A tool currently *cannot* emit an image block at all. This is
precisely the kind of thing that would have been missed by assuming.

---

## Design

### 1. Multi-block tool results

`Server::handle_tools_call()` must stop assuming one text block. Convention:
a tool may return either

- a scalar / array → today's behaviour, encoded as one text block; or
- an array with a reserved `__mcp_content` key → used verbatim as the
  `content` array.

```php
if ( is_array( $result ) && isset( $result['__mcp_content'] ) ) {
    $content = $result['__mcp_content'];
    unset( $result['__mcp_content'] );
    if ( $result ) {
        $content[] = array( 'type' => 'text',
                            'text' => wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    }
    return JSON_RPC::success_response( $id, array( 'content' => $content ) );
}
```

Every existing tool keeps its current behaviour unchanged, because none
returns `__mcp_content`. Additive and backwards compatible.

### 2. The tool

`agentshell_screenshot` gains an `inline` parameter (default `true`):

- `inline: true` — returns the image block plus a text summary. No file
  written to disk at all.
- `inline: false` — writes to private storage and returns a retrieval URL, for
  an agent that wants a durable reference across turns.

Inline is the default because the feature's purpose is *letting the agent see
what it built*. An agent that wants a persistent artifact can opt out.

### 3. Private storage (only for `inline: false` and retained captures)

`<install-parent>/agentshell-private/{screenshots,tmp}/` — a sibling of the
WordPress install directory, outside the document root.

> **This was wrong at first and live verification caught it.** The original
> design put the store at `wp-content/agentshell-private/`, on the reasoning
> that `wp-content/` sits outside the document root. It does not: on a standard
> install it is *inside* it. Both mitigations that were supposed to cover the
> gap also failed, and this was measured, not reasoned about. With the store
> under `wp-content/`, an `.htaccess` deny in place and the directory at 0700,
> an unauthenticated `GET` of a capture on the live LocalWP site returned
> **200 with the exact PNG bytes**:
>
> - `.htaccess` is inert under nginx, which is what Local, most containers, and
>   most managed hosts run.
> - 0700 owned by the PHP user does not stop a web server running as that same
>   user — which is how Local's nginx runs.
>
> Being outside the document root is the only control that applied. The earlier
> `wp-content` copies are relocated on first authenticated request rather than
> abandoned; leaving them would have kept the defect alive after "fixing" it.
> A regression test asserts the resolved path is not web-reachable, and two
> mutation checks confirm the assertion fails when the guard is removed.

Remaining guards, in order of what actually applies:

1. **Outside the document root.** The control. `doc_root()` is recovered from
   `ABSPATH` and the home URL's path component, so a subdirectory install does
   not mistake the document root for a safe parent; that case falls back to the
   system temp directory. `AGENTSHELL_SCREENSHOT_DIR` overrides both. If a host
   publishes a wider tree than WordPress believes, `ensure_dir()` logs it rather
   than recording captures that anyone can read.
2. A pre-planted symlink at any of these paths is refused rather than followed —
   the same threat as the capture lock. Ownership is checked against the PHP
   euid.
3. 0700 on the directories, 0640 on the files. Stops other local users; does
   nothing against a co-located web server, per above.
4. An `index.php` that returns 404, and an `.htaccess` deny for Apache hosts.
   Kept because they cost nothing; not counted as controls.
5. The retrieval route is the only supported path.

Storage must not assume a fixed key: on a host with an open directory the
filename itself leaks. Filenames become
`substr(hash_hmac('sha256', $id, AGENTSHELL_REST_TOKEN), 0, 40) . '.png'`, with
the id never used verbatim on disk. A capture id is handed back to the agent
and can appear in a transcript or a log, so it must not be a path.

The index stores ids only — `path_for()` derives the filename — so relocating
the store is a directory rename, with no index rewrite.

Inline captures use a separate `tmp/` scratch directory and are deleted as soon
as the bytes are read — on the success path, the refusal path, and the thrown
path. Keeping the two directories apart makes "an inline capture leaves nothing
behind" a property you can check by listing one directory.

### 4. Authenticated retrieval

`GET /wp-json/agentshell-mcp/v1/screenshot/<id>` under
`Transport::NAMESPACE`, so it inherits the existing `Auth` middleware
(application password, or `AGENTSHELL_REST_TOKEN`).

- **Authorisation, not just authentication.** The transport accepts any user
  holding `manage_options`, so acceptance alone is not a permission to read a
  particular capture. Each capture records the actor that took it, and a read
  by anyone else is refused — a second administrator cannot read a capture of a
  draft they cannot edit. A capture with no recorded owner is refused too; an
  unowned capture has no principal to authorise.
- Responds `image/png` with `Cache-Control: private, no-store`.
- Unknown id → 404. Expired id → 410. Wrong actor → 403. A client that gets
  403 knows it is an access problem, not a missing file.

`agentshell_get_screenshot` is the same check over MCP, for a client that has
no use for a URL. The REST route is **not** the primary delivery mechanism —
that distinction is the whole point of the decision above.

### 5. Size limits

An unbounded base64 blob can exhaust the PHP worker's memory and the client's
context. Limits, applied before encoding:

- `AGENTSHELL_SCREENSHOT_MAX_BYTES`, default 5 MB, clamped to 1–25 MB.
- Measured on the PNG in memory before `base64_encode`. Over the limit → the
  capture is **not** returned inline. The tool returns a `captured: false`
  result with `reason: too_large`, naming the limit and offering
  `inline=false`. It is never truncated: a partial PNG handed back as if it
  were the page is worse than an honest refusal.
- No caller can raise the limit per request. It is a wp-config constant, and
  the tool's input schema has no parameter for it.
- Chosen against observed reality: the 9 existing captures are 18–25 KB, so
  5 MB is ~200× headroom. 25 MB ceiling ≈ 33 MB base64 ≈ 8 M tokens, which no
  client can usefully consume.

Base64 inflation is 4/3; the limit is on raw bytes and the encoding step
accounts for it.

### 6. Retention

`AGENTSHELL_SCREENSHOT_TTL_HOURS`, in hours, default 24, clamped to 1–720. A
capture cannot be made permanent by a typo.

- Inline captures write nothing, so there is nothing to expire.
- Stored captures get `expires_at`; an hourly WP-Cron sweep deletes expired
  rows and files. The sweep is idempotent.
- Every read is also checked against `expires_at` — and against the file's
  mtime when the index record is missing — so correctness does not depend on
  cron running.
- `MAX_CAPTURES` (100) bounds disk growth independently of the TTL, so a site
  that captures constantly cannot fill the disk.

### 7. Existing screenshots

Migrate, and cut the public path at the same moment.

On first admin request (lazy, version-stamped — no migration runs at upgrade
time, because activation has no user to attribute captures to):

- `rename()` each `uploads/agentshell-screenshots/*.png` into private storage.
  A rename within one filesystem is atomic, so there is no interval in which
  the bytes exist both publicly and privately.
- Delete everything else that was in that directory and remove the directory,
  so it cannot be listed. A stale copy is never kept as a compatibility
  measure.
- Attribute each migrated capture to the current actor.

**No grace period.** An earlier draft of this design proposed serving the old
public path via an authenticated 302 for one TTL window. That was wrong: the
requirement is that the old URLs stop serving images immediately, and any
window during which a scrapeable URL still returns a draft is exactly the
exposure being fixed. The files were already public, so deleting them buys no
new privacy — but leaving them is a standing exposure that never ends.

Old URLs now 404. Anything that still points at one needs the capture id from
`agentshell_screenshot`, not the old URL.

### 8. Backward compatibility

- **Tool arguments:** `inline` is optional and defaults to the new inline
  behaviour. Callers that pass only `viewport`/`url` keep working.
- **Tool result:** gains `content[]` image block; the existing JSON text
  block is still present with the same fields, plus `inline` and `expires_at`.
  Nothing is removed.
- **`url` field:** returned as `null` for inline captures, since there is no
  file. Consumers must tolerate its absence — this is the one genuine contract
  change and needs calling out in the tool description.
- **Servers without the change:** `Server` ignores an unknown `__mcp_content`
  key only if it is absent; a tool returning it against an unpatched server
  would fall through to text encoding. Guard: only set the key when the
  running `Server` advertises image support, via a version constant.

---

## Tests

`tests/screenshot-privacy-test.php` — 43 executable tests, run standalone with
`php tests/run-screenshot-privacy.php` or as part of `run-all.php`. Every
capture in the suite runs against a fake browser against a temp directory; the
live site is never involved.

Most assertions are about a *refusal*, because that is where the defect was: a
request that must not get bytes. A grep can show a guard exists; only
execution shows it runs on the path an unauthenticated client actually takes.

**Server / protocol**

1. An image tool returns the image block first, then the structured summary.
2. An ordinary text tool is unchanged — one block, byte-identical JSON.
   (The regression guard for every other tool in the plugin.)
3. Malformed blocks are dropped: bad base64, non-image mime type, unknown
   block types.
4. A hostile tool cannot smuggle a `resource` block (with a `uri`) into the
   response — `__mcp_content` is a channel into the protocol, so it is
   validated on the request path, not merely by a validator existing.
5. The reserved key never leaks into the text payload.

**Storage**

6. Captures land outside every web-served directory.
7. Directories are 0700; stored captures are 0640, not world-readable.
8. The filename is not the capture id.
9. A malformed or traversing id cannot address a file.
10. The retention window is clamped (`0` → 1, `99999` → 720) — checked in a
    child process per value, since the constant cannot vary within one.
11. A symlink planted at the storage or screenshot path is refused.
12. A directory owned by another user is refused.

**Authorisation** — the requirements that decided the design

13. The capturing actor can retrieve their own capture.
14. An authenticated *but different* actor is refused.
15. A capture with no recorded owner is refused rather than granted.
16. An unauthenticated request → 401; a bad password → 401.
17. Authenticated but unauthorised → **403, not 401** — the client must be able
    to tell an access problem from a missing capture.
18. A valid request returns the bytes with `Cache-Control: no-store`.
19. An unknown id → 404; an expired one → 410.
20. A capture is not served past its window even when the index is lost (mtime
    fallback).

**Retention**

21. The sweep removes expired captures and keeps live ones.
22. The sweep is idempotent.
23. The store stays bounded at `MAX_CAPTURES`, and the index and directory do
    not disagree.

**Legacy migration**

24. The public upload is gone the moment the capture is migrated — no grace
    period, and the directory is removed rather than left listable.
25. Migration runs once; a second pass neither duplicates nor drops captures.
26. Files the capture loop did not claim are deleted too.
27. A migrated capture with no attributable owner is not readable by anyone.

**The tool**

28. An inline capture returns the image and leaves no file behind.
29. An inline capture decodes to exactly the bytes the browser wrote, and the
    base64 contains no newline (the stdio transport frames on newlines).
30. An over-limit capture is refused explicitly, naming the limit and offering
    `inline=false` — never truncated.
31. The inline limit is clamped and cannot be set per call.
32. A stored capture is owned by the actor who took it: retrievable by them,
    not by a second administrator.
33. A stored capture is retrievable over MCP by its owner.
34. A second actor calling the MCP retrieval tool is refused, and the refused
    read does not delete the capture.
35. A traversing id comes back as a bad-argument error, echoing nothing.

**Logs**

36. Image payloads are redacted from the audit log, and no screenshot argument
    can carry one.
37. A rejected capture carries no payload anywhere.

**Storage location** (added after live verification found the store was readable)

38. A root install resolves the store outside the document root.
39. A subdirectory install does not fall back into the web root.
40. The store in this process is not web-reachable.
41. A store left at the old served path is relocated, not left exposed — the
    capture is readable at the new path with no index rewrite, and the
    world-readable copy is gone.

**Body encoding** (found live: WordPress JSON-encodes REST bodies)

42. The retrieval route registers `rest_pre_serve_request` and emits the exact
    PNG bytes; a JSON-quoted body would start with `"` and never render. The
    handler is one-shot, so a later response is not served raw by accident.

### Mutation testing

A test that cannot fail proves nothing, so each control was mutated in turn and
the suite re-run. 30 of 32 mutations were caught, including every authorisation
control and both of the storage-location guards. The two that survive are
equivalent mutants: removing the `is_link()` check inside `dir_is_safe()`
changes nothing because its only caller, `ensure_dir()`, performs the same check
first; and removing the cross-device source cleanup in `relocate_old_store()`
changes nothing because `rename()` within a filesystem already removes the
source.

### Live verification

The unit suite could not catch two of the defects above, because a stubbed
`WP_REST_Response` returns its data verbatim and no test stands up an HTTP
server. Both were found by running against the live LocalWP site:

- The retrieval route returned **JSON-encoded bytes** — `"…PNG\r\n\u001a\n…"` —
  with a correct `Content-Type: image/png` and a matching `Content-Length`.
  Silent corruption: the size was right and nothing rendered. Fixed with
  `rest_pre_serve_request`, and now covered by test 42.
- The private store was **readable by anyone** at
  `wp-content/agentshell-private/screenshots/`. Fixed by relocating it out of
  the document root; see §3.

Verified end to end through Claude Code itself, not just the daemon: a fresh
`claude -p` session calling `agentshell_screenshot` receives a viewable image
block and reports the rendered dimensions and page content.

---

## What changed from the original design

Two decisions in the draft above were reversed during review, both on security
grounds, and both are worth recording because the original reasoning sounded
reasonable:

1. **Authorisation.** The draft proposed that *any* authenticated AgentShell
   caller may read any capture, arguing that screenshots are "not per-user
   secrets". That conflates authentication with authorisation. The transport
   accepts anyone with `manage_options`, so the draft would have let any
   administrator read a capture of a draft another administrator cannot edit.
   Ownership is now recorded and enforced.

2. **Legacy URLs.** The draft proposed an authenticated 302 keeping the old
   public paths alive for one TTL window. The requirement is that they stop
   serving images immediately; a grace period is a delay before the fix, not a
   graceful one.

---

## Not in scope

- Host-side capture (the availability boundary documented in
  `class-screenshot.php`). Unrelated to confidentiality.
- Revoking URLs already scraped before the fix. They 404 once the migration
  runs, but an attacker who downloaded the bytes still has them; only
  regenerating the captured content actually revokes that.
- CDN/edge caching. `no-store` is the whole policy.