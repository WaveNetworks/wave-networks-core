<?php
/**
 * views/analytics_cohorts.php
 * Weekly signup cohort retention heatmap + N-day retention curve.
 * Screenshot-friendly for direct-selling pitches.
 */
$page_title = 'Analytics — Cohorts';

$scope = get_visible_user_scope($_SESSION['user_id'] ?? 0);
if ($scope['type'] === 'none') {
    http_response_code(403);
    echo '<div class="alert alert-danger">You do not have access to analytics.</div>';
    return;
}
?>

<style>
.cohort-heatmap { border-collapse: separate; border-spacing: 2px; font-size: 0.85rem; }
.cohort-heatmap th, .cohort-heatmap td { padding: 6px 8px; text-align: center; min-width: 56px; }
.cohort-heatmap td.cohort-cell { color: #fff; font-weight: 500; border-radius: 3px; }
.cohort-cell-empty { background: #e9ecef; color: #6c757d !important; font-weight: 400; }
.cohort-cell-0   { background: #adb5bd; color: #212529 !important; }
.cohort-cell-10  { background: #fcc; color: #212529 !important; }
.cohort-cell-25  { background: #f88; }
.cohort-cell-40  { background: #ff8b3d; }
.cohort-cell-55  { background: #f5b041; color: #212529 !important; }
.cohort-cell-70  { background: #66bb6a; }
.cohort-cell-85  { background: #2e7d32; }
.cohort-cell-100 { background: #1b5e20; }
[data-bs-theme="dark"] .cohort-cell-empty { background: #343a40; color: #adb5bd !important; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-0">Cohorts</h3>
        <small class="text-muted">Scope: <?= h($scope['label']) ?> &middot; weekly signup cohorts &middot; last 12 weeks</small>
    </div>
    <div class="d-flex align-items-center gap-2">
        <label for="segmentFilter" class="form-label mb-0 small text-muted">Segment</label>
        <select id="segmentFilter" class="form-select form-select-sm" style="min-width: 200px;">
            <option value="">All users</option>
        </select>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0">Weekly Cohort Retention</h6></div>
    <div class="card-body">
        <div id="cohortHeatmap" class="text-muted text-center py-4">Loading…</div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0">N-Day Retention Curve (last 90 days of signups)</h6></div>
    <div class="card-body"><div id="chartRetentionCurve">Loading…</div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/d3@7/dist/d3.min.js"></script>
<script src="../assets/js/reports.js"></script>
<script>
(function () {
    var R = window.WNReports;
    var colors = R.getThemeColors();

    function load(segment) {
        var fd = new FormData();
        fd.append('action', 'getAnalyticsData');
        fd.append('page', 'cohorts');
        if (segment) fd.append('segment', segment);
        fetch('../api/index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.error) { throw new Error(j.error); }
                var d = j.results || {};
                renderHeatmap(d.matrix || []);
                renderSegments(d.segments || [], d.active_segment || '');
                renderCurve(d.retention_curve || []);
            })
            .catch(function (err) {
                document.getElementById('cohortHeatmap').innerHTML =
                    '<p class="text-danger">Failed to load: ' + (err.message || '') + '</p>';
            });
    }

    function bucketClass(pct) {
        if (pct >= 85) return 'cohort-cell-100';
        if (pct >= 70) return 'cohort-cell-85';
        if (pct >= 55) return 'cohort-cell-70';
        if (pct >= 40) return 'cohort-cell-55';
        if (pct >= 25) return 'cohort-cell-40';
        if (pct >= 10) return 'cohort-cell-25';
        if (pct > 0)   return 'cohort-cell-10';
        return 'cohort-cell-0';
    }

    function weekLabel(yw) {
        // YEARWEEK mode 1: YYYYWW
        var s = String(yw);
        if (s.length < 6) return s;
        var year = s.slice(0, 4), week = s.slice(4);
        return 'W' + week + " '" + year.slice(2);
    }

    function renderHeatmap(matrix) {
        var el = document.getElementById('cohortHeatmap');
        if (!matrix.length) { el.innerHTML = '<p class="text-muted text-center">No cohorts in this window.</p>'; return; }
        var maxCols = 12;
        var head = '<tr><th class="text-start">Signup week</th><th class="text-end">Size</th>';
        for (var i = 0; i < maxCols; i++) { head += '<th>W' + i + '</th>'; }
        head += '</tr>';
        var body = matrix.map(function (row) {
            var cells = '<tr><td class="text-start"><strong>' + weekLabel(row.signup_week) + '</strong></td><td class="text-end text-muted">' + row.size + '</td>';
            row.cells.forEach(function (c) {
                if (row.size === 0 || c.cohort === 0) { cells += '<td class="cohort-cell cohort-cell-empty">—</td>'; }
                else { cells += '<td class="cohort-cell ' + bucketClass(c.pct) + '" title="' + c.active + ' of ' + row.size + '">' + c.pct + '%</td>'; }
            });
            cells += '</tr>';
            return cells;
        }).join('');
        el.innerHTML = '<div class="table-responsive"><table class="cohort-heatmap mx-auto">' +
            '<thead>' + head + '</thead><tbody>' + body + '</tbody></table></div>';
    }

    function renderSegments(segments, active) {
        var sel = document.getElementById('segmentFilter');
        // Keep base option, append registered segments
        var base = '<option value="">All users</option>';
        var opts = segments.map(function (s) {
            var selAttr = s.slug === active ? ' selected' : '';
            return '<option value="' + s.slug + '"' + selAttr + '>' + escapeHtml(s.label) + '</option>';
        }).join('');
        sel.innerHTML = base + opts;
    }

    function renderCurve(rows) {
        if (!rows.length) {
            document.getElementById('chartRetentionCurve').innerHTML = '<p class="text-muted text-center py-4">No data.</p>';
            return;
        }
        var data = rows.map(function (r) { return { d: 'Day ' + r.day, value: +r.pct, day_n: +r.day }; });
        R.barChart('#chartRetentionCurve', data.map(function (r) { return { label: r.d, value: r.value }; }), {
            width: 700, height: 260, color: colors.primary,
        });
    }

    function escapeHtml(s) { return String(s).replace(/[&<>"']/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }

    document.getElementById('segmentFilter').addEventListener('change', function (e) {
        load(e.target.value);
    });

    load('');
})();
</script>
