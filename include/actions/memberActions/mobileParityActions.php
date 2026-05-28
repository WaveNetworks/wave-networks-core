<?php
/**
 * Mobile Parity member actions — session-authed wrappers for the admin UI.
 *
 *   memberListMobileParity      list rows with filters (admin only)
 *   memberSetMobileParityStatus quick status flip (admin only)
 *
 * The apiActions/mobileParityActions.php equivalents are Bearer-token gated
 * (require_api_scope) for external callers (the audit script, MCP tools).
 * views/mobile_parity.php is loaded in an admin session and uses apiPost(),
 * which sends NO Bearer token, so it must hit member actions instead — or
 * the page sits at "Loading…" forever because the scope check returns
 * "Service API key required." as a JSON error. (Diagnosed 2026-05-22 after
 * the view shipped wired to api* names that aren't reachable from a session.)
 */

// ── List parity rows ─────────────────────────────────────────────────────
if (($action ?? null) == 'memberListMobileParity') {
    $errs = array();
    if (empty($_SESSION['user_id'])) { $errs['auth'] = 'Login required.'; }
    if (empty($errs) && !has_role('admin')) { $errs['role'] = 'Admin role required.'; }

    if (empty($errs)) {
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
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Quick status flip ────────────────────────────────────────────────────
if (($action ?? null) == 'memberSetMobileParityStatus') {
    $errs = array();
    if (empty($_SESSION['user_id'])) { $errs['auth'] = 'Login required.'; }
    if (empty($errs) && !has_role('admin')) { $errs['role'] = 'Admin role required.'; }

    $id   = (int)($_POST['parity_id'] ?? 0);
    $stat = trim((string)($_POST['mobile_status'] ?? ''));
    $allowed_stat = ['missing','partial','wired','n_a'];
    if (empty($errs) && $id <= 0)                        $errs['id']     = 'parity_id is required.';
    if (empty($errs) && !in_array($stat, $allowed_stat)) $errs['status'] = 'mobile_status invalid.';

    if (empty($errs)) {
        db_query_prepared(
            "UPDATE mobile_parity SET mobile_status = ?, updated = NOW() WHERE parity_id = ?",
            [$stat, $id]
        );
        $data['parity_id']     = $id;
        $data['mobile_status'] = $stat;
        $_SESSION['success']   = 'Status updated.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}
