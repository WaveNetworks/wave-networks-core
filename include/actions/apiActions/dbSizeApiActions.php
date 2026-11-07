<?php
/**
 * dbSizeApiActions.php
 * Public API action, authenticated via service API key (Bearer token).
 * Exposes this host's database sizes so a central monitor (wave-networks-core
 * on the ops host) can poll every registered shard's admin API and aggregate.
 * Actions: apiGetDbSizes, apiGetDbTableSizes (scope: system:read)
 *
 * apiGetDbTableSizes: optional schema, limit (1-100, default 15), count_table (a real COUNT(*)
 * of that table, with `schema` required so one call cannot scan every database). Table names,
 * sizes and counts only: never row contents or credentials.
 */

if (($action ?? null) == 'apiGetDbSizes') {
    if (require_api_scope('system:read')) {
        $rows = get_live_db_sizes();

        $total_mb = 0;
        $child_db_sizes = [];
        foreach ($rows as $r) {
            $total_mb += $r['size_mb'];
            $child_db_sizes[] = [
                'app_name' => $r['schema_name'],
                'size_mb'  => $r['size_mb'],
                'status'   => $r['status'],
            ];
        }

        $data['schemas']        = $rows;
        $data['child_db_sizes'] = $child_db_sizes;
        $data['total_mb']       = round($total_mb, 2);
        $data['limit_mb']       = DB_SIZE_LIMIT_MB;
        $data['warn_mb']        = DB_SIZE_WARN_MB;
        $data['critical_mb']    = DB_SIZE_CRITICAL_MB;
        $_SESSION['success'] = 'OK';
    }
}

if (($action ?? null) == 'apiGetDbTableSizes') {
    if (require_api_scope('system:read')) {
        $schema = trim((string) ($_POST['schema'] ?? $_GET['schema'] ?? ''));
        $count  = trim((string) ($_POST['count_table'] ?? $_GET['count_table'] ?? ''));
        if ($count !== '' && $schema === '') {
            $_SESSION['error'] = 'count_table needs schema.';
        } else {
            $data['schemas'] = get_live_db_table_sizes($schema, $_POST['limit'] ?? $_GET['limit'] ?? 15, $count);
            $_SESSION['success'] = 'OK';
        }
    }
}
