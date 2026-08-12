<?php
/**
 * dbSizeApiActions.php
 * Public API action, authenticated via service API key (Bearer token).
 * Exposes this host's database sizes so a central monitor (wave-networks-core
 * on the ops host) can poll every registered shard's admin API and aggregate.
 * Action: apiGetDbSizes (scope: system:read)
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
