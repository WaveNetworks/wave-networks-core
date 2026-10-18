# Stored credentials — inventory and rules (task #2397, 2026-10-12)

**Rule:** a credential core must USE is encrypted at rest; one it only COMPARES is hashed.
Nothing secret is ever echoed into a form, returned from an API action, or logged
(`actionLogPolicy.php` is default-deny for params).

Helpers: `include/common/secretFunctions.php`
- `wn_secret_seal($plain)` → `enc:v2:<kid>:` + XChaCha20-Poly1305 (authenticated) under
  `wn_key('seal', kid)` from the `$app_secret` key ring (`keyRingFunctions.php`).
  `wn_secret_open($stored)` reverses it; `enc:v1:` (pre-ring AES-256-CBC off the k1 secret)
  still opens; an UNMARKED value is legacy plain text and is returned unchanged.
  `wn_secret_encrypt()` / `encrypt_source_password()` write the same v2 format.
- `wn_token_hash($token)` → `sha256:` + hex.
- `WN_SEALED_COLUMNS` is the list of sealed columns. Add new credential columns there.

The key is `$app_secret` — `config/config.php` or the `APP_SECRET` env, per host. Never the DB.

## Inventory (core main DB)

| Column | Kind | State before #2397 | State now |
|---|---|---|---|
| `email_settings.smtp_pass` | used (SMTP auth) | **plain text** | sealed on save; opened only in `get_email_settings()`; self-heals on read + daily sweep. Form field write-only (blank = unchanged) |
| `oauth_provider.client_secret` | used | **plain text**; edit with blank field wiped it | sealed on save; blank = unchanged; daily sweep. (Login currently reads `GOOGLE_/GITHUB_CLIENT_SECRET` from config/env, not this column) |
| `user.totp_secret` | used (TOTP verify) | **plain text** | sealed on `enable2FA`; `totp_verify()` opens it; daily sweep seals existing rows |
| `forgot.forgot_token` | compared only | **plain text** | `sha256:` hash; migration main/5.9 hashed existing rows |
| `invite.invite_token` | compared only | **plain text** | `sha256:` hash (main/5.9); lookup also accepts a raw row from older child code |
| `shard_config.db_pass_enc` | used | encrypted (`wn_secret_encrypt`, no marker) | unchanged; covered by rotation |
| `migration_source.db_password_enc` | used | encrypted (`encrypt_source_password`, `::` framing) | unchanged; covered by rotation |
| `service_api_key.key_hash` | compared | bcrypt | unchanged (good) |
| `login_code` / `login_approval` / 3.1–3.2 `token_hash` | compared | hashed | unchanged (good) |
| `user.password` | compared | password_hash | unchanged (good) |
| `api_key.api_key` (session/device bearer keys) | compared only | **plain text** | `sha256:` hash (task #2398): `create_api_key()` stores `wn_token_hash()` and returns the raw key once; `validate_api_key()` / `delete_api_key()` match the hash or a legacy raw row, refuse hash-shaped input, and never return the stored value; migration main/6.0 hashed existing rows (cookies and device tokens stay signed in) |
| `saml_provider.idp_x509_cert` | public IdP certificate | plain | not a secret; no SP private key is stored in the DB |
| push subscription `auth_key` / `p256dh_key` (shard) | browser-issued subscription keys, must be used to send | plain | low sensitivity; left as is |
| Stripe / IMAP / webhook secrets | — | none stored in core DB tables | — (config/env only) |

OAuth/Facebook/Google client secrets, SMTP fallback and `APP_SECRET` itself live in
`config/config.php` / env, not the DB.

## Checks
- Deploy: `scripts/stored-secrets-probe.php` — write sites sealed/hashed; on SQLite: raw DB value
  is not the password, legacy rows read, sweep twice = idempotent, rotation works.
- Live host: `php scripts/stored-secrets-check.php [--fix]` — exit 1 naming any unmarked value.
- Cron: `cron/days/1/seal_stored_secrets.php` seals any plain value left (in-place migration
  for every host's main DB; also catches rows from older child code).

## Rotating the key
Key ring, no downtime: docs/core-secret-rotation.md. In short: add `'k2' => …` to
`$app_secrets` (a host with only `$app_secret` is `'k1'`), set `$app_secret_current = 'k2'`,
`php scripts/rotate-secret-key.php sweep`, then `retire k1` (refuses while anything is left on
it) and remove k1 from config. Child apps with their own sealed columns register them with
`wn_register_sealed_column()` so the counts include them; code with its own crypto off
`$app_secret` (brand-compiler `bc_encrypt`) must move to `wn_secret_encrypt()` first.
