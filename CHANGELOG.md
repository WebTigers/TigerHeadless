# Changelog

All notable changes to **tiger-headless**. Format follows [Keep a Changelog](https://keepachangelog.com/);
SemVer.

## [Unreleased]

## [0.5.0] — 2026-09-14

### Added

- **`login --app-root=<dir> [--email=<user>]`** — mints a one-time, 2-minute, single-use sign-in link
  for the site's founding admin (or a named user) via tiger-core ≥ 1.8.0's magic-link login and
  returns its path; the caller (a hosting panel's "Log in" button) prefixes the host and redirects.
  Older cores are refused with the version to update to.

## [0.4.0] — 2026-09-14

Both found by the first one-click install through TigerWHM on a real cPanel account.

### Fixed

- **`.htaccess` is merged, not skipped or clobbered.** cPanel writes a `.htaccess` (the domain's PHP
  handler) into a new subdomain's docroot before anything is installed; `expose` only wrote Tiger's
  rules when no file existed, so the front controller never landed and every route 404'd. Existing
  content is kept and Tiger's block appended once under a marker (`mergeHtaccess()`).
- **Every `public/_*` entry reaches the docroot** at the `assets` step — a theme's asset base above
  all — linked or copied, so an installed theme's CSS serves on the first request. The integration
  test now fetches the CSS rather than checking the HTML mentions it.

## [0.3.0] — 2026-09-14

### Added

- **`check --spec=…`** — the requirements step alone (PHP, extensions, writable paths, a live database
  connection), changing nothing; reports `existing` when a live Tiger is already at the app root. What a
  panel runs before it shows a form the user cannot complete.
- **`config` in the spec** — extra `local.ini` keys written at `configure` (a host's defaults: mail
  relay, module posture). Installer-owned keys (`tiger.db.*`, the secrets) are refused.

## [0.2.0] — 2026-09-14

### Added

- **Adoption of existing installs.** A live Tiger at the app root — put down by the web installer,
  Composer, or a run whose ledger is gone — now reads as `already_installed` (+ `adopted: true`) when
  the spec names the same database; the ledger is written and nothing runs. Before, it skipped extract
  and failed at the owner step. Detection is the tree + `local.ini` + a database holding the schema
  and a founding org, checked with one PDO probe and no Tiger boot.
- **`discover --root=<dir> [--depth] [--check-updates]`** — every Tiger under a directory with app
  root, docroot (from the shim), layout, version, live state, database name, and optionally whether a
  newer core exists. Boots nothing. The basis for a hosting panel's fleet view (TIGER-39).
- `status` now reports the live probe (`installed`) separately from the ledger (`ledger`).
- `discover` carries a `summary` — live / unknown / `updates_available` / `latest` / a version
  histogram — so a server manager reads the roll-up without counting rows.

### Fixed

- **The app root is created as deep as needed.** The cPanel convention is
  `/home/<user>/<domain>/tiger-app` and `<domain>/` does not exist before the first install; only one
  level was being created, so a first install on a subdomain failed at `requirements`. The nearest
  existing ancestor is what must be writable. The integration suite now installs in that shape.
- **stdout is JSON only, always.** PHP's own diagnostics (a vendored library's deprecation on a newer
  PHP) now go to stderr; a `curl_close()` deprecation on PHP 8.5 had landed in the result document.

## [0.1.0] — 2026-09-14

First release (TIGER-124).

### Added

- `tiger-headless install --spec=<file|->` — the non-interactive install: requirements (including a
  live database check) → fetch + sha256 verify (fail closed) → extract above the docroot → `local.ini`
  at 0600 + minted secrets → migrate → storage → owner → Directory modules → theme → assets (copied where
  `symlink()` is disabled) → optional MCP credential → the docroot shim, written last.
- A JSON result naming every step and its outcome, the admin URL on success, the failing step on
  failure; exit `0` / `1` / `2`.
- A ledger (`var/headless/state.json`): resume from the failed step, no-op after success, refuse to
  repoint an installed tree at a different database.
- `upgrade`, `backup`, `restore`, `status` — thin wrappers over `Tiger_Update_Core` and `Tiger_Backup`.
- `layout: above-docroot` (default, enforced by the spec) and `layout: docroot` (explicit opt-in).
- Unit suite (spec, pipeline engine, release resolution, ini merge) and an integration suite that runs
  a real install against a bundle + database, with and without `symlink()`, and breaks each guard.
