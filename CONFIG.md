# AgentShell — Installation & Configuration

> **Pointer / non-normative guide.** This file documents how to install and
> connect AgentShell. It is **not** an agent contract. The canonical contract
> is [`AGENTS.md`](./AGENTS.md) — the architecture (§1), the tool surface (§2),
> the operation loop (§6), and every other normative rule live there and are
> referenced by section, never restated here. If anything here disagrees with
> `AGENTS.md`, `AGENTS.md` wins.

Covers a full setup: fresh Ubuntu → LocalWP site → linked theme and plugins →
WordPress Application Password → configured MCP daemon → working Claude Code
connection, plus verification and troubleshooting.

---

## 0. What you are building

An agent connects to WordPress through the `agentshell-mcp` plugin, reached via
the `agentshell-mcp-daemon` PHP CLI proxy that Claude Code speaks MCP to. The
architecture diagram and the authoritative source-of-truth rules are in
`AGENTS.md` §1. This guide only covers getting the pieces installed and
connected.

Four things must all be true for a connection to work:

| # | Piece | Failure symptom |
|---|---|---|
| 1 | `agentshell-mcp` plugin active | `No route was found` |
| 2 | PHP CLI on `PATH` | daemon spawn fails, `CONNECTION_CLOSED` |
| 3 | Application Password valid | HTTP 401 on the MCP endpoint |
| 4 | TLS trusted | connection refused or certificate error |
| 5 | `tools/list` schema-valid | connects, but **zero tools** are exposed |

Precondition 5 is easy to miss: the server can be fully authenticated and still
expose nothing at all. §10 verifies each in turn.

---

## 1. Prerequisites

| Requirement | Notes |
|---|---|
| Ubuntu (or a close relative) | Commands use `apt`; adapt for other distros |
| LocalWP | Local site manager; provides nginx, PHP-FPM, MySQL, HTTPS |
| Git | For the repository checkout |
| PHP CLI | **Separate from LocalWP's bundled PHP** — see §4 |

Placeholders used throughout:

```
<SITE_DOMAIN>     e.g. your-site.local      (LocalWP site domain)
<SITE_PATH>       e.g. ~/local/your-site    (LocalWP site root)
<REPO_PATH>       e.g. ~/projects/agent-shell-theme
<WP_USER>         WordPress user login the agent authenticates as
```

---

## 2. Install LocalWP and create a site

1. Download and install LocalWP from <https://localwp.com> for Linux.
2. Launch it and create a site (wizard or "Create a new site").
3. Note the **domain** and the **path**. LocalWP serves the site over **HTTPS**
   with a self-signed certificate; both matter for Application Passwords
   (WordPress requires HTTPS) and for the daemon.

LocalWP's layout — you will need the site path for the symlinks in §3:

```
<SITE_PATH>/
├── app/public/          # the WordPress document root (wp-content lives here)
├── conf/                # service templates (nginx, php, mysql)
└── logs/                # nginx, php, mysql logs — check these when debugging
```

Confirm the site responds:

```bash
curl -sS -o /dev/null -w '%{http_code}\n' "https://<SITE_DOMAIN>/"
```

A self-signed certificate is expected at this stage; trust it first (§7), then
come back.

---

## 3. Clone the repository and link it into WordPress

```bash
git clone <REPO_URL> <REPO_PATH>
cd <REPO_PATH>
```

For development, link the repository into the WordPress install rather than
copying it, so edits take effect immediately and nothing is edited in two
places.

**Theme** — the linked directory name becomes the WordPress theme slug, so keep
it exactly `agent-shell-theme`:

```bash
ln -s <REPO_PATH> "<SITE_PATH>/app/public/wp-content/themes/agent-shell-theme"
```

**Plugins** — both are required (`agentshell-mcp` for the tool surface,
`agentshell-blocks` for the widget registry tools):

```bash
ln -s <REPO_PATH>/agentshell-mcp    "<SITE_PATH>/app/public/wp-content/plugins/agentshell-mcp"
ln -s <REPO_PATH>/agentshell-blocks "<SITE_PATH>/app/public/wp-content/plugins/agentshell-blocks"
```

Verify the links resolve:

```bash
ls -l "<SITE_PATH>/app/public/wp-content/themes/" "<SITE_PATH>/app/public/wp-content/plugins/"
```

### 3.1 What a development install serves, and what a deployment ships

The links above are a **development** arrangement: the whole repository is
under the web server's document root, so the web server can serve the test
suite, the CI workflow and the release tooling as well as the theme. That
is convenient and it is not a deployment.

A **deployment** is a build, and the build is explicit:

```bash
# Build the archive for the revision you reviewed.
bash tools/package-release.sh --out /tmp/agentshell-dist

# Assert what it contains, and what it must not.
bash tools/check-release-archive.sh --archive /tmp/agentshell-dist/agentshell-*.tar.gz
```

`tools/package-release.sh` builds from a *revision's tree*, not from the
working directory, so a deployment is the code that was reviewed. It ships
the theme, both plugins and the theme's assets, and it excludes:

| Excluded | Why |
|---|---|
| `tests/` | the test suite and every fixture it carries |
| `.git/`, `.worktrees/` | git's own directory and other checkouts |
| `.github/` | the CI workflow and its scripts |
| `tools/`, `docs/_archive/`, `skills/`, `configurator/` | repository tooling, not the product |
| `agentshell-mcp-daemon/` | the daemon runs on the operator's machine, not on the server |
| any file that looks like a private key | refused outright, not merely skipped |

`tools/check-release-archive.sh` re-checks the built archive against that
list, and also works against a directory (`--tree`), so an installation that
serves the repository directly can be checked too. Both run in CI, so a
category that is excluded in one and forgotten in the other fails the build
rather than a deployment.

> **Do not export or archive a site whose theme is a symlink into the
> checkout.** The development arrangement above is a symlink into the
> repository, and the tools that copy a WordPress site — WP Migrate among
> them — follow symlinks. An export taken from a site in this state
> carries the whole repository: measured with the migration plugin's own
> file enumerator, 59 MiB, of which 19.6 MiB is the `.git` directory, and
> the published history contains a revoked database password. The
> measurements, and the procedure, are in
> [`docs/RELEASE-SIZE-AUDIT.md`](docs/RELEASE-SIZE-AUDIT.md) §4.3 and
> [`docs/WP-MIGRATE-CUTOVER.md`](docs/WP-MIGRATE-CUTOVER.md) §2.2.

**The test-bed profile.** The destructive test suites read a profile that
names a database and its password. It used to live at
`tests/config/test-bed.json`, inside the repository — and a gitignored file
is still a file on a disk that may be served. The independent review of PR
#16 found it readable over HTTP at that path, with the password in
cleartext. It now lives outside the repository, in the operator's own
configuration directory:

```bash
mkdir -p ~/.config/agentshell && chmod 700 ~/.config/agentshell
cp <your-profile>.json ~/.config/agentshell/test-bed.json
chmod 600 ~/.config/agentshell/test-bed.json
```

The harness looks there by default; `AGENTSHELL_TEST_BED_CONFIG` overrides
it. The in-tree location is still honoured for an installation that has not
moved its profile yet, and `tools/check-release-archive.sh` reports it if a
deployment contains one.

---

## 4. PHP CLI on `PATH`

The MCP daemon is a **local PHP CLI script** — Claude Code runs `php` itself.
This is a different PHP from LocalWP's bundled PHP-FPM. Installing only LocalWP
does **not** put a `php` binary on your `PATH`, and that alone produces a
`CONNECTION_CLOSED` error with no server-side log entry at all.

```bash
sudo apt update && sudo apt install -y php-cli
```

Confirm, and note the version:

```bash
command -v php && php -v
```

The two versions are allowed to differ. LocalWP pins its own PHP to the site
(e.g. 8.2.x); the CLI may be newer (e.g. 8.5.x). The daemon only needs a modern
PHP with `openssl` — it makes outbound HTTPS calls.

---

## 5. Activate the theme and plugins

In the WordPress admin at `https://<SITE_DOMAIN>/wp-admin/`:

- **Appearance → Themes** — activate **AgentShell**.
- **Plugins** — activate **agentshell-mcp** and **agentshell-blocks**.

Optional but recommended: assign a menu to the navigation location and set a
Site Logo, so the header zone renders with real content.

Confirm the theme's REST namespace is registered:

```bash
curl -sS -o /dev/null -w '%{http_code}\n' \
  "https://<SITE_DOMAIN>/wp-json/wp/v2/agentshell/config"
```

This route requires authentication, so a **`401`** carrying
`rest_not_logged_in` is the expected result before you create an Application
Password — and it is a **positive** one: the theme's namespace is registered.
A `404` / `rest_no_route` means the theme is not active.

---

## 6. Create the WordPress Application Password

Application Passwords let a headless client authenticate without cookies. They
**require HTTPS** — finish §7 first.

1. WordPress admin → **Users → All Users** → edit `<WP_USER>`.
2. Scroll to **Application Passwords** (or **Profile → Application Passwords**
   on older installs).
3. Name it (e.g. `agentshell-daemon`) and **Add New**. WordPress shows the
   password **once** — copy it now.

**The generated password contains spaces**, e.g. `XXXX XXXX XXXX XXXX XXXX
XXXX`. Those spaces are grouping for readability only; the stored credential is
the bare alphanumeric string. Both forms are accepted and are equivalent (see
the note in §11).

The user needs **`manage_options`** — the MCP endpoint refuses any authenticated
user without it, returning `forbidden`.

> Never commit this password. It lives only in the daemon config file (§8),
> which must be mode `0600`.

---

## 7. Trust the HTTPS certificate

LocalWP issues a self-signed certificate for `<SITE_DOMAIN>`. Two clients must
trust it: the system (for `curl`) and PHP CLI (for the daemon).

**Never disable certificate verification.** Do not pass `curl -k` / `--insecure`,
do not set `verify_peer => false`, and do not disable TLS in the daemon. Trust
the certificate properly instead.

On Linux, install LocalWP's certificate into the system trust store:

```bash
# Locate the certificate LocalWP generated for your site
find ~/.config/Local/run/router/nginx/certs -name '<SITE_DOMAIN>.crt'

sudo cp ~/.config/Local/run/router/nginx/certs/<SITE_DOMAIN>.crt \
        /usr/local/share/ca-certificates/<SITE_DOMAIN>.crt
sudo update-ca-certificates
```

Verify that verification now genuinely succeeds (`ssl_verify_result` must be `0`,
and you must **not** have passed `-k`):

```bash
curl -sS -o /dev/null \
  -w 'status=%{http_code} tls_verify=%{ssl_verify_result}\n' \
  "https://<SITE_DOMAIN>/"
```

If `tls_verify` is not `0`, fix the trust store before continuing — a
`tls_verify` of `2` means the certificate is still untrusted, and the daemon
will fail the same way.

---

## 8. Configure the daemon

The daemon reads a JSON config from the path passed via `--config`. Create it at
`~/.agentshell-mcp.json`:

```json
{
  "url": "https://<SITE_DOMAIN>/wp-json/agentshell-mcp/v1/mcp",
  "user": "<WP_USER>",
  "pass": "<APPLICATION PASSWORD>",
  "timeout": 60
}
```

All four of `url`, `user`, and `pass` are **required**; `timeout` is optional and
defaults to 30. The URL must be the `/wp-json/agentshell-mcp/v1/mcp` route — not
the ordinary `wp-json/wp/v2` REST root.

Lock it down:

```bash
chmod 600 ~/.agentshell-mcp.json
```

Never print this file into logs, issues, or commits.

---

## 9. Register the MCP server with Claude Code

```bash
claude mcp add agentshell --scope user -- \
  php <REPO_PATH>/agentshell-mcp-daemon/daemon.php --config ~/.agentshell-mcp.json
```

`--` marks where the subprocess command begins, so the daemon's own flags are
not mistaken for options to `claude mcp add`.

### Choosing a scope

Claude Code resolves MCP servers from three scopes, highest precedence first:

| Scope | Where it is stored | Reaches |
|---|---|---|
| `local` *(the default)* | `~/.claude.json` → `projects.<absolute path>.mcpServers` | one project, this machine only |
| `user` | `~/.claude.json` → `mcpServers` | every project, this machine |
| `project` | `<project>/.mcp.json` | everyone who checks out the repository |

A higher-precedence entry **silently overrides** a lower one for the projects it
covers, so a stray `agentshell` entry at `local` or `project` scope will hide
the one you meant to register. Check with `claude mcp get agentshell`, which
prints the scope it actually resolved.

For a personal setup, `--scope user` is the right choice: the daemon path lives
in your checkout and the config holds a credential reference, so the entry
belongs to your machine rather than to the repository. Choose `project` only
when the whole team should get the same registration, and be aware that the
file is shared, appears in diffs, and requires each developer's approval before
Claude Code will connect to it.

### User scope versus the MPM adapter's registration

AgentShell and MPM are registered independently, and both normally end up at
user scope in the same `~/.claude.json`:

```json
{
  "mcpServers": {
    "agentshell": {
      "type": "stdio",
      "command": "php",
      "args": ["<REPO_PATH>/agentshell-mcp-daemon/daemon.php", "--config", "~/.agentshell-mcp.json"]
    },
    "mpm": {
      "type": "stdio",
      "command": "<HOME>/.mpm/bin/mpm-mcp",
      "args": [],
      "env": {
        "MPM_WORKSPACE": "<HOME>/.mpm",
        "MPM_PROVENANCE_FRAMEWORK": "claude-code",
        "MPM_FRAMEWORK": "claude-code"
      }
    }
  }
}
```

Two differences are worth keeping straight:

- **Different mechanisms.** The `agentshell` entry above is a plain command you
  register yourself. The `mpm` entry is created by the MPM Claude Code adapter
  (`agent_installation/mpm-claude-code/install.sh` in the MPM repository), which
  also carries `MPM_WORKSPACE` and the provenance variables. Install or update
  MPM through that adapter rather than by hand, or the provenance variables go
  missing and MPM records every call as generic `mcp` instead of `claude-code`.
- **Neither depends on the other.** AgentShell works with MPM absent, and MPM
  works with AgentShell absent. If `claude mcp list` shows one connected and
  the other failed, they are being diagnosed separately — start from the one
  that failed.

Check what Claude Code sees:

```bash
claude mcp list
claude mcp get agentshell   # which scope actually won
```

**Restart the session after registering.** Claude Code spawns MCP servers at
session start, so a server added mid-session will not appear until you start a
new one.

---

## 10. Verify the connection

Do this before trusting any MCP tool. It isolates *which* of the four
preconditions is failing.

### 10.1 The endpoint answers

Reads credentials from the config without echoing them:

```bash
MCP_URL=$(jq -r .url  ~/.agentshell-mcp.json)
MCP_USER=$(jq -r .user ~/.agentshell-mcp.json)
MCP_PASS=$(jq -r .pass ~/.agentshell-mcp.json)

curl -sS -o /dev/null \
  -w 'status=%{http_code} tls_verify=%{ssl_verify_result}\n' \
  -X POST "$MCP_URL" \
  -H "Content-Type: application/json" \
  -H "Authorization: Basic $(printf '%s:%s' "$MCP_USER" "$MCP_PASS" | base64 -w0)" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'
```

Expect `status=200` and `tls_verify=0`. (`base64 -w0` is GNU; on macOS use
`base64 | tr -d '\n'`. If `jq` is unavailable, read the fields with
`python3 -c 'import json,os;print(json.load(open(os.path.expanduser("~/.agentshell-mcp.json")))["url"])'`
instead.)

### 10.2 The daemon completes a handshake

```bash
printf '%s\n' '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' \
  | php <REPO_PATH>/agentshell-mcp-daemon/daemon.php --config ~/.agentshell-mcp.json
```

Expect a JSON-RPC result carrying `protocolVersion` and `serverInfo`. Any
exception here is the real cause — Claude Code only reports the symptom.

### 10.3 The tool list is schema-valid

Authentication alone is not enough. MCP clients validate every tool's
`inputSchema` and **reject the entire `tools/list` response** if any tool is
malformed, leaving the server connected but exposing zero tools.

```bash
printf '%s\n' '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' \
  | php <REPO_PATH>/agentshell-mcp-daemon/daemon.php --config ~/.agentshell-mcp.json \
  | python3 -c 'import sys, json
for line in sys.stdin:
    if not line.strip():
        continue
    d = json.loads(line)
    if d.get("id") == 1 and "result" in d:
        tools = d["result"]["tools"]
        bad = [t["name"] for t in tools
               if not isinstance(t.get("inputSchema", {}).get("properties"), dict)]
        print(f"{len(tools)} tools, {len(bad)} with a malformed properties")
        for n in bad:
            print("  -", n)
        break'
```

Expect `0 with a malformed properties`. The first number is whatever the server
currently exposes and will change as the tool surface grows — the only figure
with a correct value is the malformed count, which must be zero. Anything else
is the fault described in §11.

### 10.3.1 Why `properties` is checked as a type

`inputSchema.properties` must be a JSON **object**. This is worth stating
because the failure is invisible from the outside: a tool that declares no
arguments is the common case, and the empty map is the one shape that PHP is
easy to get wrong.

In PHP, `array()` encodes as the JSON **array** `[]`, not `{}`. A tool that
declares `'properties' => array()` therefore emits `"properties": []`, which
fails schema validation. Because clients validate every entry of `tools/list`
and discard the response as a whole when any entry is invalid, a single
zero-argument tool hides *every* tool — the server still reports `Connected`,
because it genuinely is connected and genuinely is authenticated.

Both base classes now normalise the empty case at the shared layer
(`agentshell-mcp` and `agentshell-blocks`), so a tool only has to declare its
arguments correctly and never has to remember this:

```php
// A tool that takes no arguments — correct, and what the fix makes safe.
return array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false );
```

`required` is deliberately *not* normalised. JSON Schema requires `required` to
be an array, including when empty, so `'required' => array()` must keep
encoding as `[]`; coercing it to `{}` would break a different rule. The
regression tests assert on the encoded JSON decoded *without* `assoc=true`,
because decoding with it turns `{}` back into an array and silently hides the
original defect.

### 10.4 The agent sees a live site

With Claude Code connected, call `agentshell_inspect` then
`agentshell_validate`. Expect the site model (zones, widgets, design tokens) and
a health report with no errors. These two names are referenced here only to
verify the connection; the authoritative tool surface is `AGENTS.md` §2, and
`inspect` / `validate` are the read-only calls to run first per the loop in
`AGENTS.md` §6.

---

## 11. Troubleshooting

### `CONNECTION_CLOSED` in Claude Code

The daemon exited. Almost always one of:

- **`php` is not on `PATH`** — check `command -v php` (§4). Nothing reaches the
  server, so there is no HTTP log to explain it.
- **Authentication failed** — the daemon throws on any HTTP 401 and dies
  (`Authentication failed — check user and application password`). Confirm with
  §10.1.
- **Untrusted TLS** — the daemon's HTTPS call fails and it reports
  `HTTP request failed: ...`. Fix §7.

Always reproduce outside Claude Code with §10.2; the daemon's stderr names the
actual failure, while Claude Code only shows that the pipe closed.

### HTTP 401 on the MCP endpoint

The response body names the failing check — read it, it is specific:

| Message | Cause |
|---|---|
| `Missing Basic Auth header` | No `Authorization` header reached the request |
| `Invalid application password` | Credential did not match any stored password |
| `forbidden` | User authenticated but lacks `manage_options` |

A useful isolation technique: the **same credential** may work against ordinary
WordPress REST while failing against MCP, because those are two independent
authentication implementations. Compare:

```bash
curl -sS -o /dev/null -w 'wp-rest=%{http_code}\n' \
  -u "<WP_USER>:<APPLICATION PASSWORD>" \
  "https://<SITE_DOMAIN>/wp-json/wp/v2/users/me"
```

- **`wp-rest=200` but MCP `401`** → the two paths disagree; the MCP endpoint has
  its own credential handling, and that is where the fault is.
- **both `401`** → the credential itself is wrong, or the username is not
  matched by login.

> **Historical note — already fixed.** Earlier releases of `agentshell-mcp`
> compared the *raw*, space-grouped password against a hash WordPress computes
> over the alphanumeric-only form, so the standard copy-pasted credential
> returned 401 on MCP while succeeding on ordinary REST. WordPress core strips
> non-alphanumerics before hashing (`wp_authenticate_application_password` in
> `wp-includes/user.php`). AgentShell now mirrors core in both its plugin and
> theme authentication paths, and both forms are accepted. Regression tests pin
> this. If you are on a current build and still see 401, the cause is something
> else in the table above — check for a stale copy of the plugin, e.g. a real
> directory in `wp-content/plugins/` shadowing the symlink from §3.

### Server connects but no tools appear / `tools fetch failed`

`claude mcp list` shows `Connected` but reports
`Invalid result for tools/list: ...inputSchema.properties: Invalid input:
expected record, received array`, and no `agentshell_*` tools are available.

This is a **schema** fault, not an authentication one — authentication already
succeeded, which is why the server connects at all.

> **Resolved.** Earlier releases emitted `"properties": []` (a JSON array) for
> every tool that takes no arguments, because an empty PHP `array()` encodes
> that way. Clients validate every entry of `tools/list` and discard the whole
> response when any entry is invalid, so those tools hid every tool in the
> surface. The fix normalises the empty map to a JSON object in the shared
> `Base_Tool::get_definition()` of both `agentshell-mcp` and `agentshell-blocks`,
> so individual tool definitions need no changes. `required` is deliberately
> left as an array, since JSON Schema requires that even when empty. Regression
> tests cover zero-argument, parameterized, and nested schemas, and assert on
> the encoded JSON; see §10.3.1 for the reasoning.

If you still hit this on a current build, the cause is almost certainly a stale
copy of the plugin shadowing the symlink from §3 — a real directory in
`wp-content/plugins/` rather than a link. Confirm which one PHP loads before
changing anything else:

```bash
# Resolves through the symlink to your checkout; a real directory stops here.
readlink -f "<SITE_PATH>/app/public/wp-content/plugins/agentshell-mcp"
grep -n "array() === \$schema\['properties'\]" \
  "<REPO_PATH>/agentshell-mcp/includes/tools/class-base-tool.php"
```

The second command should print a match. If it does not, the running copy is
older than your checkout; re-link per §3 and reactivate the plugin.

### `No route was found`

The `agentshell-mcp` plugin is not active (§5), or the symlink is broken (§3).

### Other errors

| Error | Cause |
|---|---|
| 401 on static-token requests | `AGENTSHELL_REST_TOKEN` not defined in `wp-config.php` — application passwords are unaffected |
| `A transaction is already open` | A previous transaction was not committed or rolled back |
| `Another actor (…) has an open transaction` | Another agent owns the open transaction |
| `Commit blocked by validation errors` | The staged config fails the site doctor — fix and re-validate |
| `No headless browser found` | `agentshell_screenshot` needs Chrome/Chromium on the server |

The full contract and the complete loop are in `AGENTS.md`; the authoritative
error semantics belong to the plugin tool descriptions, not to this guide.

### Logs

When the failure is server-side, read LocalWP's logs:

```bash
tail -n 50 <SITE_PATH>/logs/nginx/*.log
tail -n 50 <SITE_PATH>/logs/php/*.log
```

---

## 12. Known limitations

- **`X-App-Password` header does not authenticate.** The theme's REST auth
  accepts an `X-App-Password` header, but it cannot identify a user: the
  username is only read from the Basic `Authorization` header, and that same
  branch overwrites the password value. Use Basic auth (an `Authorization:
  Basic …` header), which is what the daemon sends and what Application
  Passwords are designed for.
- **`agentshell_screenshot` needs a headless browser** on the server; it is the
  one capability that cannot be satisfied by PHP configuration alone.
- **The daemon config is a plaintext credential.** Mode `0600` is the
  protection. It should never be committed, logged, or pasted into issues.

---

## 13. Quick checklist

- [ ] LocalWP site created; `https://<SITE_DOMAIN>/` responds
- [ ] Repository cloned to `<REPO_PATH>`
- [ ] Theme + both plugins symlinked into `wp-content/` (§3)
- [ ] `php` on `PATH` (§4)
- [ ] Theme and both plugins activated (§5)
- [ ] Application Password created for a `manage_options` user (§6)
- [ ] LocalWP certificate trusted; `tls_verify=0` (§7)
- [ ] `~/.agentshell-mcp.json` created and `chmod 600` (§8)
- [ ] MCP server registered with Claude Code (§9)
- [ ] Session restarted
- [ ] `initialize` returns 200 with `tls_verify=0` (§10.1)
- [ ] Daemon handshake succeeds outside Claude Code (§10.2)
- [ ] `tools/list` reports `0 with a malformed properties` (§10.3)
- [ ] `agentshell_inspect` and `agentshell_validate` both succeed (§10.4)