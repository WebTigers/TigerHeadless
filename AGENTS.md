# AGENTS.md — working in tiger-headless

Conventions for an AI assistant (or a new contributor) in this repo. Platform-wide conventions live in
[tiger-core's AGENTS.md](https://github.com/WebTigers/TigerCore/blob/main/AGENTS.md); read that first,
and grep its **CAPABILITIES.md** before building anything that might already exist in Tiger.

## What this repo is

The **install authority** every non-interactive front-end drives: the WHM plugin, Softaculous, a
provisioning script. `bin/tiger-headless` takes a spec ([SPEC.md](SPEC.md)) and produces a JSON result.
It is a **front-end over Tiger's own library**, not a second installer.

## The rules

- **Never re-implement an install step.** From `configure` on, every step calls a Tiger authority —
  `Tiger_Install`, `Tiger_Db_Migrator` over `Tiger_Module_Installer::migrationPaths()`,
  `Tiger_Module_Installer`, `Tiger_Theme::activate()`, `Tiger_Update_Core`, `Tiger_Backup`. If a step
  needs something Tiger doesn't expose, **add the seam to tiger-core** (as `Tiger_Theme::activate()` was
  added for this repo), guard it with `method_exists` for older bundles, and call it.
- **Zero runtime dependencies.** The tool runs *before* Tiger (and Composer) exist on the host. `src/`
  is plain PHP 8.1 with its own tiny autoloader; PHPUnit is dev-only.
- **stdout is the contract.** Only the JSON result goes to stdout. Progress → stderr, behind `--verbose`.
- **Fail closed, name the step.** A download without a matching published `.sha256` is not installed. A
  failure names its step in `error.step` and exits `1`. Nothing "warns and continues" through an
  integrity check.
- **`expose` stays last.** The docroot shim is the final step so a half-finished tree is never
  web-reachable and the ledger never says installed before every step passed.
- **Above the docroot is the default and must not be dropped silently.** `layout: docroot` is an explicit
  opt-in validated by the spec.
- **Short arrays, docblocks on every class and public method, `Throwable` in catches** — house style.

## Tests

```
composer install
vendor/bin/phpunit --testsuite unit                       # no DB, no network, no bundle
TIGER_HEADLESS_BUNDLE=/path/tiger-1.0.17.zip \
TIGER_HEADLESS_DB_HOST=127.0.0.1 TIGER_HEADLESS_DB_NAME=tiger_headless_test TIGER_HEADLESS_DB_USER=root \
vendor/bin/phpunit --testsuite integration               # real install into a temp home; DROPS EVERY TABLE in that DB
TIGER_HEADLESS_SYMLINK=off …                              # the copy-fallback case
TIGER_HEADLESS_NETWORK=1 …                                # also installs Directory modules + a theme
```

The integration suite is the acceptance list from TIGER-124, run for real: a served site, the four
host cases (MariaDB / MySQL 8 × symlink on / off), each failure mode broken deliberately and checked
for its named step and exit code, resume after failure, no-op after success. **A green happy path
alone proves nothing about the error contract** — when you add a guard, add the test that breaks it.

## Releasing

`Tiger_Headless_Version::VERSION` must equal the tag (`v<semver>`); CI asserts it. Keep
[CHANGELOG.md](CHANGELOG.md) current.
