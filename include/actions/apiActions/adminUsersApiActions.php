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

/**
 * apiGetStaffSignIns (monitoring:read) days (1-90, default 7) — how this deployment's admins and
 * owner signed in lately, so a sign-in method can be confirmed from its logs ("did the owner's
 * Continue with Google work on the web AND in the phone app?", nokemo task #2461) without anyone
 * opening Admin › Users. Staff accounts only, like apiGetAdminUsers. Per sign-in: method, status,
 * browser, a coarse platform, time — never the IP or the raw user agent. phone_handoffs = the
 * one-time codes a phone-app sign-in hands back to the app (oauth_handoff; used_at set = the app
 * took it), which is what tells a phone sign-in from one made in that same phone's browser.
 * oauth_linked = the provider now tied to the account. Rows kept 1 day past expiry.
 */
if (($action ?? null) == 'apiGetStaffSignIns') {
    if (require_api_scope('monitoring:read')) {
        $days  = max(1, min(90, (int) ($_POST['days'] ?? 7)));
        $staff = db_fetch_all(db_query(
            "SELECT user_id, email, oauth_provider, is_owner FROM user
              WHERE is_admin = 1 OR is_owner = 1 ORDER BY is_owner DESC, user_id ASC"
        )) ?: [];

        $platform = function ($ua) {
            $ua = (string) $ua;
            foreach (['iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Macintosh' => 'Mac',
                      'Windows' => 'Windows', 'CrOS' => 'ChromeOS', 'Linux' => 'Linux'] as $needle => $name) {
                if (stripos($ua, $needle) !== false) return $name;
            }
            return 'other';
        };

        $users = [];
        foreach ($staff as $s) {
            $uid = (int) $s['user_id'];
            $hist = db_fetch_all(db_query(
                "SELECT login_method, status, browser, user_agent, created FROM login_history
                  WHERE user_id = '$uid' AND created >= NOW() - INTERVAL $days DAY
                  ORDER BY created DESC LIMIT 100"
            )) ?: [];
            $signins = [];
            foreach ($hist as $h) {
                $signins[] = [
                    'method'   => $h['login_method'],
                    'status'   => $h['status'],
                    'browser'  => $h['browser'],
                    'platform' => $platform($h['user_agent']),
                    'created'  => $h['created'],
                ];
            }
            $handoffs = db_fetch_all(db_query(
                "SELECT provider AS method, created, used_at FROM oauth_handoff
                  WHERE user_id = '$uid' AND created >= NOW() - INTERVAL $days DAY ORDER BY created DESC LIMIT 50"
            )) ?: [];

            $users[] = [
                'user_id'        => $uid,
                'email'          => $s['email'],
                'role'           => !empty($s['is_owner']) ? 'owner' : 'admin',
                'oauth_linked'   => $s['oauth_provider'] ?: null,
                'signins'        => $signins,
                'phone_handoffs' => $handoffs,
            ];
        }

        $data['days']  = $days;
        $data['users'] = $users;
        $_SESSION['success'] = count($users) . ' staff account(s), last ' . $days . ' day(s).';
    }
}
