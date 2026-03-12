<?php
/**
 * shardFunctions.php
 * Shard assignment helpers.
 */

/**
 * Get the least-loaded shard (fewest users assigned).
 *
 * @return string  shard_id (e.g. 'shard1')
 */
function get_least_loaded_shard() {
    global $shardConfigs;

    // If only one shard configured, return it
    $keys = array_keys($shardConfigs);
    if (count($keys) <= 1) {
        return $keys[0] ?? 'shard1';
    }

    $r = db_query("SELECT shard_id, COUNT(*) as cnt FROM user GROUP BY shard_id ORDER BY cnt ASC LIMIT 1");
    $row = db_fetch($r);

    if ($row) {
        return $row['shard_id'];
    }

    // No users yet — return the first configured shard
    return $keys[0];
}

/**
 * Assign a user to a shard. Called once at registration.
 *
 * @param int $user_id
 * @return string  The assigned shard_id
 */
function assign_user_shard($user_id) {
    $shard_id = get_least_loaded_shard();
    $user_id  = (int)$user_id;
    $shard    = sanitize($shard_id, SQL);

    db_query("UPDATE user SET shard_id = '$shard' WHERE user_id = '$user_id'");

    return $shard_id;
}
