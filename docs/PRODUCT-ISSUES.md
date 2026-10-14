# Product issues — recorded, not fixed

Two defects found while building
[`TRANSACTION-PREVIEW.md`](./TRANSACTION-PREVIEW.md). Neither is in scope for
that work and neither is fixed here; both are recorded so they are not
rediscovered later.

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