<?php
/**
 * install/provision.php — token-gated WEB provisioning endpoint.
 *
 * Called ONCE over HTTPS by the openclaw provisioning runner after deploy, to
 * finish app setup with NO CLI PHP:
 *   1. runs DB migrations in a WEB context (so $db_version is set — the CLI
 *      /common_readonly path leaves it null and check_and_migrate no-ops),
 *   2. ensures the monitoring admin user (default nokemo@nokemo.com),
 *   3. mints a scoped monitoring service_api_key,
 *   4. returns { userid, key } as JSON.
 *
 * Inert without the correct $provision_token (a strong per-deployment secret the
 * runner writes into config/config.php, which is web-inaccessible). This is the
 * web-only replacement for the old SSH-CLI _provision_mint.php, and mirrors the
 * documented install (subtheme.com): "migrations run on first page load" +
 * installer step-2 creates the first admin — collapsed into one authenticated call.
 */
header('Content-Type: application/json');

require __DIR__ . '/../include/common_readonly.php';
global $db, $db_version, $shard_version, $shardConfigs, $provision_token;

function _prov_fail($code, $msg) { http_response_code($code); echo json_encode(['error' => $msg]); exit; }

// ── Auth: constant-time compare against config's $provision_token ─────────────
$supplied = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
if (empty($provision_token) || $supplied === '' || !hash_equals((string) $provision_token, $supplied)) {
    _prov_fail(403, 'invalid or missing provisioning token');
}

// ── Run migrations (fresh DB). Web context sets the version target explicitly;
//    target = highest available migration so a brand-new DB builds fully. ──────
$migDir     = __DIR__ . '/../db_migrations/';
$mainAvail  = function_exists('get_available_migrations') ? get_available_migrations('main', $migDir) : [];
$db_version = !empty($mainAvail) ? max($mainAvail) : ($db_version ?? 0);
if (function_exists('check_and_migrate_main_db'))   { check_and_migrate_main_db(); }
$shardAvail    = function_exists('get_available_migrations') ? get_available_migrations('shard', $migDir) : [];
$shard_version = !empty($shardAvail) ? max($shardAvail) : ($shard_version ?? 0);
if (function_exists('check_and_migrate_all_shards')) { check_and_migrate_all_shards(); }

// Sanity: migrations must have produced the core schema before we touch it.
$t = db_fetch(db_query("SHOW TABLES LIKE 'user'"));
if (!$t) { _prov_fail(500, 'migrations did not create the user table (check DB/migration runner)'); }

// ── Ensure the monitoring admin user ─────────────────────────────────────────
$email = trim($_POST['email'] ?? $_GET['email'] ?? 'nokemo@nokemo.com');
$label = trim($_POST['label'] ?? 'nokemo monitoring');
if ($email === '') { _prov_fail(400, 'email required'); }

$s_email = sanitize($email, SQL);
$row = db_fetch(db_query("SELECT user_id, is_admin FROM user WHERE email = '$s_email'"));
if ($row) {
    $uid = (int) $row['user_id'];
    if ((int) $row['is_admin'] !== 1) { db_query("UPDATE user SET is_admin = 1 WHERE user_id = '$uid'"); }
} else {
    $shard_id = get_least_loaded_shard();
    $password = bin2hex(random_bytes(32));   // discarded — this admin uses password-reset / SSO
    $hashed   = hash_password($password);
    $s_shard  = sanitize($shard_id, SQL);
    $r = db_query(
        "INSERT INTO user (email, password, shard_id, is_admin, is_confirmed, created_date)
         VALUES ('$s_email', '$hashed', '$s_shard', 1, 1, NOW())");
    if (!$r) { _prov_fail(500, 'failed to create admin user: ' . db_error()); }
    $uid = (int) db_insert_id();
    prime_shard($shard_id);
    db_query_shard($shard_id,
        "INSERT INTO user_profile (user_id, first_name, last_name, created)
         VALUES ('$uid', 'Monitoring', 'Admin', NOW())");
    if (function_exists('create_home_dir_id')) {
        $_SESSION['shard_id'] = $shard_id; create_home_dir_id($uid); unset($_SESSION['shard_id']);
    }
}

// ── Mint the scoped monitoring service key ───────────────────────────────────
$scopes = ['error_log:read', 'monitoring:read', 'monitoring:write'];
$res = create_service_api_key($label, $scopes, $uid);
if (!$res || empty($res['full_key'])) { _prov_fail(500, 'failed to mint service key'); }

echo json_encode(['userid' => $uid, 'key' => $res['full_key']]);
