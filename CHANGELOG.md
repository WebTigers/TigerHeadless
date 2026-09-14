# Changelog

All notable changes to **tiger-headless**. Format follows [Keep a Changelog](https://keepachangelog.com/);
SemVer.

## [Unreleased]

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
