# The install spec

What a front-end hands `tiger-headless install`. JSON, one object. `Tiger_Headless_Spec` is the
authority; this page describes it. Every problem with a spec is reported at once (exit `2`,
`error.step = "spec"`, `problems[]`), so a caller fixes the whole document in one round trip.

```json
{
  "db":     { "host": "localhost", "port": 3306, "name": "cpuser_tiger", "user": "cpuser_tiger", "password": "…" },
  "paths":  { "app_root": "/home/cpuser/tiger-app", "docroot": "/home/cpuser/public_html" },
  "layout": "above-docroot",
  "site":   { "url": "https://example.com", "name": "Example" },
  "admin":  { "username": "owner", "email": "owner@example.com", "password": "…", "org": "Example" },
  "locale": "en",
  "modules": ["docs"],
  "theme":   "theme-grey-mist",
  "agent":   false,
  "source":  { "version": "", "bundle": "", "sha256": "" }
}
```

## Fields

| Field | Required | Default | Notes |
|---|---|---|---|
| `db.host` | | `localhost` | |
| `db.port` | | `3306` | 1–65535 |
| `db.name` | **yes** | | The database **already exists** — the caller created it (cPanel, Softaculous, WHM all do). Tiger never creates databases. |
| `db.user` | **yes** | | Full privileges on `db.name`. |
| `db.password` | | `""` | May not contain `"` (it is written into an INI file). |
| `paths.app_root` | **yes** | | Absolute. Created if missing (its parent must be writable). Holds `application/`, `vendor/`, `local.ini`, … |
| `paths.docroot` | **yes** | | Absolute. The web server's document root for the site. Created if missing. |
| `layout` | | `above-docroot` | See below. |
| `site.url` | **yes** | | `http(s)://host[…]`; trailing slash trimmed. Used for `admin_url` in the result. |
| `site.name` | | `Tiger` | Written to `tiger.site.name`. |
| `admin.username` | | *(none)* | Optional; the email is the login either way. |
| `admin.email` | **yes** | | Must be a valid address — it is the founding account's identity. |
| `admin.password` | **yes** | | Checked against Tiger's own password policy at the `owner` step (length, complexity as configured). |
| `admin.org` | | `site.name` | The founding organization's name. |
| `locale` | | `en` | Language code (`en`, `es`, …). Anything but `en` is written to `tiger.i18n.default`. |
| `modules` | | `[]` | Directory slugs (the `WebTigers/Vendors` feed). **Free listings only.** Installed and activated in order. |
| `theme` | | *(none)* | A Directory theme slug (e.g. `theme-grey-mist`). Installed and made the active theme. |
| `agent` | | `false` | Mint an MCP credential for the owner and enable `/mcp` (the TIGER-90 connect handshake). The token is returned **once**, in `result.agent.token`. |
| `source.version` | | *(latest)* | A `WebTigers/Tiger` release tag (`v1.0.17` or `1.0.17`). Empty = the newest release carrying a bundle. |
| `source.bundle` | | | Absolute path to a local `tiger-<version>.zip` — skips the download (offline / air-gapped / a host that pre-fetched it). |
| `source.sha256` | | | Verify `source.bundle` against this digest. Downloads are **always** verified against the release's published `.sha256`; a local bundle is verified only when this is supplied, and the result says so. |

## Layouts

**`above-docroot`** (default) — the app lives outside the document root; only a generated
`index.php` shim, `.htaccess`, and asset links/copies go in the docroot. `local.ini` (DB
credentials + minted secrets) is not web-reachable. The spec is **refused** if `docroot` lies inside
`app_root` or `app_root` inside `docroot`.

**`docroot`** — explicit opt-in for a caller that cannot express the above. `paths.docroot` must be
exactly `paths.app_root + "/public"`; the bundle's own `public/index.php` serves the app and no shim
is generated. The result reports `layout` so the caller knows which posture it got.

## Adoption

If `app_root` already holds a live Tiger on the **same** database — the schema is present and a
founding org exists — the install is **adopted**: the ledger is written, nothing runs, and the result
says `already_installed: true, adopted: true`. That covers a site the web installer or Composer put
down. A live Tiger on a **different** database is refused at `requirements`.

## The fingerprint

Two runs are "the same install" when `db.name`, `paths.app_root` and `paths.docroot` match. The
ledger (`<app_root>/var/headless/state.json`) is bound to that fingerprint: a re-run with the same
fingerprint resumes or no-ops; a different fingerprint against an existing ledger is refused
(`error.step = "ledger"`) — an installed tree is never silently repointed at another database.

## Secrets

`db.password` and `admin.password` are consumed and never echoed back. Pass the spec on **stdin**
(`--spec=-`) rather than a file when the host is shared, so nothing lands on disk.
