<?php
/**
 * views/stripe.php
 * Stripe Payments — transactions, refunds, LTV, and troubleshooting.
 */
$page_title = 'Stripe';
$stripe_configured = is_stripe_configured();
?>

<!-- REGION: page-header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>Stripe Payments</h3>
    <?php if (!$stripe_configured) { ?>
    <span class="badge bg-warning text-dark">Stripe not configured — add keys in config.php</span>
    <?php } else { ?>
    <span class="badge bg-success">Connected</span>
    <?php } ?>
</div>

<!-- Stats cards (populated by JS) -->
<div class="row mb-4" id="statsRow">
    <div class="col-md-2 col-sm-4 mb-3">
        <div class="card h-100">
            <div class="card-body text-center py-2">
                <small class="text-muted d-block">Net Revenue</small>
                <h5 class="mb-0" id="statNetRevenue">—</h5>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-sm-4 mb-3">
        <div class="card h-100">
            <div class="card-body text-center py-2">
                <small class="text-muted d-block">Revenue (30d)</small>
                <h5 class="mb-0" id="statRevenue30d">—</h5>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-sm-4 mb-3">
        <div class="card h-100">
            <div class="card-body text-center py-2">
                <small class="text-muted d-block">Total Refunded</small>
                <h5 class="mb-0" id="statRefunded">—</h5>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-sm-4 mb-3">
        <div class="card h-100">
            <div class="card-body text-center py-2">
                <small class="text-muted d-block">Transactions</small>
                <h5 class="mb-0" id="statTransactions">—</h5>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-sm-4 mb-3">
        <div class="card h-100">
            <div class="card-body text-center py-2">
                <small class="text-muted d-block">Paying Users</small>
                <h5 class="mb-0" id="statPayingUsers">—</h5>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-sm-4 mb-3">
        <div class="card h-100">
            <div class="card-body text-center py-2">
                <small class="text-muted d-block">Avg LTV</small>
                <h5 class="mb-0" id="statAvgLtv">—</h5>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabTransactions" type="button">Transactions</button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabRefunds" type="button">Refunds</button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabLtv" type="button">LTV</button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabTroubleshoot" type="button">Troubleshoot</button>
    </li>
</ul>

<div class="tab-content">

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- TAB: Transactions                                          -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="tab-pane fade show active" id="tabTransactions">

    <!-- Filters -->
    <div class="row mb-3 g-2">
        <div class="col-md-2">
            <select class="form-select form-select-sm" id="txnFilterStatus">
                <option value="">All Statuses</option>
                <option value="succeeded">Succeeded</option>
                <option value="pending">Pending</option>
                <option value="failed">Failed</option>
                <option value="canceled">Canceled</option>
                <option value="refunded">Refunded</option>
                <option value="partially_refunded">Partially Refunded</option>
            </select>
        </div>
        <div class="col-md-2">
            <select class="form-select form-select-sm" id="txnFilterSource">
                <option value="">All Sources</option>
            </select>
        </div>
        <div class="col-md-2">
            <input type="text" class="form-control form-control-sm" id="txnFilterSearch" placeholder="Search ID / desc...">
        </div>
        <div class="col-md-2">
            <input type="date" class="form-control form-control-sm" id="txnFilterFrom">
        </div>
        <div class="col-md-2">
            <input type="date" class="form-control form-control-sm" id="txnFilterTo">
        </div>
        <div class="col-md-2">
            <button class="btn btn-sm btn-primary w-100" id="btnFilterTxn">Filter</button>
        </div>
    </div>

    <!-- Table -->
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Stripe ID</th>
                    <th>User</th>
                    <th>Source</th>
                    <th>Description</th>
                    <th class="text-end">Amount</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="txnBody">
                <tr><td colspan="9" class="text-muted text-center py-4">Loading...</td></tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="d-flex justify-content-between align-items-center">
        <small class="text-muted" id="txnInfo"></small>
        <nav><ul class="pagination pagination-sm mb-0" id="txnPages"></ul></nav>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- TAB: Refunds                                               -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tabRefunds">

    <div class="row mb-3 g-2">
        <div class="col-md-2">
            <select class="form-select form-select-sm" id="refFilterStatus">
                <option value="">All Statuses</option>
                <option value="succeeded">Succeeded</option>
                <option value="pending">Pending</option>
                <option value="failed">Failed</option>
            </select>
        </div>
        <div class="col-md-2">
            <input type="number" class="form-control form-control-sm" id="refFilterUserId" placeholder="User ID">
        </div>
        <div class="col-md-2">
            <input type="date" class="form-control form-control-sm" id="refFilterFrom">
        </div>
        <div class="col-md-2">
            <input type="date" class="form-control form-control-sm" id="refFilterTo">
        </div>
        <div class="col-md-2">
            <button class="btn btn-sm btn-primary w-100" id="btnFilterRef">Filter</button>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Stripe Refund ID</th>
                    <th>Txn ID</th>
                    <th>User</th>
                    <th class="text-end">Amount</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th>Refunded By</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody id="refBody">
                <tr><td colspan="9" class="text-muted text-center py-4">Loading...</td></tr>
            </tbody>
        </table>
    </div>

    <div class="d-flex justify-content-between align-items-center">
        <small class="text-muted" id="refInfo"></small>
        <nav><ul class="pagination pagination-sm mb-0" id="refPages"></ul></nav>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- TAB: LTV (Lifetime Value)                                  -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tabLtv">

    <div class="row mb-4">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">Monthly Revenue Trend</h6></div>
                <div class="card-body"><div id="chartRevenue"></div></div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0">Revenue Summary</h6></div>
                <div class="card-body" id="ltvSummary">
                    <p class="text-muted">Loading...</p>
                </div>
            </div>
        </div>
    </div>

    <h6 class="mb-3">Top Users by Lifetime Value</h6>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead>
                <tr>
                    <th>#</th>
                    <th>User</th>
                    <th class="text-end">Total Paid</th>
                    <th class="text-end">Refunded</th>
                    <th class="text-end">Net Revenue (LTV)</th>
                    <th class="text-end">Monthly Avg</th>
                    <th>Months Active</th>
                    <th>Transactions</th>
                    <th>First Payment</th>
                </tr>
            </thead>
            <tbody id="ltvBody">
                <tr><td colspan="9" class="text-muted text-center py-4">Loading...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- TAB: Troubleshoot                                          -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tabTroubleshoot">

    <div class="card mb-4">
        <div class="card-header"><h6 class="mb-0">Look Up Stripe Object</h6></div>
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-3">
                    <select class="form-select form-select-sm" id="lookupType">
                        <option value="payment_intent">Payment Intent (pi_...)</option>
                        <option value="charge">Charge (ch_...)</option>
                        <option value="customer">Customer (cus_...)</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <input type="text" class="form-control form-control-sm" id="lookupId" placeholder="e.g. pi_3Abc123...">
                </div>
                <div class="col-md-3">
                    <button class="btn btn-sm btn-primary w-100" id="btnLookup" <?= !$stripe_configured ? 'disabled' : '' ?>>Look Up</button>
                </div>
            </div>
        </div>
    </div>

    <div class="card d-none" id="lookupResultCard">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Result</h6>
            <button class="btn btn-sm btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('lookupResult').textContent)">Copy</button>
        </div>
        <div class="card-body">
            <pre id="lookupResult" class="mb-0" style="max-height:500px;overflow:auto;font-size:0.8rem;"></pre>
        </div>
    </div>
</div>

</div><!-- /tab-content -->

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- MODAL: Refund                                              -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="refundModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Process Refund</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="refundTxnId" value="">
                <div class="mb-3">
                    <label class="form-label">Transaction</label>
                    <div id="refundTxnInfo" class="form-control-plaintext"></div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Refund Amount ($)</label>
                    <input type="number" class="form-control" id="refundAmount" step="0.01" min="0.01" placeholder="Leave blank for full refund">
                    <small class="text-muted" id="refundMaxLabel"></small>
                </div>
                <div class="mb-3">
                    <label class="form-label">Reason</label>
                    <select class="form-select" id="refundReason">
                        <option value="requested_by_customer">Requested by customer</option>
                        <option value="duplicate">Duplicate payment</option>
                        <option value="fraudulent">Fraudulent</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="btnConfirmRefund" onclick="window._confirmRefund()">Process Refund</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: Transaction Detail -->
<div class="modal fade" id="txnDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Transaction Detail</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="txnDetailBody">
                <p class="text-muted">Loading...</p>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/d3@7/dist/d3.min.js"></script>
<script src="../assets/js/reports.js"></script>
<script>
(function () {
    'use strict';

    function fmt(v) { return '$' + (parseFloat(v) || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
    function escHtml(s) { if (!s) return ''; var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function statusBadge(s) {
        var map = {
            succeeded: 'bg-success', pending: 'bg-warning text-dark', failed: 'bg-danger',
            canceled: 'bg-secondary', refunded: 'bg-info', partially_refunded: 'bg-info'
        };
        return '<span class="badge ' + (map[s] || 'bg-secondary') + '">' + escHtml(s) + '</span>';
    }
    function shortId(s) { return s && s.length > 20 ? s.substring(0, 20) + '...' : (s || ''); }

    // ── Load stats ──────────────────────────────────────────
    function loadStats() {
        var fd = new FormData();
        fd.append('action', 'getStripeStats');
        fetch('../api/index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.error) return;
                var s = j.results.stats;
                document.getElementById('statNetRevenue').textContent    = fmt(s.net_revenue);
                document.getElementById('statRevenue30d').textContent    = fmt(s.revenue_30d);
                document.getElementById('statRefunded').textContent      = fmt(s.total_refunded);
                document.getElementById('statTransactions').textContent  = s.total_transactions.toLocaleString();
                document.getElementById('statPayingUsers').textContent   = s.paying_users.toLocaleString();
                document.getElementById('statAvgLtv').textContent        = fmt(s.avg_ltv);
            });
    }
    loadStats();

    // ── Transactions Tab ────────────────────────────────────
    var txnPage = 1;

    function loadTransactions(page) {
        txnPage = page || 1;
        var fd = new FormData();
        fd.append('action', 'getStripeTransactions');
        fd.append('page', txnPage);
        if (document.getElementById('txnFilterStatus').value)  fd.append('status',     document.getElementById('txnFilterStatus').value);
        if (document.getElementById('txnFilterSource').value)  fd.append('source_app', document.getElementById('txnFilterSource').value);
        if (document.getElementById('txnFilterSearch').value)  fd.append('search',     document.getElementById('txnFilterSearch').value);
        if (document.getElementById('txnFilterFrom').value)    fd.append('from_date',  document.getElementById('txnFilterFrom').value);
        if (document.getElementById('txnFilterTo').value)      fd.append('to_date',    document.getElementById('txnFilterTo').value);

        fetch('../api/index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.error) { console.error(j.error); return; }
                var d = j.results;

                // Populate source filter
                var sel = document.getElementById('txnFilterSource');
                var curVal = sel.value;
                sel.innerHTML = '<option value="">All Sources</option>';
                (d.source_apps || []).forEach(function (app) {
                    var opt = document.createElement('option');
                    opt.value = app; opt.textContent = app;
                    if (app === curVal) opt.selected = true;
                    sel.appendChild(opt);
                });

                var tbody = document.getElementById('txnBody');
                if (!d.items || d.items.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="9" class="text-muted text-center py-4">No transactions found.</td></tr>';
                } else {
                    tbody.innerHTML = d.items.map(function (row) {
                        var userCol = row.user_email ? '<a href="index.php?page=user_edit&id=' + row.user_id + '">' + escHtml(row.user_email) + '</a>' : (row.user_id || '<span class="text-muted">—</span>');
                        var canRefund = (row.status === 'succeeded' || row.status === 'partially_refunded') && row.stripe_payment_id;
                        var actions = '<button class="btn btn-sm btn-outline-secondary me-1" onclick="window._viewTxn(' + row.transaction_id + ')" title="Details"><i class="bi bi-eye"></i></button>';
                        if (canRefund) {
                            actions += '<button class="btn btn-sm btn-outline-danger" onclick="window._openRefund(' + row.transaction_id + ', ' + row.amount + ')" title="Refund"><i class="bi bi-arrow-counterclockwise"></i></button>';
                        }
                        return '<tr>' +
                            '<td>' + row.transaction_id + '</td>' +
                            '<td><small class="font-monospace">' + escHtml(shortId(row.stripe_payment_id)) + '</small></td>' +
                            '<td>' + userCol + '</td>' +
                            '<td>' + escHtml(row.source_app) + '</td>' +
                            '<td>' + escHtml(row.description || '') + '</td>' +
                            '<td class="text-end font-monospace">' + fmt(row.amount) + '</td>' +
                            '<td>' + statusBadge(row.status) + '</td>' +
                            '<td><small>' + (row.created ? row.created.substring(0, 16) : '') + '</small></td>' +
                            '<td class="text-nowrap">' + actions + '</td>' +
                            '</tr>';
                    }).join('');
                }

                // Pagination
                var totalPages = Math.ceil(d.total / d.per_page);
                document.getElementById('txnInfo').textContent = d.total > 0 ?
                    'Showing ' + ((d.page - 1) * d.per_page + 1) + '–' + Math.min(d.page * d.per_page, d.total) + ' of ' + d.total : '';
                var pagesEl = document.getElementById('txnPages');
                pagesEl.innerHTML = '';
                for (var p = 1; p <= Math.min(totalPages, 10); p++) {
                    var li = document.createElement('li');
                    li.className = 'page-item' + (p === d.page ? ' active' : '');
                    li.innerHTML = '<button class="page-link" onclick="window._loadTxn(' + p + ')">' + p + '</button>';
                    pagesEl.appendChild(li);
                }
            });
    }

    window._loadTxn = loadTransactions;
    document.getElementById('btnFilterTxn').addEventListener('click', function () { loadTransactions(1); });
    loadTransactions(1);

    // ── View transaction detail ─────────────────────────────
    window._viewTxn = function (txnId) {
        document.getElementById('txnDetailBody').innerHTML = '<p class="text-muted">Loading...</p>';
        var modal = new bootstrap.Modal(document.getElementById('txnDetailModal'));
        modal.show();

        var fd = new FormData();
        fd.append('action', 'getStripeTransaction');
        fd.append('transaction_id', txnId);
        fetch('../api/index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.error) { document.getElementById('txnDetailBody').innerHTML = '<p class="text-danger">' + escHtml(j.error) + '</p>'; return; }
                var t = j.results.transaction;
                var refTotal = j.results.refund_total;
                var refs = j.results.refunds || [];
                var ltv = j.results.user_ltv;

                var html = '<div class="row"><div class="col-md-6">';
                html += '<table class="table table-sm">';
                html += '<tr><th>Transaction ID</th><td>' + t.transaction_id + '</td></tr>';
                html += '<tr><th>Stripe Payment ID</th><td class="font-monospace">' + escHtml(t.stripe_payment_id || '—') + '</td></tr>';
                html += '<tr><th>Stripe Customer</th><td class="font-monospace">' + escHtml(t.stripe_customer_id || '—') + '</td></tr>';
                html += '<tr><th>Stripe Invoice</th><td class="font-monospace">' + escHtml(t.stripe_invoice_id || '—') + '</td></tr>';
                html += '<tr><th>Subscription</th><td class="font-monospace">' + escHtml(t.stripe_subscription_id || '—') + '</td></tr>';
                html += '<tr><th>Amount</th><td>' + fmt(t.amount) + ' ' + (t.currency || 'usd').toUpperCase() + '</td></tr>';
                html += '<tr><th>Status</th><td>' + statusBadge(t.status) + '</td></tr>';
                html += '<tr><th>Refunded</th><td>' + fmt(refTotal) + '</td></tr>';
                html += '</table></div><div class="col-md-6">';
                html += '<table class="table table-sm">';
                html += '<tr><th>User</th><td>' + (t.user_email ? escHtml(t.user_email) + ' (#' + t.user_id + ')' : '—') + '</td></tr>';
                html += '<tr><th>Source App</th><td>' + escHtml(t.source_app) + '</td></tr>';
                html += '<tr><th>Payment Method</th><td>' + escHtml(t.payment_method || '—') + '</td></tr>';
                html += '<tr><th>Description</th><td>' + escHtml(t.description || '—') + '</td></tr>';
                html += '<tr><th>Created</th><td>' + escHtml(t.created) + '</td></tr>';
                if (ltv) {
                    html += '<tr><th>User LTV</th><td>' + fmt(ltv.ltv) + ' (' + fmt(ltv.monthly_avg) + '/mo over ' + ltv.months_active + ' months)</td></tr>';
                }
                html += '</table></div></div>';

                if (t.metadata) {
                    html += '<h6 class="mt-3">Metadata</h6><pre class="bg-light p-2 rounded" style="max-height:150px;overflow:auto;font-size:0.8rem;">' + escHtml(JSON.stringify(JSON.parse(t.metadata), null, 2)) + '</pre>';
                }

                if (refs.length > 0) {
                    html += '<h6 class="mt-3">Refunds</h6><table class="table table-sm"><thead><tr><th>ID</th><th>Amount</th><th>Reason</th><th>Status</th><th>Date</th><th>By</th></tr></thead><tbody>';
                    refs.forEach(function (r) {
                        html += '<tr><td>' + r.refund_id + '</td><td>' + fmt(r.amount) + '</td><td>' + escHtml(r.reason || '—') + '</td><td>' + statusBadge(r.status) + '</td><td><small>' + (r.created || '').substring(0, 16) + '</small></td><td>' + escHtml(r.refunded_by_email || '—') + '</td></tr>';
                    });
                    html += '</tbody></table>';
                }

                document.getElementById('txnDetailBody').innerHTML = html;
            });
    };

    // ── Refund modal ────────────────────────────────────────
    window._openRefund = function (txnId, amount) {
        document.getElementById('refundTxnId').value = txnId;
        document.getElementById('refundAmount').value = '';
        document.getElementById('refundTxnInfo').textContent = 'Transaction #' + txnId + ' — ' + fmt(amount);
        document.getElementById('refundMaxLabel').textContent = 'Max refundable: ' + fmt(amount);
        document.getElementById('refundReason').value = 'requested_by_customer';
        var modal = new bootstrap.Modal(document.getElementById('refundModal'));
        modal.show();
    };

    window._confirmRefund = function () {
        var btn = document.getElementById('btnConfirmRefund');
        btn.disabled = true;
        btn.textContent = 'Processing...';

        var fd = new FormData();
        fd.append('action', 'processRefund');
        fd.append('transaction_id', document.getElementById('refundTxnId').value);
        fd.append('reason', document.getElementById('refundReason').value);
        var amt = document.getElementById('refundAmount').value;
        if (amt) fd.append('refund_amount', amt);

        fetch('../api/index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                btn.disabled = false;
                btn.textContent = 'Process Refund';
                if (j.error) { alert(j.error); return; }
                bootstrap.Modal.getInstance(document.getElementById('refundModal')).hide();
                loadTransactions(txnPage);
                loadStats();
                alert('Refund processed successfully.');
            })
            .catch(function () {
                btn.disabled = false;
                btn.textContent = 'Process Refund';
            });
    };

    // ── Refunds Tab ─────────────────────────────────────────
    var refPage = 1;
    var refLoaded = false;

    document.querySelector('[data-bs-target="#tabRefunds"]').addEventListener('shown.bs.tab', function () {
        if (refLoaded) return;
        refLoaded = true;
        loadRefunds(1);
    });

    function loadRefunds(page) {
        refPage = page || 1;
        var fd = new FormData();
        fd.append('action', 'getStripeRefunds');
        fd.append('page', refPage);
        if (document.getElementById('refFilterStatus').value) fd.append('status', document.getElementById('refFilterStatus').value);
        if (document.getElementById('refFilterUserId').value) fd.append('user_id_filter', document.getElementById('refFilterUserId').value);
        if (document.getElementById('refFilterFrom').value)   fd.append('from_date', document.getElementById('refFilterFrom').value);
        if (document.getElementById('refFilterTo').value)     fd.append('to_date', document.getElementById('refFilterTo').value);

        fetch('../api/index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.error) return;
                var d = j.results;
                var tbody = document.getElementById('refBody');

                if (!d.items || d.items.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="9" class="text-muted text-center py-4">No refunds found.</td></tr>';
                } else {
                    tbody.innerHTML = d.items.map(function (row) {
                        return '<tr>' +
                            '<td>' + row.refund_id + '</td>' +
                            '<td><small class="font-monospace">' + escHtml(shortId(row.stripe_refund_id)) + '</small></td>' +
                            '<td>' + row.transaction_id + '</td>' +
                            '<td>' + escHtml(row.user_email || row.user_id || '—') + '</td>' +
                            '<td class="text-end font-monospace">' + fmt(row.amount) + '</td>' +
                            '<td>' + escHtml(row.reason || '—') + '</td>' +
                            '<td>' + statusBadge(row.status) + '</td>' +
                            '<td>' + escHtml(row.refunded_by_email || '—') + '</td>' +
                            '<td><small>' + (row.created ? row.created.substring(0, 16) : '') + '</small></td>' +
                            '</tr>';
                    }).join('');
                }

                var totalPages = Math.ceil(d.total / d.per_page);
                document.getElementById('refInfo').textContent = d.total > 0 ?
                    'Showing ' + ((d.page - 1) * d.per_page + 1) + '–' + Math.min(d.page * d.per_page, d.total) + ' of ' + d.total : '';
                var pagesEl = document.getElementById('refPages');
                pagesEl.innerHTML = '';
                for (var p = 1; p <= Math.min(totalPages, 10); p++) {
                    var li = document.createElement('li');
                    li.className = 'page-item' + (p === d.page ? ' active' : '');
                    li.innerHTML = '<button class="page-link" onclick="window._loadRef(' + p + ')">' + p + '</button>';
                    pagesEl.appendChild(li);
                }
            });
    }

    window._loadRef = loadRefunds;
    document.getElementById('btnFilterRef').addEventListener('click', function () { loadRefunds(1); });

    // ── LTV Tab ─────────────────────────────────────────────
    var ltvLoaded = false;

    document.querySelector('[data-bs-target="#tabLtv"]').addEventListener('shown.bs.tab', function () {
        if (ltvLoaded) return;
        ltvLoaded = true;
        loadLtv();
        loadRevenueChart();
    });

    function loadLtv() {
        var fd = new FormData();
        fd.append('action', 'getStripeLtv');
        fd.append('limit', 50);

        fetch('../api/index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.error) return;
                var rows = j.results.leaderboard || [];
                var tbody = document.getElementById('ltvBody');

                if (rows.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="9" class="text-muted text-center py-4">No paying users yet.</td></tr>';
                    document.getElementById('ltvSummary').innerHTML = '<p class="text-muted">No data.</p>';
                    return;
                }

                tbody.innerHTML = rows.map(function (row, i) {
                    return '<tr>' +
                        '<td>' + (i + 1) + '</td>' +
                        '<td><a href="index.php?page=user_edit&id=' + row.user_id + '">' + escHtml(row.email) + '</a></td>' +
                        '<td class="text-end font-monospace">' + fmt(row.total_paid) + '</td>' +
                        '<td class="text-end font-monospace">' + fmt(row.total_refunded) + '</td>' +
                        '<td class="text-end font-monospace fw-bold">' + fmt(row.net_revenue) + '</td>' +
                        '<td class="text-end">' + fmt(row.monthly_avg) + '/mo</td>' +
                        '<td>' + row.months_active + '</td>' +
                        '<td>' + row.transaction_count + '</td>' +
                        '<td><small>' + (row.first_payment_date ? row.first_payment_date.substring(0, 10) : '—') + '</small></td>' +
                        '</tr>';
                }).join('');

                // Summary
                var totalRev = rows.reduce(function (sum, r) { return sum + parseFloat(r.net_revenue); }, 0);
                var avgRev = totalRev / rows.length;
                document.getElementById('ltvSummary').innerHTML =
                    '<dl class="row mb-0">' +
                    '<dt class="col-7">Paying Users</dt><dd class="col-5">' + rows.length + '</dd>' +
                    '<dt class="col-7">Total Net Revenue</dt><dd class="col-5">' + fmt(totalRev) + '</dd>' +
                    '<dt class="col-7">Average LTV</dt><dd class="col-5">' + fmt(avgRev) + '</dd>' +
                    '<dt class="col-7">Top User LTV</dt><dd class="col-5">' + fmt(rows[0].net_revenue) + '</dd>' +
                    '</dl>';
            });
    }

    function loadRevenueChart() {
        var R = window.WNReports;
        if (!R) return;
        var colors = R.getThemeColors();

        var fd = new FormData();
        fd.append('action', 'getRevenueChart');
        fd.append('months', 12);
        fetch('../api/index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.error) return;
                var trend = j.results.monthly_trend || [];
                var chartData = trend.map(function (row) {
                    return {
                        date_val: row.month_val,
                        revenue: parseFloat(row.revenue) || 0,
                        refunded: parseFloat(row.refunded) || 0,
                        net: parseFloat(row.net) || 0
                    };
                });

                R.areaChart('#chartRevenue', chartData, {
                    xKey: 'date_val',
                    area: true,
                    series: [
                        { key: 'revenue',  color: colors.success, label: 'Revenue' },
                        { key: 'refunded', color: colors.danger,  label: 'Refunded' },
                        { key: 'net',      color: colors.primary, label: 'Net' },
                    ],
                });
            });
    }

    // ── Troubleshoot Tab ────────────────────────────────────
    document.getElementById('btnLookup').addEventListener('click', function () {
        var type = document.getElementById('lookupType').value;
        var id   = document.getElementById('lookupId').value.trim();
        if (!id) { alert('Enter a Stripe ID.'); return; }

        var btn = this;
        btn.disabled = true;
        btn.textContent = 'Looking up...';

        var fd = new FormData();
        fd.append('action', 'stripeLookup');
        fd.append('lookup_type', type);
        fd.append('lookup_id', id);

        fetch('../api/index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                btn.disabled = false;
                btn.textContent = 'Look Up';
                var card = document.getElementById('lookupResultCard');
                card.classList.remove('d-none');

                if (j.error) {
                    document.getElementById('lookupResult').textContent = 'Error: ' + j.error;
                } else {
                    document.getElementById('lookupResult').textContent = JSON.stringify(j.results.stripe_data, null, 2);
                }
            })
            .catch(function () {
                btn.disabled = false;
                btn.textContent = 'Look Up';
            });
    });

})();
</script>
