<?php
/**
 * views/reports_acquisition.php
 * User acquisition report — daily signups with confirmed/unconfirmed breakdown.
 */
$page_title = 'Acquisition Report';
?>

<!-- REGION: page-header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>User Acquisition</h3>
    <div class="btn-group" role="group" id="rangeSelector">
        <button type="button" class="btn btn-outline-primary btn-sm" data-range="30">30 days</button>
        <button type="button" class="btn btn-outline-primary btn-sm active" data-range="90">90 days</button>
        <button type="button" class="btn btn-outline-primary btn-sm" data-range="365">1 year</button>
    </div>
</div>

<!-- Chart -->
<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0">Daily New Signups</h6></div>
    <div class="card-body">
        <div id="chartAcquisition"></div>
    </div>
</div>

<!-- Monthly summary table -->
<div class="card">
    <div class="card-header"><h6 class="mb-0">Monthly Summary</h6></div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Total Signups</th>
                    <th>Confirmed</th>
                    <th>Confirmation Rate</th>
                </tr>
            </thead>
            <tbody id="monthlyTable">
                <tr><td colspan="4" class="text-center text-muted py-3">Loading…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- D3.js + reports.js -->
<script src="https://cdn.jsdelivr.net/npm/d3@7/dist/d3.min.js"></script>
<script src="../assets/js/reports.js"></script>
<script>
(function () {
    var R = window.WNReports;
    var colors = R.getThemeColors();
    var currentRange = 90;

    function loadData(range) {
        currentRange = range;
        document.getElementById('chartAcquisition').innerHTML = '<p class="text-muted text-center py-4">Loading…</p>';

        R.fetch('acquisition', range).then(function (d) {
            // Area chart with two series
            R.areaChart('#chartAcquisition', d.rows, {
                series: [
                    { key: 'confirmed',   color: colors.success, label: 'Confirmed' },
                    { key: 'unconfirmed', color: colors.warning, label: 'Unconfirmed' },
                ],
            });

            // Monthly table
            var tbody = document.getElementById('monthlyTable');
            if (!d.monthly || d.monthly.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">No data for this period.</td></tr>';
                return;
            }
            var html = '';
            d.monthly.forEach(function (m) {
                var rate = m.total > 0 ? Math.round((m.confirmed / m.total) * 100) : 0;
                html += '<tr>';
                html += '<td>' + escH(m.month_val) + '</td>';
                html += '<td>' + parseInt(m.total).toLocaleString() + '</td>';
                html += '<td>' + parseInt(m.confirmed).toLocaleString() + '</td>';
                html += '<td>' + rate + '%</td>';
                html += '</tr>';
            });
            tbody.innerHTML = html;
        }).catch(function (err) {
            console.error('Acquisition report error:', err);
            document.getElementById('chartAcquisition').innerHTML = '<p class="text-danger">Failed to load data.</p>';
        });
    }

    function escH(s) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(s));
        return div.innerHTML;
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
    loadData(90);
})();
</script>
