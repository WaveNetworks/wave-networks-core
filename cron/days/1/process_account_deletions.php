<?php
/**
 * cron/days/1/process_account_deletions.php
 *
 * GDPR Article 17 — right to erasure.
 * Finds pending account_deletion_request rows whose 30-day cooling-off
 * period (cancel_before) has elapsed, wipes the user's data, and marks
 * the request completed.
 *
 * Idempotent: a missing user (already wiped by a previous run that died
 * mid-flight) still flips the request to 'completed'.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

if (!isset($db)) {
    include(__DIR__ . '/../../../include/common_readonly.php');
}

global $db;

// Pull in sibling child apps so they can register their delete_user_data
// hooks (e.g. pwt_delete_user_data). Each child opt-ins via a small
// include/admin_hooks.php file — keeping this lightweight (no migrations,
// no session, no action glob).
$adminRoot = realpath(__DIR__ . '/../../..');
foreach (glob(dirname($adminRoot) . '/*/include/admin_hooks.php') as $hookFile) {
    if (realpath(dirname(dirname($hookFile))) === $adminRoot) continue; // skip admin itself
    try { include_once($hookFile); }
    catch (Throwable $e) {
        error_log("process_account_deletions: failed to load $hookFile: " . $e->getMessage());
    }
}

$processed = 0;
$failed    = 0;

$r = db_query(
    "SELECT request_id, user_id
     FROM account_deletion_request
     WHERE status = 'pending' AND cancel_before <= NOW()"
);

while ($row = db_fetch($r)) {
    $request_id = (int) $row['request_id'];
    $user_id    = (int) $row['user_id'];

    try {
        // Prefer the cross-app wipe helper if a child app has registered one
        // (see companion task #377). Falls back to the minimal admin-only
        // wipe otherwise — mirrors what userActions.php deleteUser does today.
        if (function_exists('delete_user_data')) {
            delete_user_data($user_id);
        } else {
            $user = get_user($user_id);
            if ($user) {
                $shard_id = (int) ($user['shard_id'] ?? 0);
                if ($shard_id) {
                    prime_shard($shard_id);
                    db_query_shard($shard_id, "DELETE FROM user_profile WHERE user_id = '$user_id'");
                }
                db_query("DELETE FROM api_key WHERE user_id = '$user_id'");
                db_query("DELETE FROM forgot WHERE user_id = '$user_id'");
                db_query("DELETE FROM notification WHERE user_id = '$user_id'");
                db_query("DELETE FROM user WHERE user_id = '$user_id'");
            }
            // If $user is already gone we still mark the request completed below.
        }

        db_query(
            "UPDATE account_deletion_request
             SET status = 'completed', completed_by = 'cron', completed_at = NOW()
             WHERE request_id = '$request_id'"
        );
        $processed++;
    } catch (Throwable $e) {
        $failed++;
        if (function_exists('log_error_to_db')) {
            log_error_to_db('ERROR', 'process_account_deletions: ' . $e->getMessage(),
                __FILE__, __LINE__, $e->getTraceAsString(),
                ['request_id' => $request_id, 'user_id' => $user_id]);
        } else {
            error_log("process_account_deletions failed for request_id=$request_id user_id=$user_id: " . $e->getMessage());
        }
    }
}

echo "    process_account_deletions: processed=$processed, failed=$failed\n";
