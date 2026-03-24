<?php
/**
 * Cost API Actions
 * Actions: apiRecordCost, apiRecordCostBatch, apiGetCostSummary
 * Authenticated via service API key (Bearer token) with scope gating.
 */

// ── Record a single cost entry ──────────────────────────────
if (($action ?? null) == 'apiRecordCost') {
    if (require_api_scope('costs:write')) {
        $errs = [];

        $cost_type   = trim($_POST['cost_type'] ?? '');
        $source_app  = trim($_POST['source_app'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $amount      = $_POST['amount'] ?? '';

        if (!in_array($cost_type, ['cogs', 'cac', 'support'])) {
            $errs['cost_type'] = 'cost_type must be cogs, cac, or support.';
        }
        if ($source_app === '') {
            $errs['source_app'] = 'source_app is required.';
        }
        if ($description === '') {
            $errs['description'] = 'description is required.';
        }
        if (!is_numeric($amount) || floatval($amount) < 0) {
            $errs['amount'] = 'amount must be a non-negative number.';
        }

        if (count($errs) <= 0) {
            $opts = [];
            if (isset($_POST['user_id']) && $_POST['user_id'] !== '') {
                $opts['user_id'] = intval($_POST['user_id']);
            }
            if (!empty($_POST['currency'])) {
                $opts['currency'] = $_POST['currency'];
            }
            if (!empty($_POST['metadata'])) {
                $opts['metadata'] = $_POST['metadata'];
            }

            $cost_id = record_cost($cost_type, $source_app, $description, floatval($amount), $opts);
            if ($cost_id !== false) {
                $data['cost_id'] = $cost_id;
                $_SESSION['success'] = 'Cost recorded.';
            } else {
                $_SESSION['error'] = 'Failed to record cost.';
            }
        } else {
            $_SESSION['error'] = implode(' ', $errs);
        }
    }
}

// ── Record multiple cost entries at once ─────────────────────
if (($action ?? null) == 'apiRecordCostBatch') {
    if (require_api_scope('costs:write')) {
        $errs = [];

        $entries_json = $_POST['entries'] ?? '';
        $entries = json_decode($entries_json, true);

        if (!is_array($entries) || count($entries) === 0) {
            $errs['entries'] = 'entries must be a non-empty JSON array.';
        } elseif (count($entries) > 500) {
            $errs['entries'] = 'Maximum 500 entries per batch.';
        }

        if (count($errs) <= 0) {
            $result = record_cost_batch($entries);
            $data['inserted'] = $result['inserted'];
            $data['failed']   = $result['failed'];
            $_SESSION['success'] = $result['inserted'] . ' cost(s) recorded.';
        } else {
            $_SESSION['error'] = implode(' ', $errs);
        }
    }
}

// ── Get cost summary (aggregated totals) ────────────────────
if (($action ?? null) == 'apiGetCostSummary') {
    if (require_api_scope('costs:read')) {
        $filters = [];
        if (!empty($_POST['cost_type']))  $filters['cost_type']  = $_POST['cost_type'];
        if (!empty($_POST['source_app'])) $filters['source_app'] = $_POST['source_app'];
        if (isset($_POST['user_id']) && $_POST['user_id'] !== '') $filters['user_id'] = $_POST['user_id'];
        if (!empty($_POST['from_date'])) $filters['from_date'] = $_POST['from_date'];
        if (!empty($_POST['to_date']))   $filters['to_date']   = $_POST['to_date'];

        $data['summary']          = get_cost_summary($filters);
        $data['recurring_monthly'] = get_recurring_monthly_total();
        $_SESSION['success'] = 'OK';
    }
}
