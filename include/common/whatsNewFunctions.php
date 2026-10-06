<?php
/**
 * whatsNewFunctions.php — in-app "What's new" (nokemo task #2316).
 *
 * nokemo owns one changelog for every monitored app; the owner publishes it there. This reads
 * that app's PUBLISHED, user-facing entries from nokemo's no-key `changelogPublicFeed`, which
 * resolves the app from the site URL we send — so no app needs config. Drafts and internal
 * entries never leave nokemo. Cached per site for 10 minutes (a failed fetch too, so a slow or
 * down nokemo costs one request per 10 minutes, never one per page). Override the endpoint
 * with NOKEMO_CHANGELOG_URL in config if it ever moves.
 */

if (!function_exists('whats_new_site')) {
    /** This request's app URL: host + first path segment (the app's webroot). */
    function whats_new_site(): string
    {
        $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
        $seg  = explode('/', trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/'))[0] ?? '';
        return $host === '' ? '' : 'https://' . $host . '/' . rawurlencode($seg) . '/';
    }
}

if (!function_exists('whats_new_entries')) {
    /**
     * Published user-facing entries for this app, newest first: [{entry_date, week, kind, title, body}].
     * Empty when nokemo has published nothing, does not know this site, or is unreachable.
     */
    function whats_new_entries(int $days = 90, int $limit = 30): array
    {
        $site = whats_new_site();
        if ($site === '') { return []; }
        $cache = sys_get_temp_dir() . '/wn_whats_new_' . md5($site . '|' . $days) . '.json';
        if (is_file($cache) && filemtime($cache) > time() - 600) {
            $hit = json_decode((string) @file_get_contents($cache), true);
            if (is_array($hit)) { return array_slice($hit, 0, $limit); }
        }
        $url = defined('NOKEMO_CHANGELOG_URL') ? NOKEMO_CHANGELOG_URL : 'https://nokemo.com/nokemo/api/index.php';
        $entries = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['action' => 'changelogPublicFeed', 'site' => $site,
                                                    'from' => gmdate('Y-m-d', strtotime("-$days days"))]),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 4,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        foreach ((array) ($json['results']['entries'] ?? []) as $e) {
            if (!is_array($e) || empty($e['title'])) { continue; }
            $entries[] = ['entry_date' => (string) ($e['entry_date'] ?? ''), 'week' => (string) ($e['week'] ?? ''),
                          'kind' => (string) ($e['kind'] ?? ''), 'title' => (string) $e['title'],
                          'body' => (string) ($e['body'] ?? '')];
        }
        @file_put_contents($cache, json_encode($entries), LOCK_EX);
        return array_slice($entries, 0, $limit);
    }
}
