<?php
/**
 * mobileParityFunctions.php — let a child app register its own parity rows.
 *
 * The mobile_parity table (main 4.2/4.3) is browsed at admin ?page=mobile_parity.
 * The python audit scripts feed it over the API for apps with a separate SPA; an app
 * that keeps its OWN machine-readable catalog of what its clients reach (web vs
 * mobile) can instead push that catalog straight in from its cron, because a child
 * app runs inside the same deployment and $db is this admin's main database.
 *
 *   mobile_parity_sync('myapp', $rows)
 *
 * $rows is the COMPLETE desired set for that app, each row shaped like the
 * apiBulkUpsertMobileParity payload (category, feature_key, feature_name,
 * desktop_source, mobile_source, mobile_status, priority, notes). The sync is a
 * diff: unchanged rows are not written, changed rows are updated, new rows are
 * inserted, and rows of that app in the synced categories that are no longer in
 * $rows are DELETED — so a catalog entry that goes away leaves no stale gap behind.
 * An empty $rows never prunes (a failed read must not wipe the app's inventory).
 *
 * The caller's catalog is the source of truth: a status flipped by hand in the admin
 * view is put back on the next sync. Change the catalog, not the row.
 */

if (!function_exists('mobile_parity_sync')) {
    /**
     * @param string     $source_app  app slug (mobile_parity.source_app)
     * @param array      $rows        complete desired rows for this app
     * @param array|null $categories  categories this sync owns (prune scope);
     *                                default = the categories present in $rows
     * @return array{inserted:int,updated:int,deleted:int,unchanged:int,skipped:int,ok:bool}
     */
    function mobile_parity_sync($source_app, array $rows, $categories = null) {
        global $db;
        $out = ['inserted' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0, 'skipped' => 0, 'ok' => false];
        $source_app = trim((string) $source_app);
        if ($source_app === '' || empty($db)) return $out;

        $allowed_cat  = ['page', 'action', 'script', 'snippet', 'widget', 'element'];
        $allowed_stat = ['missing', 'partial', 'wired', 'n_a'];
        $allowed_pri  = ['low', 'medium', 'high', 'critical'];
        $fields = ['feature_name', 'desktop_source', 'mobile_source', 'mobile_status', 'priority', 'notes'];

        // Normalise the desired set, keyed category|feature_key.
        $want = [];
        foreach ($rows as $r) {
            if (!is_array($r)) { $out['skipped']++; continue; }
            $cat = trim((string) ($r['category'] ?? ''));
            $key = trim((string) ($r['feature_key'] ?? ''));
            if (!in_array($cat, $allowed_cat, true) || $key === '' || strlen($key) > 255) { $out['skipped']++; continue; }
            $stat = trim((string) ($r['mobile_status'] ?? 'missing'));
            $pri  = trim((string) ($r['priority'] ?? 'medium'));
            $norm = [
                'feature_name'   => mb_substr(trim((string) ($r['feature_name'] ?? '')), 0, 255),
                'desktop_source' => mb_substr(trim((string) ($r['desktop_source'] ?? '')), 0, 500),
                'mobile_source'  => mb_substr(trim((string) ($r['mobile_source'] ?? '')), 0, 500),
                'mobile_status'  => in_array($stat, $allowed_stat, true) ? $stat : 'missing',
                'priority'       => in_array($pri, $allowed_pri, true) ? $pri : 'medium',
                'notes'          => trim((string) ($r['notes'] ?? '')),
            ];
            foreach (['feature_name', 'desktop_source', 'mobile_source', 'notes'] as $f) {
                if ($norm[$f] === '') $norm[$f] = null;
            }
            $want[$cat . '|' . $key] = ['category' => $cat, 'feature_key' => $key] + $norm;
        }
        if (!$want) return $out;   // never prune on an empty read

        if ($categories === null) {
            $categories = array_values(array_unique(array_column($want, 'category')));
        }
        $categories = array_values(array_intersect((array) $categories, $allowed_cat));

        try {
            // Current rows for this app in the owned categories.
            $have = [];
            if ($categories) {
                $ph = implode(',', array_fill(0, count($categories), '?'));
                $st = $db->prepare("SELECT parity_id, category, feature_key, feature_name, desktop_source,
                                           mobile_source, mobile_status, priority, notes
                                      FROM mobile_parity
                                     WHERE source_app = ? AND category IN ($ph)");
                $st->execute(array_merge([$source_app], $categories));
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $have[$row['category'] . '|' . $row['feature_key']] = $row;
                }
            }

            $db->beginTransaction();
            $ins = $db->prepare("INSERT INTO mobile_parity
                    (source_app, category, feature_key, feature_name, desktop_source, mobile_source,
                     mobile_status, priority, notes, last_checked, created, updated)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())");
            $upd = $db->prepare("UPDATE mobile_parity
                    SET feature_name = ?, desktop_source = ?, mobile_source = ?, mobile_status = ?,
                        priority = ?, notes = ?, last_checked = NOW(), updated = NOW()
                  WHERE parity_id = ?");
            $del = $db->prepare("DELETE FROM mobile_parity WHERE parity_id = ?");

            foreach ($want as $k => $w) {
                if (!isset($have[$k])) {
                    $ins->execute([$source_app, $w['category'], $w['feature_key'], $w['feature_name'],
                        $w['desktop_source'], $w['mobile_source'], $w['mobile_status'], $w['priority'], $w['notes']]);
                    $out['inserted']++;
                    continue;
                }
                $h = $have[$k];
                $same = true;
                foreach ($fields as $f) {
                    if ((string) ($h[$f] ?? '') !== (string) ($w[$f] ?? '')) { $same = false; break; }
                }
                if ($same) { $out['unchanged']++; continue; }
                $upd->execute([$w['feature_name'], $w['desktop_source'], $w['mobile_source'],
                    $w['mobile_status'], $w['priority'], $w['notes'], (int) $h['parity_id']]);
                $out['updated']++;
            }
            foreach ($have as $k => $h) {
                if (!isset($want[$k])) {
                    $del->execute([(int) $h['parity_id']]);
                    $out['deleted']++;
                }
            }
            $db->commit();
            $out['ok'] = true;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('mobile_parity_sync(' . $source_app . '): ' . $e->getMessage());
        }
        return $out;
    }
}
