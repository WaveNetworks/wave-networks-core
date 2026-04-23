# admin/scripts

One-shot CLI utilities. Not auto-run — each script documents its own invocation.

All scripts bootstrap via `include/common_readonly.php` (no session guard, no
action includes) and must be run on the deployed admin host (where
`config/config.php` exists with live DB credentials).

## create_test_user.php

Seeds the canonical test user (`nokemo@nokemo.com`, `is_test_account=1`) on a
deployed admin host. Idempotent: if the user exists, the script verifies the
flag and exits; if the flag is missing it fixes it.

### Prerequisites

- Migration **3.4** (which adds the `user.is_test_account` column) must have
  run on the target host.
- `config/config.php` present with live DB credentials.

### Dry run

```
php admin/scripts/create_test_user.php --dry-run
```

Prints the INSERT/UPDATE that would fire; makes no changes.

### Real run

```
php admin/scripts/create_test_user.php
```

Prints one of:

```
Test user created: user_id=<id>, shard_id=<shard>, is_test_account=1
Test user already exists: user_id=<id>, shard_id=<shard>, is_test_account=1
Test user existed, flag fixed: user_id=<id>, shard_id=<shard>, is_test_account=1
```

### Hosts to run on (Phase 1 rollout)

Run once per deployed admin host after migration 3.4 has reached it:

- subtheme.com
- dswa.org
- playwithtarot.com
- p3sig.com
- nokemo.com

### Notes

- The generated password is a discarded 64-char random hex. The test user is
  only reached via Phase 3 service-impersonation tokens, never a real login.
- See `CLAUDE.md` → **Test account** for the policy (infinite action log TTL,
  re-consent exemption, no real PII).
