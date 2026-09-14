# tiger-headless

**The non-interactive installer for [Tiger](https://github.com/WebTigers/Tiger).** One JSON spec in,
one JSON result out, an honest exit code. It is what a WHM plugin, a Softaculous package, or a
provisioning script calls when there is nobody at a browser — the install authority the
[web installer](https://github.com/WebTigers/TigerInstall) is the human face of.

```
tiger-headless install  --spec=<file|->  [--verbose]
tiger-headless upgrade  --app-root=<dir> [--version=<tag>]
tiger-headless backup   --app-root=<dir> [--components=database,media,modules,platform]
tiger-headless restore  --app-root=<dir> --archive=<zip> [--components=…]
tiger-headless status   --app-root=<dir>
tiger-headless discover --root=<dir> [--depth=4] [--check-updates]
```

No prompts, no HTML, no colour. `stdout` carries only the JSON result; `--verbose` streams step
progress to `stderr`. Exit `0` ok · `1` a step failed (`error.step` names it) · `2` invalid spec or
usage.

## What it does

Given a pre-created database, an app root, a docroot, a site URL and an admin:

| Step | What happens |
|---|---|
| `requirements` | PHP 8.1+, the extensions Tiger needs, writable paths, **and a live connection to the database** — before anything is downloaded |
| `fetch` | resolve the newest `WebTigers/Tiger` release carrying `tiger-<version>.zip`, download it, verify it against the published `.sha256` (**fail closed**) — or take a local bundle |
| `extract` | the vendored app tree into the app root, **above the docroot** |
| `configure` | `application/configs/local.ini` at `0600` — DB credentials, site name, locale — merged, never replaced; secrets minted by `Tiger_Install::provisionSecrets()` |
| `migrate` | Tiger's own migrator over `Tiger_Module_Installer::migrationPaths()` (core + bundled modules); module assets published |
| `storage` | the writable runtime dirs (`Tiger_Install::provisionStorage()`) |
| `owner` | the founding org + admin (`Tiger_Install::createOwner()` — Tiger's password policy applies) |
| `modules` | each requested Directory slug installed through `Tiger_Module_Installer` and activated |
| `theme` | the requested theme installed and made active (`Tiger_Theme::activate()`) |
| `assets` | docroot asset links — **copied where `symlink()` is disabled** |
| `agent` | optional: an MCP credential for the owner + `/mcp` switched on |
| `expose` | the docroot front-controller shim — **written last**, so a half-finished install is never web-reachable |

Every step from `configure` on calls Tiger's own library. Nothing about an install is re-implemented
here; this is a front-end over the same authorities `bin/tiger` and the web installer use.

## The result

```json
{
  "ok": true,
  "verb": "install",
  "version": "1.6.4",
  "layout": "above-docroot",
  "already_installed": false,
  "steps": [
    { "step": "requirements", "status": "ok", "detail": "PHP 8.3.12, 10.11.6-MariaDB, symlink() available", "ms": 3 },
    { "step": "fetch",        "status": "ok", "detail": "tiger-1.0.17.zip (v1.0.17, sha256 verified)", "ms": 2140 },
    …
    { "step": "expose",       "status": "ok", "detail": "front controller written to /home/u/public_html/index.php", "ms": 2 }
  ],
  "error": null,
  "admin_url": "https://example.com/admin",
  "login": { "username": "owner", "email": "owner@example.com" }
}
```

On failure `ok` is `false`, `error` is `{ "step": "<name>", "message": "…" }`, and the exit is `1`.

## Resumable, idempotent, honest

A ledger at `<app_root>/var/headless/state.json` records each step. A re-run after a failure
**resumes from the failed step**; a re-run after success is a **no-op** that reports
`"already_installed": true`. The ledger is bound to the database + paths of the spec, so an installed
tree can never be silently repointed at a different database — that is refused with
`error.step = "ledger"`.

## Already-installed detection

Two levels:

- **Per target.** `install` recognises a Tiger that is already live at `app_root` — whoever installed
  it (the web installer, Composer, or this tool with its ledger gone) — by the tree, `local.ini`, and a
  database that has the schema and a founding org. Same database → it is **adopted**: the ledger is
  written and the result says `already_installed: true, adopted: true`; nothing runs. A different
  database → refused. A tree with the schema but no owner (a web install that died on the last screen)
  resumes and finishes.
- **Per host.** `discover --root=/home/cpuser` (or `/home`) walks a directory and reports every Tiger it
  finds — app root, docroot (mapped from the front-controller shim), layout, version, whether it is a
  live site, its database name — without booting any of them. `--check-updates` adds the latest
  tiger-core version, an `update_available` flag per install, and a `summary` (live count,
  `updates_available`, version histogram). Run it as the account user for one account, or as root over
  `/home` for the whole server — the same command, a different root.

Two subdomains in one cPanel account is the ordinary case: two specs, two databases, app roots at
`/home/<user>/<subdomain>/tiger-app`, docroots at `public_html/<sub>`. The account's existing site and
its `.htaccess` are never touched.

## The spec

See [SPEC.md](SPEC.md). The short form:

```json
{
  "db":    { "name": "cpuser_tiger", "user": "cpuser_tiger", "password": "…" },
  "paths": { "app_root": "/home/cpuser/tiger-app", "docroot": "/home/cpuser/public_html" },
  "site":  { "url": "https://example.com", "name": "Example" },
  "admin": { "email": "owner@example.com", "password": "…" },
  "theme": "theme-grey-mist"
}
```

Pass it on stdin (`--spec=-`) so credentials never touch the disk.

## Layout

The default keeps the app **above the docroot** — the security story against `wp-config.php`. A
caller that genuinely cannot express that opts in to the `docroot` layout explicitly; the result
reports which layout was used. The installer never drops the default silently to fit anyone's
packaging.

## Requirements

PHP 8.1+ with `pdo_mysql`, `zip`, `mbstring`, `json`; `curl` or `allow_url_fopen` to download (or
supply `source.bundle`). MariaDB 10.4+ or MySQL 8. Works with and without `symlink()`. No Composer,
no dependencies — the tool runs before Tiger exists on the host.

## Install the tool

```bash
composer require webtigers/tiger-headless      # vendor/bin/tiger-headless
# or
git clone https://github.com/WebTigers/TigerHeadless && php TigerHeadless/bin/tiger-headless …
```

## License

BSD-3-Clause. Tiger™ and WebTigers™ are trademarks of WebTigers.
