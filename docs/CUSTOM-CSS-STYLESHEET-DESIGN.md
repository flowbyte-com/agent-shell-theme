# Custom CSS as a versioned, cacheable stylesheet — feasibility and implications

> **This is a design record, not a contract.** It records an investigation, an
> option, a recommendation and the evidence for it. Where it disagrees with
> `AGENTS.md`, `AGENTS.md` is right.
>
> Status: **implemented.** The stylesheet is materialised as a content-addressed
> static file and served with a `<link>`; the normative behaviour is
> `AGENTS.md` §6.6. §7 below records what was actually built and where the
> implementation differs from what this document proposed.

---

## 1. The question

The custom CSS limit was raised to 256 KiB (§6.6). That number makes a
question that was academic at 10 KB acute: **should the custom CSS be served
as a versioned, cacheable stylesheet rather than inlined into every page?**

The short answer: yes, it should — and the 256 KiB limit is what makes it
worth doing. The reasoning, the shape of the change and the risks are below.

---

## 2. What happens today

`agentshell_inject_saved_styles()` (theme, `functions.php`) is hooked into
`wp_head` and emits, on every page:

```html
<style id='agentshell-saved-config'>:root { --theme-accent: #ff00ff; … }</style>
<style id='agentshell-custom-css'>…the author's stylesheet…</style>
<style id='agentshell-layout-css'>…</style>
<style id='agentshell-grid-fix'>…</style>
```

The custom CSS therefore travels inside **every HTML response**, in the same
document, with the same cache lifetime as the document.

Three consequences follow, and they get worse as the stylesheet grows:

1. **The bytes are re-transferred on every navigation.** The stylesheet is
   not a separate resource, so the browser cannot cache it independently of
   the HTML. A user who visits ten pages of the site downloads the custom CSS
   ten times. At 256 KiB (uncompressed) that is 2.5 MB of transfer for
   navigation that a single cached file would have cost once.
2. **The HTML is bigger for every page, for every reader.** The stylesheet is
   usually the same for every visitor; only the document differs. Inlining it
   makes every response carry the part that never changes.
3. **The inlined block cannot be revalidated cheaply.** A content hash is
   impossible on an inline block: changing one byte changes the whole
   document's ETag, so a reader whose page changed for an unrelated reason
   re-downloads the stylesheet too.

What the inlined approach does buy: correctness. The stylesheet is
authoritative at render time, there is no second copy that can go stale, and
there is no file to write, rotate, or fail to write.

---

## 3. What the change would be

Serve the custom CSS as a separate resource whose URL changes when its
content changes.

```
GET /wp-content/uploads/agentshell-css/agentshell-custom-<hash>.css
Cache-Control: public, max-age=31536000, immutable
```

with `<hash>` a truncated SHA-256 of the exact bytes served. The page then
carries a link instead of the bytes:

```html
<link rel="stylesheet" href="…/agentshell-custom-<hash>.css" id="agentshell-custom-css">
```

### 3.1 Where the file comes from

The stylesheet lives in `wp_options['agentshell_config']['custom_css']`. A
separate file therefore has to be **materialised**: written to disk from the
committed configuration.

- **On commit.** `agentshell_commit_transaction` (and the theme's REST PUT,
   which commits directly) writes the file. The write must be atomic — a
   temporary file in the same directory, then `rename()` — so a reader can
   never fetch a half-written stylesheet.
- **On rollback.** A rollback that changes the custom CSS must remove or
   re-materialise the file. A rollback that does not touch the custom CSS
   must leave it alone. Today a rollback is a pure `wp_options` operation,
   and the materialised file must not become a second source of truth that
   can disagree with the option row.
- **On activation.** The file is written when the theme is activated and the
   configuration is seeded, so a fresh install is never missing it.

### 3.2 What is *not* materialised

**The staged preview must not be.** A preview renders an uncommitted
configuration; writing that to a public file would publish uncommitted work
to anyone who guesses the URL, and would leave a file that outlives the
transaction. The preview path must keep inlining the staged stylesheet — it
is a single response, it is already marked `no-store`, and it is the one
place where the bytes are wanted inline anyway.

This is the sharpest edge in the whole change: **two rendering paths, two
delivery mechanisms, one source of truth.** The inline path and the file path
must produce byte-identical CSS, or the site renders differently depending on
whether the reader is looking at a preview or at the live site.

### 3.3 The URL must be stable and safe

- The hash is derived from the served bytes, so the URL changes exactly when
  the content changes. The previous file can be deleted; a reader holding the
  old URL gets a 404, which is why the old file must be kept for a while (or
  the URL must be stable per content hash — the same URL for the same
  content, so a returning reader with the old URL still gets the old bytes,
  which is harmless because the old CSS is what their page expects).
- The directory must be outside the document root or inside a directory that
  the server serves with a correct MIME type and **without** executing
  anything. A `.css` extension is inert, but the directory must not be
  writable by the web user in a way that lets an unrelated process drop a
  file the server will execute.
- The file must be written by the same code path that validates the CSS, so
  the bytes on disk are the bytes the inline path would have emitted —
  including the CSS-context escaping (§6.6) and the `</style` hardening.

---

## 4. What it would buy

| Today (inlined) | With a versioned file |
|---|---|
| The stylesheet is re-sent with every page | One transfer, cached for a year |
| The HTML grows by the size of the stylesheet | The HTML grows by one `<link>` |
| A one-byte CSS change re-downloads the CSS for every reader | Only readers with the old URL re-fetch (and they get the old bytes, which is what their page expects) |
| No server-side asset to manage | A file to write, rotate and clean up |

At the 256 KiB limit the second row is the argument: a quarter of a megabyte
of CSS inlined into every page is a quarter of a megabyte of HTML per
response.

---

## 5. What it would cost, and what could go wrong

1. **A second copy of the stylesheet exists.** The file and the option row
   can disagree — after a failed write, after a rollback, after a restore
   from a snapshot, after a direct database edit. The mitigation is to
   derive the file from the option row on every read that matters (or to
   treat a mismatch as a cache miss and regenerate), and to make the
   materialisation idempotent.
2. **A failure to write is now a rendering failure.** Today a missing file
   cannot happen. After the change, a permissions problem on the uploads
   directory means the site serves no custom CSS. The write must therefore
   be checked, and the page must fall back to inlining when the file cannot
   be produced — which means the fallback path has to exist and be tested.
3. **Cache invalidation becomes a real concern.** The immutable,
   one-year cache lifetime is only safe because the URL contains the
   content hash. Any change to that scheme (a stable URL, a shorter
   lifetime, a CDN in front) reintroduces the stale-stylesheet problem.
4. **The transaction preview is unaffected by construction** (§3.2), but the
   *committed* configuration is not visible in a preview's materialised
   file, so a preview must not link to it.
5. **Snapshot and revision restore** change the configuration without going
   through `commit_transaction` in the same way, so they need the same
   materialisation hook.
6. **The commit path gains a side effect.** Today a commit is: validate,
   write one option row, delete the transaction row, write an audit row. It
   would become: validate, write the option row, write a file, delete the
   transaction row, write an audit row. A file write inside the commit
   needs its own failure semantics — does a failed materialisation fail the
   commit, or is it a warning with an inline fallback? That decision has to
   be made explicitly, because "the commit succeeded but the stylesheet was
   not written" is exactly the kind of state that is hard to diagnose.

---

## 6. Recommendation

**Implement it, but as its own change, after the remediation wave closes.**
The reasoning:

- The problem is real and grows with the limit that was just authorised. At
  256 KiB, inlining is not a reasonable steady state.
- The problem is *not* urgent: the current behaviour is correct, only
  inefficient. Nothing is broken for a reader.
- The change touches the render path, the commit path, the rollback path,
  the preview path, the snapshot path and the cache headers. Each of those
  is load-bearing. Doing it as part of a remediation wave, alongside
  unrelated fixes, is how a rendering change becomes unreviewable.

### 6.1 If it is implemented, the shape to aim for

1. **One materialiser, called from one place.** A single function takes the
   committed configuration and returns the URL of the materialised file (or
   `null` when it cannot be produced). The commit path, the restore paths and
   the activation path all call it. No path writes the file itself.
2. **The bytes are identical in both paths.** The inline path and the file
   path must call the *same* escaping function (§6.6). A test must assert
   that the file's bytes and the inline block's bytes are the same string.
3. **Fallback, not failure.** If the file cannot be written, the page inlines
   the stylesheet and the site still renders. A materialisation failure is
   reported (audit row, doctor warning) but does not fail a commit.
4. **The preview never links to the file.** The staged stylesheet is always
   inlined, and the response stays `no-store`.
5. **The hash is over the served bytes**, truncated to something short, and
   the file is written atomically.
6. **The cache headers are explicit**: `immutable` and a long lifetime are
   only correct because the URL is content-addressed.

### 6.2 What to test

- The file's bytes and the inline block's bytes are identical.
- A commit that changes the CSS changes the URL; a commit that does not,
  does not.
- A rollback restores the previous URL, and the previous file is served
  correctly to a reader holding it.
- The preview response inlines the staged stylesheet and links to nothing;
  the staged stylesheet never appears in a file.
- A materialisation failure (an unwritable directory) leaves the site
  rendering correctly with the inline fallback.
- A snapshot restore re-materialises.
- The served response carries the intended cache headers, and the content
  type is `text/css`.

---

## 7. What was built, and where it differs from this proposal

The change was made as its own commit, after the remediation wave closed, as
§6 recommended. The normative description is `AGENTS.md` §6.6; this section
records the differences, so that the reasoning and the implementation can be
compared honestly.

**As proposed, and implemented as proposed:**

- The stylesheet is materialised into the uploads directory under a name that
  carries a truncated SHA-256 of the served bytes, and the page carries a
  `<link>` instead of the bytes.
- The write is atomic: a temporary file in the same directory, then a rename.
- The hash is over the served bytes, so the URL changes exactly when the
  content changes.
- The preview never links to the file. The staged stylesheet is inlined, and
  the preview response stays uncacheable.
- A materialisation failure does not fail a commit. The page falls back to
  inlining, the failure is audited, and the doctor reports it.
- One function produces the bytes: `agentshell_custom_css_bytes()` is called by
  both delivery paths, so the file and the inline block are the same string.

**Different from the proposal, and why:**

1. **The materialisation is driven by one option hook, not by three call
   sites.** §6.1 proposed "one materialiser, called from one place", and named
   the commit path, the restore paths and the activation path as the callers.
   In this codebase every one of those paths writes the same option through
   `update_option()`, and WordPress fires one action for all of them — so the
   caller is that action. The consequence is stronger than the proposal's: a
   path this document did not think of (the theme's own REST endpoint, the
   legacy widget migration, a theme import) is materialised too, because it
   cannot write the configuration without going through the same hook.

2. **The preview guarantee is stronger than "do not materialise".** Because
   `update_option()` is intercepted while a transaction is open — the staged
   value is returned in place of the new one, and core skips the write — the
   action never fires for a staged configuration. The guarantee is therefore
   structural rather than a rule someone has to remember: there is no code
   path that writes a staged stylesheet to a file.

3. **The pruning policy was added.** §3.3 noted that "the previous file can be
   deleted; a reader holding the old URL gets a 404, which is why the old file
   must be kept for a while". Files this feature wrote that are more than
   thirty days old are pruned; the current file and every file that is not
   ours are left alone.

4. **The cache headers are a best effort, not a guarantee.** The `.htaccess`
   sets `Cache-Control: public, max-age=31536000, immutable` and declares
   `text/css`, but only Apache with `mod_headers` reads it. On nginx the file
   is served correctly and is not cached as long as the file is; the URL is
   still content-addressed, so a stale stylesheet is impossible either way.
   The lifetime is an optimisation, and the correctness rests on the hash.

5. **The directory is the uploads directory.** §3.3 asked for "outside the
   document root, or inside a directory that the server serves with a correct
   MIME type and without executing anything". The uploads directory is inside
   the document root and is writable by the web user; the `.htaccess` in the
   stylesheet's own subdirectory disables the PHP engine, removes the CGI
   handlers and forbids directory listing. A site that protects its uploads
   directory with a `deny from all` rule cannot serve the file — the doctor
   says so, and the page inlines the stylesheet instead.

## 8. Related records

- `AGENTS.md` §6.6 — the normative custom stylesheet contract (limit,
  escaping, enforcement, the routine-read shape).
- `AGENTS.md` §6.2 — the transaction lease, whose semantics any commit-path
  change must preserve.
- `docs/CONTENT-TRANSACTIONS-DESIGN.md` — the transactional-content
  investigation; the commit/rollback lifecycle this change would have to join.
