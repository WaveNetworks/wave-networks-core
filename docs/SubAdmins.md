# Core sub-admins — the contract (nokemo task #2396)

A **sub-admin** is a reusable admin module with an admin dependency: help-desk (#2395, the first
full implementation), multi-lingual, social-media, beta-testing. One shared contract, not four
one-offs.

## Rules
- One repo per sub-admin (`WaveNetworks/<repo>`). It depends on this core and never duplicates
  it: auth, staff roles, shards, email queue, notifications, API keys/scopes, the migration
  runner, cron. It is never specific to one app; app behaviour comes from settings and
  extension points.
- Mounted at `public_html/<mount>/`, a sibling of `admin/` and the app. Bootstrap:
  `<mount>/include/common.php` → `admin/include/common.php`. Additive: a sub-admin deploy never
  writes into `admin/` or the app.

## subadmin.json (repo root)
```json
{
  "slug": "social-media",            // repo name
  "name": "Social media",            // menu label
  "mount": "social",                 // public_html/<mount>/
  "icon": "bi-megaphone",            // bootstrap-icons class for the admin sidebar
  "requires_core": ">=main",         // admin core version it needs
  "db": "none",                      // none | single | sharded  (drives nokemo's onboarding)
  "scopes": [],                      // API scopes it adds
  "cron": [],                        // [{"path":"cron/x.php","every":"1m"}]
  "settings": {},                    // settings schema: key => {type,label,default}
  "extension_points": [],            // app-side hooks it offers
  "onboarding": []                   // ordered steps nokemo's Sub-admins panel runs
}
```
`db`: `none` = stub/skeleton. `single` = one DB per app, never sharded (multi-lingual,
social-media, beta-testing when built). `sharded` = main + shards (help-desk only).

## Mounting (app deploy.yml)
One generic block reads the repo variable `SUBADMINS="help-desk,social-media"` (repo names).
For each it checks out `WaveNetworks/<repo>` at main, lints it, reads `mount` from its
manifest and rsyncs it to `public_html/<mount>/` (`--delete` scoped to that dir, `config/`
excluded). A sub-admin that is mounted on the host but no longer listed has its
`subadmin.json` removed — it drops out of the registry and the sidebar; its code and data are
left alone (offboarding data is a separate, explicit step).

## Registry (this repo)
`subadmin_registry()` (`include/common/subadminFunctions.php`) lists sibling directories whose
`subadmin.json` names that directory as its mount. The admin sidebar renders one entry per
mounted sub-admin; `subadmin_scopes()` returns the scopes they declare.
