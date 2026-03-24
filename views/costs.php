<?php
/**
 * views/costs.php
 * Cost Tracking — cost log, recurring expenses, and cost reports.
 */
$page_title = 'Costs';
$recurring = get_recurring_costs();
?>

<!-- REGION: page-header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>Cost Tracking</h3>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabCostLog" type="button">Cost Log</button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabRecurring" type="button">Recurring Expenses</button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabReport" type="button">Cost Report</button>
    </li>
</ul>

<div class="tab-content">

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- TAB: Cost Log                                              -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="tab-pane fade show active" id="tabCostLog">

    <!-- Filters -->
    <div class="row mb-3 g-2">
        <div class="col-md-2">
            <select class="form-select form-select-sm" id="filterType">
                <option value="">All Types</option>
                <option value="cogs">COGS</option>
                <option value="cac">CAC</option>
                <option value="support">Support</option>
            </select>
        </div>
        <div class="col-md-2">
            <select class="form-select form-select-sm" id="filterSource">
                <option value="">All Sources</option>
            </select>
        </div>
        <div class="col-md-2">
            <input type="number" class="form-control form-control-sm" id="filterUserId" placeholder="User ID">
        </div>
        <div class="col-md-2">
            <input type="date" class="form-control form-control-sm" id="filterFrom" placeholder="From">
        </div>
        <div class="col-md-2">
            <input type="date" class="form-control form-control-sm" id="filterTo" placeholder="To">
        </div>
        <div class="col-md-2">
            <button class="btn btn-sm btn-primary w-100" id="btnFilterCosts">Filter</button>
        </div>
    </div>

    <!-- Results -->
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle" id="costLogTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Type</th>
                    <th>Source</th>
                    <th>User</th>
                    <th>Description</th>
                    <th class="text-end">Amount</th>
                    <th>Date</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="costLogBody">
                <tr><td colspan="8" class="text-muted text-center py-4">Loading...</td></tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="d-flex justify-content-between align-items-center" id="costLogPagination">
        <small class="text-muted" id="costLogInfo"></small>
        <nav>
            <ul class="pagination pagination-sm mb-0" id="costLogPages"></ul>
        </nav>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- TAB: Recurring Expenses                                    -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tabRecurring">
    <div class="d-flex justify-content-end mb-3">
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#recurringModal" onclick="openRecurringModal()">
            <i class="bi bi-plus-lg"></i> Add Recurring Cost
        </button>
    </div>

    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Description</th>
                    <th class="text-end">Amount</th>
                    <th>Frequency</th>
                    <th class="text-end">Monthly Equiv.</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="recurringBody">
                <?php if (count($recurring) === 0) { ?>
                <tr><td colspan="7" class="text-muted text-center py-4">No recurring expenses configured.</td></tr>
                <?php } ?>
                <?php foreach ($recurring as $rec) {
                    $monthlyEquiv = (float) $rec['amount'];
                    switch ($rec['frequency']) {
                        case 'daily':  $monthlyEquiv *= 30; break;
                        case 'weekly': $monthlyEquiv *= 4.33; break;
                        case 'yearly': $monthlyEquiv /= 12; break;
                    }
                    $badgeClass = $rec['is_active'] ? 'bg-success' : 'bg-secondary';
                    $badgeText  = $rec['is_active'] ? 'Active' : 'Paused';
                    $typeBadge  = match($rec['cost_type']) {
                        'cogs'    => '<span class="badge bg-info">COGS</span>',
                        'cac'     => '<span class="badge bg-warning text-dark">CAC</span>',
                        'support' => '<span class="badge bg-primary">Support</span>',
                        default   => '<span class="badge bg-secondary">—</span>',
                    };
                ?>
                <tr id="recurring-<?= (int)$rec['recurring_id'] ?>">
                    <td><?= $typeBadge ?></td>
                    <td><?= h($rec['description']) ?></td>
                    <td class="text-end">$<?= number_format((float)$rec['amount'], 2) ?></td>
                    <td><?= h(ucfirst($rec['frequency'])) ?></td>
                    <td class="text-end">$<?= number_format($monthlyEquiv, 2) ?>/mo</td>
                    <td><span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span></td>
                    <td>
                        <button class="btn btn-sm btn-outline-secondary" data-edit-recurring="<?= h(json_encode($rec)) ?>" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-warning" onclick="toggleRecurring(<?= (int)$rec['recurring_id'] ?>)" title="Toggle">
                            <i class="bi bi-toggle-on"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteRecurring(<?= (int)$rec['recurring_id'] ?>)" title="Delete">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>

    <!-- Monthly totals summary -->
    <div class="row mt-3">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <h6 class="text-muted mb-1">COGS / mo</h6>
                    <h4 class="mb-0">$<?= number_format(get_recurring_monthly_total('cogs'), 2) ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <h6 class="text-muted mb-1">CAC / mo</h6>
                    <h4 class="mb-0">$<?= number_format(get_recurring_monthly_total('cac'), 2) ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <h6 class="text-muted mb-1">Support / mo</h6>
                    <h4 class="mb-0">$<?= number_format(get_recurring_monthly_total('support'), 2) ?></h4>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- TAB: Cost Report                                           -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tabReport">

    <!-- Summary cards (populated by JS) -->
    <div class="row mb-4" id="reportSummary">
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card h-100">
                <div class="card-body">
                    <h6 class="card-title text-muted mb-1">COGS (30d)</h6>
                    <h2 id="statCogs" class="mb-0">—</h2>
                    <small class="text-muted" id="statCogsRecurring"></small>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card h-100">
                <div class="card-body">
                    <h6 class="card-title text-muted mb-1">CAC (30d)</h6>
                    <h2 id="statCac" class="mb-0">—</h2>
                    <small class="text-muted" id="statCacRecurring"></small>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card h-100">
                <div class="card-body">
                    <h6 class="card-title text-muted mb-1">Support (30d)</h6>
                    <h2 id="statSupport" class="mb-0">—</h2>
                    <small class="text-muted" id="statSupportRecurring"></small>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card h-100">
                <div class="card-body">
                    <h6 class="card-title text-muted mb-1">Cost / Active User</h6>
                    <h2 id="statPerUser" class="mb-0">—</h2>
                    <small class="text-muted" id="statUserCount"></small>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts -->
    <div class="row mb-4">
        <div class="col-lg-8 mb-3">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0">Monthly Cost Trend</h6></div>
                <div class="card-body">
                    <div id="chartCostTrend"></div>
                </div>
            </div>
        </div>
        <div class="col-lg-4 mb-3">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0">Cost by Source (30d)</h6></div>
                <div class="card-body">
                    <div id="chartBySource"></div>
                </div>
            </div>
        </div>
    </div>
</div>

</div><!-- /tab-content -->

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- MODAL: Add/Edit Recurring Cost                             -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="recurringModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="recurringModalTitle">Add Recurring Cost</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="recEditId" value="">
                <div class="mb-3">
                    <label class="form-label">Cost Type</label>
                    <select class="form-select" id="recType">
                        <option value="cogs">COGS</option>
                        <option value="cac">CAC</option>
                        <option value="support">Support</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <input type="text" class="form-control" id="recDescription" placeholder="e.g. OpenAI API subscription">
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Amount ($)</label>
                        <input type="number" class="form-control" id="recAmount" step="0.01" min="0">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Frequency</label>
                        <select class="form-select" id="recFrequency">
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly" selected>Monthly</option>
                            <option value="yearly">Yearly</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnSaveRecurring" onclick="saveRecurring()">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- D3.js + reports.js (reuse existing chart library) -->
<script src="https://cdn.jsdelivr.net/npm/d3@7/dist/d3.min.js"></script>
<script src="../assets/js/reports.js"></script>
<script>
(function () {
    'use strict';

    // ── Cost Log Tab ────────────────────────────────────────
    var currentPage = 1;

    function loadCostLog(page) {
        currentPage = page || 1;
        var fd = new FormData();
        fd.append('action', 'getCostData');
        fd.append('page', currentPage);
        if (document.getElementById('filterType').value)   fd.append('cost_type',  document.getElementById('filterType').value);
        if (document.getElementById('filterSource').value) fd.append('source_app', document.getElementById('filterSource').value);
        if (document.getElementById('filterUserId').value) fd.append('user_id_filter', document.getElementById('filterUserId').value);
        if (document.getElementById('filterFrom').value)   fd.append('from_date',  document.getElementById('filterFrom').value);
        if (document.getElementById('filterTo').value)     fd.append('to_date',    document.getElementById('filterTo').value);

        fetch('../api/index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.error) { console.error(j.error); return; }
                var d = j.results;

                // Populate source filter dropdown
                var sel = document.getElementById('filterSource');
                var curVal = sel.value;
                sel.innerHTML = '<option value="">All Sources</option>';
                (d.source_apps || []).forEach(function (app) {
                    var opt = document.createElement('option');
                    opt.value = app; opt.textContent = app;
                    if (app === curVal) opt.selected = true;
                    sel.appendChild(opt);
                });

                // Populate table
                var tbody = document.getElementById('costLogBody');
                if (!d.items || d.items.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="8" class="text-muted text-center py-4">No cost entries found.</td></tr>';
                } else {
                    tbody.innerHTML = d.items.map(function (row) {
                        var typeBadge = {
                            cogs: '<span class="badge bg-info">COGS</span>',
                            cac: '<span class="badge bg-warning text-dark">CAC</span>',
                            support: '<span class="badge bg-primary">Support</span>'
                        }[row.cost_type] || '';

                        var userCol = row.user_id ? row.user_id : '<span class="text-muted">—</span>';
                        var amount  = parseFloat(row.amount);
                        var amtStr  = amount < 0.01 ? '$' + amount.toFixed(6) : '$' + amount.toFixed(2);
                        var date    = row.created ? row.created.substring(0, 16) : '';
                        var metaBtn = row.metadata ? '<button class="btn btn-sm btn-outline-secondary" onclick="alert(JSON.stringify(JSON.parse(this.dataset.meta), null, 2))" data-meta=\'' + row.metadata.replace(/'/g, '&#39;') + '\' title="View metadata"><i class="bi bi-code-slash"></i></button>' : '';

                        return '<tr>' +
                            '<td>' + row.cost_id + '</td>' +
                            '<td>' + typeBadge + '</td>' +
                            '<td>' + escHtml(row.source_app) + '</td>' +
                            '<td>' + userCol + '</td>' +
                            '<td>' + escHtml(row.description) + '</td>' +
                            '<td class="text-end font-monospace">' + amtStr + '</td>' +
                            '<td><small>' + date + '</small></td>' +
                            '<td>' + metaBtn + '</td>' +
                            '</tr>';
                    }).join('');
                }

                // Pagination info
                var totalPages = Math.ceil(d.total / d.per_page);
                document.getElementById('costLogInfo').textContent =
                    'Showing ' + ((d.page - 1) * d.per_page + 1) + '–' + Math.min(d.page * d.per_page, d.total) + ' of ' + d.total;

                var pagesEl = document.getElementById('costLogPages');
                pagesEl.innerHTML = '';
                for (var p = 1; p <= Math.min(totalPages, 10); p++) {
                    var li = document.createElement('li');
                    li.className = 'page-item' + (p === d.page ? ' active' : '');
                    li.innerHTML = '<button class="page-link" onclick="window._loadCostLog(' + p + ')">' + p + '</button>';
                    pagesEl.appendChild(li);
                }
            })
            .catch(function (err) { console.error('Cost log error:', err); });
    }

    window._loadCostLog = loadCostLog;

    document.getElementById('btnFilterCosts').addEventListener('click', function () { loadCostLog(1); });

    // Load on tab show
    loadCostLog(1);

    // ── Recurring Expenses Tab ──────────────────────────────

    window.openRecurringModal = function (data) {
        document.getElementById('recEditId').value = '';
        document.getElementById('recType').value = 'cogs';
        document.getElementById('recDescription').value = '';
        document.getElementById('recAmount').value = '';
        document.getElementById('recFrequency').value = 'monthly';
        document.getElementById('recurringModalTitle').textContent = 'Add Recurring Cost';
    };

    // Bind edit buttons via data attribute
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-edit-recurring]');
        if (!btn) return;
        var data = JSON.parse(btn.dataset.editRecurring);
        document.getElementById('recEditId').value = data.recurring_id;
        document.getElementById('recType').value = data.cost_type;
        document.getElementById('recDescription').value = data.description;
        document.getElementById('recAmount').value = data.amount;
        document.getElementById('recFrequency').value = data.frequency;
        document.getElementById('recurringModalTitle').textContent = 'Edit Recurring Cost';
        var modal = new bootstrap.Modal(document.getElementById('recurringModal'));
        modal.show();
    });

    window.saveRecurring = function () {
        var editId = document.getElementById('recEditId').value;
        var fd = new FormData();
        fd.append('action', editId ? 'updateRecurringCost' : 'addRecurringCost');
        if (editId) fd.append('recurring_id', editId);
        fd.append('cost_type',   document.getElementById('recType').value);
        fd.append('description', document.getElementById('recDescription').value);
        fd.append('amount',      document.getElementById('recAmount').value);
        fd.append('frequency',   document.getElementById('recFrequency').value);

        fetch('../api/index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.error) { alert(j.error); return; }
                bootstrap.Modal.getInstance(document.getElementById('recurringModal')).hide();
                location.reload();
            });
    };

    window.toggleRecurring = function (id) {
        var fd = new FormData();
        fd.append('action', 'toggleRecurringCost');
        fd.append('recurring_id', id);
        fetch('../api/index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.error) { alert(j.error); return; }
                location.reload();
            });
    };

    window.deleteRecurring = function (id) {
        if (!confirm('Delete this recurring cost?')) return;
        var fd = new FormData();
        fd.append('action', 'deleteRecurringCost');
        fd.append('recurring_id', id);
        fetch('../api/index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.error) { alert(j.error); return; }
                location.reload();
            });
    };

    // ── Cost Report Tab ─────────────────────────────────────

    // Load report when tab is shown
    var reportTab = document.querySelector('[data-bs-target="#tabReport"]');
    var reportLoaded = false;
    reportTab.addEventListener('shown.bs.tab', function () {
        if (reportLoaded) return;
        reportLoaded = true;
        loadCostReport();
    });

    function loadCostReport() {
        var R = window.WNReports;
        var colors = R.getThemeColors();

        var fd = new FormData();
        fd.append('action', 'getCostReport');
        fd.append('months', 12);

        fetch('../api/index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.error) { console.error(j.error); return; }
                var d = j.results;
                var s = d.summary_30d || {};
                var rec = d.recurring_monthly || {};

                function fmt(v) { return '$' + (v || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); }

                document.getElementById('statCogs').textContent    = fmt(s.cogs ? s.cogs.total : 0);
                document.getElementById('statCac').textContent     = fmt(s.cac ? s.cac.total : 0);
                document.getElementById('statSupport').textContent = fmt(s.support ? s.support.total : 0);

                document.getElementById('statCogsRecurring').textContent    = '+ ' + fmt(rec.cogs) + '/mo recurring';
                document.getElementById('statCacRecurring').textContent     = '+ ' + fmt(rec.cac) + '/mo recurring';
                document.getElementById('statSupportRecurring').textContent = '+ ' + fmt(rec.support) + '/mo recurring';

                // Cost per user
                var totalEntries = (s.cogs ? s.cogs.total : 0) + (s.cac ? s.cac.total : 0) + (s.support ? s.support.total : 0);
                var users = d.total_active_users || 1;
                document.getElementById('statPerUser').textContent = fmt(totalEntries / users);
                document.getElementById('statUserCount').textContent = users.toLocaleString() + ' active users';

                // Monthly trend chart — reshape data
                var trend = d.monthly_trend || [];
                var months = {};
                trend.forEach(function (row) {
                    if (!months[row.month_val]) {
                        months[row.month_val] = { date_val: row.month_val, cogs: 0, cac: 0, support: 0 };
                    }
                    months[row.month_val][row.cost_type] = parseFloat(row.total_amount);
                });
                var trendData = Object.values(months).sort(function (a, b) { return a.date_val.localeCompare(b.date_val); });

                R.areaChart('#chartCostTrend', trendData, {
                    xKey: 'date_val',
                    area: true,
                    series: [
                        { key: 'cogs',    color: colors.info,    label: 'COGS' },
                        { key: 'cac',     color: colors.warning, label: 'CAC' },
                        { key: 'support', color: colors.primary, label: 'Support' },
                    ],
                });

                // By source bar chart
                var bySource = d.by_source || [];
                var sourceAgg = {};
                bySource.forEach(function (row) {
                    if (!sourceAgg[row.source_app]) sourceAgg[row.source_app] = 0;
                    sourceAgg[row.source_app] += parseFloat(row.total_amount);
                });
                var sourceData = Object.keys(sourceAgg).map(function (k) {
                    return { label: k, value: Math.round(sourceAgg[k] * 100) / 100 };
                }).sort(function (a, b) { return b.value - a.value; });

                R.barChart('#chartBySource', sourceData, {
                    width: 400,
                    height: 260,
                    color: colors.primary,
                });
            })
            .catch(function (err) {
                console.error('Cost report error:', err);
                document.getElementById('chartCostTrend').innerHTML = '<p class="text-danger">Failed to load cost report data.</p>';
            });
    }

    // ── Helpers ──────────────────────────────────────────────
    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

})();
</script>
