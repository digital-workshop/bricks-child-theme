# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Bricks Builder child theme (`digital-workshop/bricks-child-theme`, forked from `sinanisler/snn-brx-child-theme`). The core ongoing effort is **replacing third-party WordPress plugins with lean, theme-native features** — e.g. a custom Code Snippets manager instead of FluentSnippets, a custom Analytics dashboard instead of Independent Analytics, a custom Redirects/404 log instead of the Redirection plugin. New feature work should default to this pattern: a self-contained file under `includes/features/`, no new plugin dependency.

There is no local WordPress install and no automated test suite in this repo — verification is `php -l` plus manual/standalone reasoning (see "Verifying changes" below), and real testing happens on the live deploy targets.

The theme also carries a large set of stock features inherited unchanged from the upstream fork (White Labeling, Custom Post Types/Fields/Taxonomies, Security Settings, SMTP Settings & Mail Logs, custom Login/Register page, GSAP animations, OSM Map element, Cookie Banner, etc. — see README.md's "Key Features" list). Those aren't documented here since they haven't been touched; only the custom plugin-replacement features and shared infrastructure below are.

## Commands

**Lint a changed PHP file** (Windows/Laravel Herd environment):
```
/c/Users/Mario/.config/herd/bin/php.bat -l path/to/file.php
```

**Grep-sweep for the `?>`-in-comment bug** (see "PHP gotcha" below) — run on every touched PHP file before considering work done:
```
grep -n '^\s*//.*\?>\|^\s*#.*\?>' path/to/file.php
```

**Recompile German translations** after adding/changing any `__()`/`_e()` string. There is no `msgfmt` available in this environment, so `.mo` files must be built with a small hand-rolled Node compiler (no external deps) rather than gettext tools — reuse the pattern rather than reinventing it:
- Parse `languages/de_DE.po`, add new `msgid`/`msgstr` entries (byte-sorted, matching the file's existing order), write back — preserving every existing entry byte-for-byte (do not round-trip/reserialize unrelated entries; some contain `msgctxt` blocks that a naive parser can corrupt).
- Mirror the same new entries into `languages/snn.pot` with empty `msgstr`.
- Compile `languages/de_DE.po` → `languages/de_DE.mo` with a minimal PO→MO binary writer (magic number `0x950412de`, sorted key table, no gettext binary needed).
- Verify by reading the compiled `.mo` back and spot-checking a few keys resolve correctly, and that pre-existing entries (especially any `msgctxt` ones) are untouched.

## Release / deploy workflow

No CI/CD builds or deploys on every push — a version bump + a published GitHub Release is what makes a change installable. The flow is:
1. Bump the `Version:` header in `style.css` for every completed feature/fix (one bump per logical change, not per commit).
2. Commit and push to `main`.
3. The user manually creates a GitHub Release; `.github/workflows/release-zip.yml` then packages the repo (`git archive`) into `bricks-child-theme.zip` and attaches it to the release.
4. `includes/features/auto-update-snn-brx-github.php` is a self-hosted updater: it polls `api.github.com/repos/digital-workshop/bricks-child-theme/releases/latest` (cached via transient, 12h TTL) and hooks `pre_set_site_transient_update_themes` / `themes_api`, so every live site sees a normal "Update available" notice in wp-admin once the release is published and can update it like any other theme — no manual FTP/zip upload needed. It prefers a release asset literally named `{get_stylesheet()}.zip` and falls back to GitHub's auto-generated `zipball_url` if that asset is missing; keep the workflow's zip filename (`bricks-child-theme.zip`) in sync with the live sites' actual theme-folder slug or this match silently fails and updates fall back to the zipball.

Because of this, always confirm what the *last actual GitHub Release* was (not just the latest `Version:` in `style.css`, which may already be ahead) before writing release notes — check `git tag` / the repo's Releases page, since several commits can accumulate between releases.

**Git commits**: sign as `digital-workshop`, never add a Co-Authored-By Claude line. Only commit when explicitly asked.

## Architecture

### Bootstrap (`functions.php`)

`functions.php` itself is marked "DO NOT TOUCH" at the top — it only defines the `SNN_PATH`/`SNN_URL` constants and a flat list of `require_once` lines, one per feature file, plus a Bricks element registration block at the bottom. Adding a feature means creating a new file under `includes/features/` and adding exactly one `require_once` line here — logic does not belong in this file.

### Feature file convention (`includes/features/*.php`)

Every feature file is self-contained: `if ( ! defined( 'ABSPATH' ) ) { exit; }` guard, its own hooks, its own admin page(s) if any, its own DB table(s) if any. Functions are prefixed `snn_`. There's no class/namespace structure — plain procedural functions and `add_action`/`add_filter`.

### Admin UI

- **`SNN Settings` hub** (`settings-page.php`): a top-level menu page rendering a grid of cards (`$menu_items` array of `{slug, label, dashicon}`) linking to each feature's own submenu page (`add_submenu_page('snn-settings', ...)`). When adding a settings page reachable from this hub, add it to `$menu_items` too.
- A few features are their own top-level menu instead of a hub submenu (e.g. Analytics — `add_menu_page(...)`), when they need prominent placement.
- **Screen/page detection**: match against `sanitize_key( wp_unslash( $_GET['page'] ) )` directly, never `get_current_screen()->id` — predicting WP's internal hook-suffix naming for a submenu registered under a custom parent slug is fragile and has caused real bugs (a CSS-not-loading bug shipped this way once).
- **Shared design system** (`admin-ui-design.css` / `admin-ui-design.php`): a toggle-switch component (`.snn-admin-toggle` / `.snn-admin-check-label` + `.snn-admin-toggle-slider`, hidden-checkbox + sibling-`<span>` pattern — more robust than styling the native checkbox with `appearance:none`, which was tried and failed) plus an optional fuller card-based redesign (`.snn-admin-ui` scope) used only on a few pages (Post Types, Custom Fields, Taxonomies). The CSS is enqueued conditionally via an allowlist function (`snn_admin_ui_design_page_slugs()`) checked against the current `page` query var — add a new page's slug there to opt in, rather than loading the stylesheet everywhere.
- Native WordPress **postboxes** (`add_meta_box()` / `do_meta_boxes()`) are used for drag-and-drop/collapsible dashboard cards (Analytics). To get a genuinely even 2-column layout (not the narrow-sidebar post-edit-screen proportions), wrap in the same markup the core Dashboard widgets use — `#dashboard-widgets-wrap > #dashboard-widgets.metabox-holder.columns-2 > #postbox-container-1/-2` — not `#poststuff`/`#post-body`, which hardcodes a ~280px narrow column regardless of content.

### Database tables

Several features use their own `$wpdb->prefix . 'snn_*'` tables (Analytics pageviews/markers, Redirects, 404 log) rather than post meta or options, for scale. The established pattern (see `analytics.php`, `redirects.php`):
- A versioned `dbDelta()` migration function, gated by `if ( (int) get_option( '..._db_version_option', 0 ) >= CURRENT_VERSION ) return;`, hooked on `admin_init` (not an activation hook — themes don't get reliable activation hooks across a GitHub-zip-based update the way plugins do, so this "self-heals" on the next admin page load instead).
- Bump the version constant and add the new/altered `CREATE TABLE` block to the same SQL string when changing schema; `dbDelta()` handles multiple `CREATE TABLE` statements in one call.

### Code Snippets (`custom-code-snippets.php`)

Snippets are stored as a custom post type (`snn_code_snippet`, one per snippet, with type/location/priority/tags in postmeta), but on save/toggle/delete are **compiled into static PHP files** under `wp-content/uploads/snn-code-snippets/{location}.php` (4 locations: immediate/frontend_head/frontend_footer/admin_head). Normal page loads just `include()` the compiled file — no DB query at runtime. Falls back to a live DB-query+`eval()` path if the uploads directory isn't writable.

Each PHP snippet is syntax-validated individually before compiling (wrapped in a uniquely-named never-called function inside try/catch(ParseError) — catches parse errors `token_get_all()` alone misses) and function-name-collision-checked against every other active snippet and the current PHP process (catches the case where the same function is declared by an external plugin too — see `snn_snippets_get_function_collisions()`). A broken/colliding snippet is auto-disabled (set to draft) rather than taking down the whole compiled file.

### Analytics chart rendering (`analytics.php`)

The "Pageviews per day" chart is a hand-rolled SVG line chart (`snn_analytics_render_line_chart()`) — no JS charting library. Multiple series (pageviews, visitors) share one scale computed from the combined min/max; per-point hover values use native `<title>` tags inside `<circle>` elements rather than a JS tooltip library. Date markers are rendered as dashed vertical lines with matching IDs (`#snn-chart-marker-{date}`) so the marker list below the chart can highlight/scroll to one on click via plain DOM class toggling. Follow this pattern (SVG + native tooltips + ID-based cross-referencing) rather than reaching for a charting dependency if this chart needs to grow.

### Admin UI state across a full page reload

Several admin pages (e.g. `redirects.php`) use plain `<form method="post">` actions with no AJAX/`preventDefault` — every action is a real page reload. Where there's also client-side-only UI state to preserve across that reload (which tab is active, sort order, etc.), the established fix is `sessionStorage`, not a query-string param or server-side session: persist the state on every client-side state change, and restore it once on `DOMContentLoaded` before anything else renders. This avoids a server-side redirect dance and keeps the tab-switching logic entirely client-side.

### PHP gotcha: `?>` inside a `//` comment

A literal `?>` inside a `//` (or `#`) line comment terminates PHP mode immediately — this has caused real fatal errors in this codebase (both in shipped code and in code written mid-session). Always grep-sweep for `^\s*//.*\?>` / `^\s*#.*\?>` on any touched PHP file before considering it done (see Commands above). Does not apply to `/* */` block comments.

### Bricks Builder elements (`includes/elements/*.php`)

Registered via `\Bricks\Elements::register_element( SNN_PATH . 'includes/elements/xxx.php' )` inside an `add_action('init', ..., 11)` callback in `functions.php`. Some elements (GSAP-related) are gated behind a settings toggle (`snn_get_interactions_settings()['enqueue_gsap']`) and only registered/enqueued when enabled.

### Verifying changes

There's no WordPress install here to run code against. This session's working pattern for anything nontrivial (a new compiler/validator function, SQL query logic, a data importer, string parsing):
1. Copy the function(s) under test verbatim into a throwaway PHP script in the scratchpad, with minimal hand-written stubs for the WordPress functions they call (`get_option`, `$wpdb`, etc.).
2. Write assertions covering the normal case, edge cases, and at least one case that used to be a real bug.
3. Run it with the Herd `php.bat` from Commands above.

This has caught real bugs before shipping (e.g. a false-positive self-collision in the snippet compiler, an XSS-unsafe tooltip, incorrect SVG chart math) — worth doing for anything touching data integrity or security, not just UI tweaks.
