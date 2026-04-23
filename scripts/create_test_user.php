<?php
/**
 * create_test_user.php — Phase1-T6 seed script.
 *
 * Ensures the canonical test user (nokemo@nokemo.com, is_test_account=1)
 * exists on this admin host. CLI-only and idempotent: safe to re-run.
 *
 * Usage:
 *   php admin/scripts/create_test_user.php
 *   php admin/scripts/create_test_user.php --dry-run
 *
 * Prints one line on completion:
 *   "Test user created: user_id=<id>, shard_id=<shard>, is_test_account=1"
 *   (or "already exists" / "flag fixed" wording for idempotent paths).
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI.\n");
    exit(1);
}

$dry_run = in_array('--dry-run', $argv, true);

require __DIR__ . '/../include/common_readonly.php';

$test_email = 'nokemo@nokemo.com';
$s_email    = sanitize($test_email, SQL);

// 1. Check if user already exists
$existing = db_fetch(db_query(
    "SELECT user_id, shard_id, is_test_account FROM user WHERE email = '$s_email'"
));

if ($existing) {
    $uid   = (int)$existing['user_id'];
    $shard = $existing['shard_id'];

    if ((int)$existing['is_test_account'] === 1) {
        echo "Test user already exists: user_id=$uid, shard_id=$shard, is_test_account=1\n";
        exit(0);
    }

    if ($dry_run) {
        echo "[dry-run] would UPDATE user SET is_test_account=1 WHERE user_id=$uid\n";
        exit(0);
    }

    db_query("UPDATE user SET is_test_account = 1 WHERE user_id = '$uid'");
    echo "Test user existed, flag fixed: user_id=$uid, shard_id=$shard, is_test_account=1\n";
    exit(0);
}

// 2. No user yet — resolve shard via the same least-loaded logic registerActions uses
$shard_id = get_least_loaded_shard();

if ($dry_run) {
    echo "[dry-run] would INSERT user (email=$test_email, shard_id=$shard_id, is_test_account=1)\n";
    exit(0);
}

// Random 64-char password. Never surfaced anywhere — login for this user
// happens exclusively via Phase 3 service-impersonation tokens.
$password   = bin2hex(random_bytes(32));
$hashed     = hash_password($password);
$confirm    = generateHashCode(100);
$s_shard    = sanitize($shard_id, SQL);
$s_confirm  = sanitize($confirm, SQL);

$r = db_query(
    "INSERT INTO user (email, password, shard_id, is_confirmed, is_test_account, confirm_hash, created_date)
     VALUES ('$s_email', '$hashed', '$s_shard', 1, 1, '$s_confirm', NOW())"
);

if (!$r) {
    fwrite(STDERR, "Failed to insert test user: " . db_error() . "\n");
    exit(1);
}

$new_id = db_insert_id();

// 3. Shard profile
prime_shard($shard_id);
db_query_shard(
    $shard_id,
    "INSERT INTO user_profile (user_id, first_name, last_name, created)
     VALUES ('$new_id', 'Nokemo', 'Test', NOW())"
);

// 4. Homedir (create_home_dir_id reads $_SESSION['shard_id'])
$_SESSION['shard_id'] = $shard_id;
create_home_dir_id($new_id);
unset($_SESSION['shard_id']);

echo "Test user created: user_id=$new_id, shard_id=$shard_id, is_test_account=1\n";
exit(0);
