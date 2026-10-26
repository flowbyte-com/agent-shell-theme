# Configurator redesign — design record

**Category:** design record (category (e) in `AGENTS.md` §8). This file
records an investigation, a proposal, the decision taken, and the evidence for
it. It is **not** a contract. Where it disagrees with `AGENTS.md`, that file is
right and this one is out of date. The normative statements about the human
editor are in `AGENTS.md` §3.1; the normative statements about transactions are
in `AGENTS.md` §6.1–§6.2.

**Status:** implemented on `feature/configurator-redesign`, which is stacked on
`feature/configurator-packaging` (the release-packaging fix) and therefore on
`feature/navigation-blocks` (the independent-menus work). Nothing is merged.

---

## 1. What was measured, before anything was changed

The owner's screenshots are of the **older production panel** on
flowbyte.com. The development panel is not the same thing, so it was measured
in a headless browser rather than assumed. The measurement is against the
development panel, with a stubbed REST response, at a 460×1200 viewport:

| Measurement | Value |
|---|---|
| Panel height | 3,870 px |
| Stacked sections | 9 |
| Form controls | 62 |
| Buttons | 28 |
| Top of the Save button | y = 3,816 px |
| Screens of scrolling to reach Save | 3.4 |
| Rows in the custom CSS / JavaScript textareas | 5 |

The 9 sections were: Zones, Layout & Alignment, Typography & Geometry, Borders
& Shadows, Global Colours, Header Settings, Main Layout, Footer Settings, and
Custom Assets.

Two structural consequences follow from those numbers, and they are the reason
for the redesign rather than a list of complaints:

1. **The panel had no spatial model.** Every control was in one column, in
   kind-order (all the borders together, all the colours together). A header
   colour was five screens away from the header's own area map, so changing
   the header meant scrolling past the footer and back.
2. **The destructive action was at the far end of the scroll.** Save was the
   last thing in a 3,870-pixel document. A control that applies a change being
   3.4 screens away from the change is a control that is not reliably used.

Adding nine header areas, nine footer areas, independent menus, nested
navigation, forms, CAPTCHA settings and import/export to that panel would have
made it worse in exactly the same proportion.

## 2. What was worth keeping

- **The floating trigger.** It is discreet, it is where a site owner expects
  "edit this site" to be, and it does not compete with the page. It stays, in
  the same place, with the same capability gate.
- **One configuration model.** The editor writes the same flat design
  variables, the same two custom assets and the same zone structure that the
  MCP tools write, through the same REST endpoint. Nothing in the redesign
  introduces a second store, and the payload shape is unchanged (§4).
- **The server as the authority.** The editor's payload goes through the same
  validator and the same doctor as an agent's. A refused configuration is
  refused, with the server's own message.
- **The transaction machinery.** The editor opens a transaction, stages the
  configuration, and commits — the same path the agent tooling uses. It does
  not write the option directly.
- **The token vocabulary.** The design tokens and their defaults are the
  theme's; the redesign changes where they are edited, not what they are.

## 3. What was fundamentally awkward

| Problem | Why it could not be patched |
|---|---|
| One scroll for every kind of setting | The problem is the absence of a spatial model, not the length of the document. Shortening the list made the same list shorter. |
| Save at the bottom | A sticky footer bar was possible, but a bar that floats over a list of unrelated settings is a bar that hides the setting being changed. The list itself had to become contextual. |
| Colours grouped by kind | A colour belongs to the thing it colours. Grouping by scope (global / header / main / footer) is what an operator is thinking about. |
| 5-row textareas for CSS and JavaScript | The control was sized for the layout, not for the task. A code editor without a dependency is a tall monospace textarea with a sensible tab size. |
| No history surface | Snapshots and revisions existed as MCP tools. The panel had a "Snapshot now" button and nothing to restore from. |
| No ownership surface | The transaction state was a small pill. When another actor held the transaction, the panel still offered Save. |
| One 1,201-line script | The panel's state, its transport, its rendering and its event handling were interleaved, so none of them could be tested on its own. |

## 4. The architecture that replaced it

The old script is five modules, each with one job, in `configurator/`:

| Module | Responsibility | Depends on |
|---|---|---|
| `configurator-state.js` | The models: transaction state, zone/slot rules, and the editor model (what is selected, what is dirty, what a move does). No DOM, no fetch. | nothing |
| `configurator-api.js` | The transport: the REST routes, the nonce, and the translation of a refusal into an error with a code and a message. No state. | nothing |
| `configurator-picker.js` | The component picker: it presents the choices and reports the one that was made. It knows nothing about zones or drafts. | nothing |
| `configurator-ui.js` | The view: it builds DOM from the editor model and reports what the operator did. It holds no state of its own. | the editor model, through the app |
| `configurator-app.js` | The wiring: the requests, the save path, the dirty guard, the boot. | the other three |

The rules that follow from that split:

- **Dirty is computed, not tracked.** The editor model holds two copies of the
  draft — the one being edited and the one the server last reported — and
  "dirty" is the difference between them. A flag can be wrong; a wrong dirty
  flag is how an operator loses work.
- **The view is a function of the model.** Nothing in the interface is
  rendered from state the view kept for itself. That is what makes the
  browser test possible: the same modules, in a real browser, driven by
  their public entry points.
- **The payload is the payload.** `to_payload()` emits the flat design
  variables, `custom_css`, `custom_js` and `zones` — the shape the panel has
  always sent. A redesigned interface cannot change what is stored.
- **The transport is the only place that knows the routes.** The routes are
  the theme's own. No MCP tool is added, and no MCP tool changes behaviour.

### 4.0 The interface as it is

The four contexts, at 1600 × 1000, against the site being edited:

**Layout** — the zone switcher, the zone's own appearance (behind a
disclosure), the 3×3 map of the nine areas, the selected area's components
and the component inspector. The action bar is pinned to the bottom of the
dock.

![The Layout context](../images/configurator/editor-layout.png)

**Appearance** — the settings that apply to the whole site, grouped by what
they are: colours, typography, spacing, borders and shadows.

![The Appearance context](../images/configurator/editor-appearance.png)

**History** — the open transaction, the snapshots and the revisions, with a
restore on each.

![The History context](../images/configurator/editor-history.png)

**Advanced** — custom CSS and JavaScript in full-height editors, the menus
the design depends on, and the configuration as JSON.

![The Advanced context](../images/configurator/editor-advanced.png)

**The component picker**, open on the Header's middle-centre area. It is a
dialog inside the dock, and it says where the component will land before the
choice is made.

![The component picker](../images/configurator/editor-picker.png)

**At 390 × 844** the rail becomes a horizontal strip of tabs, the dock takes
the whole width, and the action bar stays pinned.

![The editor on a narrow viewport](../images/configurator/editor-mobile.png)

The picker at 390 × 844. The dialog is bounded by the dock and the list
scrolls.

![The component picker on a narrow viewport](../images/configurator/editor-picker-mobile.png)

These are captures of the real interface: the theme's stylesheet and its five
modules, driven by the committed browser harness
(`tests/configurator-browser-test.html`), with the REST responses stubbed.

**They are not captures of a running WordPress.** The harness stubs the
theme's own REST routes, so what these show is the editor's behaviour — its
layout, its keyboard, its state — and not the server's. The server's half of
the contract is covered by the PHP suites, not by these pictures.

**How to reproduce them.** The harness is a page, not a script: open
`tests/configurator-browser-test.html` in a browser and it runs the same
assertions the CI step runs. To capture a picture, serve that page with the
theme's `configurator/` directory beside it and drive the headless browser:

```
google-chrome --headless --disable-gpu --no-sandbox \
    --hide-scrollbars --force-prefers-reduced-motion \
    --virtual-time-budget=6000 --window-size=1440,900 \
    --screenshot=editor-layout.png tests/configurator-browser-test.html
```

`--force-prefers-reduced-motion` is required: the panel slides in with a CSS
transition, and a headless browser whose virtual clock does not advance
animations would measure the panel while it is still off screen.

### 4.0.1 The zone's appearance is a disclosure, not a scroll

The zone's own design tokens were, in the first version of the redesign, at
the *end* of the Layout context — below the area map, the component list and
the whole component inspector. Measured in a browser rather than assumed:

| Viewport | Scrolling to reach the zone's colours |
|---|---|
| 1920 × 1080 | 0 px |
| 1440 × 900 | 68 px |
| 820 × 1180 | 0 px |
| 390 × 844 | 172 px |

So on a laptop and on a phone, changing the header's background meant
scrolling past the header's structure. The zone's appearance is now behind a
disclosure that sits **directly under the zone switcher**, where it is on
screen at every width, and where it belongs: it is a property of the zone
that was just chosen. Opened, it expands in place.

The Appearance context is unchanged — that context *is* the groups, so they
stay open there.

### 4.1 The information architecture

Four contexts, chosen because they are the four things an operator actually
does:

- **Layout** — header / main / footer, and for the structural zones a 3×3 map
  of the nine areas. Selecting an area shows its ordered components, and
  selecting a component shows its settings. The zone's own appearance tokens
  sit with the zone, not in a separate global list.
- **Appearance** — the settings that apply to the whole site: colours,
  typography, spacing, borders and shadows.
- **History** — the open transaction, the snapshots, and the revisions, with
  restore. These are the store the MCP tools already use; the editor reads
  them, it does not keep a second history.
- **Advanced** — custom CSS and custom JavaScript in full-height editors, the
  configuration as JSON, and the menus the design depends on.

Reordering and moving are done with buttons and a select, not with
drag-and-drop: a drag gesture has no keyboard equivalent, and a select moves a
component between any two of the eighteen areas in one control.

### 4.2 The action bar

Preview, Save and Discard live in a bar that is always on screen, above the
content, in the same place in every context. The state chip beside them says
which of four states the draft is in — `Saved`, `Unsaved changes`, `Staged`,
or `Locked by <actor>` — so "staged", "saved" and "committed" are never
confused with one another.

## 5. Previews and transactions

**The preview is WordPress's own rendering.** The Preview button asks the
theme for a signed preview credential and opens the staged site in a new tab.
There is no second renderer in JavaScript and no client-side approximation of
the theme. The credential is the same one the agent preview uses, and it is
minted only for the actor who owns the open transaction.

**Ownership is respected in both directions.** While another actor holds the
transaction, the editor says so, does not offer Save, and does not offer
Discard either — discarding rolls a transaction back, and somebody else's
staged work is not this session's to throw away. The refusal is enforced
twice: the button is disabled, and the app refuses again before it sends
anything.

### 5.1 The corrective pass: sessions, outcomes, and failed previews

The first implementation identified ownership by `Store::actor()` — the
WordPress username. Two browser sessions logged in as the same administrator
therefore looked like one editing operation. The GET also returned the staged
configuration to any matching actor, which is useful for reload recovery and
wrong for a second tab: a reload is the same session continuing, but a second
tab or a second browser is a different operation, and the server had no way to
tell them apart. The same conflation made an agent using the administrator's
account indistinguishable from the human editor.

**The correction is a per-transaction session capability, not a second login.**
When the configurator opens a transaction, the server mints a random token,
returns it once, and stores only its SHA-256 in the transaction row. The token
lives in `sessionStorage`: one tab, one editing operation, gone when the tab
closes. Every configurator request presents it in
`X-AgentShell-Editor-Session`. The account check remains the outer boundary;
the capability decides whether *this session* may read the staged draft or
mutate the row. An agent-opened row has no binding and keeps its historical
actor-only semantics — which is exactly why a browser cannot silently stage
into one.

**Reload recovery still works, and recovery of a lost capability is explicit.**
A reload in the same tab still holds the token. A lost token (a different tab,
a cleared storage, a row opened by an agent or by the pre-capability editor)
is not guessed and not handed over: the server reports whether the same
account may recover it, the editor asks, and the recovery endpoint rotates the
capability, audits the act with the staged state it inherited, and refuses any
transaction holding durable content work. The previous session stops being able
to act immediately.

**Save is a state machine over six outcomes.** The first version committed and
then re-read, and a failed read was reported as a failed save even when the
commit had landed. A network failure during the commit had no answer at all.
The correction records the phase (begin, stage, commit, refresh) and, when the
commit's outcome is unknown, asks
`GET /wp/v2/agentshell/transaction/outcome?id=…`. That route reads the audit
log's transaction id — the durable record of a commit, rollback or reclaim —
rather than comparing raw JSON that normalisation may have changed. A row that
is gone is not evidence of a commit; the audit record is. The client never
sends a second commit, never rolls back after a commit, and never says Saved
before the commit is confirmed. A confirmed commit whose refresh fails is
reported as saved-but-not-refreshed.

**A failed Preview asks before it abandons anything.** Validation before the
PUT rolls back only the empty transaction this Preview opened. A lost PUT
response is reconciled; a staged draft whose URL request failed is kept for
Save/Discard; a transaction that expired or changed ownership is not rolled
back at all. The local draft is kept in every case, so the operator can correct
and retry.

**A refused save keeps the draft.** The server's message is shown, the
operator's input is still there to correct, and the transaction that was
opened for the save is rolled back rather than left behind for somebody else
to find — but only when it was opened by that save, holds nothing, and is
still that session's.

## 6. The REST routes that were added

No MCP tool was added, and no MCP tool changed. The editor needed four things
the agent tooling had and the panel did not, and they are now routes on the
theme's own REST surface, all behind the same permission callback as the
config endpoint:

| Route | Method | Why the editor needs it |
|---|---|---|
| `/wp/v2/agentshell/transaction/recover` | POST | Explicitly recover an unbound or other-session transaction, rotating the capability. Requires `confirm: true`, the opening account, and no durable content work; audited. |
| `/wp/v2/agentshell/transaction/outcome` | GET | Reconcile a commit or rollback whose response was lost, by the audit log's transaction id. |
| `/wp/v2/agentshell/transaction/preview` | GET | A signed preview URL for the staged configuration. |
| `/wp/v2/agentshell/snapshots` | GET | The list of snapshots, to restore from. |
| `/wp/v2/agentshell/snapshots/restore` | POST | Restore a snapshot. It calls the same tool class the agent uses. |
| `/wp/v2/agentshell/revisions` | GET | The list of revisions. |
| `/wp/v2/agentshell/revisions/restore` | POST | Restore a revision, through the same tool class. |

The restore routes call `AgentShell_MCP\Tools\Restore_Snapshot` and
`AgentShell_MCP\Tools\Restore_Revision` rather than reimplementing them, so
"restore" means one thing in AgentShell.

One further theme-side change: `agentshell_preview_width()` honours a
requested width **only inside an authorised preview**. The width is read
through the existing preview-request gate, so an ordinary request cannot use
it, and a width outside 320–1600 pixels is ignored rather than clamped.

## 7. The evidence

| Suite | What it is | Count |
|---|---|---|
| `tests/configurator-editor-test.js` | The editor model and the transport, in Node. | 78 assertions |
| `tests/configurator-browser-test.html` | The editor in a headless browser, against the theme's real stylesheet, modules and markup. | 62 assertions |
| `tests/configurator-picker-test.js` | The choices the server's configuration produces. | 36 assertions |
| `tests/configurator-browser-test.html` | The editor in a headless browser, against the theme's real stylesheet, modules and markup. Four scenarios. | 126 assertions |
| `tests/configurator-enqueue-test.php` | Who may open the editor, and what an ordinary visitor is sent. | 8 assertions |
| `tests/configurator-state-test.js` | The pre-existing state-model suite, unchanged. | 44 assertions |

The browser suite drives the real interface through its public entry points
and covers: the dock's structure, the nine-area map, selecting an area and a
component, the navigation component's fields, editing a token, the history
and advanced contexts, discarding, saving, previewing, a refused save, the
keyboard navigation of the context rail, the case where another actor holds
the transaction, a refused save inside a transaction that was already open,
and the component picker — opening it, choosing from it with the keyboard,
cancelling it, filtering it, and using it while another actor holds the
transaction.

### 7.1 The defects the browser suite found

Three defects were found this way, and each would have shipped otherwise:

1. **The editor closed itself.** The view replaced the panel's whole class
   list on every render, which removed the class that slides the panel into
   view. The editor opened and then immediately slid away.
2. **The colour swatch did not follow the typed value.** A hex value typed
   into the field left the swatch showing the old colour, so the field gave
   no visual feedback at all.
3. **Discard was offered while another actor held the transaction.** The
   button was enabled, and the app would have rolled back the agent's staged
   work.

A fourth was found by reading the stylesheet rather than the interface: the
map button and the component panel shared a class name, so a plain section
inherited the button's border, its minimum height and its pointer cursor.

A fifth was found by the review of the whole stack: a failed save rolled
back a transaction it had not opened, so a refusal destroyed the operator's
own staged work.

## 8. Risks and migration requirements

| Risk | How it is handled |
|---|---|
| A redesigned interface rewrites the site's layout | The editor writes only what the operator changed. Opening the editor loads the configuration and does not write it back. The payload shape is unchanged, and a legacy three-slot configuration is still accepted and stored in the canonical shape by the existing normalisation. |
| The editor and the agent disagree about the state | One model, one endpoint, one validator, one transaction. The editor's payload is the agent's payload. |
| The editor needs privileges it should not have | The assets are enqueued only for `edit_theme_options`; the panel and the trigger are printed only for that capability; the REST routes use the existing permission callback. A visitor is sent no configurator bytes at all. |
| The editor is deployed without its assets | The release archive now carries the directory, and the archive checker requires the files the theme enqueues, so an archive that loses them is refused rather than deployed. That is a separate change, in `feature/configurator-packaging`. |
| The editor grows another script | The four modules are separate files, each is small, and the model is testable without a browser. The size of the editor is a reviewable number. |

## 9. Deliberately not done

These are **planned**, not implemented, and nothing in this file should be
read as claiming otherwise:

- **Forms and CAPTCHA settings.** The component inspector is built so that a
  form component can have its own fields; the form programme itself is not
  part of this change.
- **Import and export.** The Architecture section of the editor is the place
  it would go, and the export format already exists on the MCP side.
- **A client-side preview.** The preview is the real WordPress render. A
  second renderer would drift from the theme, and the drift would be
  invisible until somebody deployed it.
- **A full-screen editing mode.** The dock is 560 px and collapses to the
  full width on a narrow viewport. A separate full-screen mode was
  considered and not taken, because the dock already fits the work.

## 10. The pointer map

The normative statements about the human editor are in `AGENTS.md` §3.1. The
normative statements about the transaction it obeys are in `AGENTS.md` §6.1
and §6.2. The design record for the nine-area grid this editor draws is
`docs/NINE-AREA-ZONES-DESIGN.md`. The design record for the navigation
components it configures is `docs/NAVIGATION-BLOCKS-DESIGN.md`. The record for
the release packaging this editor depends on is
`docs/RELEASE-SIZE-AUDIT.md`.
