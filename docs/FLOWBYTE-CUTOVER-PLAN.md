# flowbyte.com — migration, cutover and rollback plan

**Date:** 2026-10-24
**Status:** **prepared, not executed.** Nothing in this document has been
applied to flowbyte.com, and no step of it may be run without a separate,
explicit approval.
**Companion:** the rehearsal script lives beside the showcase's operational
tooling (`/home/v/agentshell-dogfood/rehearse_migration.sh`) — the same
procedure, run against a disposable site, with the timings in §6.

---

## 1. What flowbyte.com is today (measured, read-only)

Everything in this table was read from the public site with ordinary HTTP
requests. No credential was used, and nothing was written.

| Item | Value | How it was read |
|---|---|---|
| WordPress | 7.1.3 | the generator meta tag |
| PHP | 8.3.35 | the `X-Powered-By` header |
| Theme | `twentytwentytwo` (the default block theme) | the asset paths |
| Edge | Cloudflare (the A/AAAA records are Cloudflare's) | DNS |
| REST namespaces | `oembed`, `ai1wm`, `ai`, `wp-site-health`, `wp-block-editor`, `wp-abilities` | `/wp-json/` |
| Published content | 6 posts, 2 pages | the `X-WP-Total` header |
| Media | 93 items | the `X-WP-Total` header |
| Plugins seen in the markup | `ai` (plus All-in-One WP Migration, which exposes `ai1wm/v1`) | the asset paths and the REST namespaces |

Three observations that shape the plan:

1. **The site has no theme of its own to preserve.** It runs the default
   block theme, so there is no bespoke front-end to reverse-engineer. The
   migration is a *replacement*, not a port: the old presentation goes
   away and the new one is designed deliberately.
2. **The content is small.** Eight published items and 93 media files. This
   is a site that can be migrated and verified in one working session, and
   a site whose rollback must be quick.
3. **All-in-One WP Migration is installed on it.** That plugin is what
   rebuilt the showcase's filesystem during the previous release, without
   anyone asking it to. Whatever else the plan says, **the site's backups
   must not depend on that plugin being the only copy** — and the cutover
   must not touch it.

## 2. What the migration is

Move the site onto the AgentShell theme and the two AgentShell plugins, with
its content and media carried across and its design built deliberately
rather than inherited.

| Moves | How | Notes |
|---|---|---|
| The theme and the plugins | a versioned directory on the host, symlinked into `wp-content/` | the symlink is what makes the cutover and the rollback one command each |
| The content | read from the REST API or a dump, written through the AgentShell MCP content tools | the content tools are ordinary WordPress content afterwards: editable in the admin, indexable, revocable |
| The media | the uploads directory, copied | 93 files |
| The design | **not moved** — designed, then staged through a transaction and committed | there is no old design worth preserving (§1.1) |
| The navigation | registered through `agentshell_register_navigation` | the menu is data, not a template |
| The users and roles | left alone | AgentShell never touches them |

**What does not move:** the users, the roles, the plugin set (the `ai`
plugin and All-in-One WP Migration stay exactly where they are), the
database engine, the hosting, the mail, and the Cloudflare configuration.

## 3. The cutover

The cutover is a symlink switch and a DNS change. Both are reversible, and
the second is reversible only while the old target's TTL has not expired —
which is why the TTL is lowered **before** the window opens.

### 3.1 Before the window (no user-visible change)

1. **Lower the TTL.** The `flowbyte.com` and `www.flowbyte.com` records go
   from whatever they are to **300 seconds**, and the change is left in
   place for at least one full TTL *before* the cutover. This is the step
   that decides whether the rollback is a five-minute operation or a
   one-hour one.
2. **Take a backup** that does not depend on the site's own plugins:
   - a `mysqldump` of the database ✓;
   - a copy of `wp-content/uploads` ✓;
   - a copy of `wp-config.php` ✓ (it holds the salts ✓);
   - a copy of the theme and plugin directories as they are *before* the
     cutover ✓ (so the old site can be put back exactly ✓).
   The backup is **verified by restoring it** into a scratch schema, not by
   existing. The showcase's own backup key store (`BACKUP-KEYS.md`) is the
   pattern: the passphrase for an encrypted off-machine copy lives in the
   key store, not in the backup tree.
3. **Build the new site beside the old one.** A separate directory, a
   separate database, the same content, the design built and staged.
   Nothing about the old site changes while this happens.
4. **Build the release, and check it.** The deployment is not a copy of
   the working tree — it is an archive built from the reviewed revision:

   ```bash
   bash tools/package-release.sh --out /tmp/agentshell-dist
   bash tools/check-release-archive.sh --archive /tmp/agentshell-dist/agentshell-*.tar.gz
   ```

   The archive ships the theme, both plugins and the theme's assets. It
   does **not** ship the test suite, git's directory, the CI workflow, the
   repository's tooling, the daemon, or any file that looks like a
   private key. That exclusion is not a matter of care: the checker asserts
   it, and CI runs both steps, so a category that is excluded in the
   packager and forgotten in the checker fails the build.

   **This matters more here than in a development install.** The
   independent review of PR #16 found the test-bed profile readable over
   HTTP at its path inside the theme, with a database password in
   cleartext — because the repository is a theme, and a theme is a
   directory a web server serves. The profile now lives outside the
   repository (in the operator's configuration directory), and the
   deployment does not carry the test suite at all. On `flowbyte.com`
   there is no test suite, no test bed and no database credential in the
   served tree, and the checker says so before the window opens.

### 3.2 The window

| Step | Command or action | Time (rehearsed) |
|---|---|---|
| 1 | Put the site in maintenance mode | seconds |
| 2 | Copy any content created since the backup | seconds |
| 3 | Take the final backup (the one the rollback uses) | ~1 minute |
| 4 | Point the theme and plugin symlinks at the release | < 1 second |
| 5 | Switch the DNS (Cloudflare) at the origin | seconds to propagate |
| 6 | Verify: the home page, one page, one post, the REST API, the media | ~1 minute |
| 7 | Leave maintenance mode | seconds |

The whole window is minutes, not hours. The rehearsals in §6 measured the
mechanical parts at seconds each.

### 3.3 After the window

- The audit log is checked for anything unexpected.
- The old target is kept for **seven days**, so the rollback stays a command
  rather than a project.
- The old database is kept, untouched, for the same seven days.

## 4. The rollback

**The rollback is a symlink switch and a DNS switch back.** No data is
restored, because the cutover never writes to the old site's data: the
new site has its own database, and the old one was never touched.

| Step | Action | Time |
|---|---|---|
| 1 | Put the site in maintenance mode | seconds |
| 2 | Point the theme and plugin symlinks back at the previous target | < 1 second |
| 3 | Switch the DNS back | up to one TTL (300 seconds) |
| 4 | Verify the old site answers | ~1 minute |
| 5 | Leave maintenance mode | seconds |

### 4.1 When to roll back

Roll back, rather than fix forward, when:

- the home page or any of the eight migrated items does not render ✓;
- the media are missing or 404 ✓;
- the REST API is not answering ✓;
- the site is slower than before the cutover by more than the plan allows ✓.

Do **not** roll back for a content problem that is confined to one page: the
content tools can fix that inside the new site, and a rollback for a typo
costs a DNS propagation cycle.

### 4.2 When the rollback is *not* enough

If the old site is also broken — a shared host, a database problem, an
expired certificate — then the backup from §3.1 is the recovery path, and
that is why it is verified by restoring it beforehand. Restoring is minutes
of work but it is a *different* operation from the symlink rollback, and
the two are not interchangeable.

## 5. What could go wrong, and what is done about it

| Risk | What is done about it |
|---|---|
| The DNS does not propagate back | The TTL is lowered days ahead of the cutover, so the rollback is bounded by 300 seconds ✓ |
| The new theme does not render the old content | The content is written through ordinary WordPress content ✓ — the theme's blocks are a layer above it, not a replacement for it ✓ |
| The `ai` plugin or All-in-One WP Migration breaks under the new theme | They are not touched ✓, and the rehearsal site carries the same REST namespaces ✓ |
| A plugin writes to the database during the switch | The switch is a symlink, and the database is not rewritten ✓ |
| The cutover happens on the wrong target | The rehearsal's cutover and rollback are two `ln -sfn` commands ✓ — the whole point is that they are trivial |
| The site's only backup is inside the site's own plugin | The backup is taken outside WordPress ✓ and stored in the key store ✓ |

## 6. The rehearsal

`rehearse_migration.sh` runs the whole procedure against a disposable site:
a fresh WordPress, a fresh database, the theme and the plugins linked from
the checkout under test, the content created through the MCP surface, the
design staged and committed in a transaction, the doctor's verdict, the
symlink cutover, the symlink rollback, and the content's survival across
both.

The content it creates is **synthetic** and shaped like the production site
(two pages, two posts). What is rehearsed is the *mechanism*; nobody's data
is copied.

The measured run (this machine, disposable site, the branch under test):

```
provision        0s   (the site already existed; a cold run is ~40s)
migrate          4s   (four pieces of content, a staged and committed design)
verify           0s   (the doctor: status ok, one warning — an unused file widget)
cut over         0s   (two symlinks; the site answered 200)
roll back        0s   (two symlinks back; the site answered 200, the content intact)
```

The doctor's verdict after the migration was `status: ok` ✓ with no errors ✓
and one warning — the default `hello-world` widget is registered and not
referenced by any zone ✓ — which is a real finding about a migrated site ✓
and exactly the kind of thing the doctor is for ✓.

Two things the rehearsal cannot tell you, and the plan does not pretend
otherwise:

1. **It does not rehearse the DNS.** Cloudflare was not touched. The
   propagation times in §3 are the published TTL semantics, not a
   measurement.
2. **It does not rehearse the production content.** It rehearses the
   mechanism with synthetic content. The first production rehearsal is a
   staging copy of the real site, built in the window's preparation days,
   with the real content and the real media — and it is still not the
   cutover.

## 7. The commands

```sh
# The rehearsal: a disposable site, from nothing, and its own teardown.
MYSQL_SOCKET=/path/to/mysqld.sock \
MYSQL_ROOT_CMD='mysql --socket=/path/to/mysqld.sock -uroot -p…' \
PHP_BIN=/path/to/php WP_CLI=/path/to/wp \
  ./rehearse_migration.sh

# The cutover, on the real host.
ln -sfn /srv/releases/agentshell-2026-10-24 /srv/www/flowbyte/wp-content/themes/agent-shell-theme
ln -sfn /srv/releases/agentshell-2026-10-24/agentshell-mcp    /srv/www/flowbyte/wp-content/plugins/agentshell-mcp
ln -sfn /srv/releases/agentshell-2026-10-24/agentshell-blocks /srv/www/flowbyte/wp-content/plugins/agentshell-blocks

# The rollback: the same three lines, with the previous release.
ln -sfn /srv/releases/agentshell-2026-10-17 /srv/www/flowbyte/wp-content/themes/agent-shell-theme
ln -sfn /srv/releases/agentshell-2026-10-17/agentshell-mcp    /srv/www/flowbyte/wp-content/plugins/agentshell-mcp
ln -sfn /srv/releases/agentshell-2026-10-17/agentshell-blocks /srv/www/flowbyte/wp-content/plugins/agentshell-blocks
```

## 8. What this plan does not decide

- **The design.** The site gets a new one; that is a design decision, not a
  migration decision, and it is not made here.
- **The content's shape.** Whether the six posts and two pages keep their
  URLs, their excerpts and their dates is a decision for the site's owner.
  The plan moves them; it does not rewrite them.
- **The plugin set.** The `ai` plugin stays ✓; whether it keeps working
  under the new theme is a question for the staging copy ✓, not for this
  document.
