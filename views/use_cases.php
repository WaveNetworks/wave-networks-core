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
                <span class="badge bg-danger" id="badgeNeedsAttention" title="Flagged by a rating drop or rising error rate vs the previous build">0 needs attention</span>

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
                    <th style="width: 150px;">Health</th>
                    <th style="width: 110px;">Graphics</th>
                    <th style="width: 130px;">Starting page</th>
                    <th style="width: 130px;">Ending action</th>
                    <th style="width: 70px;">Logs</th>
                    <th style="width: 150px;">Last seen</th>
                    <th style="width: 150px;">Updated</th>
                </tr>
            </thead>
            <tbody id="useCaseTable">
                <tr><td colspan="11" class="text-center text-muted py-3">Loading...</td></tr>
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

<!-- Link / replace graphic modal -->
<div class="modal fade" id="linkModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="linkModalTitle">Link graphic</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-2" id="linkRoleWrap">
                    <div class="col-md-6">
                        <label class="form-label small mb-0">Role</label>
                        <select class="form-select form-select-sm" id="linkRole"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small mb-0">Variant <span class="text-muted">(e.g. light / dark / en)</span></label>
                        <input type="text" class="form-control form-control-sm" id="linkVariant" maxlength="50" placeholder="optional">
                    </div>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small mb-0">Pick a Media library asset</label>
                    <input type="text" class="form-control form-control-sm" style="width:180px;" placeholder="Search media…" oninput="loadMediaPicker(this.value)">
                </div>
                <div id="mediaPickerGrid" style="max-height:48vh;overflow-y:auto;"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary" id="linkConfirmBtn" onclick="confirmLink()" disabled>Save</button>
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
            tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted py-3">No use cases match. Click <strong>Refresh</strong> to re-derive from the latest test-user action logs, or wait for the nightly 4 AM cron.</td></tr>';
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
    var na = document.getElementById('badgeNeedsAttention');
    if (na) {
        na.textContent = (s.needs_attention || 0) + ' needs attention';
        na.style.display = (s.needs_attention || 0) > 0 ? '' : 'none';
    }
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
    html += '<td>' + healthCell(item) + '</td>';
    html += '<td>' + graphicBadge(item) + '</td>';
    html += '<td class="small text-muted">' + escHtml(item.starting_page || '—') + '</td>';
    html += '<td class="small text-muted">' + escHtml(item.ending_action || '—') + '</td>';
    html += '<td class="small text-end">' + (item.derived_from_log_count || 0) + '</td>';
    html += '<td class="small">' + escHtml(item.last_seen_at || '—') + '</td>';
    html += '<td class="small">' + escHtml(item.updated || '—') + '</td>';
    html += '</tr>';
    return html;
}

function healthCell(item) {
    var html = '';
    if (item.health_status === 'needs_attention') {
        html += '<span class="badge bg-danger" title="' + escAttr(item.flag_reason || 'Regression flagged') + '"><i class="bi bi-exclamation-triangle me-1"></i>needs attention</span> ';
    }
    // Rating
    if (item.rating_count && item.rating_count > 0) {
        var avg = (item.rating_avg != null) ? Number(item.rating_avg).toFixed(1) : '—';
        var cls = (item.rating_avg >= 4) ? 'bg-success' : (item.rating_avg >= 3 ? 'bg-warning text-dark' : 'bg-danger');
        html += '<span class="badge ' + cls + '" title="' + item.rating_count + ' rating(s)"><i class="bi bi-star-fill me-1"></i>' + avg + '</span> ';
    } else {
        html += '<span class="badge bg-light text-muted border" title="No ratings yet">no ratings</span> ';
    }
    // Error users on the flow
    if (item.error_users && item.error_users > 0) {
        html += '<span class="badge bg-danger" title="users hitting open errors on this flow"><i class="bi bi-bug me-1"></i>' + item.error_users + '</span>';
    }
    return html || '<span class="text-muted">—</span>';
}

function graphicBadge(item) {
    var n  = item.graphic_count || 0;
    var nr = item.graphic_needs_review || 0;
    if (n === 0) {
        return '<span class="badge bg-light text-muted border" title="No linked graphics">0</span>';
    }
    var html = '<span class="badge bg-secondary" title="' + n + ' linked graphic(s)"><i class="bi bi-image me-1"></i>' + n + '</span>';
    if (nr > 0) {
        html += ' <span class="badge bg-warning text-dark" title="' + nr + ' need review"><i class="bi bi-exclamation-triangle me-1"></i>' + nr + '</span>';
    }
    return html;
}

function renderDetailRow(item) {
    var html = '<tr id="detail-' + item.use_case_id + '" style="display:none;">';
    html += '<td colspan="11" class="p-3 bg-body-tertiary" id="detail-content-' + item.use_case_id + '">';
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

        // Health panel: per-build trend of rating, error rate and test status,
        // plus the latest comments.
        html += renderHealthPanel(json.results);

        // Graphics panel (linked media assets beside the latest passing run).
        html += '<hr class="my-3">';
        html += '<div id="graphics-' + id + '"><div class="text-muted small">'
             +  '<i class="bi bi-hourglass-split me-1"></i>Loading graphics…</div></div>';

        content.innerHTML = html;
        loadGraphics(id);
    });
}

// ── Health panel: per-build trend + regression flag + latest comments ────────
function renderHealthPanel(res) {
    var uc    = res.use_case || {};
    var trend = res.trend || [];
    var comments = res.comments || [];
    var health = res.health || {};

    var html = '<hr class="my-3">';
    html += '<div class="d-flex align-items-center mb-2"><h6 class="mb-0"><i class="bi bi-heart-pulse me-1"></i>Use case health</h6>';
    if (health.score != null) {
        var sc = Number(health.score);
        var scls = sc >= 80 ? 'bg-success' : (sc >= 50 ? 'bg-warning text-dark' : 'bg-danger');
        html += ' <span class="badge ' + scls + ' ms-2" title="Composite of test status, error rate and ratings">score ' + sc + '</span>';
    }
    html += '</div>';

    if (uc.health_status === 'needs_attention') {
        html += '<div class="alert alert-danger py-2 small mb-2"><i class="bi bi-exclamation-triangle me-1"></i>'
             +  '<strong>Needs attention</strong> on build <code>' + escHtml(uc.flag_build || '') + '</code>: '
             +  escHtml(uc.flag_reason || '') + '</div>';
    }

    html += '<div class="row g-3"><div class="col-md-7">';
    html += '<p class="mb-1 small text-muted">Per-build trend (newest first). Error users = distinct users hitting an open error on this flow\'s pages in that build\'s window.</p>';
    if (trend.length === 0) {
        html += '<div class="text-muted small">No ratings recorded yet, so no per-build trend. The in-flow prompt feeds this once users start rating.</div>';
    } else {
        html += '<table class="table table-sm small mb-0 align-middle"><thead><tr>'
             +  '<th>Build</th><th class="text-end">Rating</th><th class="text-end">#</th>'
             +  '<th class="text-end">Error users</th><th>Last rated</th></tr></thead><tbody>';
        trend.forEach(function(t) {
            var avg = (t.rating_avg != null) ? Number(t.rating_avg).toFixed(2) : '—';
            var acls = (t.rating_avg >= 4) ? 'text-success' : (t.rating_avg >= 3 ? 'text-warning' : 'text-danger');
            html += '<tr>';
            html += '<td><code>' + escHtml(t.build || '(web)') + '</code></td>';
            html += '<td class="text-end ' + (t.rating_avg != null ? acls : 'text-muted') + '">' + avg + '</td>';
            html += '<td class="text-end">' + (t.rating_count || 0) + '</td>';
            html += '<td class="text-end ' + ((t.error_users||0) > 0 ? 'text-danger' : 'text-muted') + '">' + (t.error_users || 0) + '</td>';
            html += '<td class="text-muted">' + escHtml(t.last_rated_at || '—') + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
    }
    html += '</div>';

    // Latest comments
    html += '<div class="col-md-5"><p class="mb-1"><strong>Latest comments</strong> (' + comments.length + '):</p>';
    if (comments.length === 0) {
        html += '<div class="text-muted small">No comments yet.</div>';
    } else {
        html += '<div class="list-group list-group-flush small">';
        comments.forEach(function(c) {
            html += '<div class="list-group-item px-0 py-1">';
            html += '<span class="badge bg-secondary me-1">' + (c.rating || '?') + '★</span>';
            if (c.app_build) html += '<code class="me-1">' + escHtml(c.app_build) + '</code>';
            html += '<span class="badge bg-light text-dark border me-1">' + escHtml(c.platform || '') + '</span>';
            html += '<span class="text-muted">' + escHtml(c.created || '') + '</span>';
            html += '<div>' + escHtml(c.comment || '') + '</div>';
            html += '</div>';
        });
        html += '</div>';
    }
    html += '</div></div>';
    return html;
}

// ── Graphics panel: linked assets BESIDE the latest passing run screenshots ──
function loadGraphics(id) {
    apiPost('getUseCaseAssets', { use_case_id: id }, function(json) {
        var box = document.getElementById('graphics-' + id);
        if (!box) return;
        if (json.error) { box.innerHTML = '<div class="text-danger small">' + escHtml(json.error) + '</div>'; return; }
        var res   = json.results || {};
        var assets= res.assets || [];
        var shots = res.run_screenshots || [];
        var run   = res.latest_run;
        var roles = res.roles || [];

        var html = '<div class="d-flex justify-content-between align-items-center mb-2">';
        html += '<h6 class="mb-0"><i class="bi bi-images me-1"></i>Graphics <span class="text-muted">(' + assets.length + ')</span></h6>';
        html += '<button class="btn btn-sm btn-outline-primary" onclick="openLinkModal(' + id + ')"><i class="bi bi-plus-lg"></i> Link graphic</button>';
        html += '</div>';

        // Latest passing run screenshots (the parity reference).
        html += '<div class="mb-3"><div class="small text-muted mb-1">Latest passing run';
        html += run ? (' <code>#' + run.run_id + '</code> <span class="text-muted">' + escHtml(run.run_at || '') + '</span>') : ' — none yet';
        html += '</div>';
        if (shots.length) {
            html += '<div class="d-flex flex-wrap gap-2">';
            shots.forEach(function(s) {
                html += '<a href="#" onclick="showShot(\'' + escAttr(s.url) + '\',\'' + escAttr(s.name) + '\');return false;" title="' + escAttr(s.name) + '">';
                html += '<img src="' + escAttr(s.url) + '" alt="' + escAttr(s.name) + '" onerror="this.closest(\'a\').style.display=\'none\';" style="height:96px;width:auto;border:2px solid var(--bs-success);border-radius:6px;object-fit:cover;"></a>';
            });
            html += '</div>';
        } else {
            html += '<div class="text-muted small fst-italic">No run screenshots captured yet.</div>';
        }
        html += '</div>';

        if (assets.length === 0) {
            html += '<div class="text-muted small">No graphics linked. Use <strong>Link graphic</strong> to attach a Media library asset this use case depicts.</div>';
        } else {
            html += '<div class="row g-2">';
            assets.forEach(function(a) {
                html += renderAssetCard(a);
            });
            html += '</div>';
        }
        // Stash roles for the modal.
        box.setAttribute('data-roles', JSON.stringify(roles));
        box.innerHTML = html;
    });
}

function reviewBadge(a) {
    var s = a.live_status || a.review_status || 'pending';
    var map = { approved: 'bg-success', needs_review: 'bg-warning text-dark', pending: 'bg-secondary' };
    var label = { approved: 'approved', needs_review: 'needs review', pending: 'pending' }[s] || s;
    return '<span class="badge ' + (map[s] || 'bg-secondary') + '">' + escHtml(label) + '</span>';
}

function renderAssetCard(a) {
    var isVideo = (a.mime_type || '').indexOf('video') === 0;
    var html = '<div class="col-md-4 col-sm-6">';
    html += '<div class="card h-100">';
    if (isVideo) {
        html += '<div class="ratio ratio-1x1 bg-body-tertiary d-flex align-items-center justify-content-center"><i class="bi bi-film" style="font-size:2rem;"></i></div>';
    } else {
        html += '<a href="#" onclick="showShot(\'' + escAttr(a.asset_url) + '\',\'' + escAttr(a.original_name || '') + '\');return false;">';
        html += '<img src="' + escAttr(a.asset_url) + '" class="card-img-top" style="height:120px;object-fit:contain;background:var(--bs-tertiary-bg);" alt="' + escAttr(a.original_name || '') + '"></a>';
    }
    html += '<div class="card-body p-2">';
    html += '<div class="small fw-semibold text-truncate" title="' + escAttr(a.title || a.original_name || '') + '">' + escHtml(a.title || a.original_name || '') + '</div>';
    html += '<div class="small text-muted"><span class="badge bg-light text-dark border">' + escHtml(a.role || '') + '</span>';
    if (a.variant) html += ' <span class="badge bg-light text-dark border">' + escHtml(a.variant) + '</span>';
    html += '</div>';
    html += '<div class="mt-1">' + reviewBadge(a);
    if ((a.live_status === 'needs_review') && a.live_reason) {
        html += ' <span class="text-warning small" title="' + escAttr(a.live_reason) + '"><i class="bi bi-info-circle"></i></span>';
    }
    html += '</div>';
    if (a.live_reason && a.live_status === 'needs_review') {
        html += '<div class="small text-muted mt-1">' + escHtml(a.live_reason) + '</div>';
    }
    html += '<div class="btn-group btn-group-sm mt-2 w-100">';
    html += '<button class="btn btn-outline-success" onclick="assetAction(\'approveUseCaseAsset\',' + a.link_id + ',' + a.use_case_id + ')" title="Record the current run as the approved reference">Approve</button>';
    html += '<button class="btn btn-outline-secondary" onclick="openReplaceModal(' + a.link_id + ',' + a.use_case_id + ')" title="Point at a different media asset">Replace</button>';
    html += '<button class="btn btn-outline-danger" onclick="if(confirm(\'Unlink this graphic?\'))assetAction(\'unlinkUseCaseAsset\',' + a.link_id + ',' + a.use_case_id + ')">Unlink</button>';
    html += '</div>';
    html += '</div></div></div>';
    return html;
}

function assetAction(action, linkId, ucId) {
    apiPost(action, { link_id: linkId }, function(json) {
        if (json.error) { alert(json.error); return; }
        loadGraphics(ucId);
        loadUseCases();
    });
}

var _linkTargetUc = null, _replaceTargetLink = null, _replaceTargetUc = null;

function openLinkModal(ucId) {
    _linkTargetUc = ucId;
    _replaceTargetLink = null;
    var box = document.getElementById('graphics-' + ucId);
    var roles = [];
    try { roles = JSON.parse(box.getAttribute('data-roles') || '[]'); } catch(e) {}
    var sel = document.getElementById('linkRole');
    sel.innerHTML = roles.map(function(r){ return '<option value="' + r + '">' + r + '</option>'; }).join('');
    document.getElementById('linkVariant').value = '';
    document.getElementById('linkModalTitle').textContent = 'Link graphic';
    document.getElementById('linkRoleWrap').style.display = '';
    loadMediaPicker('');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('linkModal')).show();
}

function openReplaceModal(linkId, ucId) {
    _replaceTargetLink = linkId;
    _replaceTargetUc = ucId;
    _linkTargetUc = null;
    document.getElementById('linkModalTitle').textContent = 'Replace graphic';
    document.getElementById('linkRoleWrap').style.display = 'none';
    loadMediaPicker('');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('linkModal')).show();
}

function loadMediaPicker(search) {
    _pickedAsset = null;
    var btn = document.getElementById('linkConfirmBtn');
    if (btn) btn.disabled = true;
    apiPost('listMediaForLink', { search: search || '' }, function(json) {
        var grid = document.getElementById('mediaPickerGrid');
        if (json.error) { grid.innerHTML = '<div class="text-danger small">' + escHtml(json.error) + '</div>'; return; }
        var assets = (json.results || {}).assets || [];
        if (!assets.length) { grid.innerHTML = '<div class="text-muted small p-2">No media assets. Upload them in the Media library first.</div>'; return; }
        var html = '<div class="row g-2">';
        assets.forEach(function(a) {
            var isVideo = (a.mime_type || '').indexOf('video') === 0;
            html += '<div class="col-3"><div class="card h-100 media-pick" style="cursor:pointer;" onclick="pickMedia(' + a.asset_id + ', this)">';
            if (isVideo) {
                html += '<div class="ratio ratio-1x1 d-flex align-items-center justify-content-center bg-body-tertiary"><i class="bi bi-film"></i></div>';
            } else {
                html += '<img src="' + escAttr(a.url) + '" style="height:72px;object-fit:contain;background:var(--bs-tertiary-bg);" alt="">';
            }
            html += '<div class="card-body p-1 small text-truncate" title="' + escAttr(a.title || a.original_name || '') + '">' + escHtml(a.title || a.original_name || '') + '</div>';
            html += '</div></div>';
        });
        html += '</div>';
        grid.innerHTML = html;
    });
}

var _pickedAsset = null;
function pickMedia(assetId, el) {
    _pickedAsset = assetId;
    document.querySelectorAll('#mediaPickerGrid .media-pick').forEach(function(c){ c.classList.remove('border','border-primary','border-3'); });
    el.classList.add('border','border-primary','border-3');
    document.getElementById('linkConfirmBtn').disabled = false;
}

function confirmLink() {
    if (!_pickedAsset) return;
    if (_replaceTargetLink) {
        apiPost('replaceUseCaseAsset', { link_id: _replaceTargetLink, asset_id: _pickedAsset }, function(json) {
            if (json.error) { alert(json.error); return; }
            bootstrap.Modal.getOrCreateInstance(document.getElementById('linkModal')).hide();
            loadGraphics(_replaceTargetUc); loadUseCases();
        });
    } else if (_linkTargetUc) {
        apiPost('linkUseCaseAsset', {
            use_case_id: _linkTargetUc,
            asset_id:    _pickedAsset,
            role:        document.getElementById('linkRole').value,
            variant:     document.getElementById('linkVariant').value
        }, function(json) {
            if (json.error) { alert(json.error); return; }
            bootstrap.Modal.getOrCreateInstance(document.getElementById('linkModal')).hide();
            loadGraphics(_linkTargetUc); loadUseCases();
        });
    }
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
            // The Use Cases view renders under /admin/app/index.php, but
            // use_case_screenshot.php lives at the admin root (/admin/). A bare
            // relative URL resolves to /admin/app/… and 404s, so the <img
            // onerror> hides every thumbnail. Step up one dir to the admin root.
            url:  '../use_case_screenshot.php?run_id=' + encodeURIComponent(r.run_id) + '&f=' + encodeURIComponent(name)
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
