<!-- tiger:doc title="Headless install" collection="install" visibility="admin" -->

# Headless install

`tiger-headless` installs Tiger with nobody at a browser: a hosting panel, a package installer or a
script hands it a JSON spec and reads back a JSON result. It is the same install the web installer
performs — the same migrations, the same owner record, the same asset links — driven by a program
instead of a person.

## When you would use it

- A **WHM plugin** installing Tiger for a cPanel account on one click.
- A **Softaculous** or Installatron package.
- A **provisioning script** that stands up many sites.

If you are installing one site by hand, use the web installer instead.

## The spec

```json
{
  "db":    { "name": "cpuser_tiger", "user": "cpuser_tiger", "password": "…" },
  "paths": { "app_root": "/home/cpuser/tiger-app", "docroot": "/home/cpuser/public_html" },
  "site":  { "url": "https://example.com", "name": "Example" },
  "admin": { "email": "owner@example.com", "password": "…" },
  "theme": "theme-grey-mist",
  "modules": ["docs"]
}
```

The database must already exist — the panel creates it and passes the credentials in. Pass the spec on
stdin (`--spec=-`) so passwords never touch the disk.

## Reading the result

`ok` says whether the install finished; `steps` lists every step with `ok`, `skipped` or `failed` and
a one-line detail; `error.step` names the step that stopped the run. On success `admin_url` is where
the new owner signs in.

Run it again after fixing a problem and it resumes from the step that failed. Run it again after
success and it does nothing, reporting `already_installed`.

## Other verbs

`upgrade` moves the site to the latest tiger-core release (or a named one); `backup` and `restore`
wrap TigerBackup; `status` reports what is installed without touching the network.
