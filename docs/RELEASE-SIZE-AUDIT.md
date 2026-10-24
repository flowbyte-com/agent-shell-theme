# Release size audit

**Status:** record (not a contract). The normative behaviour lives in
[`AGENTS.md`](../AGENTS.md) — §7 for what a deployment must not carry, and
§8 for what this file is.

**Date:** 2026-10-24. **Measured on:** the development checkout at
`/home/v/projects/agent-shell-theme`, revision `31344c7`.

---

## 1. The question, and the answer

> The owner made a copy of AgentShell's theme directory and removed the
> plugins, but the resulting theme copy was still over 60 MB. This seems
> unexpectedly large.

**The theme is not 60 MB. The copy was of the development repository.**

The theme directory that the local site serves is a **symlink into the
development checkout**:

```
/home/v/Local Sites/agentshell-demo/app/public/wp-content/themes/agent-shell-theme
  -> /home/v/projects/agent-shell-theme
```

So a copy of "the theme directory" is a copy of the checkout, and a copy
of the checkout carries the Git history and four other checkouts with it.
Removing the plugins — which are ~1.5 MB of the 71 MB — changes nothing.

The production distribution is the **release archive**: **345,329 bytes
compressed, 1,400,222 bytes extracted, 120 files**. That is the number
that matters, and it is 1.6 MB rather than 70 MB.

### 1.1 The owner's observation, reproduced

```console
$ cp -rL ".../wp-content/themes/agent-shell-theme" /tmp/owner-copy/theme
$ du -sh /tmp/owner-copy/theme
71M   /tmp/owner-copy/theme

$ rm -rf /tmp/owner-copy/theme/agentshell-mcp \
          /tmp/owner-copy/theme/agentshell-blocks \
          /tmp/owner-copy/theme/agentshell-mcp-daemon
$ du -sb /tmp/owner-copy/theme
60656083   /tmp/owner-copy/theme      # 70 MB — "over 60 MB"
```

Exactly the owner's report. And what is in those 70 MB:

| Part | Apparent | Disk | What it is |
|---|---:|---:|---|
| `.worktrees/` | 34,215,046 | 36 MB | **four other Git checkouts**, gitignored |
| `.git/` | 20,382,325 | 28 MB | **the Git history and its objects** |
| `tests/` | 2,917,070 | 3.2 MB | the regression suite |
| `screenshot.png` | 1,637,508 | 1.6 MB | the repository's theme screenshot |
| `docs/` | 728,647 | 784 KB | the design records |
| everything else | ~776,000 | ~1.5 MB | the actual theme and plugins |

**97 % of the "theme" was Git history and other checkouts.**

---

## 2. The measurements

### 2.1 The development repository

| # | What | Apparent bytes | Disk | Files |
|---|---|---:|---:|---:|
| A1 | the checkout, everything | 61,871,866 | 73 MB | 4,487 |
| A2 | `.git/` — history and objects | 20,382,325 | 29 MB | 2,784 objects |
| A3 | `.worktrees/` — four other checkouts | 34,215,046 | 36 MB | 1,703 |
| A4 | the working tree without A2 and A3 | 7,274,495 | 7.9 MB | ~1,700 |

**Hard links:** none. `find -type f -links +1` returns nothing, so no
file is counted twice by `du`.

**Symlinks inside the checkout:** none. The only symlinks are *outside*
it — the two in the local site that point into it.

**The Git objects are loose.** `git count-objects -v` reports 2,784
loose objects, 26.80 MiB, **zero packs**. The history has never been
packed. `git gc` would pack it (and would probably remove the 8.6 MB
session-capture blob that §4.2 describes) — it is not run here, because
rewriting or pruning the object store is the owner's decision and not
part of a deployment.

### 2.2 The pieces, in the checkout

| What | Apparent | Disk | Classification |
|---|---:|---:|---|
| `agentshell-mcp/` (the MCP plugin) | 1,162,548 | 1.4 MB | **production** |
| `agentshell-blocks/` (the Blocks plugin) | 28,963 | 56 KB | **production** |
| `agentshell-mcp-daemon/` (the standalone daemon) | 26,227 | 60 KB | **production, not deployed** — it runs on the operator's machine |
| the theme's own PHP, CSS and JSON | 172,496 | 172 KB | **production** |
| `widgets/`, `template-parts/`, `assets/` | 9,902 | 36 KB | **production** |
| `tests/` | 2,917,070 | 3.2 MB | development |
| `docs/` | 728,647 | 784 KB | development (design records) |
| `skills/` | 51,619 | 84 KB | development (agent skill documents) |
| `configurator/` | 60,426 | 72 KB | development (the design tool) |
| `outputs/` | 115,452 | 164 KB | development (generated output, tracked) |
| `tools/` | 15,137 | 24 KB | development (the release tooling) |
| `jr-mut/` | 180,727 | 192 KB | **historical evidence** — deliberately preserved, see `docs/STAGE-2C-DESIGN.md` §`jr-mut/ preserved` |
| `screenshot.png` | 1,637,508 | 1.6 MB | development (a 1200×800 capture) |
| `LICENSE` | 34,523 | 36 KB | **production** — it travels with the work |

### 2.3 The release archive — the production distribution

| # | What | Bytes | Note |
|---|---:|---:|---|
| C1 | `agentshell-31344c7.tar.gz` | **345,329** (340 KB) | the compressed archive |
| C2 | extracted | **1,400,222** (1.6 MB) | 120 files, 128 paths |

Largest files inside the archive:

```
140,169  functions.php
116,676  agentshell-mcp/includes/class-content-recovery.php
 98,572  agentshell-mcp/includes/tools/class-publish-edit.php
 79,373  agentshell-mcp/includes/class-transactions.php
 78,201  agentshell-mcp/includes/class-content-journal.php
 64,179  agentshell-mcp/includes/class-screenshot.php
 55,735  agentshell-mcp/includes/tools/class-edit-published.php
 35,706  agentshell-mcp/includes/tools/class-update-draft.php
 34,523  LICENSE
 33,127  agentshell-mcp/includes/tools/class-screenshot.php
```

There is no image, no font, no archive and no database in the release.
The whole distribution is source code.

### 2.4 What the local site actually serves

| What | Size | Note |
|---|---:|---|
| `wp-content/themes/agent-shell-theme` | a **symlink** | → the checkout |
| `wp-content/plugins/agentshell-mcp` | a **symlink** | → the checkout |
| `wp-content/plugins/agentshell-blocks` | a **symlink** | → the checkout |
| `wp-content/uploads/` | 12 KB, **0 files** | the showcase uses no uploaded media |
| `wp-content/plugins/all-in-one-wp-migration` | 4.1 MB | the migration plugin, version **7.112**, free edition |

### 2.5 The owner's copy

| # | What | Apparent | Disk |
|---|---|---:|---:|
| D1 | the copy, plugins included | 61,873,821 | 71 MB |
| D2 | the copy, plugins removed | 60,656,083 | 70 MB |

---

## 3. What is required at runtime, and what is not

**Required at runtime** — the release archive, and nothing else:

* the theme's PHP (`functions.php`, `style.css`, the templates, the
  `template-parts/`, `widgets/`, `assets/`, `default-config.json`),
* the two plugins (`agentshell-mcp`, `agentshell-blocks`),
* the licence.

**Required on the operator's machine, not on the server** — the daemon
(`agentshell-mcp-daemon/`). It is the client that speaks to the plugin;
it does not run inside WordPress, and the release archive deliberately
does not carry it.

**Not required at runtime** — everything else in the checkout: the test
suite, the design records, the agent skills, the configurator, the
release tooling, the generated outputs, the historical `jr-mut/` copy,
and the repository's own `screenshot.png`.

---

## 4. What was discovered

### 4.1 The release distribution was already clean

`tools/package-release.sh` builds the archive from an explicit list of
what ships, and `tools/check-release-archive.sh` asserts that it
contains what it must and none of what it must not. The two share one
list of exclusions (`tools/release-exclusions.sh`), so an exclusion
forgotten in one is caught by the build.

The exclusions cover `.git`, `.worktrees`, `tests/`, `tools/`,
`configurator/`, `skills/`, `docs/`, `outputs/`, `dist/`, the daemon,
the CI workflow, and — at any depth — `*.log`, `*.sql`, `*.tar.gz`,
`*.zip`, `*.key`, `*.pem`, `screenshot.png` and `pi-session-*.html`.

**Verified independently here, not taken on trust:**

```console
$ tar -tzf dist/agentshell-31344c7.tar.gz | grep -E '(^|/)\.git|tests/|\.worktrees' | head
   (nothing)
$ tar -xzf dist/agentshell-31344c7.tar.gz -C /tmp/x && grep -rl 'NkeWyTNCSUYSEGHUN0toj07xKNlnleI' /tmp/x
   (nothing)
```

### 4.2 The Git history carries a revoked credential — and it must never be deployed

The disposable test bed's database password was hardcoded in the
stage-2c live child scripts in five commits before it was rotated. The
literal is still in the published history:

```console
$ git show 5590b19:tests/stage-2c-publish-edit-live-child.php | grep DB_PASS
$DB_PASS  = 'NkeWyTNCSUYSEGHUN0toj07xKNlnleI';
```

It is **not** in the current tree, **not** in the working tree and
**not** in the release archive (all three checked above). It *is* in
the history, and the history is reachable from `main`, so it cannot be
removed without rewriting history — which is not permitted and would not
be the right answer.

The same history also holds an **8,570,372-byte** session capture
(`pi-session-2026-10-23T09-40-44-705Z_*.html`) that was committed by
mistake and removed in `0b48e62`. It is 42 % of the object store. The
release exclusions keep it out of the archive; it stays in the history.

**The consequence is the whole point of this audit:** the Git directory
must never be copied into a public WordPress installation, and a
deployment that copies the *working tree* copies it.

### 4.3 The migration plugin would have carried the history — this is the live risk

The local site serves the theme and the plugins as **symlinks into the
checkout**. The All-in-One WP Migration export enumerates themes and
plugins with `RecursiveDirectoryIterator`, and PHP follows the symlink.
Measured with the plugin's **own** iterator and the plugin's **own**
exclude filter:

```
export would carry: files=4487 bytes=61871866 (59.0 MiB)
  of which .git:        files=2878 bytes=20382325 (19.4 MiB)
  of which .worktrees:  files=1242 bytes=34215046 (32.6 MiB)
```

The plugin's default filters exclude only its own backups path and its
own base directory. **`.git` is not among them.**

So an export taken today, without changing anything, would produce a
59 MiB archive carrying the Git history — **including the revoked
credential** — and four other development checkouts, and would install
them as the theme on `flowbyte.com`.

This is not a hypothetical: it is what the current arrangement does.
§6 is the fix.

### 4.4 Local material that is genuinely unnecessary

| What | Size | What it is | Action |
|---|---:|---|---|
| `agentshell-mcp/includes/.git/` | 4 KB | an **empty** directory, left behind | **removed** |
| `.worktrees/hal9001-programme/pi-session-*.html` | 8,570,372 | a session capture committed by mistake | cannot be removed without rewriting history; excluded from the release |
| `.worktrees/` (four checkouts) | 34,215,046 | development checkouts, gitignored | **kept** — two of them hold uncommitted work (16 and 5 changed files) |
| `jr-mut/` | 180,727 | a historical copy of the theme's files | **kept** — it is preserved evidence, and the documentation gates exclude it from the doc checks on purpose |
| `outputs/` | 115,452 | generated output, tracked in Git | **kept** — it is evidence; excluded from the release |
| `screenshot.png` | 1,637,508 | a 1200×800 capture of the theme | **kept** in the repository; excluded from the release |

**The empty nested `.git` was the only thing that was genuinely
unnecessary, and it was small.** It was removed, and a regression guard
was added (`tests/release-archive-test.php`): the theme's runtime
directories — `agentshell-mcp/`, `agentshell-blocks/`,
`agentshell-mcp-daemon/`, `widgets/`, `template-parts/`, `assets/` — must
carry no `.git`, `.worktrees`, `node_modules`, `vendor`, `dist` or
`build` directory.

### 4.5 Things that were looked for and not found

* **No Composer dependencies.** No `composer.json`, no `vendor/`. The
  plugins use nothing outside WordPress and PHP.
* **No JavaScript dependencies.** No `package.json`, no `node_modules/`.
  The theme's one script (`assets/theme.js`) is hand-written and has no
  imports.
* **No source maps.** None are generated.
* **No debug logs.** `*.log` is excluded from the release, and there are
  none in the checkout.
* **No build directory.** `dist/` is gitignored and absent.
* **No database inside the theme.** The `agentshell-test.local`
  disposable database lives outside the repository, and the release
  refuses `*.sql`.
* **No duplicated CSS, JavaScript or images.** The custom stylesheet is
  materialised at runtime into `wp-content/uploads/agentshell-custom/`
  and is not stored in the repository.
* **No unexpected uploads inside the theme.** The theme directory
  contains no `wp-content`.

---

## 5. Before and after

| What | Before | After | Change |
|---|---:|---:|---:|
| the checkout (everything) | 61,871,866 | 61,871,866 | unchanged |
| the checkout without `.git` and `.worktrees` | 7,274,495 | 7,274,495 | unchanged |
| the owner's copy, plugins removed | 60,656,083 | — | **not the deployment** |
| the release archive, compressed | 327,371 | **345,329** | +17,958 (one new class, §6.1) |
| the release archive, extracted | 1,357,785 | **1,400,222** | +42,437 |
| files in the release archive | 119 | **120** | +1 |
| the runtime directories | — | — | one empty nested `.git` removed |

**The archive grew by 18 KB and the deployment shrank by 59 MB** — the
growth is one class file, the shrinkage is everything that was never
supposed to be deployed.

---

## 6. What was changed

### 6.1 The release gained the request policy

`agentshell-mcp/includes/class-browser-request-policy.php` is 42,437
bytes and is part of the product (see the design record
`docs/SCREENSHOT-REQUEST-POLICY.md`). It is in the archive because the
archive is built from the revision's tree.

### 6.2 The nested repository guard

`tests/release-archive-test.php` now asserts that the theme's runtime
directories carry no `.git`, `.worktrees`, `node_modules`, `vendor`,
`dist` or `build` directory. The empty `agentshell-mcp/includes/.git/`
was removed at the same time.

### 6.3 Nothing else was changed

No development file was deleted to make a number smaller. The test
suite, the design records, the skills, the configurator, the generated
outputs and the preserved `jr-mut/` copy are all still here.

---

## 7. The recommended deployment layout

**Deploy the release archive. Never copy the working tree.**

```console
$ bash tools/package-release.sh --out dist
$ bash tools/check-release-archive.sh --archive dist/agentshell-*.tar.gz
```

Then, on the server:

```
wp-content/
├── themes/
│   └── agent-shell-theme/     ← the archive's agentshell/ directory, renamed
├── plugins/
│   ├── agentshell-mcp/        ← the archive's agentshell/agentshell-mcp/
│   └── agentshell-blocks/     ← the archive's agentshell/agentshell-blocks/
└── uploads/                   ← the media, from the migration
```

The archive has one top-level directory, `agentshell/`, and it holds
the theme, the two plugins and the licence at the top level. Split it
into the three places WordPress expects. The whole thing is 1.4 MB.

**Never deploy:**

`.git` · `.worktrees` · `tests/` · `docs/` · `tools/` · `skills/` ·
`configurator/` · `outputs/` · `dist/` · `agentshell-mcp-daemon/` ·
`jr-mut/` · the repository's `screenshot.png` · any development
database · the test-bed profile or its credentials · the local
Application Password · backup archives.

**Never export the local site while the theme and the plugins are
symlinks into the checkout.** See §4.3 and
`docs/WP-MIGRATE-CUTOVER.md` §3.

---

## 8. The conclusion

> State clearly whether the theme's size was genuinely excessive or was
> simply the result of copying development material.

**It was the result of copying development material.** The theme is
172 KB of PHP, CSS and JSON plus 1.2 MB of plugin code: **1.4 MB
extracted, 345 KB compressed.** Nothing about the theme is excessive,
and nothing was refactored to make it smaller, because nothing needed
to be.

What was genuinely excessive was the *deployment method*: a symlink into
a development checkout, copied wholesale. The fix is not a smaller
repository — it is a deployment that is a build. The build already
existed (`tools/package-release.sh`); what was missing was the
instruction to use it, and the guard against the mistake that would
have carried a revoked credential into a public installation.

The success criterion is not a small Git repository. It is a complete,
secure, minimal-enough production installation without development
baggage — and that is 1.4 MB.
