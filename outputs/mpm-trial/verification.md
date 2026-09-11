# AgentShell / MPM trial — 11 September 2026

Prepared PHP fixes applied to the live symlinked repository. PHP syntax checks and git diff whitespace checks pass.

- Before: 55 tool entries, duplicate agentshell_list_widgets; get_config returned CSS tokens only.
- After: 54 unique tool names; get_config includes zones, design, layout, custom_css, custom_js, widgets and tokens.
- Live local homepage ID 7 now contains the current README-based MPM introduction; retrieved raw HTML equals home.html exactly.
- Design transaction validated with zero errors and committed. Snapshot: before-mpm-readme-trial-20260911. Original page and config saved here.
- Desktop and 390px mobile viewport reviewed. Quick-start anchor and native disclosure work. No horizontal document overflow.
- Found and fixed mobile menu layering in style.css Section 9: expanded navigation now stays in header flow. Visually verified menu, link navigation and automatic collapse.

Remaining observations: transaction preview diffs only flattened tokens, omitting zone content; mapped legacy palette fields override duplicate custom CSS variables. Use semantic palette tools. Native screenshot backend is unavailable; browser verification works. Existing unused hello-world and optional static-auth-token warnings remain; application-password authentication works.

No header.php, footer.php, style.css Sections 3–4, or AGENTS.md edits.
