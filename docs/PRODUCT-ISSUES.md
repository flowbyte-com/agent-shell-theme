# Product issues — recorded, not fixed

Issues 1–2 were found while building
[`TRANSACTION-PREVIEW.md`](./TRANSACTION-PREVIEW.md). Issues 3–8 were found
later, driving a real site through the Fieldwork Studio evaluation end to end.
All are recorded so they are not rediscovered.

Issue 3 has since been **fixed**; it keeps its place here because the failure
modes it documents are the ones worth remembering. The F3 prerequisite blocking
issue 8 has been fixed too (§8a). Nothing else is fixed.

---

## 1. The no-browser fallback recommends a step that cannot work

**Where:** `agentshell-mcp/includes/tools/class-screenshot.php:57`

**What it says.** When no headless browser is available, `agentshell_screenshot`
returns:

> `Until then, use agentshell_preview_theme to get a preview URL and view it
> in a browser.`

**Why it is wrong.** `agentshell_preview_theme` does return a URL, but the
theme only renders that preview for a **logged-in browser session**
(`functions.php`, the profile-preview block: `is_user_logged_in()` or an HMAC
key derived from `AGENTSHELL_REST_TOKEN`). The tip fires precisely when there is
no browser on the server — i.e. for a headless agent. The agent it is aimed at
has no session, cannot open a browser, and on any install without
`AGENTSHELL_REST_TOKEN` (which includes the Local development site) cannot
obtain the key either. The suggested next step cannot succeed.

**Why it matters more than a bad string.** This advice is the reason the
`site-from-brief` workflow skips its visual verification step. The skill says
*"when `agentshell_screenshot` is available, capture desktop + mobile; when not,
surface that the visual loop was skipped"* — an agent that has just been told
there is a good alternative will take it, find it does not work, and end up
shipping an unverified design. The honest failure is already what the skill
asks for.

**Suggested fix (not applied).** Drop the tip, or replace it with the true
option: `agentshell_preview_transaction` still returns the staged-vs-committed
diff, which is useful without a browser. Say plainly that no visual check is
possible.

---

## 2. No MCP tool can delete or trash content

**Where:** `agentshell-mcp/includes/tools/` — content tools are `create_page`,
`create_post`, `update_content`, `publish_content`, `unpublish_content`,
`get_content`, `search_content`. There is no delete or trash.

**What that costs.** An agent can create content but never remove it. Anything
created for a probe, a smoke test or a throwaway draft has to be cleaned up
outside the agent surface — in practice `curl` against
`DELETE /wp-json/wp/v2/pages/<id>` or direct database access, neither of which
goes through the transaction, audit-log or revision machinery. The practical
effect during this work: creating a single throwaway page to answer a question
left content that had to be deleted by hand, and the deletion left no audit
entry.

`unpublish_content` is not a substitute. An unpublished page still occupies a
published-permalink identity and still appears in search and in the REST index,
so it is not a cleanup operation.

**Suggested fix (not applied).** `agentshell_delete_content(id, force=false)`,
trash by default with `force` for permanent deletion, gated on
`edit_others_posts` / `delete_others_posts` for content the caller does not own,
recorded in the audit log and stamped as a revision like every other mutation.
It should refuse to act outside a transaction the same way the config tools do,
so a delete can be previewed and rolled back like any other change.

---

## 3. ~~`agentshell_register_navigation` fatals on every call, after creating a menu~~ — FIXED

**Status: fixed.** The API errors, the orphan menu, the false transactional
claim and two further defects are all corrected. **Navigation transaction
staging is not** — that remains deferred to issue 8, and the tool now says so
in its own description and its MCP result rather than claiming otherwise.

**Where:** `agentshell-mcp/includes/tools/class-register-navigation.php`

**What it said.** The tool's docblock promised to "replace all items in one
atomic write (`wp_update_nav_menu_items`)", and claimed it was "safe to re-run
inside the same transaction".

**What was wrong.** Four defects, only the first of which was visible:

1. `wp_update_nav_menu_items()` — plural — has never existed in WordPress.
   Every call died with `Call to undefined function`. The function it was
   reaching for is `wp_nav_menu_update_menu_items()`, an **admin** helper in
   `wp-admin/includes/nav-menu.php` that loops `wp_update_nav_menu_item()` one
   item at a time and returns a flat `string[]` of ids. The fatal fired at
   line 157, *after* `wp_create_nav_menu()` at line 137, so every call left an
   orphaned menu term behind — with no MCP tool to remove it (issue 2).

2. The `_agentshell_position` tag-then-resolve second pass was built on a bulk
   return value that does not exist. Neither the plural nor the admin helper
   returns item rows.

3. `nav_menu_locations` was written **backwards**. `get_nav_menu_locations()`'s
   docblock claims the map is keyed by menu id, but it is keyed by *location
   slug* — `has_nav_menu()`, `wp_delete_nav_menu()`, the REST menu-locations
   controller and `wp-admin/nav-menus.php` all read it that way. So even past
   the fatal, the assignment would have been invisible to `wp_nav_menu()`,
   which would have quietly fallen back to the automatic page menu. The theme
   looked fine and the navigation never appeared.

4. Item order relied on `menu-item-position = 0` for the first slot. WordPress
   reads a zero position as *append to the end*: updating an existing item to
   position 0 moved it to the bottom of the menu. Positions are 1-based.

The old tests passed throughout, because they only ever exercised a pure
row-builder and a schema literal — `execute()` was never called.

**How it behaves now.** The whole item list and the theme location are
validated before any write, so a bad request cannot create a menu and then die
half way through one. Items are written with `wp_update_nav_menu_item()` in
list order at explicit 1-based positions; on an existing menu they are
reconciled in place, so item database ids are stable across repeated runs. A
`parent` may name the label of an earlier item, which lets every parent resolve
in the same forward pass that inserts the items — no second pass. Surplus
existing items are deleted only after every create and update has succeeded.
The location is assigned last.

If a write fails part-way, the tool compensates for exactly what it touched:
items it inserted are deleted, items it mutated are restored from a snapshot
taken before each write, the menu term is deleted if this call created it, and
`nav_menu_locations` is put back as it was found — which matters because
`wp_delete_nav_menu()` zeroes a location rather than restoring what was there.

`WP_Error` messages from WordPress propagate, naming the failing item.

**Coverage.** `tests/navigation-test.php` (21 tests) pins the pure row builder
and the pre-write validation. `tests/navigation-live-test.php` (20 tests) runs
`execute()` against a real WordPress install and reads the result back with
WordPress's own APIs — including `wp_nav_menu()`, so the frontend rendering is
asserted rather than assumed. It covers the mid-write failure paths by
provoking a genuine `WP_Error` through the `wp_insert_post_empty_content`
filter. It skips cleanly when no WordPress is available.

Two bugs found while writing it are worth recording because they are easy to
reintroduce: reading "no parent" as index 0 silently nests a flat menu under
its own first entry (ordering still looks correct, so only a check of
`_menu_item_menu_item_parent` catches it), and position 0 moves an item to the
end rather than to the front.

**A note on how these APIs were checked.** MPM's reference corpus *does* carry
the WordPress nav-menu documentation, and it contradicts the installed core in
one useful way: it documents `wp_nav_menu_update_menu_items()` as the real
bulk helper and confirms `wp_update_nav_menu_items()` does not exist. It was
initially reported as absent because the lookup went to `mpm_memory` — which
queries the `memories`, `decisions`, `theories` and `skills` collections —
rather than to `mpm reference search`. The corpus is tagged WordPress 6.6, so
behaviour is still verified against the 7.1.3 core in LocalWP; the two agreed
on every signature used here.

**Remaining limitation.** Still not transaction-staged. A menu written by this
tool is live immediately, is not covered by `agentshell_preview_transaction`,
and `agentshell_rollback_transaction` will not undo it. Navigation writes need
the staging path built in issue 8 before the transaction can be trusted to
cover content.

---

## 4. Theme-side and tool-side config readers disagree — the widget registry is the worst case

**Where:** `functions.php:970` (`agentshell_get_widget_registry`) and
`functions.php:885` (`agentshell_get_config`) versus
`agentshell-mcp/includes/tools/class-base-tool.php:47-63`.

**What it says.** The base tool docblock says the config accessor "lets a
sequence of mutation tools compose within a transaction", implying there is one
config.

**Why it is wrong.** There are two readers, and only one of them knows about
transactions:

- `Base_Tool::get_agentshell_config()` →
  `Transaction_Manager::effective_config()`, which returns **staged** config
  while a transaction is open.
- `agentshell_get_config()` → `get_option( 'agentshell_config' )`, which is
  **stored** config only.

`agentshell_get_widget_registry()` — the bilateral merge of `widgets/.index.json`
with `$config['widgets']` — uses the second. Its consumers are the renderer
(`template-parts/widgets.php:13`), the REST configurator, Doctor, and four MCP
tools (`list_widgets`, `enable_widget`, `disable_widget`, `inspect`, `explain`,
`get_config`). But `register_widget`, `remove_widget`, `enable_widget` and
`disable_widget` **write** through the first.

The concrete failure, entirely inside the documented workflow:

```
begin_transaction → register_widget(id="hero") → enable_widget(id="hero")
```

`register_widget` stages the widget and reports success. `enable_widget`'s
existence guard (`class-enable-widget.php:26-31`) consults the registry, which
reads stored config, has never seen the staged widget, and throws "unknown
widget". The agent has done exactly what the tool contract says and hit a
contradiction. The same split means `agentshell_validate_transaction` — Doctor
also reads the registry — validates a configuration that does not contain the
staged widget, so the transaction previews clean and commits something the
validation never examined.

**Why it matters.** This is the mechanism issue 1 and issue 2 both hide behind.
Any future tool that reads through a `functions.php` helper while a sibling tool
writes through `Base_Tool` inherits the same split-brain, silently.

**Suggested fix (not applied).** Make `agentshell_get_config()` accept an
optional transaction-aware mode and have `agentshell_get_widget_registry()` take
the effective config as a parameter rather than fetching its own, so there is
exactly one reader. Render paths keep passing stored config explicitly (a live
visitor must never see staged design); tool paths pass effective. Add a test
that registers and enables in one transaction.

**Status: fixed.** The two readers are now one reader with an explicit
committed/effective contract, and the defect turned out to be wider than
described above.

**The contract.** `agentshell_get_config()` takes an explicit source argument,
defaulting to `'committed'`:

- `agentshell_get_config( 'committed' )` — what is stored, what every ordinary
  visitor sees, what survives a rollback. The default, so an existing call that
  passes nothing keeps its meaning.
- `agentshell_get_config( 'effective' )` — staged configuration when a
  transaction is open, falling back to committed. What tools act on.
- Anything else throws `InvalidArgumentException` rather than silently defaulting.

`agentshell_get_config()` was deliberately *not* changed to expose staged values
globally; a visitor request and a tool call are different questions and now ask
them by name. `agentshell_get_widget_registry()` and `agentshell_get_zones()`
take the config explicitly, and passing none means committed.

**Rendering** goes through `agentshell_config_for_render()`, gated by
`agentshell_authorize_transaction_preview()` and memoised per credential
fingerprint (`tx|key|exp`) so the `template_redirect` decision and the later
render-time call cannot disagree. `agentshell_widget_registry_for_render()` and
`agentshell_zones_for_render()` are the only places that answer "may this request
see staged state".

**Wider than documented — three further causes, all fixed:**

1. **The staging filter cancelled itself.** `Transaction_Manager::filter_config_update()`
   returned `false` to mean "do not write this yet". But
   `pre_update_option_{$option}` is a *transform* filter, not a veto — core only
   skips a write when the returned value is identical to the old one
   (`option.php`, `update_option()`). A `false` return was therefore **stored**.
   Worse, the callback was registered per instance, and `apply_filters` threads
   each return value into the next callback, so a second instance received the
   first one's `false` and `stage()` was called with a non-array. The dispatcher
   is now a single static registration and a transaction returns `$old_value`,
   which is how a filter actually cancels.

2. **Two widget stores.** `agentshell-blocks` kept its own `agentshell_widgets`
   option alongside the canonical `agentshell_config.widgets`. `Widget_Registry`
   now defers to the theme's canonical registry when the theme is loaded, and the
   legacy option is only written when there is no canonical store. The legacy
   migration reconciles into the canonical store and deliberately does **not**
   delete the legacy option unless the canonical write actually landed — the write
   is staged inside a transaction, so "the migration ran" is not evidence that
   anything was persisted.

3. **Doctor validated a configuration that did not exist.** Doctor resolved
   staged zones against committed widgets, so `validate_transaction` could pass a
   transaction whose staged widget ids it had never seen.

**One defect the tests did not catch, found live.** The preview gate authorised
correctly and staged design tokens rendered, but the staged widget rendered as
`<!-- Widget not found: … -->`. `template-parts/shell-render.php` does not render
widgets itself — it fires `do_action( 'agentshell_render_widget', $id )` and the
blocks plugin answers. The plugin's `render_widget()` resolved that id against
the **committed** registry. So one preview could resolve its zone composition
from staged config and its widget body from committed config, and the two halves
disagreed. `render_widget()`, `get_all_css()` and `get_init_js()` now resolve
through the theme's preview-aware registry.

The regression that should have caught this called the theme's
`agentshell_render_widget()` directly — a function production never calls when
the plugin is active. It passed while the live site was broken. The replacement
calls `Widget_Registry::render_widget()`, which is exactly what the hook handler
calls. `agentshell_render_block()` cannot be used to get there because the test
bootstrap stubs `do_action()` as a no-op.

**Coverage.** `tests/config-consistency-test.php` (31 tests) covers the ten
numbered scenarios plus negatives: register-then-enable in one transaction, a
staged widget visible to the next tool, `get_config` reporting staged values,
register-then-disable and register-then-remove, validate accepting a staged
widget *and* still rejecting a genuinely unknown one, ordinary render seeing
neither staged widget nor staged zone through both resolvers, authorised preview
rendering both, rollback, commit, existing widgets surviving, legacy migration,
the blocks plugin reading the canonical store, its renderer honouring disabled,
and credential negatives (no credential, forged key, valid signature for another
transaction, closed transaction).

`tests/config-consistency-mutation.php` (15 mutants, all killed, checkout
untouched) targets `functions.php`, `class-base-tool.php`, `class-transactions.php`,
`class-doctor.php`, `template-parts/shell-render.php` and
`agentshell-blocks/includes/class-widget-registry.php`. Two of its mutants exist
specifically to fail if the plugin's render path ever goes back to committed-only.

**Live verification.** Every step went through the MCP tools; nothing was written
by any other route. `begin_transaction → register_widget → enable_widget →
update_zone_composition → set_css_var → validate_transaction` returns
`status: ok` with no `WIDGET_REFERENCE_UNKNOWN`. Under an authorised credential
the page renders the staged widget and the staged accent and carries
`X-Robots-Tag: noindex, nofollow, noarchive` and `Cache-Control: … no-store`;
an anonymous request and a request with a forged key render neither and carry no
cache-suppressing headers. `agentshell_rollback_transaction` restored the site
(registry back to `hello-world` only, accent back to `#3b82f6`, main zone back to
`[wp_loop]`), and a separate commit path published the staged state to ordinary
visitors and then restored the same baseline through a second transaction.

**Remaining inconsistencies, found but not fixed** (they are new work, not part
of this issue):

- `Transaction_Manager::begin()` builds the id as `'tx_' . time()`. Two
  transactions begun in the same second share an id, so a stale preview credential
  for the first would authorise the second. A collision-resistant id belongs here.
- `agentshell_remove_widget` reports `remaining` as the length of
  `config.widgets`, which excludes file widgets. After removing the only
  config-registered widget it reports `remaining: 0` on a registry that still
  contains `hello-world`. Correct, but it reads like data loss.
- `agentshell_get_render_widget()` — the theme's own renderer — is defined and
  never hooked while the blocks plugin is active. Not a behaviour bug, but two
  implementations of the same decision is one more than there should be.

---

## 5. Core WordPress blocks render completely unstyled

**Where:** `style.css` — zero `.wp-block-*` selectors.

**What it says.** Nothing; there is simply nothing there.

**Why it is wrong.** The theme is a full-site-editing shell: an agent composes
`main` out of `{"type":"wp_loop"}` and raw post content, and that content is
whatever block markup the agent writes. `style.css` has an extensive,
carefully-specified style for `.wp-core-*` — the theme's own wrapper classes for
core components (title, tagline, nav, search) — and **no** styling at all for
the blocks those components wrap.

The observable result during the evaluation: a page built from a heading, some
paragraphs, a list, a quote and an image rendered as an unstyled vertical stack
of browser-default Times New Roman at 16px/1.15, with no container width, no
`alignwide`/`alignfull` handling, no spacing between blocks, and images running
edge to edge inside the content column. Setting a designed palette
(`agentshell_set_palette`, which works correctly and is visible on the chrome)
changed nothing inside the content area — the two surfaces are disconnected.

This also defeats the design system's own promise. `agentshell_get_design_system`
reports typography and spacing tokens; none of them reach block content.

**Why it matters.** Content is where most of the page is. An agent asked to make
a site "look designed" can set every design token correctly and still ship a page
that looks like a plain HTML document inside a designed frame.

**Suggested fix (not applied).** A Section 6 block layer: container width,
vertical rhythm from the spacing tokens, `alignwide`/`alignfull` breakout,
figure/quote/callout/table/list/heading resets, and CSS-variable-driven colour
so `agentshell_set_palette` reaches block content. Must respect the immutable
grid protocol in Sections 3 & 4 and the structural prohibitions in
`agentshell_inject_saved_styles`.

---

## 6. No MCP tool can write free-form CSS

**Where:** the tool registry. There is `agentshell_set_css_var`,
`agentshell_set_design`, `agentshell_set_palette`, `agentshell_set_typography`,
`agentshell_set_spacing`, `agentshell_set_shape` — and no `agentshell_set_custom_css`.

**What it says.** Nothing claims one exists.

**Why it is wrong.** Free-form CSS is first-class in the config — the REST schema
accepts a `custom_css` string up to 262,144 bytes (256 KiB, measured in bytes
of the UTF-8 encoding; see `agentshell_max_custom_css_bytes()` in
`functions.php`) and `agentshell_inject_saved_styles` renders it — but the only
way to set it is `PUT /wp-json/wp/v2/agentshell/config`. So the most expressive styling surface in
the product is reachable only by bypassing MCP, which means it skips the
transaction staging, the validation, the revision history and the audit log that
every token-based path gets for free.

The token tools cannot express what the evaluation actually needed: a grid on the
main zone, a capped measure on paragraphs, a sticky footer. Those are the changes
that turn a designed frame into a designed page, and issue 5 is the reason they
were being written by hand.

**Why it matters.** The product's most powerful tool is the one it does not
expose. An agent that discovers it will use `curl`, and the change will not be
transactional, previewable or reversible.

**Suggested fix (not applied).** `agentshell_set_custom_css(css)`, routed through
`update_agentshell_config()` so it stages like everything else, with the
structural prohibitions from `AGENTS.md` §5 enforced at write time rather than at
render time (a proposal that passes the render check but is rejected on commit is
worse than one rejected on entry). Follow it with `agentshell_get_custom_css`.
Pair with issue 5: the block layer removes most of the pressure to hand-write it.

---

## 7. Screenshot viewport is capped at 4096px with no full-page option

**Where:** `agentshell-mcp/includes/tools/class-screenshot.php:68`.

**What it says.** "Viewport must be within 320-4096px wide and 240-4096px tall."

**Why it is wrong.** 4096px is roughly five 800px-tall screens. A site-from-brief
landing page is routinely 6000–12000px tall, and there is no full-page mode: the
capture is viewport-sized only. Combined with `AGENTSHELL_SCREENSHOT_MAX_BYTES`
(default 25 MB, clamped), a full-page capture of a content-heavy page would blow
the byte limit anyway.

The result is that visual verification systematically covers only the first
few screens — exactly the part above the fold that already looks fine. The
evaluation captured desktop and mobile and got two images that confirmed the
header and hero were correct and said nothing at all about the rest of the page.

**Why it matters.** It makes the visual loop weaker than it appears to be, which
is worse than having no visual loop: an agent will report "verified with
screenshots" having verified a fifth of the page. Issue 1 in this file is the
same class of defect: a verification path that reports success for less than it
checked.

**Suggested fix (not applied).** `full_page: true` with `capture_beyond_viewport`
in the Chrome flags, a separate and much higher byte ceiling for stored
screenshots, and an explicit `{width, height, full_page}` in the response so the
caller knows what it got. Until then, agents should be told plainly that a
capture is one viewport, not the page.

---

## 8. Content tools do not participate in transactions at all

**Where:** `agentshell-mcp/includes/tools/class-create-page.php`,
`class-create-post.php`, `class-update-content.php`, `class-update-post-content.php`,
`class-publish-content.php`, `class-unpublish-content.php`.

**What it says.** `AGENTS.md` §6: mutating calls — which it enumerates as
"`create_*`, `publish`, `register_*`, `update_*` … must run inside an open
transaction or be flagged as needing recovery", and the loop diagram annotates
them "(staged; not yet live)". All six of these tools are in that list.

**Why it is wrong.** None of these six tools reference the transaction manager at
all. `create_page` calls `wp_insert_post()` and returns a post id; the page is
immediately in the database. `agentshell_begin_transaction` does not defer it,
`agentshell_validate_transaction` does not see it, `agentshell_rollback_transaction`
does not undo it. The only hint of transaction awareness in the codebase is a
comment — `class-register-navigation.php:72` — in a tool that is not
transaction-aware either (issue 3).

This was directly observed. A probe page created during the evaluation had to be
removed with hand-written `wp_delete_post()`, outside the transaction and outside
the audit log, which is precisely the gap issue 2 describes.

There is a second, quieter half. Content mutations do not create revisions the
way config mutations do, so `agentshell_list_revisions` / `agentshell_restore_revision`
— which are advertised as the undo mechanism — cover config and design only. A
content change is outside the history it appears to belong to.

**Why it matters.** The transaction is the product's central safety claim. Today
it covers design and configuration, which is roughly half the state an agent
touches; the other half is unguarded. An agent can begin a transaction, compose
a design, hit an error mid-way, roll back — and find the pages it created along
the way still sitting there, published.

**Suggested fix (not applied).** Route the six tools through the transaction
manager: buffer the intended post write, and either apply it on commit or apply
it immediately with a compensating delete wired into the rollback path — the
staged-config model only works if the staged value can be re-derived, which for
posts means staging the post array rather than the post id. Make content
revisions first-class so restore_revision covers them. Ship `agentshell_delete_content`
(issue 2) in the same change, since a transaction that cannot be un-done by
rollback needs a delete that can be audited.

### 8a. The prerequisite is now done: a content write can no longer half-happen

**Status: FIXED.** Recorded here because issue 8's fix depends on it and because
the failure mode is the one worth remembering.

Fixing issue 8 was ruled to be blocked on F3 — the partial `wp_insert_post()`
failure described in `CONTENT-TRANSACTIONS-DESIGN.md` §1.6b — so it was fixed
first, as a standalone correctness change. Verified, not asserted:

- Every content write runs inside `Content_Write_Guard`, a bounded SQL
  transaction that rolls back on a `WP_Error`, a `false`/`0`, or a thrown
  exception. On the local install, the genuine post-insert failure now leaves
  **0 post rows, 0 metadata rows, and an object cache that no longer serves the
  rolled-back post**. Before the fix it left 1 of each.
- Two things that looked correct and were not: a rollback *alone* still left the
  object cache serving a post that was no longer in the database, and a nested
  write opening its own transaction would have **committed the outer one**,
  because MySQL has no nested transactions.
- A non-transactional engine gets no protection, and the tool says so in its
  result rather than claiming one it cannot keep.
- **A second, separate defect found while investigating:** `create_page` with an
  empty title *and* empty content wrote an untitled draft page and reported
  success. Nothing identifies it in a listing, an edit screen or a preview. It
  is now refused before anything is written, matching core's own `empty_content`
  rule. A titled page with an empty body is still allowed.

**The honest limit.** F3 was reproducible against WordPress but was **not
reachable through AgentShell's own arguments** — no `create_*` field reaches a
`wp_insert_post()` branch that validates after inserting. The protection is in
place for when that changes; it is not evidence that AgentShell has been leaking
posts. A new content field must be checked against that branch list before it is
added.

**Tests.** `tests/content-write-test.php` (17 groups, boundary via a recording
`wpdb`), `tests/content-write-live-test.php` (the real reproduction against a
real WordPress, plus the MCP tool classes and a before/after site check), and
`tests/content-write-mutation.php` — **22 of 22 mutants killed**.

**What remains for issue 8.** Unchanged by this: the six content tools still do
not participate in transactions, `rollback_transaction` still cannot undo a
content change, content still creates no revision, and `list_revisions` /
`restore_revision` still cover design only.