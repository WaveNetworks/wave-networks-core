<?php
/**
 * dbSizeActions.php
 * Admin action: force a live refresh of the shard DB size cache from the
 * ?page=db_sizes view.
 */

if (($_POST['action'] ?? null) == 'refreshDbSizes') {
    $errs = array();
    if (!has_role('admin')) { $errs['auth'] = 'Admin access required.'; }

    if (count($errs) <= 0) {
        $rows = refresh_db_size_cache();
        $data['db_sizes'] = $rows;
        $_SESSION['success'] = 'Database sizes refreshed (' . count($rows) . ' schemas).';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}
