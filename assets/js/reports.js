/**
 * reports.js
 * D3.js chart rendering functions for the Reports section.
 * Requires D3 v7 loaded before this script.
 */
(function () {
    'use strict';

    // ── Helpers ──────────────────────────────────────────────

    function getThemeColors() {
        var style = getComputedStyle(document.documentElement);
        var isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        return {
            text:       isDark ? '#dee2e6' : '#212529',
            muted:      isDark ? '#6c757d' : '#adb5bd',
            gridLine:   isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.08)',
            primary:    '#0d6efd',
            success:    '#198754',
            danger:     '#dc3545',
            warning:    '#ffc107',
            info:       '#0dcaf0',
            secondary:  '#6c757d',
            bg:         isDark ? '#212529' : '#ffffff',
        };
    }

    function fetchReportData(report, range) {
        var formData = new FormData();
        formData.append('action', 'getReportData');
        formData.append('report', report);
        formData.append('range', range);
        return fetch('../api/index.php', { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.error) { throw new Error(j.error); }
                return j.results;
            });
    }

    // ── Area / Line Chart ───────────────────────────────────

    function renderAreaChart(selector, data, options) {
        var container = document.querySelector(selector);
        if (!container || !data || data.length === 0) {
            if (container) container.innerHTML = '<p class="text-muted text-center py-4">No data available for this period.</p>';
            return;
        }

        var colors = getThemeColors();
        var opt = Object.assign({
            width: 700, height: 300,
            marginTop: 20, marginRight: 20, marginBottom: 40, marginLeft: 50,
            xKey: 'date_val', yKey: 'value', color: colors.primary,
            series: null, // array of { key, color, label } for multi-line
            area: true,
            yLabel: '',
        }, options || {});

        container.innerHTML = '';

        var svg = d3.select(selector).append('svg')
            .attr('viewBox', '0 0 ' + opt.width + ' ' + opt.height)
            .attr('preserveAspectRatio', 'xMidYMid meet')
            .style('width', '100%')
            .style('height', 'auto');

        var w = opt.width - opt.marginLeft - opt.marginRight;
        var h = opt.height - opt.marginTop - opt.marginBottom;

        var g = svg.append('g')
            .attr('transform', 'translate(' + opt.marginLeft + ',' + opt.marginTop + ')');

        // Parse dates
        var parseDate = d3.timeParse('%Y-%m-%d');
        var parseMonth = d3.timeParse('%Y-%m');
        data.forEach(function (d) {
            if (typeof d[opt.xKey] === 'string') {
                d._date = parseDate(d[opt.xKey]) || parseMonth(d[opt.xKey]) || new Date(d[opt.xKey]);
            }
        });

        var x = d3.scaleTime()
            .domain(d3.extent(data, function (d) { return d._date; }))
            .range([0, w]);

        var seriesList = opt.series || [{ key: opt.yKey, color: opt.color, label: opt.yLabel }];

        var yMax = d3.max(data, function (d) {
            return d3.max(seriesList, function (s) { return +d[s.key] || 0; });
        });

        var y = d3.scaleLinear()
            .domain([0, Math.max(yMax, 1)])
            .nice()
            .range([h, 0]);

        // Grid lines
        g.append('g')
            .attr('class', 'grid')
            .call(d3.axisLeft(y).tickSize(-w).tickFormat(''))
            .selectAll('line').style('stroke', colors.gridLine);
        g.selectAll('.grid .domain').remove();

        // X axis
        g.append('g')
            .attr('transform', 'translate(0,' + h + ')')
            .call(d3.axisBottom(x).ticks(Math.min(data.length, 8)).tickFormat(d3.timeFormat('%b %d')))
            .selectAll('text').style('fill', colors.text).style('font-size', '11px');

        // Y axis
        g.append('g')
            .call(d3.axisLeft(y).ticks(5))
            .selectAll('text').style('fill', colors.text).style('font-size', '11px');

        g.selectAll('.domain').style('stroke', colors.muted);
        g.selectAll('.tick line').style('stroke', colors.muted);

        // Draw each series
        seriesList.forEach(function (s) {
            if (opt.area) {
                var area = d3.area()
                    .x(function (d) { return x(d._date); })
                    .y0(h)
                    .y1(function (d) { return y(+d[s.key] || 0); })
                    .curve(d3.curveMonotoneX);

                g.append('path')
                    .datum(data)
                    .attr('fill', s.color)
                    .attr('fill-opacity', 0.15)
                    .attr('d', area);
            }

            var line = d3.line()
                .x(function (d) { return x(d._date); })
                .y(function (d) { return y(+d[s.key] || 0); })
                .curve(d3.curveMonotoneX);

            g.append('path')
                .datum(data)
                .attr('fill', 'none')
                .attr('stroke', s.color)
                .attr('stroke-width', 2)
                .attr('d', line);

            // Dots
            g.selectAll('.dot-' + s.key)
                .data(data)
                .enter().append('circle')
                .attr('cx', function (d) { return x(d._date); })
                .attr('cy', function (d) { return y(+d[s.key] || 0); })
                .attr('r', data.length > 60 ? 1.5 : 3)
                .attr('fill', s.color);
        });

        // Legend (if multiple series)
        if (seriesList.length > 1) {
            var legend = svg.append('g')
                .attr('transform', 'translate(' + (opt.marginLeft + 10) + ',' + (opt.height - 10) + ')');

            seriesList.forEach(function (s, i) {
                var lg = legend.append('g')
                    .attr('transform', 'translate(' + (i * 120) + ', 0)');
                lg.append('rect').attr('width', 12).attr('height', 3).attr('y', -2).attr('fill', s.color);
                lg.append('text').attr('x', 16).attr('y', 3).text(s.label || s.key)
                    .style('font-size', '11px').style('fill', colors.text);
            });
        }
    }

    // ── Bar Chart ────────────────────────────────────────────

    function renderBarChart(selector, data, options) {
        var container = document.querySelector(selector);
        if (!container || !data || data.length === 0) {
            if (container) container.innerHTML = '<p class="text-muted text-center py-4">No data available.</p>';
            return;
        }

        var colors = getThemeColors();
        var opt = Object.assign({
            width: 500, height: 280,
            marginTop: 20, marginRight: 20, marginBottom: 40, marginLeft: 50,
            labelKey: 'label', valueKey: 'value',
            color: colors.primary,
            colorMap: null, // { label: color }
        }, options || {});

        container.innerHTML = '';

        var svg = d3.select(selector).append('svg')
            .attr('viewBox', '0 0 ' + opt.width + ' ' + opt.height)
            .attr('preserveAspectRatio', 'xMidYMid meet')
            .style('width', '100%')
            .style('height', 'auto');

        var w = opt.width - opt.marginLeft - opt.marginRight;
        var h = opt.height - opt.marginTop - opt.marginBottom;

        var g = svg.append('g')
            .attr('transform', 'translate(' + opt.marginLeft + ',' + opt.marginTop + ')');

        var x = d3.scaleBand()
            .domain(data.map(function (d) { return d[opt.labelKey]; }))
            .range([0, w])
            .padding(0.3);

        var y = d3.scaleLinear()
            .domain([0, d3.max(data, function (d) { return +d[opt.valueKey]; }) || 1])
            .nice()
            .range([h, 0]);

        // Grid
        g.append('g')
            .call(d3.axisLeft(y).tickSize(-w).tickFormat(''))
            .selectAll('line').style('stroke', colors.gridLine);
        g.selectAll('.grid .domain').remove();

        // Bars
        g.selectAll('.bar')
            .data(data)
            .enter().append('rect')
            .attr('class', 'bar')
            .attr('x', function (d) { return x(d[opt.labelKey]); })
            .attr('y', function (d) { return y(+d[opt.valueKey]); })
            .attr('width', x.bandwidth())
            .attr('height', function (d) { return h - y(+d[opt.valueKey]); })
            .attr('rx', 3)
            .attr('fill', function (d) {
                if (opt.colorMap && opt.colorMap[d[opt.labelKey]]) return opt.colorMap[d[opt.labelKey]];
                return opt.color;
            });

        // Bar value labels
        g.selectAll('.bar-label')
            .data(data)
            .enter().append('text')
            .attr('x', function (d) { return x(d[opt.labelKey]) + x.bandwidth() / 2; })
            .attr('y', function (d) { return y(+d[opt.valueKey]) - 5; })
            .attr('text-anchor', 'middle')
            .style('font-size', '11px')
            .style('fill', colors.text)
            .text(function (d) { return +d[opt.valueKey]; });

        // X axis
        g.append('g')
            .attr('transform', 'translate(0,' + h + ')')
            .call(d3.axisBottom(x))
            .selectAll('text').style('fill', colors.text).style('font-size', '11px');

        // Y axis
        g.append('g')
            .call(d3.axisLeft(y).ticks(5))
            .selectAll('text').style('fill', colors.text).style('font-size', '11px');

        g.selectAll('.domain').style('stroke', colors.muted);
        g.selectAll('.tick line').style('stroke', colors.muted);
    }

    // ── Forecast Chart (historical solid + projected dashed) ─

    function renderForecastChart(selector, historicalData, projectedData, options) {
        var container = document.querySelector(selector);
        if (!container) return;
        if ((!historicalData || historicalData.length === 0) && (!projectedData || projectedData.length === 0)) {
            container.innerHTML = '<p class="text-muted text-center py-4">Not enough data to generate forecast.</p>';
            return;
        }

        var colors = getThemeColors();
        var opt = Object.assign({
            width: 700, height: 320,
            marginTop: 20, marginRight: 20, marginBottom: 40, marginLeft: 60,
            yKey: 'value',
            xKey: 'date',
            histColor: colors.primary,
            projColor: colors.info,
            peakLine: null, // { value: N, label: 'Est. Peak' }
        }, options || {});

        container.innerHTML = '';

        var svg = d3.select(selector).append('svg')
            .attr('viewBox', '0 0 ' + opt.width + ' ' + opt.height)
            .attr('preserveAspectRatio', 'xMidYMid meet')
            .style('width', '100%')
            .style('height', 'auto');

        var w = opt.width - opt.marginLeft - opt.marginRight;
        var h = opt.height - opt.marginTop - opt.marginBottom;

        var g = svg.append('g')
            .attr('transform', 'translate(' + opt.marginLeft + ',' + opt.marginTop + ')');

        // Parse dates
        var parseMonth = d3.timeParse('%Y-%m');
        var allData = (historicalData || []).concat(projectedData || []);
        allData.forEach(function (d) {
            if (typeof d[opt.xKey] === 'string') {
                d._date = parseMonth(d[opt.xKey]) || new Date(d[opt.xKey]);
            }
        });
        (historicalData || []).forEach(function (d) {
            if (typeof d[opt.xKey] === 'string') {
                d._date = parseMonth(d[opt.xKey]) || new Date(d[opt.xKey]);
            }
        });
        (projectedData || []).forEach(function (d) {
            if (typeof d[opt.xKey] === 'string') {
                d._date = parseMonth(d[opt.xKey]) || new Date(d[opt.xKey]);
            }
        });

        var x = d3.scaleTime()
            .domain(d3.extent(allData, function (d) { return d._date; }))
            .range([0, w]);

        var yMax = d3.max(allData, function (d) { return +d[opt.yKey] || 0; });
        if (opt.peakLine && opt.peakLine.value > yMax) yMax = opt.peakLine.value;

        var y = d3.scaleLinear()
            .domain([0, Math.max(yMax, 1)])
            .nice()
            .range([h, 0]);

        // Grid
        g.append('g')
            .attr('class', 'grid')
            .call(d3.axisLeft(y).tickSize(-w).tickFormat(''))
            .selectAll('line').style('stroke', colors.gridLine);
        g.selectAll('.grid .domain').remove();

        // Axes
        g.append('g')
            .attr('transform', 'translate(0,' + h + ')')
            .call(d3.axisBottom(x).ticks(Math.min(allData.length, 10)).tickFormat(d3.timeFormat('%b %Y')))
            .selectAll('text').style('fill', colors.text).style('font-size', '10px')
            .attr('transform', 'rotate(-30)').attr('text-anchor', 'end');

        g.append('g')
            .call(d3.axisLeft(y).ticks(6))
            .selectAll('text').style('fill', colors.text).style('font-size', '11px');

        g.selectAll('.domain').style('stroke', colors.muted);
        g.selectAll('.tick line').style('stroke', colors.muted);

        // Historical area + line (solid)
        if (historicalData && historicalData.length > 0) {
            var histArea = d3.area()
                .x(function (d) { return x(d._date); })
                .y0(h)
                .y1(function (d) { return y(+d[opt.yKey] || 0); })
                .curve(d3.curveMonotoneX);

            g.append('path').datum(historicalData)
                .attr('fill', opt.histColor).attr('fill-opacity', 0.15).attr('d', histArea);

            var histLine = d3.line()
                .x(function (d) { return x(d._date); })
                .y(function (d) { return y(+d[opt.yKey] || 0); })
                .curve(d3.curveMonotoneX);

            g.append('path').datum(historicalData)
                .attr('fill', 'none').attr('stroke', opt.histColor)
                .attr('stroke-width', 2.5).attr('d', histLine);

            // Dots
            g.selectAll('.dot-hist')
                .data(historicalData).enter().append('circle')
                .attr('cx', function (d) { return x(d._date); })
                .attr('cy', function (d) { return y(+d[opt.yKey] || 0); })
                .attr('r', 3).attr('fill', opt.histColor);
        }

        // Projected area + line (dashed)
        if (projectedData && projectedData.length > 0) {
            // Bridge: connect last historical point to first projected
            var bridgeData = [];
            if (historicalData && historicalData.length > 0) {
                bridgeData = [historicalData[historicalData.length - 1]].concat(projectedData);
            } else {
                bridgeData = projectedData;
            }

            var projArea = d3.area()
                .x(function (d) { return x(d._date); })
                .y0(h)
                .y1(function (d) { return y(+d[opt.yKey] || 0); })
                .curve(d3.curveMonotoneX);

            g.append('path').datum(bridgeData)
                .attr('fill', opt.projColor).attr('fill-opacity', 0.08).attr('d', projArea);

            var projLine = d3.line()
                .x(function (d) { return x(d._date); })
                .y(function (d) { return y(+d[opt.yKey] || 0); })
                .curve(d3.curveMonotoneX);

            g.append('path').datum(bridgeData)
                .attr('fill', 'none').attr('stroke', opt.projColor)
                .attr('stroke-width', 2).attr('stroke-dasharray', '6,4').attr('d', projLine);

            // Dots
            g.selectAll('.dot-proj')
                .data(projectedData).enter().append('circle')
                .attr('cx', function (d) { return x(d._date); })
                .attr('cy', function (d) { return y(+d[opt.yKey] || 0); })
                .attr('r', 3).attr('fill', opt.projColor).attr('stroke', '#fff').attr('stroke-width', 1);
        }

        // Peak annotation line
        if (opt.peakLine && opt.peakLine.value > 0) {
            var peakY = y(opt.peakLine.value);
            g.append('line')
                .attr('x1', 0).attr('x2', w)
                .attr('y1', peakY).attr('y2', peakY)
                .attr('stroke', colors.danger).attr('stroke-width', 1)
                .attr('stroke-dasharray', '4,3').attr('opacity', 0.7);
            g.append('text')
                .attr('x', w - 5).attr('y', peakY - 6)
                .attr('text-anchor', 'end')
                .style('font-size', '10px').style('fill', colors.danger)
                .text(opt.peakLine.label || 'Est. Peak');
        }

        // Legend
        var legend = svg.append('g')
            .attr('transform', 'translate(' + (opt.marginLeft + 10) + ',' + (opt.height - 8) + ')');

        var items = [
            { color: opt.histColor, label: 'Historical', dash: false },
            { color: opt.projColor, label: 'Projected', dash: true },
        ];
        items.forEach(function (item, i) {
            var lg = legend.append('g').attr('transform', 'translate(' + (i * 120) + ', 0)');
            if (item.dash) {
                lg.append('line').attr('x1', 0).attr('x2', 12).attr('y1', 0).attr('y2', 0)
                    .attr('stroke', item.color).attr('stroke-width', 2).attr('stroke-dasharray', '4,2');
            } else {
                lg.append('rect').attr('width', 12).attr('height', 3).attr('y', -2).attr('fill', item.color);
            }
            lg.append('text').attr('x', 16).attr('y', 3).text(item.label)
                .style('font-size', '11px').style('fill', colors.text);
        });
    }

    // ── Signed Bar Chart (green positive, red negative) ─────

    function renderSignedBarChart(selector, data, options) {
        var container = document.querySelector(selector);
        if (!container || !data || data.length === 0) {
            if (container) container.innerHTML = '<p class="text-muted text-center py-4">No data available.</p>';
            return;
        }

        var colors = getThemeColors();
        var opt = Object.assign({
            width: 700, height: 260,
            marginTop: 20, marginRight: 20, marginBottom: 50, marginLeft: 50,
            labelKey: 'label', valueKey: 'value',
            positiveColor: colors.success,
            negativeColor: colors.danger,
        }, options || {});

        container.innerHTML = '';

        var svg = d3.select(selector).append('svg')
            .attr('viewBox', '0 0 ' + opt.width + ' ' + opt.height)
            .attr('preserveAspectRatio', 'xMidYMid meet')
            .style('width', '100%').style('height', 'auto');

        var w = opt.width - opt.marginLeft - opt.marginRight;
        var h = opt.height - opt.marginTop - opt.marginBottom;

        var g = svg.append('g')
            .attr('transform', 'translate(' + opt.marginLeft + ',' + opt.marginTop + ')');

        var x = d3.scaleBand()
            .domain(data.map(function (d) { return d[opt.labelKey]; }))
            .range([0, w]).padding(0.3);

        var extent = d3.extent(data, function (d) { return +d[opt.valueKey]; });
        var yMin = Math.min(0, extent[0]);
        var yMax = Math.max(0, extent[1]);

        var y = d3.scaleLinear()
            .domain([yMin, yMax || 1]).nice().range([h, 0]);

        var zeroY = y(0);

        // Grid
        g.append('g').call(d3.axisLeft(y).tickSize(-w).tickFormat(''))
            .selectAll('line').style('stroke', colors.gridLine);
        g.selectAll('.grid .domain').remove();

        // Zero line
        g.append('line')
            .attr('x1', 0).attr('x2', w)
            .attr('y1', zeroY).attr('y2', zeroY)
            .attr('stroke', colors.muted).attr('stroke-width', 1);

        // Bars
        g.selectAll('.bar').data(data).enter().append('rect')
            .attr('x', function (d) { return x(d[opt.labelKey]); })
            .attr('y', function (d) { var v = +d[opt.valueKey]; return v >= 0 ? y(v) : zeroY; })
            .attr('width', x.bandwidth())
            .attr('height', function (d) { var v = +d[opt.valueKey]; return Math.abs(y(v) - zeroY); })
            .attr('rx', 2)
            .attr('fill', function (d) { return +d[opt.valueKey] >= 0 ? opt.positiveColor : opt.negativeColor; });

        // Value labels
        g.selectAll('.bar-label').data(data).enter().append('text')
            .attr('x', function (d) { return x(d[opt.labelKey]) + x.bandwidth() / 2; })
            .attr('y', function (d) { var v = +d[opt.valueKey]; return v >= 0 ? y(v) - 4 : y(v) + 14; })
            .attr('text-anchor', 'middle')
            .style('font-size', '10px').style('fill', colors.text)
            .text(function (d) { var v = +d[opt.valueKey]; return (v >= 0 ? '+' : '') + v; });

        // X axis
        g.append('g').attr('transform', 'translate(0,' + h + ')')
            .call(d3.axisBottom(x))
            .selectAll('text').style('fill', colors.text).style('font-size', '10px')
            .attr('transform', 'rotate(-30)').attr('text-anchor', 'end');

        // Y axis
        g.append('g').call(d3.axisLeft(y).ticks(5))
            .selectAll('text').style('fill', colors.text).style('font-size', '11px');

        g.selectAll('.domain').style('stroke', colors.muted);
        g.selectAll('.tick line').style('stroke', colors.muted);
    }

    // ── Public API ───────────────────────────────────────────

    window.WNReports = {
        fetch: fetchReportData,
        areaChart: renderAreaChart,
        barChart: renderBarChart,
        forecastChart: renderForecastChart,
        signedBarChart: renderSignedBarChart,
        getThemeColors: getThemeColors,
    };

})();
