# WP Content Audit

Finds the SEO gaps hiding in your published content — posts with no featured image, thin content, missing excerpts, no internal links, and posts you haven't touched in over a year — in one actionable report.

## Why

Most sites don't have an SEO problem, they have a *content hygiene* problem: a hundred posts published years ago, some with no featured image, some 80 words long, some linking to nothing on your own site. Search engines notice. This plugin scans everything and hands you the exact list of posts to fix, with edit links.

## Features

- **Five checks per post** — no featured image, thin content (under 300 words), missing excerpt, no internal links, stale (not modified in 12 months).
- **Batched scan** — 50 posts per request with automatic continuation, so it works on sites with thousands of posts without timing out.
- **Actionable report** — summary counts per issue plus a table linking straight to each post's edit screen.
- **Configurable thresholds** — `wca_min_words`, `wca_stale_months` and `wca_post_types` filters.
- **Smart internal-link detection** — relative URLs count as internal, protocol-relative `//` links are checked by host, `#anchor` links are ignored.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it from **Plugins**.
3. Open **Tools → Content Audit** and click **Run audit**.

## Uninstall

Deleting the plugin removes its stored audit results.
