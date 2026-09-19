<?php
/**
 * Admin users, for the monitoring platform.
 *
 * Action:
 *   apiGetAdminUsers  (monitoring:read)  — who the admins of THIS deployment are,
 *                                          so alerts can reach a human.
 *
 * Why this exists: nokemo's admin-sync (sync_app_admins -> fetch_remote_admins)
 * has called `apiGetAdminUsers` on every registered app since it was written,
 * and no deployment ever implemented it. Every sync therefore returned 0 admins,
 * every app_admin row stayed absent, and the alerts and digests for all 9 active
 * apps were dropped silently — the platform was telling nobody. Found 2026-09-19
 * by proxying the call to ContactSwipe: "Unknown action 'apiGetAdminUsers'".
 *
 * Deliberately minimal: identity and role only. No password material, no session
 * data, no profile beyond a name, because the caller needs an address to mail and
 * nothing else.
 */

if (($action ?? null) == 'apiGetAdminUsers') {
    if (require_api_scope('monitoring:read')) {
        $r = db_query(
            "SELECT user_id, email, shard_id, is_admin, is_owner
               FROM user
              WHERE is_admin = 1 OR is_owner = 1
              ORDER BY is_owner DESC, user_id ASC"
        );
        $rows = $r ? db_fetch_all($r) : [];

        $users = [];
        foreach ($rows as $row) {
            if (empty($row['email'])) continue;   // no address, no delivery

            // Names live on the shard, not in the main user row. Admins are a
            // handful of rows, so a lookup each is cheap; a missing profile is
            // not a reason to withhold the address.
            $profile = null;
            if (function_exists('get_user_profile') && !empty($row['shard_id'])) {
                $profile = get_user_profile($row['user_id'], $row['shard_id']);
            }

            $users[] = [
                'user_id'    => (int) $row['user_id'],
                'email'      => $row['email'],
                'first_name' => $profile['first_name'] ?? null,
                'last_name'  => $profile['last_name'] ?? null,
                'role'       => !empty($row['is_owner']) ? 'owner' : 'admin',
            ];
        }

        $data['users'] = $users;
        $data['count'] = count($users);
        $_SESSION['success'] = count($users) . ' admin user(s).';
    }
}
