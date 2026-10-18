# Core secrets: `$app_secret` key ring and `$hiddenhash` pepper v2

Status: design, agreed with the owner 2026-10-18. Built in interactive sessions, not by the
builder. Companion: `p3sig/docs/machine-identity-and-secret-delivery.md` (§4 covers how the
service keys leave `config.php`; these two secrets stay in `config.php` for now).

## 1. Today

### `$app_secret`: one value, four jobs

| Use | Where | Today |
|---|---|---|
| JWT signing | `jwtFunctions.php` `jwt_signing_key()` | HS256 with the raw value (or sha256 of it when < 32 bytes) |
| Credentials stored in the DB (`WN_SEALED_COLUMNS`: `smtp_pass`, `oauth_provider.client_secret`, `user.totp_secret`) | `secretFunctions.php` → `wn_secret_encrypt()` | `enc:v1:` + AES-256-CBC, key = sha256(`$app_secret`), **no MAC** |
| Shard DB passwords | `shardConfigFunctions.php` | same `wn_secret_encrypt()`, no marker |
| Migration-source passwords | `migrationSyncFunctions.php` | AES-256-CBC off the same key, `::` framing |

Rotation today: `scripts/rotate-secret-key.php --old --new` re-encrypts everything, then
`config.php` is edited by hand "straight after". Between the two the app can't open its own
stored credentials, and every JWT signed with the old key fails.

### `$hiddenhash`: a password pepper

`hash_password()` = `password_hash($password . $hiddenhash, PASSWORD_BCRYPT)`. It can't be
rotated today at all: changing it locks out every user.

Latent bug: bcrypt reads only the first 72 bytes of its input. The pepper is appended
after the password, so a password of 72 bytes or more pushes it out entirely, and long
passwords are hashed without any pepper.

Also: when `config/config.php` is missing, `bootstrap.php` and `common_readonly.php` fall back
to `getenv()` with defaults `'dev_hash'` / `'dev_secret'`. A production host with a lost
`config.php` and no env would silently run on publicly known secrets.

## 2. `$app_secret` key ring

### 2.1 Config

```php
// config/config.php
$app_secrets = [
    'k2' => '<64 random chars>',   // current
    'k1' => '<old value>',         // kept until the sweep reports zero
];
$app_secret_current = 'k2';
```

Back-compat: a host with only `$app_secret` is treated as `['k1' => $app_secret]`, current
`k1`. Nothing changes for an app until someone adds a second key. Env equivalent:
`APP_SECRETS='k2:…,k1:…'`, `APP_SECRET_CURRENT=k2`.

### 2.2 Purpose keys

Each job gets its own key derived from the ring entry, so a leak or misuse in one doesn't
hand over the others:

`wn_key($purpose, $kid)` = `hash_hkdf('sha256', $app_secrets[$kid], 32, 'wn/' . $purpose . '/v2')`,
with purposes `jwt`, `seal`.

`$app_secret` itself stays defined (= the current key's raw value) so child code that reads
it keeps working.

### 2.3 Sealed values: `enc:v2:<kid>:`

- New format `enc:v2:<kid>:` + base64(nonce ‖ ciphertext) with **sodium
  `crypto_aead_xchacha20poly1305_ietf`** (authenticated; fixes the missing MAC) under
  `wn_key('seal', kid)`.
- `wn_secret_open()` reads `enc:v2:<kid>:` with that key, `enc:v1:` with the legacy derivation
  from `$app_secrets['k1']` (or `$app_secret`), and unmarked values unchanged (as today).
- `wn_secret_seal()` always writes `enc:v2:<current>:`.
- The daily sweep (`cron/days/1/seal_stored_secrets.php`) also re-seals any value not on the
  current kid (v1 or an older v2 kid). Same for shard and migration-source passwords, which
  move onto the marker format.
- `scripts/stored-secrets-check.php` reports counts per kid; a key may be retired when its
  count is zero.

### 2.4 JWTs

- Header gains `kid`. `jwt_issue()` signs with `wn_key('jwt', current)`.
- `jwt_verify()` picks the key by `kid`; a token with no `kid` is verified with the legacy
  key (`k1` raw, today's behaviour). Unknown kid → invalid.
- Access tokens live an hour and refresh tokens aren't JWTs, so retiring a key costs at most
  an hour of forced renewals.

### 2.5 Rotation procedure (no downtime)

1. Add `k3` to `$app_secrets` (not current). Deploy. Reads now accept it everywhere.
2. Set `$app_secret_current = 'k3'`. New writes and new JWTs use it.
3. Wait for the daily sweep (or run `php scripts/stored-secrets-check.php --fix`) until
   `k2` shows zero values, plus one hour for JWTs.
4. Remove `k2`.

`scripts/rotate-secret-key.php` becomes this flow (`--add`, `--promote`, `--retire` with a
refusal while values remain on the key), replacing the old/new one-shot.

Where the ring comes from later: p3sig can deliver `$app_secrets` like any other value; the
procedure is unchanged.

## 3. `$hiddenhash` pepper v2

### 3.1 Config

```php
$peppers = ['p2' => '<64 random chars>', 'p1' => '<old $hiddenhash>'];
$pepper_current = 'p2';
```

Back-compat: only `$hiddenhash` = `['p1' => $hiddenhash]`, current `p1`.

### 3.2 Hash format

- **v1 (today):** a bare bcrypt string `$2y$…` = bcrypt(password ‖ pepper). Implicitly `p1`.
- **v2:** `$wn2$<pid>$` + bcrypt(base64(HMAC-SHA256(pepper, password))). The HMAC output is
  44 bytes, under bcrypt's 72-byte limit, so the whole password and the whole pepper count.
  Fits the 255-char `user.password` column.

`hash_password()` writes v2 with the current pepper. `verify_password()` dispatches on the
prefix. `password_needs_upgrade()` returns true for v1 or a non-current `pid`, so the existing
login paths (`loginActions.php:47`, `mobileAuthActions.php:53`) re-hash on the next successful
login with no new code there.

### 3.3 Rotation procedure

1. Add `p3` as current (keep `p2`, `p1`). Users move to `p3` as they log in.
2. Stats: `scripts/password-pepper-stats.php` counts hashes per pepper version per shard.
3. To retire an old pepper: its remaining users must reset their password. The retire step
   clears their hash and sends the reset email. Run it only on the owner's decision, never on
   a timer.

## 4. Missing-config guard

When `config/config.php` is absent and the env has no `APP_SECRET`/`HIDDEN_HASH`, refuse to
boot (HTTP 500 + error log) unless `WN_ENV=dev`. Docker and CI set `WN_ENV=dev`.

## 5. Tests (deploy gate, SQLite like `stored-secrets-probe.php`)

- v1 hashes still verify and are upgraded to v2 on login; the 72-byte case now differs.
- Sealed `enc:v1:` and unmarked rows still open; the sweep moves them to the current kid; a
  value tampered with is rejected (MAC).
- JWT with no kid / old kid / current kid verifies; unknown kid fails.
- Rotation add → promote → sweep → retire leaves every value readable at every step, and
  retire refuses while values remain on the key.
