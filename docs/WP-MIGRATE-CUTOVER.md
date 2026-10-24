# flowbyte.com — WP Migrate cutover plan

**Status:** record (not a contract). The normative behaviour lives in
[`AGENTS.md`](../AGENTS.md).

**Supersedes:** [`FLOWBYTE-CUTOVER-PLAN.md`](./FLOWBYTE-CUTOVER-PLAN.md) —
the symlink-and-DNS cutover. That plan is kept as a historical record of
the rehearsal it describes; it is **not** the procedure. The owner has
decided to migrate the local showcase with **WP Migrate**, and the
mechanism it describes — a symlink switch at the origin and a DNS change
— is not what will happen.

**Date:** 2026-10-24.

---

## 1. Who does what

**The owner performs the migration.** This document says what to do; it
does not do it. Nothing in this plan has been executed, and nothing
about `flowbyte.com` has been changed.

An agent may help with: building and checking the release archive,
inspecting the local showcase, reviewing the checklist, and diagnosing
afterwards. An agent may **not**: run the export or the import, touch
the production database, change DNS, or alter the hosting.

---

## 2. What the owner has, measured

| What | Value |
|---|---|
| the migration plugin | **All-in-One WP Migration**, version **7.112** |
| the edition | the **free** edition — no extensions installed |
| where it is | `wp-content/plugins/all-in-one-wp-migration/` on the local site |
| the source site | `agentshell-demo.local` (LocalWP) |
| the source theme | `wp-content/themes/agent-shell-theme` — **a symlink into the development checkout** |
| the source plugins | `wp-content/plugins/agentshell-{mcp,blocks}` — **also symlinks** |
| the source media | `wp-content/uploads/` — **12 KB, 0 files** |
| the destination | `flowbyte.com`, an existing WordPress installation with its own content, media, users and settings |

### 2.1 What the free edition can and cannot do

Read from the installed plugin's own source, not from its marketing:

**It can:**

* **Export** the site to a single `.wpress` archive: the database, the
  media, the themes and the plugins. The export's theme and plugin
  enumeration is a `RecursiveDirectoryIterator` over `wp-content/themes`
  and `wp-content/plugins` — **and it follows symlinks.**
* **Import** that archive on the destination. The import **drops the
  destination's tables** (`DROP TABLE IF EXISTS`) and writes the
  archive's tables in their place. It is a **replacement, not a merge**.
* **Replace URLs during the import.** The import wizard's find/replace
  step is applied to the database *and* to the extracted content, and
  the replacement is **serialisation-aware**: `replace_table_values()`
  walks the serialised strings rather than doing a blind string
  substitution, so a URL inside a serialised array is replaced
  correctly rather than corrupting the option.
* Restore the archive's themes and plugins over the destination's
  (`$old_paths = array( 'plugins', 'themes' )` → the destination's own
  directories).

**It cannot, in the free edition:**

* Push the archive to a cloud destination (S3, Google Drive, OneDrive,
  Dropbox, Box) — the owner downloads the `.wpress` and uploads it.
* Clone automatically to another domain, or keep the source running.
* Move a multisite network.
* Merge two databases. **There is no merge.** The import replaces.

### 2.2 The precondition — read this before exporting

**The theme and the plugins on the source site are symlinks into the
development checkout.** The export follows symlinks, so an export taken
today would carry the whole development repository.

Measured with the plugin's own iterator and its own exclude filter:

```
export would carry: files=4522 bytes=62147318 (59.3 MiB)
  of which .git:        files=2910 bytes=20584693 (19.6 MiB)
  of which .worktrees:  files=1242 bytes=34215046 (32.6 MiB)
```

The plugin's default filters exclude only its own backups path and its
own base directory. **`.git` is not among them.** The Git history of the
published repository contains a **revoked** database password
(`NkeWyTNCSUYSEGHUN0toj07xKNlnleI`, rotated in October 2026; the
literal is still reachable from `main` in five commits, and history
rewriting is not permitted). An export taken today would carry it into
the archive, and the import would install it as the theme on the public
site.

**So the first thing to do is replace the symlinks with real
directories containing the release content.** §3.1.

---

## 3. The procedure

### 3.1 On the source site — make the tree deployable

The export can only carry what is in the theme and plugin directories.
Those directories must contain the **release archive's** content and
nothing else.

```console
# 1. Build and check the release archive.
$ cd /home/v/projects/agent-shell-theme
$ bash tools/package-release.sh --out dist
$ bash tools/check-release-archive.sh --archive dist/agentshell-*.tar.gz
[check] the deployment is clean: nothing forbidden is present, nothing required is missing

# 2. Extract it somewhere outside the checkout.
$ rm -rf /tmp/agentshell-release && mkdir -p /tmp/agentshell-release
$ tar -xzf dist/agentshell-*.tar.gz -C /tmp/agentshell-release
$ ls /tmp/agentshell-release/agentshell
```

Then, on the LocalWP site:

```console
# 3. Replace the theme symlink with a real directory holding the release.
$ cd "/home/v/Local Sites/agentshell-demo/app/public/wp-content/themes"
$ rm agent-shell-theme
$ cp -R /tmp/agentshell-release/agentshell agent-shell-theme
# …and take the plugins out of the theme copy:
$ rm -rf agent-shell-theme/agentshell-mcp agent-shell-theme/agentshell-blocks

$ cd ../plugins
$ rm agentshell-mcp agentshell-blocks
$ cp -R /tmp/agentshell-release/agentshell/agentshell-mcp .
$ cp -R /tmp/agentshell-release/agentshell/agentshell-blocks .

# 4. Prove it: nothing forbidden is left, and nothing is a symlink.
$ find "../themes/agent-shell-theme" ../plugins/agentshell-mcp ../plugins/agentshell-blocks \
     -name .git -o -name .worktrees -o -name tests -o -type l
   (nothing)
$ du -sh "../themes/agent-shell-theme" ../plugins/agentshell-mcp ../plugins/agentshell-blocks
1.4M    …/themes/agent-shell-theme
1.4M    …/plugins/agentshell-mcp
56K     …/plugins/agentshell-blocks
```

**The local showcase stops following the checkout after this step.**
Edits to the checkout no longer appear on the local site until the
release is rebuilt and copied again. That is the price of a site that
can be exported safely, and it is the right trade: the checkout is a
development tree, and a deployment is a build.

*If the owner would rather keep the symlinks for local development, the
alternative is to exclude themes and plugins from the export (the export
wizard has "Exclude themes" and "Exclude plugins" options) and install
them on the destination from the release archive separately. §3.3 says
which is which; §5 says why the symlink-free route is the one this plan
recommends.*

### 3.2 On the source site — export

In the WordPress admin: **All-in-One WP Migration → Export → Download**.

The wizard's options matter:

| Option | Set it to | Why |
|---|---|---|
| Format | **WPRESS** | the default; the free edition's own format |
| **Exclude themes** | **off** | the theme must travel with the archive (§3.1) |
| **Exclude plugins** | **off** | both plugins must travel with the archive |
| **Exclude inactive themes/plugins** | off | `twentytwentyfive` and friends are inert; leaving them in costs a few hundred KB and no risk. Excluding them is also fine. |
| **Exclude media** | off | there is no media, but leave it off so the step is not a surprise later |
| Database tables | all | the showcase's database is the showcase |

The result is a single `.wpress` file. **Verify it before importing
it:**

```console
$ ls -lh ~/Downloads/*.wpress
$ unzip -l ~/Downloads/*.wpress | tail -1     # the file count
$ unzip -l ~/Downloads/*.wpress | grep -E '\.git/|\.worktrees/|tests/'   # must print nothing
```

### 3.3 On the destination — prepare

```console
# On flowbyte.com, by SFTP or SSH:
$ cd wp-content/themes && mkdir -p agent-shell-theme
$ cd wp-content/plugins  && mkdir -p agentshell-mcp agentshell-blocks
# …upload the release archive's three directories…
```

Upload the **release archive's** theme and plugin directories — not a
copy of the checkout. The import will overwrite these with the
archive's copies anyway (it extracts `themes` and `plugins` over the
destination's), so uploading them first means the site is never in a
state where the active theme does not exist.

### 3.4 On the destination — import

In the destination's WordPress admin: **All-in-One WP Migration →
Import → Upload**.

| Step | What to do |
|---|---|
| Select the file | the `.wpress` from §3.2 |
| **Replace URLs** | find `http://agentshell-demo.local` → replace with `https://flowbyte.com` |
| Confirm | read the warning: **this replaces the destination's database** |

**One find/replace pair, and only one.** The source site's URL appears
in the database (in `siteurl`, in `home`, in post content, in the
`agentshell_config` option, in menus, in widget settings) and in the
media paths. The plugin's serialisation-aware replacement handles all of
them. A second pair — for example `agentshell-demo.local` →
`flowbyte.com` without the scheme — would corrupt the first.

Check the replace pair before continuing:

* the **find** value is exactly what the source site serves —
  `http://agentshell-demo.local` (the source site is **http**, not
  https: LocalWP's local domain is plain HTTP);
* the **replace** value is exactly what the destination serves —
  `https://flowbyte.com` (with the scheme, and without a trailing
  slash).

### 3.5 After the import

* Flush the permalinks: **Settings → Permalinks → Save** (the import
  rewrites the rules, and saving rebuilds them against the new host).
* Confirm the site's `siteurl` and `home` are `https://flowbyte.com`.
* Sign in again. The import replaces `wp_users`, so the local site's
  administrator account is the one that now exists — **the
  destination's previous administrator password no longer works.** The
  import's user step can preserve the destination's password; if it did
  not, reset it by email or through the host's panel.

---

## 4. The checklist

### 4.1 What must travel in the archive

| # | Item | How it travels | Notes |
|---|---|---|---|
| 1 | **the showcase database** | the archive's database | every table, including `wp_options['agentshell_config']`, the nav menu, the pages and the users |
| 2 | **media uploads** | the archive's `wp-content/uploads` | **there is none** — the showcase has 0 uploaded files, so there is nothing to lose and nothing to miss. The theme draws with CSS and inline SVG. |
| 3 | **the AgentShell theme** | the archive's `themes/agent-shell-theme/` | from the **release archive**, not the checkout |
| 4 | **both AgentShell plugins** | the archive's `plugins/agentshell-{mcp,blocks}/` | both must be present, or the tool surface is not 69 tools |
| 5 | **the site URL** | the import's find/replace | `http://agentshell-demo.local` → `https://flowbyte.com` |
| 6 | **serialised data** | the import's replacement is serialisation-aware | `agentshell_config`, the menu structure and the widget settings are all serialised options; a blind string replacement would corrupt them, and this plugin's is not blind |
| 7 | **permalinks** | re-save after the import | the rewrite rules are rebuilt from the new host |
| 8 | **navigation and widget references** | the database | the nav menu (`wp_term_taxonomy`/`wp_posts` of type `nav_menu_item`) and the widget registry (`wp_options['agentshell_config']['widgets']`) travel with the database. The widget *references* are ids, not paths, so they survive the domain change. |
| 9 | **custom CSS and the rest of the AgentShell configuration** | the database | `custom_css` is **9,991 bytes** inside `wp_options['agentshell_config']`; the zones, the design tokens and the three active widgets (`tool-atlas`, `code-copy`, `status-strip`) are in the same option. The custom stylesheet is *also* materialised as a file under `wp-content/uploads/agentshell-custom/` — the database is the source of truth and the file is a cache, so it is rebuilt after the import. |
| 10 | **HTTPS** | the host's certificate, not the archive | the destination must already serve `https://flowbyte.com` with a valid certificate. The archive cannot carry a certificate. |
| 11 | **the Application Password** | **not** transferred | the local Application Password is a credential of the *local* site and must not travel. A **new** one is created on the destination for the MCP account. |
| 12 | **production MCP connectivity** | configured after the import | the daemon's `~/.agentshell-mcp.json` points at `https://flowbyte.com/wp-json/agentshell-mcp/v1/mcp` with the new credentials. The daemon itself stays on the operator's machine. |
| 13 | **file permissions** | set on the destination | `wp-content/` and the theme's directories writable by the web user; the theme's PHP readable. The release archive extracts with ordinary permissions — check them after extraction. |
| 14 | **PHP and WordPress compatibility** | checked before the import | the plugins need **PHP 8.0+** and **WordPress 6.0+**; the showcase runs on LocalWP's PHP 8.2. Check the destination's PHP version *before* importing, not after. |
| 15 | **site-specific paths and local-only configuration** | removed from the deployment | the `AGENTSHELL_REST_TOKEN`, the `AGENTSHELL_TRUSTED_CA_FILES`, the `AGENTSHELL_CHROME_BIN` and the `AGENTSHELL_SCREENSHOT_ALLOWED_HOSTS` constants are **local** configuration and live in `wp-config.php`, which is **not** in the archive. The destination needs its own `wp-config.php`; nothing local-only is transferred, and nothing local-only should be. |

### 4.2 What must be installed separately, and why

| What | Why it is not in the archive |
|---|---|
| **the standalone daemon** (`agentshell-mcp-daemon/`) | it runs on the *operator's* machine and talks to the plugin over HTTP. It is not a WordPress plugin and must never be inside `wp-content/`. |
| **the Application Password** | a credential. It is created on the destination after the import. |
| **the `wp-config.php` constants** | local configuration. The destination's own `wp-config.php` is what configures it. |
| **the TLS certificate** | the host's, not the archive's. |
| **the All-in-One WP Migration plugin on the destination** | it is already there if the import is being run there; it is not part of the product. |

---

## 5. The existing production installation

**`flowbyte.com` already has a WordPress installation with its own
content, media, users and settings. A full database import replaces
that material. There is no merge.**

Concretely, after the import:

| What happens to the destination's… | |
|---|---|
| pages, posts and other content | **replaced** by the showcase's |
| media | **replaced** by the showcase's (which is none) — the destination's media is *not* deleted from disk by the import, but nothing references it any more |
| users | **replaced** by the showcase's users; the destination's administrator accounts stop existing unless the import preserved them |
| menus and widget settings | **replaced** |
| the theme's and the plugins' settings | **replaced** — including any plugin settings the destination had |
| the Active theme and the active plugins | **replaced** by the showcase's |
| `wp_options` | **replaced**, wholesale |

**Two routes, and the owner must choose one deliberately.**

**Route 1 — replace (recommended, and what this plan describes).**
The showcase *becomes* flowbyte.com. The destination's previous content
is gone from the site. It is recoverable only from a backup taken
first (§6).

**Route 2 — do not import the database.** Export the showcase's
*media and nothing else*, and rebuild the showcase's content on the
destination by hand or by MCP. This keeps the destination's content and
loses the "every word was written by an agent over MCP" story, because
the content is not migrated, only re-created.

**Route 3 — merge the content by hand.** Import into a *staging* copy
of the destination, copy the showcase's pages across with
`agentshell_update_content`, and leave the destination's own content in
place. This is the only route that keeps both, and it is a project of
its own — the mission for this cutover explicitly does not include it.

**Route 1 is what this plan prepares.** If the owner wants Route 3, the
import must not happen as described here.

---

## 6. Backup and recovery for the existing public site

**Before anything else. The import drops tables; a backup is the only
thing that makes that reversible.**

### 6.1 Take these four backups

| # | What | How | Verify |
|---|---|---|---|
| B1 | **the destination's database** | the host's control panel, or `wp db export` / `mysqldump` | the dump restores into a scratch database and its table count matches the live one |
| B2 | **the destination's media** | the host's control panel, or `wp db export --add-drop-table` plus a copy of `wp-content/uploads` | the file count matches |
| B3 | **the destination's theme and plugins** | a copy of `wp-content/themes` and `wp-content/plugins` | the copy opens |
| B4 | **the destination's `wp-config.php`** | a copy | it holds the production database credentials |

Store them **off the server**. The local site's own backup regime
(`~/.config/agentshell/backup-keys/`, the encrypted archives on the
external drive and the off-machine copy) is the pattern; the destination's
backups belong in the same place, under the same key separation, and
**not** alongside the archives they protect.

### 6.2 The recovery procedure

If the import has to be undone:

1. Put the site into maintenance mode.
2. Restore **B1** over the destination's database (drop and recreate the
   tables, then import the dump — a dump that was taken with
   `--add-drop-table` does this itself).
3. Restore **B2**'s media into `wp-content/uploads`.
4. Restore **B3**'s theme and plugins.
5. Restore **B4**'s `wp-config.php`.
6. Flush the permalinks. Re-save them.
7. Clear the caches: the object cache, the page cache, and
   `wp-content/uploads/agentshell-custom/` (the materialised custom
   stylesheet, which is a cache of the database and is rebuilt on the
   next write).
8. Take the site out of maintenance mode and verify §8.

**Verify the backups before the import, not after.** A backup that has
never been restored is a hypothesis.

### 6.3 The local showcase

**The local showcase is preserved.** The migration does not delete it,
and §3.1 changes it only in one way: the theme and the plugins stop
being symlinks into the checkout and become real directories holding
the release. The *content* — the database, the pages, the media — is
untouched.

To go back to following the checkout:

```console
$ cd "/home/v/Local Sites/agentshell-demo/app/public/wp-content/themes"
$ rm -rf agent-shell-theme && ln -s /home/v/projects/agent-shell-theme agent-shell-theme
```

---

## 7. The production artefacts

**Prefer the validated release package to a raw copy of the source
repository.** The size audit measured the difference: the release
archive is **346 KB compressed / 1.4 MB extracted / 120 files**; the
checkout is **61.9 MB**, of which 59 MiB is Git history and other
checkouts.

### 7.1 The extracted directory structure

The archive has one top-level directory, `agentshell/`:

```
agentshell/
├── LICENSE
├── style.css
├── functions.php
├── index.php
├── front-page.php
├── singular.php
├── header.php
├── footer.php
├── default-config.json
├── template-parts/
├── widgets/
├── assets/
├── agentshell-mcp/          ← the plugin
└── agentshell-blocks/       ← the plugin
```

On the server:

```
wp-content/
├── themes/
│   └── agent-shell-theme/   ← the archive's theme files (everything except the two plugin directories)
├── plugins/
│   ├── agentshell-mcp/      ← the archive's agentshell/agentshell-mcp/
│   └── agentshell-blocks/   ← the archive's agentshell/agentshell-blocks/
└── uploads/
```

The theme directory must **not** contain the plugin directories: they
belong in `wp-content/plugins/`, and a copy of the whole `agentshell/`
directory into `wp-content/themes/agent-shell-theme/` puts the plugins
inside the theme, where they are inert and confusing.

### 7.2 Never upload

`.git` · `.worktrees` · `tests/` · `docs/` · `tools/` · `skills/` ·
`configurator/` · `outputs/` · `dist/` · `agentshell-mcp-daemon/` ·
`jr-mut/` · the repository's `screenshot.png` · any development
database · the test-bed profile (`tests/config/test-bed.json`) or its
credentials · the local Application Password · backup archives · the
local `wp-config.php` · the internal evidence under
`/home/v/agentshell-dogfood/` and the screenshots in it.

`tools/check-release-archive.sh` refuses every one of these in the
archive, and `tests/release-archive-test.php` fails the build if an
exclusion is forgotten. The check is run again by CI on every pull
request.

### 7.3 The check that the package is complete

```console
$ bash tools/package-release.sh --out dist
$ bash tools/check-release-archive.sh --archive dist/agentshell-*.tar.gz
[check] the deployment is clean: nothing forbidden is present, nothing required is missing
```

The checker asserts that the archive carries the theme, both plugins,
the assets, the licence and the configuration defaults, and that it
carries none of the excluded categories. A deployment that has passed
it has everything WordPress needs to run AgentShell.

---

## 8. The post-migration verification

Run by the operator, on `flowbyte.com`, after the import.

### 8.1 The site

| # | Check | How |
|---|---|---|
| V1 | the homepage renders | `curl -sI https://flowbyte.com/` → 200 |
| V2 | **all nine showcase routes** render | `/`, `/sample-page/`, `/capabilities/`, `/architecture/`, `/safety/`, `/recipes/`, `/start/`, `/changelog/`, `/release-notes/` |
| V3 | the navigation renders and every item works | the header menu has seven items, all internal links resolving to 200 |
| V4 | the widgets render | the footer strip (`status-strip`) and the two main-zone widgets (`tool-atlas`, `code-copy`) are present; `hello-world` is **disabled** and must not appear |
| V5 | every expected image and asset loads | no 404s in the page source; the theme's `assets/theme.js` is served from `https://flowbyte.com/wp-content/themes/agent-shell-theme/assets/theme.js` |
| V6 | the database-backed configuration is intact | `agentshell_get_config` returns the three zones, the design tokens and the three active widgets |
| V7 | the custom stylesheet loads | `https://flowbyte.com/wp-content/uploads/agentshell-custom/agentshell-custom-<hash>.css` returns 200, **or** the stylesheet is inlined (the doctor reports which) |
| V8 | mobile and desktop rendering | 390 px and 1280 px; the ink-and-ember design is unchanged from the local showcase — **no aesthetic change is part of this migration** |
| V9 | no `.local` references anywhere | grep the page source and the database for `agentshell-demo.local` — **there must be none** |
| V10 | the WordPress site URLs are correct | `https://flowbyte.com` in Settings → General, and in the database's `siteurl` and `home` |
| V11 | no development files are exposed | `https://flowbyte.com/wp-content/themes/agent-shell-theme/.git` → 404; `…/tests/` → 404; `…/docs/` → 404; `…/tools/` → 404 |
| V12 | the theme and plugin versions are the release's | the theme's `style.css` header and the two plugins' headers match the release archive |
| V13 | public HTTPS operation | a valid certificate; `https://` works; `http://` redirects to `https://` |
| V14 | MCP connectivity with new production credentials | the daemon, pointed at `https://flowbyte.com/wp-json/agentshell-mcp/v1/mcp`, answers `tools/list` with **69** tools |
| V15 | a healthy AgentShell doctor | `agentshell_validate` reports no error |

### 8.2 Necessary public configuration, and optional MCP access

They are different things, and the difference matters.

**Necessary for the public site to be correct:**

* the theme and both plugins, from the release archive;
* the database, with the URL replaced;
* the custom stylesheet, which is materialised from the database;
* a valid TLS certificate.

**Optional — MCP access is not a public feature.** The MCP endpoint is
for the owner's agent. It requires an authenticated principal
(`manage_options`), and it is reached over HTTPS with an Application
Password. It does not need to be exposed more widely than it already
is, and **the credentials must never appear in a page, a log, a
screenshot or a transcript**.

**Do not** put the MCP endpoint in a public page, a README on the site,
or the theme's footer. The showcase's own copy says what it needs to
say and no more.

---

## 9. What this plan does not decide

* **Whether the destination's content is kept.** §5 is the decision, and
  it is the owner's.
* **Whether the showcase keeps following the checkout locally.** §6.3
  says how to go back either way.
* **The MCP credential's storage.** The daemon's configuration file must
  be mode `0600` and must live outside the repository.
* **The DNS and the certificate.** The destination must already serve
  `https://flowbyte.com` before the import; the import changes neither.
* **Anything about the hosting.** The plan assumes the destination's
  hosting can run PHP 8.0+ with a WordPress 6.0+ install and that the
  owner can reach the admin over HTTPS.
