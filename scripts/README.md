# admin/scripts

One-shot CLI utilities. Not auto-run — each script documents its own invocation.

All scripts bootstrap via `include/common_readonly.php` (no session guard, no
action includes) and must be run on the deployed admin host (where
`config/config.php` exists with live DB credentials).

## create_test_user.php

Seeds the test user (`$test_account_email` from config, else `TEST_ACCOUNT_EMAIL`,
else `test@temporarydemo.com`; `is_test_account=1`) on a
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

Run once per deployed admin host after migration 3.4 has reached it.

### Notes

- The generated password is a discarded 64-char random hex. The test user is
  only reached via Phase 3 service-impersonation tokens, never a real login.
- See `CLAUDE.md` → **Test account** for the policy (infinite action log TTL,
  re-consent exemption, no real PII).

## set-owner.php

The only way to set `user.is_owner` and `user.is_agent` (main/7.2, task #2779). Run over SSH on
the app's host; there is deliberately no admin-UI toggle and no API action for either.

```
php admin/scripts/set-owner.php --list                 # owners, admins, agents
php admin/scripts/set-owner.php owner@nokemo.com       # make the owner (also admin)
php admin/scripts/set-owner.php --revoke <email>
php admin/scripts/set-owner.php --agent <email>        # automation account: never owner, never a human gate
php admin/scripts/set-owner.php --not-agent <email>
```

Add `--dry-run` to see the change. It refuses to make an agent account or the test account the
owner. Gates use `wn_is_owner_session()` / `wn_is_human_session()` (include/common/ownerFunctions.php),
which read the DB, refuse any service-key request and any agent account; `php tests/owner-gate-test.php`.
