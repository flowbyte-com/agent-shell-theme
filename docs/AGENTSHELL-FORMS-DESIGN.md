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
lives in **post meta**, which gives native WordPress revisions, native
capabilities, native trash and a native REST surface for free. Submissions are
stored as a **second, separate custom post type** (`agentshell_submission`),
which keeps personal data out of the definition store and out of AgentShell's
existing content tools. The plugin owns **one** small table for ephemeral
protocol state (single-use submission challenges and rate-limit counters),
because that state must be consumed atomically and neither the options table
nor transients can do that.

### 1.2 The MVP in one paragraph

An administrator configures a contact form in a visual builder at
**Appearance → AgentShell Forms**, inserts it into a page with the shortcode
`[agentshell_form id="contact"]`, optionally protects it with Cloudflare
Turnstile, and receives submissions by email. Submissions land in
**Submissions**, where an authorised administrator can read them, export them
as CSV, and delete them. The definition has a **draft** and a **published**
state; nothing an editor or an agent changes reaches a visitor until it is
published. Every submission is validated on the server against the *published*
definition, stored with the version it was validated against, and delivered
once — a failed email does not produce a second submission.

### 1.3 The shape of the work

Six independently reviewable PRs. Phase 1 is the only hard blocker; after it,
four phases can proceed largely in parallel.

| Phase | Deliverable | Depends on |
|---|---|---|
| 1 | The form model, the schema, the validator, the store | — |
| 2 | Rendering, the shortcode, the widget, accessible markup | 1 |
| 3 | The submission pipeline, notifications, Turnstile, the submissions screen | 1, 2 |
| 4 | The visual builder | 1 |
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
   people wrote into it.

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
| F5 | Core copies post meta into revisions (`_wp_copy_post_meta` in `wp_save_post_revision`) | A definition in post meta gets native revision support for free — a decisive argument for the post-type choice |
| F6 | The generic content tools are hard-limited to `post` and `page` (`Content_Primitives::TYPES`, and the `type` enums in the tool schemas) | A submission post type is invisible to `agentshell_search_content`, `agentshell_get_content` and `agentshell_trash_content` — no leakage, and no change to those tools |
| F7 | The registry keys tools by name and keeps the *first* registration, so a third plugin registering at a later priority can never shadow an existing tool | The forms plugin registers its tools at priority 15 and cannot collide |
| F8 | `tests/tool-count-test.php` scans exactly two plugin files, and `tests/doc-consistency-test.php` gates the documented count in two places | Adding a third publishing plugin **requires** extending the count test and updating the documented count, or the count becomes wrong |
| F9 | `tools/package-release.sh` includes an explicit list of plugins, and the checker re-checks it | A new plugin must be added to both, or the archive ships without it and nothing notices |
| F10 | The doctor is the single source of truth for what a valid block is, and configuration writes are staged by intercepting one option name | The forms domain does not need to join the configuration transaction, and should not |
| F11 | WordPress's cookie authentication already refuses a REST request without a valid nonce | The admin REST routes need a capability check, not a hand-rolled nonce check |
| F12 | The repository has no build step: `configurator/*.js` are plain files that also export via `module.exports` for Node tests | The forms builder must be plain JavaScript with no bundler |

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
| **B. A custom post type with the definition in post meta** | `agentshell_form` posts; `_agentshell_form_definition` meta | Native revisions (F5); native capabilities; native trash; native search; multiple forms for free; a stable identity that survives import/export; the definition is a document, which is what a post is | A new architectural element (F4); post meta is not unique, so concurrency needs a value-guarded update; revisions are per-post, not a shared ring |
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
| **A. A custom post type** | `agentshell_submission` posts, values in meta | Capabilities, trash, search, `wp_delete_post`, the WordPress personal-data exporter/eraser hooks, and invisibility to the generic content tools (F6); a durable record that a notification retry can reference | `wp_posts` is a wide table; each submission is a post row plus meta rows; no native indexing of field values |
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

The cost is real: `wp_posts` is not a submission table. The documented exit
criterion is in §6.7 — a form or site that sustains submission volumes where
per-post overhead dominates should move to a dedicated table, behind the same
service interface, without changing the pipeline.

### 3.4 How a form is inserted into a page

| Option | What it means | For | Against |
|---|---|---|---|
| **A. Shortcode** | `[agentshell_form id="contact"]` | Theme-independent; works with the core Shortcode block; works with any editor; the existing `[agent_block]` precedent; no build step | Not drag-and-drop; not visually represented in the editor canvas |
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
| Where do submissions live? | `agentshell_submission` post type, values in meta | F6, plus the privacy hooks |
| Where does ephemeral protocol state live? | One plugin-owned table | Atomicity requirements |
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
    class-form-post-types.php   registers `agentshell_form` and `agentshell_submission`
    class-form-schema.php       the authoritative schema, the validator, the error codes
    class-form-field-types.php  the field-type registry
    class-form-definition.php   read/write of working and published definitions; the CAS
    class-form-renderer.php     server-side rendering of a form
    class-form-shortcode.php    `[agentshell_form id="…"]`
    class-form-widget.php       the `WP_Widget` subclass
    class-form-challenges.php   the single-use challenge and rate-limit store
    class-submission-handler.php the one authoritative submission pipeline
    class-submission-store.php   persistence, retention, export, erasure
    class-form-notifier.php      `wp_mail()` notifications and their retry
    class-turnstile.php          the verification client
    class-form-privacy.php       the WordPress personal-data exporter and eraser
    class-form-admin.php         the admin screens (list, builder, submissions)
    class-form-rest.php          the admin REST controller
    class-form-tools.php         the MCP tool classes
  admin/
    js/forms-model.js            pure models (Node-testable)
    js/forms-api.js              the transport
    js/forms-ui.js               the view
    js/forms-app.js              the application layer
    css/forms-admin.css
```

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

```json
{
  "schema_version": 1,
  "form_id": "f_7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a",
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
builder offers "duplicate" and "remove" instead.

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
| `form_id` matches `^[a-f0-9]{32}$` | `form_id_invalid` |
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

### 5.6 Form versioning

`version` is an integer, incremented when a definition is published. It is
meaningful in exactly two places:

- a submission records the version it was validated against, so a later change
  to the form does not change what a past submission means;
- a notification records the version it described.

The version is **not** a content hash and is not used to detect concurrent
edits; that is the working definition's revision counter (§9.6).

### 5.7 Draft and published definitions

A form carries two definitions:

- the **working** definition — what the builder and the agents are editing;
- the **published** definition — what visitors get and what submissions are
  validated against.

A form with no published definition is **unpublished**: it renders nothing to
visitors, and the builder says so. §9 describes the publish protocol.

---

## 6. The submission storage model

### 6.1 The organising principle

**Durable, user-visible records live in WordPress's own tables. Ephemeral
protocol state lives in a table the plugin owns.**

The distinction is not tidiness; it is about which guarantees each store can
give. The options table and transients give no atomicity for a
consume-once operation and no atomicity for a counter increment. A table with
a unique key and a deleting `DELETE` gives both. So the plugin owns one small
table, and nothing else.

### 6.2 The submission record

`agentshell_submission` is a post type registered with:

- `public => false`, `exclude_from_search => true`, `show_ui => false` — the
  plugin provides its own screens;
- `capability_type => 'agentshell_submission'`, with the capabilities mapped
  to `read`, `edit_post`, `delete_post` so WordPress's per-post model applies
  to a submission exactly as it applies to a page;
- `supports => array( 'title' )` only. A submission is immutable: no editor,
  no revisions, no comments.

The record's content is in post meta:

| Meta key | Holds |
|---|---|
| `_agentshell_form_id` | the form's stable id (not the post id) |
| `_agentshell_form_version` | the published version the submission was validated against |
| `_agentshell_form_data` | the submitted values, JSON, keyed by field key |
| `_agentshell_form_meta` | received-at, source, the hash of the source address, the user agent (only if enabled) |
| `_agentshell_form_spam` | `clean`, `spam`, `unverified` |
| `_agentshell_form_notification` | `{status, attempts, last_error, sent_at}` |
| `_agentshell_form_hash` | the SHA-256 of the canonical submitted payload — the tamper-evidence record and the duplicate key |

The post **title** is generated by the server (`Contact us — 2026-10-27
14:03`), never taken from the submission, so no personal data lands in a
field the admin list table sorts on or a search index.

### 6.3 The plugin's own table

One table, `agentshell_form_challenges`, holding two kinds of short-lived
row:

```
id            bigint unsigned AUTO_INCREMENT PRIMARY KEY
token_hash    char(64) NOT NULL
kind          varchar(16) NOT NULL      -- 'challenge' | 'rate'
subject_hash  char(64) NOT NULL         -- the hashed source address, for 'rate'
form_id       varchar(32) NOT NULL
form_version  int unsigned NOT NULL
created_at    datetime NOT NULL
expires_at    datetime NOT NULL
UNIQUE KEY token_hash (token_hash)
KEY kind_subject (kind, subject_hash, created_at)
KEY expires_at (expires_at)
```

**Why a table and not a transient.** The challenge must be consumed exactly
once. Consuming a transient is a read followed by a delete, and two concurrent
requests can both read it. A `DELETE … WHERE token_hash = ?` is atomic: the
affected-row count says who won. The same argument applies to the rate-limit
counter, which is an increment.

**Why not the options table.** The options table's `add_option()` is atomic
against the unique key on `option_name`, which is the trick
`Transaction_Manager` uses to open a transaction — but a challenge row per
rendered form would grow `wp_options` without bound and would need its
autoload behaviour managed per row. A table with an expiry index and a cron
prune is the right shape for data that is born expiring.

**Retention.** Rows are deleted by a cron job that runs hourly and removes
anything past `expires_at`. Correctness never depends on the cron firing:
every read re-checks the expiry itself, exactly as the screenshot store does.

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
- The **definition is the record of processing.** What a form collects, from
  whom, on what basis and for how long is written down in the form itself —
  which is a genuinely useful property: the form *is* the Article 30 record,
  not a separate document that can drift from it.

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
  "form_id": "f_7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a",
  "challenge": "3f9a…",
  "fields": { "name": "Ada Lovelace", "message": "…", "consent": true },
  "turnstile_token": "0x4AAAA…"
}
```

**The challenge token.** When a form is rendered, the plugin creates a
random 32-byte token, stores its SHA-256 in the `challenge` table with the
form id, the published version and an expiry (two hours by default), and
renders the token into a hidden input. On submit, the row is **deleted**
atomically; the affected-row count decides whether this is the first use. A
second use of the same token finds no row and is refused.

This is the duplicate-submission defence and it is deliberately *not* a
WordPress nonce. **A WordPress REST nonce is not a CSRF defence for an
anonymous form**: `wp_create_nonce()` for a logged-out visitor with no cookie
derives from user id 0 and an empty session token, so every anonymous visitor
on the site receives the *same* nonce value. It is not a secret, and it
provides no protection against a cross-site post. The single-use challenge is
what actually prevents a blind cross-site post and a double-click, because
the token can only be read from the form's own HTML by a same-origin client.

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
already-used challenge, a rate limit, a failed Turnstile verification — are
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
| `GET /forms` | List forms: id, name, status, version, field count, submission count. No field values |
| `GET /forms/<id>` | One form: working definition, published definition, validation result, metadata |
| `POST /forms` | Create a form (a draft) |
| `PUT /forms/<id>` | Replace the working definition, with an expected revision for the compare-and-swap |
| `POST /forms/<id>/validate` | Validate a definition without writing it |
| `POST /forms/<id>/preview` | Render the working definition to HTML, for the builder's preview |
| `POST /forms/<id>/publish` | Promote the working definition to published (§9.3) |
| `POST /forms/<id>/discard` | Discard the working definition, reverting to published |
| `DELETE /forms/<id>` | Trash a form. `confirm: true` required |
| `GET /forms/<id>/submissions` | List submissions — **metadata only** (§8.4) |
| `GET /submissions/<id>` | Read one submission's contents. Requires `authorize_personal_data: true` |
| `DELETE /submissions/<id>` | Trash a submission. `confirm: true` |
| `POST /submissions/<id>/restore` | Restore a trashed submission |
| `DELETE /submissions/<id>?permanent=1` | Hard delete (erasure). `confirm: true` |
| `GET /submissions/export?form=<id>&format=csv` | Export. Requires `authorize_personal_data: true` |
| `GET /field-types` | The field-type registry, for the builder |

**Cookie authentication and nonces.** WordPress's own REST cookie
authentication already refuses a request from a logged-in browser that lacks a
valid nonce, so the permission callback needs only the capability check. The
builder's transport attaches `X-WP-Nonce` on every request, exactly as
`configurator-api.js` does.

### 7.3 The shortcode and the widget

```php
add_shortcode( 'agentshell_form', array( 'AgentShell_Forms\\Form_Shortcode', 'render' ) );
```

Attributes: `id` (required — the stable form id), `title` (optional — show or
hide the form's name), `class` (optional — extra classes on the wrapper).
Unknown or unpublished forms render **nothing** to a visitor and a small
diagnostic to an editor who can manage forms.

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

The agent surface is deliberately small, and the table names the **capability
first** — the authoritative list of the existing tool surface is in
`AGENTS.md` §2.

| Capability | Proposed name | Effect | Authority |
|---|---|---|---|
| List the forms | `agentshell_list_forms` | Every form: id, name, status, version, field count, submission count. No field values | Transport |
| Read one form | `agentshell_get_form` | The working and published definitions, the validation result, the metadata | Transport |
| Create a form | `agentshell_create_form` | A new draft form with a name and, optionally, a first field | Transport |
| Change a form | `agentshell_update_form` | Replace or patch the working definition. A compare-and-swap against the expected revision | Transport |
| Check a form | `agentshell_validate_form` | Validate a definition and return the errors and warnings, without writing | Transport |
| Publish a form | `agentshell_publish_form` | Promote the working definition to published, increment the version | Transport |
| Discard a form's changes | `agentshell_discard_form_changes` | Revert the working definition to the published one | Transport |
| List the submissions | `agentshell_list_submissions` | **Metadata only**: id, form, received-at, spam status, notification status. No field values | Transport + personal-data authorisation |
| Read one submission | `agentshell_get_submission` | The submitted values | Transport + personal-data authorisation |
| Export the submissions | `agentshell_export_submissions` | CSV or JSON for one form | Transport + personal-data authorisation |
| Remove a submission | `agentshell_trash_submission` | Move a submission to the trash | Transport + per-post `delete_post` |
| Erase a submission | `agentshell_delete_submission` | Hard delete — the erasure path | Transport + per-post `delete_post` + `confirm` |

Twelve verbs. Each is a distinct act with a distinct authority, and none of
them can be merged into another without either losing an authority check or
making a destructive act look like a routine one.

Two tools are proposed for a later phase rather than the MVP:
`agentshell_delete_form` (trashing a form must also decide what happens to
its submissions, which is a decision worth making deliberately) and
`agentshell_restore_submission`.

### 8.3 The two permission domains

**Editing a form** and **reading what people wrote into it** are different
acts with different consequences. The first is a configuration act; the
second is an access to personal data.

The plugin therefore defines two capabilities:

- `manage_agentshell_forms` — create, edit, publish, discard, delete forms;
- `manage_agentshell_forms_submissions` — read, export and delete
  submissions.

Both are granted to administrators on activation and can be delegated to a
role such as "site editor" without granting anything else.

**An honest statement about what that means today.** The MCP transport
requires `manage_options` for every call, so **every MCP agent already holds
both capabilities**, and the capability split does not by itself separate the
two domains for agents. The separation that actually binds is the
**explicit personal-data authorisation**, which is the mechanism the content
journal already uses:

- a tool that reads submission *contents* requires the caller to pass
  `authorize_personal_data: true` **in the same call**;
- the tool then re-checks the capability at that moment;
- a refusal is answered with a full-shaped result that says the authorisation
  was absent, and it writes an audit row naming the actor and the role they
  acted under — **including refusals**, so a refusal is distinguishable from
  a call that never happened;
- the metadata-only listing never returns a field value, so an agent can
  audit a form's traffic without reading anybody's message.

This is the same two-part shape as `authorize_inspection` /
`authorize_recovery` in the content journal: **read authority and
destructive authority are separate, and both are re-checked at the moment
they are exercised.**

The capability split is still worth having: it is what makes the *admin UI*
and the *MCP surface* separable, and it is what would matter if a
lower-privileged MCP transport were ever introduced.

### 8.4 Read-only defaults for submissions

`agentshell_list_submissions` returns **metadata only** by default:

```json
{ "submissions": [
    { "id": 812, "form_id": "f_7c1d…", "form_version": 3, "received_at": "2026-10-27T14:03:11Z",
      "spam_status": "clean", "notification_status": "sent", "field_count": 3 }
  ], "total": 1 }
```

Field values appear only in `agentshell_get_submission`, and only with the
explicit authorisation. This is the same discipline the codebase already uses
for the custom stylesheet: a routine read does not carry the body, because a
body in a transcript is a body in a log.

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
| `form_submission_inspected` | A submission's contents are read; the payload names the form and the authorisation used |
| `form_submission_trashed` / `form_submission_deleted` | A submission is trashed or erased |
| `form_submission_exported` | A submission set is exported; the payload names the form and the row count |

An audit row never carries a submitted value. It carries identifiers and
counts.

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
publish can be undone by a human, and the plugin's own revision history —
native WordPress revisions, because the definition is post meta (F5) — keeps
the full lineage.

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

### 9.6 Concurrent editing

Two editors, or an editor and an agent, can have the same form open. The
plugin detects this rather than resolving it by last-write-wins.

Every write to the working definition carries an **expected revision**. The
revision is an integer incremented on every write, stored alongside the
definition. The write is a value-guarded update:

```sql
UPDATE wp_postmeta
   SET meta_value = %s
 WHERE post_id = %d
   AND meta_key = '_agentshell_form_working'
   AND meta_value = %s
```

where the last parameter is the exact previous value. Zero affected rows means
somebody else wrote first. The write is then **refused**, and the result
names the other editor, the time of their change, and the fact that the
caller's draft was not saved. The caller can reload and re-apply.

This is the same compare-and-swap discipline the rest of the system uses:
**a lost race is refused, never overwritten.** It is the reason the
configuration's revision ring and the transaction row both use value-guarded
writes, and it is applied here to post meta, which is not unique-indexed and
therefore needs the value guard to be safe.

The revision counter is also what the publish path checks (§9.3 step 3), so
"publish what I previewed" is guaranteed.

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
| **Duplicate submission** | A double-click, a retried request, a replayed POST | The single-use challenge token, consumed atomically | `Form_Challenges::consume()` |
| **Cross-site request forgery on an anonymous form** | A page on another origin posts to the endpoint | The single-use challenge. A WordPress nonce is **not** a defence here — anonymous visitors all share one | `Submission_Handler` |
| **CSRF on the admin routes** | A page on another origin calls the admin REST route | WordPress's cookie authentication requires a valid nonce on a cookie-authenticated request; the capability check is a second gate | `Form_REST` |
| **CSRF on the MCP surface** | A browser tricked into calling the agent transport | The transport is authenticated by an application password or a static token, not by a cookie, so a browser cannot forge it | The existing transport |
| **Cross-site scripting through a field label** | A malicious definition puts `<script>` in a label | Every operator-authored string is escaped on output. There is **no** raw-HTML escape hatch anywhere in the renderer | `Form_Renderer` |
| **Cross-site scripting through a submitted value** | A value is echoed back | Submitted values are never echoed into the page. The success message is operator text; errors are operator templates | `Form_Renderer` |
| **SQL injection** | A field key or a value reaches a query | Every query uses `$wpdb->prepare()`; the definition is stored through `update_post_meta()`, which core parameterises; field keys are constrained to a safe character class anyway | Everywhere |
| **Email header injection** | A submitted value is interpolated into a subject or a recipient | Subjects have CR, LF and other control characters stripped and are length-limited; recipients come only from the definition and are validated with `is_email()`; `From:` is never set to a submitter's address — `Reply-To:` is used instead, and only from an `email` field | `Form_Notifier` |
| **Arbitrary recipient routing** | A definition names an attacker as a recipient | Recipients are drawn from an allowlist: the site administrator's address, plus addresses on the site's own domain. A recipient outside the allowlist is refused by name | `Form_Notifier` |
| **Arbitrary outbound requests** | A definition names a URL to fetch | The plugin makes exactly one outbound request, to a **hard-coded** verification endpoint. There is no configuration path to a URL | `Turnstile` |
| **Secret key disclosure** | The secret appears in a tool result, a log, a page, an export | The secret is read from a constant or a protected option and is never returned by any tool, never rendered, never exported. The transport's argument redaction covers it too | Everywhere |
| **Unauthorised submission access** | An agent, an editor or a subscriber reads submissions | The post type is non-public and excluded from search; the generic content tools are hard-limited to `post` and `page` (F6), so submissions are outside their reach; the plugin's own routes check `manage_agentshell_forms_submissions` | `Form_REST`, the tools |
| **Cross-form data leakage** | A request posts field values belonging to another form | Only the fields declared in *that* form's published definition are read, and only those are stored. A value for an undeclared key is discarded | `Submission_Handler` |
| **Stored personal information** | Submissions accumulate indefinitely | Data minimisation (§6.4); per-form retention; a daily purge; the source address is an HMAC, not an address | `Submission_Store` |
| **Agent-initiated destruction** | An agent deletes a form and its submissions | `confirm: true`; a separate, separately-named `delete_submissions` flag; the per-post `delete_post` capability re-checked at the moment of destruction; an audit row for the refusal as well as the act | The tools |
| **Concurrent form edits** | Two editors overwrite each other | A value-guarded update on the working definition; the loser is refused and told who won | `Form_Definition` |
| **Accidental publication** | A draft reaches visitors | Nothing renders from the working definition; publishing is a separate, explicit act that re-validates and re-checks the revision | `Form_Definition::publish()` |
| **Malicious field definitions** | A definition carries unknown keys, huge strings, or HTML | The validator rejects unknown keys by name, bounds every length, and validates every setting against its type's declared schema. A field type registered by a third party is **trusted code** — the registry is a trust boundary, and that is stated | `Form_Schema` |
| **Stored XSS via an option value** | An option value is HTML | Option values are slugs; option labels are escaped | `Form_Renderer` |
| **Notification failure causing a duplicate** | A failed email is retried by re-running the submission | The notification retry re-uses the **same submission row**. The pipeline never creates a second submission for a notification failure | `Form_Notifier`, `Submission_Store` |
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
the explicit personal-data authorisation.

**Erasure.** The hard-delete tool, plus the WordPress eraser hook, plus the
retention purge. The per-post capability is re-checked at the moment of
deletion.

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

**Records of processing.** The definition *is* the record: what is collected,
on what basis, for how long, and where the privacy notice is. That is a
genuinely useful property, and it is why the definition is a first-class
document rather than a set of loose fields.

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

**Every refusal and every failure is recorded** on the submission — the
policy in force, the Cloudflare error codes, whether the failure was a
network failure — so the operator can distinguish "people are being blocked
because Cloudflare is down" from "people are being blocked because the
secret is wrong".

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
2. **The rate limit.** Per source address and per form: a maximum number of
   submissions in a rolling window. Enforced with an atomic counter (§6.3),
   so it holds under concurrency. The limit is per form, so a form used for
   a legitimate campaign does not consume another form's budget.
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
configure one, and does not check whether one exists. If `wp_mail()` returns
`false`, the delivery failed, and that is the whole story.

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
| `{form:id}` | The form's stable id |
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
3. consume the challenge token          ← atomic, single-use
4. verify Turnstile, if the form requires it
5. check the honeypot                  ← a filled honeypot returns a fake success here
6. check the rate limit
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

**Retries re-use the row.** A cron job picks up submissions whose
notification status is `pending` or `failed` and whose attempt count is below
the limit, and re-sends **using the same submission record**, incrementing
the attempt count. The retry path does not go through the submission writer
at all, so it structurally cannot duplicate.

**A failed notification does not fail the submission.** The visitor gets a
success response. The operator sees a failed notification on the submission
and can re-send it from the administrative screen.

**A delivery that hangs** is bounded by the transport's own timeout, and the
attempt is recorded as failed with the timeout as the reason.

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

Three screens:

| Screen | Purpose |
|---|---|
| The list | Every form: name, status, version, field count, submission count, last change. Actions: edit, duplicate, publish, discard, trash |
| The builder | The field list and the field inspector, plus the settings tabs (notification, spam, storage, privacy) and the preview |
| The submissions | One form's submissions: metadata by default, the values on demand, export, trash, erase |

### 12.2 The module split

The split mirrors the configurator, and it exists for the same reasons: the
models are testable without a browser, and only one module knows both the
models and the DOM.

| Module | Responsibility |
|---|---|
| `admin/js/forms-model.js` | Pure models: the form model, the field model, the status model, the settings model. No DOM, no network. Exports via `module.exports` so a Node suite can exercise it |
| `admin/js/forms-api.js` | The transport. Attaches `X-WP-Nonce` and `X-WP-Nonce` on every request; knows the REST shapes |
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
| 1 | The form model, the schema and the store | Large | — |
| 2 | Rendering and insertion | Medium | 1 |
| 3 | The submission pipeline, notifications and Turnstile | Large | 1, 2 |
| 4 | The visual builder | Large | 1 |
| 5 | The agent tool surface | Medium | 1 |
| 6 | The AgentShell integration and release packaging | Small | 2 |

Six PRs. Phase 1 is the only hard blocker.

### 13.2 The phases in detail

#### Phase 1 — The form model, the schema and the store

**Scope.** The plugin skeleton; the two post types; the authoritative schema
and its validator; the field-type registry with the ten MVP types; the
definition store with the working/published split and the compare-and-swap;
the challenge table and its pruning.

**Reused.** Nothing — this is the bottom of the stack. It reuses *WordPress*:
the post type, post meta, the revision machinery, `dbDelta`, the cron API.

**New modules.** `class-form-post-types.php`, `class-form-schema.php`,
`class-form-field-types.php`, `class-form-definition.php`,
`class-form-challenges.php`.

**Data model.** The `agentshell_form` post type; the `agentshell_form_working`,
`_agentshell_form_published`, `_agentshell_form_published_previous`,
`_agentshell_form_revision`, `_agentshell_form_id`, `_agentshell_form_version`
and `_agentshell_form_meta` meta keys; the `agentshell_form_challenges` table.

**MCP.** None yet.

**WordPress integration.** The post type registration, the activation hook
that creates the table and grants the capabilities, the uninstall handler
that removes the table and the options.

**Security.** The validator is the boundary; unknown keys are refused by
name; every length is bounded; the field-type registry is documented as a
trust boundary.

**Migration.** None — this is the first version. The schema carries a
`schema_version` so that a later version can be recognised and migrated.

**Tests.** `tests/forms-schema-test.php` (the validator, each error code,
the field-type registry, the settings schema walk); `tests/forms-definition-test.php`
(create, read, write, publish, discard, the compare-and-swap refusal, the
version increment).

**Acceptance.** A form can be created, edited, published and discarded
programmatically. An invalid definition is refused with a named error. A
second writer with a stale revision is refused, and the working definition is
unchanged. The challenge is consumed exactly once.

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
consumption; the rate limiter; the honeypot; the Turnstile client; the
notifier; the submission store; the retention cron; the privacy exporter and
eraser; the submissions screen.

**Reused.** `wp_mail()`, `is_email()`, `wp_remote_post()`, the cron API, the
WordPress privacy registries, the `Store::record_event()` vocabulary, the
`Content_Write_Guard` *pattern* (re-implemented locally, because the forms
plugin must not depend on the MCP plugin).

**New modules.** `class-submission-handler.php`, `class-submission-store.php`,
`class-form-notifier.php`, `class-turnstile.php`, `class-form-privacy.php`,
`class-form-admin.php` (the submissions screen).

**Data model.** The `agentshell_submission` post type and its meta keys; the
notification status; the retention setting.

**MCP.** None yet, but the submission store is written with the tool
authorisation in mind from the start.

**WordPress integration.** The public REST route, the cron jobs, the privacy
hooks, the admin screen.

**Security.** The whole of §10.2 applies. The submission is written before
the notification is attempted, so a delivery failure cannot duplicate it.

**Migration.** None.

**Tests.** `tests/forms-submission-test.php` (validation, required fields,
the honeypot's fake success, the challenge's single use, the rate limit, the
size limits, the malformed request, the cross-form key discard);
`tests/forms-turnstile-test.php` (every branch of the response handling, the
failure policies, the test-credential refusal in production);
`tests/forms-notification-test.php` (the templates, the escaping, the header
injection attempts, the recipient allowlist, the retry reusing the row, the
failure not duplicating the submission);
`tests/forms-retention-test.php` (the purge, the deletion paths).

**Acceptance.** A submission is stored once, validated against the published
version, and produces exactly one notification. A failed notification leaves
one submission and a retry re-uses it. A honeypot-filled submission returns
success and stores nothing. A Turnstile token that was already used is
refused.

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
count test extension; the documented count.

**Reused.** The base tool class, the `agentshell_mcp_register_tools` filter,
`Store::record_event()`, the two-part authority pattern, the transport's
argument redaction.

**New modules.** `class-form-tools.php` and the tool classes.

**Data model.** None.

**MCP.** The whole surface.

**WordPress integration.** None beyond the plugin's own activation.

**Security.** Read authority and destructive authority separated; the
explicit personal-data authorisation; `confirm` on destruction; the per-post
`delete_post` re-check at the moment of deletion; the secret never returned.

**Migration.** The tool-count test is extended to three plugin files, and the
documented count is updated in the two places the documentation gate checks.

**Tests.** `tests/forms-tools-test.php` (each tool's schema, its refusals,
its result shape, its authorisation behaviour, its audit rows).

**Acceptance.** Every tool is reachable by name and returns a documented
shape. A listing never contains a field value. A refusal is written to the
audit log. The documented count equals the registry's.

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
        ┌──────────────┐
        │    Phase 1   │  the model, the schema, the store
        └──┬───┬───┬───┘
     ┌─────┘   │   └─────┐
  ┌──▼──┐  ┌───▼───┐  ┌───▼──┐
  │ P2  │  │  P4   │  │  P5  │
  │render│ │builder│  │ tools│
  └──┬──┘  └───────┘  └──────┘
     │
  ┌──▼──┐
  │ P3  │  the pipeline, the notifications, Turnstile
  └─────┘
     │
  ┌──▼──┐
  │ P6  │  the zone block, the packaging
  └─────┘
```

### 13.4 What can be developed in parallel

- **Phase 4's model work** — the pure models in `forms-model.js` — can be
  written against the frozen schema while Phase 2 and Phase 3 are in flight.
  It has no dependency on the renderer.
- **Phase 5's tool classes** can be written against the Phase 1 service API
  while Phase 3 is in flight. The tools call the definition service, not the
  pipeline.
- **Phase 3's Turnstile client** is self-contained once the schema is
  frozen; it depends on nothing but the configuration and the transport.
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
| Duplicate submission and the single-use challenge | `tests/forms-challenge-test.php` | destructive |
| Rate limiting | `tests/forms-rate-limit-test.php` | destructive |
| Turnstile verification and every failure branch | `tests/forms-turnstile-test.php` | unit |
| Turnstile against a real HTTP transport | `tests/forms-turnstile-live-test.php` | live |
| Notification templates, escaping, header injection | `tests/forms-notification-test.php` | unit |
| Notification delivery and the retry path | `tests/forms-notification-live-test.php` | live |
| Submission storage, trash, erasure, export | `tests/forms-retention-test.php` | destructive |
| The privacy exporter and eraser hooks | `tests/forms-privacy-test.php` | live |
| Permission enforcement on every route | `tests/forms-permissions-test.php` | unit |
| MCP access boundaries and the personal-data authorisation | `tests/forms-tools-test.php` | unit |
| Form versioning | `tests/forms-definition-test.php` | unit |
| Concurrent editing | `tests/forms-concurrency-test.php` | unit |
| The builder's models | `tests/forms-builder-test.js` | node |
| The builder's interface | `tests/forms-builder-browser-test.js` | browser |
| Accessibility of the rendered form | `tests/forms-accessibility-test.php` | unit + browser |
| Release packaging | `tests/forms-packaging-test.php` | unit |

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
12. An agent lists the forms, reads one, changes it, and publishes it — and
    an agent reading a submission's contents must pass the explicit
    personal-data authorisation, and the refusal is audited.
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
| An agent with `manage_options` reads personal data it should not | Medium | A privacy incident | The explicit personal-data authorisation is the binding control, it is re-checked at the moment of use, listings are metadata-only by construction, and every read and every refusal is audited |
| The count test is not extended and the documented count goes stale | **High if forgotten** | The agent-facing documentation lies about the tool surface | The count test is a *build failure*, not a note; the phase that adds the tools is the phase that extends the test, and the extension is in the same PR |
| The release archive ships without the plugin | Medium | The feature is missing in production with nothing noticing | The packager and the checker share one list, and the checker requires the plugin's files — the same discipline the configurator's packaging fix used |
| The honeypot penalises assistive technology | Low | Some legitimate visitors are silently dropped | The honeypot is `aria-hidden`, `tabindex="-1"` and off-screen — never `display:none` alone — and it is verified against a real browser in the accessibility suite |
| The rate limit is wrong for a legitimate campaign | Medium | A site owner loses submissions and does not know why | The limit is per form and configurable; the refusals are counted and visible in the submissions screen; the builder warns when the limit is tight for the form's expected traffic |

### 15.2 Unresolved questions

These are genuinely open. Each is listed with the decision that has to be
made, who should make it, and when.

| # | Question | Why it is open | Proposed default | Decided by |
|---|---|---|---|---|
| Q1 | Should the site key and secret be site-level or form-level? | A site with several forms may want different Turnstile configurations | **Site-level** configuration, with the *policy* (`disabled`/`optional`/`required`) per form. One secret, one verification path, many policies | Before Phase 3 |
| Q2 | Should a submission be visible to an editor who can edit pages? | The capability split is proposed, but the default mapping to roles is a site decision | Administrators only by default; the capability is assignable to a "site editor" role by the operator | Before Phase 3 |
| Q3 | What is the site-wide default retention period? | It is a policy decision with a legal dimension | 90 days, with the operator able to change it | Before Phase 3 |
| Q4 | Should the notification body be plain text, HTML, or both? | HTML is friendlier; plain text is safer and always readable | **Both**: an HTML part and a plain-text alternative, generated from the same values. `wp_mail()` accepts the alternative as the `Content-Type` boundary, and the plain-text part is what a client without HTML support reads | Before Phase 3 |
| Q5 | Should the Turnstile `action` be per form or per site? | Per form is stricter; per site is simpler | Per form, and the action is verified on the response | Before Phase 3 |
| Q6 | Should the plugin be active by default when the theme is installed? | A theme that installs a plugin is unusual, and the plugin must not be auto-enabled | **No.** The theme does not install it. It is documented, and the packaging ships it ready to activate | Before Phase 6 |
| Q7 | Should the forms plugin offer an export/import of definitions? | Useful for moving a site; a security surface if it can carry submissions | Definitions only, in Phase 6 or later, with the secret never included in an export | Before Phase 6 |
| Q8 | Should the builder support a form template gallery? | A convenience that could pull in a definition from an untrusted source | No gallery. Templates are defined in the plugin's own code, where they are reviewable | Before Phase 4 |
| Q9 | Should the post type's `capability_type` be a single capability or a set? | WordPress's model is per-type capabilities | A set — `edit_agentshell_form`, `read_agentshell_form`, `delete_agentshell_form` — mapped to `manage_agentshell_forms` | Before Phase 1 |
| Q10 | Should the submission's source address be a salted HMAC or a plain hash? | Both are irreversible in practice; an HMAC is stronger | HMAC with a per-site salt, truncated. A plain hash of an IPv4 address is trivially reversible by enumeration | Before Phase 1 |

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
right to read what people wrote into it. The capability split alone does not
separate them, because the agent transport requires `manage_options` for
every call. The separation that actually binds is the **explicit
personal-data authorisation**, the metadata-only listing, and the audit row
on every read *and every refusal*.

That is the same lesson the content journal learned — that read authority and
destructive authority are separate, that both are re-checked at the moment
they are exercised, and that a refusal is indistinguishable from an absence
— applied to a domain where the sensitive thing is not a transaction but a
person's message. It is the part of this design that should be reviewed
hardest, and the part that should be built first.

---

*This document is a design record. It records an investigation and a
recommendation. It does not make anything normative, and it does not claim
that any of it exists. The normative contract for AgentShell is `AGENTS.md`,
and the tool surface it names is in `AGENTS.md` §2.*
