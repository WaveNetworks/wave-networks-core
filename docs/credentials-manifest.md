# Pasteable App Credentials

A seamless-build primitive: an app declares **what credentials it needs**, and
the owner pastes them in from nokemo (or the provisioning wizard) without
touching the server. The app is authoritative about what's missing; nokemo never
stores the secrets — it passes them straight to the app.

## 1. The manifest (`credentials.json`) — the app emits this

Each child app ships a `credentials.json` at its **public_html root** (sibling
of `admin/`). The builder generates it from the spec's configuration doc
(`docs/spec/*configuration*.md`), one entry per credential:

```json
{
  "credentials": [
    {
      "key": "AMAZON_ASSOCIATE_TAG",
      "label": "Amazon Associate Tag",
      "group": "P0 — launch",
      "secret": false,
      "required": true,
      "validate": "^[a-z0-9-]+-2[0-9]$",
      "howto": "Associates Central → Account → Manage Tracking IDs (e.g. primodollar-20). Register primodollar.com as a property first."
    },
    {
      "key": "PAAPI_ACCESS_KEY",
      "label": "PA-API Access Key",
      "group": "P1 — PA-API (unlocks after 3 sales)",
      "secret": true,
      "required": false,
      "howto": "Associates Central → Tools → Product Advertising API → Add credentials."
    }
  ]
}
```

| Field | Meaning |
|---|---|
| `key` | Storage key; also the name the app reads via `credential_get('KEY')`. |
| `label` | Human label for the paste-in UI. |
| `group` / `phase` | Section header (e.g. launch phase). |
| `secret` | `true` masks the value in the UI; secret & non-secret share one store. |
| `required` | If `true` and empty → reported as **missing**. Default `true`. |
| `validate` | Optional regex the value must match (delimiters added automatically). |
| `howto` | Owner instructions shown next to the field. |

`credentials.json` is **committed** (it's just the schema, no values). Config may
pin a different path via `$credential_manifest_file`.

## 2. Where values live

Pasted values are written to `$files_location/credentials.store.json`
(**outside the webroot, `0600`, never committed, survives deploys**). The app
reads them with `credential_get('KEY')`. `secret` only affects display.
Audit trail: `$files_location/credentials.audit.log` (key + time + actor, never
the value).

> MVP stores values as plaintext JSON above the webroot (same trust level as
> `config.php`'s DB creds). Encrypting the store with `$app_secret` is a
> follow-up.

## 3. The API (service-key scoped)

Exposed by `include/actions/apiActions/credentialsApiActions.php`:

- **`apiGetCredentialStatus`** — scope `credentials:read`. Returns the manifest
  annotated with `satisfied` per key, plus a `missing` list and `missing_count`.
  **Never returns values.** This is what nokemo polls to render the card and to
  know what to ask for.
- **`apiSetCredential`** — scope `credentials:write`. Body `key`, `value`.
  Validates against the manifest regex and persists. Returns `{key, satisfied}`.

Mint a per-app key with both scopes via the admin API-Keys screen (or
`createServiceApiKey`); nokemo stores it like any other monitored-app key.

## 4. The loop

1. Builder writes `credentials.json` from the spec.
2. App reports `missing` via `apiGetCredentialStatus`.
3. nokemo surfaces a **Credentials** card on the monitored app; owner pastes a
   value.
4. nokemo POSTs `apiSetCredential` to the app; the value lands in the store and
   the next status poll shows it satisfied.

Same mechanism, two entry points: the monitoring dashboard (any app, any time)
and the provisioning wizard (up-front P0 creds before a new app completes).
