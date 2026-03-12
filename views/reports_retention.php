<?php
/**
 * views/reports_retention.php
 * User retention report — MAU trend + login recency buckets.
 */
$page_title = 'Retention Report';
?>

<!-- REGION: page-header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>User Retention</h3>
    <div class="btn-group" role="group" id="rangeSelector">
        <button type="button" class="btn btn-outline-primary btn-sm" data-range="90">90 days</button>
        <button type="button" class="btn btn-outline-primary btn-sm active" data-range="365">1 year</button>
        <button type="button" class="btn btn-outline-primary btn-sm" data-range="730">2 years</button>
    </div>
</div>

<!-- Active rate card + MAU chart -->
<div class="row mb-4">
    <div class="col-md-3 mb-3">
        <div class="card h-100">
            <div class="card-body text-center">
                <h6 class="card-title text-muted mb-2">30-Day Active Rate</h6>
                <h1 id="activeRate" class="mb-0 text-primary">—</h1>
            </div>
        </div>
    </div>
    <div class="col-md-9 mb-3">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">Monthly Active Users (MAU)</h6></div>
            <div class="card-body">
                <div id="chartMAU"></div>
            </div>
        </div>
    </div>
</div>

<!-- Recency buckets -->
<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0">Last Login Recency</h6></div>
    <div class="card-body">
        <div id="chartRecency"></div>
    </div>
</div>

<!-- D3.js + reports.js -->
<script src="https://cdn.jsdelivr.net/npm/d3@7/dist/d3.min.js"></script>
<script src="../assets/js/reports.js"></script>
<script>
(function () {
    var R = window.WNReports;
    var colors = R.getThemeColors();
    var currentRange = 365;

    function loadData(range) {
        currentRange = range;
        document.getElementById('chartMAU').innerHTML = '<p class="text-muted text-center py-4">Loading…</p>';

        R.fetch('retention', range).then(function (d) {
            // Active rate
            document.getElementById('activeRate').textContent = d.active_rate + '%';

            // MAU line chart
            R.areaChart('#chartMAU', d.mau, {
                xKey: 'month_val',
                yKey: 'active_users',
                color: colors.primary,
                area: true,
            });

            // Recency bar chart
            R.barChart('#chartRecency', d.recency_buckets, {
                width: 700,
                height: 280,
                colorMap: {
                    'Today':   colors.success,
                    '7 days':  '#20c997',
                    '30 days': colors.info,
                    '90 days': colors.warning,
                    '90d+':    colors.danger,
                    'Never':   colors.secondary,
                },
            });
        }).catch(function (err) {
            console.error('Retention report error:', err);
            document.getElementById('chartMAU').innerHTML = '<p class="text-danger">Failed to load data.</p>';
        });
    }

    // Range selector
    document.getElementById('rangeSelector').addEventListener('click', function (e) {
        var btn = e.target.closest('[data-range]');
        if (!btn) return;
        this.querySelectorAll('.btn').forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
        loadData(parseInt(btn.getAttribute('data-range')));
    });

    // Initial load
    loadData(365);
})();
</script>
