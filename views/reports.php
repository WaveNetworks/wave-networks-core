<?php
/**
 * views/reports.php
 * Reports Overview — summary cards + trend charts.
 */
$page_title = 'Reports Overview';
?>

<!-- REGION: page-header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>Reports Overview</h3>
</div>

<!-- Summary cards (populated by JS) -->
<div class="row mb-4" id="summaryCards">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card h-100">
            <div class="card-body">
                <h6 class="card-title text-muted mb-1">Total Users</h6>
                <h2 id="statTotal" class="mb-0">—</h2>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card h-100">
            <div class="card-body">
                <h6 class="card-title text-muted mb-1">Active (30d)</h6>
                <h2 id="statActive" class="mb-0">—</h2>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card h-100">
            <div class="card-body">
                <h6 class="card-title text-muted mb-1">New (30d)</h6>
                <h2 id="statNew" class="mb-0">—</h2>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card h-100">
            <div class="card-body">
                <h6 class="card-title text-muted mb-1">Deactivated (30d)</h6>
                <h2 id="statDeactivated" class="mb-0">—</h2>
            </div>
        </div>
    </div>
</div>

<!-- Signups trend chart -->
<div class="row mb-4">
    <div class="col-lg-8 mb-3">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">New Signups — Last 90 Days</h6></div>
            <div class="card-body">
                <div id="chartSignupsTrend"></div>
            </div>
        </div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">User Status</h6></div>
            <div class="card-body">
                <div id="chartStatusBreakdown"></div>
            </div>
        </div>
    </div>
</div>

<!-- D3.js + reports.js -->
<script src="https://cdn.jsdelivr.net/npm/d3@7/dist/d3.min.js"></script>
<script src="../assets/js/reports.js"></script>
<script>
(function () {
    var R = window.WNReports;
    var colors = R.getThemeColors();

    R.fetch('overview', 90).then(function (d) {
        // Summary cards
        document.getElementById('statTotal').textContent = d.summary.total.toLocaleString();
        document.getElementById('statActive').textContent = d.summary.active_30d.toLocaleString();
        document.getElementById('statNew').textContent = d.summary.new_30d.toLocaleString();
        document.getElementById('statDeactivated').textContent = d.summary.deactivated_30d.toLocaleString();

        // Signups trend
        R.areaChart('#chartSignupsTrend', d.signups_trend, {
            yKey: 'new_users',
            color: colors.primary,
        });

        // Status breakdown
        R.barChart('#chartStatusBreakdown', d.status_breakdown, {
            width: 400,
            height: 260,
            colorMap: {
                'Active':      colors.success,
                'Inactive':    colors.danger,
                'Unconfirmed': colors.warning,
            },
        });
    }).catch(function (err) {
        console.error('Reports error:', err);
        document.getElementById('chartSignupsTrend').innerHTML = '<p class="text-danger">Failed to load report data.</p>';
    });
})();
</script>
