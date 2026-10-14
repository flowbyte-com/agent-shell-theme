# Transaction-aware preview

`agentshell_screenshot(transaction=true)` renders the design staged in the open
transaction, for the caller, without publishing it.

## Why

The `site-from-brief` workflow could stage a design but not *see* it.
`agentshell_preview_transaction` returns a diff, not a render, and the theme
reads the committed option — so the only way to look at staged work was to
publish it and walk it back. That inverts the point of a transaction, and it is
worst exactly where it is most tempting: an agent optimising for a finished
result has the strongest incentive to skip the step that verifies it.

## The shape

A preview URL is a **bearer capability**: whoever holds it can see uncommitted
design. So it is minted server-side, never supplied by the caller, and:

| Property | Value |
|---|---|
| Scope | one transaction (`id` is signed) |
| Owner | the actor who opened it (`actor` is signed) |
| Window | 900s (`expires` is signed **and** enforced at render time) |
| Revocation | implicit — commit and rollback both `delete_option` the row authorisation reads |
| Signing key | `AGENTSHELL_REST_TOKEN`, else `AUTH_SALT`; empty ⇒ refuse, never guess |

Three query arguments: `agentshell_tx`, `agentshell_tx_exp`, `agentshell_tx_key`
(`hash_hmac('sha256', 'agentshell-tx-preview|<id>|<actor>|<expires>', $secret)`).

`functions.php` verifies them on `template_redirect` priority 0 — before any
body is built, so `nocache_headers()` still applies — and
`agentshell_config_for_render()` substitutes the staged config **for that one
request only**.

### Why substitution, not CSS layering

An appended `:root` block cannot express *removal*: a token dropped from the
committed config has to disappear, and override-after-the-fact cannot make a
declaration vanish. Substituting the whole config in memory also means the
preview render is the same code path a commit produces, rather than an
approximation of it. Nothing is written — no option update, no object cache
priming, no filter left attached.

### Why not `effective_config()` in the render path

The obvious implementation — have every render read `effective_config()` while
a transaction is open — is banned by requirement and rightly so: it exposes
uncommitted changes to every visitor, including search engines. The staged
buffer is reachable only through an authorised request. The tests assert this
directly (`ordinary render never consults effective_config()`).

### Why the response is refused when headers are already sent

`nocache_headers()` on a response whose headers already went out is a no-op.
Continuing would send a staged render that a shared cache may store and replay
to visitors — the exact leak the header block exists to prevent. Refusing costs
the agent one retry; allowing it costs a visitor's privacy. So it fails closed.

## Files

| File | Change |
|---|---|
| `agentshell-mcp/includes/class-transactions.php` | `preview_args()`, `authorize_preview()`, `sign_preview()`, `redact_preview_url()`, `strip_preview_args()` |
| `functions.php` | `agentshell_authorize_transaction_preview()`, `agentshell_config_for_render()` |
| `agentshell-mcp/includes/tools/class-screenshot.php` | `transaction` argument, credential minting, URL scrubbing |
| `tests/transaction-preview-test.php` | 39 regressions |

Preserved untouched: the screenshot lock, hard timeout, Chrome sandbox, SSRF
host check, private storage and inline MCP image delivery.

## Credential leakage

- The tool **strips** every preview argument from a caller-supplied `url`
  before use — the audit log records arguments verbatim, so a pasted key would
  otherwise be persisted by being typed into a URL.
- `captured_url` is **redacted**: the key and the profile-preview key are
  removed; the transaction id and expiry stay, because they are useless without
  the signature and make the URL diagnosable.
- The key is minted inside `execute()`, which the audit log never sees — it
  records arguments, not results.

## Verification

**Unit:** 39 regressions in `tests/transaction-preview-test.php`; full suite
**266 passed, 0 failed** across 17 suites.

**Mutation:** 11 mutations of the controls, each weakening exactly one guard.
**11/11 killed, 0 equivalent mutants.** The suite is not merely green — every
guard is load-bearing. (M11, the headers-sent guard, initially survived: it was
a real coverage gap, since nothing drove the `agentshell_test_headers_sent()`
seam. That test now exists.)

### Live evidence (LocalWP, real Chrome, `agentshell.local`)

Staged `--theme-bg: #ff00ff` inside a transaction, captured desktop + mobile,
then rolled back. SHA-256 of the returned PNG bytes:

| Capture | sha256 | bytes |
|---|---|---|
| before, desktop 1280×800 | `18c869e7cf5baf166d42f50e8348dd3c73e450467c52e8fbddee943f8cabfe9d` | 25,574 |
| before, mobile 390×844 | `0ea9df9cfecf24ec6b5ded682c8db9741332075b55517b7e37e3f4e085040510` | — |
| **staged preview, desktop** | `071f364d0de014f0d998472a22a411a7e53b611bbae602abf522f03655c9b508` | 25,750 |
| **staged preview, mobile** | `013172395ae1cf76c29edc2bb9801d779e8fd35205609ce01f3e6be566d90632` | 18,950 |
| after rollback, desktop | `18c869e7cf5baf166d42f50e8348dd3c73e450467c52e8fbddee943f8cabfe9d` | 25,574 |

Desktop before and after rollback are **the same file**: the rollback restored
the render exactly, not approximately. The preview differs in both viewports.
Hashes reproduced exactly across two independent runs.

What a visitor received, over HTTP, anonymously, with the transaction open:

| Moment | Body bytes | sha256 (first 16) |
|---|---|---|
| before staging | 3,806 | `c7e92be84d5043ef` |
| during the open transaction | 3,806 | `c7e92be84d5043ef` |
| after rollback | 3,806 | `c7e92be84d5043ef` |

Byte-identical, and the staged colour appears nowhere in it.

Render isolation, with a credential minted server-side and requested directly:

```
$ curl -sL "$PREVIEW_URL" | grep -o '\-\-theme-accent:[^;]*'
--theme-accent: #ff00ff        # authorised
$ curl -sL http://agentshell.local/ | grep -o '\-\-theme-accent:[^;]*'
--theme-accent: #3b82f6        # ordinary visitor
$ curl -sIL "$PREVIEW_URL" | grep -i 'cache-control\|x-robots'
Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private
X-Robots-Tag: noindex, nofollow, noarchive
```

After rollback, the same call fails rather than degrading:

```
No transaction is open. Call agentshell_begin_transaction before previewing one.
```

Live config hash was identical before staging and after rollback
(`2cd60ef1aa0d8f51`), and the probe pages created during Phase 2 investigation
were deleted — 0 rows, 0 trash, no test content left on the site.

**A note on how the live evidence was first gathered, because it is the more
useful finding:** the first two attempts showed *identical* before and preview
hashes, which looked like a broken preview. Both were probe errors. The first
staged `--as-color-primary`, a variable the theme never consumes; the second
staged `--theme-accent`, which is referenced only by `.skip-link` — parked off
-screen at `left: -9999px` — on a site whose home page is a single default
paragraph. A token can be staged correctly, authorised correctly, and still be
invisible. `--theme-bg` (the `body` background) is the first token on this site
that actually paints, and it does.

## Related

- [`PRODUCT-ISSUES.md`](./PRODUCT-ISSUES.md) — two defects found during this
  work and deliberately not fixed here.
- [`DRAFT-CAPTURE-DESIGN.md`](./DRAFT-CAPTURE-DESIGN.md) — the Phase 2 design
  for screenshotting drafts, proposed and not implemented.