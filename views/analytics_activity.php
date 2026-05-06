<?php
/**
 * views/analytics_activity.php
 * Operational activity view: login frequency, time-since-last-login, churn-risk
 * table, anonymous device funnel, session-length distribution.
 */
$page_title = 'Analytics — Activity';

$scope = get_visible_user_scope($_SESSION['user_id'] ?? 0);
if ($scope['type'] === 'none') {
    http_response_code(403);
    echo '<div class="alert alert-danger">You do not have access to analytics.</div>';
    return;
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-0">Activity</h3>
        <small class="text-muted">Scope: <?= h($scope['label']) ?></small>
    </div>
    <span class="badge bg-secondary">Activity</span>
</div>

<div class="row mb-4">
    <div class="col-md-6 mb-3">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">Login Frequency (last 30 days)</h6></div>
            <div class="card-body"><div id="chartLoginFreq">Loading…</div></div>
        </div>
    </div>
    <div class="col-md-6 mb-3">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">Time Since Last Login</h6></div>
            <div class="card-body"><div id="chartTimeSince">Loading…</div></div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0">Churn-Risk Users (no login in 14+ days)</h6>
        <small class="text-muted" id="churnCount">—</small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" id="churnTable">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th class="text-end">Last login</th>
                        <th class="text-end">Days idle</th>
                        <th class="text-end">Signed up</th>
                    </tr>
                </thead>
                <tbody><tr><td colspan="5" class="text-muted text-center py-4">Loading…</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-6 mb-3">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">Anonymous Device Funnel (last 7 days)</h6></div>
            <div class="card-body"><div id="chartFunnel">Loading…</div></div>
        </div>
    </div>
    <div class="col-md-6 mb-3">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">Session Length Distribution</h6></div>
            <div class="card-body"><div id="chartSession">Loading…</div></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/d3@7/dist/d3.min.js"></script>
<script src="../assets/js/reports.js"></script>
<script>
(function () {
    var R = window.WNReports;
    var colors = R.getThemeColors();

    function load() {
        var fd = new FormData();
        fd.append('action', 'getAnalyticsData');
        fd.append('page', 'activity');
        fetch('../api/index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.error) { throw new Error(j.error); }
                var d = j.results || {};
                R.barChart('#chartLoginFreq', d.login_frequency || [], { width: 700, height: 260, colorMap: { '0': colors.danger, '1-3': colors.warning, '4-10': colors.info, '11-30': colors.primary, '30+': colors.success } });
                R.barChart('#chartTimeSince', d.time_since_last_login || [], { width: 700, height: 260, colorMap: { 'Today': colors.success, '1-7 days': colors.info, '8-30 days': colors.primary, '31-90 days': colors.warning, '90+ days': colors.danger, 'Never': colors.secondary } });
                R.barChart('#chartFunnel', (d.device_funnel || []).map(function (r) { return { label: r.page || '(none)', value: +r.c }; }), { width: 700, height: 260, color: colors.primary });
                R.barChart('#chartSession', d.session_length || [], { width: 700, height: 260, color: colors.info });
                renderChurn(d.churn_risk || []);
            })
            .catch(function (err) {
                document.querySelector('#churnTable tbody').innerHTML =
                    '<tr><td colspan="5" class="text-danger text-center">Failed to load: ' + (err.message || '') + '</td></tr>';
            });
    }

    function renderChurn(rows) {
        document.getElementById('churnCount').textContent = rows.length + ' users';
        var tbody = document.querySelector('#churnTable tbody');
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-muted text-center py-4">No churn-risk users in scope.</td></tr>';
            return;
        }
        var now = Date.now();
        tbody.innerHTML = rows.map(function (r) {
            var lastLogin = r.last_login || '';
            var idle = '—';
            if (lastLogin) {
                idle = Math.max(0, Math.floor((now - new Date(lastLogin.replace(' ', 'T') + 'Z').getTime()) / 86400000)) + 'd';
            } else {
                idle = '<span class="text-danger">never</span>';
            }
            var nameCell = '';
            if (r.opted_out) {
                nameCell = '<em class="text-muted">— opted out —</em>';
            } else {
                nameCell = '<a href="index.php?page=user_edit&user_id=' + r.user_id + '">' + escapeHtml((r.first_name || '') + ' ' + (r.last_name || '')) + '</a>';
            }
            return '<tr>' +
                '<td>' + nameCell + '</td>' +
                '<td>' + escapeHtml(r.email || '') + '</td>' +
                '<td class="text-end">' + escapeHtml(lastLogin || 'never') + '</td>' +
                '<td class="text-end">' + idle + '</td>' +
                '<td class="text-end">' + escapeHtml((r.created_date || '').slice(0, 10)) + '</td>' +
                '</tr>';
        }).join('');
    }

    function escapeHtml(s) { return String(s).replace(/[&<>"']/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }

    load();
})();
</script>
