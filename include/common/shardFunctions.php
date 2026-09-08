<?php
/**
 * shardFunctions.php
 * Shard assignment helpers.
 */

/**
 * Pick the shard a new user should be assigned to.
 *
 * @return string  shard_id (e.g. 'shard1')
 */
function get_least_loaded_shard() {
    global $shardConfigs;

    // The registry is authoritative where it has rows: only it knows a shard's
    // status and size, so only it can exclude one that is unmigrated
    // ('pending'), retired ('draining'), or already near the 1GB ceiling.
    // Ordered by bytes free — see shard_assignable_rows().
    if (function_exists('shard_assignable_rows')) {
        $rows = shard_assignable_rows('admin', '');
        if ($rows) return $rows[0]['shard_id'];
    }

    // Fallback for a host with no registry rows yet: least-loaded across
    // whatever config.php declared.
    //
    // ⚠️ This previously read `SELECT shard_id, COUNT(*) FROM user GROUP BY
    // shard_id ORDER BY cnt ASC LIMIT 1`, which can only ever return a shard
    // that ALREADY has users. A newly provisioned, empty shard produces no row
    // in that GROUP BY and so was invisible to the ordering — it never received
    // a single registration while the original shards kept filling toward the
    // ceiling. Seeding every CONFIGURED shard at zero is what makes a new shard
    // reachable at all.
    $keys = array_keys((array)$shardConfigs);
    if (!$keys)              return 'shard1';
    if (count($keys) === 1)  return $keys[0];

    $counts = array_fill_keys($keys, 0);
    $rows   = db_fetch_all(db_query("SELECT shard_id, COUNT(*) AS cnt FROM user GROUP BY shard_id"));
    foreach (($rows ?: []) as $r) {
        if (isset($counts[$r['shard_id']])) $counts[$r['shard_id']] = (int)$r['cnt'];
    }

    asort($counts);
    return array_key_first($counts);
}

/**
 * Assign a user to a shard. Called once at registration.
 *
 * ⚠️ A user's shard_id never changes after this point — which is why the
 * high-water guard in shard_assignable_rows() matters: a user pinned to a shard
 * that later fills to the 1GB ceiling has failing writes and cannot be moved.
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
