# AGENT-PROMT-EXAMPLE.md — historical prompt fragments (non-normative)

> **Archive notice.** This file is preserved for reference only. It is **not** a normative contract. The canonical agent guide is [`AGENTS.md`](./AGENTS.md). Any text in this file that disagrees with `AGENTS.md` is superseded.
>
> Specifically, the "Unbreakable Grid Protocol" wording below describes the legacy `template-parts/grid-areas.php` generator that the v2 flex-column shell no longer ships with. The protocol itself (1fr rule, descendant scope, quoted rows) is captured in `AGENTS.md` §5 as an absolute system law; this file is the historical artefact, not the law.

## Original Rule Zero: the Shell Hierarchy

| File / Zone | Status | Role |
| :--- | :--- | :--- |
| `header.php`, `footer.php` | **Forbidden** | Static shell HTML — structural only. |
| `template-parts/grid-areas.php` | **Grid OS Only** | Only edit to fix track logic or selector scope. |
| `style.css` (Sec 3 & 4) | **Forbidden** | Static Grid foundation. |
| `style.css` (Other) | **Editable** | Add CSS targeting `#zone-header`, `#zone-nav`, etc. |
| `widgets/` | **Editable** | Add stable widgets as `*.php` files + register in `.index.json`. |
| **REST Config** | **Primary** | Toggle `sidebar_enabled`, update Design Tokens, inject `custom_css`/`custom_js`. |

(Superseded: the bilateral widget registry is the union source of truth — see `AGENTS.md` §1 and §4.1. Tool counts and tool lists are in `AGENTS.md` §2.)

## Original "Unbreakable Grid Protocol" wording

1. **The Rule of 1fr:** the base `#agentshell-root` must always have `grid-template-columns: 1fr;`. This ensures the content expands to fill the 1200px (or 1280px) max-width by default.
2. **Descendant Scoping:** never use `#agentshell-root.sidebar-enabled`. Always use `.sidebar-enabled #agentshell-root`. The class is on the `<body>`, and the grid is the child.
3. **No String Smashing:** when generating `grid-template-areas` in PHP, every row MUST be individually quoted and separated by spaces.
    * **Bad:** `"header main footer"`  ← cells merge into one column
    * **Good:** `"header" "main" "footer"`  ← properly quoted rows

This is the historical source of the protocol. The current, authoritative wording is in `AGENTS.md` §5.

## Original "Safe Edit" guidance (preserved, not normative)

- **Do not write PHP into content.** Use widgets or Web Components.
- **Do not bypass the bilateral widget registry** with custom REST endpoints.
- **Do not depend on `wp_options` as the sole source** — the registry is the union of file-based and config-registered widgets.
- **Do not invent `fetch()` paths for widgets** — see `AGENTS.md` §4.3.
- **Do not reimplement `wp_loop` or `wp_core`** in a widget — see `AGENTS.md` §4.4.

For the canonical, current contract — including the data-* hydration law, the no-fetch boundary, the bilateral registry, the 45+ tool surface, the three grid rules, and the working pattern — see `AGENTS.md`.
