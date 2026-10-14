# Content transactions — design and investigation

**Status: architecture review complete. Stage 0, the F3 partial-write fix
(§5.1), and Stage 1a (the journal and its recovery engine, one operation type)
are implemented; stages 1b–6 are proposals.**
Scope: AgentShell issue #8 (content tools do not participate in transactions)
and issue #2 (no tool can delete content).

> **What this file is.** A *design record* under `AGENTS.md` §8 category (e): an
> architectural investigation, the decision taken, and the evidence for it. The
> review ruled that §8 gains a fifth category for exactly this kind of file, so
> it stays where it is. It cannot override `AGENTS.md`, and it does not make
> unimplemented behaviour normative — every statement below is a proposal unless
> it is marked as implemented, and where the two disagree `AGENTS.md` wins.

> **What the review decided, and where each decision now lives.**
>
> 1. **Conditionally approved C + D + B** — persisted drafts for preview, a
>    durable pre-image journal for compensation, short-lived SQL transactions
>    for the final commit. Explicitly **not** one database transaction held open
>    across MCP calls, screenshots or reasoning; and the whole workflow is
>    **not** atomic, so §3 below keeps commit-time atomicity, pre-commit
>    visibility, compensating rollback and non-database side effects as four
>    separate things. The partial `wp_insert_post()` residue (F-numbered in §5)
>    was a precondition, not a later refinement, and **it is now fixed** — see
>    §5.1. Normative form: §3 here; nothing implemented.
> 2. **Immediate-write behaviour outside transactions is preserved** for now, and
>    the semantics are stated explicitly in tool results. Inside a transaction,
>    supported mutations must be tracked and unsupported ones must fail rather
>    than silently bypass. **Recorded in `AGENTS.md` §6.1** — the current
>    contract is normative there; the inside-a-transaction rule is marked there as
>    proposed, because it is not built.
> 3. **A fifth `AGENTS.md` §8 category was approved**: non-normative design
>    record. This file remains at this path. Also added there: rows for the
>    design records that already existed unlisted.
> 4. **Stage 0 first** — the transaction lease and stale recovery, because a
>    transaction wedged the site indefinitely if a session died before commit.
>    Implemented; **normative form is `AGENTS.md` §6.2**, with regressions in
>    `tests/transaction-lease-test.php` and mutants in
>    `tests/transaction-lease-mutation.php`. Stage 0 also carries the
>    `has_durable_work()` guard this document depends on: a transaction holding
>    a journal is never reclaimed, which is why §4's journal can be made
>    load-bearing later without a second recovery design.
> 5. **Stage 1a — the journal, and recovery for one operation type.** Built and
>    implemented, because Stage 0's `has_durable_work()` guard only ever fired
>    vacuously and an unwedged site is not what it was for. **Normative form is
>    `AGENTS.md` §6.4**; the schema actually built is in §4.1a, which supersedes
>    the sketch in §4.1; the recovery failure scenarios are F23–F35 in §5.0; and
>    the limitations that matter are §9's closing list. Two things it deliberately
>    did **not** do: it did not enable journalling in any content tool, and it
>    did not implement any operation type beyond compensating a
>    transaction-created draft. §10 lists what must close before the first
>    production tool may write a journal.

---

## Evidence base

Nothing in this document is from memory. Every behavioural claim is either a
reading of this repository, a reading of installed core, or a measurement.

| Source | Detail |
|---|---|
| WordPress | **7.1.3**, `/home/v/local/agentshell/app/public` |
| Database | **MySQL 8.4.0 Community**, `REPEATABLE-READ`, `autocommit=1`, **no persistent object cache** |
| Storage engine | `wp_posts`, `wp_postmeta`, `wp_term_relationships`, `wp_term_taxonomy`, `wp_options`, `wp_comments`, `wp_commentmeta` — **all InnoDB**; `information_schema.ENGINES` reports `InnoDB … transactions=YES` |
| Reference subsystem | `mpm_references` (`mpm call mpm_references`), a corpus of 33 WordPress API documents. `wp-wp-insert-post.md` carries the full core source of `wp_insert_post()` and was read against 7.1.3 |
| Live site state at time of writing | 3 posts (`#1` Hello world!, `#2` Sample Page, `#3` Privacy Policy draft), **0 revisions anywhere**, 0 nav menu items, no open transaction |

The reference corpus is labelled `wp_version: 6.6`, but its
`wp-wp-insert-post.md` body contains 6.9.0-era hooks (`pre_post_insert`,
`wp_after_insert_post`) and matches installed 7.1.3 line for line. Where the
two disagree, **installed core wins**; in this document they did not.

### The site has zero WordPress revisions

```
#1  post   publish  revs=0  Hello world!
#2  page   publish  revs=0  Sample Page
#3  page   draft    revs=0  Privacy Policy
```

No revision has ever been written on this install. "WordPress revisions will
save us" is not a claim this codebase has ever been able to make.

---

## 1. Current-state inventory

### 1.1 Which content operations modify the database immediately

**All of them. All six mutating content tools.**

`agentshell-mcp/includes/class-content.php` (`Content_Primitives`) is the sole
path; the tools are thin wrappers.

| Tool | File | Call | Lands in the DB |
|---|---|---|---|
| `agentshell_create_page` | `class-create-page.php` | `create( 'page', … )` | immediately |
| `agentshell_create_post` | `class-create-post.php` | `create( 'post', … )` | immediately |
| `agentshell_update_content` | `class-update-content.php` | `update()` | immediately |
| `agentshell_update_post_content` | `class-update-post-content.php` | `update()` | immediately |
| `agentshell_publish_content` | `class-publish-content.php` | `set_status()` | immediately |
| `agentshell_unpublish_content` | `class-unpublish-content.php` | `set_status()` | immediately |
| `agentshell_register_navigation` | `class-register-navigation.php` | `wp_create_nav_menu` + `wp_update_nav_menu_item` | immediately, and says so in its own `transaction_note` |
| `agentshell_get_content` / `agentshell_search_content` | | read-only | — |

`create()` calls `wp_insert_post( wp_slash( $postarr ), true )` and returns
`(int) $post_id`. `update()` and `set_status()` call `wp_update_post()`.

**Not one of these eight files references `Transaction_Manager`.** Config is the
only staged thing in the system, and it is staged by a single interception point
— `pre_update_option_agentshell_config` → `Transaction_Manager::on_config_update()`
(`class-transactions.php:206`), which diverts the write into
`$tx['staged']` and returns `$old_value` so core skips it.

The shape of the problem is therefore: **the transaction owns exactly one
option row.**

### 1.2 What is already reversible

| Surface | Reversible? | Mechanism |
|---|---|---|
| Config / design tokens | **Yes, fully** | Never written while a transaction is open; `rollback()` is a single `delete_option`. The database is genuinely never touched. |
| Snapshots / revisions | **Yes, for config only** | `Store::CONFIG_KEY` — `agentshell_snapshots`, `agentshell_revisions`, `agentshell_audit_log` all store *config arrays*. They never touch posts. |
| Content | **No** | Nothing records it. |
| Navigation | **Partially, one call only** | A local `try/catch` compensation journal (`$created_menu`, `$created_items`, `$restores`, `$locations_before`) that undoes its own partial failure. It does not survive past the call, so `rollback_transaction` cannot undo a successful navigation write. |

`agentshell_restore_revision` / `agentshell_restore_snapshot` are advertised as
the undo mechanism. They restore `agentshell_config` and nothing else. A content
change is outside the history it appears to belong to.

### 1.3 WordPress's own revisions — measured, not assumed

The brief warns: *do not presume that WordPress database writes are
automatically reversible through its revision system.* Measured:

**Revisions are post-images, not pre-images, in 7.1.3.** Source:

```php
// wp-includes/default-filters.php:446
add_action( 'post_updated', 'wp_save_post_revision', 10, 1 );   // ← 1 argument

// wp-includes/revision.php:129
function wp_save_post_revision( $post_id ) {
    …
    $post = get_post( $post_id );          // ← the CURRENT, already-updated row
    …
    $return = _wp_put_post_revision( $post );
```

`post_updated` fires *after* the UPDATE. The function takes only the post id
and re-reads it. The docblock states the design plainly: *"every update is a
revision, and the most recent revision always matches the current post."*

Measured, on a page updated twice:

```
created #296 with CONTENT_A
revisions immediately after INSERT: 0

after update to CONTENT_B:
  rev #297 content=CONTENT_B
  live post content = CONTENT_B

after update to CONTENT_C:
  rev #297 content=CONTENT_B
  rev #298 content=CONTENT_C
  live post content = CONTENT_C

newest revision #298 holds 'CONTENT_C' — the CURRENT state  => POST-IMAGES
restoring the NEWEST revision leaves: CONTENT_C (a no-op)
restoring the OLDEST revision leaves: CONTENT_B (the real undo)
```

This is still a usable history — you undo by restoring an *older* revision — but
it changes what an undo journal may assume. There is no pre-image to restore.
The pre-image is "the second-newest revision", and it may not exist.

**Revisions capture three fields.** `_wp_post_revision_fields()`
(`revision.php:22-62`) returns exactly `post_title`, `post_content`,
`post_excerpt`, minus a hard-protected list. Everything below is **outside the
safety net**:

`post_name` (slug) · `post_status` · `post_parent` · `post_author` ·
`post_date` / `post_date_gmt` · `post_mime_type` · `post_type` ·
`comment_status` · `ping_status` · `menu_order` · `post_password` ·
`comment_count` · `guid` · `post_content_filtered`

**Taxonomy relationships are never revisioned.** No code path in `revision.php`
touches `wp_set_object_terms`. Restoring a revision does not restore
categories or tags.

**A new post has no revision at all.** Both save paths are `$update`-gated
(`wp_save_post_revision_on_insert()` returns immediately when `! $update`;
the `post_updated` fallback lives inside `if ( $update )` in `wp_insert_post`).
The first version of anything is unrecoverable. Measured above:
`revisions immediately after INSERT: 0`.

**Some writes produce no revision at all.** The no-change bail compares only
the three revisionable fields. A slug-only change, a status-only change, a
meta-only change or a taxonomy-only change writes **zero** recovery points.

**Restoring is not symmetric with writing.** `wp_restore_post_revision()` calls
`wp_update_post()` with only the revisioned fields, so it restores content but
never status and never slug. Measured on a post that had been trashed and
untrashed:

```
post_content after restore: 'probe v2'
post_status  after restore: draft      (revisions never carry post_status)
post_name    after restore: __trashed  (revisions never carry post_name)
```

A trashed post stays trashed while its content reverts, and a `__trashed` slug
stays suffixed.

**Deleting a post destroys its revisions in the same operation.**
`wp_delete_post()` removes every revision row (`post.php:3913-3920`) as part of
the delete. There is no post-hoc recovery window: hard delete is final.

### 1.4 Side effects needing compensation

From `wp_insert_post` / `wp_update_post` / `wp_delete_post` / `wp_trash_post`
read in installed core, plus the reference copy of `wp_insert_post()`.

**Writes to tables other than `wp_posts`**

| Write | Where | Insert | Update |
|---|---|---|---|
| `postmeta` via `meta_input` | `post.php:5111-5115` | yes | yes |
| `postmeta` `_wp_page_template` | `post.php:5169/5171` | yes | yes |
| `postmeta` `_thumbnail_id` | `post.php:5149/5151` | conditional | conditional |
| `postmeta` `_wp_old_slug` | `wp_check_for_changed_slugs` on `post_updated` prio 12 | — | yes |
| `term_relationships` INSERT / DELETE | `taxonomy.php:2922 / 3088` | yes | yes |
| `term_taxonomy.count` UPDATE | `taxonomy.php:4253` | yes | yes |
| `options.cron` — the **whole array rewritten** | `cron.php:1304 / 1348`, for `future` status | yes | yes |
| `options.posts_last_changed` | `post.php:7992` | yes | yes |
| `options.terms_last_changed` | `taxonomy.php:2993` | yes | yes |
| revision rows + revision meta | `revision.php:372 / 561` | **no** | yes |
| `guid` | `post.php:5121`, insert only, `if ( ! $update && '' === $current_guid )` | yes | **never** |

Two of these deserve emphasis:

- **`wp_set_post_terms()` is replace-by-default.** With `$append = false` (the
  default) it diffs and calls `wp_remove_object_terms()` for the difference.
  Writing a post can therefore **silently delete existing term relationships**
  that are absent from the new set. Undo must snapshot the prior term set, not
  the new one.
- **The `guid` is written once, on first insert, and never again by
  `wp_insert_post`.** A slug change does not touch it. This is why a rollback
  that restores a slug is safe and one that tries to regenerate a guid is not.

**Core-owned side effects that are *not* reverse-safe** — core performs them
and keeps no record of the prior value:

| Hook | Core callback | Writes |
|---|---|---|
| `transition_post_status` | `_wp_auto_add_pages_to_menu` | **creates `nav_menu_item` posts** when a page is published |
| `delete_post` | `_wp_delete_post_menu_item` | **cascade-deletes menu item posts** |
| `before_delete_post` | `_reset_front_page_settings_for_post` | zeroes `show_on_front`, `page_on_front`, `page_for_posts`, `sticky_posts` |
| `wp_trash_post` | `_reset_front_page_settings_for_post` | the same four options |
| `before_delete_post` | `_reset_privacy_policy_page_for_post` | `wp_page_for_privacy_policy` |
| `deleted_post` | `_wp_after_delete_font_family` | cascade-deletes `wp_font_face` posts |

Compensating `_reset_front_page_settings_for_post` means recording
`page_on_front` / `page_for_posts` / `show_on_front` **before** the write.
Nothing else records them.

**Plugin-owned side effects — unbounded, and outside our control.** Core fires
the hook and does nothing else. A rollback cannot compensate these unless the
plugin cooperates:

`save_post` · `save_post_{$post_type}` · `wp_after_insert_post` ·
`wp_insert_post` · `post_updated` · `edit_post` · `set_object_terms` ·
`before_delete_post` · `deleted_post` · `transition_post_status` ·
`wp_insert_post_data`

ACF, SEO plugins, redirect tables, page builders, search indexes, custom tables
and outbound API calls all live here. On this site, only AgentShell is
installed, so a plugin blast radius is zero today — that is a property of the
dev environment, not a guarantee.

**A second write path bypasses `wp_insert_post` entirely.**
`wp_publish_post()` (`post.php:5404-5470`) does its own `$wpdb->update` of
`post_status` and its own `clean_post_cache()`. It is what
`publish_future_post` cron calls. A layer that wraps only `wp_insert_post`
does not see cron-driven publications at all.

### 1.5 What happens when a content operation succeeds and a later one fails

Nothing happens. There is no compensation, no rollback path, and no record.
`agentshell_rollback_transaction` deletes one option row and returns
`rolled_back: true, discarded_staged_changes: <bool>` — a statement that is
true about config and silent about the seven drafts now sitting in the
database.

This was already observed once in practice, recorded in issue #8: a probe page
created during the evaluation had to be removed with hand-written
`wp_delete_post()`, outside the transaction and outside the audit log.

### 1.6 Two failure modes that exist today and are not in any issue

> **(a) is FIXED — Stage 0.** It is kept below as the record of what was found,
> because the fix's shape is only legible against it. `AGENTS.md` §6.2 is the
> normative description of the lease that replaced it.

**(a) A crashed transaction locks the site indefinitely.** At the time of
writing the transaction row was `{id, label, actor, timestamp, before, staged}`
and `is_active()` was simply `is_array( payload() )`. There was **no TTL, no
expiry check and no reaper**.
`agentshell_get_transaction` reports the row but does not flag it as stale. If
a PHP process dies between `begin()` and `commit()`, the next actor to call
`agentshell_begin_transaction` gets

> Another actor (…) has an open transaction (tx_…, started …). Transactions are
> locked to the actor that opened them — wait for them to commit or roll back.

forever. The 900-second TTL applies to the *preview credential*, not to the
transaction. This is an availability bug that issue #8's design must fix rather
than inherit.

**(b) A `WP_Error` from `wp_insert_post` does not mean nothing was written.**

**RESOLVED — see F3 below. The measurement stands; the conclusion has changed.**

Measured, before the fix:

```
wp_insert_post returned WP_Error: invalid_page_template — Invalid page template.
(no post id was returned to the caller)

did the post row survive the WP_Error? YES — #303 status=draft
meta rows for it     : 1
```

The invalid-page-template bail at `post.php:5164-5168` returns `WP_Error`
*after* the post row, categories, tags and meta have already been written. The
caller receives no post id, so it cannot even identify what it leaked. Any
apply-loop that treats a `WP_Error` as "that step did nothing" is wrong.

### 1.7 Can content be staged without persisting posts?

This is the load-bearing question of the whole design. **Not all three of
{no persisted rows, WordPress-generated IDs, draft screenshots} are obtainable
at once.** Two are directly incompatible:

- **WordPress-generated IDs require a row.** `wp_insert_post()` derives the id
  from `$wpdb->insert_id`. There is no pre-allocation API. (`import_id` lets a
  caller *force* a chosen id, but that is caller-chosen, not WordPress-generated,
  and it is refused when the id is taken.)
- **Draft screenshots require the post to exist.** `agentshell_screenshot(post_id=N)`
  mints a credential, restores the caller's identity, and lets core's own
  `WP_Query` preview path find the post. A post that was never written cannot
  be found by a query.

So: **staged content that supports real ids and draft previews must be
persisted.** The real choice is *what kind of persistence* — and only then,
*how visible* it is.

### 1.8 What a visitor can see about a draft

Measured over HTTP against the running site, for a freshly created draft page:

| Surface | Result |
|---|---|
| `GET /?page_id=293` (anonymous) | **404** |
| `GET /wp-json/wp/v2/pages/293` (anonymous) | **401** `rest_forbidden` |
| `GET /wp-json/wp/v2/pages?search=AS-VIS-PROBE` | **0 matches** |
| `GET /wp-json/wp/v2/pages?status=draft` (anonymous) | **400** |
| `/feed/` | 200, probe **absent** |
| `is_post_publicly_viewable()` | `false` |
| post row exists in `wp_posts` | **yes** |

A draft is invisible to the public. It is **not** invisible to an authenticated
user holding `edit_posts` — it appears in the admin, in authenticated REST
queries, and to the REST search index for anyone who can read it. Any contract
must say "invisible to the public", not "invisible".

### 1.9 Two previews already exist and both must keep working

| Preview | Mechanism | File |
|---|---|---|
| Staged **config** | HMAC credential over `tx\|actor\|expires`, verified on `template_redirect` prio 0, substitutes the whole config in memory for one request | `class-transactions.php`, `functions.php`, `class-screenshot.php` |
| Staged **content** | HMAC credential over `post\|actor\|exp\|nonce`, verified on `init`, calls `wp_set_current_user()` for one request, then lets core's `current_user_can('edit_post'\|'read_post', $id)` decide | `class-post-preview.php` |

Both are bearer capabilities, both fail closed, both are proven by mutation
harnesses. Any content-transaction design must keep the `Post_Preview` contract
intact — in particular, `UNPUBLISHED_STATUSES = ['draft','pending','private','future']`
and *"WordPress keeps ownership and capabilities; nothing is bypassed."*

### 1.10 A SQL transaction around a content write — measured

`START TRANSACTION` / `ROLLBACK` around a real `wp_insert_post()` with a
category and post meta, MySQL 8.4, InnoDB:

```
post_id                : 292
open transaction       : started
visible to an independent session : 0        ← isolated
ROLLBACK
wp_posts row           : gone
wp_postmeta rows       : 0
wp_term_relationships  : 0
any probe post anywhere: 0
probe tag terms        : 0
```

And the bare form — `START TRANSACTION` alone, with no `SET autocommit`:

```
inserted #302 inside a bare transaction
visible to another session while open : 0   ← isolated
visible to this session                : yes ← the writer can still read it
after ROLLBACK, rows present           : 0
```

**Everything is InnoDB, isolation works, rollback is complete, and the writing
session keeps a consistent view throughout.** That is a real finding, and it
changes the option space.

A related, unplanned measurement: a PHP fatal error mid-`wp_insert_post` — the
connection dropped with the transaction open — left **zero** rows behind.

---

## 2. Architectural options and trade-offs

Four options, not three: the fourth (a database transaction) is the only route
to true atomicity anywhere in this list, and it is easy to miss because it
sounds too good.

### Option A — Deferred writes: buffer the intent, write nothing until commit

Stage the post *array*, not the post id. On `commit()`, replay the buffer
through `wp_insert_post()`. On `rollback()`, drop the buffer.

| Criterion | Assessment |
|---|---|
| Consistency | **Excellent.** Nothing is written, so nothing can be half-written. |
| Isolation | Trivial — there is nothing to isolate. |
| Crash recovery | **Perfect.** No residue, no reaper, no garbage collection. |
| WordPress compatibility | **Perfect.** No post exists, so no hook fires, no taxonomy is touched, no plugin callback runs, no option is written. The plugin blast radius is structurally zero rather than merely small. |
| Implementation complexity | Rollback is trivial. **Everything else is not.** |
| Draft previews | **Broken.** `agentshell_screenshot(post_id=N)` cannot photograph a post that does not exist. |
| WordPress-generated ids | **Unavailable before commit.** Every id reference — `parent_id`, nav menu `page_id`, a link from one staged page to another — must be resolved at commit time, so the buffer needs a deferred-reference resolver. |
| site-from-brief compatibility | **Fails step 14 → 18.** The skill creates pages as drafts and then screenshots them. Deferring writes removes the ability to see the work. |

**Verdict: rejected.** It cannot satisfy the existing workflow's verification
step, and that step is the one the whole preview subsystem exists to support.
Its one genuine advantage — a zero plugin blast radius — can be had more
cheaply by writing drafts, because a draft's `save_post` callbacks are already
the same ones a publish would fire.

### Option B — Immediate writes with an undo journal

Write immediately; record enough before each write to undo it; on rollback,
replay the journal backwards. This is what
`class-register-navigation.php` already does, for one call.

| Criterion | Assessment |
|---|---|
| Consistency | **Eventual, compensating.** Not atomic. |
| Isolation | None. Content is live the moment it is written. |
| Crash recovery | **Only as good as the journal's durability — and the journal must be written *before* the mutation, or the crash that loses the journal is the same crash that lost the ability to undo.** A write-ahead journal row is mandatory, not optional. |
| WordPress compatibility | Full, but every plugin callback fires and the journal cannot know what they did. Unbounded compensation surface. |
| Implementation complexity | Moderate. Proven pattern, already in this repo. |
| Draft previews | Work today, unchanged. |
| **The killer** | **The guarantee ceiling is "we undo what we recorded, best effort."** There is no honest way to call that atomic. |

**Verdict: viable, and a component of the recommendation — but never described
as a transaction.** It is a compensating-action system, and the contract must
say so.

### Option C — Hybrid: persisted private drafts plus staged configuration *(recommended)*

Every content mutation is written immediately, but **as `draft` (create) or
with its prior state journaled (update)**, and every written post is stamped
with the owning transaction id. `commit()` promotes staged content to its
requested status and writes the staged config. `rollback()` deletes the posts
this transaction created and restores the recorded prior state of the posts it
changed.

| Criterion | Assessment |
|---|---|
| Consistency | Config commit is genuinely atomic (one option row, already true). **Content commit is a sequence, not atomic — unless combined with Option D.** |
| Isolation | From the public: complete (measured §1.8). From other editors: none, and that must be stated. |
| Crash recovery | Drafts carry the transaction id and a timestamp, so they are **identifiable and reapable**. Fixes the indefinite-lock bug by fixing the lock's lifetime. |
| WordPress compatibility | Full. Ids are real and stable from the first moment. Draft previews work unchanged. |
| Implementation complexity | Moderate-high. One new journal, one new postmeta key, changes to six tools. |
| site-from-brief compatibility | Complete. Step 14 creates drafts; step 18 screenshots them; step 19 commits. |
| Plugin blast radius | Real — `save_post` fires. Mitigated by Option D and by the fact that a rollback can only undo what it recorded. |

**Verdict: recommended.** It is the only option that keeps the workflow intact.

### Option D — A database transaction around the apply sequence *(recommended as a component)*

Wrap the whole `commit()` apply sequence in `START TRANSACTION` … `COMMIT`,
with `ROLLBACK` on any failure. This is the only mechanism in the option space
that delivers genuine all-or-nothing for content.

| Criterion | Assessment |
|---|---|
| Consistency | **True atomicity for database writes** — measured, §1.10. |
| Isolation | `REPEATABLE-READ`. The writing session sees a consistent view; other sessions see the pre-commit state throughout, which is exactly right. |
| Crash recovery | **Excellent and automatic.** A dropped connection rolls back. Demonstrated in §1.10. |
| WordPress compatibility | Good, with real caveats below. |
| Implementation complexity | Low — roughly 40 lines, all in one place. |
| **What it does not solve** | It cannot span requests, so it gives **no staged visibility** and no preview. And it cannot undo a plugin that already shipped a request to an external service. |

Caveats, all of which must be enforced in code:

- **Only `START TRANSACTION` / `COMMIT` / `ROLLBACK`.** Never `SET autocommit` —
  it is a different mechanism, it interacts badly with `START TRANSACTION`, and
  `START TRANSACTION` alone is sufficient (measured).
- **Re-entrancy guard.** `START TRANSACTION` implicitly commits any pending
  transaction on the same connection. Nested acquisition must be refused.
- **Fail closed if any target table is not InnoDB.** `information_schema.ENGINES`
  must be checked at begin-of-commit, not assumed. A `MyISAM` table silently
  ignores the rollback.
- **DDL is not transactional.** Any plugin issuing `ALTER`/`CREATE`/`TRUNCATE`
  mid-sequence issues an implicit `COMMIT` and destroys atomicity. Core's post
  path issues no DDL; plugins might.
- **A persistent object cache is not transactional.** This site has none
  (`wp_using_ext_object_cache()` is false), so the in-request cache dies with
  the request. **A production site with Redis or Memcached can serve a
  rolled-back post from cache after the database has correctly discarded it.**
  A commit that rolls back must flush `clean_post_cache()` for every touched id.
- **In-request option cache survives a rollback.** `update_option()` primes the
  WP object cache; after a `ROLLBACK` the process still holds the new value.
  Any failure path must `wp_cache_delete( $option, 'options' )`.

### Comparison at a glance

| | A: deferred | B: undo journal | C: hybrid *(rec.)* | D: SQL txn *(component)* |
|---|---|---|---|---|
| Nothing written before commit | ✅ | ❌ | ❌ | ❌ |
| Content invisible to the public pre-commit | ✅ | ❌ | ✅ | ✅ |
| Content visible to the author pre-commit | ❌ | ✅ | ✅ | ✅ |
| WordPress-generated ids before commit | ❌ | ✅ | ✅ | ✅ |
| Draft screenshots work | ❌ | ✅ | ✅ | ✅ |
| site-from-brief step 14→18 works | ❌ | ✅ | ✅ | ✅ |
| True atomicity at commit | n/a | ❌ | ❌ *(unless D)* | ✅ |
| Crash needs no cleanup | ✅ | ❌ | ❌ *(reaper)* | ✅ |
| Plugin side effects undoable | n/a | ❌ | ❌ | ❌ |
| Rollback guaranteed to succeed | ✅ | ⚠️ | ⚠️ | ✅ *(DB only)* |
| Implementation cost | low to build, high to finish | moderate | moderate-high | low |

---

## 3. Recommended transaction model

**Option C for the staging model, Option D as the commit-time wrapper, and
Option B's journal as the rollback mechanism.** Each is used for what it is
actually good at, and the contract below states plainly which is which.

```
begin_transaction
  ├─ config mutations ─────────────► staged in the option row        [atomic on commit]
  └─ content mutations ────────────► written as drafts, tagged      [immediately, visible to author]
                                     with tx id in postmeta
                                     each write journals its prior state

preview_transaction / screenshot(transaction, post_id)
  └─ reads the draft + the staged config under a scoped bearer      [no writes]

validate_transaction
  ├─ Doctor::check( staged config )
  └─ NEW: replay the content buffer in dry-run mode against the
          staged state, reporting conflicts without writing

commit_transaction
  ├─ Doctor::check( staged config )                    ← fails closed
  ├─ capability + engine preflight (§6.1, §3.2.8)
  ├─ START TRANSACTION                              ┐
  ├─ promote each tagged draft to its requested status │
  ├─ restore/patch anything the buffer must rewrite    │  Option D:
  ├─ apply the navigation journal                     │  all-or-nothing
  ├─ update_option( agentshell_config, staged )  ← ONE row ┘
  ├─ write the commit audit entry
  └─ COMMIT   (or ROLLBACK, leaving config untouched)

rollback_transaction
  ├─ delete_option( agentshell_transaction )
  ├─ replay the journal backwards:
  │     created posts  → wp_delete_post( id, true )        [guaranteed: we created it]
  │     updated posts  → wp_update_post( recorded prior state )  [best effort]
  │     nav menu items → restore recorded prior state / delete created ones
  │     front-page option cascade → restore recorded values
  └─ if any step fails: DO NOT delete the journal. Mark it
     `failed` and return the exact residue.
```

**Why content is written as drafts rather than deferred entirely.** The site is
built by an agent that must *look at what it made* before committing
(`skills/agentshell/site-from-brief/SKILL.md` steps 14, 17, 18, 19). Deferring
writes removes that. A draft is invisible to the public, carries a real id, is
screenshot-able through the existing `Post_Preview`, and — crucially — is the
only representation WordPress itself understands.

**Why the commit wrapper is a SQL transaction and not more journal entries.**
The journal answers "what did we change?"; it cannot answer "did all of it
land?". Option D answers the second question for every database write, which
removes the entire class of partial-failure the journal would otherwise have to
compensate for — including the `WP_Error`-with-a-written-row case in §1.6(b),
which no journal can clean up because the caller never learns the id.

### 3.1 Commit guarantees

| # | Guarantee | Strength |
|---|---|---|
| C1 | The staged configuration is written as **one** `update_option`. Either the whole row lands or none of it does. | **True.** Already true today; preserved. |
| C2 | Content promotion and configuration write are **all-or-nothing for database writes**, when the engine preflight passes. | **True for the database** (Option D). Requires all target tables to be InnoDB. |
| C3 | `commit` is refused outright if `Doctor::check( staged )` reports errors. | **True.** Already true today. |
| C4 | Every content write is in the `agentshell_audit_log` with actor, transaction id and operation. | **True**, by design. |
| C5 | On `COMMIT` failure the transaction row **remains open**, so the caller can retry or roll back. Nothing is half-dismissed. | **True**, by design. |
| C6 | Plugin side effects outside the database (HTTP calls, custom tables via a second connection, emails, CDN purges, search indexing) are **not** covered by C2. | **Explicit limitation.** |
| C7 | Content and configuration are committed **atomically with respect to the database**, and **not** atomically with respect to any external system. | **Stated precisely.** Do not upgrade this to "atomic" unqualified. |

**C2's real boundary, stated once:** it covers rows in `wp_posts`,
`wp_postmeta`, `term_relationships`, `term_taxonomy` and `wp_options`. It does
not cover DDL, it does not cover a non-InnoDB table, and it does not survive
the request that issued it.

### 3.2 Rollback guarantees

| # | Guarantee | Strength |
|---|---|---|
| R1 | Config rollback writes nothing to `wp_posts`; the database was never touched. | **True.** Unchanged from today. |
| R2 | Every post **created** by the transaction is deleted permanently. | **Strong.** These are provably ours. |
| R3 | Every post **updated** by the transaction is restored to the exact prior row. | **Best effort.** Requires a journal entry written *before* the write. |
| R4 | Taxonomy, featured image and meta are restored for updated posts. | **Best effort**, for R3's reasons plus: meta is only partly ours (see §1.4). |
| R5 | Front-page option cascade is undone. | **Best effort**, and only if the prior values were recorded. |
| R6 | Plugin side effects are undone. | **Never promised.** Not attempted. |
| R7 | If any step fails, rollback **does not** report success. It returns the residue: what was undone, what was not, and why. | **True**, by design. See §3.3. |

#### 3.2.1 When rollback fails

This is the case the current design does not have and the one that matters most.

1. Rollback proceeds step by step, collecting outcomes rather than throwing on
   the first failure.
2. Each step is idempotent, so a retry is safe.
3. If any step fails, the transaction row is **not** deleted. It is rewritten as
   `{status: 'rollback_failed', residue: [...], attempts: n}` and remains visible
   to `agentshell_get_transaction`.
4. `agentshell_rollback_transaction` returns
   `{rolled_back: false, failed_steps: [...], residue: [...]}` and **exits
   non-zero**.
5. The failure is written to the audit log as a first-class event, not a
   suppressed warning.
6. A subsequent `agentshell_rollback_transaction` retries only the failed steps.

**Rationale.** A rollback that silently discards its journal on failure converts
a recoverable incident into permanent data loss. The journal is the only copy
of the prior state; destroying it is the one unrecoverable act available.

#### 3.2.2 Reversibility matrix

| Operation | Reversible by | Strength |
|---|---|---|
| Config / design tokens | discard the staged buffer | **Exact** |
| Create page or post (draft) | `wp_delete_post( id, true )` | **Exact** — we created it |
| Update page or post | journal of the full prior row + prior terms + prior meta | **Exact for title/content/excerpt; best effort for the rest** |
| Change status (`publish` / `unpublish`) | journal of prior `post_status` | **Exact** — the journal stores the status string, which is the one thing revisions cannot |
| Change slug | journal of prior `post_name` | **Exact** — ditto |
| Set parent page | journal of prior `post_parent` | **Exact** |
| Assign categories / tags | journal of the prior term id set per taxonomy | **Exact**, provided it was journalled before the write |
| Featured image | journal of prior `_thumbnail_id` | **Exact** |
| Arbitrary post meta | **Not reversible.** Meta is only partly written by `wp_insert_post` and otherwise by callers and plugins. | **Not claimed** |
| Navigation registration | the existing `$created_menu` / `$created_items` / `$restores` / `$locations_before` journal, lifted to the transaction | **Exact** for what it records |
| Trash a page | journal of prior status; **see §3.2.3** | **Conditional** |
| Force-delete a post | **Nothing.** Revisions are destroyed in the same operation. | **Never** |

#### 3.2.3 A defect that must be designed around, not inherited

Measured on a **published** page:

```
#300 status=publish slug='as-trash-probe-9cbc96'
permalink: https://agentshell.local/as-trash-probe-9cbc96/

after wp_delete_post() default : status=trash slug='as-trash-probe-9cbc96__trashed'
after wp_untrash_post()         : status=draft slug='as-trash-probe-9cbc96'
permalink now: https://agentshell.local/?page_id=300
=> status demoted from publish to draft; the pretty permalink did not come back
```

**`wp_trash_post()` → `wp_untrash_post()` is not a faithful restore.**
`wp_untrash_post()` restores the *slug* from `_wp_desired_post_slug`, but it
restores the *status* to `wp_untrash_post_status`, whose default is **`draft`**,
not the pre-trash status — and it **deletes `_wp_trash_meta_status`** in the
process, so the original status is then unrecoverable.

Consequences for this design:

- **Rollback must not use trash/untrash as its undo.** It must restore the
  journalled status explicitly.
- **`agentshell_delete_content` must record the pre-trash status in the
  transaction journal** before calling `wp_delete_post()`, or a rollback will
  silently demote a published page to draft.
- Also observed on a **draft**: trash sets `post_name` to `__trashed`, and
  untrash does **not** clear it, because the draft's original slug was `''` and
  `_wp_desired_post_slug` was `''`. So trash→untrash leaves `__trashed`
  permanently on a draft's slug.

Also measured: **`wp_delete_post( $id )` with default arguments trashes rather
than deletes**, whenever `EMPTY_TRASH_DAYS` is truthy (30 here). The tool must
report which happened and must never report a trash as a delete.

#### 3.2.4 What ordinary visitors can observe before commit

| Surface | Before commit | Evidence |
|---|---|---|
| Public front-end URL | **404** | measured, draft `#293` |
| Public REST by id | **401** | measured |
| Public REST search / collection | **0 matches / 400** | measured |
| Feed | **absent** | measured |
| `is_post_publicly_viewable()` | `false` | measured |
| Object cache / page cache | **untouched** — the draft is not rendered by any public request | by construction |
| Search engines | nothing to index; preview responses carry `X-Robots-Tag: noindex` | existing `TRANSACTION-PREVIEW.md` |
| **Authenticated user with `edit_posts`** | **fully visible** — admin UI, authenticated REST, `?status=draft` | WordPress's own permission model |
| **Another agent actor** | visible if it holds `edit_posts`; **mutation is blocked** by `assert_owner()` | `class-transactions.php:146` |

The contract must say **"invisible to the public"**, never "invisible".

#### 3.2.5 Concurrency

Today: **one open transaction site-wide, owner-locked.** `begin()` throws
`InvalidArgumentException` if another actor holds one; `assert_owner()` gates
`stage`, `commit` and `rollback`; `preview` and `validate` are open to any actor.

Preserved, with two changes:

1. **A TTL.** The transaction row gains `expires` (default: 3600s). An expired
   transaction is not silently ignored — it is *reaped* by the next `begin()`
   after logging it, which fixes §1.6(a). Reaping a transaction with staged
   content must first perform its rollback, so the residue is cleaned rather
   than abandoned.
2. **Content adds a second dimension.** Because staged content is persisted,
   two actors can now contend on the *same post* even though only one can hold
   the transaction. Content mutations must therefore carry an **optimistic
   concurrency check**: the journal records the post's `post_modified_gmt` at
   read time, and the write refuses if it changed underneath. Refusing is
   correct; overwriting someone else's edit is not.

#### 3.2.6 Content ids and revisions

- **Ids.** Real and stable from the moment of creation — this is the main
  benefit of Option C over Option A. Ids created in a transaction **survive
  rollback only if the post does**. A rolled-back id is burned, exactly like a
  burned auto_increment value.
- **Ownership.** Every post a transaction touches carries `_agentshell_tx_id`
  and `_agentshell_tx_actor` in post meta. On commit these are removed (or
  rewritten with a provenance value); on rollback they are how the reaper
  identifies residue without guessing.
- **Revisions.** AgentShell does **not** create its own revision rows and must
  not: WordPress already writes one per update, and a second writer would
  corrupt the restore chain. What AgentShell adds is
  - a **transaction journal**, which is strictly richer than a revision
    (status, slug, parent, terms, meta, and — critically — the *pre-write*
    state, which revisions in 7.1.3 do not hold), and
  - an audit entry stamped with the transaction id, in the same shape as
    `stamp_last_revision()` does for config today.
- **`agentshell_list_revisions` / `restore_revision` must not silently change
  meaning.** They stay config-only. Adding content history under the same tool
  names would be a silent contract change; if content history is exposed it
  needs its own surface or an explicit `scope` argument.

#### 3.2.7 Can content and configuration commit atomically?

**Yes for the database, with Option D.** Both live in InnoDB tables, so one
`START TRANSACTION` … `COMMIT` covers `wp_posts`, `wp_postmeta`,
`term_relationships` and `wp_options` together.

**The honest caveats**, restated because this is where the design is most likely
to be oversold:

- It is atomic with respect to **the database only**.
- It is **not** atomic with respect to third-party plugins holding their own
  connections, sending HTTP requests, or writing to non-transactional storage.
- It **cannot** span requests, so it provides no atomicity *during* the
  transaction — only at commit.
- It **fails closed**: if the engine preflight does not confirm InnoDB, the tool
  must refuse rather than degrade silently to best-effort.

Without Option D the answer is **no** — config would be one atomic write and
content a sequence, and calling that "atomic" would be wrong.

---

## 4. Proposed API and data structures

### 4.1 Changes to the transaction row

```php
// agentshell_transaction option, extended
array(
    'id'        => 'tx_1789…_a1b2c3d4', // CHANGED in Stage 0 — see below
    'label'     => 'brief-acme',
    'actor'     => 'agent',
    'timestamp' => 1789…,
    'created'       => 1789…,         // ADDED in Stage 0
    'last_activity' => 1789…,         // ADDED in Stage 0
    'expires'       => 1789… + 3600,  // ADDED in Stage 0 — stale reporting
    'before'    => array( … ),        // config before, unchanged
    'staged'    => array( … ) | null, // staged config, unchanged

    // NEW — content journal, write-ahead
    'content'   => array(
        'posts' => array(
            // created by this transaction
            'created' => array(
                301 => array( 'type' => 'page', 'title' => 'Home' ),
                302 => array( 'type' => 'page', 'title' => 'Pricing' ),
            ),
            // modified by this transaction
            'updated' => array(
                12 => array(
                    'prior' => array(            // full pre-write row
                        'post_title'   => 'Old title',
                        'post_content' => '…',
                        'post_excerpt' => '',
                        'post_status'  => 'publish',
                        'post_name'    => 'old-slug',
                        'post_parent'  => 0,
                        'post_modified_gmt' => '2026-10-14 16:40:00', // OCC guard
                    ),
                    'prior_terms' => array(       // [taxonomy => term_id[]]
                        'category' => array( 4, 7 ),
                    ),
                    'prior_meta' => array(        // only keys we wrote
                        '_thumbnail_id' => 88,
                    ),
                    'applied' => array( … ),      // what we actually wrote
                ),
            ),
            'trashed' => array(
                300 => array( 'prior_status' => 'publish' ),  // §3.2.3 — required
            ),
        ),
        'nav' => array(
            'created_menu'  => 5,
            'created_items' => array( 44, 45, 46 ),
            'restores'      => array( 47 => array( … ) ),
            'locations_before' => array( 'primary' => 5 ),
        ),
        'options_before' => array(   // only for keys we may disturb
            'page_on_front'  => 300,
            'page_for_posts' => 0,
            'show_on_front'  => 'page',
        ),
    ),
)
```

**Why the id changed in Stage 0.** It was `tx_` . `time()`, which is not unique:
two transactions opened in the same wall-clock second shared one. That was
previously harmless because a preview credential is bound to the transaction id
and could only be minted while its transaction was open — but Stage 0 makes
"reclaim a transaction and open another in its place" an ordinary, designed path,
and a collision there would let a URL minted for a closed transaction match a
different one. The id is now `tx_{time}_{8 hex}`. The `_agentshell_tx_id` meta
value and every message that names a transaction follow it automatically.

**Why `content` is consulted before any reclaim.** The rule Stage 0 enforces is
that a transaction holding a journal is never deleted, because for an updated
post that journal is the only copy of the prior state. `has_durable_work()`
recurses rather than testing one level: a transaction that journalled nothing
still has its bucket keys, and treating those as work would make the safeguard
permanent rather than selective.

#### 4.1a The sketch above, superseded — the schema Stage 1a actually built

The buckets in the sketch are **not** what was built, and the difference is not
cosmetic. A bag of `created` / `updated` / `trashed` lists can describe *what a
transaction did*, but recovery cannot be driven from it, because every question
recovery has to answer is per-operation: is this one still owed? who is
compensating it right now? did it already settle? was the post's evidence
checked? A bucket layout answers none of those without a second structure
alongside it — and two structures over the same facts can disagree, which is
exactly the failure a journal exists to prevent.

So the journal is a flat map of operation id → record, keyed by the same id the
post carries as its marker:

```php
'content' => array(
    'v'    => 1,                  // schema version, per journal
    'tx'   => 'tx_1789…_a1b2c3d4', // the row it must be found in; a mismatch is a problem
    'ops'  => array(
        'op_1789…_9f3c' => array(
            'op'          => 'create_draft',
            'v'           => 1,          // per-operation, so two builds cannot half-understand a row
            'state'       => 'intended', // intended | applied | compensating | compensated | conflict
            'actor'       => 'agent',
            'recorded_at' => 1789…,
            'updated_at'  => 1789…,
            'target'      => array( 'post_type' => 'page', 'post_status' => 'draft' ),
            'before'      => null,       // a creation has no pre-image; null says so explicitly
            'after'       => array(
                'post_type'      => 'page',
                'post_status'    => 'draft',
                'post_name'      => 'acme-home',
                'op_meta_value'  => 'op_1789…_9f3c',  // the marker value the post must carry
                'post_modified_gmt' => '…',            // optional OCC guard, when known
            ),
            'post_id'     => 0,          // 0 = never learned; NOT "nothing was created"
            'claim'      => null,        // { actor, at } while another caller is inside compensation
            'comp'       => null,        // { outcome, detail, at, deleted_post_id }
            'notes'      => array(),
        ),
    ),
    'signature' => '…',                 // HMAC over the serialised ops; verify-before-replay
),
```

Three decisions in there are load-bearing and the sketch gave no hint of them:

- **`op_meta_value` is the operation id.** The post's own metadata is the
  evidence that it is ours; the journal is only the claimant. When `post_id` was
  never recorded — the crash between `mark_applied()` and the row write — the
  marker is the *only* way to find the post. That inverts the sketch's
  assumption that the journal finds things.
- **`after` is mandatory, `before` is not.** Compensation deletes, so it needs
  enough to identify the object and to prove it has not changed underneath:
  `post_type`, `post_status`, `post_name`, `op_meta_value`. A record missing any
  of them fails `validate()` and is refused. A creation's `before` is `null`
  because an empty array would be indistinguishable from a lost pre-image.
- **`v` is on the operation as well as the journal.** A row written by an older
  build is read by a newer one without either pretending to understand the
  other's fields.

Only `create_draft` exists in `SUPPORTED_OPERATIONS`. Updates, navigation,
taxonomy and permanent deletion are refused by name at validation, at record
time and again inside compensation. §3.2.2 rates create-draft compensation as
*Exact* — "we created it" — and that rating is the only reason any of this can
ship; every other operation type in this document lacks the before/after
contract that would make an inverse safe to attempt.

Normative: `AGENTS.md` §6.4.

### 4.2 Post meta ownership stamp

```
_agentshell_tx_id     — 'tx_1789…'   set on write, removed on commit, drives reaping
_agentshell_tx_actor  — 'agent'
_agentshell_provenance— 'agentshell'  survives commit; explains where the post came from
```

`_agentshell_provenance` is what makes §6.3's "protection against deleting
unrelated content" enforceable: a post without it was not made by AgentShell.

**Status of these three keys: not implemented.** None is written by any tool
today, because no tool journals. What Stage 1a *did* add is a fourth key with a
different job, used by the recovery engine and by nothing else:

```
_agentshell_journal_op  — 'op_1789…_9f3c'
```

It is a per-operation ownership stamp whose value **is** the operation id, and
that is deliberate. The three keys above are transaction-scoped: every post in
a transaction would carry the same value, which is enough to say "this
transaction created it" and not enough to say "this specific operation created
*this* specific post". Recovery's hardest case — a crash before any id was
recorded — needs the second. A per-operation marker means the question "did
*operation X* happen?" is answerable from the post itself, without trusting a
journal row that may itself be stale, truncated or wrong.

The trade is that the key is per-operation rather than per-transaction, so
finding "everything this transaction created" is now a query rather than a
lookup. Nothing needs that query yet; recovery needs the narrower one, so the
narrower key won. `_agentshell_provenance` remains the right answer for the
"was this post made by AgentShell at all" question that §6.3 asks, and is still
a Stage 2 prerequisite.

The marker lookup is direct SQL against `wp_postmeta`, not `get_posts()`,
because `get_posts()` does not return trashed posts by default — so the one
case most likely to matter (a post somebody has since trashed) would be invisible
to it. Verified live: `journal-recovery-live-test.php` asserts that
`get_posts()` cannot see the trashed orphan while the marker query finds and
names it.

### 4.3 The SQL commit wrapper

```php
namespace AgentShell_MCP;

/**
 * Runs a callback inside a real InnoDB transaction.
 *
 * Refuses — rather than degrading — when the preconditions do not hold:
 * a transaction is already open on this connection, or any target table is
 * not transactional. Never uses SET autocommit.
 */
final class Db_Transaction {
    const LOCK_KEY = 'agentshell_db_transaction';   // re-entrancy guard

    public static function is_supported( array $tables ): bool;   // engines === InnoDB
    public static function run( callable $apply );                // bool committed
}
```

Contract of `run()`:

- `START TRANSACTION` (never `SET autocommit`).
- `COMMIT` on normal return; `ROLLBACK` on any `Throwable`.
- After a `ROLLBACK`, `clean_post_cache()` for every touched post id and
  `wp_cache_delete( $option, 'options' )` for every touched option — the
  in-request caches are **not** transactional and would otherwise serve the
  discarded values for the rest of the request.
- The re-entrancy guard is a static, so a nested acquisition throws rather than
  implicitly committing the outer transaction.
- DDL issued by a plugin mid-sequence would implicitly commit and destroy
  atomicity. Core's post path issues none. This is recorded as a known
  limitation (§9, item 10) rather than detected at runtime.

### 4.4 Changes to the six content tools

No signature changes — that is deliberate, so `site-from-brief` and every other
caller keep working unchanged.

| Tool | Change |
|---|---|
| `create_page` / `create_post` | write as `draft` (or the requested status) **inside a transaction**; refuse to create non-draft content while a transaction is open unless `allow_live=true`; stamp `_agentshell_tx_id`; add the id to `content.posts.created` |
| `update_content` / `update_post_content` | capture the full prior row + terms + the meta keys we touch **before** writing; write; add to `content.posts.updated` |
| `publish_content` | journal prior `post_status`; write; record |
| `unpublish_content` | same; **and** note that `draft` is not `trash` — an unpublished page still occupies a permalink identity (issue #2) |
| *(new)* `delete_content` | §4.6 |
| `get_content` / `search_content` | unchanged, except they may now accept `scope=staged` so an agent can read its own uncommitted work |

The uniform refusal outside a transaction is what issue #2 asked for and what
§3.2 depends on. Content mutations that are not journalled cannot be rolled
back, so they must not be permitted.

### 4.5 Tool response changes

Responses gain explicit truth about what happened, because WordPress's defaults
lie:

```jsonc
// create_page inside a transaction
{
  "id": 301, "status": "draft", "staged": true,
  "transaction": "tx_1789…",
  "edit_url": "…",
  "visible_to_public": false,
  "note": "Written as a draft and tagged tx_1789…. Rollback deletes it; commit promotes it."
}

// delete_content
{
  "id": 300,
  "action": "trashed",            // NOT "deleted" — see §3.2.3
  "prior_status": "publish",
  "recoverable": true,
  "reversible_by_transaction": true,
  "note": "wp_delete_post() with default arguments trashes rather than deletes."
}
```

### 4.6 `agentshell_delete_content`

```
agentshell_delete_content
  id       integer   required
  force    boolean   default false — permanent deletion; true bypasses the trash
  confirm  boolean   required true when the post is not owned by the open transaction
```

**Capabilities.** Use WordPress's own per-post meta-capability mapping, not a
flat role check:

```php
$post = get_post( $id );
if ( ! $post ) { throw; }                                  // 404-shaped, not 403 — do not leak existence
if ( ! current_user_can( 'delete_post', $id ) ) {         // maps to delete_posts / delete_others_posts
    throw new \WP_Error( 'agentshell_delete_forbidden', … );
}
```

`current_user_can( 'delete_post', $id )` resolves through `map_meta_cap()` to
`delete_post` (own content) or `delete_others_posts` (anyone else's) — which is
precisely the boundary issue #2 asked for. The MCP transport additionally
requires `manage_options` and the REST boundary `edit_theme_options`; those
already hold.

**Trash vs force.**

| | `force = false` | `force = true` |
|---|---|---|
| Calls | `wp_delete_post( $id )` | `wp_delete_post( $id, true )` |
| Effect | `post_status = trash`, slug gains `__trashed` | row, meta, terms, comments **and all revisions** destroyed |
| Recoverable | **Yes**, in principle — see §3.2.3 for why not automatically | **No.** Revisions go in the same operation. |
| Reported as | `"action": "trashed"` | `"action": "deleted"` |

`force = true` requires an explicit `confirm`, prints the post's id, type,
title and status in the confirmation, and is refused outright inside a
transaction unless `allow_irreversible = true` is passed — because a
transaction whose rollback cannot complete is not a transaction.

**Protection against deleting unrelated content.** Four independent guards,
each of which must pass:

1. **Existence is not leaked.** An id the caller may not see returns the same
   error as an id that does not exist.
2. **Transaction ownership.** Inside a transaction, deleting a post that the
   transaction did **not** create (no `_agentshell_tx_id`, or an id absent from
   `content.posts.created`) requires `confirm = true`. This is the guard that
   stops an agent that mistypes an id from deleting the user's real homepage.
3. **Provenance.** A post with no `_agentshell_provenance` and no
   `_agentshell_tx_id` is treated as human-authored content and requires
   `confirm = true`, with the response naming it as pre-existing.
4. **Structural exclusions**, refused regardless of `confirm`:
   - the front page, the posts page, and the privacy policy page
     (`page_on_front`, `page_for_posts`, `wp_page_for_privacy_policy`);
   - any post that is the `post_parent` of another post — orphans must be
     re-parented deliberately;
   - any post type outside `post` / `page` (attachments route to
     `wp_delete_attachment()` with different semantics — §1.4).

**Audit.** Every delete writes an audit entry in the existing `Store` shape —
`{timestamp, actor, operation: 'content_delete', changed, revision_id}` — plus
`id`, `action`, `prior_status`, `transaction_id`, `confirmed`. The existing
`MAX_AUDIT = 100` ring means a large cleanup can evict the entries that explain
it; **the transaction id is also stamped on the post row itself** so the record
survives eviction of the log.

**Transaction interaction.**

| Situation | Behaviour |
|---|---|
| No transaction open | Refuse. Matches the config tools and issue #2's requirement. |
| Transaction open, post created by this transaction | Delete, journal, no `confirm` needed. |
| Transaction open, post updated by this transaction | Refuse — roll back the update instead, or `confirm` to delete anyway. |
| Transaction open, unrelated post | Requires `confirm = true`; journaled as a recorded prior state so it is *undoable*. |
| Rollback after a delete | Restore by re-creating from the journal (for `force`) or by restoring the recorded prior status (for `trashed`). **Never** via `wp_untrash_post()` — §3.2.3. |
| `force = true` inside a transaction | Refuse unless `allow_irreversible = true`. |

### 4.7 A new tool, or an argument?

The brief's hard rules forbid adding tools casually ("no new MCP tools in
skills"), but the canonical count is "45+", not a fixed number — and
`delete_content` is a gap issue #2 already identified. One new tool
(`agentshell_delete_content`) is the honest shape: deletion is a distinct
capability, and overloading `update_content(status='trash')` would hide a
destructive operation behind a mutating-sounding one.

No other new tool is proposed. Everything else is an argument or a change to an
existing tool's behaviour.

**Superseded by Stage 1a, which added two.** The sentence above was true when
written and is now false, so it is corrected rather than left to mislead:

| Tool | Implemented | Why an argument would not do |
|---|---|---|
| `agentshell_get_journal` | yes | Read-only inspection of a transaction's journal: what was recorded, in what state, by whom, and whether it verifies. A journal nobody can look at is a journal that can only be resolved by running the destructive tool — which is the wrong order. |
| `agentshell_recover_transaction` | yes | Destructive, confirmed (`confirm: true`). §6.3 calls the rollback path an attack surface, and the mitigation for that is a separate, narrow, authority-checked, audited operation — not an argument on a tool whose name does not say what it destroys. |

`agentshell_delete_content` is **not** implemented and remains Stage 2. Recovery
compensates drafts a transaction created; it is not a general delete capability,
and there is no `force` argument anywhere in it that bypasses the evidence and
authority checks.

The reason these two are allowed to be new tools at all is the same reason
`delete_content` is: the canonical count is "45+", not a fixed number, and
overloading an existing tool's name with a capability that destroys things
hides the destruction behind a mutating-sounding word.

---

## 5. Failure and recovery scenarios

Each row is a scenario with the required behaviour. These become the acceptance
tests in §8.

| # | Scenario | Required behaviour |
|---|---|---|
| F1 | Content mutation outside a transaction | Refuse with a message naming `begin_transaction`. |
| F2 | `create_page` succeeds, `update_zone_slots` then fails, agent rolls back | Page deleted; config unchanged; response states both outcomes. |
| F3 | `create_page` returns `WP_Error` after a partial write (§1.6b) | **FIXED.** Every content write runs inside `Content_Write_Guard`, which opens a bounded SQL transaction and rolls back on any failure — a `WP_Error`, a `false`/`0`, or a thrown exception. Nothing is left, so nothing has to be found later. See F3 in §5.1. |
| F4 | Commit succeeds | Config committed; drafts promoted; `_agentshell_tx_id` cleared; audit stamped with tx id. |
| F5 | Commit fails midway (invalid config, engine preflight, DB error) | `ROLLBACK`; **transaction row stays open**; no drafts promoted; no residue. |
| F6 | PHP fatal error mid-apply | Connection drops → implicit `ROLLBACK` → zero residue. Demonstrated in §1.10. |
| F7 | PHP fatal error between `begin()` and any mutation | Transaction row persists with no staged content; reaped on TTL. Fixed by §3.2.5. |
| F8 | Process crashes *after* `begin()` and content was written | Drafts carry `_agentshell_tx_id`; reaper finds them, rolls the transaction back, deletes them, logs it. |
| F9 | Rollback step fails (post locked by a plugin) | Other steps still run; `rolled_back: false`; residue listed; journal **retained**; non-zero exit; retry safe. |
| F10 | Two actors race for `begin_transaction` | One wins; the other gets the existing conflict error with the owner's name and age. |
| F11 | Agent A's transaction open; agent B tries to update a post A created | B's write is refused by the OCC guard; the error names A's transaction. |
| F12 | Two agents update the same pre-existing post concurrently | Second write refused; no silent overwrite. |
| F13 | Transaction expires un-rolled-back | Reaped: rollback performed, residue cleaned, audit entry written, then `begin_transaction` succeeds. |
| F14 | Commit on a MyISAM table | **Refused** by the engine preflight. Not degraded to best-effort. |
| F15 | `delete_content` on an id the caller may not read | Error identical to a non-existent id. Existence not leaked. |
| F16 | `delete_content` on the front page | Refused regardless of `force` or `confirm`. |
| F17 | `delete_content` on unrelated content, no `confirm` | Refused, naming the post and asking for confirmation. |
| F18 | `delete_content( force = true )` inside a transaction | Refused without `allow_irreversible`. |
| F19 | Rollback after trashing a published page | Status restored to `publish` and the pretty permalink restored — **not** via `wp_untrash_post()` (§3.2.3). |
| F20 | Rollback after `force = true` | Post re-created from the journal; the **new id differs** and the response says so. |
| F21 | Preview credential used after commit | Row deleted at commit ⇒ authorisation fails ⇒ 404/refusal, never a stale render. |
| F22 | Staged content screenshot with `transaction=true, post_id=N` | Both credentials verified; staged config on staged draft; response no-store + noindex. |

### 5.0 Expired-transaction recovery — F23 to F30

Added by Stage 1a, and the only rows in this table that describe behaviour that
**exists**. The rest are still proposals. Each is a regression in
`tests/journal-recovery-test.php`; F24, F27, F29 and F30 are also measured
against a real WordPress in `tests/journal-recovery-live-test.php`.

| # | Scenario | Required behaviour |
|---|---|---|
| F23 | Transaction expires holding a journal, no recovery run | Never deleted by a later `begin()`. `begin()` refuses and names it. §6.2's rule, now non-vacuous. |
| F24 | Recovery run against an expired journalled transaction | Each operation claimed by CAS, evidence verified, draft force-deleted, operation marked `compensated`, transaction row released by a guarded delete — only after nothing is outstanding. |
| F25 | Crash after the intent swap, before `wp_insert_post()` | `post_id` is 0 and that is **not** read as "nothing was created". Marker lookup finds nothing, and nothing is deleted; the operation settles as "no evidence the write ever happened". |
| F26 | Crash after `wp_insert_post()`, before `mark_applied()` | The post is found by its `_agentshell_journal_op` marker, identified, and compensated. This is the case the sketch in §4.1 could not express. |
| F27 | Recovery repeated after completion | Second run is a success reporting nothing done, never an error that sends someone hunting; the post stays gone. |
| F28 | Crash *during* recovery; a second caller arrives | The first caller's claim is younger than 120 s and the second is refused `contended`. After 120 s the claim ages out and the second takes over — which is what makes recovery resumable rather than permanently wedged. |
| F29 | Content changed by another actor after the interrupted transaction | `post_type` / `post_status` / `post_name` / `post_modified_gmt` mismatch ⇒ **conflict**. Nothing is deleted, the post survives with its real state, and the row is *kept*. Trashing a draft counts as divergence, so a trashed orphan is found, named, and refused rather than silently removed. |
| F30 | Journal missing, corrupt, or of an unsupported operation type | Validation failure is reported by name; recovery refuses and deletes nothing. A journal that cannot be read counts as outstanding work, so the row is never discarded unread. |
| F31 | Two callers recover simultaneously | Exactly one claim lands. The loser is told `contended` and deletes nothing. |
| F32 | Unauthorised actor attempts recovery | Refused before any inspection of content; the draft survives; the journal is kept; the refusal is audited. The owner needs `confirm: true`; anyone else needs `manage_options` **and** an explicit `authorize_recovery`. |
| F33 | A new transaction is opened while recovery is in flight | The recovery's release CAS is guarded, so it cannot erase the new row. The new transaction survives intact and the recovery reports `contended`. |
| F34 | Recovery is run against a transaction holding an operation type this build cannot compensate | Refused whole. Nothing deleted, nothing approximated, and the operation type is named. |
| F35 | Any recovery, refusal or conflict | Written to the audit log, naming the actor and the role they acted under, plus each deleted post id. |

Two of these are worth stating as design positions rather than test rows, because
the alternative is tempting and wrong:

- **A conflict keeps the transaction row.** Only `compensated` counts as settled.
  Discarding a conflicted journal to free the slot would throw away the record
  that a human still has to decide something — which is the one thing §6.3 says
  must never happen quietly. The cost is that `begin()` keeps refusing until
  someone acts, and that is the intended trade.
- **Compensation deletes, it does not trash.** `wp_delete_post()`'s default
  branch trashes, and `wp_trash_post()` rewrites the slug — so a trash-based
  compensation would leave a row behind *and* break the identity the journal
  records. Verified live: a trashed row still exists and its slug has changed.

### 5.1 F3 — the partial content write, fixed at the boundary

**Status: implemented and verified.** The ruling was that F3 is a hard
prerequisite for transactional content mutations, and that it be fixed as a
focused correctness change rather than as part of the content-transaction model.

**Root cause.** `wp_insert_post()` is a sequence of independent writes — post
row, slug, categories, tags, default terms, `meta_input`, GUID, status
transition, then `save_post` / `wp_after_insert_post` — and it can return a
`WP_Error` from a branch that runs after some of them. Measured against 7.1.3,
every branch was checked rather than assumed:

| Branch | Where | Row left behind? |
|---|---|---|
| `invalid_post` | before the write | no |
| `empty_content` | before the write | no |
| `invalid_date` | before the write | no |
| `db_insert_error` | the insert itself | no — InnoDB makes a single-row insert atomic |
| `db_update_error` | the update itself | no — same, and the row pre-existed |
| **`invalid_page_template`** | **after** the row, terms and meta | **yes** |

So the residue was one specific branch rather than a general property, and it
returns an error that **never names the post it wrote**. There is no
pre-allocation API, so an id cannot be reserved in advance either; the only
post-hoc recovery is guessing from the title and the clock, which is not
identification. A caller also cannot roll back itself, because by the time it
sees the `WP_Error` the writes it would undo have already committed.

**Fix — prevention at the write boundary.** `Content_Write_Guard` wraps each
single `wp_insert_post()` / `wp_update_post()` call in a bounded SQL
transaction and rolls back on any failure. Verified on the local install:

```
wp_insert_post returned WP_Error: invalid_page_template — Invalid page template.

without the guard : post rows 1   meta rows 1   cache still serves the post
with the guard    : post rows 0   meta rows 0   get_post() false
```

The transaction is bounded by one function call — ordinary PHP request scope,
never held across an MCP call, a screenshot or agent reasoning.

Three things the first cut of the fix got wrong, recorded because they are easy
to reintroduce:

1. **A rollback alone leaves the object cache lying.** After `ROLLBACK`,
   `get_post()` still returned the post and `get_permalink()` still produced a
   `?page_id=` URL for a row that no longer existed. The same failure in a
   different costume. The guard now flushes on rollback.
2. **`START TRANSACTION` inside an open transaction commits it.** MySQL has no
   nested transactions, so a `save_post` hook creating its own post would have
   committed the outer write mid-apply. A depth counter makes a nested call
   join the open transaction instead.
3. **A non-transactional engine silently has no protection.** `ROLLBACK` is a
   no-op on MyISAM. The engine is checked once; when it is not InnoDB — or when
   the lookup cannot be answered at all, which is *not* the same as "probably
   InnoDB" — the write still happens and the tool result says it was
   unprotected. A guarantee that cannot be kept is not reported as one.

**What the guard explicitly does not promise**, and what every content tool now
returns under an `atomicity` key in its result:

```json
{ "database_transaction": true, "engine": "InnoDB",
  "on_failure": "rolled back — nothing this call wrote remains in the database",
  "not_covered": "side effects outside the database (email, files, outbound HTTP)
                  and any other plugin's database writes made during save_post,
                  which this transaction also rolls back" }
```

**A second defect found while investigating.** `create_page` with an empty title
*and* empty content wrote an untitled draft page and reported success — WordPress
stores it happily and hands back an id. Nothing identifies it in a listing, an
edit screen or a preview. `create()` now refuses it before any write, matching
core's own `empty_content` rule. A titled page with an empty body is still
allowed; the refusal is aimed at "neither field says anything".

**Tests.** `tests/content-write-test.php` (17 groups) drives the boundary with a
recording `wpdb`: statement order, the failure predicate, nesting, the three
degradation paths, and that the primitives route through the guard at all.
`tests/content-write-live-test.php` runs the real reproduction against a real
WordPress — the genuine `invalid_page_template` branch, rows read straight from
the tables, `get_post()` / `get_permalink()` checked after the rollback, the MCP
tool classes themselves, and the site left exactly as it was found.
`tests/content-write-mutation.php` removes each mechanism in turn: **22 of 22
killed**, including "no transaction is opened", "a failure commits", "a
`WP_Error` is not a failure", "a nested write opens its own transaction", "a
MyISAM table is assumed transactional", "the primitives stop routing through the
guard", and "the record claims an atomicity nobody measured".

**Still latent, and the honest limit.** F3 was reproducible against WordPress but
**not reachable through AgentShell's own arguments** — `page_template` is not a
field `create_page` accepts, and core builds its `INSERT` column set from a fixed
`compact()` list, so a plugin adding it through the late `wp_insert_post_data`
filter gets a database error rather than this branch. The live suite therefore
drives `Content_Write_Guard` directly for that case, which is exactly the boundary
that will hold when the branch stops being latent: a new content option, an
importer, or any future `wp_insert_post()` argument core validates after
inserting. **A new `create_*` field that core validates after insertion must be
checked against this list before it is added.**

---

## 6. Security boundaries

### 6.1 Capability boundary (unchanged, plus one new check)

| Layer | Requirement | Enforced by |
|---|---|---|
| MCP transport | `manage_options` | daemon / plugin bootstrap |
| REST | `edit_theme_options` | `agentshell_rest_auth_callback` (`functions.php:1603`) |
| Raw HTML content | `unfiltered_html` | WordPress `wp_insert_post` |
| **Per-post delete** | **`delete_post` for own, `delete_others_posts` for others'** | **`current_user_can( 'delete_post', $id )` — new** |
| Taxonomy assignment | `assign_terms` | `wp_insert_post` (already gates `tax_input`) |

The per-post check is the only genuinely new security surface, and it is the one
issue #2 called for. Everything else is inherited.

### 6.2 Preview credentials (unchanged, and load-bearing)

The content design must **not** weaken either bearer capability:

- **Transaction credential** — HMAC over `agentshell-tx-preview|tx|actor|expires`,
  key `AGENTSHELL_REST_TOKEN` → `AUTH_SALT` → **fail closed**. 900s. Verified
  on `template_redirect` prio 0 so `nocache_headers()` still applies. Stage 0
  added a fourth gate on top of the three in the signature: the transaction's
  **lease must still be live**, checked *before* the signature so an expired
  credential and a forged one are indistinguishable. Without it the two windows
  disagree in a specific way — a credential minted in minute 1 of a 60-minute
  lease is still inside its own 900-second window at minute 16, so the tail of
  every abandoned transaction stayed renderable to whoever still held the URL.
  The lease clock is a separate, freezable seam; the credential's window keeps
  reading real `time()`, so no test can stretch a bearer capability.
- **Post credential** — HMAC over the post/actor/expiry/nonce. Verified on
  `init` (not `template_redirect` — the main query runs before it). Restores the
  caller's identity for one request, then lets core decide. 300s.

Three rules the content work inherits and must preserve:

1. **A draft preview must never promote staged content to a public render.** The
   credential authorises *one request from the owner*; it does not change status.
2. **Staged content must be rendered under a no-store, noindex response**, for
   the same reason staged config is (`TRANSACTION-PREVIEW.md`): a shared page
   cache is free to store and replay the response otherwise.
3. **The credential is minted server-side, never accepted from the caller**, and
   is stripped from any caller-supplied URL before the audit log records it.

### 6.3 The rollback path is an attack surface

Rollback executes recorded state. That means:

- The journal is **integrity-sensitive**. It lives in an option row writable by
  `edit_theme_options`. A user who can write that option can already rewrite the
  site, so this is not a new privilege — but the journal should carry an HMAC
  over its own contents, keyed on the same secret, and rollback should **verify
  before executing**. A journal that fails its check is reported as corrupted,
  not executed.
- Journal contents are **treated as data, never as code or as paths**.
  `post_content` in a journal is HTML that will be re-written by
  `wp_update_post()`; it must go through the same escaping and capability rules
  as any other write, not through a raw `$wpdb->update`.
- Rollback does **not** re-run `save_post` with history recording enabled. It
  writes rows through the same `Content_Primitives` path with history recording
  suppressed, so a rollback cannot recursively trigger plugin behaviour that
  itself mutated state.

### 6.4 Data exposure from staging

Persisting staged content is a real change in the site's data footprint:

- A staged draft is visible to **any authenticated user with `edit_posts`** —
  including the site's human editors, who have no reason to know an agent is
  mid-build. If that matters, the status should be `private` rather than `draft`
  during a transaction; this is a product decision, flagged here rather than
  decided.
- Staged drafts appear in **authenticated REST collections** and in the admin
  list. A long-running transaction accumulates rows.
- The reaper (§3.2.5) bounds this: an expired transaction is rolled back, not
  left to accumulate.
- Nothing staged is publicly reachable, ever — §3.2.4, measured.

### 6.5 Summary judgement

- **Authentication** is unchanged: bearer credentials for previews, HTTP Basic
  or `X-AgentShell-Token` for REST.
- **Authorisation** gains exactly one new check — per-post `delete_post` — and
  four structural exclusions.
- **The journal is executable data.** Verified by HMAC before replay; written
  back through the normal content path, never raw SQL with journal-supplied
  identifiers.
- **The preview surface is unchanged in kind.** Adding staged content adds a
  second thing that can be previewed; it does not add a new authorisation
  primitive.
- **No new trust boundary is crossed by staging.** The same actor that can
  publish a page now merely does so as a draft first.
- **The residual risk is data residue, not access.** The threat model that
  matters here is "a crashed agent leaves half a site behind", not "an
  unprivileged user reaches a draft".

---

## 7. Implementation stages

Each stage is independently shippable and independently revertible. Stage 0 and
Stage 1a are done; stages 1b–6 are a proposal for review, not a commitment to
build them.

### Stage 0 — Fix the lock's lifetime *(no content work; small; do first)* — **IMPLEMENTED**

Built as specified, with three additions the investigation here did not foresee
and that only became visible once the guard itself existed:

- **Guarded compare-and-swap instead of reap-and-rollback.** The plan said "reap
  expired transactions on `begin()`, running their rollback first". `delete_option()`
  is a read-then-delete by name, so a reaper that read the row a moment ago
  deletes whatever now occupies that name — including a transaction opened in
  between. Nothing here uses `update_option()` or `delete_option()` for the row
  any more; every write is guarded on the row's current bytes, and opening uses
  `add_option()` so a lost race fails loudly. See `AGENTS.md` §6.2.
- **`has_durable_work()`.** Written now, while trivially false, so the moment a
  transaction carries a journal the guard is already consulted. Without it,
  Stage 2 would have inherited a reaper that deletes the only copy of a
  pre-image by whoever called `begin()` next.
- **An expiry gate on preview authorisation.** Not in the plan, and found by
  writing the test: the 900-second credential window and the 60-minute lease are
  different lengths, so without the second gate a credential minted in minute 1
  of a dead transaction was still good at minute 16.

Normative: `AGENTS.md` §6.2. Regressions: `tests/transaction-lease-test.php`
(36 cases, frozen clock). Mutants: `tests/transaction-lease-mutation.php` (29,
all killed).

### Stage 1 — Journal primitives *(no behaviour change to any tool)* — **PARTIALLY IMPLEMENTED (1a)**

- `Content_Journal` class: capture/apply/replay for post rows, terms, the meta
  keys we write, and the front-page option cascade.
- HMAC over the journal contents; verify-before-replay.
- *Tests:* capture fidelity, replay idempotence, tamper detection.

**Stage 1a is done, and is a deliberate subset of the bullets above.** Built:
`Content_Journal` (flat op map, schema validation, HMAC verify, `record_intent()`
/ `mark_applied()` / `claim` / `settle`), `Content_Recovery` (compensate,
conflict, resumable, audited), the two tools, and `has_durable_work()`'s
schema-aware delegation.

Deliberately **not** built: terms, the meta keys we write, and the front-page
option cascade — i.e. every operation type except `create_draft`. Those have
before/after contracts in §3.2 that would have to be *implemented*, not
assumed, and shipping a half-compensating journal is worse than shipping a
narrow one. `SUPPORTED_OPERATIONS` has exactly one entry and each refusal is by
name.

Also deliberately not built: `record_intent()` has **no production caller**. No
content tool journals. A journal record with no compensation path behind it is a
record that can never be resolved, and §6.3's whole argument is that the
unresolvable case is the dangerous one. Stage 2 is what gives it a caller.

What Stage 1a changed that the stage description did not foresee: `has_durable_work()`
gained a schema-aware branch. Pre-journal rows (a bare `content` array of
buckets) still take the original recursive path unchanged; a row carrying `ops`
and `v` delegates to `Content_Journal::has_outstanding_work()`. Without the
branch, a journal that cannot be read would look like a transaction with nothing
to do, and the next `begin()` would reclaim it — the exact silent deletion §6.2
was written to prevent.

Normative: `AGENTS.md` §6.4. Regressions: `tests/journal-recovery-test.php`
(33 cases) and `tests/journal-recovery-live-test.php` (11 assertions against a
real WordPress). Mutants: `tests/journal-recovery-mutation.php` (31: 30 killed,
1 provably equivalent and recorded as such).

### Stage 2 — Content mutations journal, and refuse outside a transaction

- The six tools journal and refuse.
- Content responses gain `staged`, `transaction`, `visible_to_public`.
- `agentshell_delete_content` lands **here**, not later, so the journal is
  exercised by a destructive operation from the first commit.
- **Behaviour change:** the six tools now require an open transaction. This is
  a breaking change for any existing caller. `AGENTS.md` §6.1 already records the
  contract it will have to obey, marked as proposed; this stage makes it true.
- *Tests:* every row from F1 to F3.

### Stage 3 — `Db_Transaction` around commit

- Engine preflight, re-entrancy guard, cache invalidation on rollback —
  **all three already exist** in `Content_Write_Guard`, which wrapped the
  single-write case in §5.1. This stage generalises them from one write to a
  whole commit: the transaction spans the configuration write *and* the content
  promotion, so a commit that fails halfway leaves neither.
- F3 is already fixed per-write (§5.1); here it stops being per-write.

### Stage 4 — Navigation joins the transaction

- Lift the existing `try/catch` compensation into the transaction journal.
  One implementation, two callers; the per-call compensation becomes a
  transaction-scoped one.
- `register_navigation` drops its `transaction_note` disclaimer once it is
  genuinely staged.

### Stage 5 — Staged content previews and reaper hardening

- `scope=staged` reads; combined `transaction=true, post_id=N` screenshots.
- A recovery *sweep* for transactions holding unrecovered work. Partly answered
  by Stage 1a: an expired journalled transaction is no longer a permanent
  refusal, because `agentshell_recover_transaction` can resolve it. What is
  still missing is the discovery — nothing lists transactions that need
  attention, and no background scheduler runs (deliberately; §6 of the brief
  forbids one). Recovery is something an actor asks for, after
  `agentshell_get_journal` shows them what is owed. Extending this to the other
  operation types is also still open, and each needs its own compensation
  contract first.

### Stage 6 — Documentation, gates, live verification

- AGENTS.md §2 (tool list) and §6 (loop semantics). **§8 is already done** —
  the fifth category was approved at review, this file is listed, and the
  remaining design records were listed at the same time.
- `skills/agentshell/site-from-brief/SKILL.md` — its failure-mode table gains
  the new cases, and step 19's rollback story becomes true.
- Live verification on LocalWP, in the style of `TRANSACTION-PREVIEW.md`'s
  hash-and-bytes tables.

### Deliberately out of scope

- **Content in the transaction's *preview* for arbitrary post types** — only
  `post` and `page` (`Content_Primitives::TYPES`).
- **Attachments.** `wp_delete_attachment()` has separate meta and child
  semantics; handling them would double the compensation surface for a case the
  brief does not raise.
- **Comments.** No comment tools exist.
- **Reversal of third-party plugin side effects.** Not attempted; stated as a
  limitation wherever it applies.
- **Making `wp_options` the source of truth for anything.** The bilateral
  registry (`AGENTS.md` §4.1) stands.

---

## 8. Executable acceptance-test plan

The repo already has the harness this plan targets: `tests/*.php` suites
aggregated by `tests/run-all.php`, mutation harnesses (`*-mutation.php`) that
prove each guard is load-bearing, and a sandbox primitive that keeps the
checkout clean. **A change that passes the tests but has no mutants is not
accepted.**

### 8.1 Unit and integration — `tests/content-transaction-test.php`

| # | Test | Asserts |
|---|---|---|
| U1 | create inside a transaction | row exists, `post_status` draft, `_agentshell_tx_id` set, id in journal |
| U2 | create outside a transaction | throws, names `begin_transaction`, **no row created** |
| U3 | update journals the prior row *before* writing | journal entry equals the row as read |
| U4 | rollback after create | row gone, meta gone, `term_relationships` gone |
| U5 | rollback after update | every prior field restored, including `post_name` and `post_status` |
| U6 | rollback after taxonomy change | prior term ids restored exactly |
| U7 | rollback after trashing a published page | status is `publish` again and the permalink is the pretty one — the §3.2.3 regression |
| U8 | commit clears ownership meta | `_agentshell_tx_id` gone, `_agentshell_provenance` retained |
| U9 | ordinary visitor during an open transaction | 404 front-end, 401 REST, absent from feed — the §1.8 measurement as a test |
| U10 | an authorised draft preview | 200, staged content rendered, `Cache-Control: no-store`, `X-Robots-Tag: noindex` |
| U11 | an unauthorised draft preview | 404/401, no staged bytes in the body |
| U12 | combined `transaction=true, post_id=N` | both credentials verified; neither alone suffices |
| U13 | expired transaction | reaped; audit entry written (**covered today** — `tests/transaction-lease-test.php`, config-only case); its *content* rolled back (**Stage 2**) |
| U14 | two actors | second `begin_transaction` throws naming the owner and the age |
| U15 | OCC guard | a write against a changed `post_modified_gmt` is refused |

### 8.2 Delete — `tests/content-delete-test.php`

| # | Test | Asserts |
|---|---|---|
| D1 | trash is the default | `post_status = trash`, response says `"action": "trashed"` — **not** "deleted" |
| D2 | force really deletes | row, meta, terms, comments **and revisions** gone |
| D3 | force requires `confirm` | refuses without it |
| D4 | front page | refused with `force`, with `confirm`, and with both |
| D5 | posts page / privacy policy | refused |
| D6 | a post with children | refused unless re-parenting is explicit |
| D7 | unrelated post, no `confirm` | refused, names the post |
| D8 | unrelated post, with `confirm` | proceeds, journalled so it is undoable |
| D9 | non-existent id vs unauthorised id | **byte-identical responses** |
| D10 | delete outside a transaction | refuses |
| D11 | rollback after trash | status and slug restored; **not** via `wp_untrash_post()` |
| D12 | rollback after force | re-created; response states the id changed |
| D13 | audit entry | written, with actor, tx id, prior status, action |

### 8.3 Database transaction — `tests/db-transaction-test.php`

The single-write half of this already exists and is already tested. Rows marked
**covered** are pinned by `tests/content-write-test.php` (boundary, via a
recording `wpdb`) and `tests/content-write-live-test.php` (real WordPress); this
stage re-runs the same properties across a whole multi-step commit.

| # | Test | Asserts |
|---|---|---|
| B1 | successful apply | commits; rows present — **covered** |
| B2 | throw mid-apply | rolls back; **zero** rows in `wp_posts`, `wp_postmeta`, `term_relationships` — **covered** |
| B3 | `WP_Error` from `wp_insert_post` with a partial write (§1.6b) | **no row survives** — the F3 regression — **covered** |
| B4 | re-entrancy | a nested `run()` joins the open transaction rather than committing it — **covered** |
| B5 | non-InnoDB table | not degraded silently: the write still happens and the result says it is unprotected — **covered** |
| B6 | `SET autocommit` never appears | asserted against the source |
| B7 | rollback cache invalidation | `get_post()` returns false after rollback — **covered** |
| B8 | crash simulation (connection dropped mid-apply) | zero residue — §1.10 measured it, not yet a test |

### 8.4 Mutation testing — `tests/content-transaction-mutation.php`

Each mutant weakens exactly one guarantee and must be killed. Minimum set:

| # | Mutant | Killed by |
|---|---|---|
| M1 | journal written *after* the mutation instead of before | U3 |
| M2 | rollback skips posts it created | U4 |
| M3 | rollback restores title/content but not status | U5, U7 |
| M4 | rollback uses `wp_untrash_post()` | U7 |
| M5 | the six tools fall back to a direct write when no transaction is open | U2 |
| M6 | `Db_Transaction` swallows the exception and commits | B2 |
| M7 | `Db_Transaction` degrades to best-effort on a non-InnoDB table | B5 |
| M8 | `Db_Transaction` uses `SET autocommit = 0` | B6 |
| M9 | journal replay trusts unverified contents | tamper test |
| M10 | `delete_content` skips the per-post capability check | D9 |
| M11 | `delete_content` allows the front page when `confirm` is passed | D4 |
| M12 | `delete_content` reports a trash as a delete | D1 |
| M13 | ownership meta is not cleared on commit | U8 |
| M14 | the reaper ignores `expires` | U13 |
| M15 | the OCC guard compares `post_modified` instead of `post_modified_gmt` | U15 |
| M16 | `delete_content( force )` permitted inside a transaction | D3 |

**Target: 16/16 killed, 0 equivalent mutants.** An equivalent mutant here
would mean the guarantee it weakens is not actually implemented, and should be
reported as a design gap rather than deleted from the harness.

### 8.5 Live verification (LocalWP, real Chrome)

The style established in `TRANSACTION-PREVIEW.md` — hashes and byte counts, not
narrative.

1. Create three pages as drafts in one transaction; screenshot desktop + mobile;
   record sha256 of each PNG.
2. Verify anonymous HTTP during the open transaction: 404 on each permalink,
   401 on each REST id, absent from `/feed/`. Record body bytes and hash.
3. Roll back. Assert zero rows for the three pages, zero `_agentshell_tx_id`
   meta rows, no audit entry claiming a surviving page.
4. Repeat with commit. Assert the pages are `publish` and reachable, and that
   the hashes of the pre-commit and post-commit renders of the *unchanged*
   parts of the site are identical.
5. Trash a published page, roll back, and assert the pretty permalink returned —
   the §3.2.3 trap, live.
6. Kill the PHP process mid-apply (Stage 3) and assert zero residue.

### 8.6 Documentation gates

Before merge, per `AGENTS.md` §9:

1. No duplicate MCP tool table outside §2 — the §4.6 tool must be listed in
   §2, not defined here.
2. No duplicate architecture diagram or operation loop — §3's diagram is a
   proposal and must not be restated as the loop; §6 remains the loop.
3. No tool-count claim other than "45+".
4. No `wp_options`-as-source-of-truth claim.
5. §2 and §6 amended in `AGENTS.md` **before** any dependent skill is updated,
   and the skill updated by reference — not by duplication.
6. ~~This document's §8 category ruled on.~~ **Done at review**: §8 gained
   category (e), this file is listed as a design record, and §6.2 carries the
   normative form of Stage 0.

---

## 9. Limitations, stated plainly

1. **Rollback of content updates is compensating action, not atomicity.** A
   restore that fails leaves the post in an intermediate state. §3.2 says "best
   effort" for a reason.
2. **Plugin side effects are not reversed.** Any `save_post` handler that writes
   outside `wp_posts` is outside every guarantee in this document. On a site
   with ACF, a page builder or an SEO plugin, this is the largest residual risk.
3. **Staged content is visible to authenticated editors.** "Staged" means "not
   public", not "not present".
4. **A SQL transaction cannot span requests.** It buys atomicity at commit and
   nothing before it. It cannot make staged content simultaneously invisible to
   other sessions and previewable by the owner — those two requirements are
   incompatible in WordPress's architecture, not merely hard.
5. **`force = true` is irreversible by construction.** WordPress destroys the
   revisions in the same operation, so there is no recovery window. The only
   mitigation is refusing it inside a transaction.
6. **WordPress revisions do not do what "revisions" suggests.** Three fields,
   post-images not pre-images, none on insert, no taxonomy, no status, no slug.
   They are not an undo journal and this design does not use them as one.
7. **Rollback recreating a force-deleted post yields a new id.** Nothing can
   restore the original auto_increment value.
8. **Recovery is a mechanism, not a guarantee.** Reclaiming a config-only
   transaction runs on the next `begin_transaction`; it is not a background
   service, so between a crash and the next begin, the row sits there — now
   visible as `stale` rather than as an open transaction. A transaction holding
   a journal is never reclaimed at all; since Stage 1a it can be *resolved* by
   `agentshell_recover_transaction`, but only by an actor who runs it, and only
   for `create_draft`. Nothing sweeps, nothing schedules, nothing notifies.
9. **The design does not cover comments, attachments, or post types beyond
   `post` and `page`.**
10. **The engine preflight checks the tables it knows about.** A plugin writing
    to a non-transactional table during `save_post` would escape it, and DDL
    would implicitly commit. This is documented, not solved.
11. **F3 is fixed per write, not per workflow.** `Content_Write_Guard` (§5.1)
    makes one content write all-or-nothing at the database boundary. It does not
    make `create_page` + `update_zone_slots` + `register_navigation` atomic
    together — that is Stage 3.
12. **A non-database transaction held by something else could be committed by
    the guard's `START`.** MySQL 8 exposes no way to ask "is this connection
    already in a transaction" — `@@autocommit` stays 1 inside an explicit
    transaction, and `INNODB_TRX` did not report this session's own on the
    verified install. The guard therefore protects against *its own* nesting and
    nothing else. WordPress core opens no transactions, so this is a plugin
    hazard, not a core one.
13. **The F3 branch is latent for AgentShell.** `invalid_page_template` is not
    reachable through the fields `create_page` accepts today (§5.1), so the live
    regression drives the guard directly. The protection is in place for when it
    stops being latent; it is not evidence that AgentShell has already been
    leaking posts.

### Limitations of the implemented journal and recovery engine

Everything above is about the design. These are about what Stage 1a actually
built, and they are the ones that matter when reading the code.

14. **It compensates drafts, and only drafts.** `SUPPORTED_OPERATIONS` has one
    entry. Updates, navigation, taxonomy changes and permanent deletion are
    refused by name — not approximated, not silently skipped. A transaction
    holding any of them cannot be recovered at all until each one brings its
    own complete before/after and compensation contract.
15. **No content tool journals yet, so nothing produces a journal.** The
    recording primitives and the recovery engine exist and are tested against
    hand-built journals and, live, against a real orphan post. That is the
    strongest evidence obtainable without a production caller, and it is not
    the same thing as evidence from production.
16. **Compensation is exact for creation and best-effort for everything else.**
    Deleting a draft we created is exact. Restoring a prior state is not
    implemented at all, so the §3.2 "best effort" caveat above applies to
    everything in this document that has not been built.
17. **A journal's HMAC is only as good as the secret it is signed with.** Where
    no signing secret is defined, `verify()` reports `unverified` with a reason
    rather than claiming the journal is intact, and recovery falls back to the
    per-post evidence checks — which are the stronger guarantee anyway, because
    they consult the WordPress objects rather than the record of them.
18. **Nothing here makes a workflow atomic.** Two content writes in one
    transaction are two journalled operations, not one atomic step. F11, F12 and
    the OCC guard for pre-existing posts are still Stage 2/3 work.
19. **Plugin side effects are still not reversed, and this is now a sharper
    gap.** A `save_post` handler that writes outside `wp_posts` produced content
    that the journal cannot see and recovery cannot compensate. Deleting the post
    does not undo what the plugin wrote. This was always true; it was tolerable
    while nothing was journalled, and it is the first thing to check before
    Stage 2 turns journalling on for a real site.

---

## 10. Before the first production tool may write a journal

Stage 1a built the mechanism. Nothing uses it. This is the list that has to be
closed first, in order, and each item names the check that would show it closed.

1. **Decide the operation set Stage 2 actually journals, and write each
   compensation contract before the code that depends on it.** For anything
   beyond `create_draft`: what the before-image is, where it is stored, what
   "the post has not changed underneath us" means for that type, and what the
   inverse operation is. An entry in `SUPPORTED_OPERATIONS` is the commit that
   makes an operation recoverable, so it must arrive with its contract.
2. **Settle `_agentshell_provenance` and `_agentshell_tx_id`.** §4.2's three
   keys are still unimplemented, and `_agentshell_provenance` is what makes
   §6.3's "is this post even ours?" enforceable at the *transaction* level.
   `_agentshell_journal_op` answers a narrower question and was not chosen as a
   replacement.
3. **Wire the recording primitives into a tool, with the crash windows
   deliberately provoked.** `record_intent()` → write → `mark_applied()` in that
   order, with the mutation refused if the intent swap did not land. Tests must
   kill the process between each pair, not merely mock the gap.
4. **Prove the plugin side-effect gap on a real site** (limitation 19). If a
   `save_post` handler writes outside `wp_posts`, decide explicitly whether
   AgentShell refuses on such a site, or documents the gap and accepts it.
   Do not discover it during a recovery.
5. **Extend the OCC guard from the journal to live writes.** F11/F12 — an actor
   updating a post another transaction owns — is not implemented and is not a
   journal concern. Journalling without it would record an intent to modify
   something that had already moved.
6. **Decide the concurrency story for two *writers*, not two recoverers.** The
   claim CAS serialises recovery. Nothing serialises two tools inside one
   transaction, and nothing stops an agent outside the transaction from editing
   a draft between intent and write. The evidence checks make that a *refusal*;
   they do not make it *impossible*.
7. **Re-read `AGENTS.md` §6.1 and make the proposal there true.** It currently
   describes the contract content tools will obey and marks it as proposed.
   Turning Stage 2 on makes it normative, which is a separate, explicit act.
8. **Only then** add operation types, and only in commits that bring their own
   before/after contracts and their own recovery regressions.

---

## Related

- [`PRODUCT-ISSUES.md`](./PRODUCT-ISSUES.md) — issue #8 (this work) and issue
  #2 (the delete tool).
- [`TRANSACTION-PREVIEW.md`](./TRANSACTION-PREVIEW.md) — the staged-config
  preview credential this design extends to content.
- [`DRAFT-CAPTURE-DESIGN.md`](./DRAFT-CAPTURE-DESIGN.md) — the draft preview
  credential this design depends on.
- [`../AGENTS.md`](../AGENTS.md) — the authoritative contract. §2 tool surface,
  §6 operation loop, §8 documentation rules.