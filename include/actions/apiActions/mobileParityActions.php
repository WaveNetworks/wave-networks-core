<?php
/**
 * Mobile Parity API Actions — feeds the desktop↔mobile gap inventory.
 *
 *   apiListMobileParity      list rows with filters (app, category, status)
 *   apiGetMobileParity       single row by parity_id or (app,category,key)
 *   apiUpsertMobileParity    UPSERT one row (key: app+category+feature_key)
 *   apiBulkUpsertMobileParity   batch upsert from the audit script
 *   apiSetMobileParityStatus    quick status flip (missing→wired, etc.)
 *   apiGetMobileParitySummary   counts per (app, category, status)
 *
 * Read scope:  actions:read
 * Write scope: tests:write   (same scope used by use_case mutations)
 */

// ── List parity rows ─────────────────────────────────────────────────────
if (($action ?? null) == 'apiListMobileParity') {
    if (require_api_scope('actions:read')) {
        $app    = trim((string)($_POST['source_app']    ?? ''));
        $cat    = trim((string)($_POST['category']      ?? ''));
        $status = trim((string)($_POST['mobile_status'] ?? ''));
        $limit  = max(1, min(1000, (int)($_POST['limit'] ?? 200)));

        $sql = "SELECT parity_id, source_app, category, feature_key, feature_name,
                       desktop_source, mobile_source, mobile_status, priority,
                       notes, last_checked, created, updated
                FROM mobile_parity";
        $where = [];
        $args  = [];
        if ($app    !== '') { $where[] = 'source_app = ?';    $args[] = $app; }
        if ($cat    !== '') { $where[] = 'category = ?';      $args[] = $cat; }
        if ($status !== '') { $where[] = 'mobile_status = ?'; $args[] = $status; }
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY source_app ASC,
                           FIELD(mobile_status, "missing","partial","wired","n_a"),
                           FIELD(category, "page","action","script","snippet","widget"),
                           feature_key ASC
                  LIMIT ' . $limit;

        $r = db_query_prepared($sql, $args);
        $items = [];
        if ($r) {
            while ($row = $r->fetch(PDO::FETCH_ASSOC)) $items[] = $row;
        }
        $data['items'] = $items;
        $data['count'] = count($items);
        $_SESSION['success'] = 'OK';
    }
}

// ── Get one ──────────────────────────────────────────────────────────────
if (($action ?? null) == 'apiGetMobileParity') {
    if (require_api_scope('actions:read')) {
        $id  = (int)($_POST['parity_id'] ?? 0);
        $app = trim((string)($_POST['source_app']  ?? ''));
        $cat = trim((string)($_POST['category']    ?? ''));
        $key = trim((string)($_POST['feature_key'] ?? ''));
        if ($id > 0) {
            $r = db_query_prepared("SELECT * FROM mobile_parity WHERE parity_id = ?", [$id]);
        } elseif ($app !== '' && $cat !== '' && $key !== '') {
            $r = db_query_prepared(
                "SELECT * FROM mobile_parity WHERE source_app = ? AND category = ? AND feature_key = ?",
                [$app, $cat, $key]
            );
        } else {
            $_SESSION['error'] = 'parity_id, or source_app+category+feature_key, is required.';
            $r = null;
        }
        if ($r) {
            $row = $r->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $data['parity'] = $row;
                $_SESSION['success'] = 'OK';
            } else {
                $_SESSION['error'] = 'Row not found.';
            }
        }
    }
}

// ── Upsert one ───────────────────────────────────────────────────────────
if (($action ?? null) == 'apiUpsertMobileParity') {
    if (require_api_scope('tests:write')) {
        $errs = [];
        $app  = trim((string)($_POST['source_app']  ?? ''));
        $cat  = trim((string)($_POST['category']    ?? ''));
        $key  = trim((string)($_POST['feature_key'] ?? ''));
        $name = trim((string)($_POST['feature_name']   ?? ''));
        $desk = trim((string)($_POST['desktop_source'] ?? ''));
        $mob  = trim((string)($_POST['mobile_source']  ?? ''));
        $stat = trim((string)($_POST['mobile_status']  ?? 'missing'));
        $pri  = trim((string)($_POST['priority']       ?? 'medium'));
        $note = trim((string)($_POST['notes']          ?? ''));

        $allowed_cat  = ['page','action','script','snippet','widget'];
        $allowed_stat = ['missing','partial','wired','n_a'];
        $allowed_pri  = ['low','medium','high','critical'];
        if ($app === '')                     $errs[] = 'source_app is required.';
        if (!in_array($cat, $allowed_cat))   $errs[] = 'category must be one of: ' . implode(', ', $allowed_cat);
        if ($key === '')                     $errs[] = 'feature_key is required.';
        if (!in_array($stat, $allowed_stat)) $stat = 'missing';
        if (!in_array($pri,  $allowed_pri))  $pri  = 'medium';

        if (empty($errs)) {
            db_query_prepared(
                "INSERT INTO mobile_parity
                   (source_app, category, feature_key, feature_name,
                    desktop_source, mobile_source, mobile_status, priority,
                    notes, last_checked, created, updated)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    feature_name   = VALUES(feature_name),
                    desktop_source = VALUES(desktop_source),
                    mobile_source  = VALUES(mobile_source),
                    mobile_status  = VALUES(mobile_status),
                    priority       = VALUES(priority),
                    notes          = VALUES(notes),
                    last_checked   = NOW(),
                    updated        = NOW()",
                [$app, $cat, $key, $name, $desk ?: null, $mob ?: null, $stat, $pri, $note ?: null]
            );
            $data['source_app']    = $app;
            $data['category']      = $cat;
            $data['feature_key']   = $key;
            $data['mobile_status'] = $stat;
            $_SESSION['success']   = 'OK';
        } else {
            $_SESSION['error'] = implode('<br>', $errs);
        }
    }
}

// ── Bulk upsert (audit script feeds this) ────────────────────────────────
if (($action ?? null) == 'apiBulkUpsertMobileParity') {
    if (require_api_scope('tests:write')) {
        $errs  = [];
        $rows_in = $_POST['rows'] ?? null;
        if (is_string($rows_in)) {
            $decoded = json_decode($rows_in, true);
            $rows_in = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($rows_in)) $errs[] = 'rows must be a JSON array.';

        $allowed_cat  = ['page','action','script','snippet','widget'];
        $allowed_stat = ['missing','partial','wired','n_a'];
        $allowed_pri  = ['low','medium','high','critical'];
        $inserted = 0;
        $skipped  = 0;

        if (empty($errs)) {
            foreach ($rows_in as $row) {
                if (!is_array($row)) { $skipped++; continue; }
                $app  = trim((string)($row['source_app']  ?? ''));
                $cat  = trim((string)($row['category']    ?? ''));
                $key  = trim((string)($row['feature_key'] ?? ''));
                if ($app === '' || !in_array($cat, $allowed_cat) || $key === '') {
                    $skipped++;
                    continue;
                }
                $name = trim((string)($row['feature_name']   ?? ''));
                $desk = trim((string)($row['desktop_source'] ?? ''));
                $mob  = trim((string)($row['mobile_source']  ?? ''));
                $stat = trim((string)($row['mobile_status']  ?? 'missing'));
                $pri  = trim((string)($row['priority']       ?? 'medium'));
                $note = trim((string)($row['notes']          ?? ''));
                if (!in_array($stat, $allowed_stat)) $stat = 'missing';
                if (!in_array($pri,  $allowed_pri))  $pri  = 'medium';

                db_query_prepared(
                    "INSERT INTO mobile_parity
                       (source_app, category, feature_key, feature_name,
                        desktop_source, mobile_source, mobile_status, priority,
                        notes, last_checked, created, updated)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
                     ON DUPLICATE KEY UPDATE
                        feature_name   = VALUES(feature_name),
                        desktop_source = VALUES(desktop_source),
                        mobile_source  = VALUES(mobile_source),
                        mobile_status  = VALUES(mobile_status),
                        priority       = VALUES(priority),
                        notes          = COALESCE(VALUES(notes), notes),
                        last_checked   = NOW(),
                        updated        = NOW()",
                    [$app, $cat, $key, $name, $desk ?: null, $mob ?: null, $stat, $pri, $note ?: null]
                );
                $inserted++;
            }
            $data['inserted'] = $inserted;
            $data['skipped']  = $skipped;
            $_SESSION['success'] = 'OK';
        } else {
            $_SESSION['error'] = implode('<br>', $errs);
        }
    }
}

// ── Quick status flip ────────────────────────────────────────────────────
if (($action ?? null) == 'apiSetMobileParityStatus') {
    if (require_api_scope('tests:write')) {
        $errs = [];
        $id   = (int)($_POST['parity_id'] ?? 0);
        $stat = trim((string)($_POST['mobile_status'] ?? ''));
        $allowed_stat = ['missing','partial','wired','n_a'];
        if ($id <= 0)                        $errs[] = 'parity_id is required.';
        if (!in_array($stat, $allowed_stat)) $errs[] = 'mobile_status invalid.';
        if (empty($errs)) {
            db_query_prepared(
                "UPDATE mobile_parity SET mobile_status = ?, updated = NOW() WHERE parity_id = ?",
                [$stat, $id]
            );
            $data['parity_id']     = $id;
            $data['mobile_status'] = $stat;
            $_SESSION['success']   = 'OK';
        } else {
            $_SESSION['error'] = implode('<br>', $errs);
        }
    }
}

// ── Summary counts ───────────────────────────────────────────────────────
if (($action ?? null) == 'apiGetMobileParitySummary') {
    if (require_api_scope('actions:read')) {
        $app = trim((string)($_POST['source_app'] ?? ''));
        $sql = "SELECT source_app, category, mobile_status, COUNT(*) AS n
                  FROM mobile_parity";
        $args = [];
        if ($app !== '') { $sql .= ' WHERE source_app = ?'; $args[] = $app; }
        $sql .= ' GROUP BY source_app, category, mobile_status';
        $r = db_query_prepared($sql, $args);
        $rows = $r ? $r->fetchAll(PDO::FETCH_ASSOC) : [];
        // Reshape into nested { app: { category: { status: n } } } for the view.
        $by = [];
        foreach ($rows as $x) {
            $by[$x['source_app']][$x['category']][$x['mobile_status']] = (int)$x['n'];
        }
        $data['summary'] = $by;
        $_SESSION['success'] = 'OK';
    }
}
