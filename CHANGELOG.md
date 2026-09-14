# Changelog

All notable changes to **tiger-headless**. Format follows [Keep a Changelog](https://keepachangelog.com/);
SemVer.

## [Unreleased]

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
