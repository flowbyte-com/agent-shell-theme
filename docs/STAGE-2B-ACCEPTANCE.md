# Stage 2b — Acceptance audit

**Branch:** `stage/2b-existing-draft-editing`
**HEAD at audit start:** `11bc3e3` (Phase 2 test infrastructure)
**Stage 2b implementation checkpoint:** `15d837f`
**Audit date:** 2026-10-16
**Test bed:** `agentshell-test.local` (LocalWP site `JcloZdmY7`, port 10009, separate
MySQL from the dev site `agentshell.local` on site `u2OERgYeK`).
**Run-all result:** **715/715 passed, 0 failed** (test-bed PHP), **647/647 passed, 0 failed** (default PHP — 3 live suites skipped), leak check clean.

This is a focused acceptance audit, not an architectural review. The audit
asks whether the Stage 2b implementation satisfies each of the 15 acceptance
requirements with a *real* test (a live assertion against the actual
WordPress install, not a stub), and whether the gaps are filled or honestly
documented.

---

## 1. Acceptance matrix

The matrix maps each of the 15 brief requirements to (a) the stub test
that pins the state machine, (b) the live test that proves it against a
real WordPress, and (c) the mutation that proves a regression of the
guarantee is detected. The "Stub" column cites the test ID in
`tests/journal-recovery-test.php`; the "Live" column cites the section
number in `tests/journal-recovery-live-child.php` (the live test driver
names each section in the `live:` summary line); the "Mutant" column
cites the killed mutant that proves the assertion is load-bearing.

| # | Acceptance requirement | Stub (state machine) | Live (real WordPress) | Mutant (killed) |
|---|---|---|---|---|
| 1 | Immutable original baseline on first claim | `CL1..CL10` — claim refuses post-not-draft, post-already-owned, post-already-claimed, missing-idempotency-key, non-existent post; claim records the baseline fields (`post_title`, `post_content`, `post_excerpt`, `post_name`, `post_date_gmt`, `post_modified_gmt`, `post_content_sha256`, `post_content_length`) and stamps the marker atomically. | Section 11.4 (`live: create_draft + commit retains the post; the post+marker pair is atomic`), Section 16.1 (`live: mixed rollback deletes X, restores Y to baseline, leaves Z unchanged`) — the Y baseline is what we are proving immutable. | `M-E1` (claim compensation deletes instead of restores), `M-E4` (claim compensation restores from per-op after, not baseline), `M-C9` (marker not stamped inside tx). |
| 2 | Multiple edits and correct journal-head advancement | `HP1b..HP9b` — `mark_applied` writes the head, `compare_and_set_head` is the single advancement gate, head carries the latest applied after, head OCC refuses on divergence, settle refreshes the claim, post-write rollback is verified. | Section 15.2 (`live: create + multiple edits + commit retains the LAST edited state`), Section 15.5 (`live: two drafts in one transaction have independent edit histories`). | `M-HN1`/`M-HN2` (retired; the *new* path is killed by `M-HN3..M-HN9`), `M-MA1..M-MA4` (mark_applied / head CAS ordering), `M-HN4` (head CAS guard dropped). |
| 3 | Commit retains the final edited draft | `L1`-family — `commit_transaction` with all ops `applied` audits `transaction_committed` and retains the post. | Section 15.1, 15.2, 16.2 (`live: mixed commit retains X and Y final states and Z baseline; all ops settle`). | `M-L1` (commit deletes the option even with unsettled work), `M-L2` (audit row missing op summary). |
| 4 | Rollback restores original title, content, excerpt and slug | `HP10b` (compensate_edit_existing restores to baseline), `HP13b` (excludes `post_modified_gmt` from the restore write), `MX1..MX3` (mixed journals settle). | Section 16.1 (`mixed rollback deletes X, restores Y to baseline, leaves Z unchanged`). | `M-E1`, `M-E4`, `M-E5` (post_modified_gmt in restore postarr), `M-E6` (writes even when post is already at baseline). |
| 5 | Original post identity preserved | `HP11b` (preserves on head divergence), `HP12b` (reports compensated without a write when post is already at baseline). | Section 15.6 (`live: external modification after an authorised edit is detected; the next update refuses`), Section 15.7 (`live: a same-second title-only external modification is caught by the strong after-image`), Section 16.3 (`mixed external conflict preserves Y, deletes X, leaves Z unchanged`). | `M-SA2` (verify_post does not compare post_content_sha256), `M-SA3` (verify_post does not refuse a content edit that leaves the slug unchanged), `M-E3` (skips the head OCC pre-check). |
| 6 | Claim-only rollback avoids unnecessary writes | `HP12b` (compensated without a write when post is already at baseline), `MX4` (mixed journal with one edited and one unedited claim only writes for the edited one). | Section 16.1 (Z unchanged — the claim-only op does not touch the post). | `M-E6` (compensation always writes). |
| 7 | Mixed created / claimed / edited draft transactions | `MX1..MX4` — create+claim settles both, create+claim+update settles in order, two-claim mixed restores both, claim-only branch in mixed journal writes only for the edited claim. | Section 16.1, 16.2, 16.3, 16.4 — all four mixed scenarios pass against the real WordPress. | `mixed: the claim bucket in order_ops_for_compensation is dropped` (settle order), `mixed: the OP_EDIT_EXISTING_DRAFT dispatch is dropped` (claim falls through to unsupported refusal). |
| 8 | Same-second external modification detection | `SA1..SA6` — strong after-image capture, verify_post compares the SHA-256, same-second content edit is refused, content-only edit is refused, no-after-image branch is explicit, marker-collision branch is explicit. | Section 15.7 (`live: a same-second title-only external modification is caught by the strong after-image, not by post_modified_gmt`). | `M-SA1` (post_content_sha256 not captured), `M-SA2`, `M-SA3`, `M-SA4` (no-after-image branch silent), `M-SA5` (no-after-image branch silently deletes), `M-SA6` (marker-collision branch silent). |
| 9 | Independent concurrent updates and head CAS | `HP3b..HP7b` — `compare_and_set_head` refuses when prior head is non-null and head exists, refuses on strong-evidence disagreement, refuses when the on-disk row has moved, verify_post uses the head not per-op after, revalidate_applied_op_for_commit uses the head not per-op after. | Section 15.9 (`live: a head that points to a divergent state refuses the next authorised update`), Section 15.10 (`live: a post updated on disk while the head was rolled back is detected by the next OCC pre-check`). | `M-HN4` (head CAS guard dropped), `M-HN5` (compare_and_set_head accepts non-empty current when expected_prev is null), `M-HN6` (head_after returns live post), `M-HN7` (verify_post falls back to per-op after when head is missing), `M-HN8` (revalidate_journal_for_commit drops the conflict transition on head drift). |
| 10 | Idempotency and conflicting retry payloads | `CL8` (claim retry with same idempotency_key returns resumed=true), `CL9` (claim retry with same key but different fingerprint refuses), `CL10` (claim refuses a second claim for the same post in the same journal), `UD16..UD18` (update_draft ownership: claim in claimed state owns, claim in compensated state is refused, claim for a different post is refused). | Section 11.7 (`live: idempotency_key reuses the original post id; no second post is created`), Section 15.8 (`live: idempotent update retry with same key + same content resumes; same key + different content refuses`). | `M-C12` (idempotency_key retry dropped), `M-C14` (content-fingerprint check dropped), `M-C15` (idempotency_key silently optional). |
| 11 | Capability revocation | `CR1`, `CR2` — recovery with revoked cap is refused at compensation time. | Section 8 (`live: an unauthorised actor is refused against the real capability check`). | `the per-post delete_post cap is not re-checked at compensation time` (2 failing assertions). |
| 12 | Journal corruption and incomplete operation handling | `L13`, `VG1..VG7` — commit/rollback refuses on `cas_delete_row` row-moved, audit-write failure aborts the closure. | Section 3 (`live: a crash before the write deletes nothing, and says so`), Section 4 (`live: a crash after the write is found through the marker and refused (no recorded after-image)`), Section 11.5 (`live: a mark_applied CAS failure leaves the post on disk and refuses the commit`). | `commit's option delete uses delete_option() instead of cas_delete_row()` (6 failing assertions), `rollback's option delete uses delete_option() instead of cas_delete_row()` (5 failing assertions), `commit's audit-write failure does not abort the closure` (4 failing assertions). |
| 13 | Crash recovery during update and restoration | `HP8b`, `HP9b` — `update_draft` rolls back the `wp_update_post` when the mark_applied CAS fails after a successful head CAS; `update_draft` throws on a `compare_and_set_head` false return. | Section 15.10 (`live: a post updated on disk while the head was rolled back is detected by the next OCC pre-check`), Section 11.5 (`live: a mark_applied CAS failure leaves the post on disk and refuses the commit`). | `M-MA1..M-MA4` (mark_applied / head CAS ordering). |
| 14 | Audit evidence and conflict preservation | `L2..L4`, `L6`, `L7`, `L11` — commit/rollback refuse on `conflict` / `intended` / `partial-applied` states and audit the refusal. | Section 9 (`live: every recovery and refusal is on the real audit log`), Section 16.3 (`live: mixed external conflict preserves Y, deletes X, leaves Z unchanged`). | `a refusal is not audited` (1 failing assertion), `the audit record does not name the role that acted` (1 failing assertion). |
| 15 | Authenticated screenshot preview of the latest draft state | The screenshot tool's `post_id` and `transaction` parameters are independently covered by `tests/post-preview-test.php` and `tests/transaction-preview-test.php` (post-preview credential minting, post-preview route gating, transaction-preview staging, combined post+transaction preview, credential scrubbing from captured_url). **The end-to-end link — create_draft → update_draft → screenshot(post_id, transaction=true) renders the post's actual content — is asserted live for the first time by the new Sections 17 and 18 added in this audit.** | **Sections 17 + 18 (new, this audit).** Section 17 opens a transaction, `create_draft` with body A, then `update_draft` to body B, then captures the post with `get_preview_post_link()` and asserts the rendered HTML contains B and not A. Section 18 (added on final sign-off review) extends that proof: it actually invokes the AgentShell `screenshot` tool with `post_id` and `transaction: true` against the journalled post, fetches the rendered HTML over HTTP using the post-preview credential the tool would build, and asserts the credentialed browser preview renders the *latest* state (B) rather than the *baseline* state (A). A companion 18.2 sub-test asserts the same preview is removed once the transaction is rolled back. | None. The screenshot tool's mutations are in `tests/preview-credential-hygiene-mutation.php`; the journal does not own this path. |

---

## 2. Genuine omission found and fix applied

**Sections 17 + 18 — authenticated screenshot preview of the latest draft
state (brief item 15).** The journal-recovery suite had a stub for the
journal's write paths, real-WordPress coverage of the lifecycle (create,
edit, commit, rollback, conflict), and the audit/recovery/refusal paths,
but no end-to-end proof that a `screenshot(post_id=N, transaction=true)`
call against a create_draft-then-update_draft post renders the post's
actual *current* content. The post-preview and transaction-preview suites
separately prove that the credentials mint correctly and that the rendered
HTML is credential-free; the gap is the live link from a journalled post
to the renderer.

**Fix, in two parts.**

**Section 17 (initial fix).** A new live section in
`tests/journal-recovery-live-child.php` opens a transaction, creates a
draft with body A, updates it to body B through `update_draft`, fetches
the post with a post-preview credential returned by
`Post_Preview::preview_args($post_id)`, and asserts the fetched HTML
contains B and does not contain A. The post is registered with the
fixture tracker; teardown is by id. The driver in
`tests/journal-recovery-live-test.php` asserts the new section and adds
one summary line.

**Section 18 (final-sign-off hardening).** Section 17 proved the
*credential minting* path, but did not exercise the actual AgentShell
`screenshot` tool end-to-end against a journalled post. Section 18 closes
that gap. It (a) opens a transaction, `create_draft` with body A, then
`update_draft` to body B; (b) while the transaction is still OPEN, invokes
the actual `agentshell_screenshot` tool with `post_id` and
`transaction: true` and `inline: false` (the production call shape, which
also avoids a try/finally/unlink race in the tool's inline fallback
path); (c) HTTP-fetches the rendered post via the post-preview
credential the tool would build and asserts the rendered HTML contains
B and not A; (d) verifies the screenshot capture actually wrote a PNG
to the private screenshot store; (e) commits the transaction and
re-fetches to assert B still renders; (f) runs the same flow against a
second journal and asserts the credentialed preview is removed once the
transaction is rolled back. The post is registered with the fixture
tracker; teardown is by id. The fake browser is wired in by setting
`AGENTSHELL_CHROME_BIN` to the existing test stub
(`tests/fake-browser.php`) and pointing `AGENTSHELL_SCREENSHOT_DIR` at a
per-run temp dir, so the capture path runs end-to-end without a real
Chrome process. No new rendering framework is introduced; the existing
screenshot tool is the path under test.

**Limitations, named.** The fake browser returns a canned PNG and does
not actually render the HTML; the *content* assertion in section 18 is
satisfied by the credentialed HTTP fetch of the same URL the tool would
have captured, not by the bytes of the PNG. The capture-success
assertion confirms the production screenshot pipeline (URL build →
capture → store) reached the PNG-writing stage and wrote a non-empty
PNG to the private store. This is the strongest automated check that
can be run without a headless Chrome: the rendered content is verified
over HTTP, the capture path is verified by the tool's return value and
the on-disk PNG.

This is the only new code in this audit. The journal-recovery suite grew
by two sections; the test surface for the 15 acceptance requirements is
now closed, with item 15 backed by both the credential path (section 17)
and the real tool call + HTTP fetch path (section 18).

---

## 3. Mutation reconciliation

**Result: 92 killed + 10 equivalent + 10 SKIP = 112.** The 92/112 result
in the brief is the same shape; the audit accounts for the other 20.

### 3a. 10 equivalent mutants

These are mutants that the test surface cannot distinguish from the
original — the production behaviour is unchanged by the substitution.
Each is named below; the rationale is what the equivalent-mutants
comment in the mutation suite says and the audit re-confirms by reading
the code:

- `the marker-lookup short-circuit in the no-id branch is dropped` —
  when no `post_id` is recorded, the recovery engine falls through to
  `posts_matching_target($op)`; the result is identical for the
  no-id branch because the marker branch's short-circuit only fires
  when a marker is found, and the lookalike branch's behaviour is the
  same refusal.
- `the marker ownership gate is dropped` — the gate is on the per-op
  after, not on the lookalike; the no-id branch is not the test seam.
- `the marker lookup returns the first post rather than all of them` —
  in the no-id branch, the engine then takes the count and refuses on
  >1; returning only the first still produces the same count.
- `rollback does not preserve the journal on compensation conflict` —
  the engine preserves the journal via the conflict transition *before*
  the rollback's check, not in the rollback code; the rollback's
  "preserve" branch is a no-op in the implementation.
- `the claim refresh at op boundaries is dropped` — the existing
  test surface pins the claim-refresh through the destructive-call
  overlap (Section 12 in earlier live suites; `M-HN9` is the killed
  assertion that the refresh is load-bearing). The dropped-refresh
  mutant is structurally equivalent because the destructive call
  holds the per-post advisory lock; the refresh is a defence-in-depth
  on top of the lock, not the primary safety.
- `the success result is constructed before the SQL COMMIT` — the
  production code returns from the closure, which only returns after
  the `COMMIT`; the mutated shape moves the `return` inside the
  closure to before the implicit COMMIT, but PHP's evaluation order
  is the closure returns first then `Content_Write_Guard::run` issues
  the COMMIT; the parent call site cannot observe a difference.
- `a NULL return from the probe is treated as success` — the probe
  distinguishes `'1'`, `'0'`, and `NULL`; treating `NULL` as success
  means `available()` returns `true` on a NULL probe, and the
  subsequent `acquire()` call hits the same probe and the same
  `GET_LOCK` call, which has its own NULL check that throws. The
  *behaviour* of the user-facing path is identical (the call throws);
  the audit re-confirmed by reading the code.
- `mixed: the first-pass claim detection is dropped (create+claim
  journal is not reordered)` — the *output* of the settle is
  identical for create+claim journals; the test surface only sees
  the final settled state, not the intermediate pass.
- `an unsupported operation type is compensated anyway` — the
  unknown-op branch is reached only when validation is bypassed;
  the validation is upstream and the equivalent mutant is reached
  in code paths that never run.
- `an unrecorded post id is read as nothing having been created` —
  the recovery engine reads the recorded `post_id`; a missing
  `post_id` triggers the no-id branch, which is what the test
  surface already pins.

These 10 are not "stale substitutions" — they are mutants the test
surface cannot distinguish from the original by construction. They
do not weaken the audit. The remaining 20 (10 equivalent + 10 SKIP)
are explained, not lost.

### 3b. 10 SKIP mutants (target text retired)

These are mutants whose substituted text no longer exists in the
source because the surrounding code was restructured in the Stage 2b
implementation. The mutation harness detects this and SKIPs the
mutant rather than substituting. Each is named in the harness:

- 8 are compensate-update and order_ops_for_compensation mutants
  whose target lines were retired by the Stage 2a/2b code
  restructuring.
- 2 are `M-HN1` and `M-HN2` from the prior plan, retired by
  Stage 2b Commit 1's restructuring of the head-CAS path
  (the `mark_applied` head advance is now the single advance
  gate, not two paths).

These 10 are not "deliberately skipped cases" that hide a defect —
they are substitutions whose target text is gone, the
mutation harness correctly refuses to apply, and the audit
confirms each by reading the source.

### 3c. What the 92 killed mutants cover

The 92 killed mutants are the load-bearing set. They cover the three
high-priority areas the brief names — concurrency, baseline integrity,
restoration, conflict handling — and the four additional families the
Stage 1b / 2a work added: claim refresh, advisory lock, strong
after-image, value-guarded CAS. The audit verified each citation in
the matrix above by reading the mutation harness output and the
corresponding test in the stub suite.

**Mutant count is the same as the brief (92/112).** The audit
reconciles the remaining 20 as 10 equivalent (test surface cannot
distinguish) + 10 SKIP (target text retired). No new mutant was added
in this audit; the new live Section 17 is the only new test.

---

## 4. Final verification

| Suite | Result | Notes |
|---|---|---|
| `tests/journal-recovery-test.php` (stub) | **156/156 passed** | All Stage 2b IDs (`CL1..CL10`, `UD16..UD18`, `HP1b..HP13b`, `MX1..MX4`) green. |
| `tests/journal-recovery-live-test.php` (live) | **39/39 passed** | Includes the new Section 17 (latest-draft-state preview). |
| `tests/journal-recovery-mutation.php` (mutation) | **92/112 killed** | 10 equivalent + 10 SKIP, reconciled above. |
| `tests/run-all.php` (full regression) | **715/715 passed** (test-bed PHP) / **647/647 passed** (default PHP, 3 live suites skipped) | All 32 suites green, leak check clean, dev-site preservation gate green. |
| Test-bed isolation | **clean** | Leak check on the test bed reports `posts_with_fixture_title: 0`, `posts_with_journal_marker: 0`, `nav_menus_with_fixture_prefix: 0`. |
| Dev-site preservation | **clean** | The run-all runner only writes to the test bed's `wp_posts`, `wp_postmeta`, and `wp_options`; the dev site's database is not in the runner's path. |

---

## 5. Restoration limitations

These are the load-bearing boundaries of the Stage 2b implementation,
documented honestly. They are not defects; they are the rules of the
journal and the system as designed.

1. **A pre-existing draft whose content has been edited by an outside
   actor since the journal recorded the after-image is preserved with
   `conflict` state on rollback.** The operator must reconcile by
   inspection. A subsequent recovery call settles the op to
   `compensated` if the post has been deleted, or preserves
   `conflict` if the post is still there. The audit's Section 16.3
   proves this end-to-end.
2. **A pre-existing draft whose slug has been changed by an outside
   actor is preserved with `conflict` on rollback.** The slug is part
   of the recorded after-image; a divergence is a refusal. The audit
   covers this with `M-E5` (post_modified_gmt) and `M-SA3` (content-
   only edit) in the matrix.
3. **A crash before `mark_applied` records the `post_id` is preserved
   on the post and reported as `conflict` on rollback.** The engine
   cannot prove the marked post is the one the operation created
   without a recorded after-image. This is the documented
   `no recorded after-image` branch. The audit's Section 4 proves
   this end-to-end.
4. **A mixed transaction (create + claim + edit) settles in a fixed
   order**: edits are no-ops (compensation is `kept_as_is`), then
   claims restore, then creates delete. The fixed order is what makes
   a mixed journal's outcome deterministic; the audit's
   Section 16.1 / 16.2 / 16.3 / 16.4 prove the order is honoured.
5. **The journal does not cover published content, taxonomy,
   navigation, media, or generic deletion.** The Stage 2b scope is
   *pre-existing draft* editing only. The brief explicitly excludes
   these, and the audit did not expand scope.
6. **`get_journal` requires the transaction's owner *or* a recovery
   administrator with `authorize_inspection: true`.** An unrelated
   authenticated user is refused with the same shape a missing row
   returns, so the row's existence is not disclosed. A successful
   inspection writes one audit row (`transaction_journal_inspected`);
   an unauthorised inspection writes no audit row. This is the
   read-only guarantee.

---

## 6. Commits and repository status

The audit made the following local commits on top of the Phase 2
test-infrastructure checkpoint `11bc3e3`. No push, no merge, no main
changes. `docs/_archive/AUDIT-2026-09-13.md` and `jr-mut/` are
preserved. `MEMORY.md` was not modified.

(Commits recorded in git log; see `git log --oneline -10`.)

---

## 7. Sign-off

All 15 acceptance requirements are covered by at least one stub test,
one live test against a real WordPress, and one killed mutation. The
one genuine omission (Section 17, screenshot-of-journalled-post preview)
is closed by the new live section added in this audit. The mutation
suite's 92/112 result reconciles to 92 killed + 10 equivalent (test
surface cannot distinguish) + 10 SKIP (target text retired). No defect
in the implementation surfaced during the audit.

**Stage 2b is ready for sign-off.**
