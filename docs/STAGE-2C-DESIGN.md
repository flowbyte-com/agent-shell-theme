# Stage 2c — Transactional editing of published content (design)

**Status: design proposal. Stage 2c is not implemented.**
**Branch context:** `stage/2b-existing-draft-editing` (HEAD `d314ed4`); Stage 2b
fully signed off.
**Date:** 2026-10-16.

This document is a *design record* under `AGENTS.md` §8 category (e): an
architectural investigation, a proposal, the decision taken, and the
evidence for it. It cannot override `AGENTS.md`. Nothing in it is normative
until it is implemented and the relevant section of `AGENTS.md` is updated
to match.

The brief for Stage 2c is in the conversation that produced this document;
the substantive constraints are summarised at the head of each section
below.

---

## 0. The shape in one paragraph

A new tool, **`agentshell_edit_published`**, edits the four supported
fields of an existing *published* post without touching the live row. It
opens a transaction, writes a private *shadow* post that holds the
staged values, journals the original's strong baseline and the shadow's
identity, and refuses until the operator runs **`agentshell_publish_edit`**
(or rolls back). The preview path renders the *original* post's template
and hierarchy with the shadow's fields overlaid under an authenticated
post-preview credential; the public permalink is unchanged. A claim row
in `wp_options`, atomically created and CAS-renewed, prevents a second
AgentShell transaction from staging the same original. Commit takes a
`SELECT ... FOR UPDATE` row lock on the original, runs strong OCC
revalidation against the baseline, then `wp_update_post`, then a
write-and-verify-C that compares against the expected resulting state
(not the baseline), then transitions the journal and writes the success
audit — all inside one short protected SQL transaction. A
post-commit reconciliation (idempotent, retryable, CAS-guarded) deletes
the shadow, invalidates caches, and finalises the claim. A MySQL named
advisory lock scoped to the transaction id is held from publish-edit
entry through the end of reconciliation, and is acquired by rollback,
lease-recovery and reconciler paths too, so a second PHP process cannot
delete a shadow while publication is actually running. The journal op
type, `OP_EDIT_PUBLISHED`, has an eight-state machine that distinguishes
"unpublished staging" from "published to the database, awaiting
reconciliation" from "fully settled" so crash recovery never repeats
a public write.

---

## 1. Decisions taken (with evidence)

### 1.1 Staging model — private shadow post (selected)

A draft of the post is created and held in the transaction; the live
published post is untouched. The design record's investigation in
`docs/CONTENT-TRANSACTIONS-DESIGN.md` §1.7, §1.8 measured that drafts
are invisible to the public front-end (404), public REST (401),
search/collection endpoints (0 matches or 400), and feeds (absent), and
that a `post_status=publish` post on the public URL is reachable by
everyone. The Option C hybrid ("persisted private drafts") is therefore
the only one of the four options that keeps the public render of the
permalink unchanged.

Rejected alternatives:
- **Write-through with an undo journal** — visitors see the staged
  content live. The design record's §1.8 measurements say this is
  exactly the leak the staging model exists to prevent.
- **In-place with public-page bypass** — a render-time hook substitutes
  the pre-image for visitors while a transaction is open. Heavier
  guarantee than the design record makes for any preview credential;
  a misconfigured permalink hook can leak.
- **Defer the write entirely until commit** — the design record's §2
  rejected this because it breaks the screenshot workflow; for Stage 2c
  it also breaks the preview path, which is a load-bearing requirement.

### 1.2 Shadow identity — meta-only binding, journal-enforced (selected)

The shadow is a real `wp_posts` row with `post_status = 'private'` (admin
UI only; invisible to public queries, REST, search and feeds) and
`post_type` equal to the original's. It carries one piece of
corroborating metadata: `_agentshell_shadow_of = <original_id>`. The
*authoritative* binding is the journal op, which records the (original
id, shadow id, tx id, actor) triangle independently. The shadow does
**not** set `post_parent = original_id` because `post_parent` is a real
hierarchical relationship in WordPress and would distort preview
fidelity, permalink context, and ancestor-dependent theme behaviour
(e.g. a child-of query, a breadcrumb that walks parents).

The journal's identity check is the gate; the meta is corroboration.
The verification path treats them as two independent sources of truth
and refuses if they disagree (mismatched, duplicated, or tampered
identity binding).

### 1.3 Preview path — keep the original's identity, overlay only the four staged fields (selected)

**The original-page identity requirement.**
The brief required that the original's identity
be preserved during preview: `get_queried_object_id()`
returns the original's id, `$post->ID` is the
original's id, the original's page-specific
template (`page-{ID}.php`) is selected by the
template loader, the original's hierarchy and
ancestors are intact, the original's post
metadata is read, and dynamic block context
resolves to the original's id. The original is
*publicly readable* (a `post_status=publish` post),
so anonymous requests reach the original with no
404; HMAC-authorised requests reach the original
through the same main query and have only the
four staged fields overlaid. There is no need
to make WordPress treat a private shadow as the
queried post — the original *is* the queried
post, and the four fields are overlaid in a
strictly request-scoped, identity-preserving
manner.

**Why an earlier approach was rejected.** A
prior revision rewrote the main query at
`pre_get_posts` to resolve the shadow instead
of the original. That approach had the correct
*timing* (before the main query executed) but
violated the original-page identity requirement:
the main query's queried post became the
shadow, so `get_queried_object_id()` returned
the shadow's id, `$post->ID` was the shadow's
id, the template loader selected the shadow's
page-specific template (not the original's),
the shadow's hierarchy was used (the shadow has
no parent in the original's tree), and the
shadow's metadata was read. An HMAC-authorised
but anonymous request would also have been
vulnerable to a 404 if the shadow's
`post_status=private` were applied to a
capability check (it is not — the rewrite
bypasses the capability check — but the design
*appeared* to depend on a private post being
publicly reachable, which is a brittle
invitation for an unrelated future change to
break it). The corrected approach is described
below: the main query continues to resolve the
original; the four staged fields are overlaid
via an identity-preserving request-local
`WP_Post` object plus field-level filters
installed at the earliest available point.

**The actual interception points** (each is a
real WordPress action or filter, identified in
core):

- `query_vars` filter (request start) —
  register a public query var
  `agentshell_preview` so WordPress carries
  the request-local flag through the
  `WP_Query`. The credential itself is *not*
  a query var; it is stashed in a per-request
  global so the cached query does not leak it.
- `init` (or `after_setup_theme` /
  `plugins_loaded`) — install the
  field-level overlay filters
  (`the_title`, `the_content`, `the_excerpt`,
  and the permalink family:
  `pre_post_link`, `post_link`,
  `post_type_link`, `page_link`,
  `_get_page_link`, `the_permalink`)
  unconditionally. The filter callbacks are
  *request-scoped no-ops* unless the
  per-request global is set by a verified
  HMAC; installation at `init` is required
  so the filters fire for any code path that
  reads the post via the persistent
  `wp_object_cache` (e.g. a `get_post()`
  call in a block render that runs before
  the template loader). The filter callbacks
  check the per-request global and the
  current post id; if both match the bound
  `(original_id, shadow_id, tx_id, actor)`
  tuple, they overlay the four fields from
  the shadow. If either fails, the filter
  passes through unchanged.
- `pre_get_posts` action (the earliest
  point where the main query is mutable,
  before it executes) — verify the
  credential against the journal op's
  (original, shadow, tx, actor) triangle.
  The callback is registered to run for the
  main query only (`$query->is_main_query()`
  is the first check). On a valid
  credential, the callback (a) stashes the
  verified `(original_id, shadow_id, tx_id,
  actor)` in the per-request global, and
  (b) registers a `the_posts` filter (via
  `add_filter( 'the_posts', $callback, 10,
  2 )`) on the current `$query` instance
  (the filter is per-instance because
  `$query` is passed by reference, but the
  filter is installed globally and the
  callback checks the per-request global and
  the post id). The `the_posts` filter
  callback constructs a fresh
  `WP_Post` instance whose `ID`,
  `post_type`, `post_status`, `post_author`,
  `post_parent`, `post_date`,
  `post_date_gmt`, `post_modified`,
  `post_modified_gmt`,
  `post_content_filtered`, `post_mime_type`,
  `comment_status`, `ping_status`,
  `menu_order`, `post_password`, and
  `ancestors` come from the *original* (so
  `get_queried_object_id()` returns the
  original's id, `$post->ID` is the
  original's id, the page-specific
  template is the original's
  `page-{ID}.php`, the hierarchy is the
  original's, and the metadata is the
  original's) and whose `post_title`,
  `post_content`, `post_excerpt`, and
  `post_name` come from the *shadow*. The
  substituted object is **not** written
  to `wp_object_cache`: it lives in
  `$wp_query->posts[0]` for the lifetime
  of the request only, and the persistent
  post cache is populated by
  `WP_Query::get_posts()`'s own
  `update_post_caches()` call, which
  reads the original's row by id and
  caches it (the substituted object is
  *not* consulted by the cache update).
  **The main query's queried post is the
  original**; the substituted object is a
  *view* on the original with the four
  staged fields swapped in. The
  pre-existing
  `posts_results`/`the_posts` overlay
  approach (installing the filters on
  `template_redirect`) is rejected as
  too late — `template_redirect` fires
  *after* `WP_Query::get_posts()` has
  already returned, so installing
  filters there cannot retroactively
  overlay a query result that has
  already been produced. The
  `pre_get_posts`-time installation is
  the earliest available point that
  fires *before* the main query
  executes and *before* the
  `the_posts` filter is applied to the
  result.
- `template_redirect` priority 1 (after
  the main query has resolved, before
  the template loads) — apply
  response headers (`X-Robots-Tag:
  noindex, nofollow`, `Cache-Control:
  no-store, private, must-revalidate`,
  `Pragma: no-cache`, suppressed
  `Last-Modified`) and disable
  `redirect_canonical` for the
  request's lifetime (so a request
  that hits a permalink different
  from the original's canonical URL
  does not 301 to a different URL
  mid-preview; the preview URL is the
  original's permalink, so this is
  only a defence against a non-canonical
  request to the original's URL with
  the credential attached). WordPress's
  canonical-redirect callback is
  `redirect_canonical`, not
  `wp_canonical_redirect`.

**Why the four staged fields require the
field-level filters in addition to the
substituted `WP_Post`.** WordPress's standard
template tags do not read `$post` directly for
field values; they call `get_post( $post->ID )`,
which reads the persistent `wp_object_cache`.
The cache was populated by the main query with
the *original*'s row (because the main query
resolved the original). A substituted
`WP_Post` in `$wp_query->posts[0]` with the
shadow's `post_title` is *not* what
`the_title()` reads; `the_title()` calls
`get_the_title()`, which calls `get_post()`,
which returns the cached original with the
original's `post_title`. The base value the
`the_title` filter sees is therefore the
original's `post_title`, and the filter
overlays the shadow's `post_title`. The same
is true of `the_content`, `the_excerpt`, and
`get_permalink()` (which calls
`apply_filters( 'pre_post_link', $permalink,
$post, ... )` with the base value computed
from `$post->post_name`, the original's
slug). The field-level filters are
*load-bearing*; the substituted `WP_Post` in
the result array is a complementary view for
direct property access (block themes that read
`$post->post_title` directly, custom block
templates) and for the *identity* fields (id,
type, status, parent, page template, ancestors,
metadata) that the persistent cache and the
main query both resolve to the original. The
two mechanisms together give the brief's
contract: identity preserved, four fields
overlaid, persistent cache not poisoned.

**The substituted `WP_Post` is never written
to `wp_object_cache`.** The callback
constructs the object locally in the filter
closure; the closure returns the array; the
array is held by `$wp_query->posts` for the
lifetime of the request only. There is no
`wp_cache_set( 'post/<id>', $substituted,
'posts' )` call. The persistent cache holds
the original's row (because the main query
fetched the original and `update_post_caches()`
read the original's row by id and cached it).
A subsequent `get_post( $original_id )` call
in the same request (e.g. from a third-party
plugin's `save_post` filter of *another*
post, from a widget that calls `get_post`
directly, from a `get_post_meta(
$original_id, ... )` call inside a block)
returns the original's cached value, not the
substituted object. The overlay does not
claim to override `get_post`, and the docs
do not promise it. This is the documented
boundary: the four supported fields are
overlaid for the main query's template
render; any other value in the response is
the original's live value, not the staged
value.

**What is supported and what is not** (stated
explicitly because the brief required it):

- **Supported.** Single-post and single-page
  templates that read the post via the main
  query (`$wp_query->posts[0]`, `$post` global,
  `the_title()`, `the_content()`, `the_excerpt()`,
  `get_permalink()`). Block themes that read
  `WP_Block_Bindings_Source::get_value` for the
  supported fields, or that consume `$post` from
  the global, are covered. Standard classic themes
  (Twenty Twenty-One, Twenty Twenty-Four) are
  covered. A child theme that builds its own
  template tags against `$post` is covered.
  Original page-specific templates
  (`page-{ID}.php` where `ID` is the original's
  id) are selected by the template loader, because
  the queried post's id is the original's id.
  Page ancestry (`get_ancestors()`,
  `get_page_children()`) is the original's, because
  `$post->post_parent` is the original's parent.
  Post metadata (`get_post_meta(
  $original_id, ... )`) is the original's, because
  `get_post($original_id)` returns the cached
  original. Dynamic blocks that call
  `get_post($post->ID)` and `get_post_meta(
  $post->ID, ... )` see the original's values; the
  four staged fields are overlaid through the
  field-level filters that fire inside the same
  render. The front page, the author archive, the
  category archive, and the search results page
  are *not* preview paths — they are not the
  staging preview surface and the overlay does
  not target them.
- **Not supported (and not promised).** Direct
  `get_post( $original_id )` calls from a third-
  party plugin's `save_post` filter of *another*
  post. A search indexer's direct `SELECT` from
  `wp_posts` for the original id. An analytics
  plugin's `wp_posts` read. A `get_field(
  $original_id )` call inside a third-party
  plugin's filter. These bypass the template
  loader and the main query, and the overlay
  does not intercept them — the persistent post
  cache holds the original's row by construction
  (because the main query resolved the original),
  so a third-party `get_post($original_id)` call
  returns the original. The four supported fields
  are the public contract for the preview; any
  other value in the response is documented as
  the original's live value, not the staged
  value. This is the same fidelity limitation
  §6.2 names; it is not a defect of the preview
  path, it is the boundary of what the
  identity-preserving overlay can intercept.
- **Not supported (and explicitly out of scope).**
  The WP-CLI `wp post get` command, the JSON
  REST API for the post (`/wp-json/wp/v2/posts/<id>`),
  the `?p=<id>` shortlink redirect, the `?preview=true`
  WordPress built-in preview (which is for
  unpublished drafts and would bypass our
  overlay), RSS / Atom feeds, the `wp_sitemap`
  renderer, the search result page, the
  WordPress admin edit page, and any custom
  REST endpoint that reads the post directly.
  These are documented in §6.2.

**The preview HTTP request path** (corrected
for the filter-based overlay and the original-
page identity requirement):

1. The preview URL is the *original's* permalink
   (the URL the public sees), with a query
   parameter that carries the credential (the
   existing `Post_Preview` HMAC) and a stable
   request-local flag (`agentshell_preview=1`).
   The `Post_Preview` credential is bound to
   (original id, shadow id, transaction id,
   actor, expires); the credential's signature
   includes all five so a credential minted
   for one (original, shadow, tx) cannot be
   used to preview a different combination.
   The preview URL is the original's URL
   because the main query continues to resolve
   the original — there is no permalink
   substitution.
2. **At `query_vars` filter (request start,
   before any query runs).** Register a
   public query var `agentshell_preview` so
   WordPress carries it through `WP_Query`.
   Also stash the raw credential in a per-
   request global
   (`$agentshell_preview_credential`) — query
   vars are visible to `WP_Query` and to
   caching, so the credential itself is *not*
   a query var.
3. **At `init` (before any query runs).** The
   field-level overlay filters (`the_title`,
   `the_content`, `the_excerpt`, and the
   permalink family: `pre_post_link`,
   `post_link`, `post_type_link`, `page_link`,
   `_get_page_link`, `the_permalink`) are
   installed unconditionally. The callbacks
   are *request-scoped no-ops* unless the
   per-request global is set by a verified
   HMAC at the `pre_get_posts` step below.
   Installation at `init` is required so the
   filters fire for any code path that reads
   the post via the persistent
   `wp_object_cache` (e.g. a `get_post()`
   call in a block render that runs before
   the template loader, a `get_post_meta(
   $original_id, ... )` call inside a
   dynamic block). The filter callbacks
   check the per-request global and the
   current post id; if both match the bound
   `(original_id, shadow_id, tx_id, actor)`
   tuple, they overlay the four fields from
   the shadow's `wp_posts` row (read by
   `get_post($shadow_id)` once per request,
   cached in the per-request global). If
   either check fails, the filter passes
   through unchanged.
4. **At `pre_get_posts` action (the earliest
   point where the main query is mutable,
   before it executes).** The callback
   verifies the credential against the
   journal: the (original, shadow, tx,
   actor) triangle is read from the journal
   op; if the op is missing, in `compensated`
   state, in `conflict` state, in
   `publication_settled` state, or in any
   other state that means "this staging is
   not currently previewable", the
   credential is refused. A refused
   credential **does not** install the
   `the_posts` filter; the field-level
   filters are no-ops because the per-
   request global was never set; the
   request continues to render the
   original post with its live values. **On
   a valid credential, the callback (a)
   stashes the verified `(original_id,
   shadow_id, tx_id, actor)` and the
   shadow row in the per-request global,
   and (b) registers a `the_posts` filter
   callback via `add_filter( 'the_posts',
   $callback, 10, 2 )`.** The main query
   is **not** rewritten; it continues to
   resolve the original (because the
   preview URL is the original's permalink
   and the original is a `post_status=publish`
   post that the main query resolves
   naturally). When `WP_Query::get_posts()`
   returns the original's row, the
   `the_posts` filter substitutes the
   result's `WP_Post` object with a fresh
   instance whose identity fields (`ID`,
   `post_type`, `post_status`, `post_author`,
   `post_parent`, `post_date`,
   `post_date_gmt`, `post_modified`,
   `post_modified_gmt`,
   `post_content_filtered`, `post_mime_type`,
   `comment_status`, `ping_status`,
   `menu_order`, `post_password`,
   `ancestors`) come from the *original*
   and whose four staged fields
   (`post_title`, `post_content`,
   `post_excerpt`, `post_name`) come from
   the *shadow*. The substituted object
   lives in `$wp_query->posts[0]` for the
   lifetime of the request only; it is
   **not** written to `wp_object_cache`.
   The persistent post cache is populated
   by `WP_Query::get_posts()`'s own
   `update_post_caches()` call with the
   *original*'s row, keyed by the
   original's id. The identity of the
   queried post is the original's: the
   `the_posts` filter does not change the
   id, type, status, parent, ancestors,
   or metadata, only the four staged
   fields.
5. **At `template_redirect` priority 1
   (after the main query has resolved,
   before the template loads).** Apply
   response headers for an authorised
   preview: `X-Robots-Tag: noindex,
   nofollow`, `Cache-Control: no-store,
   private, must-revalidate`, `Pragma:
   no-cache`, and suppress the original's
   `Last-Modified` header. Canonical
   redirects that would otherwise redirect
   the original's permalink to its
   "real" canonical target (a different
   URL than the request's URL) are
   disabled for the authorised preview
   request by
   `remove_filter( 'template_redirect',
   'redirect_canonical' )` for the
   request's lifetime. (WordPress's
   canonical-redirect callback is
   `redirect_canonical`, not
   `wp_canonical_redirect`.)
6. **The four supported fields are the
   shadow's own values, overlaid in two
   complementary ways.** First, the
   substituted `WP_Post` in
   `$wp_query->posts[0]` carries the
   shadow's `post_title`, `post_content`,
   `post_excerpt`, and `post_name` for
   direct property access by block themes
   and custom templates. Second, the
   field-level filters (`the_title`,
   `the_content`, `the_excerpt`, the
   permalink family) overlay the same
   four fields for code paths that read
   through `get_post()` (which returns
   the cached original) — these are the
   standard template tags
   (`the_title()`, `the_content()`,
   `the_excerpt()`, `get_permalink()`).
   **The permalink filters overlay the
   staged slug representation in the
   response's permalink links** (the
   `<link rel="canonical">`, the
   `get_permalink()` output in the
   navigation, the post URL in the
   JSON-LD structured data) by reading
   the shadow's `post_name` from the
   per-request global. The original's
   permalink was the URL the request
   hit; the response's permalink
   *links* show the post-publication
   permalink (the shadow's slug), so
   the response is consistent with what
   the visitor will see after publish.
7. **The WordPress global post cache is
   *not* mutated by the overlay path.**
   The persistent post cache holds the
   *original*'s row, keyed by the
   original's id. The substituted
   `WP_Post` is constructed in the
   `the_posts` filter closure and held
   in `$wp_query->posts[0]` only; it
   is not written to `wp_object_cache`.
   A second `get_post( $original_id )`
   call in the same request — e.g. from
   a third-party plugin's `save_post`
   filter running for a *different*
   post, or from a widget that calls
   `get_post` directly — returns the
   original's cached value, not the
   shadow's. The overlay does not claim
   to override `get_post`, and the docs
   do not promise it. The shadow's row
   is fetched by the per-request global
   (read once, cached for the request)
   for the field-level filters; it is
   fetched by `get_post($shadow_id)`
   from the persistent cache, which was
   populated by `WP_Query::get_posts()`
   if any code path (a secondary query,
   a `clean_post_cache` re-population)
   caused the shadow's row to be
   cached, or by a direct `get_post()`
   otherwise.

**The spike** (the brief required a defined spike
to validate the overlay before building the
complete feature): a minimal
`tests/preview-overlay-spike.php` against
the disposable test bed. The spike:

- Stages a `OP_EDIT_PUBLISHED` op with a
  known staged payload (title "SPIKE
  TITLE", content "SPIKE BODY", excerpt
  "SPIKE EXCERPT", slug "spike-slug").
- Mints a `Post_Preview` credential
  bound to (original, shadow, tx, actor,
  exp).
- Issues an HTTP request to the
  *original's* permalink with the
  credential.
- Asserts the response body contains
  "SPIKE TITLE" in the rendered
  `<h1>` / `entry-title` (classic theme
  shape) and in the `<title>` (block
  theme shape).
- Asserts the response body contains
  "SPIKE BODY" in the rendered content
  area.
- Asserts the response body contains
  "SPIKE EXCERPT" if the theme prints
  the excerpt.
- Asserts the permalink in the
  response's `<link rel="canonical">` (or
  the canonical redirect filter's absence
  assertion) corresponds to the staged
  slug — proving the shadow's `post_name`
  is what `get_permalink()` returns
  through the field-level filters.
- Asserts the response carries
  `X-Robots-Tag: noindex` and the no-
  store cache headers.
- Asserts **original identity is
  preserved**: a test-only `init` callback
  fires during the request and reads
  `get_queried_object_id()` — the value
  is the *original*'s id, not the
  shadow's. The callback also reads
  `$post->ID` (set by the template
  loader from `$wp_query->posts[0]`) —
  it is the original's id. A separate
  callback reads `get_page_template()`
  (selected by the template loader
  based on the queried post's id and
  type) — the result is the original's
  page-specific template, e.g.
  `page-{original_id}.php`. A callback
  reading `get_ancestors($post->ID)`
  returns the original's ancestors. A
  callback that does
  `get_post_meta($post->ID, '_wp_page_template',
  true)` returns the original's page-
  template selection. These are the
  brief's identity invariants: identity
  is the original's, only the four
  fields are overlaid.
- Asserts **dynamic blocks see the
  original's identity**: a registered
  test block reads `$post->ID` (the
  substituted object's `ID`, which is
  the original's id) and calls
  `get_post_meta($post->ID, ...)` — the
  metadata returned is the original's
  metadata. The block's content output
  reads `$post->post_title` directly,
  which is the shadow's `post_title`
  (because the substituted object's
  `post_title` is the shadow's), proving
  the field-level overlay and the
  identity-preserving object both work
  for the same block render.
- Asserts a second HTTP request to the
  same URL *without* the credential
  returns the original's live values
  (the `pre_get_posts` callback's
  verification finds no credential; the
  per-request global is not set; the
  field-level filters are no-ops; the
  `the_posts` filter is not installed;
  the template loader receives the
  original's row unaltered).
- Asserts `wp_object_cache` is unchanged
  for the original post: the spike reads
  the cache after the response,
  confirms the original's cached row
  is intact (the persistent cache holds
  the original because the main query
  resolved the original, not the
  shadow). The shadow's cache may be
  populated by a direct `get_post(
  $shadow_id )` call in the field-level
  filter callbacks — this is fine
  because the shadow's row is keyed by
  the shadow's id, not the original's,
  and the original's row is unaffected.
- Asserts a third-party `get_post(
  $original_id )` call inside a stub
  filter returns the original's value,
  not the shadow's (the documented
  boundary — the overlay is identity-
  preserving for the original and
  field-only for the four supported
  fields; the persistent cache is not
  poisoned).
- Asserts the **anonymous 404 boundary
  does not arise**: an anonymous request
  to the original's permalink without
  the credential returns the original's
  page (200 OK), not a 404 — the main
  query resolves the original
  (a `post_status=publish` post), the
  template loader renders it. The brief
  required this: the main query is the
  original, the original is publicly
  readable, no 404 path exists.

The spike lives at
`tests/preview-overlay-spike.php` and is
run once on the disposable test bed
before Commit 2 begins. The spike's
output is the evidence that the
identity-preserving overlay delivers a
coherent preview of the four supported
fields and that the documented boundary
holds. If the spike fails, Commit 2's
design is revised before implementation;
the spike's negative result is not silently
worked around. The spike is
*disposable*: a successful spike result
is recorded as evidence, and the spike
itself is not part of the production
test suite.

**Preview URL behaviour** (the brief
required explicit definition): the
preview URL is the *original's*
permalink, plus the credential as a
query parameter. A `post_name` change
in the staged payload does **not**
change the original's permalink during
the preview — the preview hits the
*original* URL, not a synthetic URL
computed from the staged slug. The
*response body's* permalink links (the
`<link rel="canonical">` if the theme
prints one, the `get_permalink()`
output in the navigation, the post
URL in the JSON-LD structured data)
reflect the staged slug. **The old
permalink (the original's `post_name`)
does not preview the staged content**
during staging; it serves the original
post's live values to anyone who
visits it. After publication, the
original's `post_name` is updated to
the staged slug, and WordPress's
`wp_old_slug_redirect` redirect logic
sends the old permalink to the new
one. The brief asked us to name this
limitation; it is a WordPress
permalink-update side effect, not an
AgentShell decision.

**Fidelity constraints** (from the
brief):

- **Original-page identity is
  preserved.** The main query continues
  to resolve the *original* (a
  `post_status=publish` post that the
  query naturally resolves from the
  request URL). The original's identity
  fields — `ID`, `post_type`, `post_status`,
  `post_author`, `post_parent`,
  `post_date`, `post_date_gmt`,
  `post_modified`, `post_modified_gmt`,
  `post_content_filtered`, `post_mime_type`,
  `comment_status`, `ping_status`,
  `menu_order`, `post_password` — are
  the original's, not the shadow's.
  `get_queried_object_id()` returns the
  original's id. `$post->ID` is the
  original's id. The template loader
  reads `$post->ID` and `$post->post_type`
  to select the page-specific template
  (`page-{ID}.php`), and the original's
  page-specific template is selected.
  Page ancestry is the original's
  (`get_ancestors($post->ID)` returns
  the original's chain of ancestors
  because `$post->post_parent` is the
  original's parent). Post metadata is
  the original's (`get_post_meta(
  $post->ID, ... )` reads the original's
  metadata). Dynamic block context
  resolves to the original's id
  (because `$post->ID` is the original's
  id, and blocks that call
  `get_post($block->context['postId'])` or
  `get_post_meta($block->context['postId'], ...)`
  with the post's id see the original's
  data).
- **The four supported fields are the
  shadow's own values, overlaid in two
  complementary ways.** First, the
  substituted `WP_Post` in
  `$wp_query->posts[0]` carries the
  shadow's `post_title`, `post_content`,
  `post_excerpt`, and `post_name` for
  direct property access (block themes
  and custom templates that read
  `$post->post_title` directly). Second,
  the field-level filters (`the_title`,
  `the_content`, `the_excerpt`, the
  permalink family) overlay the same
  four fields for code paths that read
  through `get_post()` — the standard
  template tags (`the_title()`,
  `the_content()`, `the_excerpt()`,
  `get_permalink()`). Both mechanisms
  read the shadow row from a per-request
  global (fetched once at HMAC
  verification time). The four fields
  are the *only* difference between
  the substituted object and the
  original; every other field is the
  original's.
- **Unauthorised requests must not
  fall back to exposing the shadow.**
  If credential verification fails (or
  no credential is supplied), the
  `pre_get_posts` callback's
  verification refuses; the per-request
  global is not set; the field-level
  filters are no-ops; the `the_posts`
  filter is not installed. The
  response is the original's live
  render. The shadow post is not
  reachable via the public permalink,
  via the front-end search, via the
  REST API, or via the feeds. The
  WordPress admin's edit page for the
  shadow id is reachable by a user
  with `read_post` on a `private` post;
  the brief required us to name this,
  and §9b does.
- **Anonymous 404 boundary does not
  arise.** Because the main query is
  the original (a `post_status=publish`
  post, publicly readable), an
  anonymous request to the original's
  permalink returns the original's
  page with HTTP 200. The HMAC
  credential is not consulted by
  WordPress's capability system; the
  HMAC is verified by the AgentShell
  overlay, which gates the field-level
  filter installation on a valid
  credential. A missing or invalid
  credential means the overlay is not
  installed; the response is the
  original's live render with no 404.

### 1.4 Commit boundary — short protected SQL transaction + post-commit reconciliation (selected)

The commit is **not** a single statement, but it is a short held
lock. The shape is the second of the two shapes the brief
required us to investigate and report: `SELECT ... FOR UPDATE`
to take the row lock, then strong OCC revalidation, then
`wp_update_post`, then a write-and-verify-C that compares the
row against the *expected resulting state* (the four supported
fields we wrote, plus the preserved fields we did not write,
plus the WordPress-generated `post_modified_gmt` that we read
back from the row rather than predicting). No separate
"conditional publication CAS" step is run after `wp_update_post`;
the row lock plus the write-and-verify-C check are the entire
publication-side guarantee, and a redundant `post_modified_gmt`-
only CAS would either always pass (the row is C) or always fail
(`post_modified_gmt` is no longer A's, by construction).

**Why `SELECT ... FOR UPDATE` and not the implicit row lock
from `wp_update_post`.** The brief required us to report this
choice. The two candidate shapes are:

- *Single conditional `UPDATE`*. `UPDATE wp_posts SET
  post_title=?, post_content=?, post_excerpt=?, post_name=?,
  post_modified_gmt=NOW() WHERE ID=? AND post_type=? AND
  post_status=? AND post_name=? AND post_title=? AND
  post_content=? AND post_excerpt=? AND post_date_gmt=? AND
  post_modified_gmt=?` is the atomic write. Zero rows
  affected = concurrent edit = refuse. **The cost:** this
  bypasses `wp_update_post` and therefore bypasses the
  `save_post` / `wp_after_insert_post` / `transition_post_status`
  / `post_updated` hook chain, the revision-system write, the
  term/taxonomy sync, and the post-cache invalidation. None
  of these can be reproduced from a raw `UPDATE`; the only
  way to invoke them is `wp_update_post`. A site that
  depends on any of these (and almost every production site
  does) would silently break.
- *`SELECT ... FOR UPDATE` + strong revalidation +
  `wp_update_post`*. The row lock is taken at step 3; the
  `wp_update_post` runs at step 4 with the lock held; the
  write-and-verify-C at step 5 catches an external writer's
  lost race. **The cost:** the lock is held for the
  duration of the publication transaction, including the
  `save_post` hook chain, which on a heavy plugin can be
  hundreds of milliseconds. This is acceptable for the
  publication path (commits are rare and bounded), and is
  the only shape that preserves the WordPress update
  machinery.

**Selected shape: `SELECT ... FOR UPDATE` to serialise against
concurrent writers, then a write-and-verify-C sequence.**

The corrected sequence:

1. **Advisory lock acquisition.** Before step 2,
   `Recovery_Advisory_Lock::acquire( 'agentshell_publish_lock_<tx_id_hash>' )`
   acquires a MySQL named advisory lock scoped to the
   transaction id (not the original id — see §1.6a for why).
   The lock is held from this point through the end of
   reconciliation, released in `finally`. Recovery paths
   (rollback, lease recovery, reconciler) acquire the same
   lock before touching the op or the shadow, so a second
   PHP process cannot delete a shadow while publication is
   actually running. The lock is connection-scoped; a
   `KILL -9` of the publishing process releases it via the
   server's connection-close handler, exactly the property
   the per-post lock in §1.6a has.
2. `Transaction_Manager` validates ownership, expiry, and
   that the op is in `staged` state.
3. `Content_Write_Guard::run()` opens a real InnoDB
   transaction (existing primitive).
4. **Row lock + baseline-A revalidate** (inside the
   transaction). `SELECT post_type, post_status, post_name,
   post_title, post_content, post_excerpt, post_date_gmt,
   post_modified_gmt FROM wp_posts WHERE ID = ? FOR
   UPDATE` is compared field-for-field against the op's
   `before` baseline A. The `FOR UPDATE` takes an
   exclusive InnoDB row lock; any concurrent
   `wp_update_post` on the same row blocks until our
   COMMIT. A divergence between the live row and A means
   an external writer committed since the baseline was
   captured; **ROLLBACK** the SQL transaction, then in a
   *separate, post-rollback* write (see audit-durability
   below) audit `publication_refused_baseline_drift` and
   transition the op to `conflict`. The
   `post_content_sha256` field stored in A is the fast
   pre-check (compare hashes first, full content string
   only on hash mismatch) and the second line of defence
   against engine string normalisation. The same-second
   race the brief asked about is addressed by including
   `post_content` (not just `post_content_sha256` or
   `post_modified_gmt`) in the compare: a same-second
   edit that changed the content fails the content
   comparison, not the timestamp comparison.
5. **`wp_update_post( $original, $shadow_fields )`** issues
   WordPress's standard update. WordPress's
   `wp_update_post` is not a single SQL statement; it is
   a PHP function that performs a sequence of operations
   — main `wp_posts` `UPDATE` for the supported fields,
   `wp_posts` `INSERT` for one revision row, taxonomy
   term sync, post-meta upserts for the call's
   `meta_input`, and `clean_post_cache()` for the
   in-process object cache — and fires the
   `save_post` / `wp_after_insert_post` /
   `transition_post_status` / `post_updated` /
   `wp_save_post_revision` hook chain. The database
   writes inside `wp_update_post` *join* the open InnoDB
   transaction (same `$wpdb` connection, same depth
   counter, no nested `START TRANSACTION`): they
   COMMIT together with our transaction, or ROLLBACK
   together on our transaction's failure. The hook
   chain is *not* part of the database transaction —
   the hooks are PHP callbacks that may issue their
   own SQL (which joins our transaction) or may
   perform external work (`wp_remote_post`,
   `wp_schedule_event`, an email send, a third-party
   API call). The exclusive row lock from step 3 is
   held throughout, so an external `wp_update_post` on
   the same row blocks until our COMMIT.
6. **Write-and-verify-C** (still inside the
   transaction). Re-read the row:
   `SELECT post_type, post_status, post_name,
   post_title, post_content, post_excerpt,
   post_date_gmt, post_modified_gmt FROM wp_posts
   WHERE ID = ?` (no `FOR UPDATE` — the row is
   locked, we don't need it). Construct the
   *expected resulting state* C from:

   - the four supported fields we just wrote
     (from the shadow's `after`);
   - the preserved fields we did not write
     (from the original's baseline: `post_type`,
     `post_status`, `post_date_gmt`,
     `post_content_filtered`, `post_mime_type`,
     `post_author`, `post_parent`, `comment_status`,
     `ping_status`, `menu_order`, `post_password`);
   - the WordPress-generated `post_modified_gmt` —
     read from the re-read row, **not predicted**.
     The expected `post_modified_gmt` is the
     `post_modified_gmt` we just observed; we
     compare the row to itself, except for the
     four supported fields, which must equal the
     shadow's `after`.

   **The row-lock contract and what divergence
   means.** The exclusive InnoDB row lock from
   step 4 is held throughout `wp_update_post` and
   the hook chain. No external writer's
   `wp_update_post` can have committed between
   our write and our re-read. The only mechanism
   that could have changed the row during the
   locked interval is a WordPress `save_post` /
   `wp_after_insert_post` /
   `transition_post_status` / `post_updated` hook
   (a PHP callback that fires during
   `wp_update_post` and may issue its own SQL
   writes, which join our transaction), or a
   WordPress-internal transformation within
   `wp_update_post` itself (the
   `post_modified` and `post_modified_gmt`
   columns that `wp_update_post` sets to the
   current time at the moment of the update).
   External writes that happened *before* the
   lock was acquired are caught at step 4 (the
   baseline-A revalidate) as
   `publication_refused_baseline_drift`; a
   post-write divergence at step 6 cannot be
   attributed to an independent external writer
   because the lock prevents one. This is the
   load-bearing fact for the classification
   below: every divergence at step 6 is
   either a hook side effect or a
   WordPress-internal transformation, never an
   external writer.

   **The allowlist-based verification rule.**
   Three categories of `wp_posts` column, three
   verification rules:

   1. **The four supported fields** —
      `post_title`, `post_content`, `post_excerpt`,
      `post_name`. The post-write value must
      equal the shadow's `after` value. A
      divergence is **always a refusal**:
      `publication_refused_hook_overwrote_supported_field`.
      The supported fields are the operator's
      contract with the database; a hook that
      overwrites one of them has broken the
      contract. The brief is explicit: "a hook
      that changes protected original fields must
      not be accepted as successful publication
      merely because the change originated in a
      hook." (SQL ROLLBACK; post-rollback
      refusal audit in a separate transaction;
      op to `conflict`; the original is
      unchanged.)

   2. **Protected preserved fields** — the
      named set of fields whose post-write
      value must equal the baseline A's value.
      The set is: `post_status`, `post_author`,
      `post_parent`, `post_type`, `post_date`,
      `post_date_gmt`, `post_mime_type`,
      `comment_status`, `ping_status`,
      `menu_order`, `post_password`,
      `post_content_filtered`, `comment_count`,
      `to_ping`, `pinged`, `guid`. These are
      the fields that `wp_update_post` does not
      itself modify (its only self-mutation is
      the two timestamps below), and a
      divergence in any one of them is a
      hook-induced change. The brief is
      explicit: a hook that changes a protected
      field must not be accepted as successful
      publication. A divergence in any protected
      preserved field is **always a refusal**:
      `publication_refused_hook_changed_protected_preserved_field`,
      with the actual field name, baseline
      value, and post-write value in the
      refusal audit. (SQL ROLLBACK;
      post-rollback refusal audit in a separate
      transaction; op to `conflict`; the
      original is unchanged.)

   3. **Allowlisted housekeeping fields** —
      `post_modified` and `post_modified_gmt`.
      These are the only two `wp_posts` columns
      that `wp_update_post` itself sets during
      the update (it writes them to the current
      time at the moment of the update). The
      post-write compare does not predict these
      values; the read-back value is the
      expected value. A divergence in *only*
      these two fields (the four supported
      fields match the shadow's `after`, all
      protected preserved fields match the
      baseline) is a successful publication:
      the op transitions to
      `publication_committed`, and the
      `transaction_published` audit row records
      the read-back `post_modified_gmt` (and,
      if a divergence arose, the
      `post_modified`) so the operator can see
      what `wp_update_post` actually wrote. A
      divergence that is *not* in the allowlist
      falls into one of the two refusal
      categories above; a divergence that
      crosses categories (e.g. one supported
      field and one housekeeping field) is a
      single refusal, classified by the
      supported-field divergence (the more
      serious of the two).

   **The post-rollback audit-durability rule**
   (constraint #6 of the third question):
   the refusal audit row for *any* pre-COMMIT
   refusal at step 6 (or step 4) is written
   *after* the rolled-back §1.4 SQL
   transaction, in a separate short guarded
   transaction. A ROLLBACK of the publication
   transaction does not discard refusal
   evidence. The success audit row
   (`transaction_published`) and the
   `publication_committed` journal transition
   are written *inside* the §1.4 SQL
   transaction; a ROLLBACK rolls them back,
   and that is correct (the publication did
   not land). The success state is inside the
   transaction; the failure state is outside.

   **The earlier "compare against baseline A"
   post-update check is removed.** It was
   redundant (the row is no longer A, by
   design) and conflated the supported-field-
   overwrite and preserved-field-only cases.
   The allowlist-based verification rule at
   step 6, combined with the baseline-A
   revalidate at step 4, is what enforces the
   row-lock contract. The
   `publication_refused_write_lost_race` name
   is removed — there is no "external writer
   during the lock" case at step 6; the only
   mechanism that could have changed the row
   is a hook or a WordPress-internal
   transformation, and the rule classifies
   both by the field they touched.

   **The `publication_committed_hook_changed_preserved_field`
   state is removed.** It was a
   successful-publication-with-side-effects
   classification for hook-induced preserved-
   field changes, on the rationale that the
   supported fields had landed and the hook's
   effect was "merely a side effect". The
   brief is explicit: any hook-induced change
   to a protected original field is a refusal,
   not a successful publication. A preserved-
   field hook is no longer treated as a side
   effect; it is treated as a publication
   conflict, and the rule is an allowlist:
   only `post_modified` and `post_modified_gmt`
   may diverge, and they are tolerated only
   because `wp_update_post` itself sets them,
   not because a hook is trusted.

7. **No additional conditional publication CAS.**
   The conditional `UPDATE WHERE post_modified_gmt =
   A.modified_gmt` from the previous draft is removed.
   It served no distinct guarantee: the `FOR UPDATE`
   row lock from step 4 already serialised against
   any concurrent writer; the write-and-verify-C
   check at step 6 catches the only race the
   row lock cannot catch (a writer that bypassed
   the row lock, which is not a WordPress path).
   A `post_modified_gmt`-only CAS that returned 0
   rows would be *uninformative* — it would mean
   either "the row is now C" (success) or "the row
   was C, then an external writer committed, then
   our `wp_update_post` happened" (the lost race
   that step 6 already catches). Treating 0 rows as
   a conflict would reject successful publications
   where `wp_update_post` set `post_modified_gmt` to
   `NOW()` and the WHERE predicate expected A's
   older `post_modified_gmt` — the failure mode
   the brief explicitly named.
8. **Journal transition + audit, atomic with the
   publication** (still inside the SQL transaction).
   The op's `state` moves from `staged` to
   `publication_committed` (the only success
   state at step 6, on the allowlist-based
   verification rule: the four supported fields
   match the shadow's `after`, all protected
   preserved fields match the baseline, and any
   divergence is in the allowlisted housekeeping
   fields). A pre-COMMIT refusal
   (`publication_refused_hook_overwrote_supported_field`
   or
   `publication_refused_hook_changed_protected_preserved_field`,
   or the step 4
   `publication_refused_baseline_drift`) is
   *not* written inside the SQL transaction;
   the refusal audit is written after the
   ROLLBACK, in a separate short guarded
   transaction (per the post-rollback
   audit-durability rule). The
   `transaction_published` audit row is written
   by `Content_Write_Guard` with the op
   summary (op id, original id, shadow id, the
   four staged fields, the actor, the timestamp,
   the read-back `post_modified_gmt` and
   `post_modified`, the expected vs. verified C,
   and the allowlist bookkeeping delta when
   applicable). The journal + audit are part of
   the same atomic write as the original-row
   update in step 5: a COMMIT lands the
   publication *and* the journal success marker
   *and* the audit row together, or a ROLLBACK
   rolls them all back together. The journal +
   audit are not a separate step that "the
   publication has happened, now we record it" —
   they are part of the same atomic write.
9. `Content_Write_Guard` COMMITs. The row lock is
   released. After COMMIT, the original's four
   supported fields hold the staged values C; the
   op is in `publication_committed`; the audit
   log has the publication row; the shadow is
   still present. **There is no pre-publication
   state the recovery engine could mistake for an
   unpublished state** — the durable
   evidence-of-publication is the
   `publication_committed` journal state and the
   audit row, both of which are visible only
   after a successful COMMIT. A pre-COMMIT crash
   leaves *no* `publication_committed` marker;
   the op is in `staged` (the state it entered
   the SQL transaction in) and recovery
   recognises it as unpublished.
10. **Audit durability on the refusal paths.**
    The conflict audit rows
    (`publication_refused_baseline_drift`,
    `publication_refused_hook_overwrote_supported_field`,
    `publication_refused_hook_changed_protected_preserved_field`,
    and any other refusal written by
    `agentshell_publish_edit` before the SQL
    transaction begins) are written *after* the
    rolled-back SQL transaction, in a separate
    short guarded transaction (or in
    `Content_Write_Guard::run()` with a fresh
    `START TRANSACTION` that has nothing to roll
    back). A ROLLBACK of the publication
    transaction therefore does not discard the
    refusal audit evidence. The refusal-audit
    write is the *first* durable write after the
    rollback; the operator can inspect it after
    the failure. The same rule applies to the
    early-failure paths (lease expired, op not
    in `staged`, claim held by another tx,
    shadow missing): the refusal audit is
    written *outside* any rolled-back
    transaction, so it survives.
11. **Reconciliation** runs (see §1.7). The SQL
    transaction is over; the publication is durable;
    the shadow survives until reconciliation
    completes. The advisory lock from step 1 is
    still held; the reconciler, the rollback
    recovery path, and the lease-recovery path
    cannot enter their destructive sections until
    the lock is released by the publish tool's
    `finally`. The lock is released in the
    publish tool's `finally` after reconciliation
    is complete (or has reached
    `reconciliation_pending` and the operator has
    been told to retry).

**Why the row lock is not a long transaction, and not a
transaction held across MCP calls.** The transaction is
bounded by a single PHP function call inside the commit.
The agent does not hold the SQL transaction while it
"thinks" or while the next MCP call is being authorised.
The reconciliation runs in a fresh request. The lock is
held only for the duration of the publication transaction
(tens to hundreds of milliseconds; the `save_post` chain
is bounded by the same `Content_Write_Guard` depth
counter and by WordPress's own `apply_filters` time),
not for the duration of a multi-step user workflow.
Ordinary editors see a short blocking wait, not a hang.

**What we explicitly do not promise as transactionally atomic** (per
brief constraint #4 of the third question):

- **`save_post` / `wp_after_insert_post` / `transition_post_status` /
  `post_updated` / `wp_save_post_revision` hook chain.** These
  are PHP callbacks, not database operations. The SQL they
  issue (a search reindexer writing to its own table, a Yoast
  meta save, an ACF field update) joins our open InnoDB
  transaction and is rolled back with it. The *external work*
  they perform (a `wp_remote_post` to a webhook provider, an
  outbound email, a `wp_schedule_event`) is **not** part of
  the database transaction and is **not** rolled back by our
  ROLLBACK. The write-and-verify-C check at step 5 catches
  *recorded-field* changes that a hook performs, because the
  row no longer matches C; a hook that performs only external
  work (and does not change a recorded field) is not caught
  here, and is named in §6.2 as a best-effort side effect
  that is not undone by ROLLBACK.
- **WordPress revision writes.** WordPress's `wp_update_post`
  is not a single SQL statement; it is a PHP function that
  performs a sequence of operations: a main
  `UPDATE wp_posts SET ... WHERE ID=?` for the supported
  fields, an `INSERT INTO wp_posts` for the revision row,
  taxonomy term sync, post-meta upserts for the call's
  `meta_input`, and `clean_post_cache()` for the in-process
  object cache. The database writes *join* our open InnoDB
  transaction; they are committed by our COMMIT or rolled
  back by our ROLLBACK. The revision history is therefore
  consistent with the database state at COMMIT: a successful
  COMMIT lands the post update *and* the revision row; a
  ROLLBACK removes both. The post-update revalidate at
  step 5 reads the post row to verify C; the revision
  row is not the same row, and the revision row's
  existence is *not* re-validated (the existence of a
  revision row is a WordPress-internal side effect of
  `wp_update_post`, not a guard for the publication).
- **Object cache.** `clean_post_cache()` is the per-post
  in-process object-cache invalidation called by
  `wp_update_post`. The in-process `$wp_object_cache` is
  per-request and dies with the request; a ROLLBACK
  leaves the cache primed with the rolled-back values,
  which is a known limitation of the existing
  `Content_Write_Guard` (the design record §5.1 names
  this). The post-commit reconciliation step
  (`clean_post_cache($original_id)`) is the explicit
  post-COMMIT invalidation that handles the cross-request
  case. Persistent object caches (Redis, Memcached) are
  not transactional; the post-commit reconciliation
  flushes them, but a cache that is unreachable at the
  moment of the flush serves stale content until it
  expires — eventually-consistent, not exactly-once.
- **External systems** (CDN purge, search reindex, sitemap
  regen) are not part of the SQL transaction at all. They
  are post-commit reconciliation work, and are
  retryable/idempotent where possible (see §1.7).

### 1.5 Op type — one new op, narrow field set (selected)

A single new journal op, `OP_EDIT_PUBLISHED`. Supported fields (and
only these):

- `post_title`
- `post_content`
- `post_excerpt`
- `post_name` (slug)

Everything else is refused by the validator: `post_status`, `post_parent`,
`post_author`, `post_password`, `post_mime_type`, `post_date`,
`comment_status`, `ping_status`, `menu_order`, `post_content_filtered`,
`meta_input`, taxonomy, featured image, custom fields, navigation
menus. The refusal names the journal-aware alternative (`status`
transitions are deliberate Stage 2+ out-of-scope; the brief asked for
narrow scope).

The op does not reuse `OP_UPDATE_DRAFT` because (a) the op semantics
are different — the recovery disposition is "publication to the
database, not deletion of a draft", (b) the before-image is the
*original*'s state, not the *shadow*'s state, and conflating them in
one op type would force `verify_post` to branch on a flag, and (c) the
state machine has five states (§1.7) instead of the four that the
existing ops use. The implementation reuses the journal's CAS,
idempotency, hashing, locking, and `Content_Write_Guard` primitives
internally — the rejection is of `OP_UPDATE_DRAFT` as a wire-format
identity, not of the underlying mechanisms.

### 1.6 Single-claim invariant — `wp_options` claim row with value-guarded SQL CAS (selected)

A per-original claim key in `wp_options`:

```
agentshell_published_claim_<original_id>
  serialized: { tx_id, original_id, shadow_id, actor,
                baseline_ts, claimed_at, claim_ttl }
```

The claim row is the cross-process lock that prevents a
second AgentShell transaction from staging the same
original while a first is still holding it. The journal
op is the in-process record of who is staging; the claim
row is the cross-process barrier.

**WordPress's `update_option()` is not a value-guarded
CAS.** `update_option()` is a PHP function that issues an
unguarded `UPDATE wp_options SET option_value = ?
WHERE option_name = ?` (the guard against double-write
is the `option_id` row, not the option bytes). A claim
that is refreshed through `update_option()` could be
silently overwritten by a second caller whose
`get_option()` returned a stale value. The brief
required us to use the established AgentShell CAS
primitives, which are explicit conditional SQL with the
expected bytes in the `WHERE` clause.

**Established primitives** (already implemented for
Stage 1b's `Transaction_Manager`; reused here):

- `Transaction_Manager::cas_delete_row( $option_name,
  $expected_bytes )` — guarded `DELETE FROM wp_options
  WHERE option_name = ? AND option_value = ?`. Returns
  the number of affected rows; 0 means the row moved or
  was already gone, 1 means the delete landed.
- `Transaction_Manager::cas_renew_row( $option_name,
  $expected_bytes, $new_bytes )` — guarded `UPDATE
  wp_options SET option_value = ?, autoload = ? WHERE
  option_name = ? AND option_value = ?`. Returns
  affected-rows count. **This is the primitive that
  replaces `update_option()` for claim renewal.**
- `Transaction_Manager::atomic_create_row(
  $option_name, $new_bytes )` — `INSERT ... ON
  DUPLICATE KEY UPDATE` no-op or guarded create, with
  the affected-rows count distinguishing "created"
  (1) from "already existed" (0). **This is the
  primitive that replaces `add_option()` for atomic
  claim create.**

The brief's preferred shape is "genuine conditional SQL
updates with expected option bytes, ownership
verification, and correct cache invalidation." These
three primitives are exactly that. The cache
invalidation runs after a successful CAS, via
`wp_cache_delete( $option_name, 'options' )` — not via
`clean_post_cache` (which is for posts, not options),
and not via `update_option`'s internal
`wp_cache_delete` (which is what makes `update_option`
look like a CAS but actually is not).

**Lifecycle of the claim row:**

- **Atomic create.** On the first `edit_published` for
  an original, the claim row is created via
  `atomic_create_row`. If a row already exists (a prior
  staging has not yet released), the create returns 0
  affected rows; the caller reads the existing row to
  determine ownership. If the existing row is owned by
  the same `(tx_id, actor)`, the call is a no-op and
  the operation proceeds (the same transaction staging
  the same original is the same lock holder, by
  definition). If the existing row is owned by a
  *different* `(tx_id, actor)`, the create is refused
  with the existing claim's identity; the caller is
  told the claim is held.
- **CAS renewal.** The claim is refreshed via
  `cas_renew_row` with the *expected current bytes*
  (the row's value as last read) and the *new bytes*
  (the new claim_ttl, claimed_at, etc.). Zero rows
  affected means the row moved underneath the caller
  (a competing transaction's `cas_renew_row` won the
  race, or `cas_delete_row` removed the row); the
  caller re-reads and decides whether the row is still
  theirs.
- **Expired claims are not stolen while work is
  unresolved.** If the claim has expired, the next
  `edit_published` for the same original may reclaim it,
  but only after consulting the journal: if any journal
  op in any state other than `publication_settled`,
  `compensated`, or `conflict` references this
  original, the claim is preserved and the caller is
  told to run `agentshell_recover_transaction` first.
  (This mirrors the Stage 1b rule that a transaction
  holding durable work is never reclaimed.)
- **External editors are not bound by AgentShell
  claims.** A human WordPress editor who clicks
  "Update" on the original post while an AgentShell
  transaction is staging it succeeds; their edit
  changes the original's `post_modified_gmt` and
  (potentially) the four supported fields. The
  AgentShell commit's write-and-verify-C check at
  step 5 of §1.4 catches the race.
- **Release is CAS-guarded.** The claim row is
  released after `publication_settled` (or after
  pre-publication rollback) by `cas_delete_row` with
  the row's current bytes. A release that returns 0
  rows affected (row moved, row already gone) is a
  no-op; the claim is not held by the current
  transaction regardless. The cache invalidation
  (`wp_cache_delete( $option_name, 'options' )`) runs
  after a successful delete.

The claim row is not the source of truth for "who is
staging this original" — the journal op is. The claim
row is the cross-process lock that prevents two
AgentShell transactions from racing for the same
original. A recovered claim (after the holder crashed)
is decided by consulting the journal, not by trusting
the claim's `tx_id` field.

### 1.6a Shared exclusion — per-transaction MySQL named advisory lock (selected)

The per-original `wp_options` claim row is a *data*
lock: it says "this original is being staged". It is
not a *process* lock: a second PHP process that holds
no claim row but is mid-publication of the same
original cannot be detected by the claim row alone,
because the claim row's "create" happens at
`edit_published` time, not at `publish_edit` time, and
`publish_edit` is the step that can race with itself.
A lease reaper or a reconciler that wakes up while a
`publish_edit` is in flight would also race against
the publisher.

The exclusion that protects the publisher from
concurrent recovery is a **MySQL named advisory lock**,
acquired by `Recovery_Advisory_Lock::acquire(
'agentshell_publish_lock_<tx_id_hash>' )`. The lock
is scoped to the *transaction id* (not the original
id), so a single transaction with one
`OP_EDIT_PUBLISHED` op acquires one lock; a
hypothetical future transaction with multiple
`OP_EDIT_PUBLISHED` ops (out of scope for Stage 2c —
see §9a) would acquire one lock per tx. The lock is
acquired at `publish_edit` step 1, held through the
§1.4 SQL transaction and through reconciliation, and
released in the publish tool's `finally` block. The
lock is connection-scoped, not transaction-scoped: a
`KILL -9` of the publishing process releases it via
the server's connection-close handler, exactly the
property the per-post lock in
`Content_Recovery::compensate()` has.

**Who acquires the lock.**

- `agentshell_publish_edit` — at step 1, before
  validation, before the SQL transaction begins.
  Held through reconciliation. Released in
  `finally` (which runs on the happy path, on a
  refused publication, on an exception, and on a
  ROLLBACK).
- `agentshell_publish_edit_reconcile` — on every
  retry. The lock is *not* held by the publisher
  across MCP calls (it is released at the end of
  the `publish_edit` request), so the reconcile
  tool may acquire it independently. If the
  publisher is still running (it should not be,
  but a hung publisher is a real possibility), the
  reconcile's `GET_LOCK(name, 0)` returns `0` and
  the reconcile retries after a short sleep. The
  reconcile refuses with
  `transaction_publish_reconcile_refused_publisher_active`
  if the lock is held.
- `agentshell_recover_transaction` — when
  recovering an `OP_EDIT_PUBLISHED` op in any
  state. The recovery engine acquires the lock
  before reading the op's state, before deleting
  the shadow, before releasing the claim. If the
  publisher is still running, the recoverer's
  `GET_LOCK(name, 0)` returns `0` and recovery
  refuses with
  `transaction_recover_refused_publisher_active`
  (a transient refusal, not a destructive one).
- `agentshell_rollback_transaction` — when
  rolling back a transaction whose only op is an
  `OP_EDIT_PUBLISHED` op in `staged` (the
  pre-publication rollback path). Acquires the
  lock before deleting the shadow. If the
  publisher has acquired the lock between the
  rollback's pre-check and the lock acquisition,
  the rollback's `GET_LOCK(name, 0)` returns `0`
  and the rollback refuses with
  `transaction_rollback_refused_publisher_active`.
- **The lease reaper** — when transitioning an
  op to `abandoned` because the transaction's
  lease expired. Acquires the lock before the
  transition. If the publisher is still running
  (a lease that expired while publication is in
  flight is an inconsistency — the publisher's
  process is alive but the lease says it
  abandoned), the reaper refuses and the
  operator is told to run
  `agentshell_recover_transaction` after the
  publisher finishes.

**What the lock prevents.** A second PHP process
*cannot* enter any destructive path (shadow delete,
claim release, op state transition to `compensated`
or `abandoned`) while the publisher is in the
critical section. The publisher, in turn, holds the
lock for the entire critical section — through the
SQL transaction and through reconciliation — so
recovery and rollback cannot interfere with
mid-publication. The lock is **advisory**, not a
SQL row lock: it does not block `wp_update_post`
on the original. The `SELECT ... FOR UPDATE` row
lock from §1.4 step 4 is what serialises against
external writers; the advisory lock is what
serialises against recovery / rollback / lease
reaper. The two locks are independent and serve
different purposes.

**Engine requirement and fail-closed behaviour.**
The lock uses `GET_LOCK(name, 0)` and
`RELEASE_LOCK(name)`, available in MySQL 5.7+ and
MariaDB 10.0.1+. `Recovery_Advisory_Lock::available()`
probes the engine once per process and caches the
result. A `NULL` return from `GET_LOCK` (server
error), an unavailable lock service, or a `0`
return (held by another connection) all refuse
destructively: the publisher refuses with
`transaction_publish_refused_lock_unavailable`,
the recoverer / reconciler / rollback refuses
with the appropriate `*_refused_lock_unavailable`
or `*_refused_publisher_active` message. There is
no silent downgrade to "well, the lock service
wasn't reachable, so we'll just proceed without
it"; an unsupported engine is a refusal, not a
best-effort.

**Tested properties** (each is a live test
in `tests/journal-recovery-live-child.php`):

1. *The publisher holds the lock through the
   entire critical section.* Fork a child PHP
   process that runs `agentshell_publish_edit`,
   `usleep( 1 * SECOND )` at the reconciliation
   boundary, and then finishes. The parent
   attempts `agentshell_recover_transaction`
   during the sleep. The parent's
   `GET_LOCK(name, 0)` returns `0` within 200 ms
   and recovery is refused with
   `transaction_recover_refused_publisher_active`.
   The audit log has the refusal; the op is
   unchanged; the shadow is unchanged.
2. *A `KILL -9` of the publisher releases the
   lock via connection close.* The child
   acquires the lock, then `KILL -9`. The parent
   then runs `agentshell_recover_transaction`
   and succeeds. The original is unchanged
   (the publication did not COMMIT), the op
   transitions to `compensated`, the audit log
   has
   `transaction_published_compensated_after_crash`.
3. *The reconciler's prompt failure is bounded.*
   The reconciler's `GET_LOCK(name, 0)` is
   prompt: when the publisher holds the lock,
   the reconciler is refused within 200 ms, not
   after a default 60-second wait.
4. *Rollback's `GET_LOCK` is prompt and refuses
   when the publisher is active.* Same shape as
   (3), for the rollback path.
5. *The lease reaper's `GET_LOCK` refuses when
   the publisher is active.* The reaper's
   refusal is recoverable: the operator runs
   `agentshell_recover_transaction` after the
   publisher finishes, and the abandon path
   proceeds.

**Why the lock is scoped to the tx id, not the
original id.** A future Stage that supports
multiple `OP_EDIT_PUBLISHED` ops in one
transaction (out of scope here) would publish
each op in turn under the same tx id; one lock
per tx is the right granularity. The journal
op's `original_id` is not a substitute for the
tx id because the recoverer needs to know
"this whole transaction is in a critical
section" to make the right refusal decision
across all of its ops, not just the one that
matches the original.

**The post-rollback audit-durability rule.**
The `agentshell_publish_edit` tool's refusal
paths (steps 4 baseline-A drift, step 6
external-concurrency divergence) write their
audit rows in a *separate short transaction*
that runs *after* the rolled-back §1.4 SQL
transaction. The rule is: **no audit row that
records a refusal is written inside the §1.4
SQL transaction.** A ROLLBACK of the publication
transaction therefore does not discard the
refusal evidence. The same rule applies to
early-failure paths (lease expired, op not in
`staged`, claim held by another tx, shadow
missing, advisory lock unavailable): the
refusal audit is written outside the §1.4 SQL
transaction. The success audit row (the
`transaction_published` row in step 8) **is**
written inside the §1.4 SQL transaction,
because its durability is part of the
"publication has happened" guarantee; a
ROLLBACK rolls it back, and that is correct
(the publication did not land). The
*success* state is inside the transaction;
the *failure* state is outside.

### 1.7 State machine, durability, and recovery (corrected)

The brief required explicit answers to: which journal
states are durably recorded inside vs. outside the SQL
publication transaction; whether the publication
success marker survives a ROLLBACK; whether conflict
and failure evidence survives a ROLLBACK; and whether
recovery infers unpublished status from a stale pre-
commit marker.

**The single atomic event** is the SQL transaction
inside `Content_Write_Guard::run()` in §1.4. Inside
that transaction, the following writes share one
`START TRANSACTION ... COMMIT` boundary:

- The original-row update via `wp_update_post`
  (step 4 of §1.4), which writes the main `wp_posts`
  row, one revision row, taxonomy sync rows, and the
  per-post object cache invalidation — all in the
  same connection, same depth counter, same
  transaction.
- The `transaction_published` audit row (step 7),
  which records the op summary.
- The journal op's `state` transition from `staged`
  to `publication_committed` (step 7).

A `COMMIT` lands all three. A `ROLLBACK` (any failure
between steps 2 and 8 of §1.4) discards all three:
the original is unchanged, the audit log has no
`transaction_published` row, and the journal op's
`state` is still `staged` (the value it had when the
SQL transaction began). There is **no
`publication_committed` state recorded pre-COMMIT**;
the state transition and the publication are written
together, or neither is written.

**Outside the SQL transaction** (after COMMIT):

- The reconciliation steps (shadow delete, cache
  invalidation, claim release).
- The journal op's transition from
  `publication_committed` to `publication_settled`
  (or to `reconciliation_pending` if a step failed).
- The reconciliation audit rows
  (`shadow_cleaned_up`, `caches_invalidated`,
  `claim_released`,
  `transaction_published_reconciled`).
- Recovery-time audit rows
  (`transaction_published_recovered_after_crash`).

These are durable but **not atomic with the
publication**. A crash between the COMMIT and the
reconciliation leaving the system in
`publication_committed` is a normal state, not an
error; recovery's job is to drive that state to
`publication_settled`.

**Conflict and failure evidence survives SQL
ROLLBACK.** The pre-COMMIT refusal paths in §1.4
(steps 4, 6) and the early-failure paths in
`agentshell_publish_edit` (lease expired, op not in
`staged`, claim held by another tx, shadow missing,
advisory lock unavailable) write audit rows
*after* the rolled-back §1.4 SQL transaction, in a
separate short guarded transaction. **No audit row
that records a refusal is written inside the §1.4
SQL transaction** — that is the post-rollback
audit-durability rule from §1.6a. A ROLLBACK of
the publication transaction therefore does not
discard refusal evidence. The
`publication_refused_*` audit row is durable; the
operator can inspect it after the failure. The
*success* audit row (`transaction_published`) **is**
written inside the §1.4 SQL transaction, because
its durability is part of the "publication has
happened" guarantee; a ROLLBACK rolls it back, and
that is correct (the publication did not land).
The success state is inside the transaction; the
failure state is outside.

**Recovery never infers unpublished status from a
stale pre-commit marker.** The recovery engine's
authoritative evidence for "the publication has
landed" is the journal op's `state` field, as
written by the §1.4 SQL transaction's COMMIT. A
`publication_committed` state is the only marker
the recovery engine reads as "publication has
landed". A pre-COMMIT crash leaves the op in
`staged` (the entry state), and recovery treats
`staged` as "unpublished". A pre-COMMIT crash
cannot produce a `publication_committed` state —
the state transition and the publication are
written together, or neither is written.

**The eight states, corrected:**

| State | Inside SQL tx? | Meaning | Set by | Reachable from |
|---|---|---|---|---|
| `staged` | n/a | Shadow written, op recorded, claim held, original untouched. | `agentshell_edit_published` | (entry) |
| `publication_in_progress` | n/a (transient, in-memory marker) | The commit's SQL transaction is open. **Not a durable state** — it is the in-memory marker that the publishing tool uses to coordinate with the transaction's lease reaper. The SQL transaction is the only thing that holds this marker. | `agentshell_publish_edit` step 2 | `staged` |
| `publication_committed` | YES (written by COMMIT) | The SQL transaction COMMITted; the original is published; the shadow still exists; reconciliation is owed. | `Content_Write_Guard` COMMIT | `staged` (atomic with the publication) |
| `reconciliation_pending` | NO (written after COMMIT) | One or more of the three reconciliation steps (shadow delete, cache invalidation, audit) failed; the op is in this state until a retry succeeds. | the first reconciliation attempt that fails | `publication_committed` |
| `publication_settled` | NO (written after COMMIT) | All three reconciliation steps have completed; the op is fully done. | the successful reconciliation retry | `reconciliation_pending`, or directly from `publication_committed` if the first attempt succeeded |
| `compensated` | NO (written after rollback decision) | Pre-publication rollback deleted the shadow. The publication has not landed (a pre-COMMIT crash left the op in `staged`; recovery or rollback moved it to `compensated`). | `agentshell_rollback_transaction` or recovery on `staged` | `staged` |
| `conflict` | NO (written by the early-failure path, before the SQL transaction begins or as its first write) | Write-and-verify-C or baseline-A revalidate failed, the shadow is missing, the post is wrong, or the external editor was detected. The publication did not land. The op is preserved for the operator to resolve. | the commit path's early-failure audit | `staged`, `publication_in_progress` |
| `abandoned` | NO (written by the lease reaper) | The transaction's lease expired without `commit` or `rollback`; the claim is held until recovery runs. Recovery treats `abandoned` as `staged` (publication did not land). | lease reaper | any state |

**Why `publication_in_progress` is not a durable
state.** The brief asked the recovery engine to
"never infer unpublished status from a stale pre-
commit marker". A `publication_in_progress` marker
that survives a crash would be exactly that: a pre-
commit marker that recovery could mistake for
evidence of publication. The SQL transaction is the
only thing that holds this marker; a crash closes
the connection, InnoDB ROLLBACKs, and the marker
disappears. The recovery engine never sees
`publication_in_progress`; it sees `staged`
(unpublished) or `publication_committed` (published).
The `publication_in_progress` state exists only as
an in-memory variable inside the publish tool's
stack, while the SQL transaction is open.

**Reconciliation, in detail.** Three idempotent
steps, each CAS-guarded by the op id:

1. **Shadow delete.** `wp_delete_post( $shadow_id,
   true )` wrapped in `Content_Write_Guard::run()`.
   Idempotent: if the shadow is already gone (a
   previous attempt got partway), the call returns
   `false` and the step is treated as complete.
   **The shadow's identity is validated before
   deletion**: the pre-delete check reads
   `get_post( $shadow_id )` and confirms the post
   carries the `_agentshell_shadow_of = $original_id`
   meta and the op's `op_meta_value` marker. A
   shadow that has been retargeted or relabelled
   (out-of-band tampering, an external editor's
   mistake) is refused with
   `reconciliation_refused_shadow_identity_mismatch`
   and the op is preserved in
   `reconciliation_pending` for the operator.
   Audit row on success: `shadow_cleaned_up`.
2. **Cache invalidation.** `clean_post_cache(
   $original_id )` (the per-post object cache,
   which is what `clean_post_cache` actually does —
   it deletes the `post/<id>`, `post_meta/<id>`,
   `posts/<id>`, and `terms/<id>` keys from the
   in-process object cache and dispatches the
   `clean_post_cache` action for persistent-cache
   backends). **The persistent object cache (Redis,
   Memcached) is invalidated through the
   `clean_post_cache` action**; the persistent
   page cache (the full-page response cache, if a
   page-cache plugin is in use) is invalidated
   through the page-cache plugin's own purge API
   (out of scope to integrate; the design
   documents the limitation in §6.2). The claim
   row's option cache is invalidated via
   `wp_cache_delete(
   'agentshell_published_claim_<original_id>',
   'options' )` after the claim release (step 3).
   Audit row on success: `caches_invalidated`.
   Idempotent: `clean_post_cache` on a non-existent
   post id is a no-op.
3. **Journal settlement, audit, and claim
   release.** The op's `state` transitions
   `reconciliation_pending → publication_settled`
   (or `publication_committed → publication_settled`
   on the first attempt). The audit row
   `transaction_published_reconciled` is written.
   The claim row is released via
   `Transaction_Manager::cas_delete_row( $key,
   $expected_bytes )` (the value-guarded CAS from
   §1.6). A `cas_delete_row` that returns 0 rows
   (the row moved underneath reconciliation, e.g.
   another `edit_published` raced) is treated as
   "the claim is no longer ours"; the audit row
   records the move. Audit row: `claim_released`.

   **Crash-safe ordering of release and durable
   completion evidence.** The release has three
   observable events: (a) the claim row is
   deleted from `wp_options`, (b) the audit row
   `claim_released` is written, (c) the op's
   `state` transitions to `publication_settled`.
   The order must be such that a crash anywhere
   in the sequence leaves the system in a state
   that a subsequent retry can settle without
   re-doing work that already landed and without
   losing evidence of work that did. The
   ordering is:

   - **Step 3a: the durable completion evidence
     is recorded first.** The op's
     `reconciliation.claim_released` field is
     set to the value
     `'attempted_at:<time>, status:pending'`,
     inside the same SQL transaction that
     performs the `cas_delete_row` (step 3b).
     A crash before step 3a has the op's
     `reconciliation.claim_released` field
     still `false`; a retry runs step 3 again
     and observes the attempt hasn't happened.
   - **Step 3b: the `cas_delete_row` is run
     inside the same SQL transaction as
     3a.** The CAS returns 1 row affected
     (release landed) or 0 (the WHERE clause
     did not match). A 0-row return is
     *advisory*; the actual outcome is
     determined by the direct SQL read at
     step 3c, which classifies into the
     three outcomes below (claim absent →
     release confirmed; claim still owned
     by this transaction → anomalous;
     claim owned by a successor → takeover).
     A crash between 3a and the SQL COMMIT
     rolls back 3a too (both are inside the
     same transaction); a retry runs step 3
     again.
   - **Step 3c: the `claim_released` audit row
     is written *after* the SQL COMMIT of 3a/3b,
     in a separate short transaction.** A
     crash between the COMMIT and the audit
     write leaves the claim released, the
     op's `reconciliation.claim_released`
     field set to `'attempted_at:<time>,
     status:pending'`, and the audit row
     absent. A retry observes the pending
     marker, **but the pending marker is a
     *hint*, not proof of release** —
     the brief is explicit: "claim-release
     recovery must verify the actual claim
     and journal state after a crash, never
     infer successful release from a pending
     marker." The retry therefore does the
     following, in order:
     1. **Re-read the claim row
        *authoritatively*.** The retry
        issues a direct SQL read against
        `wp_options`:

        ```php
        $row = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM wp_options
                 WHERE option_name = %s",
                'agentshell_published_claim_<original_id>'
            )
        );
        ```

        `get_option()` is **not** an
        authoritative read: it is
        cache-backed (it reads
        `wp_object_cache` first, and the
        cache may hold a stale value
        after a successful CAS that
        deleted the row, or after a
        `wp_cache_delete` that did not
        reach the row because the
        in-process object cache and the
        persistent cache are different
        substrates). The CAS is the
        actual mutation; the direct SQL
        read is the actual observation.
        The retry classifies the read
        into one of three outcomes, each
        with a distinct disposition:
        - **Outcome 1 — claim absent
          (read returns null).** The
          claim row is gone from
          `wp_options` on disk. The
          release is verified; the
          audit row can be written in
          step 3c' below. The pending
          marker is now confirmed by
          the direct read.
        - **Outcome 2 — claim still
          owned by this transaction
          (read returns the row bytes
          we expected).** The row is
          present with the same bytes
          we just tried to delete via
          the value-guarded CAS. This
          is *anomalous* — the CAS's
          WHERE clause
          `option_name = X AND option_value = Y`
          should have matched a row
          with value Y, and the only
          way it did not is a row
          identity change (e.g. the
          row was deleted and
          re-inserted with the same
          name and value, or the
          engine's row visibility
          semantics produced a stale
          read). The retry does not
          guess: it refuses with
          `publication_settle_refused_claim_release_anomaly`
          and audits the row's
          current bytes (the actual
          `option_id`, the read-back
          value, and the CAS return
          value) so the operator can
          investigate. The op stays
          in `reconciliation_pending`;
          the claim row is not
          touched by the retry.
        - **Outcome 3 — claim owned
          by a successor transaction
          (read returns bytes that
          differ from ours).** A
          *successor* transaction has
          re-acquired the claim for
          the same original. The
          brief is explicit: a
          successor's claim is its
          own; this transaction's
          reconciliation does not
          delete it. The retry
          records the takeover
          (`transaction_claim_released_successor_took_over`
          audit row, with the
          successor's bytes and the
          successor's `tx_id` if
          encoded in the row), and
          does not call
          `cas_delete_row` against
          the successor's row. The
          op transitions to
          `publication_settled` (this
          transaction's reconciliation
          is complete; the claim has
          moved to a successor; the
          site-visible state is the
          successor's responsibility).
          **Never delete a successor
          claim** is the rule.
     2. **Re-read the journal op's state.**
        The retry reads the journal
        row (a `get_option( $tx_key, null )`
        call) and confirms the op is
        still in
        `reconciliation_pending` with
        the pending marker. If the op
        has already transitioned to
        `publication_settled` (a
        concurrent retry succeeded),
        the current retry is a
        no-op: report settled and
        exit. If the op is in a
        different state (e.g. another
        recoverer moved it to
        `compensated`), the current
        retry refuses with
        `publication_settle_refused_op_state_unexpected`
        and the operator is told to
        inspect.
     3. **Write the audit row** (the
        actual step 3c). On Outcome 1
        (claim absent), the audit row
        records the release
        verification. On Outcome 3
        (successor took over), the
        audit row records the
        takeover (the successor's
        bytes, the successor's
        `tx_id` if encoded, and the
        `verified_at` timestamp). On
        Outcome 2 (anomalous), the
        audit row is the refusal
        evidence and is written in a
        separate short guarded
        transaction; the operator is
        told to investigate, and the
        step 3d state transition is
        *not* run.
   - **Step 3d: the op's `state` transitions
     to `publication_settled` in a separate
     short SQL transaction after the audit
     write of 3c.** A crash between 3c and 3d
     leaves the op in
     `reconciliation_pending` with
     `reconciliation.claim_released =
     'verified_at:<time>, completed_at:<time>'`
     (a different field value than the
     `'status:pending'` from 3a, signalling
     the release is verified and the audit
     row is on disk) and the audit row
     present. A retry observes the
     `verified_at` field, **but again
     re-reads the claim row authoritatively**
     (the direct SQL read, not `get_option()`,
     because the cache may be stale) to
     confirm the claim row is still in the
     state observed at 3c — gone (Outcome 1,
     no-op transition) or owned by a
     successor (Outcome 3, no-op
     transition). The retry then skips
     step 3 to run step 3d only. A crash
     between 3d and the COMMIT of 3d leaves
     the op in `reconciliation_pending` with
     all three reconciliation steps done;
     a retry re-runs 3d (idempotent, the
     SQL UPDATE is
     `SET state = 'publication_settled'`
     with no WHERE predicate on the
     current state, so a re-run is a
     no-op).

   The order (3a, 3b, then authoritative
   verification, then audit, then state) is
   the crash-safe ordering. A simpler "write
   the audit row, then run `cas_delete_row`,
   then transition state" ordering is *not*
   crash-safe: a crash between the audit
   write and the `cas_delete_row` would leave
   the audit row claiming a release that did
   not happen, and a subsequent retry would
   observe the audit row and skip the
   release — leaving the claim held
   indefinitely. The
   "`get_option()`-as-authoritative-read"
   ordering is also *not* safe: the cache
   may be stale, and a "claim is gone" claim
   based on a cache hit can be wrong. The
   direct SQL read is the source of truth, and
   the three-outcome classification is what
   turns the read into a disposition. The
   brief's wording — "never record
   `claim_released` and treat it as completed
   before the actual claim CAS succeeds" —
   and the follow-up "claim-release recovery
   must verify the actual claim and journal
   state after a crash, never infer
   successful release from a pending marker"
   — are what the authoritative read and
   three-outcome handling above satisfy.

If any step fails, the op state is
`reconciliation_pending` and the audit row
`transaction_published_reconciliation_failed`
records which step failed and why. A subsequent
`agentshell_publish_edit_reconcile` (or the next
`begin_transaction`, which consults the claim)
retries the failed step only. The other two steps
are skipped on a retry — the op's per-step status
record tells the retry which steps are already
done. **Hooks, webhooks, email, search reindex, and
other WordPress side effects are not part of
reconciliation and are not retried by this path**;
they are documented in §6.2 as "best effort, not
exactly once, and not part of the recovery
contract".

**Crash recovery.** `agentshell_recover_transaction`
(existing tool, with a new branch for
`OP_EDIT_PUBLISHED` ops) inspects the op state by
reading the journal op's `state` field:

- `staged` — the publication has not landed; the
  SQL transaction either never began or was
  ROLLBACKed. Recovery deletes the shadow (with
  identity validation, per the reconciliation
  step above), releases the claim, transitions
  the op to `compensated`. The original is
  unchanged. The audit row
  `transaction_published_compensated_after_crash`
  records this path.
- `publication_in_progress` — the recovery
  engine never reads this state from disk; a
  crash during the SQL transaction ROLLBACKs
  the transaction, and the op on disk is
  `staged`. (The `publication_in_progress`
  marker is in-memory only.)
- `publication_committed` or
  `reconciliation_pending` — the publication
  HAS landed; recovery runs reconciliation (not
  re-publication). The original is not
  re-published. The shadow is deleted; the
  caches are invalidated; the claim is
  released; the op transitions to
  `publication_settled`. The audit row
  `transaction_published_recovered_after_crash`
  records this path. **The original-row update
  is not reversed. Visitors may have observed
  the new content.**
- `publication_settled` — nothing to do;
  report settled.
- `compensated` — already settled; nothing to
  do.
- `conflict` — preserved; the operator
  resolves. The op's per-step evidence (which
  field diverged, the post's actual bytes, the
  shadow's actual bytes) is in the audit log.
- `abandoned` — treated as `staged`: shadow
  deleted, claim released, op to
  `compensated`. A `publication_in_progress`
  op that was abandoned while the SQL
  transaction was open is genuinely undone
  because the SQL transaction was rolled back
  on connection close.

**Why the shadow is held past COMMIT (constraint
#5 of the third question).** Two reasons: (a) the
journal needs a recovery artefact in case
reconciliation crashes; (b) the brief explicitly
requires that the shadow survives until the
publication has durably succeeded and the required
post-commit reconciliation is complete or safely
recoverable. The shadow's `post_status =
'private'` means its existence is invisible to the
public; the cost is one row in `wp_posts` and a
small set of postmeta, not a public artifact.

### 1.8 Pre-publication rollback vs. post-publication recovery (selected, named)

- **Pre-publication rollback** (`op in staged` or `publication_in_progress`):
  the SQL transaction has either not started or has not COMMITted; a
  `ROLLBACK` (or the absence of a COMMIT) discards everything; the
  original is unchanged. The shadow is deleted. The claim is
  released. The op transitions to `compensated`. This is what the
  user does by calling `agentshell_rollback_transaction` on a journal
  that has only staged edits.
- **Post-publication recovery** (`op in publication_committed` or
  `reconciliation_pending`): the original is already live. The
  recovery engine does not reverse the public write. It runs
  reconciliation. The op transitions to `publication_settled`. The
  brief's constraint #11 of the fourth question is explicit: "After
  durable publication, never pretend that compensating changes can
  erase what visitors or caches may already have observed." The
  recovery audit row records that this was a post-publication
  reconciliation, not a rollback. A human operator who wants the
  original content back edits the post (or opens a new
  `agentshell_edit_published` transaction) — there is no automatic
  undo, because there cannot be, in the general case.

---

## 2. Public API (the new tools)

### 2.1 `agentshell_edit_published`

Edits the four supported fields of an existing published post by
creating a private shadow and journaling the edit. The journal
holds **at most one `OP_EDIT_PUBLISHED` op per original per
transaction**; subsequent authorised calls in the same
transaction advance that op's `shadow_head` field, rather
than creating new ops.

Input:
- `original_id` (int, required) — the published post to stage
  against.
- `post_title` (string, optional) — new title.
- `post_content` (string, optional) — new content.
- `post_excerpt` (string, optional) — new excerpt.
- `post_name` (string, optional) — new slug.
- `idempotency_key` (string, required) — 1-128 ASCII
  characters, like `agentshell_create_draft`.

Refusal conditions, in evaluation order:

1. Not inside a transaction.
2. Missing or non-printable `idempotency_key`.
3. `original_id` does not exist, is the wrong type, or is not
   currently `post_status=publish` (status transitions are out
   of scope; named).
4. A different transaction holds the claim for this
   `original_id` (a competing staging is in progress).
5. The original's claim is held by a different transaction in
   a non-terminal state (the caller is told to run
   `agentshell_recover_transaction` first).
6. None of the four supported fields is supplied (a no-op edit
   is refused).

**Idempotency and head-advancement rules.** The
authoritative source is the journal op for this
`(tx_id, original_id)`. The op may be in one of the
following states:

- **No op exists yet.** Fresh staging. The tool
  creates a new `OP_EDIT_PUBLISHED` op with the
  supplied fields, writes a new shadow, captures
  the baseline, holds the claim, records the op
  id as the `shadow_head.op_id`. The op state is
  `staged`. The tool returns the new op id,
  shadow id, and a fresh result shape.
- **Op exists in `staged` state with the same
  `idempotency_key` and the same staged payload**
  (post_title, post_content, post_excerpt,
  post_name). The tool returns the prior result
  with `resumed: true`. No second shadow is
  created; no second op is recorded; the
  `shadow_head` is unchanged. **This is the
  retry-resume path.** A network failure between
  the prior tool's response and the client's
  retry is the motivating case.
- **Op exists in `staged` state with the same
  `idempotency_key` but a different staged
  payload.** The tool refuses with
  `transaction_edit_published_idempotency_conflict`.
  The op is unchanged; the `shadow_head` is
  unchanged. The caller must use a new
  `idempotency_key` (and accept the head
  advancement) or close the transaction.
- **Op exists in `staged` state with a different
  `idempotency_key`.** The tool advances the
  shadow: the shadow post is updated to the
  new staged fields, the `shadow_head` is
  CAS-updated to the new head, the baseline
  is *not* re-captured (the original has not
  been touched since staging started, the
  lock is held, and re-capturing would invite
  the race the lock is meant to prevent). The
  op id is unchanged; the shadow id is
  unchanged. The tool returns the same op id,
  the same shadow id, the new `shadow_head`,
  and `advanced: true` (not `resumed: true`).
  **The commitment to publish reads
  `shadow_head`, not the op's per-call
  after-image.** The audit log records
  `transaction_edit_published_shadow_advanced`
  for traceability of the head history.
- **Op exists in any non-`staged` state
  (`publication_committed`, `reconciliation_pending`,
  `publication_settled`, `compensated`,
  `conflict`, `abandoned`).** The tool
  refuses. A committed or settled op is
  final; a `compensated` op has had its
  shadow deleted; a `conflict` op must be
  resolved by recovery; an `abandoned` op
  must be resolved by recovery. The caller
  is told which state the op is in and
  which tool to run next
  (`agentshell_publish_edit_reconcile` for a
  reconciliation-pending op, recovery for
  the others).

Result (fresh staging or head advancement):
- `original_id`, `op_id`, `shadow_id`,
  `state: 'staged'`, `shadow_head: { op_id,
  after_sha256, at }`, `claim: { tx_id,
  original_id, shadow_id }`,
  `idempotent: { key, content_fingerprint,
  actor, tx }`, `atomicity: { engine,
  on_failure, not_covered }`.

Result (retry resume, same key + same payload):
- Same as above with `resumed: true` and the
  prior op id, shadow id, and `shadow_head`.

Result (idempotency conflict, same key + different
payload):
- `error: 'transaction_edit_published_idempotency_conflict'`,
  the prior op's `op_id`, the prior op's
  `shadow_head`, and a message naming the
  fields that differ.

Side effects (fresh staging or head advancement):
- **Fresh staging.** A private shadow post is
  created with `post_status=private`,
  `post_type=<original>`, the four staged
  fields, and the `_agentshell_shadow_of`
  meta. The shadow is registered in the
  journal op as the `after.post_id`. The
  original's strong baseline is captured as
  the op's `before`: `post_type, post_status,
  post_name, post_title, post_content,
  post_excerpt, post_date_gmt,
  post_modified_gmt, post_content_sha256,
  post_content_length`. The capture runs
  inside `Content_Write_Guard::run()`, so
  the read of the original and the
  shadow's `wp_insert_post` share one
  guarded SQL transaction. The
  `transaction_edit_published` audit row
  is written inside the same transaction.
- **Head advancement.** The shadow's
  `post_title`, `post_content`,
  `post_excerpt`, and `post_name` are
  updated via `wp_update_post( $shadow,
  $fields )` (which is the same WordPress
  sequence as the commit path, but on a
  `private` post). The `shadow_head` in
  the journal row is CAS-updated to the
  new `after_sha256` and `at`. The
  baseline `before` is *not* re-captured
  (the original is unchanged by the
  AgentShell staging path, and the claim
  guarantees no other AgentShell
  transaction is editing this original).
  The `transaction_edit_published_shadow_advanced`
  audit row is written.
- **Claim.** The claim row
  `agentshell_published_claim_<original_id>`
  is created via
  `Transaction_Manager::atomic_create_row`
  on fresh staging (and is unchanged on
  head advancement, since the same
  transaction holds it). The cache
  invalidation runs after a successful
  create: `wp_cache_delete(
  'agentshell_published_claim_<original_id>',
  'options' )`.

### 2.2 `agentshell_publish_edit` (renamed from `agentshell_publish` to avoid collision)

**Name collision discovered.** The existing
`agentshell_publish` tool (in
`agentshell-mcp/includes/tools/class-publish-content.php`)
is the immediate-write status-change tool — it sets
`post_status=publish` for a draft or pending post and
refuses inside a transaction. The new commit tool
cannot share that name; the names have different
semantics, the same string would be ambiguous in
the audit log and in agent instructions.

The new tool is **`agentshell_publish_edit`**. The
name is honest: it publishes an *edit* (a staged
shadow) to the original, not a status change on a
draft. The existing `agentshell_publish` (status
change) is unchanged.

`agentshell_publish_edit` runs the full §1.4
sequence. Inside the open transaction; refuses if
no `OP_EDIT_PUBLISHED` op is in `staged` state for
the calling actor's transaction, refuses if the
op's shadow is missing, refuses if the original is
not currently `post_status=publish`, refuses if
the lease has expired.

This is the `commit_transaction` shape from §6.4 of
`AGENTS.md`, but for content. The implementation
lives in
`agentshell-mcp/includes/tools/class-publish-edit.php`
and reuses the transaction manager, the
content-write guard, and the journal's CAS. The
tool does not bypass any of the existing commit
gates (validation, lease, authority).

Result:
- `op_id`, `state: 'publication_committed' |
  'conflict' | …`, `reconciliation: {
  shadow_deleted, caches_invalidated,
  claim_released, audit_written }`, `audit: {
  row_ids: […] }`.

Failure modes: the tool reuses the existing
`transaction_publish_refused_*` audit family. The
strong OCC revalidate failure at step 4 is
`publication_refused_baseline_drift`. The
write-and-verify-C failure at step 6 is classified
by the allowlist-based verification rule:
`publication_refused_hook_overwrote_supported_field`
for a divergence in one of the four supported
fields, or
`publication_refused_hook_changed_protected_preserved_field`
for a divergence in any of the named protected
preserved fields. The previous
`publication_refused_write_lost_race` (an
"external writer during the lock" classification)
is removed; the only external-writer case is
caught at step 4. The journal refuses are the
existing `transaction_*_refused` shapes.

### 2.3 `agentshell_publish_edit_reconcile` (renamed for consistency)

Retries a failed reconciliation. Idempotent. If
the op is already in `publication_settled`,
returns immediately with that fact. If the op is
in `reconciliation_pending`, runs the failed
step (the per-op status record names which one)
and only that step. If the op is in
`publication_committed` (no reconciliation
attempt yet), runs all three steps.

This is a separate tool from
`agentshell_publish_edit` because reconciliation
is not the same operation as publication: the
database write is durable; what remains is
cache + audit + cleanup.

### 2.4 `agentshell_rollback_transaction`

Existing tool. Extended to recognise `OP_EDIT_PUBLISHED` ops and
route them through the pre-publication rollback path (delete shadow,
release claim, transition op to `compensated`). Post-publication
recovery is the recovery tool's job, not the rollback tool's.

### 2.5 `agentshell_recover_transaction`

Existing tool. Extended to recognise `OP_EDIT_PUBLISHED` ops in
`publication_committed` or `reconciliation_pending` state and run
reconciliation. Does not reverse the public write. Audit row
`transaction_published_recovered_after_crash`.

### 2.6 `agentshell_screenshot`

Existing tool, no schema change. `transaction=true, post_id=N` now
works for `OP_EDIT_PUBLISHED` ops: the post-preview credential is
verified against the journal op's (original, shadow, tx) triangle,
the overlay is installed, and the original's template renders with
the shadow's four fields.

### 2.7 `agentshell_get_journal`

Existing tool, no schema change. Returns the op's `state` and
`reconciliation` fields for `OP_EDIT_PUBLISHED` ops, so an operator
inspecting a crashed transaction can see whether the publication
landed and what reconciliation is owed.

---

## 3. Journal schema (the new op)

The journal op shape, in the same flat-map form as Stage 1b/2a/2b:

```php
'op_<time>_<hex>' => array(
    'op'             => 'edit_published',     // self-identifying
    'v'              => 1,                    // per-op schema version
    'state'          => 'staged',             // one of the eight states
    'actor'          => 'agent',
    'recorded_at'    => <time>,
    'updated_at'     => <time>,

    'target'         => array(
        'original_id' => 301,
        'post_type'   => 'page',              // immutable for the op's life
    ),
    'before'         => array(                // the original's strong baseline
        'post_type'           => 'page',
        'post_status'         => 'publish',
        'post_name'           => 'about-us',
        'post_title'          => 'About Us',
        'post_content'        => '...',
        'post_excerpt'        => '...',
        'post_date_gmt'       => '2026-09-01 12:00:00',
        'post_modified_gmt'   => '2026-10-10 09:15:00',
        'post_content_sha256' => '...',
        'post_content_length' => 1234,
        'op_meta_value'       => 'op_<time>_<hex>',  // the original's marker (none yet; see below)
    ),
    'after'          => array(                // the shadow's staged values
        'post_type'    => 'page',
        'post_status'  => 'private',         // shadow is private
        'post_name'    => 'about-us-v2',     // the staged slug
        'post_title'   => 'About Us — V2',
        'post_content' => '...',
        'post_excerpt' => '...',
        'op_meta_value' => 'op_<time>_<hex>', // the shadow's marker (the op id)
    ),
    'shadow_head'    => array(                // the latest authorised shadow
        'op_id'        => 'op_<time>_<hex>',  // points at this op
        'after_sha256' => '...',              // hash of the after dict
        'at'           => <time>,
    ),
    'shadow_id'      => 412,                  // the shadow's post id
    'claim'          => array(                // mirrors wp_options claim row
        'option_key'   => 'agentshell_published_claim_301',
        'claimed_at'   => <time>,
        'claim_ttl'    => <seconds>,
    ),
    'reconciliation' => array(                // per-step status
        'shadow_deleted'        => false,    // or 'attempted_at:<time>, error:...'
        'caches_invalidated'    => false,
        'claim_released'        => false,
        'audit_written'         => false,
    ),
    'comp'           => null,                 // { outcome, detail, at } after settlement
    'notes'          => array(),
)
```

Notes on the schema:

- The `before` is the original's strong baseline at edit time. It is
  captured inside `Content_Write_Guard::run()` so the read of the
  original is consistent with the capture of the shadow.
- The `after` is the shadow's staged values. The shadow carries
  `post_status = 'private'`, which is *not* the published status; the
  published status (`publish`) is what the commit will set on the
  original. The op's `after.post_status` is recorded for the
  recovery engine's benefit.
- The `op_meta_value` is the operation id. For `OP_CREATE_DRAFT` and
  `OP_UPDATE_DRAFT`, the post carries the marker as
  `_agentshell_journal_op`. For `OP_EDIT_PUBLISHED`, the **shadow**
  carries the marker; the original does not. The recovery engine
  finds the shadow by marker, not the original. The original is
  identified by `original_id` in the op.
- The `shadow_head` is the head-pointer mechanism from constraint #3
  of the fourth question. Subsequent edits in the same transaction
  advance the head with a durable CAS on the journal row. The commit
  reads the head and applies it; the per-op after is for the audit
  history.
- The `claim` mirror is a redundant copy of the `wp_options` claim
  row. The journal is the source of truth; the option row is the
  cross-process lock. They are kept in sync by the same code paths.
- The `reconciliation` field records the per-step status. A retry
  reads it to know which steps are still owed.

---

## 4. Failure scenarios (the test surface)

Each row is a scenario that becomes a regression in
`tests/journal-recovery-test.php` (stub) and a real-WordPress assertion
in `tests/journal-recovery-live-test.php`. Naming follows the
existing `F<number>` convention; the Stage 2c numbers start at F36.

| # | Scenario | Required behaviour |
|---|---|---|
| F36 | `edit_published` outside a transaction | Refuse; name `begin_transaction`. |
| F37 | `edit_published` on a draft post | Refuse; name `agentshell_update_draft` (which is the journal-aware path for drafts). |
| F38 | Two AgentShell transactions race for the same `original_id` | One wins the claim row; the other is refused. |
| F39 | An external editor updates the original while staging is in `staged` | Staging is unaffected (the shadow is private); the commit's strong OCC revalidate detects the divergence and refuses. |
| F40 | An external editor updates the original in the same second as the commit's OCC revalidate | The `SELECT ... FOR UPDATE` row lock at step 4 serialises against the external writer; if the external writer committed first, the strong OCC revalidate at step 4 detects the divergence in `post_content` / `post_modified_gmt` / `post_title` / etc. directly (the `post_content` read fails the content comparison even if the `post_modified_gmt` matches to the second); the SQL transaction ROLLBACKs, the post-rollback refusal audit `publication_refused_baseline_drift` is written in a separate transaction, and the op transitions to `conflict`. **There is no separate conditional publication CAS step** — the row lock and the baseline-A revalidate at step 4 are the entire publication-side guarantee, and the write-and-verify-C at step 6 catches the same-second race that the row lock cannot catch (a writer that bypassed the lock). |
| F41 | `publish_edit` on a journal op in `staged` with shadow present | `Recovery_Advisory_Lock::acquire('agentshell_publish_lock_<tx_id>')`; `SELECT ... FOR UPDATE` row lock + baseline-A revalidate; `wp_update_post` (with revisions, term sync, cache invalidation, hook chain); write-and-verify-C (the four supported fields match the shadow's `after`, the preserved fields match the baseline, the `post_modified_gmt` is the read-back value not a prediction); journal transition to `publication_committed`; `transaction_published` audit row; COMMIT — all atomic. State is `publication_committed`; shadow survives; advisory lock released in `finally` after reconciliation. |
| F42 | Crash between `wp_update_post` return and COMMIT (e.g. PHP fatal) | Connection drops; InnoDB ROLLBACKs; original unchanged; op is in `staged` (the entry state — the `publication_committed` transition is inside the rolled-back transaction, so it is not visible to recovery). The next `agentshell_recover_transaction` recognises `staged` as unpublished and runs the pre-publication rollback path (acquires the per-tx advisory lock, deletes shadow with identity validation, releases claim with crash-safe ordering per §1.7 step 3, op to `compensated`). The pre-COMMIT refusal audit row is *not* written by the failed commit; a recovery-time audit row is written instead. |
| F43 | Crash after COMMIT, before reconciliation | Original is published; op is in `publication_committed`; audit log has `transaction_published`. `agentshell_recover_transaction` acquires the per-tx advisory lock (the publisher's connection has closed, so the lock is released by the server), then runs reconciliation (shadow identity-validated delete, cache invalidation, claim release with crash-safe ordering per §1.7 step 3), not re-publication. Audit row `transaction_published_recovered_after_crash`. **The original is not reversed.** |
| F44 | Crash after shadow delete, before cache invalidation | Op is in `publication_committed` with `reconciliation.shadow_deleted = true`; the next `agentshell_publish_edit_reconcile` retry acquires the per-tx advisory lock and runs only the cache invalidation step (the per-op status record says shadow is gone). Idempotent. |
| F45 | Cache invalidation step fails (e.g. Redis unavailable) | Op is in `reconciliation_pending`; the per-op status record names the failed step. Retry succeeds when Redis is back. Persistent cache staleness is documented in §6.2 as best-effort, eventually-consistent. |
| F46 | Claim row release fails because another transaction has raced to create a new claim | The crash-safe release ordering in §1.7 step 3: (3a) set `reconciliation.claim_released` to `'attempted_at:<time>, status:pending'`, (3b) `cas_delete_row` returns 0 (row moved), the audit row `claim_released_row_moved` is written *after* the SQL COMMIT of 3a/3b in a separate short transaction (the post-rollback audit-durability rule), the per-op status record names the failed step, the op is in `reconciliation_pending`. The next retry reads the new claim row, sees it is not ours, treats the claim as "no longer ours", and the op transitions to `publication_settled` with the audit row naming the move. **The audit row is not written before the `cas_delete_row` returns** — that ordering would lie about a release that did not happen. |
| F47 | `rollback_transaction` on a staged op (pre-publication) | Acquires the per-tx advisory lock; shadow deleted; claim released (crash-safe ordering per §1.7 step 3); op transitions to `compensated`; original unchanged. Audit row `transaction_published_compensated_after_rollback`. |
| F48 | `rollback_transaction` on a `publication_committed` op | Refused; the rollback tool's contract is "the database is untouched, you can roll back"; a published op is not that. The error names `agentshell_recover_transaction` for the reconciliation path. |
| F49 | `rollback_transaction` on a `publication_settled` op | Refused; the publication is durable; there is nothing to roll back. |
| F50 | Previews with an unauthorised request | The `pre_get_posts` callback's credential verification finds no credential; the per-request global is not set; the field-level filters (`the_title`, `the_content`, `the_excerpt`, the permalink family) are no-ops; the `the_posts` filter is not installed. The request resolves the original post and renders the original's live values. The shadow is not exposed. **No 404**: the original is a `post_status=publish` post, the main query resolves it naturally, and the response is HTTP 200. |
| F51 | Previews with a valid credential but a wrong (original, shadow, tx) triangle | The `pre_get_posts` callback's credential verification fails; the per-request global is not set; the field-level filters are no-ops; the `the_posts` filter is not installed. The request renders the original's live values. The error message does not disclose the existence of the shadow. |
| F52 | Previews with a valid credential, the (original, shadow, tx) triangle intact, and a third-party plugin that calls `get_field( $original_id )` | The plugin reads the original's value. Documented in §6.2. The preview still renders correctly for the four supported fields (the field-level filters overlay the four fields; the plugin's direct `get_post($original_id)` call reads the original from the persistent cache, which holds the original because the main query resolved the original). The original-page identity is preserved: `get_queried_object_id()` returns the original's id, `$post->ID` is the original's id, the original's page-specific template is selected, page ancestry is the original's, post metadata is the original's, dynamic block context resolves to the original's id. |
| F53 | Original-page identity preservation: `get_queried_object_id()`, `$post->ID`, page-specific templates, page ancestry, dynamic blocks, post metadata | Asserted by tests against a real WordPress with a child theme that exercises each invariant: (a) `get_queried_object_id()` returns the original's id (not the shadow's); (b) `$post->ID` is the original's id; (c) the template loader selects the original's `page-{ID}.php` (a `page-{shadow_id}.php` would be a test failure); (d) `get_ancestors($post->ID)` returns the original's ancestors; (e) `get_post_meta($post->ID, '_wp_page_template', true)` returns the original's page-template selection; (f) a dynamic block that does `get_post_meta($block->context['postId'], ...)` reads the original's metadata; (g) the four supported fields (`post_title`, `post_content`, `post_excerpt`, `post_name`) read from the rendered response body are the shadow's staged values (the field-level filter overlay); (h) `wp_object_cache` is not poisoned — a direct `wp_cache_get( 'post/' . $original_id, 'posts' )` returns the original's row. |
| F53a | Anonymous request without a credential | The main query resolves the original (a `post_status=publish` post); the template loader renders the original's page; HTTP 200; the response carries the original's live values for all fields; no overlay, no shadow exposure, no 404. The original is publicly readable, and the main query's resolution of the original is the only mechanism the response depends on. |
| F53b | Concurrent preview and commit | The preview holds no locks. The commit's strong OCC revalidate runs against the original's row at commit time. A preview that started before the commit has its overlay installed (per-request global set, `the_posts` filter installed); a preview that starts after the commit sees the original's row updated to the staged values (the four fields now match the shadow's `after` directly, because the publication has landed and the original's row holds the staged values; the overlay is a no-op). The `pre_get_posts` callback's credential verification reads the journal op's state; an op in `publication_committed` state refuses the credential (the staging is no longer previewable — the publication has landed, the public URL is the post-publication value, and the preview URL is the original's URL, so the overlay would render the same as the public render). |
| F54 | `publish_edit` on a `OP_EDIT_PUBLISHED` op whose `before.post_status` is not `publish` | Strong OCC revalidate fails at step 4; the SQL transaction ROLLBACKs; the post-rollback refusal audit `publication_refused_baseline_drift` is written in a separate transaction; the op transitions to `conflict`. |
| F55 | `publish_edit` on a `OP_EDIT_PUBLISHED` op whose shadow has been deleted out-of-band | The shadow's `op_meta_value` is gone; the journal's `after.post_id` is now an orphan; the publish tool's preflight checks the shadow exists and refuses with `publication_refused_shadow_missing`. The post-rollback refusal audit is written in a separate transaction; the op transitions to `conflict`. |
| F56 | `publish_edit_reconcile` on a `staged` op | Refused; staged means the database write has not happened. The error names `agentshell_publish_edit`. |
| F57 | Hook-induced protected-preserved-field change during `wp_update_post` | A `save_post` / `wp_after_insert_post` hook writes to a *protected preserved field* — any of `post_status`, `post_author`, `post_parent`, `post_type`, `post_date`, `post_date_gmt`, `post_mime_type`, `comment_status`, `ping_status`, `menu_order`, `post_password`, `post_content_filtered`, `comment_count`, `to_ping`, `pinged`, `guid` (the named set in the §1.4 step 6 allowlist). The allowlist-based verification at step 6 finds a protected-preserved-field divergence: the post-write read shows the hook's value, not the baseline. The op transitions to `conflict`; the SQL transaction ROLLBACKs; the post-rollback refusal audit `publication_refused_hook_changed_protected_preserved_field` is written in a separate transaction with the actual field name, baseline value, and post-write value; the original is unchanged. **This is a publication conflict, not a successful publication with side effects** — the brief is explicit: "a hook that changes protected original fields must not be accepted as successful publication merely because the change originated in a hook." The previous classification of this case as `publication_committed_hook_changed_preserved_field` (a successful publication with audit) is removed: any hook-induced change to a protected field is a refusal, full stop. |
| F57a | Hook-induced supported-field overwrite during `wp_update_post` | A `save_post` filter writes to `post_title`, `post_content`, `post_excerpt`, or `post_name` (rare; an ACF field save to a supported field, an aggressive custom auto-save). The write-and-verify-C at step 6 finds a supported-field divergence: the post-write read shows the hook's value, not the shadow's `after`. The op transitions to `conflict`; the SQL transaction ROLLBACKs; the post-rollback refusal audit `publication_refused_hook_overwrote_supported_field` is written in a separate transaction; the original is unchanged. **This is a publication conflict, not a successful publication with side effects** — the operator's staged value is the contract, and a hook that overwrites it has broken the contract. |
| F57b | Allowlisted housekeeping change during `wp_update_post` | `wp_update_post` itself sets `post_modified` to `current_time('mysql')` and `post_modified_gmt` to `current_time('mysql', 1)` at the moment of the update. No hook or external code touched the row (the lock was held, the baseline-A revalidate passed). The allowlist-based verification at step 6 reads the row and finds: the four supported fields match the shadow's `after`; all protected preserved fields match the baseline; only `post_modified` and/or `post_modified_gmt` carry the read-back values. The op transitions to `publication_committed` (the *only* success state); the `transaction_published` audit row records the read-back `post_modified_gmt` (and, if a divergence arose, the `post_modified`) so the operator can see what `wp_update_post` actually wrote. **This is a successful publication with housekeeping audit** — the only housekeeping fields `wp_update_post` itself changes are `post_modified` and `post_modified_gmt`, and these are documented as WordPress-generated timestamps, not hook-induced changes. |
| F58 | `wp_update_post` writes `post_modified_gmt` to `NOW()` and the read-back value differs from any predicted value | The write-and-verify-C at step 6 reads the row's `post_modified_gmt` and uses the read-back value as the expected value; the comparison is the row to itself plus the four supported fields. A read-back `post_modified_gmt` is never "wrong" — it is what `wp_update_post` set. No prediction is made; no divergence can arise from a `NOW()` mismatch. `post_modified_gmt` is in the §1.4 step 6 allowlist, alongside `post_modified`, and is the only field whose post-write value is read rather than compared. |

---

## 5. Acceptance matrix (the 10-point brief)

| # | Brief requirement | Addressed in | Verified by |
|---|---|---|---|
| 1 | Staging approach choice | §1.1, §1.2 | Stub F36-F38; live F36-F38. |
| 2 | Preserve original identity; permit authorised staged fields; preserve all other fields | §1.4 step 5 (only the four supported fields are written to the original), §1.2 (shadow does not change original hierarchy), §1.5 (refusal rules), §1.3 (preview overlay preserves original identity), §1.4 step 6 (allowlist-based verification rule: hook-induced changes to protected preserved fields are refusals, not successful publications) | F53 (original-page identity preservation: `get_queried_object_id()`, `$post->ID`, page-specific templates, page ancestry, dynamic blocks, post metadata), F57 (hook-induced protected-preserved-field change is a refused publication), F57a (hook-induced supported-field overwrite is a refused publication), F57b (allowlisted housekeeping change is a successful publication with audit), F58: original `ID` is unchanged; the four supported fields (`post_title`, `post_content`, `post_excerpt`, `post_name`) hold the staged values after commit; `post_modified_gmt` is updated to the publication time (this is WordPress's own bookkeeping, not an AgentShell decision); **all other fields are unchanged**: `post_status=publish`, `post_author`, `post_type`, `post_parent`, `post_date_gmt`, `post_content_filtered`, `post_mime_type`, `comment_status`, `ping_status`, `menu_order`, `post_password`, taxonomy relationships, featured image, custom meta. **Hook-induced supported-field overwrites are publication conflicts** (F57a) and are refused with `publication_refused_hook_overwrote_supported_field`. **Hook-induced protected-preserved-field changes are publication conflicts** (F57) and are refused with `publication_refused_hook_changed_protected_preserved_field` — the brief is explicit: "a hook that changes protected original fields must not be accepted as successful publication merely because the change originated in a hook." The previous `publication_committed_hook_changed_preserved_field` state is removed; the only success state is `publication_committed`, which is reached when the four supported fields match the shadow's `after`, all protected preserved fields match the baseline, and any divergence is in the allowlisted housekeeping fields (`post_modified`, `post_modified_gmt`; F57b). F58 covers the refusal of `meta_input`, `tax_input`, and any non-supported field on the `edit_published` input schema. |
| 3 | Immutable baseline capture, OCC, conflict behaviour | §1.5 (strong OCC), §1.4 step 4 (baseline-A revalidate) and step 6 (write-and-verify-C) | F39, F40, F54, F57, F57a, F58. |
| 4 | Private authenticated previews that preserve original-page identity | §1.3 (identity-preserving overlay installed before the main query executes; field-level filters + `the_posts` substituted object; main query resolves the original, not the shadow) | F50, F51, F52, F53, F53a, F53b, plus the preview-overlay-spike in `tests/preview-overlay-spike.php`. The original-page identity invariants are tested by F53: `get_queried_object_id()` returns the original's id, `$post->ID` is the original's id, the original's page-specific template is selected, page ancestry is the original's, post metadata is the original's, dynamic block context resolves to the original's id, `wp_object_cache` is not poisoned, anonymous requests render the original's live values without 404. |
| 5 | Integration with journal, head-pointer CAS, Content_Write_Guard, lease, recovery, audit | §1.5 (op type, head), §1.4 (Content_Write_Guard), §1.7 (state machine, audit), §1.6 (claim), §1.6a (advisory lock) | F38, F41, F43, F44, F47. |
| 6 | Commit boundary: validation, atomicity, hooks/revisions/cache, failure | §1.4 (every step), §1.7 (state machine), §1.6a (advisory lock) | F41, F42, F43, F44, F45, F57. |
| 7 | Pre-publication rollback vs. post-publication undo | §1.8 | F47, F48, F49. |
| 8 | Remote production behaviour (no local shell, no Chromium) | §6.1 (no Chrome dependency for the commit path), §6.2 (hosting caveats), §6.3 (PHP / MySQL requirements) | Documented; not testable in disposable test bed. |
| 9 | Narrow scope: published pages, four fields | §1.5 (refusal rules) | F58: `edit_published` with `post_status` set is refused; with `meta_input` set is refused; with `tax_input` set is refused. |
| 10 | Concurrency with two AgentShell clients and an independent editor | §1.6 (claim, value-guarded SQL CAS), §1.6a (per-tx advisory lock), §1.4 (`SELECT ... FOR UPDATE` row lock + write-and-verify-C) | F38, F39, F40, F42, F46. |

---

## 6. Security and operational boundaries

### 6.1 No Chrome dependency for the commit path

The screenshot tool remains a preview aid. The commit path does not
require a browser. A remote production site with no shell access and
no Chromium binary can use `agentshell_edit_published` and
`agentshell_publish_edit` against the live database; what it cannot do is
*visually verify* the result before commit. The brief asks for this
to be assessed honestly: visual verification is a feature of the
preview path, not a precondition for the commit path.

### 6.2 Documented limitations

These are stated so the contract does not oversell:

- **Hooks and side effects during `wp_update_post` on the original**
  (e.g. ACF saves, search reindexers, webhook fires, email sends) are
  inside the SQL transaction in the sense that their database writes
  are rolled back on ROLLBACK, but their *external* side effects are
  not. A `save_post` hook that sent an email before the post-update
  revalidate noticed a divergence has already sent the email. The
  design does not retry or undo these; it documents them.
- **Persistent object caches (Redis, Memcached)** are not
  transactional. The post-commit cache invalidation step is
  best-effort and is retried, but a cache that was primed with a
  stale value and is unreachable at the moment of the retry will
  serve the stale value to visitors until it expires. A persistent
  cache that is down at the moment of the post-commit cache flush
  leaves the original's row correct but the cached view stale.
  Eventually-consistent, not exactly-once.
- **CDN purges** are not part of reconciliation. The CDN is outside
  the database and outside the WordPress process; the brief asks for
  this to be named. A site behind a CDN that has cached the pre-
  publication content will serve it until the CDN's TTL or until the
  operator manually purges. A future Stage 2d could add a
  reconciliation step that calls the CDN's purge API; that is out of
  scope here.
- **Third-party plugins that query the database directly** (a
  `get_field( $original_id )` call inside a `save_post` filter of
  *another* post, a search indexer's direct `SELECT`, an analytics
  plugin's `wp_posts` read) read the original's row, not the
  shadow's. The preview overlay cannot intercept these. The
  fidelity limitation is documented in the preview tool's response
  (`atomicity.not_covered` lists "third-party direct DB reads").
- **Sitemaps, feeds, search.** A shadow with `post_status=private` is
  not in any of these by construction. The original is unchanged
  during staging, so the original's sitemap entry, feed entry, and
  search-index entry are unchanged. A publish does not need to
  invalidate these (the original was already there); the only
  invalidation needed is the post's permalink's rewrite rules, which
  the slug change does require if the slug actually changed.
- **One staging per original per transaction.** The constraint is
  enforced by the journal: a transaction may have at most one
  `OP_EDIT_PUBLISHED` op against a given `original_id` in any
  non-terminal state. A second `edit_published` call against the
  same original in the same transaction *advances the shadow head*
  (constraint #3 of the fourth question), not creates a second op.
  The journal's head-pointer CAS is the durability mechanism for
  this.

### 6.3 Environment requirements

Stage 2c requires:

- WordPress 6.6+ (the install is 7.1.3; the design record's
  measurements are against this version).
- MySQL 5.7+ or MariaDB 10.0.1+ (for `GET_LOCK` and `RELEASE_LOCK`,
  which `Content_Recovery` already requires).
- InnoDB on every table the commit path touches (`wp_posts`,
  `wp_postmeta`, `wp_options`). The `Content_Write_Guard` already
  enforces this; the commit path inherits the check.
- No persistent object cache is required, but if one is present, the
  eventual-consistency limitation in §6.2 applies.

The engine preflight in `Content_Write_Guard::run()` is the single
check; if it fails, the tool refuses and does not degrade to
best-effort.

---

## 7. Open architectural decisions (require explicit approval)

These are decisions where the design has a default but the user
should sign off before implementation:

1. **Slug representation in the preview.** The four supported fields
   include `post_name`. A preview that overlaid the staged slug
   would change the rendered permalink. Two options: (a) overlay
   the slug (the preview shows what visitors will see after
   publish, including the new URL) but the request still hits the
   *original* permalink via the credential's `original_id`
   resolution; or (b) hold the staged slug in a separate field and
   do not overlay the permalink in preview. (a) is what the brief
   asks for ("Overlay ... the supported staged slug representation
   consistently"). I am proceeding on (a); flag if you want (b).
2. **Author change.** A change of `post_author` is out of scope
   (§1.5) but is a real concern in some workflows. If the operator
   needs to attribute the post to a different author, Stage 2c does
   not provide it. A future stage can extend the supported fields
   list with `post_author` and add the corresponding OCC field.
3. **Status transitions.** A change of `post_status` is out of
   scope. A `post_status=draft` post is a draft; the journal-aware
   path for it is `agentshell_create_draft` + `agentshell_update_draft`.
   A `post_status=publish` post is what Stage 2c edits. A
   `post_status=private` post is a private post and is also
   out of scope; the brief did not name it.
4. **One published original per transaction (Stage 2c's
   scope).** Stage 2c is intentionally narrow: a
   transaction may have at most one
   `OP_EDIT_PUBLISHED` op against one original.
   Sequential multi-page partial publication, mixed
   published/draft commits, and bulk publication of
   many originals in one transaction are **out of
   scope for Stage 2c** (see §9a). A future stage
   could extend the op type to support bulk
   publication (one transaction, many originals,
   each with its own op, claim, shadow, publication,
   and reconciliation), but that is not Stage 2c.
   The flag for you to consider is: do you want
   Stage 2c to keep the single-original scope, or
   do you want bulk publication in scope from the
   start?
5. **Claim TTL.** The default claim TTL is 60 minutes, mirroring
   the transaction lease. A `staged` op whose transaction expires
   leaves the claim held; the next `agentshell_edit_published` for
   the same original must reclaim. The brief said the claim must
   not be stolen while work is unresolved; the design preserves
   that. The TTL itself is configurable per claim (it is recorded
   in the claim row), but no UI exposes it; the default is 60
   minutes. Flag if you want a different default.

---

## 8. Implementation sequence (testable milestones)

The implementation is split into five commits, each independently
testable. The boundary between commits is "the new code can be
deployed and reverted without breaking the previous stage's
guarantees".

### Commit 1 — Journal op type, claim row, head-pointer

- New op type `OP_EDIT_PUBLISHED` in
  `agentshell-mcp/includes/class-content-journal.php`. Strong
  baseline shape; head-pointer shape; reconciliation shape.
- New claim row helpers in
  `agentshell-mcp/includes/class-published-claim.php`:
  `create_atomic`, `cas_renew`, `cas_release`, `is_held_by`,
  `reclaim_if_unresolved`.
- `agentshell_edit_published` tool. Stub tests (Edit-1 to Edit-20,
  in `tests/journal-recovery-test.php`); live tests F36, F37, F38
  in `tests/journal-recovery-live-test.php`.
- `agentshell_get_journal` returns the new op type's fields.
- `agentshell_rollback_transaction` recognises the new op and
  routes through the pre-publication rollback path.
- **Verification**: stub F36-F38, Edit-1 to Edit-20 green;
  `tests/journal-recovery-mutation.php` adds M-EP1..M-EP-N
  (claim-row CAS, head-pointer CAS, refused fields, refused
  state) killed; live F36-F38 green; full run-all green;
  checkout integrity holds.

### Commit 2 — Preview overlay (identity-preserving) and post-preview credential

- Post-preview credential extended to bind (original id, shadow
  id, transaction id, actor, expires). The existing
  `Post_Preview::preview_args()` and `Post_Preview::authenticate()`
  gain the new fields; the HMAC includes them; verification
  rejects on mismatch.
- A new public query var `agentshell_preview` is registered at
  the `query_vars` filter. The credential itself is *not* a
  query var (it is stashed in a per-request global so the
  cached query does not leak it).
- At `init` (or `after_setup_theme`), the field-level overlay
  filters are installed unconditionally: `the_title`,
  `the_content`, `the_excerpt`, and the permalink family
  (`pre_post_link`, `post_link`, `post_type_link`,
  `page_link`, `_get_page_link`, `the_permalink`). The
  callbacks are *request-scoped no-ops* unless the
  per-request global is set by a verified HMAC. Installation
  at `init` is required so the filters fire for any code
  path that reads the post via the persistent
  `wp_object_cache` (e.g. a `get_post()` call in a block
  render that runs before the template loader, a
  `get_post_meta($original_id, ... )` call inside a
  dynamic block). The callbacks check the per-request
  global and the current post id; if both match the bound
  `(original_id, shadow_id, tx_id, actor)` tuple, they
  overlay the four fields from the shadow's `wp_posts`
  row (read once per request, cached in the per-request
  global).
- A `pre_get_posts` action callback verifies the credential
  against the journal op's (original, shadow, tx) triangle
  and, on a valid credential, stashes the verified
  `(original_id, shadow_id, tx_id, actor)` in the
  per-request global and registers a `the_posts` filter
  callback via `add_filter( 'the_posts', $callback, 10,
  2 )`. The callback is registered to run for the main
  query only (`$query->is_main_query()` is the first
  check). The main query is **not** rewritten; it
  continues to resolve the *original* (a
  `post_status=publish` post that the query naturally
  resolves from the request URL). When
  `WP_Query::get_posts()` returns the original's row,
  the `the_posts` filter substitutes the result's
  `WP_Post` object with a fresh instance whose identity
  fields (`ID`, `post_type`, `post_status`,
  `post_author`, `post_parent`, `post_date`,
  `post_date_gmt`, `post_modified`, `post_modified_gmt`,
  `post_content_filtered`, `post_mime_type`,
  `comment_status`, `ping_status`, `menu_order`,
  `post_password`, `ancestors`) come from the *original*
  and whose four staged fields (`post_title`,
  `post_content`, `post_excerpt`, `post_name`) come from
  the *shadow*. The substituted object lives in
  `$wp_query->posts[0]` for the lifetime of the request
  only; it is **not** written to `wp_object_cache`. The
  persistent post cache is populated by
  `WP_Query::get_posts()`'s own `update_post_caches()`
  call with the *original*'s row, keyed by the
  original's id. **The identity of the queried post is
  the original's: the `the_posts` filter does not
  change the id, type, status, parent, ancestors, or
  metadata, only the four staged fields.**
- `template_redirect` priority 1 applies response headers
  (`X-Robots-Tag: noindex, nofollow`, `Cache-Control:
  no-store, private, must-revalidate`, `Pragma: no-cache`,
  suppressed `Last-Modified`) and disables
  `redirect_canonical` for the request's lifetime (so a
  non-canonical request to the original's URL with the
  credential attached does not 301 to a different URL
  mid-preview). WordPress's canonical-redirect callback
  is `redirect_canonical`, not `wp_canonical_redirect`.
- The preview-overlay-spike at
  `tests/preview-overlay-spike.php` is run first against
  the disposable test bed; the spike's positive result
  is the evidence the overlay path is feasible before
  production wiring. If the spike fails, Commit 2's
  design is revised before implementation. The spike
  asserts: (a) the four staged fields are overlaid in
  the response body; (b) the original-page identity is
  preserved (`get_queried_object_id()` returns the
  original's id, `$post->ID` is the original's id, the
  original's page-specific template is selected, page
  ancestry is the original's, post metadata is the
  original's, dynamic block context resolves to the
  original's id); (c) anonymous requests render the
  original's live values without 404; (d)
  `wp_object_cache` is not poisoned for the original
  post.
- **Verification**: stub F50, F51, F52, F53, F53a, F53b
  in `tests/journal-recovery-test.php`; live F50, F51,
  F52, F53, F53a, F53b in
  `tests/journal-recovery-live-test.php`. New
  `tests/preview-overlay-test.php` (F53 fidelity tests
  against a real WordPress with a child theme that
  exercises original-page identity, block rendering,
  ancestors, and a `save_post` filter that writes to a
  preserved field; F53a anonymous-request boundary
  tests; F53b concurrent-preview-during-commit tests).
  Full run-all green; mutations killed.

### Commit 3 — `agentshell_publish_edit`: short protected SQL transaction + advisory lock

- `agentshell_publish_edit` tool. The full §1.4 sequence:
  `Recovery_Advisory_Lock::acquire('agentshell_publish_lock_<tx_id_hash>')`,
  lease validation, `Content_Write_Guard::run()`, **`SELECT ...
  FOR UPDATE` row lock** + strong OCC revalidate against the
  baseline (post_type, post_status, post_name, post_title,
  post_content, post_excerpt, post_date_gmt,
  post_modified_gmt, plus SHA-256 of post_content computed from
  the read), `wp_update_post(original, shadow_fields)`,
  write-and-verify-C (the same eight fields re-read and
  compared against the expected resulting state C, not against
  the baseline — the four supported fields must match the
  shadow's `after`, the preserved fields must match the
  baseline, the `post_modified_gmt` is the read-back value not
  a prediction), journal transition to `publication_committed`,
  `transaction_published` audit row, COMMIT. **No separate
  conditional publication CAS step is run** — the row lock and
  the baseline-A revalidate at step 4 are the entire
  publication-side guarantee against concurrent writers, and
  the write-and-verify-C at step 6 catches the lost race
  against a writer that bypassed the lock.
- **Allowlist-based verification rule** at step 6
  (replaces the prior "hook-induced vs external-concurrency"
  classification): a divergence between the post-write
  row and C is classified by which `wp_posts` columns
  changed. Three categories, three dispositions:
  - **Supported-field divergence** (one of
    `post_title`, `post_content`, `post_excerpt`,
    `post_name` differs from the shadow's `after`):
    refused with
    `publication_refused_hook_overwrote_supported_field`,
    ROLLBACK + post-rollback audit in a separate
    transaction, op to `conflict`.
  - **Protected-preserved-field divergence** (one of
    the named set in §1.4 step 6 — `post_status`,
    `post_author`, `post_parent`, `post_type`,
    `post_date`, `post_date_gmt`, `post_mime_type`,
    `comment_status`, `ping_status`, `menu_order`,
    `post_password`, `post_content_filtered`,
    `comment_count`, `to_ping`, `pinged`, `guid` —
    differs from the baseline): refused with
    `publication_refused_hook_changed_protected_preserved_field`,
    ROLLBACK + post-rollback audit in a separate
    transaction, op to `conflict`.
  - **Allowlisted housekeeping divergence only**
    (`post_modified` and/or `post_modified_gmt`
    differ from the read-back — these are the only
    two fields `wp_update_post` itself sets, and the
    read-back value is the expected value): the op
    transitions to `publication_committed` (the *only*
    success state), the `transaction_published`
    audit row records the read-back
    `post_modified_gmt` (and, if a divergence arose,
    the `post_modified`).
  The previous `publication_committed_hook_changed_preserved_field`
  state is removed: any hook-induced change to a
  protected preserved field is a refusal, not a
  successful publication with side effects. The
  previous `publication_refused_write_lost_race`
  case is removed: external writes that happened
  *before* the lock are caught at step 4 (baseline-A
  OCC failure); while the lock is held, the only
  mechanism that could have changed the row is a hook
  or a WordPress-internal transformation, and the
  rule above classifies both by the field they
  touched.
- **Post-rollback audit durability** (the post-rollback
  audit-durability rule from §1.6a): all refusal audit rows
  (`publication_refused_baseline_drift`,
  `publication_refused_hook_overwrote_supported_field`,
  `publication_refused_hook_changed_protected_preserved_field`,
  and any other pre-COMMIT refusal) are written *after* the
  rolled-back §1.4 SQL transaction, in a separate short
  guarded transaction. A ROLLBACK of the publication
  transaction therefore does not discard refusal evidence.
  The success audit row (`transaction_published`) is
  written inside the §1.4 SQL transaction; a ROLLBACK rolls
  it back, and that is correct (the publication did not
  land). The success state is inside the transaction; the
  failure state is outside.
- **Per-tx advisory lock** held from `publish_edit` step 1
  through the end of reconciliation, released in `finally`.
  The recovery / rollback / lease-reaper paths acquire the
  same lock before their destructive sections, so a second
  PHP process cannot delete a shadow while publication is
  actually running.
- The `SELECT ... FOR UPDATE` row lock is taken at step 4 and
  held until COMMIT. The hook chain (`save_post` /
  `wp_after_insert_post` / `transition_post_status` /
  `post_updated`) runs while the lock is held; external
  `wp_update_post` calls on the same row block. Documented in
  §6.2 as a short blocking wait for ordinary editors.
- **Verification**: stub F41, F42, F54, F55, F57, F57a,
  F57b, F58; live F41, F42, F54, F55, F57, F57a, F57b, F58;
  mutations on the strong OCC, the row lock, the
  write-and-verify-C, the **allowlist-based verification
  rule** (three categories: supported-field divergence →
  refusal; protected-preserved-field divergence → refusal;
  allowlisted housekeeping only → success), the
  post-rollback audit-durability rule, the per-tx
  advisory lock, the audit; full run-all green. The
  `publication_committed_hook_changed_preserved_field`
  state is removed from the op state machine; the
  `publication_refused_write_lost_race` refusal is
  removed from the publish path (the external-writer
  case is fully covered by step 4's
  `publication_refused_baseline_drift`).

### Commit 4 — `agentshell_publish_edit_reconcile`, recovery path

- `agentshell_publish_edit_reconcile` tool. Per-step status
  record drives the retry. Idempotent for all three steps.
  Acquires the per-tx advisory lock (refuses with
  `transaction_publish_reconcile_refused_publisher_active`
  if the publisher is still running).
- `agentshell_recover_transaction` recognises
  `publication_committed` and `reconciliation_pending` states
  and runs reconciliation, not re-publication. Acquires the
  per-tx advisory lock.
- `agentshell_rollback_transaction` (for a pre-publication
  rollback of a `staged` op) acquires the per-tx advisory
  lock before deleting the shadow.
- **Claim release uses the crash-safe ordering from §1.7 step
  3**: set `reconciliation.claim_released = 'attempted_at:<time>,
  status:pending'`, run `cas_delete_row` in the same SQL
  transaction, write the `claim_released` audit row in a
  separate short transaction after the SQL COMMIT, transition
  the op's `state` to `publication_settled` in a separate
  short transaction after the audit write. **The audit row is
  not written before the `cas_delete_row` returns** — that
  ordering would lie about a release that did not happen.
  **The retry re-verifies the claim row authoritatively via
  direct SQL** (a `SELECT option_value FROM wp_options WHERE
  option_name = %s` via `$wpdb->get_var()`), not via
  `get_option()`. `get_option()` is cache-backed and may
  return a stale value after the CAS; the direct SQL read is
  the source of truth. The verification classifies into three
  outcomes: claim absent (release confirmed, write audit),
  claim still owned by this transaction (anomalous, refuse
  with `publication_settle_refused_claim_release_anomaly`
  and require manual investigation), or claim owned by a
  successor transaction (record
  `transaction_claim_released_successor_took_over`, do
  *not* delete the successor's claim, transition the op to
  `publication_settled`).
- **Verification**: stub F43, F44, F45, F46, F47, F48, F49;
  live F43, F44, F45, F46, F47, F48, F49; mutations on
  the state transitions, the per-step idempotence, the
  CAS release, the **authoritative direct-SQL re-read**
  (not `get_option()`), the **three-outcome classification**
  (absent, owned-by-this, owned-by-successor), the
  crash-safe ordering, the per-tx advisory lock; full
  run-all green.

### Commit 5 — Pre-publication rollback, conflict paths, doc updates

- `agentshell_rollback_transaction` extended to refuse a
  `publication_committed` op (with the
  `publication_committed_after_rollback_refused` audit row and
  a message naming `agentshell_recover_transaction`).
- `OP_EDIT_PUBLISHED` ops in `conflict` state are preserved
  (existing journal behaviour) and the operator is told to
  resolve.
- `AGENTS.md` §6.1, §6.4 updated to name the new tool, the
  new op type, the new state machine, the strong OCC, the
  reconciliation contract, and the documented limitations.
- `docs/CONTENT-TRANSACTIONS-DESIGN.md` updated to mark
  Stage 2c implemented (the design record at the head of this
  document was the proposal; the implementation is here).
- `docs/STAGE-2C-IMPLEMENTATION.md` (a new acceptance doc
  parallel to `STAGE-2B-ACCEPTANCE.md`) records the
  acceptance matrix, the test results, the mutation results,
  the reconciliation state-machine coverage.
- **Verification**: F47, F48, F49, F56; full run-all green;
  doc gates 1-9 of `AGENTS.md` §9 pass; no "10 tools" /
  "11 tools" / "12 tools" count; no duplicate architecture
  diagram; the design record is marked as implemented.

---

## 9. Boundaries preserved

### 9a. Initial scope: one published original per transaction

Stage 2c's `OP_EDIT_PUBLISHED` op applies to **exactly
one published original per transaction**. The
`edit_published` tool refuses if the same transaction
already has an `OP_EDIT_PUBLISHED` op against a
*different* `original_id` in any non-terminal state.
The `publish_edit` tool refuses if the calling
transaction has more than one `OP_EDIT_PUBLISHED` op
in `staged` state. **Sequential multi-page partial
publication and mixed published/draft commits are
out of scope for Stage 2c.** A future stage could
extend the op type to support bulk publication (one
transaction, many originals, each with its own op,
claim, shadow, publication, and reconciliation), but
that is not Stage 2c.

The implications:

- A Stage 2c transaction is either a `create_draft`
  transaction (one or more drafts) or an
  `edit_published` transaction (exactly one edit).
  A transaction cannot mix the two op types in a
  single `commit_transaction` call. The
  `commit_transaction` tool's existing journal gate
  is extended to refuse a mixed op-set at the
  transaction-close boundary with
  `transaction_commit_refused_mixed_op_types`.
- The existing immediate-write content tools
  (`agentshell_create_page`, `agentshell_create_post`,
  `agentshell_update_content`,
  `agentshell_update_post_content`, `agentshell_publish`,
  `agentshell_unpublish`) continue to refuse inside
  a transaction regardless of whether the
  transaction has an `OP_EDIT_PUBLISHED` op. The
  *only* journal-aware content tool for the edit is
  `agentshell_edit_published`.
- The head-advancement rule in §2.1 (same key +
  same payload resumes, same key + different payload
  refuses, different key advances the head) is per-
  original; the same transaction cannot stage
  multiple originals.
- The claim row is per-original; one claim row
  per Stage 2c transaction.

### 9b. Private-shadow access model

The shadow is a real `wp_posts` row with
`post_status=private` and a `_agentshell_shadow_of`
meta. The brief required explicit definition of
who can see it.

- **Anonymous visitors.** A request to the
  shadow's permalink returns 404 (the shadow has
  no public permalink; the post URL is the
  *original*'s, not the shadow's). A request to
  the original's permalink without a valid
  `Post_Preview` credential renders the original's
  live values; the shadow's content is not
  reachable.
- **WordPress authenticated users without
  `read_private_posts` capability.** Same as
  anonymous: the shadow is invisible. The shadow
  does not appear in REST, search, feeds, archives,
  or the front-end; the admin post list filters it
  out by `post_status=private`.
- **WordPress authenticated users with
  `read_private_posts`** (e.g. an editor,
  administrator, or any role the site owner has
  granted the cap to). The shadow is visible in
  the WordPress admin's edit-post screen at
  `/wp-admin/post.php?post=<shadow_id>&action=edit`.
  **This is a WordPress consequence of
  `post_status=private`, not an AgentShell
  decision.** The brief required us to name it:
  a privileged user with admin access can read
  the shadow's `post_title`, `post_content`,
  `post_excerpt`, and `post_name` through the
  standard WordPress edit-post UI. The shadow's
  `_agentshell_shadow_of` meta is also visible in
  the custom-fields panel.
- **WordPress `wp-json` REST API.** Anonymous
  REST requests return 401 for the shadow id
  (private posts are not in the public REST
  index). Authenticated requests with
  `read_private_posts` can read the shadow via
  `/wp-json/wp/v2/posts/<shadow_id>`; the response
  carries the shadow's `post_status=private`.
  AgentShell's preview path is the only
  *preview*-shaped access; the REST API access
  is the standard WordPress behaviour, not an
  AgentShell extension.
- **The AgentShell preview path.** A request to
  the *original*'s permalink with a valid
  `Post_Preview` credential bound to the
  (original, shadow, tx, actor, expires)
  triangle renders the original's template with
  the shadow's four supported fields overlaid,
  per §1.3. This is the only preview-shaped
  access. The shadow's other fields (custom
  meta, taxonomy, featured image) are *not*
  overlaid; the brief's four-field scope
  applies here too.

The shadow's `post_status=private` is the
WordPress-native way to keep a post out of the
public render while still being a real
`wp_posts` row that the journal can reference.
The cost is that a privileged WordPress user
with `read_private_posts` can read the shadow
through the admin UI; this is a WordPress
behaviour the design does not and cannot
prevent. The design does not promise
unreadability of the shadow to a privileged
WordPress user; it promises the four
guarantees the brief asked for: (a) the
public permalink of the original is not
changed by staging, (b) anonymous visitors
see the original's live values, not the
shadow's, (c) the shadow is not in any
front-end collection (search, archives,
feeds, REST index), and (d) the preview
path is the only path that overlays
shadow content onto the original's render.

### 9c. Slug change and permalink behaviour

A `post_name` change in the staged payload
**changes the original's permalink on
publication**, because `post_name` is the slug
that WordPress's rewrite rules turn into the
public URL. The behaviour:

- **During staging (op in `staged` state).** The
  original's `post_name` is unchanged. The
  original's permalink serves the original's
  live content. The preview path overlays
  the shadow's `post_name` for the permalink
  links *in the response* (the `<link
  rel="canonical">`, the `get_permalink()`
  output in the navigation, the post URL in
  the JSON-LD structured data), so a preview
  reader sees the post- publication permalink
  in the response body. The preview URL
  itself is the *original*'s permalink, with
  the credential as a query parameter —
  the original's URL during staging is the
  only way to reach the preview.
- **At publication.** The original's `post_name`
  is updated to the staged value. WordPress's
  rewrite rules are regenerated; the new
  permalink is live.
- **After publication.** A request to the
  *old* permalink (the original's pre-
  publication URL) is handled by WordPress's
  `wp_old_slug_redirect` (if the site has
  `wp_old_slug_redirect` enabled, which is the
  default for permalink-based post types) and
  redirects 301 to the new permalink. **The
  old permalink is a 301 redirect, not a
  preview of the staged content.** The staged
  content is now the live content at the new
  permalink; the old permalink serves the
  pre-publication content only if
  `wp_old_slug_redirect` is disabled (a non-
  default site configuration), in which case
  the old permalink 404s. The brief required
  this limitation to be named.

### 9. Boundaries preserved

- **No `agentshell_delete_content` tool** is added. The brief did
  not ask for it, and the design record §4.6 already proposed its
  shape; that work remains a separate Stage 2+.
- **No content update tool other than `agentshell_update_draft` is
  added.** The existing `agentshell_update_content` and
  `agentshell_update_post_content` continue to refuse inside a
  transaction by name. Stage 2c adds `agentshell_edit_published`,
  not a general `update_published`; the field set is narrow and
  named.
- **No status transition tool is added.** The existing
  `agentshell_publish` (immediate-write status change:
  draft/pending/private/future → publish) and
  `agentshell_unpublish` continue to refuse inside a
  transaction by name. The Stage 2c tool is
  `agentshell_publish_edit`, a different verb (it
  publishes an *edit* to an existing published post,
  not a status change on a draft). The two tools
  have different inputs (`agentshell_publish` takes
  `id, type`; `agentshell_publish_edit` takes
  `original_id, [shadow_id from the journal]`),
  different preconditions (`agentshell_publish`
  requires a non-`publish` post; `agentshell_publish_edit`
  requires an `OP_EDIT_PUBLISHED` op in `staged`
  state), and different effects (`agentshell_publish`
  changes the post's `post_status`; `agentshell_publish_edit`
  updates the four supported fields of a `publish`-
  status post). The name collision between
  `agentshell_publish` and `agentshell_publish_edit`
  is intentional: the verb is shared, the noun is
  different, and the input schema disambiguates
  which one the agent called.
- **No navigation staging, no taxonomy staging, no meta-input
  staging.** Out of scope.
- **No push, no merge, no main changes.** Local commits on
  `stage/2c-published-content-editing` (a new branch off
  `stage/2b-existing-draft-editing`).
- **No MPM changes.** Stage 2c is a self-contained change to the
  plugin and the test bed.
- **No change to the dev site.** Disposable test bed only.
- **`docs/_archive/AUDIT-2026-09-13.md` preserved.**
- **`jr-mut/` preserved.**

---

## 10. The honest list of unresolvables

These cannot be made atomic by Stage 2c. They are named here so
they are not promised elsewhere.

- A `save_post` hook that sends an email, fires a webhook, or
  reindexes a third-party system is not undone by a ROLLBACK.
  Stage 2c documents this and does not promise otherwise.
- A persistent object cache that is unreachable at the moment of
  the post-commit cache flush serves stale content until it
  expires. Eventually-consistent, not exactly-once.
- A CDN that has cached the pre-publication content serves it
  until the CDN's TTL or until the operator manually purges.
  Out of Stage 2c scope; documented.
- A third-party plugin that reads `$original_id` directly from
  the database during a preview reads the original's value, not
  the shadow's. The four supported fields are the public contract;
  any other field is documented as not overlaid.
- After durable publication, no automatic undo. A human operator
  who wants the pre-publication content back edits the post (or
  opens a new `agentshell_edit_published` transaction). There is
  no general-purpose "make the public un-see what they saw"
  capability, and Stage 2c does not promise one.
