# Lighthouse

> A small CLI for keeping markdown documents in sync with their rendered HTML. Watch a folder, get a static site.

## What it does

`lighthouse` watches a folder of Markdown files and rebuilds a static site whenever they change. It is deliberately small: one binary, one config file, no plugin system.

The tool is built for people who write in a single editor and serve the result with the same nginx instance that serves everything else. There is no admin UI because there is no need for one.

## Install

```bash
curl -sSf https://lighthouse.dev/install.sh | sh
```

Or grab a release binary from the releases page.

Once installed:

```bash
lighthouse init        # scaffold a sample site
lighthouse watch       # rebuild on change
lighthouse build       # one-shot build
```

## Configuration

A single file, `lighthouse.toml`:

```toml
[site]
title = "My Notes"
base_url = "https://example.com"

[markdown]
extensions = [".md", ".markdown"]
```

Everything else is a flag.

## Documentation

Full reference lives at https://lighthouse.dev/docs.

Topics covered:

- Markdown extensions
- Theme overrides
- Custom renderers
- Deployment

## About

Lighthouse is built and maintained by a single person. Bug reports and patches welcome on GitHub.