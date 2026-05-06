<?php
/**
 * views/analytics_overview.php
 * Top-line analytics: DAU/WAU/MAU, activation, retention curve, milestone
 * totals, signups vs active users chart.
 */
$page_title = 'Analytics — Overview';

$scope = get_visible_user_scope($_SESSION['user_id'] ?? 0);
if ($scope['type'] === 'none') {
    http_response_code(403);
    echo '<div class="alert alert-danger">You do not have access to analytics.</div>';
    return;
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-0">Analytics</h3>
        <small class="text-muted">Scope: <?= h($scope['label']) ?></small>
    </div>
    <span class="badge bg-secondary">Overview</span>
</div>

<div class="row mb-4" id="headlineMetrics">
    <div class="col-md-3 mb-3">
        <div class="card h-100"><div class="card-body text-center">
            <h6 class="card-title text-muted mb-2">DAU</h6>
            <h2 id="metricDAU" class="mb-0 text-primary">—</h2>
            <small class="text-muted">last 24h</small>
        </div></div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card h-100"><div class="card-body text-center">
            <h6 class="card-title text-muted mb-2">WAU</h6>
            <h2 id="metricWAU" class="mb-0 text-info">—</h2>
            <small class="text-muted">last 7 days</small>
        </div></div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card h-100"><div class="card-body text-center">
            <h6 class="card-title text-muted mb-2">MAU</h6>
            <h2 id="metricMAU" class="mb-0 text-success">—</h2>
            <small class="text-muted">last 30 days</small>
        </div></div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card h-100"><div class="card-body text-center">
            <h6 class="card-title text-muted mb-2">Activation Rate</h6>
            <h2 id="metricActivation" class="mb-0 text-warning">—</h2>
            <small class="text-muted" id="metricActivationDetail">—</small>
        </div></div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0">Signups vs Active Users (last 90 days)</h6></div>
    <div class="card-body"><div id="chartSignupsVsActive">Loading…</div></div>
</div>

<div class="row mb-4">
    <div class="col-md-6 mb-3">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">Retention Curve</h6></div>
            <div class="card-body">
                <table class="table table-sm mb-0" id="retentionTable">
                    <thead><tr><th>Day</th><th class="text-end">% Retained</th><th class="text-end">Active / Eligible</th></tr></thead>
                    <tbody><tr><td colspan="3" class="text-muted text-center">Loading…</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6 mb-3">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">Milestone Activity (key events)</h6></div>
            <div class="card-body">
                <table class="table table-sm mb-0" id="milestoneTable">
                    <thead><tr><th>Window</th><th class="text-end">Events</th></tr></thead>
                    <tbody><tr><td colspan="2" class="text-muted text-center">Loading…</td></tr></tbody>
                </table>
                <small class="text-muted d-block mt-2">Counted from <code>feature_metric_daily</code>. Child apps register milestone actions via <code>register_milestone_event()</code>.</small>
            </div>
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
        fd.append('page', 'overview');
        fetch('../api/index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.error) { throw new Error(j.error); }
                var d = j.results || {};
                renderHeadline(d.headline || {});
                renderRetention(d.retention || []);
                renderMilestones(d.milestone_counts || {});
                renderTrend(d.signups_trend || [], d.active_trend || []);
            })
            .catch(function (err) {
                document.getElementById('chartSignupsVsActive').innerHTML =
                    '<p class="text-danger">Failed to load: ' + (err && err.message ? err.message : 'unknown error') + '</p>';
            });
    }

    function renderHeadline(h) {
        document.getElementById('metricDAU').textContent = h.dau != null ? h.dau : '—';
        document.getElementById('metricWAU').textContent = h.wau != null ? h.wau : '—';
        document.getElementById('metricMAU').textContent = h.mau != null ? h.mau : '—';
        document.getElementById('metricActivation').textContent = (h.activation_rate != null ? h.activation_rate : 0) + '%';
        document.getElementById('metricActivationDetail').textContent =
            (h.activated || 0) + ' of ' + (h.signups_30plus || 0) + ' eligible';
    }

    function renderRetention(rows) {
        var tbody = document.querySelector('#retentionTable tbody');
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="3" class="text-muted text-center">No data.</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map(function (r) {
            var color = r.pct >= 50 ? 'text-success' : r.pct >= 20 ? 'text-warning' : 'text-danger';
            return '<tr><td>Day ' + r.day + '</td><td class="text-end ' + color + '"><strong>' + r.pct + '%</strong></td><td class="text-end text-muted">' + r.active + ' / ' + r.cohort + '</td></tr>';
        }).join('');
    }

    function renderMilestones(counts) {
        var tbody = document.querySelector('#milestoneTable tbody');
        var keys = Object.keys(counts);
        if (!keys.length) {
            tbody.innerHTML = '<tr><td colspan="2" class="text-muted text-center">No milestones registered yet.</td></tr>';
            return;
        }
        tbody.innerHTML = keys.map(function (k) {
            return '<tr><td>Last ' + k + ' days</td><td class="text-end"><strong>' + counts[k] + '</strong></td></tr>';
        }).join('');
    }

    function renderTrend(signups, active) {
        if (!signups.length && !active.length) {
            document.getElementById('chartSignupsVsActive').innerHTML = '<p class="text-muted text-center py-4">No activity in the last 90 days.</p>';
            return;
        }
        // Merge by date for a multi-line area chart
        var byDate = {};
        signups.forEach(function (r) { byDate[r.d] = byDate[r.d] || { d: r.d }; byDate[r.d].signups = +r.c; });
        active.forEach(function (r)  { byDate[r.d] = byDate[r.d] || { d: r.d }; byDate[r.d].active  = +r.c; });
        var merged = Object.keys(byDate).sort().map(function (k) {
            return { d: k, signups: byDate[k].signups || 0, active: byDate[k].active || 0 };
        });
        R.areaChart('#chartSignupsVsActive', merged, {
            xKey: 'd',
            yKey: 'active',
            color: colors.success,
            series: [
                { key: 'active',  color: colors.success, label: 'Active users' },
                { key: 'signups', color: colors.primary, label: 'Signups' },
            ],
            area: false,
        });
    }

    load();
})();
</script>
