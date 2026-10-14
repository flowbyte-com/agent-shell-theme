# Screenshot storage — confirmed privacy/security defect

**Status:** confirmed, open. Not fixed by the capture-hardening work in
`fix/availability-screenshot-deadlock`; fixing it needs its own approval
because it changes where screenshots live and how they are served.

## Finding

`agentshell_screenshot` writes every capture to
`wp-content/uploads/agentshell-screenshots/` and returns a plain
`uploads` URL. That directory is:

- **World-readable.** Verified on the live LocalWP site:
  an unauthenticated `GET` of a stored capture returns `200`.
- **Never pruned.** Nothing deletes these files. The live site has accumulated
  7 PNGs since 2026-10-12, growing without bound.
- **Reachable by anyone who can guess or enumerate the filename.** Filenames
  are `shot-<UTC timestamp>-<6 chars>`. `wp_generate_password( 6, false, false )`
  is not a secret: the timestamp is public and the space is small enough to
  brute-force. Treat the URL as effectively public.

## Why this matters

A capture is a rendering of whatever the agent was looking at, including:

- **Unpublished drafts.** A preview capture renders the draft's content and
  stores it at a URL no authentication guards. This is the sharpest case.
- Anything behind a preview key. `agentshell_screenshot(profile=…)` renders a
  signed preview URL; the resulting PNG is then readable without the key.
- Internal-only hosts and pages on the local network, rendered verbatim into
  a public URL.

The existing `SSRF` host check in `tools/class-screenshot.php` limits *which*
host may be captured. It does nothing about *who can read the result*.

## Recommended design (not implemented)

1. **Private storage.** Write captures outside the web-served uploads tree —
   e.g. `wp-content/agentshell-private/screenshots/`, protected by an
   `.htaccess`/`nginx` deny, or moved entirely outside `wp-content`.
2. **Authenticated access.** Serve through a REST endpoint that checks the
   caller's existing AgentShell credentials (application password or
   `AGENTSHELL_REST_TOKEN`) before returning the image. Return an opaque id,
   not a filesystem path.
3. **Retention.** Delete captures on a bounded schedule (e.g. 24h, or N most
   recent). Screenshots are an agent feedback-loop artefact — their value
   expires quickly — so retention can be aggressive.
4. **Independent deletion.** Add an explicit `agentshell_delete_screenshot`
   so an agent can clean up as it goes.

## Why this was not fixed here

The approved work was the availability defect: two concurrent captures
deadlocked the LocalWP PHP-FPM pool and took the whole site down. Items 1–4
above change the storage location, the response shape, and add a new tool.
That is a storage redesign with its own compatibility and migration
questions, so it was deliberately left out of the deadlock fix rather than
folded in silently.

Until it is addressed, treat any URL returned by `agentshell_screenshot` as
publicly readable, and do not rely on it to keep draft content confidential.