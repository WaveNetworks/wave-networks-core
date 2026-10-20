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
  "icon": "bi-megaphone",            // bootstrap-icons class for its admin footer pill
  "requires_core": ">=main",         // admin core version it needs
  "db": "none",                      // none | single | sharded  (drives nokemo's onboarding)
  "scopes": [],                      // API scopes it adds
  "cron": [],                        // [{"path":"cron/x.php","every":"1m"}]
  "settings": {},                    // settings schema: key => {type,label,default}
  "hooks": "include/hooks.php",      // optional: registers its wn_hook_provide() providers
  "extension_points": [],            // the hooks it offers: [{name, payload, result}]
  "onboarding": []                   // ordered steps nokemo's Sub-admins panel runs
}
```
`db`: `none` = stub/skeleton. `single` = one DB per app, never sharded (multi-lingual,
social-media, beta-testing when built). `sharded` = main + shards (help-desk only).

## Cron
Core's `cron/cron.php` (the app's one crontab line) runs every mounted sub-admin's manifest
`cron` jobs: `every` = `Nm`, `Nh` or `Nd` (hourly on :00, daily at 00:00). The path must be a
`.php` file inside the sub-admin's own directory. Jobs run at global scope (like child-app
crons) behind a per-job lock, so a run still going is skipped, not doubled
(`subadmin_cron_due()` in `include/common/subadminFunctions.php`).

## Notification categories
`notification_categories: [{"slug","name","description"?,"icon"?,"default_frequency"?}]` are
registered by the same cron each minute (`subadmin_register_notification_categories()`,
idempotent upsert, `created_by_app` = the sub-admin's slug), so they show in users'
notification prefs once mounted. Slugs are `[a-z0-9_]`; the icon defaults to the sub-admin's.
A slug another app already owns is never touched, so a manifest cannot rename a core category.

## User menu (links for the app's signed-in users)
`user_menu: [{"label","path","icon"?,"sub"?}]` — pages a sub-admin serves to the APP's users (not
staff), e.g. help-desk's `support/` ("Help & support"). `subadmin_user_menu()` returns them with a
root-relative `url` (`/<mount>/<path>`; a path with `..`, `//`, `:` or `\` is dropped); each app
renders the rows in its own account/menu UI, so a mounted sub-admin shows up without app code per
sub-admin.

## Mounting (app deploy.yml)
One generic block reads the repo variable `SUBADMINS="help-desk,social-media"` (repo names).
For each it checks out `WaveNetworks/<repo>` at main, lints it, reads `mount` from its
manifest and rsyncs it to `public_html/<mount>/` (`--delete` scoped to that dir, `config/`
excluded). A sub-admin that is mounted on the host but no longer listed has its
`subadmin.json` removed — it drops out of the registry and the footer; its code and data are
left alone (offboarding data is a separate, explicit step).

## Registry (this repo)
`subadmin_registry()` (`include/common/subadminFunctions.php`) lists sibling directories whose
`subadmin.json` names that directory as its mount. Admin's footer shows one pill per mounted sub-admin
(owner 2026-10-20: a pill at the bottom, like the child apps, never an admin sidebar entry); `subadmin_scopes()` returns the scopes they declare, and
`get_available_scopes()` offers them for API keys (an `api-scopes.json` description wins).

## Following sub-admin changes
`subadmin-follow.yml` in each app redeploys when a sub-admin listed in `SUBADMINS` has moved
on main since the app's last deploy (same idea as `core-follow.yml`).

## UI — same structure and theme as admin
A sub-admin's staff pages are admin pages: `<mount>/app/index.php` routes `?page=` to `<mount>/views/`,
wrapped by `<mount>/views/template.php`, which mirrors `admin/views/template.php` (dark sidebar with the
sub-admin's own nav + "Back to admin", topnav with bell / colour mode / theme switcher / user menu,
`admin/snippets/feedback.php` flash). Forms are plain posts to action files in
`<mount>/include/actions/memberActions/`; JS uses `apiPost()` against `<mount>/api/index.php`.
Theme: `get_theme_css_url('../../admin/', '../../')` and `core_asset_url($rel, '../../admin/')`; set
`<html data-wn-core="../../admin/">` so core's theme.js and notifications.js resolve core files from a
page outside `admin/app/`. Reference: help-desk.

## Hooks — how an app talks to a sub-admin
`include/common/hookFunctions.php`. A sub-admin that offers hooks names a hooks file in its manifest
(`"hooks": "include/hooks.php"`), which calls `wn_hook_provide('<prefix>.<what>', fn(array $p): array)`
for each one and lists them under `extension_points`. The app calls `wn_hook($name, $payload)` and gets
`['ok', 'available', 'result', 'error']`; core loads every mounted sub-admin's hooks file once, in-process,
the first time any hook is called. So an app never `require`s a sub-admin's internal files and holds no
key for it, and a sub-admin that is not mounted or not configured answers `available=false` / an error
instead of breaking the app. A provider loads its own helpers and refuses with `['error' => …]`.
Data flows app → sub-admin through hooks; sub-admin → app through a hook the app polls (an event
feed with a cursor), never by the sub-admin loading app code. `scripts/hook-probe.php` (deploy check)
holds the contract.
