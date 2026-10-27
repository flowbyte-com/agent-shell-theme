# AgentShell Forms — Architecture and Implementation Plan

**Status: design record.** Nothing in this document is implemented. It records
an investigation, a recommendation and the evidence for it. Where it describes
behaviour, that behaviour is *proposed*; where it describes the current
system, it is *observed*. The two are marked separately throughout, and the
current system is authoritative — not this file.

**Date:** 2026-10-27
**Baseline:** `main` at `3e0cdf4` (the #22–#35 stack, integrated)
**Scope:** architecture and a phased plan only. No code, no database tables,
no endpoints, no credentials.

---

## 1. Executive summary

### 1.1 The recommendation in one paragraph

AgentShell Forms should be **a new, self-contained WordPress plugin,
`agentshell-forms`**, not a part of the theme, not a part of the MCP plugin and
not a part of `agentshell-blocks`. The plugin owns the form schema, the
definition store, the submission pipeline, the notification path, the Turnstile
integration and the visual builder. It depends on **nothing** in AgentShell: it
works on plain WordPress with any theme. It integrates with AgentShell through
**three one-way seams** — a shortcode, a `WP_Widget`, and (in a later phase)
one new zone block type — and through the existing
`agentshell_mcp_register_tools` filter for the agent surface. Form definitions
are stored as a **custom post type** (`agentshell_form`) whose definition
lives in **post meta**, which gives native WordPress capabilities, native
trash and a native history mechanism — with the revision support registered
explicitly, because WordPress does **not** version arbitrary custom post meta
by itself (§5.8). Submissions are
stored as a **second, separate custom post type** (`agentshell_entry`),
which keeps personal data out of the definition store and out of AgentShell's
existing content tools. The plugin owns **two** small tables for ephemeral
protocol state (single-use submission challenges, and rate-limit counters),
because that state must be claimed and counted atomically and neither the
options table nor transients can do that.

### 1.2 The MVP in one paragraph

An administrator configures a contact form in a visual builder at
**Appearance → AgentShell Forms**, inserts it into a page with the shortcode
`[agentshell_form slug="contact"]`, optionally protects it with Cloudflare
Turnstile, and receives submissions by email. Submissions land in
**Submissions**, where an authorised administrator can read them, export them
as CSV, and delete them. The definition has a **draft** and a **published**
state; nothing an editor or an agent changes reaches a visitor until it is
published. Every submission is validated on the server against the *published*
definition, stored with the version it was validated against, and delivered
once — a failed email does not produce a second submission.

**An agent is not among the readers.** Submission contents are not reachable
through the agent surface under the default policy, and the capability that
would allow it is not granted to the administrator role as a whole (§8.3).

### 1.3 The shape of the work

Six independently reviewable PRs. Phase 1 is the only hard blocker; after it,
four phases can proceed largely in parallel.

| Phase | Deliverable | Depends on |
|---|---|---|
| 1 | The plugin foundation, the schema, the validator, the storage invariants | — |
| 2 | Rendering, the shortcode, the widget, accessible markup | 1 |
| 3 | The submission pipeline, notifications, Turnstile, the submissions screen | 1, 2 |
| 4 | The visual builder | 1, 2 |
| 5 | The MCP tool surface | 1 |
| 6 | The AgentShell zone-block integration and release packaging | 2 |

### 1.4 The five decisions that matter most

1. **The engine is a plugin, not theme code.** The theme is the presentation
   layer and its shell files are read-only at runtime by contract. A forms
   system that must work on any theme cannot live there.
2. **Definitions are a post type with meta, not a configuration option.** The
   configuration option is the *theme's* configuration; a form is content, and
   the configuration's revision ring, snapshot bounds and transaction machinery
   are not the right instruments for it.
3. **Submissions are a separate post type from definitions.** "Keep form
   definitions separate from submitted data" is not only a privacy requirement
   here — it is also what keeps personal data out of the tools that already
   exist for content.
4. **Nothing reaches a visitor until it is published.** The draft/published
   split replaces the configuration transaction for this domain, and gives an
   agent the same Preview → Save → Discard semantics the configurator has.
5. **Reading personal data is a separate authority from editing forms.** An
   agent that can create a form does not thereby gain the right to read what
   people wrote into it — and the boundary is enforced by a **capability the
   agent's principal may not hold**, not by a flag the agent supplies for
   itself (§8.3). Submission-content access through the agent surface is
   **off by default**, and no agent principal receives automatic access to
   personal submission data (§8.2a).

**What a focused review changed.** A review of this document corrected eight
details without changing the architecture: the submission post type's name
(WordPress caps a post type key at 20 characters), the form identifier and
slug formats, how native WordPress revisions actually work, the
compare-and-swap on post meta, the exact guarantees the public challenge and
the rate limiter give, what an explicit authorisation flag can and cannot
enforce, the difference between a notification being *accepted* and being
*delivered*, and two documentation claims that overstated what the design
does. §16 records each one, with its revised decision.

**What the owner's decisions changed.** Four policy decisions have since been
made, and they are recorded in §17: the personal-data boundary is a WordPress
capability with submission-content access **off by default** (Q11); the
submissions capability is **not** granted to the administrator role and is
assigned **per user**, with a permission-checked setup procedure that needs
no second account (Q12); the notification claim TTL is **fifteen minutes**
with a transport timeout **strictly shorter** than it (Q13); and a form's
slug is **frozen after its first publication**, with the immutable internal
identifier as the long-lived reference (Q14).

### 1.5 What this document deliberately does not do

It does not design file uploads, conditional logic, multipage forms, payment
processing, calculated fields, a workflow engine, or an SMTP transport. Those
are future capabilities, and §11.8 and §15.3 record the security and
migration work each of them would owe before it could be proposed.

---

## 2. Existing architecture findings

Everything in this section is **observed** in `main` at `3e0cdf4`.

### 2.1 The three layers

**The theme** (`functions.php`, 5,055 lines; `header.php`; `footer.php`;
`template-parts/shell-render.php`; `style.css`; `configurator/`) is the
presentation layer. It owns:

- the configuration (`wp_options['agentshell_config']`), seeded from
  `default-config.json` on activation and self-healing on every read;
- the zone model — three fixed zones (`header`, `main`, `footer`), the
  nine-area slot grid for header and footer, and a vertical composition for
  `main`;
- the bilateral widget registry — file widgets in `widgets/.index.json` merged
  with config-registered widgets and lifecycle overrides;
- the block renderer — `template-parts/shell-render.php` dispatches five block
  types (`wp_loop`, `wp_core`, `widget`, `json_block`, `wp_widget_area`);
- the configurator — six plain-JS files under `configurator/`, no build step,
  talking to REST routes under `wp/v2/agentshell/`;
- the preview-credential machinery — the secret rule (`AGENTSHELL_REST_TOKEN`,
  then `AUTH_SALT`, then nothing at all) and the 900-second credential window.

**The MCP plugin** (`agentshell-mcp/`) is the agent transport. It publishes a
JSON-RPC endpoint over REST at `/mcp`, authenticates with an application
password or a static token, and requires the `manage_options` capability. Its
tool surface is the agent-facing API of the whole system; the authoritative
list and count live in `AGENTS.md` §2. Tools are plain classes implementing
four methods (`get_name`, `get_description`, `get_input_schema`,
`execute`) and are registered through the `agentshell_mcp_register_tools`
filter. There is **no** `requires_transaction()` or `capability()` method on
the base class: transaction semantics and permission checks are expressed
behaviourally inside `execute()`.

**`agentshell-blocks`** is, despite its name, a **widget** plugin. It
registers three MCP tools, offers the `[agent_block id="…"]` shortcode, and
provides a view over the canonical widget registry. There is **no**
`register_block_type`, no `block.json`, and no Gutenberg block anywhere in the
repository. In AgentShell, "block" means an entry in a zone's block array.

### 2.2 Storage today

The whole system stores its state in six options and one table:

| Key | Holds |
|---|---|
| `agentshell_config` | the configuration: zones, design tokens, custom CSS/JS, widget registry |
| `agentshell_revisions` | the revision ring: `{counter, items, deleted}` — 25 items plus a tombstone map |
| `agentshell_snapshots` | named configuration snapshots — 20 items |
| `agentshell_audit_log` | the configuration event ring — 100 entries |
| `agentshell_theme_profiles` | saved theme profiles |
| `agentshell_transaction` | the open transaction row |
| `wp_agentshell_mcp_audit_log` | the per-MCP-call audit table (a real table, created with `dbDelta`) |

There are **no custom post types and no taxonomies** in the repository.
Introducing a post type is therefore a new architectural element for this
codebase, and it is treated as one here.

### 2.3 Transactions, staging and the preview credential

`Transaction_Manager` owns one option row (`agentshell_transaction`) and
implements:

- **staging** — `stage()` writes a staged configuration into the row; every
  write to `agentshell_config` is intercepted by the
  `pre_update_option_agentshell_config` filter, so a write inside a
  transaction lands in the buffer and a write outside one lands in the
  database. The filter *transforms* rather than vetoes: it returns the old
  value to cancel the write.
- **a lease** — 60 minutes of inactivity, renewed only by the owner; an
  expired transaction stops staging and can be reclaimed, and a transaction
  holding durable work is never reclaimed.
- **compare-and-swap** — the row is opened with `add_option()` (an `INSERT`
  against the unique key on `option_name`) and written with a value-guarded
  `UPDATE … WHERE option_value = <expected>`. A lost race is refused, never
  overwritten.
- **a preview credential** — signed with one secret chosen by one rule, with
  a 900-second window that deliberately reads the real clock rather than the
  freezable one, and an expired transaction refused *before* the signature is
  checked.

### 2.4 Permissions and the two-part authority pattern

Three different capability checks are in use, and they mean different things:

- the MCP transport requires `manage_options` for every call;
- the theme's REST routes require `edit_theme_options`;
- individual tools check narrower capabilities — `current_user_can()` for
  `manage_options`, and the **per-post** `delete_post` capability checked
  against the specific post, re-checked at the moment of destruction.

The most instructive pattern in the codebase is the **two-part authority**
used by the content journal. Read authority and destructive authority are
*different*, and both are expressed as a role returned by a method:
`'owner'` (the transaction's actor, re-checked for `manage_options` now) or
`'recovery_administrator'` (somebody else, with `manage_options` *and* an
explicit `authorize_inspection` / `authorize_recovery` argument). The refusal
is deliberately **indistinguishable from absence**: an unauthorised inspection
returns the same shape as "no transaction is open", writes no audit row, and
discloses nothing. Destructive authority additionally requires `confirm: true`
— required of the owner too.

### 2.5 Audit

Two systems, with different shapes:

- `Store::record_event( $actor, $operation, $changed )` appends to the
  configuration event ring (100 entries). `Store::record_mutation()` also
  writes a revision and derives a human description of the change with
  `describe_change()`, which returns an empty string rather than inventing a
  description it cannot justify.
- The MCP transport writes one row per tool call to `wp_agentshell_mcp_audit_log`,
  with argument redaction for keys matching password/secret/token/key patterns.

### 2.6 The configurator

The configurator is the existing visual editor, and its structure is the
pattern a form builder should imitate:

| Module | Responsibility |
|---|---|
| `configurator-state.js` | pure models — tokens, transaction mirror, session model, zone model, draft model. No DOM, no network. Exports via `module.exports` so Node tests can exercise it. |
| `configurator-api.js` | the only module that knows REST shapes; attaches `X-WP-Nonce` and the `X-AgentShell-Editor-Session` capability. |
| `configurator-ui.js` | the view; builds DOM, holds no state. |
| `configurator-live-style.js` | a browser-local overlay that writes design tokens onto the page's root element as CSS custom properties, validating each value with the browser's own CSS parser. |
| `configurator-app.js` | the application layer; the only module that knows both models and DOM. |

Its behavioural lessons, which the forms builder must inherit:

- **Dirty is computed from the data**, never from a flag.
- **Save has phases** (begin → stage → commit → refresh) and six distinct
  outcomes. It never reports *Saved* before the commit is confirmed, and a
  confirmed commit whose refresh failed is reported as "Saved, but the editor
  could not refresh" — never as "Not saved".
- **Preview stages; it does not commit.** It re-stages the draft on every
  preview.
- **An unknown outcome is reconciled** against the transaction-outcome
  endpoint; no second commit is sent and no rollback follows a commit.
- **Discard rolls back only a transaction this operation opened.**
- **The editor session is one tab, one operation** — a capability in
  `sessionStorage`, of which only a SHA-256 is kept server-side. A second tab
  is *locked*, not editable.
- **The status chip distinguishes nine states**, and staged work is never
  labelled *Saved*.

### 2.7 The zone block vocabulary and the renderer

The vocabulary is fixed at five types and enforced in five places: the
renderer's `switch`, the configuration validator's allowlist, the doctor's
`ALLOWED_BLOCKS`, and the two zone tools' type lists. Adding a sixth type is
a real change to the theme's contract, and it must be made in all five.

`agentshell_render_block()` dispatches on the block type. A `widget` block
fires `do_action( 'agentshell_render_widget', $id )` and the plugin answers;
the theme's own `agentshell_render_widget()` function is *not* on the
production render path.

### 2.8 Tests and CI

Ninety-nine suites are declared in `tests/run-all.php`. Each suite is a
**process**, spawned by `tests/run-suite.php`, not a file the runner
`require`s — the runner used to require suites into its own process, and a
suite that called `exit()` ended the whole run silently. A suite declares what
it requires:

| Requirement | Meaning | Count |
|---|---|---|
| `unit` | no external dependency; always eligible | 74 |
| `testbed` | needs the opt-in test-bed profile, read-only | 1 |
| `live` | needs a resolvable live WordPress, read-only | 1 |
| `destructive` | needs a *verified* test bed and writes to it | 23 |

`tests/bootstrap.php` is a stub WordPress — an in-memory option store, a fake
current user, stubbed `current_user_can`, `get_option`, `update_option`,
`delete_option`. It is explicitly **not** a substitute for a real WordPress,
and the repo's own discipline is that end-to-end behaviour is proven against
a real one.

CI provisions a disposable environment in `tests/ci/provision-integration-env.sh`:
a MySQL database with a restricted user, a WordPress installation in a scratch
directory with the theme and both plugins linked from the checkout, the
`agentshell_site_mode = disposable_test_bed` sentinel, an HTTP server, and a
test-bed profile. The workflow has three jobs — `static`, `unit`, `integration` —
and, since PR #35, a fourth, `full-test-suite`, which is the aggregate gate the
branch ruleset requires.

### 2.9 Release packaging

`tools/package-release.sh` builds an archive from an **explicit include
list** — the theme's files plus `configurator`, `agentshell-mcp` and
`agentshell-blocks`. `tools/release-exclusions.sh` is the single exclusion
list, shared by the packager and the checker, precisely so that an exclusion
cannot be forgotten in one and not the other. `tools/check-release-archive.sh`
re-checks the built archive against the same contract and refuses it if
something forbidden is present or something required is missing.

### 2.10 The findings that constrain the design

These are the observations that decide the architecture. Each one is a
constraint the design has to satisfy, not a preference.

| # | Finding | Consequence for Forms |
|---|---|---|
| F1 | The theme is presentation; its shell files are read-only at runtime by contract, and a forms system must work on any theme | The engine cannot be theme code |
| F2 | The MCP plugin is the agent transport, not a product data layer; the forms system must exist without it | The engine cannot depend on the MCP plugin |
| F3 | `agentshell-blocks` is a widget plugin with three tools and no block infrastructure | Extending it would be a category error |
| F4 | The repository has no custom post types | A post type is a new architectural element and is introduced deliberately, once, with a stated rationale |
| F5 | **Core does *not* version arbitrary post meta.** `wp_save_revisioned_meta_fields()` (hooked to `_wp_put_post_revision`) copies only the keys returned by `wp_post_revision_meta_keys()`, which is the set registered through `register_meta()` with `revisions_enabled => true` — and `register_meta()`'s default for that argument is `false`. Separately, `wp_save_post_revision()` only takes a revision when one of `_wp_post_revision_fields()` — `post_title`, `post_content`, `post_excerpt` — has changed | A definition in post meta gets **no** native revision support by default. §5.8 specifies the registration, the trigger and the relationship between native revisions and the plugin's own definition history |
| F6 | The generic content tools are hard-limited to `post` and `page` (`Content_Primitives::TYPES`, and the `type` enums in the tool schemas) | A submission post type is invisible to `agentshell_search_content`, `agentshell_get_content` and `agentshell_trash_content` — no leakage, and no change to those tools |
| F7 | The registry keys tools by name and keeps the *first* registration, so a third plugin registering at a later priority can never shadow an existing tool | The forms plugin registers its tools at priority 15 and cannot collide |
| F8 | `tests/tool-count-test.php` scans exactly two plugin files, and `tests/doc-consistency-test.php` gates the documented count in two places | Adding a third publishing plugin **requires** extending the count test and updating the documented count, or the count becomes wrong |
| F9 | `tools/package-release.sh` includes an explicit list of plugins, and the checker re-checks it | A new plugin must be added to both, or the archive ships without it and nothing notices |
| F10 | The doctor is the single source of truth for what a valid block is, and configuration writes are staged by intercepting one option name | The forms domain does not need to join the configuration transaction, and should not |
| F11 | WordPress's cookie authentication already refuses a REST request without a valid nonce | The admin REST routes need a capability check, not a hand-rolled nonce check |
| F12 | The repository has no build step: `configurator/*.js` are plain files that also export via `module.exports` for Node tests | The forms builder must be plain JavaScript with no bundler |
| F13 | **A post type key must be 20 characters or fewer.** `register_post_type()` returns `WP_Error( 'post_type_length_invalid' )` above 20 characters, and `wp_posts.post_type` is `varchar(20)` — so an over-long key is not merely refused, it would be **truncated in the database** | `agentshell_submission` is 21 characters and is **invalid**. The submission post type is renamed — §3.3, §6.2 |
| F14 | **`wp_postmeta` has no uniqueness guarantee.** Its only keys are `PRIMARY KEY (meta_id)` and two non-unique indexes; `add_metadata()` is a `SELECT COUNT(*)` followed by an `INSERT`, and `update_metadata()` updates **every** row matching the key | A compare-and-swap cannot rely on one row per `(post_id, meta_key)`. §9.6 specifies creation, duplicate detection and guarded updates that depend only on `meta_id` |
| F15 | **`wp_mail()` returning `true` means the message was accepted by the sending mechanism** — handed to PHPMailer and the local MTA without an error. It does not mean the message was delivered, and it does not mean it was not | The notification vocabulary must say *accepted*, never *delivered*, and the delivery guarantee is at-least-once — §11.5, §11.6 |
| F16 | **A WordPress REST nonce is not a CSRF defence for an anonymous visitor.** `wp_create_nonce()` derives from the user id and the session token, so every logged-out visitor without a cookie receives the same value | The public submission endpoint cannot rely on a nonce; §7.1 separates replay prevention, duplicate suppression, cross-origin browser restrictions and server-side automated abuse |

---

## 3. Architecture options and trade-offs

### 3.1 Where the engine lives

| Option | What it means | For | Against |
|---|---|---|---|
| **A. In the theme** | `functions.php` grows a schema, a store, a pipeline, a builder | No new plugin; the theme already owns the configurator and the REST routes | The theme is presentation and is read-only at runtime by contract; the requirement is theme-independence; `functions.php` is already 5,055 lines; the theme's REST auth is `edit_theme_options`, which is the wrong authority for submissions |
| **B. In `agentshell-mcp`** | The agent transport becomes the product | The MCP tools come for free; the transaction machinery is nearby | Forms would not exist without the agent transport; conflates transport with product; the transport requires `manage_options`, which is the wrong default for a public form endpoint |
| **C. In `agentshell-blocks`** | The widget plugin grows | Already has a shortcode and a registry view | The plugin is a widget plugin; the name would become less accurate; it has three tools and no admin surface; mixing a forms engine into it makes both worse |
| **D. A new `agentshell-forms` plugin** | A self-contained plugin with its own schema, store, pipeline, admin surface and tools | Theme-independent; independently activatable and uninstallable; keeps the theme and the MCP plugin honest about their roles; one new plugin is a bounded, reviewable change | A new plugin must be added to the release include list and the tool-count test; one more directory to maintain |

**Recommendation: D.**

The deciding argument is F1 and F2 together. A forms system that must work
without the AgentShell theme, and must exist without the MCP plugin, has
exactly one home: a plugin of its own. Options A, B and C each make one of
those two requirements false.

### 3.2 Where the form definitions live

| Option | What it means | For | Against |
|---|---|---|---|
| **A. In `agentshell_config`** | Forms become part of the configuration | Free staging, preview, revisions, snapshots, the doctor, the diff — all of it | The configuration is the *theme's* configuration; forms must survive the theme being swapped; the revision ring (25) and snapshot bounds (20) would be shared with unrelated changes; a form's definition would be published by a configuration commit, which is the wrong granularity |
| **B. A custom post type with the definition in post meta** | `agentshell_form` posts; `_agentshell_form_definition` meta | Native capabilities; native trash; native search; multiple forms for free; a stable identity that survives import/export; the definition is a document, which is what a post is; and **revision support that the plugin registers explicitly** — WordPress does not version arbitrary custom post meta on its own (F5, §5.8) | A new architectural element (F4); post meta is not unique, so concurrency needs a `meta_id`-scoped, value-guarded update (F14); native revisions are per-post and bounded, so they are a secondary record rather than the authoritative history (§5.8) |
| **C. A custom post type with the definition in `post_content`** | The definition is the post body | The body is natively versioned and diffed | The body is HTML by convention; a JSON document in it is a misuse; editing it through the block editor is possible and would corrupt it |
| **D. A dedicated table** | `agentshell_forms` with a JSON column | Full control over shape and indexes | Re-implements capabilities, revisions, trash and search that WordPress already provides; adds a migration obligation for a document-shaped record |

**Recommendation: B.**

Option A is the tempting one — it would reuse the whole configuration
machinery for free — and it is wrong for a specific reason: the configuration
is scoped to the theme, and a form is not. A contact form must keep working
when the site changes theme, must not be published by a theme-configuration
commit, and must not be versioned in the same ring as colour tokens. Options C
and D each trade a WordPress capability the repository already uses well for
control it does not need.

### 3.3 Where the submissions live

| Option | What it means | For | Against |
|---|---|---|---|
| **A. A custom post type** | `agentshell_entry` posts, values in meta | Capabilities, trash, search, `wp_delete_post`, the WordPress personal-data exporter/eraser hooks, and invisibility to the generic content tools (F6); a durable record that a notification retry can reference | `wp_posts` is a wide table; each submission is a post row plus meta rows; no native indexing of field values |
| **B. A dedicated table** | `agentshell_form_submissions` | Exact shape, indexes on form and date, efficient export | Re-implements capabilities, deletion, pagination, privacy export, and the audit trail; a new migration surface for data that is the most sensitive thing on the site |
| **C. An option** | Submissions in an option array | Trivial to implement | Unbounded growth in a serialised option; no per-record capability; no trash; no privacy hooks; catastrophic for a busy form |
| **D. Post meta on the form** | Submissions as meta rows on the form post | One place to look | Mixes definitions and personal data in one record, which the brief forbids; deletion, export and privacy all become ad hoc |

**Recommendation: A, with B as the documented exit.**

The decisive point is F6. Because the generic content tools are hard-limited
to `post` and `page`, a submission post type is *already* outside their reach
— the personal-data separation falls out of the existing architecture rather
than needing to be enforced by a new mechanism. WordPress also ships the
privacy exporter and eraser registries, which turns the UK GDPR access and
erasure obligations into a registration rather than a subsystem.

**The post type key is `agentshell_entry`, not `agentshell_submission`.**
The obvious name is invalid: `agentshell_submission` is 21 characters, and
WordPress caps a post type key at 20 (`register_post_type()` returns
`WP_Error( 'post_type_length_invalid' )` above that, and `wp_posts.post_type`
is `varchar(20)`, so an over-long key would additionally be truncated in the
database — F13). `agentshell_entry` is 16 characters, is not a reserved name,
and reads correctly: a submission is an *entry* in a form. The form post type
is `agentshell_form` (15 characters), which is within the limit.

The cost is real: `wp_posts` is not a submission table. The documented exit
criterion is in §6.7 — a form or site that sustains submission volumes where
per-post overhead dominates should move to a dedicated table, behind the same
service interface, without changing the pipeline.

### 3.4 How a form is inserted into a page

| Option | What it means | For | Against |
|---|---|---|---|
| **A. Shortcode** | `[agentshell_form slug="contact"]` | Theme-independent; works with the core Shortcode block; works with any editor; the existing `[agent_block]` precedent; no build step | Not drag-and-drop; not visually represented in the editor canvas |
| **B. A Gutenberg block** | A real block with `block.json` | The best editor experience; native | The repository has no block infrastructure at all (F-style: zero `block.json`, zero `register_block_type`); a block needs `wp-blocks`/`wp-element` and, for anything beyond a wrapper, a build step or careful vanilla registration; the core Shortcode block already covers the insertion case |
| **C. A `WP_Widget`** | A widget the plugin registers | Theme-independent; appears in every widget area; AgentShell's `wp_widget_area` block renders it | Not available in the block editor without the Legacy Widget block; widget areas are a theme concept |
| **D. A new AgentShell zone block type** | `{type: 'agentshell_form', id: 'contact'}` | The most AgentShell-native: a form drops into the nine-area grid like any other block | Requires the five coordinated changes F-style; expands the fixed block vocabulary, which is a contract change, not a feature |
| **E. Raw HTML in a `json_block`** | The operator pastes the markup | Nothing to build | Fragments the definition from the page; the sanitiser strips `style` and `<script>`; the form would not know its own id or version; accessibility and validation would be hand-rolled per copy |

**Recommendation: A and C in the MVP; D in a later phase; B only if demand
justifies it; E never.**

Shortcode plus widget gives theme-independent insertion with no theme change
and no build step, and it is the same choice the existing `[agent_block]`
shortcode already made. The zone block type is the AgentShell-native
expression of the same thing and belongs in the last phase, where its
five-place cost is visible and reviewable on its own.

### 3.5 Where the visual builder lives

| Option | What it means | For | Against |
|---|---|---|---|
| **A. Inside the AgentShell configurator** | A Forms tab in the existing dock | One editor to learn; the configurator's draft/preview/discard machinery is reused | The configurator is theme code and is enqueued only for `edit_theme_options`; a forms builder inside it couples the plugin to the theme and to a specific dock layout; the configurator's 560-pixel dock was designed for tokens and zones, not for a field list with drag-and-drop |
| **B. A dedicated admin page in the plugin** | `Appearance → AgentShell Forms` | Theme-independent; standard WordPress admin; the plugin owns its own UI, its own capability and its own assets; unlimited room for a field list | A second place to learn; the AgentShell look is not inherited automatically |
| **C. A Gutenberg page** | A block-based form editor | Native editor experience | Block infrastructure that does not exist (F-style); a build step; the field list is not really blocks |
| **D. A full-screen editor inside the configurator** | A separate mode | Room to work | Adds a mode to the configurator's state machine, which §3.1 of the configurator design record explicitly declined to add |

**Recommendation: B, with a thin A.**

The builder is a plugin-owned admin page. The *AgentShell* configurator gains
one small affordance — a picker entry that opens the builder for a form, or
copies the shortcode — rather than becoming the builder. This keeps the
plugin independent (a requirement) while giving AgentShell users a route
into it.

### 3.6 The recommendation in one table

| Question | Answer | Deciding finding |
|---|---|---|
| Where does the engine live? | A new `agentshell-forms` plugin | F1, F2 |
| Where do definitions live? | `agentshell_form` post type, definition in post meta | F5, and the theme-scoping argument |
| Where do submissions live? | `agentshell_entry` post type, values in meta | F6, F13, plus the privacy hooks |
| Where does ephemeral protocol state live? | Two plugin-owned tables | Atomicity requirements |
| How is a form inserted? | Shortcode + `WP_Widget`; zone block later | Theme-independence |
| Where is the builder? | A plugin-owned admin page | Theme-independence |
| How does it reach agents? | The `agentshell_mcp_register_tools` filter at priority 15 | F7 |
| How is publication controlled? | A working/published split on the definition | F10, and the configurator's lessons |

---

## 4. Recommended plugin/module boundaries

### 4.1 The plugin

`agentshell-forms/`, a normal WordPress plugin with its own bootstrap,
activation hook and `uninstall.php`. It requires WordPress 6.0+ and PHP 7.4+
— the same floor the MCP plugin declares — and it requires **no** other
AgentShell component.

Its dependencies are therefore: WordPress core, and nothing else. It uses
`wp_mail()`, the WordPress REST API, the WordPress privacy exporter and
eraser registries, WordPress cron, and `wp_remote_post()` for one fixed,
non-configurable verification endpoint.

### 4.2 The directory layout

```
agentshell-forms/
  agentshell-forms.php          bootstrap: constants, activation, uninstall hook
  uninstall.php                 removes the plugin's own tables and options
  includes/
    class-form-post-types.php   registers `agentshell_form` and `agentshell_entry`
    class-form-schema.php       the authoritative schema, the validator, the error codes
    class-form-field-types.php  the field-type registry
    class-form-definition.php   read/write of working and published definitions; the CAS
    class-form-capabilities.php the two capabilities, and the per-user grant and revocation
    class-form-renderer.php     server-side rendering of a form
    class-form-shortcode.php    `[agentshell_form slug="…"]`
    class-form-widget.php       the `WP_Widget` subclass
    class-form-challenges.php   the single-use challenge store
    class-form-rate-limits.php the rate-limit counter store
    class-submission-handler.php the one authoritative submission pipeline
    class-submission-store.php   persistence, retention, export, erasure
    class-form-notifier.php      `wp_mail()` notifications and their retry
    class-turnstile.php          the verification client
    class-form-privacy.php       the WordPress personal-data exporter and eraser
    class-form-admin.php         the admin screens (list, builder, submissions, access)
    class-form-rest.php          the admin REST controller
    class-form-tools.php         the MCP tool classes
  admin/
    js/forms-model.js            pure models (Node-testable)
    js/forms-api.js              the transport
    js/forms-ui.js               the view
    js/forms-app.js              the application layer
    css/forms-admin.css
```

**`class-form-capabilities.php` is the module that holds the personal-data
boundary**, and it is deliberately separate from the tools. It registers the
two capabilities, performs the per-user grant and revocation of §8.3a, and
exposes the single question every consumer asks — *may this principal reach
submission contents?* — in one place, so the MCP tools, the administrative
screen and the REST controller cannot drift apart in what they answer.

### 4.3 The public API surface

The plugin exposes a small, documented PHP API so that other code can render
and extend forms without touching its internals:

```php
// Render a form. $form_id may be the stable form id or the post id.
// Returns the form's HTML, or '' when the form is unknown or unpublished.
agentshell_forms_render( $form_id, array $args = array() ): string

// The published definition of a form, or null.
agentshell_forms_get_published( $form_id ): ?array

// The working (draft) definition of a form, or null.
agentshell_forms_get_working( $form_id ): ?array

// Validate a definition. Returns array( 'ok' => bool, 'errors' => array, 'warnings' => array ).
agentshell_forms_validate( array $definition ): array

// The registered field types, after the filter.
agentshell_forms_field_types(): array
```

Everything else — the pipeline, the store, the notifier — is internal and may
change.

### 4.4 The seams with AgentShell, and their direction

Every seam is **one-way**: AgentShell may call the forms plugin; the forms
plugin never requires AgentShell to be present.

| Seam | Direction | Phase | Notes |
|---|---|---|---|
| `[agentshell_form id="…"]` shortcode | WordPress → Forms | 2 | Works in any theme, any editor |
| `WP_Widget` subclass | WordPress → Forms | 2 | Appears in every widget area, including AgentShell's `wp_widget_area` |
| `agentshell_mcp_register_tools` filter | MCP → Forms | 5 | Priority 15, so it can never shadow an existing tool (F7) |
| A new zone block type `agentshell_form` | Theme → Forms | 6 | One `case` in the renderer, guarded by `function_exists( 'agentshell_forms_render' )` |
| A picker group in the configurator | Theme → Forms | 6 | One extra group, populated only when the plugin is active |

### 4.5 What is explicitly **not** integrated

- **The configuration transaction.** Form definitions do not live in
  `agentshell_config`, so they are not staged by it. The forms plugin has its
  own draft/published model, described in §9.
- **The content journal.** Form definitions and submissions are not staged
  content; they are not drafts the publication system owns.
- **The doctor.** The doctor's job is to say whether a *configuration* is
  valid. A form has its own validator, and a broken form is refused by the
  form's own publish path.
- **The widget registry.** A form is not a widget. The plugin's `WP_Widget`
  is a bridge to WordPress's widget areas, not an entry in AgentShell's
  bilateral registry.

---

## 5. The form-definition schema

### 5.1 The authoritative schema

One schema, stored once, used by every consumer: the builder, the MCP tools,
the renderer, the submission pipeline and the validator. There is no second
copy of the rules.

**Two identifiers, and they are not interchangeable.**

| | The internal identifier | The slug |
|---|---|---|
| Format | exactly 32 lowercase hexadecimal characters — a UUIDv4 rendered without its dashes | `^[a-z0-9][a-z0-9-]{0,63}$` |
| Example | `7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a` | `contact` |
| Mutable? | **No.** Assigned once at creation, never changed, never reused | Yes, but **only while the form has never been published** — see below |
| Unique? | Yes, globally | Yes, per site |
| Used by | The database, the API paths, the MCP tools, the submission pipeline | The shortcode, the admin screens, the human |
| Storage | `char(32)` / `varchar(32)` | `varchar(64)` in the form's own meta |

The internal identifier is the **authoritative** one. It is what a submission
records, what a version refers to, and what an agent addresses. It is
immutable, so a submission's meaning never changes and a form cannot be
"replaced" underneath a stored record.

The slug exists because `[agentshell_form slug="contact"]` is something a
human can read and edit, and a 32-character hex string is not. The two
namespaces cannot collide, because **the validator refuses a slug that
matches `^[a-f0-9]{32}$`** — so a value that looks like an internal
identifier is always an internal identifier, and the resolution rule in §7.3
is total.

A slug may be changed **only while the form has never been published**.
**The slug is frozen after the form's first publication**, and it stays
frozen for the rest of the form's life — including if the form is later
unpublished, because the shortcode may still be embedded in pages and the
shortcode is what a slug is for. The freeze is triggered by the **first**
publication, not the current one, so the rule does not depend on the form's
present state.

The reason is that a published form's shortcode is already embedded in
pages, and a silent rename would break them. Renaming a published form
means creating a new form, or editing the pages that embed it — which is
the operator's decision to make deliberately.

**The internal identifier is the long-lived reference.** Anything that must
keep pointing at the same form across renames, re-publications and
long-lived integrations uses the internal identifier: the API path segment,
the MCP tools, a submission's `form_id`, a notification's `Message-ID`, and
any external system that stores a reference. The slug is the *human*
handle and is the thing that is frozen; the identifier is the thing that
never changes, and that is why the two are not interchangeable.

```json
{
  "schema_version": 1,
  "form_id": "7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a",
  "slug": "contact",
  "name": "Contact us",
  "description": "Get in touch with the studio.",
  "version": 3,
  "status": "published",
  "fields": [
    {
      "key": "name",
      "type": "text",
      "label": "Your name",
      "description": "",
      "placeholder": "",
      "required": true,
      "settings": { "max_length": 120, "min_length": 1, "autocomplete": "name" },
      "options": []
    },
    {
      "key": "message",
      "type": "textarea",
      "label": "How can we help?",
      "description": "",
      "placeholder": "",
      "required": true,
      "settings": { "max_length": 4000, "min_length": 10, "rows": 6 },
      "options": []
    },
    {
      "key": "consent",
      "type": "consent",
      "label": "I agree to the privacy notice",
      "description": "",
      "placeholder": "",
      "required": true,
      "settings": { "privacy_url": "https://example.com/privacy/" },
      "options": []
    }
  ],
  "settings": {
    "submit_label": "Send message",
    "success_message": "Thank you — we will be in touch within two working days.",
    "redirect_url": "",
    "notification": {
      "enabled": true,
      "recipients": ["site-admin"],
      "subject": "New submission: {form:name}",
      "body_template": "A new submission arrived.\n\n{fields}\n",
      "reply_to_field": "email",
      "confirmation": { "enabled": false, "subject": "We received your message", "body_template": "…" }
    },
    "spam": {
      "turnstile": "disabled",
      "honeypot": true,
      "min_seconds": 3,
      "rate_limit": { "max": 5, "window": 300 }
    },
    "storage": {
      "retain_days": 90,
      "store_ip": false,
      "store_user_agent": false
    },
    "privacy": {
      "lawful_basis": "legitimate_interests",
      "privacy_notice_url": "https://example.com/privacy/"
    }
  }
}
```

**Field keys** are the stable identifiers a submission is keyed by. They are
constrained to `^[a-z][a-z0-9_]{0,39}$` and must be unique within a form.
The constraint is not cosmetic: a key that could contain a quote, a space or a
hyphen would be awkward in a form-encoded body, ambiguous in an export column
header, and a way to collide with a reserved meta name.

**Field keys are not renamable once a form has submissions.** Renaming is a
migration, not an edit, and the plugin refuses it with a named error. The
builder offers "duplicate" and "remove" instead. The **slug** follows the same
principle with a different trigger: it is free to change while the form is a
draft, and frozen once the form has been published (§5.1).

**`options`** is meaningful only for `select`, `radio` and `checkbox`. Each
option is `{ "value": "…", "label": "…" }`, where `value` is a stable slug
matched against `^[a-z0-9_-]{1,64}$` and `label` is free text, escaped on
output.

### 5.2 The field-type registry

A field type is a registry entry. New types are added by a filter, not by
editing the plugin:

```php
apply_filters( 'agentshell_form_field_types', array(
    'text' => array(
        'label'         => 'Single-line text',
        'multiple'      => false,
        'has_options'   => false,
        'settings'      => array(
            'max_length'   => array( 'type' => 'integer', 'min' => 1, 'max' => 1000, 'default' => 120 ),
            'min_length'   => array( 'type' => 'integer', 'min' => 0, 'max' => 1000, 'default' => 0 ),
            'autocomplete' => array( 'type' => 'string', 'enum' => array( 'name', 'given-name', 'family-name', 'organization', 'email', 'tel', 'street-address', 'postal-code', 'country', 'off' ), 'default' => 'off' ),
        ),
        'sanitize'      => callable,   // raw request value → normalised value
        'validate'      => callable,   // normalised value → true|WP_Error
        'render'        => callable,   // field + value → HTML
    ),
    // …
) );
```

Two properties of this contract matter.

**The type declares its own settings schema**, and the plugin's generic
validator walks it. A type that arrives through the filter therefore gets
validation, a default, a length bound and a builder control for free — the
builder renders a control per declared setting. The validator, the builder
and the renderer cannot drift, because all three read the same declaration.

**`multiple` is explicit.** A checkbox group stores an array; every other
type stores a scalar. The pipeline branches on the declaration, not on the
type name.

### 5.3 The MVP field registry

Ten types. This is deliberately short: each one earns its place by being
something a contact form actually needs.

| Type | Stores | Notes |
|---|---|---|
| `text` | string | The default. `autocomplete` tokens for WCAG 2.2's *Identify Input Purpose* |
| `textarea` | string | Bounded by `max_length`; the default maximum is 4,000 characters |
| `email` | string | Validated with `is_email()`; the only type eligible to be a `reply_to_field` |
| `tel` | string | Validated against a permissive E.164-ish pattern, not a strict one — over-strict telephone validation rejects valid numbers |
| `number` | string | A decimal or integer, within `min`/`max`; **stored as a string**, because `wp_insert_post` meta and CSV exports both prefer text and because a number that a user typed should not silently change shape |
| `select` | string | One of the declared options |
| `radio` | string | One of the declared options; rendered in a `<fieldset>` with a `<legend>` |
| `checkbox` | array of strings | Zero or more of the declared options; rendered in a `<fieldset>` with a `<legend>` |
| `consent` | boolean | A single required checkbox with the privacy notice. `false` is a *stored, meaningful* value, not an absence |
| `hidden` | string | See below |

### 5.4 Hidden fields: where they are safe, and where they are not

A hidden field is a value the visitor does not see. That makes it useful for
one thing — carrying a value the *server* already knows, or that the visitor
legitimately supplied in a previous step — and dangerous for another.

**Safe, and proposed:**

- A **static** default the operator sets in the builder, for example a campaign
  identifier or a routing key. It is rendered into the page, so it is not a
  secret, and it is *not* authoritative: the server may only use it for
  routing, never for authorisation.
- A **honeypot field** — but that is a separate mechanism (§11.4), not a field
  type, because it must never be a legitimate field.

**Not safe, and refused:**

- Anything derived from the visitor's identity, because the visitor can set
  it.
- Anything used for authorisation, routing to a privileged destination, or
  pricing.
- Anything a *third party* fills in. That is a third-party form post — a
  different feature with a different threat model, and not in scope.

The plugin enforces this by construction: a `hidden` field's value is
**always** taken from the form definition, and any value the request supplies
for a `hidden` field is **discarded**. A hidden field therefore cannot be
spoofed, because the server never reads the request's value for it.

### 5.5 The validation rules

The validator is the boundary. It refuses, by name, with a machine-readable
code and a human message. The same rules apply to the builder, the MCP tools
and the publish path.

| Rule | Error code |
|---|---|
| `schema_version` is a supported integer | `form_schema_version_unsupported` |
| `form_id` matches `^[a-f0-9]{32}$` — 32 lowercase hexadecimal characters, exactly | `form_id_invalid` |
| `slug` matches `^[a-z0-9][a-z0-9-]{0,63}$`, and does **not** match `^[a-f0-9]{32}$` | `form_slug_invalid` |
| `slug` is unique across forms | `form_slug_duplicate` |
| `slug` is unchanged after the form's **first** publication, permanently | `form_slug_frozen` |
| `name` is non-empty, at most 200 characters | `form_name_invalid` |
| `fields` is a list of at least one and at most 200 entries | `form_fields_count_invalid` |
| every field has a unique key matching `^[a-z][a-z0-9_]{0,39}$` | `form_field_key_invalid`, `form_field_key_duplicate` |
| every field's `type` is a registered type | `form_field_type_unknown` |
| every field's `label` is non-empty, at most 200 characters | `form_field_label_invalid` |
| every field's `settings` validate against its type's declared schema | `form_field_setting_invalid` |
| `options` are present exactly for the types that declare them, and are valid | `form_field_options_invalid` |
| `success_message` is at most 1,000 characters | `form_success_message_invalid` |
| `redirect_url` is empty or an http/https URL on this site | `form_redirect_url_invalid` |
| notification `recipients` are drawn from the allowed recipient set | `form_notification_recipients_invalid` |
| notification `reply_to_field` names a field of type `email`, or is empty | `form_notification_reply_to_invalid` |
| notification templates are at most 8,000 characters | `form_notification_template_too_large` |
| `spam.turnstile` is one of `disabled`, `optional`, `required` | `form_spam_turnstile_invalid` |
| `spam.rate_limit.max` is 1–100 and `window` is 30–86,400 | `form_spam_rate_limit_invalid` |
| `storage.retain_days` is 1–3,650, or 0 meaning "site default" | `form_storage_retention_invalid` |
| `privacy.lawful_basis` is one of `consent`, `contract`, `legitimate_interests`, `legal_obligation` | `form_privacy_basis_invalid` |

Unknown top-level keys and unknown keys inside a field are **rejected**, not
ignored. A silently ignored key is a definition that says something the
system does not do.

**The identifier rules are the same everywhere.** The `form_id` is 32
lowercase hexadecimal characters and nothing else — no prefix, no dashes, no
uppercase. The database column is `char(32)`, the validator's pattern is
`^[a-f0-9]{32}$`, the API path segment is that string, and the MCP tools
address a form by it. A prefix such as `f_` would make the stored value 34
characters and would not fit a `char(32)` column; it is deliberately absent.

### 5.6 Form versioning, and the five things that are not the same

Five distinct artefacts carry state about a form. They are frequently
conflated, so each is named, and each is told apart.

| Artefact | What it is | Who changes it | What it is for |
|---|---|---|---|
| **`version`** | An integer on the published definition, incremented on each publication | The publish path only | Naming *which* published definition a submission was validated against. It is a label, not a concurrency token |
| **The working definition** | The definition being edited | The builder, and the agent's update tool | The draft. The renderer never reads it |
| **The published definition** | The live definition | The publish path only | What visitors get and what submissions are validated against |
| **The previous publication** | The published definition as it was immediately before the last publication | The publish path only | A one-level undo, so a publication can be reversed by a human without digging through revisions |
| **The working-revision counter** | A monotonic integer on the working definition, incremented on every write to it | Every working-definition write | A **concurrency token**. It says "the draft I looked at is still the draft", and nothing else |

The version is **not** a content hash, and the working-revision counter is
**not** a version. They answer different questions: "which definition was
live?" and "has the draft changed under me?".

The working-revision counter is deliberately **not** recorded in native
revisions (§5.8): it is a concurrency token rather than content, and a
revision carrying a stale counter would be misleading.

### 5.7 Draft and published definitions

A form carries two definitions:

- the **working** definition — what the builder and the agents are editing;
- the **published** definition — what visitors get and what submissions are
  validated against.

A form with no published definition is **unpublished**: it renders nothing to
visitors, and the builder says so. §9 describes the publish protocol.

### 5.8 Native WordPress revisions, and what they do and do not give

This section exists because the naive assumption is wrong, and the naive
assumption is expensive (F5).

**What WordPress does not do.** It does **not** version arbitrary custom post
meta. Two independent facts combine:

1. `wp_save_revisioned_meta_fields()` — hooked to `_wp_put_post_revision` —
   copies into the revision only the meta keys returned by
   `wp_post_revision_meta_keys()`. That function returns the keys registered
   through `register_meta()` / `register_post_meta()` whose `revisions_enabled`
   argument is true — and **`register_meta()`'s default for that argument is
   `false`**. A meta key the plugin never registered is never copied.
2. `wp_save_post_revision()` only takes a revision at all when one of
   `_wp_post_revision_fields()` — `post_title`, `post_content`,
   `post_excerpt` — has changed since the last revision. A definition stored
   entirely in meta does not change any of those three fields, so **a
   definition-only edit would silently produce no revision at all**.

**What the plugin therefore does.**

The post type is registered with `'supports' => array( 'title', 'revisions' )`.
`register_meta()` refuses `revisions_enabled` unless the object subtype
supports revisions, so the two registrations must agree.

The three definition meta keys are registered explicitly:

```php
register_post_meta( 'agentshell_form', '_agentshell_form_working', array(
    'type'              => 'string',
    'single'            => true,
    'revisions_enabled' => true,
    'show_in_rest'      => false,
    'auth_callback'     => 'agentshell_forms_meta_auth_callback',
) );
// …the same for '_agentshell_form_published' and '_agentshell_form_published_previous'
```

`show_in_rest` is false and an `auth_callback` is supplied for all three, so
the definition is not reachable through the post REST API — the plugin's own
routes are the only way in.

**When a revision is taken.** Because a definition change does not alter
`post_title`, `post_content` or `post_excerpt`, the plugin takes the
revision itself, explicitly, at the moments where a snapshot of the
definition is meaningful:

- **after a publication** — so a revision captures the definition exactly as
  it was published;
- **after a discard** — so the discarded draft is recoverable.

The plugin calls `wp_save_post_revision( $post_id )` and, for that call only,
sets the `wp_save_post_revision_post_has_changed` filter to true, because
core's own "the post did not change" test looks only at the three post fields
and cannot see a meta change.

**The ordering matters and is fixed.** The meta row is written *first*, and
the revision is taken *after*. `wp_save_revisioned_meta_fields()` copies the
meta's current value at the moment the revision is created, so writing the
meta first and then taking the revision captures the new value; the reverse
order would capture the old one.

**What native revisions are, and are not.** They are a **secondary,
browsable record**: the admin's revision browser shows what a form looked
like at a point in time, and `wp_restore_post_revision()` can restore the
registered meta from one. They are **not**:

- the **authoritative history** of a form's live definition. The
  authoritative live state is `_agentshell_form_published`; the authoritative
  one-level undo is `_agentshell_form_published_previous`; the authoritative
  draft is `_agentshell_form_working`. The plugin never reads a native
  revision to decide what to render or validate against.
- a **complete history**. WordPress keeps a bounded number of revisions
  (`wp_revisions_to_keep()`, and the `WP_POST_REVISIONS` constant), and the
  plugin's own publications are not revisions at all — each publication
  replaces the previous one in `_agentshell_form_published`.
- **the concurrency mechanism**. That is the working-revision counter
  (§5.6), which is deliberately not revisioned.

**A native revision is not a rollback.** Restoring a revision restores a
*state*, not a *publication*. Restoring the plugin's own state is what
`agentshell_discard_form_changes` does (§9.5), and it is the only path the
plugin exposes, because it is the one that cannot half-apply.

---

## 6. The submission storage model

### 6.1 The organising principle

**Durable, user-visible records live in WordPress's own tables. Ephemeral
protocol state lives in tables the plugin owns.**

The distinction is not tidiness; it is about which guarantees each store can
give. The options table and transients give no atomicity for a
consume-once operation and no atomicity for a counter increment. A table with
a unique key does. So the plugin owns two small tables, and nothing else.

### 6.2 The submission record

`agentshell_entry` is a post type registered with:

- `public => false`, `exclude_from_search => true`, `show_ui => false` — the
  plugin provides its own screens;
- `capability_type => 'agentshell_entry'`, with the capabilities mapped to
  `read`, `edit_post`, `delete_post` so WordPress's per-post model applies to
  a submission exactly as it applies to a page;
- `supports => array( 'title' )` only. A submission is immutable: no editor,
  no revisions, no comments.

The post type key is `agentshell_entry` and **not** `agentshell_submission`,
which is 21 characters and therefore invalid (F13, §3.3). The capability
type follows the same name, so the generated capabilities read
`edit_agentshell_entry`, `read_agentshell_entry` and
`delete_agentshell_entry`, and are mapped by the plugin onto the capability
set §8.3 describes.

The record's content is in post meta:

| Meta key | Holds |
|---|---|
| `_agentshell_form_id` | the form's **internal identifier** — 32 hex characters, not the post id |
| `_agentshell_form_version` | the published version the submission was validated against |
| `_agentshell_form_data` | the submitted values, JSON, keyed by field key |
| `_agentshell_form_meta` | received-at, source, the hash of the source address, the user agent (only if enabled) |
| `_agentshell_form_spam` | `clean`, `spam`, `unverified` |
| `_agentshell_form_notification` | the notification record — see §11.6 |
| `_agentshell_form_hash` | the SHA-256 of the canonical submitted payload — the tamper-evidence record |
| `_agentshell_form_challenge` | the challenge token hash this submission consumed, so a retry can be recognised (§7.1) |

The post **title** is generated by the server (`Contact us — 2026-10-27
14:03`), never taken from the submission, so no personal data lands in a
field the admin list table sorts on or a search index.

### 6.3 The plugin's own tables

Two tables, each with exactly one job and exactly one unique key.

**The challenge table** — `agentshell_form_challenges`:

```
id             bigint unsigned AUTO_INCREMENT PRIMARY KEY
token_hash     char(64)     NOT NULL            -- SHA-256 of the challenge token
form_id        char(32)     NOT NULL            -- the internal form identifier
form_version   int unsigned NOT NULL            -- the published version it was minted for
subject_hash   char(64)     NOT NULL DEFAULT '' -- HMAC of the source address
consumed_at    datetime     NULL                -- when it was claimed
submission_id  bigint unsigned NOT NULL DEFAULT 0 -- the entry it produced, once known
created_at     datetime     NOT NULL
expires_at     datetime     NOT NULL
UNIQUE KEY token_hash (token_hash)
KEY form_id (form_id)
KEY expires_at (expires_at)
```

**The rate-limit table** — `agentshell_form_rate_limits`:

```
id             bigint unsigned AUTO_INCREMENT PRIMARY KEY
scope          varchar(16)  NOT NULL            -- 'form' | 'site'
subject_hash   char(64)     NOT NULL            -- HMAC of the source address
form_id        char(32)     NOT NULL DEFAULT ''  -- '' for the site scope
window_start   int unsigned NOT NULL            -- unix seconds, floored to the window
hits           int unsigned NOT NULL DEFAULT 0  -- the counter
created_at     datetime     NOT NULL
expires_at     datetime     NOT NULL
UNIQUE KEY bucket (scope, subject_hash, form_id, window_start)
KEY expires_at (expires_at)
```

**Why a table and not a transient.** A challenge must be claimed exactly once,
and a counter must be incremented exactly once. Consuming a transient is a
read followed by a delete, and two concurrent requests can both read it;
incrementing a transient is a read, an addition and a write. A guarded
`UPDATE … WHERE <condition>` in a table is a single atomic statement, and its
affected-row count says who won. The same argument applies to the counter,
which needs a unique bucket key to make the increment atomic.

**Why not the options table.** The options table's `add_option()` is atomic
against the unique key on `option_name`, which is the trick
`Transaction_Manager` uses to open a transaction — but a challenge row per
rendered form would grow `wp_options` without bound, would need its autoload
behaviour managed per row, and — for the counter — would still need a
compare-and-swap per increment. A table with an expiry index and a cron prune
is the right shape for data that is born expiring.

**Retention.** Rows are deleted by a cron job that runs hourly and removes
anything past `expires_at`. Correctness never depends on the cron firing:
every read re-checks the expiry itself, exactly as the screenshot store does.

**The two tables are separate on purpose.** The challenge table's unique key is
a token; the rate table's is a bucket. Mixing them would mean one table with
two incompatible uniqueness rules and a `NULL`-key trick to reconcile them,
which is harder to reason about than two tables.

### 6.3a The challenge claim, exactly

The challenge is **claimed**, not deleted, so that a retry can be recognised
(§7.1). The claim is one statement:

```sql
UPDATE wp_agentshell_form_challenges
   SET consumed_at = %s
 WHERE token_hash = %s
   AND consumed_at IS NULL
```

- **One affected row** — this request owns the challenge. It proceeds.
- **Zero affected rows** — the challenge was already claimed. The plugin then
  reads the row: if `submission_id` is non-zero, an earlier attempt of this
  same request **succeeded**, and the plugin **replays the success response**
  without creating anything (§7.1). If `submission_id` is zero, the earlier
  attempt is still in flight or failed, and the plugin refuses with a
  retryable error that carries a **fresh challenge**, so the visitor can
  retry without reloading the page.

When the submission is written, the challenge is linked to it in the same
transaction:

```sql
UPDATE wp_agentshell_form_challenges
   SET submission_id = %d
 WHERE token_hash = %s
```

### 6.3b The rate-limit algorithm, exactly

The limit is enforced by a **fixed window per (scope, subject, form)**, and
the limit is baked into the statement so the counter can never be driven past
it by a flood.

**Reserve a slot** — one atomic statement:

```sql
INSERT INTO wp_agentshell_form_rate_limits
       (scope, subject_hash, form_id, window_start, hits, created_at, expires_at)
VALUES (%s, %s, %s, %d, 1, %s, %s)
ON DUPLICATE KEY UPDATE
       hits       = IF(hits <= %d, hits + 1, hits),
       expires_at = VALUES(expires_at)
```

The third parameter of the `IF` is the **limit**; the counter therefore stops
at `limit + 1` and can never exceed it, however many concurrent writers
arrive.

**Read the counter** — one statement:

```sql
SELECT hits FROM wp_agentshell_form_rate_limits
 WHERE scope = %s AND subject_hash = %s AND form_id = %s AND window_start = %d
```

**Admit iff `hits <= limit`.**

The property this gives is the one that matters: **the number of admitted
requests in a window never exceeds the limit.** The reasoning is that the
counter is monotonic and the admission test is a read of a value that is at
least the number of reservations made so far; a request that finds the counter
at or below the limit is one of at most `limit` such requests.

The cost is **under-admission under concurrency**: several requests may
reserve concurrently, drive the counter above the limit, and then all find
themselves refused even though fewer than `limit` slots were used. That is
the safe direction — the limit is never exceeded — and it is the right
trade for an abuse control.

Two limits are enforced, and both are reserved from the same table:

| Scope | Subject | Form | Meaning |
|---|---|---|---|
| `form` | HMAC of the source address | the form's internal id | The per-form, per-source limit from `spam.rate_limit` |
| `site` | a fixed constant | `''` | A site-wide ceiling on submissions per unit time, independent of the source address |

The site scope exists because the per-source limit is defeated by rotating
source addresses. It is the backstop that bounds total volume.

**Fixed windows have a known property**, stated plainly: a subject can send
`limit` requests at the end of one window and `limit` at the start of the
next. The site scope and the honeypot and Turnstile are the controls for that;
the rate limiter is not the only defence, and it is not claimed to be.

**The reservation happens before the expensive work** — before the Turnstile
verification — so that a flood is rejected without a round trip to a third
party. A refused attempt **consumes a slot**, which is the intended
behaviour: an attempt that fails verification is still an attempt.

### 6.3c Denial of service against the plugin's own tables

The tables are the plugin's most attackable surface, because a flood would
grow them.

- **Challenges are minted on render, so renders are rate limited too.** The
  render path takes a slot from a *separate, more generous* render budget, so
  a bot looping over the form page cannot mint challenges without spending
  budget.
- **The site scope bounds the total.** Even with rotating addresses, the
  number of outstanding challenges is bounded by the site budget times the
  window.
- **Expiry bounds the table absolutely.** A challenge expires two hours after
  it was minted; a rate row expires at the end of its window plus a grace
  period. The hourly prune removes both.
- **The challenge is opaque.** Only its SHA-256 is stored, so a database
  disclosure does not yield usable tokens.

### 6.4 Data minimisation

The principle is that the submission holds what the form asked for and
nothing else.

- Only fields declared in the published definition are stored. A value the
  request supplies for an undeclared key is **discarded**, not stored — this
  is also what prevents cross-form data leakage (§10.2).
- The source address is stored as a **truncated HMAC** (`hash_hmac('sha256',
  $ip, $site_salt)`), not in the clear and not as a plain hash, so it cannot
  be recovered by a dictionary. Storing it at all is off by default;
  `storage.store_ip` turns it on for a form that genuinely needs it for abuse
  investigation.
- The user agent is not stored unless `storage.store_user_agent` is on.
- `storage.retain_days` is a per-form retention period. The site default is 90
  days. A form with no explicit period inherits the default.
- **The definition is useful processing metadata.** What a form collects, on
  what stated basis, for how long, and where the privacy notice is, is
  written down in the form itself — which is a genuinely useful property,
  because the metadata cannot drift from the thing it describes.

  **It is not, by itself, a record of processing, and it is not
  compliance.** A record of processing under Article 30 covers more than this:
  the identity of the controller and any joint controllers, the categories of
  data subjects and data, the recipients and any third-party processors, the
  international transfers and the safeguards for them, the security measures,
  and the retention periods across the whole operation. A form definition
  holds the fields, the stated basis, one retention period and one notice
  URL. It is **input to** a record of processing, not a record of one, and
  nothing the plugin does makes a site compliant. §10.3 says the same thing
  from the legal side.

### 6.5 Retention, deletion and export

**Retention** is enforced by a daily cron job that deletes submissions older
than their form's retention period. The deletion is a **hard delete** — a
retention period that leaves data in the trash is not a retention period —
and it writes an audit row naming the form, the count and the period.

**Deletion on request** (a subject-access erasure, or an administrator
removing a record) is a hard delete behind an explicit confirmation, with the
per-post `delete_post` capability re-checked at the moment of deletion —
the same discipline `Content_Recovery::authorize_destruction()` uses.

**Reversible deletion** (moving a submission to the trash) is offered
separately, mirroring the established `agentshell_trash_content` /
`agentshell_restore_content` pair. It is the wrong operation for a retention
purge and the right one for "we should not have this record".

**Export** is CSV or JSON, scoped to one form, produced by a capability-checked
route. It is the Article 15 access path for a subject request and the
administrator's own path to the data.

### 6.6 The WordPress privacy hooks

The plugin registers an **exporter** and an **eraser** with WordPress's
personal-data registries, so that a subject access request handled through
WordPress's own privacy tools answers correctly without the plugin
inventing a parallel mechanism:

- the exporter returns the submissions matching a given email address, with
  the field values, the form and the received-at time;
- the eraser removes or anonymises them, honouring the form's retention
  setting.

This is a concrete instance of "use WordPress-native facilities wherever
practical": the mechanism already exists, and hooking it is less code and
less risk than writing a second one.

### 6.7 Why not a table for submissions — and when to change

A custom table would give a cleaner physical shape and better indexes for
field-value queries. It is rejected for the MVP because it re-implements four
things WordPress already does well: the capability model, reversible
deletion, the privacy exporter/eraser registry, and the audit trail.

**The exit criterion is explicit.** Move the storage behind the same service
interface when either of these is true:

- a single form sustains more than roughly 10,000 submissions a month, or
- the site sustains more than roughly 100,000 submissions in total, or
- a query requirement appears that cannot be expressed as "the submissions of
  form X, ordered by date".

The pipeline, the renderer and the tools do not change when that happens;
only `Submission_Store` does.

---

## 7. The REST and public API design

### 7.1 The public endpoint

One public route, under the existing AgentShell namespace convention:

```
POST /wp/v2/agentshell-forms/v1/submit
permission_callback: __return_true
```

The body:

```json
{
  "form_id": "7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a",
  "challenge": "3f9a…",
  "fields": { "name": "Ada Lovelace", "message": "…", "consent": true },
  "turnstile_token": "0x4AAAA…"
}
```

`form_id` is the **internal identifier** — 32 lowercase hexadecimal characters
(§5.1). The slug is not accepted here: the request body names the form
unambiguously, and the form's own HTML already carries the identifier.

**The challenge token.** When a form is rendered, the plugin creates a random
32-byte token, stores its SHA-256 in the challenge table with the form's
internal identifier, the published version, the hashed source address and an
expiry (two hours by default), and renders the token into a hidden input. On
submit, the row is **claimed** by the guarded update in §6.3a; the
affected-row count decides whether this is the first use.

**What the challenge does, and what it does not.** This is worth being
precise about, because it is easy to over-claim.

| Property | Does the challenge provide it? | Why |
|---|---|---|
| **Replay prevention** | **Yes** | The token is claimed exactly once. A captured token cannot be used twice, so it cannot be replayed against the endpoint |
| **Duplicate suppression** | **Yes** | A double-clicked submit, or a client that retries after a network failure, carries the same token; the second attempt is recognised and does not create a second entry (§6.3a) |
| **Cross-origin browser abuse** | **Partly** | A page on another origin cannot *read* the form's HTML, so it cannot obtain a token to embed in a cross-site post. This is the same-origin policy doing the work, not the token being a secret. It holds only while the form's page is served without a CORS policy that lets another origin read it |
| **Server-side automated abuse** | **No** | An attacker running their own HTTP client can fetch the form, read the token out of the HTML, and post with it. The token is **not** a secret from a non-browser client, and it is not an anti-bot control |

So the challenge is a **protocol correctness control** — it makes the
submission endpoint idempotent and replay-proof — and it is **not** a spam
control. The spam controls are the honeypot, Turnstile and the rate limiter
(§11.4), and the challenge is deliberately independent of all three.

**It is deliberately not a WordPress nonce.** A WordPress REST nonce is not a
CSRF defence for an anonymous form (F16): `wp_create_nonce()` for a logged-out
visitor with no cookie derives from user id 0 and an empty session token, so
every anonymous visitor on the site receives the *same* nonce value. It is not
a secret and it protects nothing here. The challenge is used instead, for
the reasons in the table above, which are the reasons a nonce cannot serve.

**Genuine retries after a network failure.** A visitor whose request reached
the server but whose response was lost would retry with the same token. The
second attempt finds the challenge already claimed. If the first attempt
succeeded, the challenge row carries the entry it produced, and the plugin
**replays the success response** — the same message, the same status, and no
second entry. If the first attempt did not succeed, the plugin refuses with a
retryable error and a **fresh challenge**, so the visitor can try again
without reloading the page. The two cases are distinguished by whether
`submission_id` is set, which is why the challenge row is claimed rather than
deleted (§6.3a).

**The response.** Success is `200` with a body that never echoes submitted
values:

```json
{ "ok": true, "message": "Thank you — we will be in touch within two working days." }
```

The message is the form's configured success message, escaped for its
context. A form configured with a `redirect_url` answers `303` with a
`Location` header instead.

Failure is `422` with per-field errors:

```json
{ "ok": false, "code": "form_invalid", "errors": { "email": "Please enter an email address we can reply to." } }
```

The error messages come from the field type's validator and are operator-
controlled templates, not user content.

**Refusals that are not field errors** — an unknown form, an expired or
already-claimed challenge, a rate limit, a failed Turnstile verification — are
answered with a **single generic shape**, `{"ok": false, "code": "form_rejected",
"message": "This submission was not accepted."}`, and never disclose which
control fired. The one deliberate exception is the **honeypot**, which
answers with a *fake success* (§11.4): telling a bot that it was caught is
telling the bot what to change.

### 7.2 The admin endpoints

Under `wp/v2/agentshell-forms/v1/`, all requiring the `manage_agentshell_forms`
capability except where noted:

| Method and path | Purpose |
|---|---|
| `GET /forms` | List forms: internal identifier, slug, name, status, version, field count, entry count. No field values |
| `GET /forms/<id>` | One form: working definition, published definition, validation result, metadata |
| `POST /forms` | Create a form (a draft) |
| `PUT /forms/<id>` | Replace the working definition, with an expected revision for the compare-and-swap |
| `POST /forms/<id>/validate` | Validate a definition without writing it |
| `POST /forms/<id>/preview` | Render the working definition to HTML, for the builder's preview |
| `POST /forms/<id>/publish` | Promote the working definition to published (§9.3) |
| `POST /forms/<id>/discard` | Discard the working definition, reverting to published |
| `DELETE /forms/<id>` | Trash a form. `confirm: true` required |
| `GET /forms/<id>/submissions` | List submissions — **metadata only** (§8.4) |
| `GET /submissions/<id>` | Read one submission's contents. Requires `manage_agentshell_forms_submissions` and the `authorize_personal_data` acknowledgement |
| `DELETE /submissions/<id>` | Trash a submission. Requires `manage_agentshell_forms_submissions`. `confirm: true` |
| `POST /submissions/<id>/restore` | Restore a trashed submission. Requires `manage_agentshell_forms_submissions` |
| `DELETE /submissions/<id>?permanent=1` | Hard delete (erasure). Requires `manage_agentshell_forms_submissions`. `confirm: true` |
| `GET /submissions/export?form=<id>&format=csv` | Export. Requires `manage_agentshell_forms_submissions` and the `authorize_personal_data` acknowledgement |
| `GET /field-types` | The field-type registry, for the builder |

**The per-user grant is deliberately absent from this list.** There is no REST
route that grants or revokes `manage_agentshell_forms_submissions`. The
grant is an `admin_post` handler in the plugin's own administrative screen,
guarded by `manage_agentshell_forms`, a cookie-authenticated session and a
nonce (§8.3a). A capability grant on a REST route would be reachable by any
administrator principal — including the MCP one, whose application password
satisfies a capability check just as a cookie does — and an agent that could
grant itself the capability could widen its own authority.

**`authorize_personal_data` is an acknowledgement on these routes too.** It
is required on the routes that return submitted values, and it is recorded in
the audit log. It is not what authorises the call: the capability is.

**Cookie authentication and nonces.** WordPress's own REST cookie
authentication already refuses a request from a logged-in browser that lacks a
valid nonce, so the permission callback needs only the capability check. The
builder's transport attaches `X-WP-Nonce` on every request, exactly as
`configurator-api.js` does.

### 7.3 The shortcode and the widget

```php
add_shortcode( 'agentshell_form', array( 'AgentShell_Forms\\Form_Shortcode', 'render' ) );
```

Attributes:

| Attribute | Meaning |
|---|---|
| `id` | The form's **internal identifier** — 32 lowercase hexadecimal characters |
| `slug` | The form's **human-readable slug** — an alternative to `id` |
| `title` | Optional — show or hide the form's name |
| `class` | Optional — extra classes on the wrapper |

**Exactly one of `id` or `slug` must be present.** If both are present they
must resolve to the same form; if they resolve to different forms, the
shortcode renders nothing and says so to an editor who can manage forms. The
resolution rule is total, because the validator refuses a slug that looks
like an internal identifier (§5.5):

- a value matching `^[a-f0-9]{32}$` is an internal identifier;
- anything else is a slug.

The builder's "copy shortcode" button copies the **slug** form, because
`[agentshell_form slug="contact"]` is something a person can read and edit in
a page — and the slug is frozen once the form is published (§5.1), so the
shortcode cannot silently break.

Unknown or unpublished forms render **nothing** to a visitor and a small
diagnostic to an editor who can manage forms.

The admin REST routes (§7.2) address a form by its **internal identifier**
only. The slug is returned in every response and accepted by the admin
screens, but it is never the key of a stored record — the identifier is
(§5.1).

The `WP_Widget` renders the same output through the same function, so there
is exactly one renderer.

### 7.4 What the plugin does *not* expose over HTTP

- No endpoint returns a submission's contents without the personal-data
  authorisation.
- No endpoint accepts a definition that has not been validated.
- No endpoint performs an outbound request to a URL derived from form
  configuration. The one outbound request in the plugin (§11.1) has a
  **hard-coded** endpoint.

---

## 8. The MCP integration proposal

### 8.1 The registration mechanism, and its consequence

The forms plugin registers its tools through the existing
`agentshell_mcp_register_tools` filter, at **priority 15** — after the MCP
plugin (priority 5) and `agentshell-blocks` (priority 10). Because the
registry keeps the *first* registration of each name, a later priority means
the forms plugin can never shadow an existing tool: if a name ever collided,
the established implementation would win and the forms one would simply be
unreachable. That is the safe failure direction.

**This has a consequence that must not be skipped.** The tool-count test
scans exactly two plugin files, and the documented count is asserted in two
places. The moment the forms plugin publishes a tool, that count is wrong
unless the test is extended. The forms phase therefore changes:

- `tests/tool-count-test.php` — scan the third plugin file as well, and
  assert that no two of the three publish the same name;
- `AGENTS.md` §2 — the authoritative count;
- `agentshell-mcp/README.md` — the count it restates.

This is the same discipline the repository already enforces: a count that
drifts must fail the build, not mislead an agent.

### 8.2 The proposed tools

> **Nothing in this section is committed.** These are *proposals* for the
> agent surface, written so the shape can be reviewed. The names are
> **provisional** and may change; the tool count in `AGENTS.md` §2 is
> **unchanged** until a phase actually ships these tools, and the tool-count
> test keeps it honest (§8.1). No tool in this list exists, and this
> documentation PR adds none.

The agent surface is deliberately small, and the table names the **capability
first** — the authoritative list of the existing tool surface is in
`AGENTS.md` §2.

| Capability | Proposed name | Effect | Authority |
|---|---|---|---|
| List the forms | `agentshell_list_forms` | Every form: internal identifier, slug, name, status, version, field count, entry count. No field values | Transport + `manage_agentshell_forms` |
| Read one form | `agentshell_get_form` | The working and published definitions, the validation result, the metadata | Transport + `manage_agentshell_forms` |
| Create a form | `agentshell_create_form` | A new draft form with a name and, optionally, a first field | Transport + `manage_agentshell_forms` |
| Change a form | `agentshell_update_form` | Replace or patch the working definition. A compare-and-swap against the expected revision | Transport + `manage_agentshell_forms` |
| Check a form | `agentshell_validate_form` | Validate a definition and return the errors and warnings, without writing | Transport + `manage_agentshell_forms` |
| Publish a form | `agentshell_publish_form` | Promote the working definition to published, increment the version | Transport + `manage_agentshell_forms` |
| Discard a form's changes | `agentshell_discard_form_changes` | Revert the working definition to the published one | Transport + `manage_agentshell_forms` |
| List the submissions | `agentshell_list_submissions` | **Metadata only**: entry id, form's internal identifier, received-at, spam status, notification status. No field values | Transport + `manage_agentshell_forms` — **form** authority, not personal-data authority |
| Read one submission | `agentshell_get_submission` | The submitted values | Transport + `manage_agentshell_forms_submissions` + the policy (§8.3) |
| Export the submissions | `agentshell_export_submissions` | CSV or JSON for one form | Transport + `manage_agentshell_forms_submissions` + the policy (§8.3) |
| Remove a submission | `agentshell_trash_submission` | Move a submission to the trash | Transport + `manage_agentshell_forms_submissions` + the policy (§8.3) |
| Erase a submission | `agentshell_delete_submission` | Hard delete — the erasure path | Transport + `manage_agentshell_forms_submissions` + the policy (§8.3) + `confirm` |

Twelve verbs. Each is a distinct act with a distinct authority, and none of
them can be merged into another without either losing an authority check or
making a destructive act look like a routine one.

Two tools are proposed for a later phase rather than the MVP:
`agentshell_delete_form` (trashing a form must also decide what happens to
its submissions, which is a decision worth making deliberately) and
`agentshell_restore_submission`.

### 8.2a The MVP surface, and what refuses by default

The owner's decision on Q11 is that **MCP submission-content access is
disabled by default** and that **no agent principal receives automatic access
to personal submission data**. The table above is therefore not one flat
surface. It is three groups with three different postures.

| Group | Tools | MVP posture |
|---|---|---|
| **Form tools** | The seven `*_form*` tools | **Ship working.** They carry no personal data — a definition is operator-authored configuration |
| **The metadata listing** | `agentshell_list_submissions` | **Ships working.** It returns identifiers, timestamps and statuses. It never returns a submitted value, so it carries no personal data and no personal-data authority is needed for it |
| **The entry-content tools** | `agentshell_get_submission`, `agentshell_export_submissions`, `agentshell_trash_submission`, `agentshell_delete_submission` | **Ship registered, and refuse.** Under the default policy (`off`, §8.3) every one of them is refused with a named error, and the refusal is audited |

**Why the destructive tools refuse too.** All four entry tools act on a
personal-data record: three expose or transform its content, and one
destroys it. Leaving `agentshell_delete_submission` reachable while
`agentshell_get_submission` is refused would produce a half-trust — an agent
that may destroy a record it may not read, and may therefore delete data it
has never seen and cannot audit. The erasure path therefore stays a **human
operation in the administrative screen** for the MVP, which is where a
subject-access erasure ought to be driven anyway, and the tool becomes
available with the rest of the group when the operator turns the policy on.

**What "refuse" means concretely.** The tool is registered, so it appears in
the registry and in `agentshell_get_capabilities`; a call reaches it and is
answered with a refusal that names the policy and the reason:

```json
{
  "ok": false,
  "code": "forms_personal_data_disabled",
  "message": "Submission contents are not available through the agent surface. The site's personal-data policy is 'off'.",
  "policy": "off"
}
```

The refusal writes an audit row (§8.5). A refusal is not an error condition
and not a failure of the transport — it is a **decision**, recorded.

**`authorize_personal_data` is an acknowledgement, not a grant.** The
parameter remains on the four entry tools, and it is **required** when the
policy is `capability` — but passing it grants nothing. It states the actor's
intent, and it is what the audit row records. The authority comes from the
capability (§8.3). A tool that accepted this flag as authority would be
granting permission to itself.

**The tools are registered in Phase 5, not Phase 1.** The owner has fixed
Phase 1's scope as the plugin foundation, the schema, the post types, the
storage invariants, the validation and the concurrency controls, and has
explicitly excluded MCP tool additions. The tool surface is therefore still a
**proposal** in this document, and the tool count in `AGENTS.md` §2 is
unchanged.

### 8.3 The two permission domains, and what actually enforces them

**Editing a form** and **reading what people wrote into it** are different
acts with different consequences. The first is a configuration act; the
second is an access to personal data.

The plugin therefore defines two capabilities:

- `manage_agentshell_forms` — create, edit, publish, discard, delete forms;
- `manage_agentshell_forms_submissions` — read, export and delete
  submissions.

**WordPress custom capabilities are the primary authorisation mechanism.**
That is the owner's decision on Q11, and it is the whole of the MVP's
authorisation model. No second credential system and no human-approval
workflow is built. The capability check happens at the moment the tool runs,
which is the only place a check actually binds.

**What an explicit acknowledgement flag is, and what it is not.** A tool
that acts on submission *contents* may require the caller to pass
`authorize_personal_data: true`. That flag is **supplied by the caller**,
and a caller who is already permitted can simply supply it. It is therefore
a **deliberation and audit mechanism, not a permission boundary**: it forces
the moment to be stated, it puts the actor and the stated reason in the audit
log, and it makes a careless read visible. It does **not** stop a caller who
has the underlying permission. Saying otherwise would be a claim the
mechanism cannot support, and the owner's decision is explicit that it is an
acknowledgement and not a grant.

**The separation therefore has to be enforced somewhere the agent cannot
supply**, and WordPress's capability system is that place.

| Mechanism | What it is | Enforced by | In the MVP? |
|---|---|---|---|
| **(A) A capability the principal does not hold** | The entry tools check `manage_agentshell_forms_submissions` and **do not** accept `manage_options` as a substitute. An agent whose principal lacks the capability cannot read submissions, and it cannot grant itself the capability | WordPress's capability system, at the moment of the call | **Yes — this is the MVP's mechanism** |
| **(B) A separate credential** | A second, dedicated secret for the personal-data tools, presented as its own header and mapped to its own principal | The transport, before the tool runs | **No.** Deferred. It remains a supported extension point (§8.3b) |
| **(C) An out-of-band human approval** | A short-lived, single-use approval recorded by a person in the admin UI | The plugin's own approval record | **No.** Deferred, and it needs an approval UI the MVP does not have |

**Why (A) bites, and what it costs.** The MCP transport requires
`manage_options` for every call, so **every MCP agent is an administrator**.
The separation therefore depends on `manage_agentshell_forms_submissions`
being a capability the administrator role does **not** hold — which is the
owner's decision on Q12, and which the plugin's activation must not undo.
§8.3a is the procedure that lets a site owner hold it anyway, without a
second account and without granting it to everybody.

**The site-wide policy switch** makes the posture explicit and adjustable
without a code change. The **default is `off`**, because the owner's decision
is that MCP submission-content access is disabled by default and that no
agent principal receives automatic access to personal submission data.

| `agentshell_forms_personal_data_policy` | Behaviour |
|---|---|
| `off` | **The default.** Submission contents are not reachable through the agent surface at all. The four entry tools refuse (§8.2a). The metadata-only listing remains available |
| `capability` | Reachable only by a principal holding `manage_agentshell_forms_submissions`, **and** only with the explicit `authorize_personal_data` acknowledgement |
| `credential` | Reachable only with the dedicated data credential (B), and only with the explicit acknowledgement. Deferred — see §8.3b |

In every policy, the **metadata-only listing stays available**, and **every
read and every refusal is audited**. Turning the policy on is an explicit,
audited operator action; it is not a side effect of activating the plugin.

### 8.3a Giving one person access to submissions

The owner's decision on Q12 requires two things that are in tension: the
submissions capability must **not** be granted to the administrator role as
a whole, and the site owner must still be able to use the submissions
interface **without creating a second WordPress account**.

Both are satisfied by granting the capability **per user** rather than per
role. WordPress stores a user's own capabilities separately from their role's
(`WP_User::add_cap()` writes the user's own `caps` user meta, and a role's
capabilities are a different set), so a capability granted to one person does
**not** propagate to any other administrator.

**The mechanism.**

```php
$user = get_user_by( 'id', $user_id );
$user->add_cap( 'manage_agentshell_forms_submissions', true );
$user->get_role_caps();          // refresh the in-memory capability set
```

and, to revoke:

```php
$user->remove_cap( 'manage_agentshell_forms_submissions' );
```

The plugin uses the `WP_User` object's methods rather than a procedural
wrapper, because the procedural `add_user_cap()` / `remove_user_cap()` are
not present in current WordPress core — the object API is the supported
route.

**The setup procedure**, as the operator will meet it:

1. Open **Appearance → AgentShell Forms → Submissions**. The screen requires
   `manage_agentshell_forms` to be reached at all, so an administrator gets
   to it — but the values are not shown without the submissions capability.
2. The screen says that submission access is limited, and offers a single
   button: **"Grant me access to form submissions"**.
3. Pressing it performs the per-user grant above, for **the account the
   operator is signed in as**, and the screen reloads with the submissions
   list.
4. **Appearance → AgentShell Forms → Access** lists every user who holds the
   capability, with the date it was granted, and offers **"Revoke"** beside
   each. Revoking is the same operation in reverse, and it is audited too.

There is no role to create, no user to invite, and no second account.

**The grant action is permission-checked, and it is not reachable by the
agent.** Three conditions, all of them necessary:

| Condition | Why it is there |
|---|---|
| The actor holds `manage_agentshell_forms` | The action is part of administering forms, and that capability is the one the administrator role does hold |
| The request carries a **cookie-authenticated session** — checked with `wp_validate_auth_cookie( '', 'logged_in' )` | An MCP transport call is authenticated by an application password or a static token, **not** by a cookie. The MCP principal is therefore refused by construction, not by policy |
| The request carries a valid `_wpnonce` for the grant action | The nonce is minted by the cookie-authenticated administrative screen, which the MCP principal cannot render, so it cannot obtain one |

**The grant action is not exposed on any `wp/v2/agentshell-forms` REST
route.** It is an `admin_post` handler in the plugin's own administrative
screen. This is deliberate: a REST route guarded by a capability check alone
would be reachable by any administrator principal, including the MCP one,
because the MCP transport's application password satisfies a capability check
just as a cookie does. The nonce and the cookie-session requirement are what
close that, and the route's absence removes the question.

**Two limitations, stated plainly, because the mechanism does not reach
them.**

**Limitation 1 — a shared principal shares its capabilities.** An agent and
a human who use **the same WordPress account** share **that account's**
capabilities. If the site owner grants the submissions capability to their
own account and then runs the agent under that same account, the agent
inherits the capability, and the separation of §8.3 is gone — not because the
mechanism failed, but because there was only one principal to separate.
**Principal-level isolation requires separate WordPress accounts.** An
operator who wants an agent that cannot read submissions must run it under an
account that does not hold the capability, and that account must not be the
one the human uses.

**Limitation 2 — the policy switch is not a site-wide data control.** The
`agentshell_forms_personal_data_policy` switch governs **the plugin's own
agent tool surface, and nothing else.** It does **not** govern, and must
never be described as governing:

- the plugin's **administrative screen**, where access is governed by the
  capability alone;
- the **WordPress personal-data exporter and eraser**, which run under
  WordPress's own authority;
- any **other plugin or theme** that queries `agentshell_entry` posts
  directly, or a REST consumer of them;
- the **WordPress REST API** for the post type itself, which is kept
  unreachable by registering the type as non-public and excluding it from
  search (§6.2).

A reader who believes the switch protects the data everywhere would be
mistaken, and the switch's own name invites that belief. It is a switch over
one tool surface, and §8.3a's grant action is a switch over one capability.

### 8.3b The extension points, and what they would need

The MVP is a capability model, and it is deliberately not a dead end. Two
extensions are anticipated, and neither is built now.

**A separately authorised agent principal.** The capability model already
supports one. A dedicated WordPress user holding *only*
`manage_agentshell_forms_submissions` — and, if the operator wants the
metadata listing too, `manage_agentshell_forms` — is a principal whose
authority is exactly what the operator chose to give it. Nothing in the
design needs to change to support it; the operator needs the account, and the
account must be **separate from the one the human uses** (Limitation 1).

**The `credential` mode.** A second trust domain, with its own secret,
presented on its own header and mapped to its own principal. It would need a
second secret to provision and rotate, a transport change, and a decision
about which capabilities the credential carries. It is named in the policy
switch so that the switch is not reshaped when it arrives, and it is not
built.

### 8.4 Read-only defaults for submissions

`agentshell_list_submissions` returns **metadata only** by default:

```json
{ "submissions": [
    { "id": 812, "form_id": "7c1d2e3a…", "form_version": 3, "received_at": "2026-10-27T14:03:11Z",
      "spam_status": "clean", "notification_status": "accepted", "field_count": 3 }
  ], "total": 1 }
```

The `notification_status` values are the ones §11.6 defines. Note
`accepted`, not `sent` and not `delivered`.

**The listing is available in every policy, including the default `off`.**
It carries no submitted value, so it carries no personal data, and no
personal-data authority is required for it. This is deliberate: an operator
must be able to see that a form is receiving submissions, and that its
notifications are being accepted, without any of that being a route to
somebody's message.

Field values appear only in `agentshell_get_submission`, and only when the
policy permits the agent surface to reach them at all (§8.3) **and** the
caller has supplied the `authorize_personal_data` acknowledgement. Under the
default `off` policy they appear in no tool's result. This is the same
discipline the codebase already uses for the custom stylesheet: a routine
read does not carry the body, because a body in a transcript is a body in a
log.

### 8.5 Audit rows

Every tool writes an audit row through the existing `Store::record_event()`
vocabulary, with new event names in the existing shape:

| Event | Written when |
|---|---|
| `form_created` | A form is created |
| `form_updated` | The working definition is written |
| `form_published` | The working definition is promoted; the payload names the version and the change |
| `form_discarded` | The working definition is reverted |
| `form_trashed` / `form_deleted` | A form is trashed or hard-deleted, with the submission count |
| `form_submission_inspected` | A submission's contents are read; the payload names the form, the acknowledgement that was presented, and the policy in force |
| `form_submission_trashed` / `form_submission_deleted` | A submission is trashed or erased |
| `form_submission_exported` | A submission set is exported; the payload names the form and the row count |
| `form_submission_access_refused` | An entry tool refused — under the `off` policy, or for want of the capability. The payload names the actor, the tool and the reason |
| `form_submission_access_granted` / `form_submission_access_revoked` | The per-user submissions capability was granted or revoked (§8.3a); the payload names the actor and the subject user |

An audit row never carries a submitted value. It carries identifiers and
counts.

**A refusal is an event, not an absence.** The content journal's rule applies
here: a refusal is written to the audit log so that a refusal is
distinguishable from a call that never happened. Under the default `off`
policy, every attempt at an entry tool produces a
`form_submission_access_refused` row — which means the audit log is also the
record of an agent that keeps trying.

### 8.6 Confirmation requirements

Destructive tools require `confirm: true`, and the requirement is **not waived
for the owner** — the same reasoning the content journal records: the owner is
precisely the actor most likely to reach for a destructive tool after a
confusing moment, which is exactly when a required confirmation is worth
something.

`agentshell_delete_form` additionally requires `delete_submissions: true`
explicitly, so that "delete the form" and "delete everything people wrote
into it" are two separate, separately-named decisions.

### 8.7 What is deliberately not exposed

- **No tool returns a submission's values as part of a list.** The listing is
  metadata only, by construction.
- **No tool edits a submission.** A submission is immutable; the only
  operations are read, export, trash and erase.
- **No tool grants a capability.** The per-user submissions grant of §8.3a is
  an administrative action in the plugin's own screen, guarded by a
  cookie-authenticated session and a nonce. It is not an MCP tool, and it is
  not on any REST route the agent transport can reach — so an agent cannot
  widen its own authority.
- **No tool changes the personal-data policy.** Turning the policy on is an
  operator action, audited as one. An agent that could flip the policy would
  be an agent that could grant itself everything the policy withholds.
- **No tool sends a notification on demand** in the MVP. It is a plausible
  addition and it is an abuse vector (an agent could mail a person repeatedly),
  so it would need its own quota and confirmation.
- **No tool exposes the Turnstile secret.** The plugin never returns it, in
  any result, under any argument. The redaction pattern the MCP transport
  already applies to arguments (`password`, `secret`, `token`, `api_key`…) is
  extended to cover it.

---

## 9. The transaction and publication model

### 9.1 Why the configuration transaction is not reused

The configuration transaction stages exactly one thing: the
`agentshell_config` option. Its staging is implemented by intercepting
`pre_update_option_agentshell_config` and returning the old value to cancel
the write. It is an elegant mechanism for a single well-known option name.

Form definitions are not that option. Extending the transaction to stage a
second store would mean changing the row's shape, the commit path, the
compare-and-swap, the preview credential's notion of "what is staged", the
doctor's validation of the staged state, and the reclaim rule — all of which
are load-bearing and carefully designed. **The forms domain should not need
any of that**, because it has a better model available: a form's own
draft/published split, which is the same idea as the transaction, expressed
in the place where it belongs.

### 9.2 The working/published model

| | Working definition | Published definition |
|---|---|---|
| Lives in | `_agentshell_form_working` (post meta) | `_agentshell_form_published` (post meta) |
| Written by | The builder, and `agentshell_update_form` | `agentshell_publish_form` only |
| Read by | The builder, the preview, the validator | **The renderer and the submission pipeline** |
| Visible to visitors | No | Yes |
| Bounded by | The compare-and-swap (§9.6) | The publish transaction (§9.3) |

The renderer reads the **published** definition and nothing else. That is the
whole isolation guarantee: an uncommitted edit is not merely hidden, it is
not on the render path at all.

This also means the **WordPress post status** is not the publish mechanism.
The post's status is `publish` for a form that has ever been published and
`draft` for one that has not; the *definition* is what is live. Using the
post status for both would mean a form could be "published" while its
definition was a draft, which is exactly the ambiguity the two meta rows
remove.

### 9.3 The publish protocol

`agentshell_publish_form` (and the builder's Publish button) does this, in
order, inside **one SQL transaction**:

1. Read the working definition and the published definition.
2. Validate the working definition. **Refuse** if it has errors — publishing
   an invalid definition is not a thing that can happen.
3. Check the working definition's revision counter against the one the caller
   saw. If they differ, refuse: somebody else changed it, and the operator
   would be publishing something they did not look at.
4. Write the working definition into `_agentshell_form_published`, set
   `version` to the current published version plus one, and update the form's
   metadata (`modified_by`, `modified_at`).
5. Write an audit row naming the actor, the new version and a description of
   what changed — reusing the discipline of `describe_change()`, which
   returns an empty string rather than inventing a description it cannot
   justify.
6. Commit.

The transaction is short and bounded by the single call, exactly as the
content write guard is. It is never held across a request boundary.

**A published definition is never silently overwritten.** The previous
published definition is kept in `_agentshell_form_published_previous`, so a
publish can be undone by a human in one step. Native WordPress revisions
(§5.8) keep a browsable record of earlier states, but they are a **secondary**
record — the plugin never reads one to decide what to render, and a restore
through WordPress's own revision UI is not a supported rollback path
(§5.8).

### 9.4 Preview

Preview shows the **working** definition. It is rendered by the same renderer
the visitor uses, with the same markup and the same CSS, so a preview is the
real thing and not an approximation.

It is delivered as a rendered HTML fragment returned to the already
authenticated caller. **No preview credential is minted**, and none is
needed: the preview changes nothing a visitor can see, so there is nothing
for a credential to protect. This is deliberately simpler than the
configuration's preview credential, and the reason is that the guarantee is
different — the configuration's preview *changes what visitors see*, this one
does not.

The preview is explicitly **not** a commitment. The publish path re-validates
and re-checks the revision, so a preview of something that has since changed
cannot be published by accident.

### 9.5 Discard

`agentshell_discard_form_changes` copies the published definition back over
the working definition and leaves the published one alone. It is the exact
inverse of the edit path, and it is a single atomic write. It writes an
audit row, because discarding work is an event worth recording.

### 9.6 Concurrent editing, and the post-meta row it needs

Two editors, or an editor and an agent, can have the same form open. The
plugin detects this rather than resolving it by last-write-wins.

**The precondition, stated first, because it is the part that is easy to get
wrong.** `wp_postmeta` has **no uniqueness guarantee** (F14): its only keys
are `PRIMARY KEY (meta_id)` and two non-unique indexes. `add_post_meta()` is a
`SELECT COUNT(*)` followed by an `INSERT` — not atomic — and
`update_post_meta()` **updates every row** matching the key. So "there is one
row per key" is not a property the schema gives us, and a compare-and-swap
written against `(post_id, meta_key)` would be written against a guarantee
that does not exist.

The design therefore rests on `meta_id`, which **is** a primary key, and on
three explicit mechanisms.

**1. Authoritative-row creation.** The first write of a definition key must
create exactly one row, and two concurrent first writes must not create two.
The plugin takes a **named advisory lock** for the form before creating the
row:

```sql
SELECT GET_LOCK('agentshell_forms_def_<form_post_id>', 0);
```

This is the pattern the codebase already proves: `Content_Recovery` uses
`GET_LOCK` for the same purpose, and the lock is **connection-scoped, not
transaction-scoped**, so a `KILL -9` of the owning process releases it via
the server's connection-close handler — which an in-process `finally` block
could not guarantee.

Under the lock, the plugin re-reads the rows for the key with a direct query
and then does exactly one of three things:

| Rows found | Action |
|---|---|
| **zero** | Create the single authoritative row with a direct `INSERT` |
| **one** | Use it |
| **more than one** | **Refuse.** This is corruption, and the plugin does not pick one and carry on |

**2. Duplicate detection.** Every read counts the rows for the key. A count
above one is a corruption signal: the plugin refuses to operate on that form
and writes an audit row naming the form and the key. The lock *prevents* the
plugin from creating a duplicate; the count *detects* one that arrived some
other way. Neither depends on a schema guarantee.

**3. Guarded updates.** Every write to a definition row is a single-row,
value-guarded update scoped by `meta_id`:

```sql
UPDATE wp_postmeta
   SET meta_value = %s
 WHERE meta_id  = %d
   AND meta_value = %s
```

The last parameter is the exact previous value. Zero affected rows means
somebody else wrote first. The write is then **refused**, and the result
names the other editor, the time of their change, and the fact that the
caller's draft was not saved. The caller can reload and re-apply.

This depends on `meta_id` being a primary key — which it is — and on nothing
else. It is the same compare-and-swap discipline the rest of the system uses:
**a lost race is refused, never overwritten.** It is why the configuration's
revision ring and the transaction row both use value-guarded writes.

**The revision counter is a separate row**, incremented in the same guarded
write as the definition it accompanies, and it is the value the caller is
asked to echo. It is also what the publish path checks (§9.3 step 3), so
"publish what I previewed" is guaranteed.

**4. Publication atomicity.** A publication writes three rows — the new
published definition, the new version, and the previous published definition
— and they must move together or not at all. The plugin runs all three
guarded updates inside **one SQL transaction** over `wp_postmeta`, in the
order:

1. copy the current published definition into `_agentshell_form_published_previous`;
2. copy the working definition into `_agentshell_form_published`, guarded on
   the working revision counter the caller presented;
3. write the new version, guarded on the current version.

Any of the three failing rolls the transaction back, so the published
definition, the version and the previous publication are always mutually
consistent. The transaction is short and bounded by the single call, exactly
as the content write guard is; it is never held across a request boundary.
The plugin owns a small local equivalent of that guard, because it must not
depend on the MCP plugin (§4.1).

**The concurrency tests this design requires**, because a claim about a race
is only worth what its test is worth:

| Test | What must hold |
|---|---|
| **Two simultaneous initial writes** | Exactly one authoritative row exists afterwards; one writer succeeds and the other is refused; no duplicate row is created |
| **Two simultaneous edits** | Exactly one write lands; the other is refused with the conflict reported; the surviving value is one of the two submitted values, never a mixture |
| **Two simultaneous publications** | Exactly one publication commits; the version increments once, not twice; the published definition and the version agree; `_agentshell_form_published_previous` holds the definition that was live before the winning publication |
| **A duplicate row injected directly into `wp_postmeta`** | The plugin detects it, refuses to operate on that form, and audits it — rather than silently reading one of the two rows |

Each of these is a destructive suite that writes to a verified test bed, and
each drives the two writers from **separate processes** so the race is real
rather than simulated.

### 9.7 The comparison with the configurator

| Configurator | Forms |
|---|---|
| One configuration transaction, staged in an option row | Per-form working/published definitions, staged in post meta |
| The transaction is shared; a second editor is *locked* out | The form is shared; a second editor is told what changed and asked to re-apply |
| Preview uses a signed credential, because the preview changes what visitors see | Preview returns the rendered fragment to the authenticated caller; nothing changes for visitors |
| Save is four phases with six outcomes | Save is two phases — write the working definition, then publish — with the same discipline about never reporting success before it is confirmed |
| Discard rolls back the transaction | Discard restores the published definition |

The forms model is the configurator's model with the shared transaction
replaced by a per-object one. That is not a simplification: it is what lets
two forms be edited by two people at once without either of them locking the
other out of the whole site.

---

## 10. Security and privacy threat model

### 10.1 The assets

| Asset | Why it matters |
|---|---|
| Submitted personal data | The most sensitive thing the system holds. UK GDPR applies to it |
| The Turnstile secret key | Its disclosure lets an attacker mint valid tokens, which disables the spam control |
| Form definitions | An operator-authored document; a malicious definition could become an injection vector |
| The rate-limit and challenge state | Its exhaustion disables the abuse controls |
| The submission pipeline's availability | A form that stops accepting submissions is an outage for the site owner |

### 10.2 The threats, and the control for each

| Threat | Vector | Control | Enforced in |
|---|---|---|---|
| **Anonymous endpoint abuse** | A script posts to the public route | Turnstile (where enabled), honeypot, rate limit, single-use challenge, size limits | `Submission_Handler` |
| **Resource exhaustion** | A huge body, a huge field, a flood | A total body limit, a per-field limit, a field-count limit, a per-source rate limit, a per-form submission quota, a site-wide form-count limit | `Submission_Handler`, `Form_Challenges` |
| **Duplicate submission** | A double-click, a retried request, a replayed POST | The single-use challenge token, claimed atomically (§6.3a). A retry whose first attempt succeeded **replays the success response** and creates nothing | `Form_Challenges::claim()` |
| **Cross-site request forgery on an anonymous form** | A page on another origin posts to the endpoint | The challenge cannot be read cross-origin (the same-origin policy), so a browser-based cross-site post cannot carry one. A WordPress nonce is **not** a defence here — anonymous visitors all share one. This is **not** a defence against a server-side attacker, who can read the form and obtain a challenge; that is what the honeypot, Turnstile and the rate limiter are for | `Submission_Handler` |
| **CSRF on the admin routes** | A page on another origin calls the admin REST route | WordPress's cookie authentication requires a valid nonce on a cookie-authenticated request; the capability check is a second gate | `Form_REST` |
| **CSRF on the MCP surface** | A browser tricked into calling the agent transport | The transport is authenticated by an application password or a static token, not by a cookie, so a browser cannot forge it | The existing transport |
| **Cross-site scripting through a field label** | A malicious definition puts `<script>` in a label | Every operator-authored string is escaped on output. There is **no** raw-HTML escape hatch anywhere in the renderer | `Form_Renderer` |
| **Cross-site scripting through a submitted value** | A value is echoed back | Submitted values are never echoed into the page. The success message is operator text; errors are operator templates | `Form_Renderer` |
| **SQL injection** | A field key or a value reaches a query | Every query uses `$wpdb->prepare()`; the definition rows are written with direct parameterised SQL; field keys are constrained to a safe character class anyway | Everywhere |
| **Duplicate definition rows** | Two writers create the definition row at once | The named advisory lock serialises creation; a row count above one is detected and refused rather than silently resolved (§9.6) | `Form_Definition` |
| **Email header injection** | A submitted value is interpolated into a subject or a recipient | Subjects have CR, LF and other control characters stripped and are length-limited; recipients come only from the definition and are validated with `is_email()`; `From:` is never set to a submitter's address — `Reply-To:` is used instead, and only from an `email` field | `Form_Notifier` |
| **Arbitrary recipient routing** | A definition names an attacker as a recipient | Recipients are drawn from an allowlist: the site administrator's address, plus addresses on the site's own domain. A recipient outside the allowlist is refused by name | `Form_Notifier` |
| **Arbitrary outbound requests** | A definition names a URL to fetch | The plugin makes exactly one outbound request, to a **hard-coded** verification endpoint. There is no configuration path to a URL | `Turnstile` |
| **Secret key disclosure** | The secret appears in a tool result, a log, a page, an export | The secret is read from a constant or a protected option and is never returned by any tool, never rendered, never exported. The transport's argument redaction covers it too | Everywhere |
| **Unauthorised submission access** | An agent, an editor or a subscriber reads submissions | The post type is non-public and excluded from search; the generic content tools are hard-limited to `post` and `page` (F6), so submissions are outside their reach; the plugin's own routes check `manage_agentshell_forms_submissions` and do **not** accept `manage_options` as a substitute, so a principal without that capability is refused (§8.3). The administrative screen is guarded by the same capability | `Form_REST`, the tools |
| **An agent self-authorising for personal data** | An agent passes `authorize_personal_data: true` to unlock a read | The flag is an acknowledgement and an audit mechanism, **not** a permission boundary (§8.3). The boundary is the capability the principal may not hold. Under the default `off` policy the contents are not reachable at all (§8.2a) | The tools |
| **An agent granting itself the capability** | An agent reaches the per-user grant and grants itself access | The grant action requires `manage_agentshell_forms` **and** a cookie-authenticated session **and** a nonce minted by the administrative screen, and it is not exposed on any REST route the agent transport can reach (§8.3a). The agent is refused by construction, not by policy | The grant handler |
| **The operator's own agent inheriting the grant** | The site owner grants themselves the capability and runs the agent under the same account | A shared WordPress principal shares its capabilities, so the agent inherits the grant. This is a **deployment** property, not a defect, and it is stated plainly: principal-level isolation requires separate accounts (§8.3a, Limitation 1) | Documentation, §8.3a |
| **The policy switch read as a site-wide control** | An operator believes `off` protects the data everywhere | The switch governs the plugin's agent tool surface only. It does not govern the administrative screen, the WordPress privacy exporter and eraser, another plugin's direct queries, or the post type's REST surface (§8.3a, Limitation 2). The limitation is stated in the document rather than left to be discovered | Documentation, §8.3a |
| **Cross-form data leakage** | A request posts field values belonging to another form | Only the fields declared in *that* form's published definition are read, and only those are stored. A value for an undeclared key is discarded | `Submission_Handler` |
| **Stored personal information** | Submissions accumulate indefinitely | Data minimisation (§6.4); per-form retention; a daily purge; the source address is an HMAC, not an address | `Submission_Store` |
| **Agent-initiated destruction** | An agent deletes a form and its submissions | `confirm: true`; a separate, separately-named `delete_submissions` flag; the per-post `delete_post` capability re-checked at the moment of destruction; an audit row for the refusal as well as the act | The tools |
| **Concurrent form edits** | Two editors overwrite each other | A `meta_id`-scoped, value-guarded update on the working definition; the loser is refused and told who won (§9.6) | `Form_Definition` |
| **Accidental publication** | A draft reaches visitors | Nothing renders from the working definition; publishing is a separate, explicit act that re-validates and re-checks the revision | `Form_Definition::publish()` |
| **Malicious field definitions** | A definition carries unknown keys, huge strings, or HTML | The validator rejects unknown keys by name, bounds every length, and validates every setting against its type's declared schema. A field type registered by a third party is **trusted code** — the registry is a trust boundary, and that is stated | `Form_Schema` |
| **Stored XSS via an option value** | An option value is HTML | Option values are slugs; option labels are escaped | `Form_Renderer` |
| **Notification failure causing a duplicate submission** | A failed email is retried by re-running the submission | The notification retry re-uses the **same submission row**. The pipeline never creates a second submission for a notification failure | `Form_Notifier`, `Submission_Store` |
| **Duplicate notification emails** | Two workers retry the same failed notification concurrently | The notification record is **claimed** by a guarded update before any send (§11.6), so only one worker attempts a given notification at a time. Delivery is at-least-once, not exactly-once, and the plugin does not claim otherwise | `Form_Notifier` |
| **Email abuse** | A form is used to mail arbitrary people | Recipients are allowlisted; a per-form notification quota; the rate limit applies to the submission that triggers the mail, not only to the request | `Form_Notifier` |
| **Turnstile used as the only defence** | The operator believes Turnstile makes the form safe | Turnstile is **one layer**. The honeypot, the rate limit, the single-use challenge and the size limits are independent of it, and the documentation says so | Documentation |

### 10.3 UK GDPR

The forms system is a controller's tool, and the design assumes the operator
is the controller. It does not assume every form has the same legal basis,
and it does not make the legal judgement.

**Data minimisation.** The form definition names exactly what is collected.
Nothing else is stored, and the source address is an HMAC rather than an
address by default.

**Lawful basis.** Each form records one of `consent`, `contract`,
`legitimate_interests` or `legal_obligation`. The plugin does not decide
which applies; it records what the operator decided, makes it visible in the
builder, and refuses to publish a form whose basis is unset. A consent-type
form is expected to use the `consent` field type and a privacy notice URL.

**Retention.** Per form, with a site default, enforced by a daily purge. A
retention period that leaves data recoverable is not a retention period, so
the purge is a hard delete.

**Subject access.** Three paths: the WordPress personal-data exporter hook
(§6.6), the CSV/JSON export tool and route, and the administrative reading
of a submission. All three are capability-checked; the agent paths require
the `manage_agentshell_forms_submissions` capability, and are refused
outright under the default `off` policy (§8.2a).

**Erasure.** The hard-delete tool, plus the WordPress eraser hook, plus the
retention purge. The per-post capability is re-checked at the moment of
deletion. Under the default `off` policy the erasure tool is refused, so an
erasure is a human operation in the administrative screen for the MVP
(§8.2a).

**Privacy notices.** A form names a privacy notice URL. The `consent` field
renders a link to it. The notice is the operator's document; the plugin's job
is to make it impossible to have a consent field without one.

**Data processors.** If the site uses an SMTP plugin, the submitted values
and the submitter's address reach that provider. If Turnstile is enabled, the
visitor's address reaches Cloudflare. **Both of these are disclosures the
privacy notice must make**, and the builder's field description should say
so where Turnstile is enabled.

**International transfers.** Cloudflare's Turnstile is a transfer to a third
country in some configurations. This is a matter for the operator's notice
and their assessment, and the plugin's part is to make the fact visible: the
builder states that enabling Turnstile means the visitor's address is sent to
Cloudflare.

**Processing metadata, not a record of processing.** The definition records
what the form collects, the stated lawful basis, the retention period and the
privacy notice URL. That is **useful processing metadata**, and it is a
genuine advantage over loose fields, because the metadata cannot drift from
the thing it describes.

It is **not** a record of processing under Article 30, and it is **not**
compliance. A record of processing covers more: the identity of the
controller and any joint controllers, the categories of data subjects and
data, the recipients and any third-party processors, the international
transfers and their safeguards, the technical and organisational security
measures, and the retention periods across the operation as a whole. A form
definition holds four of those things about one form, and knows nothing about
the controller, the processors or the security measures.

So the honest statement is: **the definition is good input to a record of
processing, and it is not one.** Whether a site is compliant is the
controller's judgement and is unaffected by anything the plugin does. What
the plugin can do — and does — is make the metadata **present, per form, in
a form that cannot drift from the collection it describes**, and make the
gaps **visible** (§10.3): the builder shows which of the fields a record of
processing would need are not known to the plugin.

### 10.4 Accessibility, to WCAG 2.2 AA

The rendered form is the product's public face, and it is held to the same
standard as the rest of the system.

| Requirement | How it is met |
|---|---|
| **1.3.1 Info and Relationships** | Every control has an explicit `<label for="…">`. Radio and checkbox groups are in a `<fieldset>` with a `<legend>`. The error summary is a `<section>` with a heading |
| **3.3.1 Error Identification** | Errors are identified in text, adjacent to the field, and referenced by `aria-describedby`. The field carries `aria-invalid="true"` |
| **3.3.2 Labels or Instructions** | Required fields are marked in text — "required" in the label — not by colour alone, and the control carries the `required` attribute. A description field carries the instruction |
| **1.3.5 Identify Input Purpose** | The `autocomplete` token is a declared setting of the text, email and telephone types, and the builder offers the appropriate tokens |
| **4.1.3 Status Messages** | The success message is rendered in a `role="status"` region with `aria-live="polite"`. On a failed submission the error summary receives focus |
| **2.4.7 Focus Visible / 2.4.11 Focus Not Obscured** | The focus order is the visual order; the error summary is the first focusable element after a failed submission; nothing overlaps the focused control |
| **3.3.7 Redundant Entry** | The builder does not ask for the same value twice in one form |
| **Turnstile** | The widget is an iframe with its own label and its own keyboard interaction. The form states, in text, what the challenge is for; and the fail-open policy (§11.2) is what keeps a failed widget from making the form unusable |
| **Colour contrast** | The form inherits the theme's tokens; the plugin's own CSS uses `currentColor` and the theme's variables rather than fixed colours, so it follows the site's contrast |

**An error summary** — a list of links to each field in error, at the top of
the form, receiving focus on failure — is included. It is the pattern that
makes a long form usable with a screen reader, and it costs little.

---

## 11. The Turnstile and notification architecture

### 11.1 Turnstile: configuration and keys

**Per-site configuration**, held in the plugin's settings:

- the **site key** — publishable by definition, since it appears in the page's
  HTML. It is exposed in the rendered form, in the builder and nowhere else.
- the **secret key** — never exposed to the browser, never returned by any
  tool, never rendered, never exported, never logged. It is read from
  `AGENTSHELL_FORMS_TURNSTILE_SECRET` when that constant is defined and
  non-empty, and otherwise from a protected option.

**The empty-constant rule applies here too.** A constant that is *defined but
empty* is not a secret, and the plugin treats it as absent — the same rule
`Transaction_Manager::preview_secret()` applies, and for the same reason: a
credential signed with an empty secret is one anybody can compute.

**The verification endpoint is hard-coded.** `https://challenges.cloudflare.com/turnstile/v0/siteverify` is a constant in the code. There is no configuration path to a different host, and no field setting that can influence it. This is the direct answer to "form configuration must not create arbitrary outbound web requests".

**The verification request** is a `wp_remote_post()` with:

- the secret and the token, plus the visitor's address when the form stores
  it;
- an explicit **timeout of five seconds** and a single retry, so a hung
  request cannot hold a visitor's submission open;
- `sslverify` left at its default — certificate verification is never
  disabled.

**The verification result** is interpreted as:

| Cloudflare response | Meaning | Action |
|---|---|---|
| `success: true` | The token is valid | Proceed |
| `success: false`, `error-codes` contains `timeout-or-duplicate` | The token expired (the documented token lifetime is short) or was already used | Refuse; record the code |
| `success: false`, `error-codes` contains `invalid-input-response` | The token is malformed | Refuse |
| `success: false`, `error-codes` contains `missing-input-secret` / `invalid-input-secret` | **The server's configuration is wrong**, not the visitor's | Refuse, and record an operator-facing error — this is a misconfiguration, and it must not be reported to the visitor as their fault |
| `success: false`, `error-codes` contains `internal-error` | Cloudflare's own transient failure | Handled by the failure policy (§11.2) |
| A network error or a timeout | The verification could not be performed | Handled by the failure policy (§11.2) |

The site key and the `action` and `cdata` values are echoed back by
Cloudflare and are checked: a token minted for a *different* site key, or for
a different action, is refused. `action` is set per form so that a token
minted for one form cannot be replayed against another.

### 11.2 The failure policy

When verification **cannot be completed** — the network is down, Cloudflare
returns a 5xx, the request times out — the plugin does not guess. The form
declares its policy:

| `spam.turnstile` | Behaviour |
|---|---|
| `disabled` | No Turnstile at all. The form relies on the honeypot, the rate limit and the challenge |
| `optional` | Verification is attempted when a token is present. A *failed* verification marks the submission as spam and stores it, but does not block the visitor. A verification that *could not be performed* is treated as "no signal" and the submission proceeds |
| `required` | A token must be present and must verify. A verification that could not be performed is **refused**, because the operator chose a form that requires the protection |

**The recommended default is `required` for a form an operator has explicitly
protected, and the failure behaviour under `required` is fail-closed.** The
reasoning: if the form says "required", a Cloudflare outage silently
downgrading to "no protection" would mean the form's protection is not
reliably there. The cost is that an outage of Cloudflare blocks that form's
submissions. That cost is the operator's to accept — which is why it is a
per-form setting with a visible warning in the builder, and why the builder
shows the operator which policy is in force.

Where the operator prefers availability over certainty, `optional` gives it,
and the submissions that pass without a verification are marked `unverified`
so the operator can see how many there were.

**Every refusal and every failure is recorded — but not always on a
submission, because a refusal usually has no submission to be recorded on.**
This distinction matters, and the earlier draft of this document got it
wrong. There are two paths:

| Path | Is there a submission row? | Where the outcome is recorded |
|---|---|---|
| The verification **failed** on a form whose policy is `required` | **No** — the submission is refused before it is stored | The plugin's **rejection log** (§11.2a) and the rate-limit counters. The visitor receives the generic refusal |
| The verification **failed or was unavailable** on a form whose policy is `optional` | **Yes** — the submission is stored | On the submission itself, as `_agentshell_form_spam = 'unverified'` (verification unavailable) or `'spam'` (verification failed) |

So a `required` form's Turnstile failure leaves **no submission row**, and
therefore cannot be recorded on one. What the operator gets instead is the
rejection log and the counters, which is enough to distinguish "people are
being blocked because Cloudflare is down" from "people are being blocked
because the secret is wrong" from "people are being blocked because they
submitted too fast".

### 11.2a The rejection log

A small, bounded, personal-data-free record of refusals that happened **before
a submission existed**.

- It is the plugin's own bounded log — the same shape as the configuration
  event ring the rest of the system uses, but owned by this plugin, because
  this plugin must not depend on the MCP plugin (§4.1).
- Each entry carries the form's internal identifier, the reason code
  (`turnstile_failed`, `turnstile_unavailable`, `rate_limited`,
  `challenge_invalid`, `honeypot`), the Cloudflare error codes where they
  apply, the timestamp, and the hashed source address. **No submitted value
  ever appears in it.**
- It is **bounded** (a fixed number of entries, oldest dropped first), and it
  is written with the same compare-and-swap discipline as the rest of the
  system, so concurrent writers cannot corrupt it.
- **Under a flood it may drop entries, and that is stated.** The log is a
  diagnostic; the *volume* is recorded exactly by the rate-limit counters, so
  a flood is visible in the counters even when the log has rolled over.
- The audit log for **administrative** acts — a form published, a submission
  erased — is separate and is never dropped silently.

### 11.3 Local development

Cloudflare publishes a pair of test credentials that always pass and a pair
that always fail. **No credential is hard-coded in this repository.** The
test pair is supplied through the same configuration as a real one, and:

- the plugin **refuses the test credentials in production**, determined by
  `wp_get_environment_type() !== 'production'`, unless an explicit
  `agentshell_forms_allow_test_keys` filter says otherwise. The test
  credentials validate nothing, so a site that shipped them would have a form
  that appears protected and is not.
- in a non-production environment the test pair is accepted, so a developer
  can exercise both the pass and the fail paths offline.
- the verification client is constructed with an injectable transport, so a
  test can drive every branch of the response handling without a network
  call — the same technique the screenshot suites use with their fake browser.

The builder's Turnstile panel states which credential is in force and whether
it is a test credential.

### 11.4 The complementary controls

Turnstile is **one layer of five**, and none of them depends on another.

1. **The honeypot.** A field that is hidden from a person and visible to a
   bot — positioned off-screen, `aria-hidden`, `tabindex="-1"`, and empty.
   A submission that fills it is **accepted with a fake success**: the
   visitor is told the form was sent, and nothing is stored and nothing is
   mailed. Telling a bot it was caught teaches the bot what to change.
   The honeypot is **not** a field type — it is a mechanism the plugin adds
   to the rendered form, so it can never be a legitimate field and can never
   be optional in a way that turns it off.
2. **The rate limit.** Per source address and per form, plus a site-wide
   ceiling: a maximum number of submissions in a fixed window. Enforced with
   the atomic counter of §6.3b, so it holds under concurrency, and it never
   admits more than the limit. The limit is per form, so a form used for a
   legitimate campaign does not consume another form's budget.
3. **The single-use challenge.** §7.1.
4. **The size limits.** A total body limit, a per-field length limit, a
   field-count limit and a form-count limit.
5. **A minimum fill time.** A form with `spam.min_seconds` set refuses a
   submission that arrives sooner than that after the form was rendered. It
   is a weak signal and it is **off by default for forms with a honeypot or
   Turnstile**, because it penalises a legitimate fast typist and, more
   importantly, an assistive technology user who pastes.

### 11.5 Notifications

**The transport is `wp_mail()` and nothing else.** It is the WordPress-native
way to send mail, and it means **every SMTP plugin works unchanged**, because
those plugins replace the transport beneath `wp_mail()` rather than replacing
`wp_mail()`. The plugin does not implement an SMTP transport, does not
configure one, and does not check whether one exists.

**What `wp_mail()`'s return value actually means, stated precisely** (F15).
`wp_mail()` returning `true` means **the message was accepted by the sending
mechanism** — handed to PHPMailer and onward to the local MTA (or to the
SMTP server an operator's plugin has configured) without an error being
raised. It does **not** mean the message was delivered to the recipient, and
it does **not** mean the message was not delivered. A message accepted by
`mail()` can still be rejected by the receiving server, quarantined as spam,
or silently dropped. A message that returns `false` was **not** accepted, and
the reason is available through the `wp_mail_failed` action.

So the plugin's vocabulary is **accepted**, never *delivered* and never
*sent*, and the submission's notification status uses those words.

**Plain text is the MVP default, and it is the only MVP format.** The reason
is technical and it is worth stating plainly: `wp_mail()` sends **one**
message and cannot emit a `multipart/alternative` body. Producing one would
mean hand-rolling MIME inside the plugin, which would conflict with whatever
transport the operator has configured and would break the "do not implement a
separate transport" rule (§11.5's first paragraph). An HTML-only message
would be the alternative, and it is worse: it loses the plain-text reading
path that many mail clients and spam filters prefer.

Therefore: the MVP sends **plain text only**, and the templates are plain
text. If HTML notifications are wanted later, the supported route is a mail
plugin that adds an HTML alternative to `wp_mail()` — not a MIME builder
inside this plugin — and any HTML the plugin ever produces is built from the
same escaped values as the plain-text part, with no raw-HTML placeholder
(§11.5).

**Administrator notification.** The recipients are drawn from an **allowlist**:

- `site-admin` — the site's administration address;
- any address on the site's own domain, up to a configured count.

An address outside the allowlist is refused **by name** when the form is
saved, not at send time, so the operator finds out in the builder rather than
at three in the morning.

**The subject and body are templates** with a small, closed set of
placeholders:

| Placeholder | Expands to |
|---|---|
| `{form:name}` | The form's name |
| `{form:id}` | The form's **internal identifier** — 32 hex characters (§5.1) |
| `{submission:id}` | The submission's id |
| `{field:<key>}` | One submitted value, escaped for the context |
| `{fields}` | Every submitted field, as `key: value` lines |

**Substitution is safe by construction.** The placeholders are resolved by a
single function that knows the context it is writing into:

- in an **HTML body**, every value is passed through `esc_html()`;
- in a **plain-text body**, control characters are stripped and the value is
  used as-is;
- in a **subject**, CR, LF and every other control character are stripped, the
  value is length-limited, and the result is truncated to a bounded length.

There is no placeholder that inserts raw HTML, and no placeholder that
inserts a header.

**The `Reply-To` header** is set from the form's `reply_to_field`, which must
name a field of type `email`, and the submitted value must pass `is_email()`
before it is used. If it does not, the header is omitted rather than
malformed. **`From:` is never set to a submitter's address.** WordPress's
default `From:` is the site's own address, which is what should be there;
setting `From:` to a submitter would be a spoofing vector and would damage
deliverability.

**A confirmation to the submitter** is optional, off by default, and uses the
same template mechanism. It is sent to the value of the form's configured
confirmation field, which must be a field of type `email`. It does not echo
the submission's contents beyond what the operator's template asks for.

**Privacy.** The notification body contains the submitted values, so it is
personal data in transit and at the mail provider. The notification record on
the submission carries the status, the attempt count, the time and a short
error message — **not** the body. A failed delivery's error message is
truncated and is stored as an operator-facing string, never as a copy of the
message.

### 11.6 Delivery failures, retries and the duplicate-submission question

This is the question the brief singles out: **a notification delivery failure
must not accidentally create duplicate submissions.**

The answer is structural. The pipeline has exactly one write that creates a
submission, and it happens **once**, before any notification is attempted:

```
1. resolve the form and its published version
2. check the size limits
3. claim the challenge token           ← atomic, single-use (§6.3a)
4. verify Turnstile, if the form requires it
5. check the honeypot                  ← a filled honeypot returns a fake success here
6. reserve a rate-limit slot           ← atomic, capped (§6.3b)
7. validate every field against the published definition
8. write the submission row             ← the only write that creates anything
9. attempt the notification             ← may fail
10. record the notification outcome
11. return the response
```

Steps 9 and 10 cannot create a submission, and they run after step 8 has
committed. A failed `wp_mail()` therefore leaves a submission that exists and
is marked `notification.status = failed` — it does not produce a second
submission, and it does not roll back the first one.

**That answers the duplicate-submission question, and it is a different
question from the duplicate-notification question.** Reusing the submission
row guarantees that a failed notification never produces a second
*submission*. It does **not** guarantee that a failed notification never
produces a second *email* — and no mechanism built on `wp_mail()` can.

**The notification record** lives in the submission's
`_agentshell_form_notification` meta row:

```json
{
  "status": "pending",
  "attempts": 0,
  "next_attempt_at": "2026-10-27T14:03:11Z",
  "claimed_at": null,
  "claimed_by": null,
  "last_error": null,
  "accepted_at": null
}
```

The status vocabulary, and what each value actually asserts:

| Status | Asserts |
|---|---|
| `pending` | The notification has not been attempted, or is due for another attempt |
| `claimed` | A worker has taken it and may be sending it right now |
| `accepted` | **`wp_mail()` returned true.** The message was accepted by the sending mechanism. It does **not** assert delivery |
| `failed` | The attempt was made and `wp_mail()` returned false, or the attempt errored |
| `uncertain` | The attempt's outcome is unknown — the transport timed out, or the worker died while holding the claim |
| `exhausted` | The attempt limit was reached. No further automatic attempt will be made |

**The atomic claim.** Before any send, a worker takes the notification by a
guarded update on the record — the same `meta_id`-scoped, value-guarded
compare-and-swap of §9.6:

```sql
UPDATE wp_postmeta
   SET meta_value = %s
 WHERE meta_id  = %d
   AND meta_value = %s
```

where the new value is the record with `status = 'claimed'`, the attempt
count incremented, `claimed_at` and `claimed_by` set, and the expected value
is the record exactly as it was read. **One affected row means this worker
owns the notification**; zero means somebody else already claimed it, and
this worker moves on. Two workers can therefore never send the same
notification concurrently.

**Retry limits and backoff.** Attempts are capped, and the delay grows:

| Attempt | Delay before the next attempt |
|---|---|
| 1 | 1 minute |
| 2 | 5 minutes |
| 3 | 30 minutes |
| 4 | 2 hours |
| 5 | 12 hours |

After the fifth attempt the status becomes `exhausted` and the submission is
flagged for the operator, who can re-send it by hand from the administrative
screen. The attempt count and the last error are kept on the record, so the
operator can see that it was tried five times and why it failed each time.

**Reconciliation after an uncertain outcome.** Two situations leave the
notification in a state nobody can interpret:

- the worker **died** while holding the claim, so the record is stuck at
  `claimed`;
- the transport **timed out**, so the plugin does not know whether the
  message was accepted.

The reconciler — the same cron job — treats a `claimed` record whose
`claimed_at` is older than the **claim TTL** as `uncertain`, and an
`uncertain` record is retried at the next attempt time. That is the only way
an uncertain notification can make progress.

### 11.6a The claim lifetime and the transport timeout

Two clocks, and the invariant between them is the thing to get right.

| Clock | Value | What it bounds |
|---|---|---|
| **Claim TTL** | **900 seconds** (fifteen minutes) | How long a notification may sit in `claimed` before the reconciler may treat it as `uncertain` and retry it |
| **Attempt budget** | **60 seconds** by default, configurable | How long one send attempt may take before the plugin stops trusting the attempt's outcome |
| **Transport timeout** | The attempt budget, applied to the mail transport | How long the transport itself waits for the remote server |

**The invariant: the transport timeout is strictly shorter than the claim
TTL.** The claim TTL is the period after which a *second* worker may take the
notification over. If a transport could still be running when that happens,
two workers could send the same notification — which is precisely the
concurrency the claim exists to prevent. The margin is therefore not a
matter of taste: **900 > 60**, and the plugin **refuses a configuration in
which the transport timeout is not strictly less than the claim TTL**, by
name, at the point the configuration is saved.

**How the transport timeout is set.** `wp_mail()` builds a PHPMailer instance
and fires the `phpmailer_init` action with that instance **by reference**,
immediately before `send()`. That is the supported hook for configuring the
transport, and the plugin uses it to set PHPMailer's `Timeout` property to
the attempt budget.

Two things this gets right that "just call `wp_mail()`" does not:

- **The guarantee does not depend on a default.** PHPMailer's own default
  `Timeout` is 300 seconds, which already satisfies the invariant — but a
  default is not a guarantee, and an SMTP plugin may set its own. The plugin
  sets the value explicitly, so the bound holds whatever else is installed.
- **The bound holds for the transport the operator configured.** Setting
  `Timeout` on the PHPMailer instance covers the SMTP path as well as the
  local `mail()` path, because the timeout is a property of the client that
  performs the network operation.

**How an over-budget attempt is classified.** `wp_mail()` is synchronous and
PHP cannot interrupt it, so the plugin cannot stop a slow transport — it can
only **measure** it. The plugin records the wall-clock time around the call
and classifies the outcome by what the call returned *and* how long it took:

| `wp_mail()` returned | Elapsed | Recorded status |
|---|---|---|
| `true` | within the budget | `accepted` — the message was accepted by the sending mechanism |
| `true` | **over the budget** | `uncertain` — the send exceeded its budget; acceptance is probable but the attempt is not trusted as settled, and the reconciler may retry it |
| `false` | any | `failed` — the message was not accepted, with the transport's error message |

The middle row is the reason the budget is measured at all. A send that
returns `true` after running past its budget has already cost more than the
operator's configuration allows, and treating it as settled would hide the
fact that the transport is misbehaving. Recording it as `uncertain` puts it
back in front of the reconciler, which is where an ambiguous outcome belongs.

**What this does not do.** It does not make delivery exactly-once, and it
does not shorten the at-least-once window. A transport that accepts the
message and loses the acknowledgement still produces a possible duplicate on
retry; the bounded timeout narrows how long that ambiguity lasts, it does
not remove it. The five-attempt limit, the backoff and the atomic claim
remain the guarantees that are actually held, and the claim TTL of fifteen
minutes is the owner's accepted value for the MVP.

**The delivery guarantee is at-least-once, and the plugin does not claim
otherwise.** If the transport accepted a message and the response was lost,
the retry can produce a second email. Exactly-once delivery is not
achievable with `wp_mail()`, and no amount of bookkeeping in this plugin
changes that. What the plugin does is bound the exposure and make it
detectable:

- the attempt count is small (five) and the backoff is long;
- every message carries the submission's identifier in the body **and** in a
  `Message-ID` header, so a duplicate is recognisable by a receiving client
  or mail system;
- the attempt history is on the submission, so an operator who sees two
  emails for one submission can see that the first was uncertain.

**A failed notification does not fail the submission.** The visitor gets a
success response. The operator sees a failed notification on the submission
and can re-send it from the administrative screen.

**A delivery that hangs** is bounded by the transport timeout the plugin sets,
which is strictly shorter than the claim TTL (§11.6a). An attempt that
exceeded its budget is recorded as `uncertain` with the timeout as the
reason — not as `failed`, because the plugin genuinely does not know.

### 11.7 Preventing email abuse

- The recipients are allowlisted (§11.5) and validated at save time.
- The notification is triggered by a *submission*, and the submission is
  rate-limited, so a flood of submissions produces a flood of notifications —
  bounded by the rate limit and by a **per-form notification quota** (a
  maximum number of notifications per hour, independent of the submission
  rate limit, so a form that is deliberately generous to people is still
  bounded in what it can send).
- The template placeholders are a closed set. There is no way to make the
  plugin send to an address that is not on the allowlist.

### 11.8 Future capabilities and what each would owe

None of these is in the MVP. Each is listed with the work it would require
**before** it could be proposed, because each of them changes the security
model rather than extending it.

| Capability | What it would have to solve first |
|---|---|
| **File uploads** | A storage location outside the document root; MIME sniffing by content, never by extension; a size limit; randomised file names; malware scanning or an explicit refusal to accept uploads without it; the cleanup of files when a submission is deleted; the exclusion of uploads from the release archive and from the theme's media handling; a different privacy notice; and the fact that an uploaded file is personal data with its own retention |
| **Conditional logic** | A rule language that cannot be used to exfiltrate or to hide a field from the server while still accepting its value. Every conditional must be evaluated **server-side** on the published definition; the client-side version is a convenience only |
| **Multipage forms** | A state machine that survives a session, a save-and-resume path (which is a durable server-side record and therefore a new privacy surface), and a final submission that assembles pages whose individual validations happened at different times |
| **Payment processing** | A completely different domain: a merchant account, PCI obligations, a signed webhook, an idempotent charge, and reconciliation. It does not belong in a forms engine and should be an integration with a payment provider, not an implementation |
| **Calculated fields** | The calculation must be evaluated server-side, must not be able to read anything the server did not put into it, and must not be trusted on the client. A total computed in the browser and submitted as a value is an invitation to change the price |
| **A separate SMTP transport** | Only with a documented requirement. `wp_mail()` already respects whatever transport the operator has configured, and a second transport in the plugin would be a second place for credentials to live |

---

## 12. The visual builder architecture

### 12.1 Where it lives

A dedicated admin page in the plugin: **Appearance → AgentShell Forms**. It is
registered with `add_menu_page()`, and it requires the
`manage_agentshell_forms` capability. It is theme-independent, and it uses
the WordPress admin's own styles and conventions rather than trying to look
like the AgentShell configurator.

Three screens, plus one that is about access rather than about forms:

| Screen | Purpose |
|---|---|
| The list | Every form: name, slug, status, version, field count, entry count, last change. Actions: edit, duplicate, publish, discard, trash |
| The builder | The field list and the field inspector, plus the settings tabs (notification, spam, storage, privacy) and the preview |
| The submissions | One form's submissions: metadata by default, the values on demand, export, trash, erase |
| The access | Every user who holds `manage_agentshell_forms_submissions`, when it was granted, and a **Revoke** button beside each. Reached from the submissions screen, which offers **"Grant me access to form submissions"** when the signed-in user does not hold it (§8.3a) |

The access screen is deliberately **not** a role editor. It grants a
capability to one user and takes it away again; it does not create roles, it
does not modify the administrator role, and it is not reachable by the agent
(§8.3a).

### 12.2 The module split

The split mirrors the configurator, and it exists for the same reasons: the
models are testable without a browser, and only one module knows both the
models and the DOM.

| Module | Responsibility |
|---|---|
| `admin/js/forms-model.js` | Pure models: the form model, the field model, the status model, the settings model. No DOM, no network. Exports via `module.exports` so a Node suite can exercise it |
| `admin/js/forms-api.js` | The transport. Attaches `X-WP-Nonce` on every request; knows the REST shapes |
| `admin/js/forms-ui.js` | The view. Builds DOM, holds no state |
| `admin/js/forms-app.js` | The application layer. The only module that knows both models and DOM |

**No build step**, exactly as the configurator has none. The files are served
as they are on disk.

### 12.3 The draft, save, preview and discard model

The builder holds a **draft** in the browser and a **saved** copy of what the
server last reported. The rules are the configurator's, unchanged:

- **Dirty is computed from the data**, by comparing the draft against the
  saved copy — never from a flag that can go stale.
- **Preview stages; it does not commit.** Preview sends the current draft to
  `POST /forms/<id>/preview` and renders the returned HTML in a panel. It
  never writes.
- **Save writes the working definition.** It is one call with an expected
  revision. The server's response carries the new revision.
- **Publish is a separate, explicit act.** It names what will change and
  asks for confirmation. It is never a side effect of saving.
- **Discard reverts the draft to the published definition**, discarding
  unsaved browser state as well, and says which of the two it did.
- **Never report success before it is confirmed.** A failed write says the
  change was not saved. A write whose outcome is unknown is reconciled
  against the server before anything else is attempted — and if the outcome
  cannot be established, the builder says so rather than guessing.

### 12.4 The status vocabulary

The status chip distinguishes the same states the configurator's does, because
the same confusion applies:

| State | Meaning |
|---|---|
| Saved | The working definition matches the server |
| Unsaved changes | The draft differs from the saved copy |
| Previewing draft | The preview shows something that is not the published form |
| Changes since preview | The draft changed after the last preview |
| Published | The published definition is current with the working definition |
| Changed by someone else | The compare-and-swap refused the write; the other editor is named |
| Not saved — validation error | The server refused the definition, with the errors listed |
| Save outcome could not be verified | The outcome is unknown and has been reconciled, not guessed |

Staged work is never labelled *Saved*.

### 12.5 Insertion

The builder offers three ways to place a form, each one a single action:

1. **Copy the shortcode.** The shortcode is on the clipboard, and the button
   says so. This is the primary path and it works in every editor.
2. **Open the page.** A link to the post-edit screen, so the operator can
   paste it themselves.
3. **Insert into the block editor.** A button that inserts a Shortcode block
   at the cursor, using the editor's own API. This is a convenience and it
   degrades: if the editor API is not available, the button is not shown, and
   the clipboard path remains.

The builder never silently publishes. It never edits a page's content without
the operator asking for it.

### 12.6 The builder's own accessibility

The builder is an administrative tool and it is held to the same standard:
the field list is a list with a label per item, the reorder control is a
button with an accessible name that says what it moves and where, every input
has a label, the error list is announced, and the whole builder is operable
from the keyboard. The field list uses explicit **Move up** and **Move down**
buttons rather than drag-and-drop, because drag-and-drop is not keyboard
operable and because a screen-reader user cannot do it at all.

### 12.7 The AgentShell configurator seam

In the AgentShell configurator, the component picker gains a **Forms** group
— populated only when the forms plugin is active, and empty otherwise. A
chosen form becomes a block entry of the new `agentshell_form` type. The
theme's change is one group in the picker's `build_choices()` and one branch
in the inspector, plus the five coordinated places the block vocabulary
requires (§13.2, Phase 6).

The configurator does **not** become the builder. It offers a route into it.

---

## 13. The phased development roadmap

### 13.1 The phase table

| Phase | Title | Size | Depends on |
|---|---|---|---|
| 1 | The plugin foundation, the schema, the storage invariants | Large | — |
| 2 | Rendering and insertion | Medium | 1 |
| 3 | The submission pipeline, notifications and Turnstile | Large | 1 (2 for the end-to-end path) |
| 4 | The visual builder | Large | 1, 2 |
| 5 | The agent tool surface | Medium | 1 (3 for the submission tools) |
| 6 | The AgentShell integration and release packaging | Small | 2 |

Six PRs. Phase 1 is the only hard blocker.

**Phase 1's scope is fixed by the owner**: the plugin foundation, the
authoritative schema, the post types, the storage invariants, the validation
and the concurrency controls. **No visual builder, no submission
notifications, and no MCP tool additions.** Phase 1 therefore establishes
the floor everything else stands on, and nothing that can be deferred is
built in it.

**No phase depends on a later phase.** The dependencies above are the whole
graph, and each is a *build* dependency, not a convenience:

- **Phase 2** needs the schema and the definition store (Phase 1). It also
  **mints** the challenge, because the challenge is part of the rendered form
  — so the challenge store and its minting primitive are in **Phase 1**, not
  Phase 3. Phase 3 consumes what Phase 2 mints.
- **Phase 3** needs the schema, the definition store and the challenge
  primitives (Phase 1). It does **not** need the renderer: the pipeline
  validates and stores without rendering anything. The *end-to-end* path
  (render → submit) needs Phase 2's renderer, which is why the acceptance
  criterion in Phase 3 is stated as "with Phase 2 present".
- **Phase 4** needs the definition store (Phase 1) and the renderer (Phase 2),
  because the preview renders the working definition through the real
  renderer. It does **not** need Phase 3: the builder edits definitions, and
  the entry count on the list screen is the only thing it takes from Phase 3,
  which it shows as "—" when that phase has not landed.
- **Phase 5** splits: the seven **form** tools need only Phase 1. The five
  **submission** tools — the metadata listing and the four entry tools —
  need Phase 3's store. If the phases are cut short, the submission tools are
  the part that waits — the form tools do not.
- **Phase 6** needs the renderer (Phase 2).

### 13.2 The phases in detail

#### Phase 1 — The plugin foundation, the schema and the storage invariants

**Scope — fixed by the owner.** Phase 1 establishes the floor: the **plugin
foundation**, the **authoritative schema**, the **post types**, the
**storage invariants**, the **validation** and the **concurrency
controls**. Concretely:

- the plugin skeleton — its directory layout, its bootstrap, its activation
  and uninstall handlers, its autoloading and its version;
- the two post types — `agentshell_form` and `agentshell_entry` — and the
  capability type they use (§8.3);
- the authoritative schema and its validator, and the field-type registry
  with the ten MVP types;
- the storage invariants — the working/published split, the authoritative-row
  creation, the duplicate detection, the `meta_id`-scoped compare-and-swap,
  and the publication transaction;
- the validation — the rule set of §5.5, every error code by name, and the
  rejection of unknown keys;
- the concurrency controls — the named advisory lock, the row count, the
  guarded writes, and the **protocol state** primitives: the challenge
  table and the rate-limit table with their claim and reserve primitives and
  their pruning.

**Explicitly not in Phase 1.** No **visual builder** (Phase 4). No
**submission notifications** — no notifier, no `wp_mail()` call, no
notification record, no retry logic (Phase 3). No **MCP tool additions** —
no tool is registered, and the tool count in `AGENTS.md` §2 is unchanged
(Phase 5). No renderer (Phase 2), no public endpoint (Phase 3), and no
AgentShell integration (Phase 6).

**Reused.** Nothing — this is the bottom of the stack. It reuses *WordPress*:
the post type, post meta, the revision machinery, `dbDelta`, the cron API.

**New modules.** `class-form-post-types.php`, `class-form-schema.php`,
`class-form-field-types.php`, `class-form-definition.php`,
`class-form-capabilities.php`, `class-form-challenges.php`,
`class-form-rate-limits.php`.

**Data model.** The `agentshell_form` and `agentshell_entry` post types; the
`_agentshell_form_working`, `_agentshell_form_published`,
`_agentshell_form_published_previous`, `_agentshell_form_revision`,
`_agentshell_form_id`, `_agentshell_form_slug`, `_agentshell_form_version`
and `_agentshell_form_meta` meta keys, the three definition keys registered
with `revisions_enabled => true` where §5.8 says so; the
`agentshell_form_challenges` and `agentshell_form_rate_limits` tables.

**MCP.** None. No tool is added in this phase.

**WordPress integration.** The post type registration, the `register_post_meta`
calls, the activation hook that creates the tables and **registers** the two
capabilities — granting `manage_agentshell_forms` to the administrator role
and **not** granting `manage_agentshell_forms_submissions` to any role
(§8.3) — and the uninstall handler that removes the tables and the options.

**Security.** The validator is the boundary; unknown keys are refused by
name; every length is bounded; the field-type registry is documented as a
trust boundary. The capability split is **established** here — the
`manage_agentshell_forms_submissions` capability is registered, it is
**not** granted to the administrator role, and the per-user grant mechanism
of §8.3a is implemented — but the screens that use it are Phase 3's.

**Migration.** None — this is the first version. The schema carries a
`schema_version` so that a later version can be recognised and migrated.

**Tests.** `tests/forms-schema-test.php` (the validator, each error code, the
field-type registry, the settings schema walk); `tests/forms-definition-test.php`
(create, read, write, publish, discard, the compare-and-swap refusal, the
version increment, the authoritative-row creation, the duplicate detection);
`tests/forms-concurrency-test.php` (the four named races of §9.6, each driven
from a separate process); `tests/forms-challenge-test.php` (the claim, the
expiry, the retry replay); `tests/forms-rate-limit-test.php` (the reserve, the
cap, the concurrency property); `tests/forms-capabilities-test.php` (the
capability is registered, it is not granted to the administrator role, and
the per-user grant and revocation work).

**Acceptance.** A form can be created, edited, published and discarded
programmatically. An invalid definition is refused with a named error. A
second writer with a stale revision is refused, and the working definition is
unchanged. Two simultaneous initial writes produce exactly one authoritative
row. The challenge is claimed exactly once. A rate-limit bucket never admits
more than its limit. The administrator role does not hold
`manage_agentshell_forms_submissions`, and a single user can be granted it
without any role change. **No tool has been registered, and the documented
tool count is unchanged.**

#### Phase 2 — Rendering and insertion

**Scope.** The renderer, the shortcode, the widget, the plugin's CSS, the
accessible markup, the error summary, the honeypot markup.

**Reused.** The theme's design tokens (`var(--theme-*)`), so the form looks
like the site; the `WP_Widget` API; WordPress escaping.

**New modules.** `class-form-renderer.php`, `class-form-shortcode.php`,
`class-form-widget.php`, and the plugin's stylesheet.

**Data model.** None.

**MCP.** None.

**WordPress integration.** The shortcode, the widget registration, the
plugin's stylesheet enqueued on the front end when a form is present.

**Security.** Everything operator-authored is escaped; there is no raw-HTML
path. The renderer reads the **published** definition only.

**Migration.** None.

**Tests.** `tests/forms-render-test.php` (the markup for every field type,
the escaping, the label association, the group semantics, the published-only
rule); `tests/forms-render-live-test.php` (the shortcode and the widget
against a real WordPress).

**Acceptance.** A published form renders valid, accessible markup on any
theme. A form with unpublished changes renders the published version. A
label containing `<script>` renders as text. A radio group is a fieldset with
a legend.

#### Phase 3 — The submission pipeline, notifications and Turnstile

**Scope.** The authoritative handler; the field validation; the challenge
claim; the rate-limit reservation; the honeypot; the Turnstile client; the
notifier; the submission store; the notification claim, retry and
reconciliation; the retention cron; the privacy exporter and eraser; the
rejection log; the submissions screen.

**Reused.** `wp_mail()`, `is_email()`, `wp_remote_post()`, the cron API, the
WordPress privacy registries, the `Store::record_event()` *vocabulary* and the
`Content_Write_Guard` *pattern* — both re-implemented locally, because the
forms plugin must not depend on the MCP plugin.

**New modules.** `class-submission-handler.php`, `class-submission-store.php`,
`class-form-notifier.php`, `class-turnstile.php`, `class-form-privacy.php`,
`class-form-rejections.php` (the bounded rejection log), `class-form-admin.php`
(the submissions screen).

**Data model.** The `agentshell_entry` post type and its meta keys; the
notification record; the retention setting.

**MCP.** None yet, but the submission store is written with the tool
authorisation in mind from the start, and the notification record is written
so that the claim is a compare-and-swap on it (§11.6).

**WordPress integration.** The public REST route, the cron jobs, the privacy
hooks, the admin screen.

**Security.** The whole of §10.2 applies. The submission is written before
the notification is attempted, so a delivery failure cannot duplicate it. The
notification is claimed before it is sent, so two workers cannot send it
twice concurrently. A Turnstile refusal on a `required` form leaves no
submission and is recorded in the rejection log instead (§11.2a).

**Migration.** None.

**Tests.** `tests/forms-submission-test.php` (validation, required fields,
the honeypot's fake success, the challenge's single claim, the rate limit,
the size limits, the malformed request, the cross-form key discard, the
retry's idempotent replay);
`tests/forms-turnstile-test.php` (every branch of the response handling, the
failure policies, the test-credential refusal in production, the recording
path for a refusal with no submission);
`tests/forms-notification-test.php` (the templates, the escaping, the header
injection attempts, the recipient allowlist, the atomic claim, the retry
backoff, the reconciliation of an uncertain outcome, the reuse of the row);
`tests/forms-retention-test.php` (the purge, the deletion paths).

**Acceptance.** A submission is stored once, validated against the published
version, and produces exactly one notification. A failed notification leaves
one submission and a retry re-uses it. Two workers cannot send the same
notification concurrently. A honeypot-filled submission returns success and
stores nothing. A Turnstile token that was already used is refused, and the
refusal is recorded where it can be — in the rejection log, because there is
no submission to record it on.

#### Phase 4 — The visual builder

**Scope.** The admin pages; the four modules; the field list and inspector;
the settings tabs; the preview; the status chip; the insertion affordances.

**Reused.** The configurator's module split, its status vocabulary, its save
and reconcile discipline, its `X-WP-Nonce` transport pattern, and its
`module.exports` convention for Node testing.

**New modules.** `class-form-rest.php` (the admin REST controller), the four
admin JS modules, the admin stylesheet, the admin page registration.

**Data model.** None — the builder uses the definitions the store already has.

**MCP.** None.

**WordPress integration.** The admin menu, the REST routes, the admin assets.

**Security.** The capability check on every route; the compare-and-swap so a
second editor is not overwritten; nothing is published without an explicit
act.

**Migration.** None.

**Tests.** `tests/forms-builder-test.js` (the models: dirty computation, the
status vocabulary, the field model, the reorder model, the payload shape);
`tests/forms-builder-browser-test.js` (the real interface in a real browser,
in the shape of `configurator-browser-test.js`).

**Acceptance.** A form can be built from nothing in the browser, previewed,
saved, published and discarded. A second tab's save is refused with the
other editor named. The status chip never says *Saved* for unsaved work.

#### Phase 5 — The agent tool surface

**Scope.** The twelve tools; the authorisation model; the audit rows; the
count test extension; the documented count. The tool surface ships in the
three groups of §8.2a: the seven form tools work, the metadata-only listing
works, and the four entry tools are registered and refuse under the default
`off` policy.

**Reused.** The base tool class, the `agentshell_mcp_register_tools` filter,
`Store::record_event()`, the two-part authority pattern, the transport's
argument redaction.

**New modules.** `class-form-tools.php` and the tool classes.

**Data model.** None.

**MCP.** The whole surface.

**WordPress integration.** None beyond the plugin's own activation.

**Security.** Read authority and destructive authority separated. The
read authority is the **capability the principal may not hold**
(`manage_agentshell_forms_submissions`), and `manage_options` is **not**
accepted as a substitute; the `authorize_personal_data` acknowledgement is
required in addition, as a deliberation and audit mechanism rather than as
the boundary (§8.3). The policy switch defaults to `off`, so the four entry
tools refuse until the operator turns it on (§8.2a). `confirm` on
destruction; the per-post `delete_post` re-check at the moment of deletion;
the secret never returned. **No tool grants a capability and no tool changes
the policy** (§8.7), so an agent cannot widen its own authority.

**Migration.** The tool-count test is extended to three plugin files, and the
documented count is updated in the two places the documentation gate checks.

**Tests.** `tests/forms-tools-test.php` (each tool's schema, its refusals,
its result shape, its authorisation behaviour, its audit rows);
`tests/forms-personal-data-test.php` (a principal **without**
`manage_agentshell_forms_submissions` is refused, `manage_options` alone is
not accepted as a substitute, the `off` policy refuses the four entry tools,
the metadata-only listing never contains a field value, and a refusal is
audited).

**Acceptance.** Every tool is reachable by name and returns a documented
shape. A listing never contains a field value. Under the default policy an
agent is **refused** when it tries to read a submission's contents, and the
refusal is audited; with the policy changed to `capability`, an agent whose
principal lacks the submissions capability is still refused. The documented
count equals the registry's.

#### Phase 6 — The AgentShell integration and release packaging

**Scope.** The `agentshell_form` zone block type; the picker's Forms group;
the inspector's branch; the release include list; the checker's required
list; the documentation pointer map.

**Reused.** The zone model, the block vocabulary, the picker, the release
tooling.

**New modules.** None; this phase changes the theme's block vocabulary in
the five coordinated places, and the release tooling's two lists.

**Data model.** None.

**MCP.** None.

**WordPress integration.** The five places: the renderer's `switch`, the
configuration validator's allowlist, the doctor's `ALLOWED_BLOCKS`, and the
two zone tools' type lists; plus the configurator's picker and inspector.

**Security.** The theme's case calls the forms plugin's public function
guarded by `function_exists()`, so a site without the plugin degrades to
"no form" rather than a fatal error. The doctor's block-type validation
applies to the new type as it does to the others.

**Migration.** The block vocabulary grows. Because the vocabulary is a
contract, the change is made in all five places in one PR, and the doctor's
allowlist is the authority — the same discipline the existing block types
have.

**Tests.** `tests/forms-zone-block-test.php` (the block renders the form, the
doctor accepts it, the zone tools accept it, the picker offers it);
`tests/forms-packaging-test.php` (the archive contains the plugin and the
checker requires it).

**Acceptance.** A form can be placed in a header or footer area from the
configurator. The release archive contains the forms plugin, and the checker
refuses an archive that does not.

### 13.3 The dependency graph

```
              ┌──────────────────────┐
              │        Phase 1       │  the model, the schema, the store,
              │  the model, the      │  the protocol state (challenge +
              │  schema, the store   │  rate-limit tables and primitives)
              └──┬────────┬───────┬───┘
         ┌───────┘        │       └───────┬──────────────┐
    ┌────▼────┐     ┌─────▼─────┐   ┌────▼─────┐   ┌────▼────┐
    │   P2    │     │    P4     │   │    P5    │   │    P3    │
    │ render  │────▶│  builder  │   │   tools  │   │ pipeline │
    │ (mints  │     └───────────┘   │ (form +  │   │ notifs   │
    │  the    │                      │  entry)  │   │ turnstile│
    │  token) │                      └────┬─────┘   └────┬─────┘
    └─────────┘                           │              │
                                          └──────▶ P5 ◀───┘
                                                 (entry tools)
    ┌─────────┐
    │   P6    │◀─ needs P2 (the renderer)
    └─────────┘
```

### 13.4 What can be developed in parallel

- **Phase 4's model work** — the pure models in `forms-model.js` — can be
  written against the frozen schema while Phase 2 and Phase 3 are in flight.
  It has no dependency on the renderer.
- **Phase 5's form tools** can be written against the Phase 1 service API
  while Phase 3 is in flight. The tools call the definition service, not the
  pipeline. Only the five entry tools wait for Phase 3.
- **Phase 3's Turnstile client** is self-contained once the schema is
  frozen; it depends on nothing but the configuration and the transport.
- **Phase 3's notifier** depends on the Phase 1 schema for the template
  settings and on nothing else.
- **Phase 6's release-tooling change** can be made at any point after the
  plugin exists, and is worth doing early so that no intermediate release
  ships a plugin the archive does not carry.

Phase 2 and Phase 5 are the natural pair to run concurrently, and Phase 4 is
the natural third.

---

## 14. The verification and acceptance plan

### 14.1 The layers

| Layer | What it proves | Where it runs |
|---|---|---|
| **Unit** | The validator, the field types, the templates, the models, the argument handling | The stub bootstrap, no database |
| **Live** | The behaviour against a real WordPress: the post types, the meta, the REST routes, the shortcode, the widget, the privacy hooks | A real WordPress, read-only or writing to a test bed |
| **Destructive** | The submission pipeline, the retention purge, the deletion paths, the rate limiter | A **verified** test bed, with the fail-closed identity check |
| **Browser** | The rendered form, the builder's interface, the keyboard operation, the screen-reader semantics | A real browser, against the real interface |
| **Integration** | The whole suite against a disposable MySQL and a disposable WordPress | CI's `integration` job |

The repository's own discipline applies: **the unit layer is not a substitute
for the real thing.** The unit suites pin targeted regressions; the live
suites prove the behaviour. A forms system that is only ever tested against
stubs has not been tested.

### 14.2 The suite-by-suite plan

| Concern | Suite | Kind |
|---|---|---|
| Form-definition validation | `tests/forms-schema-test.php` | unit |
| Field-type registry and the settings walk | `tests/forms-schema-test.php` | unit |
| Definition storage, versioning, the compare-and-swap | `tests/forms-definition-test.php` | unit |
| Rendering, escaping, labels, groups | `tests/forms-render-test.php` | unit |
| Rendering on a real site | `tests/forms-render-live-test.php` | live |
| Input validation and required fields | `tests/forms-submission-test.php` | destructive |
| Malformed requests, wrong types, oversized bodies | `tests/forms-submission-test.php` | destructive |
| The honeypot's fake success | `tests/forms-submission-test.php` | destructive |
| Duplicate submission, the single claim, the retry's idempotent replay | `tests/forms-challenge-test.php` | destructive |
| Challenge expiry and the pruned table | `tests/forms-challenge-test.php` | destructive |
| Rate limiting, the cap, and the concurrency property | `tests/forms-rate-limit-test.php` | destructive |
| Two simultaneous initial writes of a definition | `tests/forms-concurrency-test.php` | destructive |
| Two simultaneous edits of a definition | `tests/forms-concurrency-test.php` | destructive |
| Two simultaneous publications | `tests/forms-concurrency-test.php` | destructive |
| A duplicate definition row injected into `wp_postmeta` | `tests/forms-concurrency-test.php` | destructive |
| Turnstile verification and every failure branch | `tests/forms-turnstile-test.php` | unit |
| Turnstile against a real HTTP transport | `tests/forms-turnstile-live-test.php` | live |
| The recording path for a refusal that has no submission | `tests/forms-turnstile-test.php` | unit |
| Notification templates, escaping, header injection | `tests/forms-notification-test.php` | unit |
| The atomic notification claim, the retry backoff, the reconciliation | `tests/forms-notification-test.php` | unit |
| Notification delivery and the retry path | `tests/forms-notification-live-test.php` | live |
| Submission storage, trash, erasure, export | `tests/forms-retention-test.php` | destructive |
| The privacy exporter and eraser hooks | `tests/forms-privacy-test.php` | live |
| Permission enforcement on every route | `tests/forms-permissions-test.php` | unit |
| MCP access boundaries, and the personal-data capability | `tests/forms-tools-test.php` | unit |
| An entry tool is refused under the default `off` policy | `tests/forms-personal-data-test.php` | unit |
| A principal without the submissions capability is refused | `tests/forms-personal-data-test.php` | unit |
| The per-user grant and revocation, and that the role is not granted | `tests/forms-capabilities-test.php` | unit |
| The grant action is refused for a non-cookie-authenticated principal | `tests/forms-capabilities-test.php` | unit |
| The transport timeout is set, and it is shorter than the claim TTL | `tests/forms-notification-test.php` | unit |
| Form versioning | `tests/forms-definition-test.php` | unit |
| Native revision registration and the revision trigger | `tests/forms-revisions-test.php` | unit |
| The builder's models | `tests/forms-builder-test.js` | node |
| The builder's interface | `tests/forms-builder-browser-test.js` | browser |
| Accessibility of the rendered form | `tests/forms-accessibility-test.php` | unit + browser |
| Release packaging | `tests/forms-packaging-test.php` | unit |

**The concurrency suites are destructive, and they drive their two writers
from separate processes**, so the race is real rather than simulated. Each of
the four concurrency cases in §9.6 has its own named assertion, and each one
must fail if the guarantee it covers is removed — the mutation-harness
discipline the repository already uses for its other guarantees.

### 14.3 The environment

The final implementation is proven against **real WordPress and a real
browser**, through the disposable `agentshell-demo.local` environment and the
CI-provisioned disposable environment.

For the local environment, the plugin is added as a third symlink beside the
existing theme and plugin symlinks. **The demo site's own data is not used
for acceptance testing**: a test form is created, submissions are made with
obviously synthetic values, and the form and its submissions are removed at
the end. The values used are of the form `test@example.invalid` — `.invalid`
is reserved by RFC 2606 and can never be a real address — and names like
`Test User`. **No real personal submission data is used for acceptance
tests**, and none exists on the site to begin with.

### 14.4 Data hygiene in the tests

Every suite that writes uses the same discipline the existing destructive
suites use:

- the test bed is **verified** before anything is written — the profile, the
  mysqli extension, the fail-closed identity check and the HTTP identity
  handshake;
- a suite that cannot prove it is talking to a disposable site **refuses to
  run** rather than running against something real;
- every record a suite creates is removed by the suite, and a leak check
  runs afterwards, in the shape of `tests/agentshell-leak-check-child.php`.

### 14.5 CI

The `integration` job already provisions a disposable MySQL and a disposable
WordPress and runs the whole suite against them. The forms suites join it:
the `unit` suites need nothing, the `live` and `destructive` suites resolve
the CI-provisioned environment through the existing test-bed profile, and the
browser suites run against the same installation.

The `static` job's documentation gates apply to the forms documentation: the
tool count must match the registry, the pointer map must list the new design
record, and no file may restate the tool table.

The `full-test-suite` gate is the aggregate the branch ruleset requires, and
it must be green before anything is merged.

### 14.6 The MVP acceptance criteria

The MVP is done when **all** of the following are true, and each is a
demonstration rather than an assertion:

1. An administrator creates a contact form in the visual builder, with a
   name, an email field, a message field and a consent field.
2. They configure a notification to the site administrator and enable
   Turnstile.
3. They publish it, and a visitor sees the form on a page.
4. A visitor submits it, and the submission is validated **on the server** —
   a submission with the email field removed is refused, and the refusal names
   the field.
5. The submission is stored, with the form version it was validated against.
6. The administrator receives an email, and the `Reply-To` is the submitter's
   address.
7. The administrator reads the submission in WordPress, and exports it as CSV.
8. A visitor who submits twice with the same token gets one submission.
9. A bot that fills the honeypot gets a success message and creates nothing.
10. A submission with an expired Turnstile token is refused, and the refusal
    says nothing about which control fired.
11. An editor who changes the form without publishing sees no change on the
    site, and the status chip says the work is unsaved.
12. An agent lists the forms, reads one, changes it, and publishes it — and an
    agent that tries to read a submission's contents is **refused by
    default**, because the site's personal-data policy is `off` and the
    refusal is audited (§8.2a, §8.3). With the policy changed to
    `capability`, the same agent is **still refused**, because its principal
    does not hold `manage_agentshell_forms_submissions`; and `manage_options`
    alone is not accepted as a substitute. The metadata-only listing remains
    available throughout and never contains a field value.
13. The release archive contains the plugin, and the checker refuses an
    archive that does not.
14. The whole suite is green, on the disposable environment, with no leaked
    records.

---

## 15. Risks, unresolved questions and alternatives rejected

### 15.1 Risks

| Risk | Likelihood | Consequence | Mitigation |
|---|---|---|---|
| The forms plugin becomes a second AgentShell | Medium | Two ways to do the same thing; the codebase's contract erodes | The plugin depends on nothing in AgentShell; every seam is one-way; the plugin's own vocabulary is closed |
| The builder's scope grows | High | The admin page becomes a second configurator | The builder edits **one document**. It is not a site editor, not a page editor, not a design tool. Anything else is refused as out of scope |
| The post-type choice conflicts with a future WordPress direction | Low | A migration | The definition is a JSON document in meta, which is portable to a table or to a plugin's own storage without changing the schema |
| Turnstile becomes a single point of failure for a site's forms | Medium | Submissions stop during a Cloudflare outage | The policy is per form and explicit; `optional` is available; the failure is recorded so the operator can see the cause; the builder states the trade-off in the panel where the choice is made |
| The submission pipeline accumulates a monolith | Medium | It becomes hard to reason about and hard to test | The pipeline is a sequence of small, separately testable stages, each with one responsibility, and the stages are the subject of the unit suites |
| An agent with `manage_options` reads personal data it should not | Medium | A privacy incident | The binding controls are, in order: the policy switch is **`off` by default**, so the entry tools refuse whatever the principal holds (§8.2a); the underlying control is the **capability the principal may not hold** (§8.3) — `manage_agentshell_forms_submissions` is not granted to the administrator role, and `manage_options` is not accepted as a substitute; the `authorize_personal_data` acknowledgement is required in addition. Listings are metadata-only by construction, and every read and every refusal is audited. The residual risk is the **deployment**: if the operator grants the capability to their own administrator account *and* runs the agent under that same account, the agent inherits it — principal-level isolation needs separate accounts (§8.3a, Limitation 1) |
| The notification guarantee is stronger than the transport can give | Medium | A duplicate email reaches an administrator | The design says **at-least-once**, never exactly-once (§11.6); the attempt count is small with a long backoff; every message carries the submission's identifier in the body and in a `Message-ID`; and the attempt history is on the submission so a duplicate is recognisable |
| A duplicate definition row appears through a path the plugin does not control | Low | An ambiguous definition | The authoritative-row creation is serialised by a named advisory lock, and **every read counts the rows**: a count above one is refused and audited rather than silently resolved (§9.6) |
| The count test is not extended and the documented count goes stale | **High if forgotten** | The agent-facing documentation lies about the tool surface | The count test is a *build failure*, not a note; the phase that adds the tools is the phase that extends the test, and the extension is in the same PR |
| The release archive ships without the plugin | Medium | The feature is missing in production with nothing noticing | The packager and the checker share one list, and the checker requires the plugin's files — the same discipline the configurator's packaging fix used |
| The honeypot penalises assistive technology | Low | Some legitimate visitors are silently dropped | The honeypot is `aria-hidden`, `tabindex="-1"` and off-screen — never `display:none` alone — and it is verified against a real browser in the accessibility suite |
| The rate limit is wrong for a legitimate campaign | Medium | A site owner loses submissions and does not know why | The limit is per form and configurable; the refusals are counted and visible in the submissions screen; the builder warns when the limit is tight for the form's expected traffic |

### 15.2 Unresolved questions

These are genuinely open. Each is listed with the decision that has to be
made, who should make it, and when.

**Q11 to Q14 are no longer open.** The owner has decided them, and the
decisions are recorded in §17 and implemented in §8.2, §8.3, §8.3a, §11.6a
and §5.1. They are listed here only so that the numbering stays stable.

| # | Question | Why it is open | Proposed default | Decided by |
|---|---|---|---|---|
| Q1 | Should the site key and secret be site-level or form-level? | A site with several forms may want different Turnstile configurations | **Site-level** configuration, with the *policy* (`disabled`/`optional`/`required`) per form. One secret, one verification path, many policies | Before Phase 3 |
| Q2 | Should a submission be visible to an editor who can edit pages? | The capability split is proposed, but the default mapping to roles is a site decision | **Not by default.** The capability is *not* granted to the administrator role; it is granted **per user** through the procedure in §8.3a, so one person can hold it without a role change and without a second account | Before Phase 3 |
| Q3 | What is the site-wide default retention period? | It is a policy decision with a legal dimension | 90 days, with the operator able to change it | Before Phase 3 |
| Q4 | Should the notification body be plain text, HTML, or both? | HTML is friendlier; plain text is safer and always readable | **Plain text only**, and this is now settled on technical grounds rather than preference: `wp_mail()` sends one message and cannot emit `multipart/alternative`, so "both" would mean hand-rolling MIME inside the plugin (§11.5). If HTML is wanted later, the supported route is a mail plugin that adds the HTML part to `wp_mail()`, not a MIME builder here | Before Phase 3 |
| Q5 | Should the Turnstile `action` be per form or per site? | Per form is stricter; per site is simpler | Per form, and the action is verified on the response | Before Phase 3 |
| Q6 | Should the plugin be active by default when the theme is installed? | A theme that installs a plugin is unusual, and the plugin must not be auto-enabled | **No.** The theme does not install it. It is documented, and the packaging ships it ready to activate | Before Phase 6 |
| Q7 | Should the forms plugin offer an export/import of definitions? | Useful for moving a site; a security surface if it can carry submissions | Definitions only, in Phase 6 or later, with the secret never included in an export | Before Phase 6 |
| Q8 | Should the builder support a form template gallery? | A convenience that could pull in a definition from an untrusted source | No gallery. Templates are defined in the plugin's own code, where they are reviewable | Before Phase 4 |
| Q9 | Should the post type's `capability_type` be a single capability or a set? | WordPress's model is per-type capabilities | A set — `edit_agentshell_form`, `read_agentshell_form`, `delete_agentshell_form` for the definition type, and the parallel set for `agentshell_entry` — mapped to the plugin's own capabilities (§8.3) | Before Phase 1 |
| Q10 | Should the submission's source address be a salted HMAC or a plain hash? | Both are irreversible in practice; an HMAC is stronger | HMAC with a per-site salt, truncated. A plain hash of an IPv4 address is trivially reversible by enumeration | Before Phase 1 |

**Nothing in Q1–Q10 blocks Phase 1.** Each of them is a policy decision that
the code can carry a default for and that the operator can change later.
Phase 1 is ready to be built on the defaults above.

### 15.3 Alternatives rejected

| Alternative | Why it was rejected |
|---|---|
| **Forms as part of the AgentShell configuration** | The configuration is the theme's. A form must survive a theme change and must not be published by a theme-configuration commit. §3.2 |
| **Forms inside the MCP plugin** | The transport is not the product. Forms would not exist without the agent transport. §3.1 |
| **Forms inside `agentshell-blocks`** | It is a widget plugin with three tools; adding a forms engine makes both less coherent. §3.1 |
| **Forms inside the theme** | The theme is presentation, is read-only at runtime by contract, and the requirement is theme-independence. §3.1 |
| **Submissions in a dedicated table from the start** | It re-implements the capability model, the trash, the privacy exporter and the audit trail that WordPress already provides. The exit criterion is documented in §6.7. |
| **Submissions as post meta on the form** | It mixes definitions and personal data in one record, which the brief forbids and which makes deletion and export ad hoc. §3.3 |
| **A Gutenberg block for the form** | The repository has no block infrastructure and no build step; the core Shortcode block covers the insertion case. Revisit if the shortcode proves insufficient. §3.4 |
| **The builder inside the AgentShell configurator** | It couples the plugin to the theme and to a 560-pixel dock designed for tokens and zones. §3.5 |
| **A WordPress REST nonce as the anti-CSRF token for an anonymous form** | Anonymous visitors all share one nonce value, so it is not a secret and is not a defence. §7.1 |
| **A separate SMTP transport** | `wp_mail()` already respects the operator's configured transport. §11.5 |
| **Extending the configuration transaction to stage forms** | It would change the transaction's row shape, its compare-and-swap, its preview credential, its doctor validation and its reclaim rule — all load-bearing — for something the per-form draft/published model does better. §9.1 |
| **A hard-coded Turnstile credential for development** | The plugin refuses the test credentials in production, and no credential is in this repository. §11.3 |
| **A configurable outbound request URL** | A form's configuration must not be able to make the server fetch an arbitrary URL. The verification endpoint is a constant. §11.1 |

### 15.4 Existing code that requires changing before implementation

Six changes to existing files. None of them is a refactor of working
behaviour, and none of them changes a contract.

| # | File | Change | When |
|---|---|---|---|
| 1 | `tests/tool-count-test.php` | Scan the third publishing plugin and assert that no two of the three publish the same name | Phase 5 |
| 2 | `AGENTS.md` §2 | The authoritative tool count | Phase 5 |
| 3 | `agentshell-mcp/README.md` | The count it restates | Phase 5 |
| 4 | `tools/package-release.sh` | The include list gains `agentshell-forms` | Phase 6 (or earlier) |
| 5 | `tools/check-release-archive.sh` | The required list gains the plugin's files, so an archive without it is refused | Phase 6 (or earlier) |
| 6 | `AGENTS.md` §8 | The pointer map gains this design record | **This PR** |

**Beyond those six, no existing code needs to change before the forms system
can be implemented.** In particular:

- the transaction manager, the content tools, the doctor and the zone
  vocabulary are **not** refactored. The forms domain is deliberately outside
  the configuration transaction (§9.1), and the block vocabulary grows only in
  Phase 6, in the five coordinated places, as an addition rather than a
  change.
- the generic content tools are **not** changed: they are already hard-limited
  to `post` and `page`, so the submission post type is invisible to them
  without a single line of modification (F6).
- the configurator is **not** refactored. Its lessons are copied, not its
  code: the forms builder has its own modules, because a shared module
  between a theme and a plugin is a coupling the design is trying to avoid.

### 15.5 The one thing worth saying plainly

The hardest part of this design is not the pipeline, the schema or the
builder. It is the **separation of two permissions that a single
`manage_options` currently collapses**: the right to change a form, and the
right to read what people wrote into it.

The correction the review forced here is the important one: **an explicit
authorisation flag supplied by the caller is not a permission separation.**
A caller who is already permitted can simply supply it. The flag is a
deliberation and audit mechanism, and it is worth having — but the boundary
has to be enforced by something the caller cannot supply. §8.3 now proposes
three such mechanisms and recommends the one that costs nothing: **a
capability the principal may not hold**, with the deployment described
step by step, the metadata-only listing preserved, and the audit row written
on every read *and every refusal*.

That is the same lesson the content journal learned — that read authority and
destructive authority are separate, that both are re-checked at the moment
they are exercised, and that a refusal is indistinguishable from an absence
— applied to a domain where the sensitive thing is not a transaction but a
person's message. It is the part of this design that should be reviewed
hardest, and the part that should be built first.

---

## 16. Design-review resolution

This section records what a focused review changed, so a reader does not have
to diff two revisions to find out what was decided and why. The architecture
was accepted and is unchanged; these are corrections to its details.

| # | The issue raised | The revised decision | Where |
|---|---|---|---|
| **1** | `agentshell_submission` is 21 characters and exceeds WordPress's 20-character post type key limit | The submission post type is **`agentshell_entry`** (16 characters). `register_post_type()` returns `WP_Error( 'post_type_length_invalid' )` above 20, and `wp_posts.post_type` is `varchar(20)`, so the original name would also have been truncated in the database. The form type is `agentshell_form` (15). The capability type follows the same name | §3.3, §6.2, F13 |
| **2** | The examples used `f_` + 32 hex, the validator required 32 hex, the schema column was `varchar(32)` and the shortcode used `contact` | **One immutable internal identifier**: exactly 32 lowercase hexadecimal characters, no prefix, stored in a `char(32)` column. **One optional human-readable slug**, `^[a-z0-9][a-z0-9-]{0,63}$`, frozen after the form's first publication, and refused by the validator if it looks like an internal identifier so the two namespaces cannot collide. The shortcode takes exactly one of `id` or `slug`; the API and the MCP tools use the identifier; the schema column matches | §5.1, §5.5, §6.2, §7.1, §7.3 |
| **3** | WordPress does not automatically version every custom post-meta field | **§5.8 now documents the whole arrangement**: `register_post_meta()` with `revisions_enabled => true` for the three definition keys (the default is `false`), the post type declaring `revisions` support, and the explicit `wp_save_post_revision()` call — with the `wp_save_post_revision_post_has_changed` filter — because `_wp_post_revision_fields()` is only `post_title`/`post_content`/`post_excerpt` and a meta-only change triggers nothing. Native revisions are named as a **secondary, browsable** record, and the five artefacts (version, working, published, previous publication, working-revision counter) are told apart in one table | §5.6, §5.8, F5 |
| **4** | The compare-and-swap relied on an unstated uniqueness guarantee for `wp_postmeta` | `wp_postmeta` has **no** unique key on `(post_id, meta_key)`; `add_post_meta()` is a `SELECT COUNT` then an `INSERT`, and `update_post_meta()` updates *every* matching row. The design now rests on **`meta_id`**, which is a primary key: a named advisory lock for authoritative-row creation, a row-count check that **refuses** a duplicate rather than picking one, `meta_id`-scoped value-guarded updates, and a publication transaction covering the three rows together. Four named concurrency tests are specified | §9.6, F14 |
| **5** | An unbound public challenge does not provide complete CSRF protection | §7.1 now separates **replay prevention** (yes), **duplicate suppression** (yes), **cross-origin browser abuse** (partly — the same-origin policy, not a secret) and **server-side automated abuse** (no). The challenge is a protocol-correctness control, not a spam control. The rate limiter gained an exact algorithm: a fixed window per (scope, subject, form), a reserve statement with the limit baked into the `IF`, and the proof that **admissions never exceed the limit** while under-admission is possible. A separate table replaces the mixed one; the **retry replay** is specified (a consumed challenge whose submission exists replays the success response); challenge expiry, denial-of-service and the genuine-retry policy are covered | §6.3, §6.3a, §6.3b, §6.3c, §7.1, F16 |
| **6** | An agent with `manage_options` can supply `authorize_personal_data: true` itself | §8.3 now says plainly that the flag is a **deliberation and audit mechanism, not a permission boundary**, and names the capability the principal may not hold as the MVP's mechanism; the separate credential and the human approval are deferred extension points (§8.3b). A site policy switch (`off` / `capability` / `credential`) makes the posture explicit, with **`off` as the default** and the four entry tools refusing under it (§8.2a). §8.3a supplies the per-user grant and the setup procedure, and states both limitations the mechanism does not reach. Metadata-only listings and audit rows survive every mode. §8.2 is explicitly marked **not committed**, with provisional names and an unchanged tool count | §8.2, §8.2a, §8.3, §8.3a, §10.2 |
| **7** | Reusing a submission row does not guarantee exactly-once email delivery | §11.6 now specifies the **notification record** (`pending` / `claimed` / `accepted` / `failed` / `uncertain` / `exhausted`), an **atomic claim** by a guarded update so two workers cannot send concurrently, **five attempts** with a stated backoff, and a **reconciliation** that reclaims a claim older than the TTL. The guarantee is named honestly: **at-least-once, not exactly-once**, with the mitigations listed. `wp_mail()` returning `true` is now defined as **accepted by the sending mechanism** — never *delivered* — and the vocabulary follows. **Plain text is the MVP default and the only MVP format**, on the technical ground that `wp_mail()` cannot emit `multipart/alternative` | §11.5, §11.6, F15 |
| **8a** | Turnstile failures were said to be recorded on submissions that are intentionally refused before storage | The two paths are now separated: a `required` form's refusal produces **no submission row** and is recorded in a new bounded **rejection log** (no personal data, with the volume recorded exactly by the counters); an `optional` form's failure **is** stored and carries `unverified` or `spam` on the submission | §11.2, §11.2a, §10.2 |
| **8b** | A form definition alone was called a complete UK GDPR Article 30 record | The definition is now described as **useful processing metadata** and as **input to** a record of processing, not one. The section names what a record of processing covers that the definition does not, and states plainly that compliance is the controller's judgement and is unaffected by the plugin | §6.4, §10.3 |
| **8c** | No phase may depend on a later phase | The dependency table is corrected and the reasons given: the challenge store and its minting move **into Phase 1** because Phase 2's renderer mints the token; Phase 3 does not need the renderer; Phase 4 needs Phases 1 and 2; Phase 5 splits into the form tools (Phase 1) and the entry tools (Phase 3). The graph is redrawn | §13.1, §13.2, §13.3, §13.4 |

**What did not change.** The plugin boundary, the storage model, the six
phases, the schema, the pipeline's shape, the threat model's coverage, and
the verification strategy are all as accepted. The corrections are to
identifiers, to the mechanisms inside the storage and notification paths, to
the permission model's enforcement, and to the documentation's accuracy about
what it can claim.

---

## 17. The owner's final decisions

The design was accepted subject to four policy decisions. They are recorded
here in the owner's own terms, with the place in this document where each one
lives, because a design record that records a decision must also record
where it was implemented.

### Q11 — The personal-data boundary

**Decided.** WordPress custom capabilities are the primary authorisation
mechanism. No second credential system and no human-approval workflow is
built for the MVP. MCP submission-content access is **disabled by default**,
and no agent principal receives automatic access to personal submission
data. The architecture stays extensible for separately authorised agent
principals later. `authorize_personal_data: true` remains an **audit
acknowledgement**, not a permission grant.

**Where it lives.** §8.3 now names the capability model as the MVP's
mechanism and marks the separate credential and the human approval as
deferred extension points (§8.3b), not as alternatives the operator chooses
between. The policy switch's default is `off` (§8.3), the four entry tools
refuse under it (§8.2a), and the `authorize_personal_data` flag is described
as an acknowledgement throughout (§8.2a, §8.3). The separately authorised
principal is §8.3b's first paragraph.

**What it changed from the reviewed draft.** The draft recommended (A) with
(B) "available as a second trust domain", and made `capability` the policy
default. Both are reversed: the default is now `off`, and (B) is deferred
rather than offered.

### Q12 — Administrator access

**Decided.** `manage_agentshell_forms_submissions` is **not** automatically
granted to the administrator role. Explicit **per-user** assignment is
supported, so the site owner can reach the submissions interface without a
second WordPress account. A simple, permission-checked setup procedure is
provided. An agent and a human sharing a WordPress principal share that
principal's capabilities, and separate accounts are necessary for
principal-level isolation. The MCP policy switch is **not** claimed to
protect data accessed through unrelated authenticated WordPress interfaces.

**Where it lives.** §8.3a is the procedure: the per-user grant through
`WP_User::add_cap()`, the "Grant me access to form submissions" action, the
access list with revocation, and the three conditions that make the grant
action unreachable by the agent — the capability check, the cookie-session
requirement, and the nonce. **Limitation 1** (§8.3a) states the shared-
principal consequence and the need for separate accounts.
**Limitation 2** (§8.3a) states exactly what the policy switch does not
govern.

**What it changed from the reviewed draft.** The draft proposed creating a
dedicated "Forms data steward" **role** and granting the capability to it.
Per-user assignment replaces that: it satisfies both halves of the decision —
the role is untouched, and the site owner needs no second account — without
asking the operator to design a role.

### Q13 — Notification claims

**Decided.** The **15-minute claim TTL** is accepted for the MVP. Transport
timeouts are bounded and **shorter than the claim lifetime**. The atomic
claim, the five-attempt limit, the backoff and the uncertain-outcome
reconciliation are preserved.

**Where it lives.** §11.6a is the new section: the claim TTL at 900 seconds,
the attempt budget at 60 seconds by default, the invariant that the
transport timeout is **strictly** shorter than the claim TTL (and the plugin
refuses a configuration that breaks it, by name), the `phpmailer_init` hook
as the supported way to set the transport's `Timeout`, and the elapsed-time
classification that records an over-budget send as `uncertain` rather than
`accepted`. The atomic claim, the five attempts, the backoff and the
reconciler are unchanged in §11.6.

**What it changed from the reviewed draft.** The draft named a fifteen-minute
TTL and left the transport timeout to the transport. The timeout is now the
plugin's own bounded value, set explicitly rather than inherited from a
default, and the relationship between the two clocks is an enforced
invariant rather than an aspiration.

### Q14 — Slugs

**Decided.** Slugs are frozen after the **first** publication. Immutable
internal form identifiers are used for long-lived references.

**Where it lives.** §5.1 states the freeze and the fact that it is triggered
by the *first* publication and is permanent; the validator rule is
`form_slug_frozen` (§5.5); and §5.1 names the internal identifier as the
long-lived reference — the API path segment, the MCP tools, a submission's
`form_id`, a notification's `Message-ID`, and any external system that stores
a reference.

### The next step: Phase 1

**Decided.** Once the design is approved and integrated, Phase 1 begins as a
**separately reviewable implementation PR from the new `main`**. It
establishes the plugin foundation, the authoritative schema, the post types,
the storage invariants, the validation and the concurrency controls. **No
visual builder, no submission notifications, no MCP tool additions.**

**Where it lives.** §13.1 states the phase scope and the three exclusions;
§13.2's Phase 1 entry is the detailed contract, with its own acceptance
criteria — including that **no tool is registered and the documented tool
count is unchanged**. §15.4's six existing-code changes are unaffected: all
six are Phase 5 or Phase 6 work, except the `AGENTS.md` §8 pointer row,
which this PR carries.

**Nothing in this document is implementation.** This PR remains
documentation-only. No table, no endpoint, no capability and no tool exists
because this document says so.

---

*This document is a design record. It records an investigation and a
recommendation. It does not make anything normative, and it does not claim
that any of it exists. The normative contract for AgentShell is `AGENTS.md`,
and the tool surface it names is in `AGENTS.md` §2.*
