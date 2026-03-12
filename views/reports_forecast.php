<?php
/**
 * views/reports_forecast.php
 * Growth Forecast — projects future user growth based on historical trends.
 */
$page_title = 'Growth Forecast';
?>

<!-- REGION: page-header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>Growth Forecast</h3>
    <div class="btn-group" role="group" id="rangeSelector">
        <button type="button" class="btn btn-outline-primary btn-sm" data-range="180">6 months</button>
        <button type="button" class="btn btn-outline-primary btn-sm active" data-range="365">1 year</button>
        <button type="button" class="btn btn-outline-primary btn-sm" data-range="730">2 years</button>
    </div>
</div>

<!-- KPI cards -->
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card h-100">
            <div class="card-body text-center">
                <h6 class="card-title text-muted mb-1">Avg Monthly Growth</h6>
                <h2 id="kpiGrowthRate" class="mb-0">—</h2>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card h-100">
            <div class="card-body text-center">
                <h6 class="card-title text-muted mb-1">Projected (12mo)</h6>
                <h2 id="kpiProjected12" class="mb-0">—</h2>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card h-100">
            <div class="card-body text-center">
                <h6 class="card-title text-muted mb-1">Est. Peak</h6>
                <h2 id="kpiPeak" class="mb-0">—</h2>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card h-100">
            <div class="card-body text-center">
                <h6 class="card-title text-muted mb-1">Trend</h6>
                <h2 id="kpiTrend" class="mb-0">—</h2>
            </div>
        </div>
    </div>
</div>

<!-- Growth curve chart -->
<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0">User Growth Curve</h6></div>
    <div class="card-body">
        <div id="chartForecast"></div>
    </div>
</div>

<!-- Net growth bar chart + forecast table -->
<div class="row mb-4">
    <div class="col-lg-6 mb-3">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">Monthly Net Growth</h6></div>
            <div class="card-body">
                <div id="chartNetGrowth"></div>
            </div>
        </div>
    </div>
    <div class="col-lg-6 mb-3">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">12-Month Projection</h6></div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0 table-sm">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th class="text-end">Projected Users</th>
                            <th class="text-end">Growth Rate</th>
                            <th class="text-end">Net Change</th>
                        </tr>
                    </thead>
                    <tbody id="forecastTable">
                        <tr><td colspan="4" class="text-center text-muted py-3">Loading…</td></tr>
                    </tbody>
                </table>
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

    function loadForecast(range) {
        document.getElementById('chartForecast').innerHTML = '<p class="text-muted text-center py-4">Loading…</p>';
        document.getElementById('chartNetGrowth').innerHTML = '<p class="text-muted text-center py-4">Loading…</p>';

        R.fetch('forecast', range).then(function (d) {
            // ── Build merged monthly data ──
            var signupMap = {};
            var cancelMap = {};

            (d.monthly_signups || []).forEach(function (r) {
                signupMap[r.month_val] = parseInt(r.signups) || 0;
            });
            (d.monthly_cancellations || []).forEach(function (r) {
                cancelMap[r.month_val] = parseInt(r.cancellations) || 0;
            });

            // Collect all months
            var allMonths = Object.keys(Object.assign({}, signupMap, cancelMap)).sort();

            // Build monthly net growth array
            var netGrowthData = [];
            allMonths.forEach(function (m) {
                var s = signupMap[m] || 0;
                var c = cancelMap[m] || 0;
                netGrowthData.push({ label: m, value: s - c, signups: s, cancellations: c });
            });

            // Build cumulative history from API data or compute from net
            var cumulativeMap = {};
            (d.cumulative_by_month || []).forEach(function (r) {
                cumulativeMap[r.month_val] = parseInt(r.cumulative_users) || 0;
            });

            var historicalData = [];
            allMonths.forEach(function (m) {
                if (cumulativeMap[m]) {
                    historicalData.push({ date: m, value: cumulativeMap[m] });
                }
            });

            // If no cumulative data from API, build from totals - net
            if (historicalData.length === 0 && d.totals) {
                var running = d.totals.total_users;
                // Work backwards from current total
                for (var i = allMonths.length - 1; i >= 0; i--) {
                    historicalData.unshift({ date: allMonths[i], value: running });
                    if (i > 0) {
                        var net = (signupMap[allMonths[i]] || 0) - (cancelMap[allMonths[i]] || 0);
                        running -= net;
                        if (running < 0) running = 0;
                    }
                }
            }

            // ── Compute growth rates ──
            var rates = [];
            for (var i = 1; i < historicalData.length; i++) {
                var prev = historicalData[i - 1].value;
                if (prev > 0) {
                    rates.push((historicalData[i].value - prev) / prev);
                }
            }

            // Weighted moving average (recent months count more)
            var avgRate = 0;
            if (rates.length > 0) {
                var totalWeight = 0;
                var weightedSum = 0;
                rates.forEach(function (r, idx) {
                    var w = idx + 1; // later months get higher weight
                    weightedSum += r * w;
                    totalWeight += w;
                });
                avgRate = weightedSum / totalWeight;
            }

            // ── Detect trend (accelerating/steady/decelerating) ──
            var trendLabel = '→ Steady';
            var trendClass = 'text-primary';
            if (rates.length >= 3) {
                var recentHalf = rates.slice(Math.floor(rates.length / 2));
                var earlyHalf = rates.slice(0, Math.floor(rates.length / 2));
                var avgRecent = recentHalf.reduce(function (a, b) { return a + b; }, 0) / recentHalf.length;
                var avgEarly = earlyHalf.reduce(function (a, b) { return a + b; }, 0) / earlyHalf.length;
                if (avgRecent > avgEarly * 1.1) {
                    trendLabel = '↑ Accelerating';
                    trendClass = 'text-success';
                } else if (avgRecent < avgEarly * 0.9) {
                    trendLabel = '↓ Decelerating';
                    trendClass = 'text-danger';
                }
            }

            // ── Project forward 12 months ──
            var lastHistorical = historicalData.length > 0 ? historicalData[historicalData.length - 1] : { date: new Date().toISOString().slice(0, 7), value: d.totals.total_users };
            var currentUsers = lastHistorical.value;

            // Avg monthly cancellation rate
            var totalCancellations = 0;
            var cancelMonths = 0;
            netGrowthData.forEach(function (n) {
                totalCancellations += n.cancellations;
                if (n.cancellations > 0) cancelMonths++;
            });
            var avgMonthlyCancelRate = (allMonths.length > 0 && currentUsers > 0)
                ? (totalCancellations / allMonths.length) / currentUsers
                : 0;

            var projectedData = [];
            var projTable = [];
            var runningProjected = currentUsers;
            var peakValue = currentUsers;
            var peakMonth = null;
            var lastDate = lastHistorical.date;

            for (var m = 1; m <= 12; m++) {
                // Next month string
                var parts = lastDate.split('-');
                var yr = parseInt(parts[0]);
                var mo = parseInt(parts[1]) + m;
                while (mo > 12) { mo -= 12; yr++; }
                var monthStr = yr + '-' + (mo < 10 ? '0' + mo : mo);

                var netChange = Math.round(runningProjected * avgRate);
                runningProjected = Math.max(0, runningProjected + netChange);

                projectedData.push({ date: monthStr, value: runningProjected });
                projTable.push({
                    month: monthStr,
                    users: runningProjected,
                    rate: avgRate,
                    netChange: netChange,
                });

                if (runningProjected > peakValue) {
                    peakValue = runningProjected;
                }
                if (netChange < 0 && peakMonth === null) {
                    peakMonth = monthStr;
                }
            }

            var projected12 = projectedData.length > 0 ? projectedData[projectedData.length - 1].value : currentUsers;

            // ── Update KPI cards ──
            var rateDisplay = (avgRate * 100).toFixed(1) + '%';
            document.getElementById('kpiGrowthRate').textContent = (avgRate >= 0 ? '+' : '') + rateDisplay;
            document.getElementById('kpiGrowthRate').className = 'mb-0 ' + (avgRate >= 0 ? 'text-success' : 'text-danger');

            document.getElementById('kpiProjected12').textContent = projected12.toLocaleString();

            if (peakMonth && avgRate < 0) {
                document.getElementById('kpiPeak').textContent = peakValue.toLocaleString();
                document.getElementById('kpiPeak').className = 'mb-0 text-warning';
            } else {
                document.getElementById('kpiPeak').textContent = 'No peak';
                document.getElementById('kpiPeak').className = 'mb-0 text-success';
            }

            var trendEl = document.getElementById('kpiTrend');
            trendEl.textContent = trendLabel;
            trendEl.className = 'mb-0 ' + trendClass;

            // ── Render forecast chart ──
            var peakOpt = null;
            if (peakMonth && avgRate < 0) {
                peakOpt = { value: peakValue, label: 'Est. Peak: ' + peakValue.toLocaleString() };
            }

            R.forecastChart('#chartForecast', historicalData, projectedData, {
                yKey: 'value',
                xKey: 'date',
                peakLine: peakOpt,
            });

            // ── Render net growth bar chart ──
            R.signedBarChart('#chartNetGrowth', netGrowthData, {
                height: 240,
            });

            // ── Populate forecast table ──
            var tbody = document.getElementById('forecastTable');
            if (projTable.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">Not enough data.</td></tr>';
                return;
            }
            var html = '';
            projTable.forEach(function (row) {
                var changeClass = row.netChange >= 0 ? 'text-success' : 'text-danger';
                var changePrefix = row.netChange >= 0 ? '+' : '';
                html += '<tr>';
                html += '<td>' + escH(row.month) + '</td>';
                html += '<td class="text-end">' + row.users.toLocaleString() + '</td>';
                html += '<td class="text-end">' + (row.rate * 100).toFixed(1) + '%</td>';
                html += '<td class="text-end ' + changeClass + '">' + changePrefix + row.netChange.toLocaleString() + '</td>';
                html += '</tr>';
            });
            tbody.innerHTML = html;

        }).catch(function (err) {
            console.error('Forecast error:', err);
            document.getElementById('chartForecast').innerHTML = '<p class="text-danger">Failed to load forecast data.</p>';
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
        loadForecast(parseInt(btn.getAttribute('data-range')));
    });

    // Initial load
    loadForecast(365);
})();
</script>
