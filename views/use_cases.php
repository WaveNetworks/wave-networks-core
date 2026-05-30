<?php
/**
 * views/use_cases.php
 * Admin-only browser for the use_case + use_case_test_run tables.
 * Lets us verify what derive_use_cases.py has produced before turning
 * the Playwright runner loose against prod.
 */
if (!has_role('admin')) {
    $_SESSION['error'] = 'Admin access required.';
    header('Location: index.php?page=dashboard');
    exit;
}
$page_title = 'Use Cases';
?>

<h4 class="mb-3"><i class="bi bi-check2-square me-2"></i>Use Cases</h4>

<div class="card mb-4">
    <div class="card-header">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h6 class="mb-0"><i class="bi bi-diagram-3 me-1"></i> Derived user journeys</h6>
            <div class="d-flex flex-wrap gap-1 align-items-center">
                <span class="badge bg-secondary" id="badgeTotal">0 total</span>
                <span class="badge bg-warning text-dark" id="badgePending">0 pending</span>
                <span class="badge bg-success" id="badgePassing">0 passing</span>
                <span class="badge bg-danger" id="badgeFailing">0 failing</span>
                <span class="badge bg-info text-dark" id="badgeFlaky">0 flaky</span>
                <span class="badge bg-dark" id="badgeDisabled">0 disabled</span>

                <select class="form-select form-select-sm" id="appFilter" onchange="currentPage=1; loadUseCases()" style="width: auto;">
                    <option value="">All apps</option>
                </select>

                <select class="form-select form-select-sm" id="statusFilter" onchange="currentPage=1; loadUseCases()" style="width: auto;">
                    <option value="">All status</option>
                    <option value="pending">Pending</option>
                    <option value="passing">Passing</option>
                    <option value="failing">Failing</option>
                    <option value="flaky">Flaky</option>
                    <option value="disabled">Disabled</option>
                </select>

                <select class="form-select form-select-sm" id="categoryFilter" onchange="currentPage=1; loadUseCases()" style="width: auto;">
                    <option value="">All categories</option>
                    <option value="preflight">Preflight</option>
                    <option value="auth">Auth</option>
                    <option value="smoke">Smoke</option>
                    <option value="feature">Feature</option>
                    <option value="accessibility">Accessibility</option>
                </select>

                <input type="text" class="form-control form-control-sm" id="searchInput" placeholder="Search slug / name..." style="width: 200px;" onkeyup="debounceSearch()">

                <button class="btn btn-sm btn-outline-primary" id="refreshBtn" onclick="refreshUseCases()" title="Re-derive use_cases from the latest test-user action log + regenerate Playwright specs">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
            </div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
            <thead>
                <tr>
                    <th style="width: 100px;">App</th>
                    <th>Slug / Name</th>
                    <th style="width: 120px;">Category</th>
                    <th style="width: 100px;">Status</th>
                    <th style="width: 130px;">Starting page</th>
                    <th style="width: 130px;">Ending action</th>
                    <th style="width: 70px;">Logs</th>
                    <th style="width: 150px;">Last seen</th>
                    <th style="width: 150px;">Updated</th>
                </tr>
            </thead>
            <tbody id="useCaseTable">
                <tr><td colspan="9" class="text-center text-muted py-3">Loading...</td></tr>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center" id="pagination">
        <span id="rowInfo" class="text-muted small"></span>
        <div>
            <button class="btn btn-sm btn-outline-secondary" id="prevPage" onclick="changePage(-1)" disabled>&laquo; Prev</button>
            <button class="btn btn-sm btn-outline-secondary" id="nextPage" onclick="changePage(1)">Next &raquo;</button>
        </div>
    </div>
</div>

<!-- Screenshot lightbox -->
<div class="modal fade" id="shotModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="shotModalLabel">Screenshot</h6>
                <a id="shotModalOpen" href="#" target="_blank" class="btn btn-sm btn-outline-secondary ms-auto me-2">
                    <i class="bi bi-box-arrow-up-right"></i> Open
                </a>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center bg-body-tertiary">
                <img id="shotModalImg" src="" alt="" class="img-fluid" style="max-height:78vh;">
            </div>
        </div>
    </div>
</div>

<script>
var currentPage = 1;
var totalItems  = 0;
var perPage     = 50;
var searchTimer = null;

function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function() {
        currentPage = 1;
        loadUseCases();
    }, 400);
}

function getFilters() {
    return {
        source_app:    document.getElementById('appFilter').value,
        test_status:   document.getElementById('statusFilter').value,
        test_category: document.getElementById('categoryFilter').value,
        search:        document.getElementById('searchInput').value
    };
}

function loadUseCases() {
    var params = getFilters();
    params.page     = currentPage;
    params.per_page = perPage;

    apiPost('getUseCases', params, function(json) {
        if (json.error) return;
        var items = json.results.items || [];
        totalItems = json.results.total || 0;
        var tbody  = document.getElementById('useCaseTable');

        updateStats(json.results.stats || {});
        updateAppFilter(json.results.apps || []);

        if (items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-3">No use cases match. Click <strong>Refresh</strong> to re-derive from the latest test-user action logs, or wait for the nightly 4 AM cron.</td></tr>';
        } else {
            var html = '';
            items.forEach(function(item) {
                html += renderRow(item);
                html += renderDetailRow(item);
            });
            tbody.innerHTML = html;
        }

        var start = ((currentPage - 1) * perPage) + 1;
        var end   = Math.min(currentPage * perPage, totalItems);
        document.getElementById('rowInfo').textContent = totalItems > 0 ? start + '-' + end + ' of ' + totalItems : '';
        document.getElementById('prevPage').disabled = currentPage <= 1;
        document.getElementById('nextPage').disabled = end >= totalItems;
    });
}

function updateStats(s) {
    document.getElementById('badgeTotal').textContent    = (s.total    || 0) + ' total';
    document.getElementById('badgePending').textContent  = (s.pending  || 0) + ' pending';
    document.getElementById('badgePassing').textContent  = (s.passing  || 0) + ' passing';
    document.getElementById('badgeFailing').textContent  = (s.failing  || 0) + ' failing';
    document.getElementById('badgeFlaky').textContent    = (s.flaky    || 0) + ' flaky';
    document.getElementById('badgeDisabled').textContent = (s.disabled || 0) + ' disabled';
}

function updateAppFilter(apps) {
    var sel = document.getElementById('appFilter');
    var current = sel.value;
    // Only rebuild if the list actually changed (avoid blowing the user's selection)
    var existing = Array.from(sel.options).slice(1).map(function(o){return o.value;});
    if (apps.length === existing.length && apps.every(function(v,i){return v === existing[i];})) return;
    sel.innerHTML = '<option value="">All apps</option>';
    apps.forEach(function(a) {
        var opt = document.createElement('option');
        opt.value = a;
        opt.textContent = a;
        if (a === current) opt.selected = true;
        sel.appendChild(opt);
    });
}

function statusBadge(s) {
    var map = {
        pending:  'bg-warning text-dark',
        passing:  'bg-success',
        failing:  'bg-danger',
        flaky:    'bg-info text-dark',
        disabled: 'bg-dark'
    };
    return '<span class="badge ' + (map[s] || 'bg-secondary') + '">' + escHtml(s || '') + '</span>';
}

function categoryBadge(c) {
    var map = {
        preflight:     'bg-secondary',
        auth:          'bg-primary',
        smoke:         'bg-info text-dark',
        feature:       'bg-light text-dark border',
        accessibility: 'bg-warning text-dark'
    };
    return '<span class="badge ' + (map[c] || 'bg-secondary') + '">' + escHtml(c || '') + '</span>';
}

function renderRow(item) {
    var name = escHtml(item.name || item.slug || '');
    var slug = escHtml(item.slug || '');
    var html = '<tr style="cursor:pointer;" onclick="toggleDetail(' + item.use_case_id + ')">';
    html += '<td><span class="badge bg-light text-dark border">' + escHtml(item.source_app || '') + '</span></td>';
    html += '<td>';
    html +=   '<div class="small fw-semibold">' + name + '</div>';
    html +=   '<div class="small text-muted"><code>' + slug + '</code></div>';
    html += '</td>';
    html += '<td>' + categoryBadge(item.test_category) + '</td>';
    html += '<td>' + statusBadge(item.test_status) + '</td>';
    html += '<td class="small text-muted">' + escHtml(item.starting_page || '—') + '</td>';
    html += '<td class="small text-muted">' + escHtml(item.ending_action || '—') + '</td>';
    html += '<td class="small text-end">' + (item.derived_from_log_count || 0) + '</td>';
    html += '<td class="small">' + escHtml(item.last_seen_at || '—') + '</td>';
    html += '<td class="small">' + escHtml(item.updated || '—') + '</td>';
    html += '</tr>';
    return html;
}

function renderDetailRow(item) {
    var html = '<tr id="detail-' + item.use_case_id + '" style="display:none;">';
    html += '<td colspan="9" class="p-3 bg-body-tertiary" id="detail-content-' + item.use_case_id + '">';
    html += '<div class="text-muted small"><i class="bi bi-hourglass-split me-1"></i>Loading detail...</div>';
    html += '</td></tr>';
    return html;
}

function toggleDetail(id) {
    var row = document.getElementById('detail-' + id);
    if (!row) return;
    if (row.style.display === 'none') {
        row.style.display = '';
        loadDetail(id);
    } else {
        row.style.display = 'none';
    }
}

function loadDetail(id) {
    apiPost('getUseCaseDetail', { use_case_id: id }, function(json) {
        if (json.error) return;
        var uc   = json.results.use_case || {};
        var runs = json.results.runs || [];
        var content = document.getElementById('detail-content-' + id);
        if (!content) return;

        var steps = [];
        try { steps = JSON.parse(uc.action_path || '[]') || []; } catch (e) { steps = []; }

        var html = '<div class="row g-3">';
        // Left: meta + steps
        html += '<div class="col-md-7">';
        html +=   '<p class="mb-1"><strong>Description:</strong> ' + escHtml(uc.description || '—') + '</p>';
        html +=   '<p class="mb-1"><strong>Requires login:</strong> ' + (uc.requires_login == 1 ? 'yes' : 'no') + '</p>';
        html +=   '<p class="mb-1"><strong>Created:</strong> ' + escHtml(uc.created || '—') + ' &middot; <strong>Updated:</strong> ' + escHtml(uc.updated || '—') + '</p>';
        html +=   '<p class="mb-1 mt-2"><strong>Action path</strong> (' + steps.length + ' steps):</p>';
        if (steps.length === 0) {
            html += '<div class="text-muted small">No action_path recorded.</div>';
        } else {
            html += '<ol class="small mb-0">';
            steps.forEach(function(s) {
                var page   = escHtml(s.page   || '');
                var action = escHtml(s.action || '');
                var result = escHtml(s.result || '');
                var dur    = s.duration_ms != null ? (' &middot; ' + s.duration_ms + 'ms') : '';
                html += '<li><code>' + page + '</code> &rarr; <code>' + action + '</code>'
                     +  ' <span class="text-muted">[' + result + dur + ']</span></li>';
            });
            html += '</ol>';
        }
        html += '</div>';

        // Right: recent runs
        html += '<div class="col-md-5">';
        html +=   '<p class="mb-1"><strong>Recent runs</strong> (' + runs.length + '):</p>';
        if (runs.length === 0) {
            html += '<div class="text-muted small">No test runs yet — the Playwright suite has not exercised this case.</div>';
        } else {
            html += '<table class="table table-sm small mb-0 align-middle">';
            html += '<thead><tr><th>When</th><th>Permutation</th><th>Status</th><th class="text-end">ms</th></tr></thead><tbody>';
            runs.forEach(function(r) {
                var sb = statusBadge(({pass:'passing',fail:'failing',flaky:'flaky',skipped:'pending'})[r.status] || r.status);
                html += '<tr title="' + escAttr(r.fail_reason || '') + '">';
                html += '<td>' + escHtml(r.run_at || '') + '</td>';
                html += '<td>' + escHtml(r.permutation || '') + '</td>';
                html += '<td>' + sb + '</td>';
                html += '<td class="text-end">' + (r.duration_ms || '—') + '</td>';
                html += '</tr>';
                var shots = runScreenshots(r);
                if (shots.length) {
                    html += '<tr><td colspan="4" class="pt-0 pb-2">' + renderThumbs(shots) + '</td></tr>';
                }
            });
            html += '</tbody></table>';
        }
        html += '</div>';
        html += '</div>';

        content.innerHTML = html;
    });
}

// Build viewable screenshot descriptors for a run. screenshot_paths is a
// JSON array of runner-local paths; we serve by run_id + basename through
// use_case_screenshot.php (the files are uploaded post-run).
function runScreenshots(r) {
    var paths = [];
    try { paths = JSON.parse(r.screenshot_paths || '[]') || []; } catch (e) { paths = []; }
    if (!Array.isArray(paths)) paths = [];
    var out = [];
    paths.forEach(function(p) {
        var name = String(p).split('/').pop();
        if (!name || !/\.png$/i.test(name)) return;
        out.push({
            name: name,
            url:  'use_case_screenshot.php?run_id=' + encodeURIComponent(r.run_id) + '&f=' + encodeURIComponent(name)
        });
    });
    return out;
}

function renderThumbs(shots) {
    var html = '<div class="d-flex flex-wrap gap-2">';
    shots.forEach(function(s) {
        html += '<a href="#" onclick="showShot(\'' + escAttr(s.url) + '\',\'' + escAttr(s.name) + '\');return false;" '
             +  'title="' + escAttr(s.name) + '">';
        html += '<img src="' + escAttr(s.url) + '" alt="' + escAttr(s.name) + '" '
             +  'onerror="this.closest(\'a\').style.display=\'none\';" '
             +  'style="height:64px;width:auto;border:1px solid var(--bs-border-color);border-radius:4px;object-fit:cover;">';
        html += '</a>';
    });
    html += '</div>';
    return html;
}

function showShot(url, name) {
    document.getElementById('shotModalImg').src = url;
    document.getElementById('shotModalLabel').textContent = name || 'Screenshot';
    document.getElementById('shotModalOpen').href = url;
    var el = document.getElementById('shotModal');
    if (window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(el).show();
    } else {
        window.open(url, '_blank');
    }
}

function changePage(dir) {
    currentPage += dir;
    if (currentPage < 1) currentPage = 1;
    loadUseCases();
}

function escHtml(str) {
    var div = document.createElement('div');
    div.textContent = (str == null) ? '' : String(str);
    return div.innerHTML;
}
function escAttr(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/'/g,'&#39;').replace(/"/g,'&quot;');
}

function refreshUseCases() {
    var btn = document.getElementById('refreshBtn');
    if (!btn) return;
    var originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Refreshing…';
    apiPost('refreshUseCases', {}, function(json) {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        if (json.error) {
            alert('Refresh failed: ' + json.error);
            return;
        }
        var elapsed = (json.results && json.results.elapsed_ms) ? Math.round(json.results.elapsed_ms / 100) / 10 : '?';
        var tail = (json.results && json.results.output_tail) || [];
        var msg = 'Refresh done in ' + elapsed + 's.\n\nLast lines of output:\n' + tail.join('\n');
        if (typeof console !== 'undefined') console.log('[refreshUseCases]', json.results);
        alert(msg);
        loadUseCases();
    });
}

document.addEventListener('DOMContentLoaded', function() { loadUseCases(); });
</script>
