<?php
/**
 * views/error_log.php
 * Admin-only error log viewer with filters, pagination, resolve/unresolve, and expandable stack traces.
 */
if (!has_role('admin')) {
    $_SESSION['error'] = 'Admin access required.';
    header('Location: index.php?page=dashboard');
    exit;
}
$page_title = 'Error Log';
$stats = get_error_log_stats();
$sources = get_error_log_sources();
?>

<h4 class="mb-3"><i class="bi bi-exclamation-triangle me-2"></i>Error Log</h4>

<!-- Stats + Filters -->
<div class="card mb-4">
    <div class="card-header">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h6 class="mb-0"><i class="bi bi-journal-text me-1"></i> PHP & JS Errors</h6>
            <div class="d-flex flex-wrap gap-1 align-items-center">
                <span class="badge bg-dark" id="badgeFatals"><?= $stats['fatals_today'] ?> fatal</span>
                <span class="badge bg-danger" id="badgeErrors"><?= $stats['errors_today'] ?> errors today</span>
                <span class="badge bg-warning text-dark" id="badgeWarnings"><?= $stats['warnings_today'] ?> warnings</span>
                <span class="badge bg-success" id="badgeResolved"><?= $stats['resolved'] ?> resolved</span>
                <span class="badge bg-secondary" id="badgeTotal"><?= $stats['total'] ?> total</span>

                <select class="form-select form-select-sm" id="statusFilter" onchange="currentPage=1; loadErrors()" style="width: auto;">
                    <option value="">All Status</option>
                    <option value="open" selected>Open</option>
                    <option value="resolved">Resolved</option>
                </select>

                <select class="form-select form-select-sm" id="levelFilter" onchange="currentPage=1; loadErrors()" style="width: auto;">
                    <option value="">All Levels</option>
                    <option value="FATAL">Fatal</option>
                    <option value="ERROR">Error</option>
                    <option value="WARNING">Warning</option>
                    <option value="INFO">Info</option>
                    <option value="DEBUG">Debug</option>
                </select>

                <select class="form-select form-select-sm" id="sourceFilter" onchange="currentPage=1; loadErrors()" style="width: auto;">
                    <option value="">All Sources</option>
                    <?php foreach ($sources as $src) { ?>
                    <option value="<?= h($src) ?>"><?= h($src) ?></option>
                    <?php } ?>
                </select>

                <input type="text" class="form-control form-control-sm" id="searchInput" placeholder="Search messages..." style="width: 180px;" onkeyup="debounceSearch()">

                <button class="btn btn-sm btn-outline-danger" onclick="clearOldErrors()" title="Clear entries older than 30 days">
                    <i class="bi bi-trash me-1"></i>Clear 30+ days
                </button>
            </div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
            <thead>
                <tr>
                    <th style="width: 80px;">Level</th>
                    <th>Message</th>
                    <th>File</th>
                    <th>Source</th>
                    <th>Page</th>
                    <th>User</th>
                    <th>Count</th>
                    <th>Created</th>
                    <th style="width: 90px;"></th>
                </tr>
            </thead>
            <tbody id="errorTable">
                <tr><td colspan="9" class="text-center text-muted py-3">Loading...</td></tr>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center" id="errorPagination">
        <span id="errorInfo" class="text-muted small"></span>
        <div>
            <button class="btn btn-sm btn-outline-secondary" id="prevPage" onclick="changePage(-1)" disabled>&laquo; Prev</button>
            <button class="btn btn-sm btn-outline-secondary" id="nextPage" onclick="changePage(1)">Next &raquo;</button>
        </div>
    </div>
</div>

<script>
var currentPage = 1;
var totalItems = 0;
var perPage = 50;
var searchTimer = null;

function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function() {
        currentPage = 1;
        loadErrors();
    }, 400);
}

function loadErrors() {
    var params = {
        page: currentPage,
        per_page: perPage,
        level: document.getElementById('levelFilter').value,
        source_app: document.getElementById('sourceFilter').value,
        search: document.getElementById('searchInput').value,
        status: document.getElementById('statusFilter').value
    };

    apiPost('getErrorLogs', params, function(json) {
        if (json.error) return;
        var items = json.results.items || [];
        totalItems = json.results.total || 0;
        var tbody = document.getElementById('errorTable');

        // Update badges
        if (json.results.stats) {
            var s = json.results.stats;
            document.getElementById('badgeFatals').textContent = s.fatals_today + ' fatal';
            document.getElementById('badgeErrors').textContent = s.errors_today + ' errors today';
            document.getElementById('badgeWarnings').textContent = s.warnings_today + ' warnings';
            document.getElementById('badgeResolved').textContent = s.resolved + ' resolved';
            document.getElementById('badgeTotal').textContent = s.total + ' total';
        }

        // Update source filter options
        if (json.results.sources) {
            var sel = document.getElementById('sourceFilter');
            var current = sel.value;
            sel.innerHTML = '<option value="">All Sources</option>';
            json.results.sources.forEach(function(src) {
                var opt = document.createElement('option');
                opt.value = src;
                opt.textContent = src;
                if (src === current) opt.selected = true;
                sel.appendChild(opt);
            });
        }

        if (items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-3">No errors found.</td></tr>';
        } else {
            var html = '';
            items.forEach(function(item) {
                var badge = {FATAL:'bg-dark',ERROR:'bg-danger',WARNING:'bg-warning text-dark',INFO:'bg-info text-dark',DEBUG:'bg-secondary'}[item.level] || 'bg-secondary';
                var msg = escHtml(item.message || '');
                var shortMsg = msg.length > 80 ? msg.substring(0, 80) + '...' : msg;
                var shortFile = item.file ? escHtml(item.file).split(/[/\\]/).slice(-2).join('/') : '';
                if (item.line) shortFile += ':' + item.line;
                var isResolved = !!item.resolved_at;
                var rowClass = isResolved ? ' class="table-success" style="cursor:pointer; opacity: 0.7;"' : ' style="cursor:pointer;"';

                html += '<tr' + rowClass + ' onclick="toggleDetail(' + item.error_id + ')">';
                html += '<td><span class="badge ' + badge + '">' + escHtml(item.level) + '</span>';
                if (isResolved) html += ' <i class="bi bi-check-circle-fill text-success" title="Resolved"></i>';
                html += '</td>';
                html += '<td class="small text-truncate" style="max-width: 300px;" title="' + msg + '">' + shortMsg + '</td>';
                html += '<td class="small text-muted" style="max-width: 180px;" title="' + escHtml(item.file || '') + '">' + shortFile + '</td>';
                html += '<td><span class="badge bg-light text-dark">' + escHtml(item.source_app || '') + '</span></td>';
                html += '<td class="small">' + escHtml(item.page || '') + '</td>';
                html += '<td class="small">' + (item.user_id || '—') + '</td>';
                var count = parseInt(item.occurrence_count) || 1;
                if (count > 1) {
                    html += '<td><span class="badge bg-warning text-dark" title="Last seen: ' + escHtml(item.last_seen_at || '') + '">' + count + 'x</span></td>';
                } else {
                    html += '<td class="small text-muted">1</td>';
                }
                html += '<td class="small">' + escHtml(item.created || '') + '</td>';
                html += '<td class="text-end text-nowrap">';
                if (isResolved) {
                    html += '<button class="btn btn-sm btn-outline-warning me-1" onclick="event.stopPropagation(); unresolveError(' + item.error_id + ')" title="Reopen"><i class="bi bi-arrow-counterclockwise"></i></button>';
                } else {
                    html += '<button class="btn btn-sm btn-outline-success me-1" onclick="event.stopPropagation(); resolveError(' + item.error_id + ')" title="Mark Resolved"><i class="bi bi-check-lg"></i></button>';
                }
                html += '<button class="btn btn-sm btn-outline-danger" onclick="event.stopPropagation(); deleteError(' + item.error_id + ')" title="Delete"><i class="bi bi-trash"></i></button>';
                html += '</td>';
                html += '</tr>';

                // Detail row (hidden by default)
                html += '<tr id="detail-' + item.error_id + '" style="display:none;">';
                html += '<td colspan="9" class="p-3 bg-body-tertiary">';
                html += '<div class="row">';

                // Left column: message + file + request
                html += '<div class="col-md-6">';
                html += '<p class="mb-1"><strong>Message:</strong></p>';
                html += '<p class="small">' + msg + '</p>';
                html += '<p class="mb-1"><strong>File:</strong> <code>' + escHtml(item.file || '?') + ':' + (item.line || '?') + '</code></p>';
                html += '<p class="mb-1"><strong>Request:</strong> <code>' + escHtml(item.request_method || '') + ' ' + escHtml(item.request_uri || '') + '</code></p>';
                if (count > 1) {
                    html += '<p class="mb-1"><strong>Occurrences:</strong> <span class="badge bg-warning text-dark">' + count + '</span>';
                    html += ' &mdash; <strong>Last seen:</strong> ' + escHtml(item.last_seen_at || '') + '</p>';
                }
                html += '<p class="mb-1"><strong>IP:</strong> ' + escHtml(item.ip_address || '—') + '</p>';
                html += '<p class="mb-1"><strong>User Agent:</strong> <span class="small text-muted">' + escHtml(item.user_agent || '—') + '</span></p>';
                if (isResolved) {
                    html += '<p class="mb-1 mt-2"><span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Resolved</span> ' + escHtml(item.resolved_at || '') + ' by user #' + (item.resolved_by || '?') + '</p>';
                }
                html += '</div>';

                // Right column: context
                html += '<div class="col-md-6">';
                if (item.context_json) {
                    try {
                        var ctx = JSON.parse(item.context_json);
                        html += '<p class="mb-1"><strong>Context:</strong></p>';
                        html += '<pre class="bg-dark text-light p-2 rounded small" style="max-height: 200px; overflow: auto;">' + escHtml(JSON.stringify(ctx, null, 2)) + '</pre>';
                    } catch(e) {
                        html += '<p class="mb-1"><strong>Context:</strong></p>';
                        html += '<pre class="bg-dark text-light p-2 rounded small">' + escHtml(item.context_json) + '</pre>';
                    }
                }
                html += '</div>';
                html += '</div>';

                // Stack trace (full width)
                if (item.stack_trace) {
                    html += '<p class="mb-1 mt-2"><strong>Stack Trace:</strong></p>';
                    html += '<pre class="bg-dark text-light p-3 rounded small" style="max-height: 300px; overflow: auto;">' + escHtml(item.stack_trace) + '</pre>';
                }

                html += '</td></tr>';
            });
            tbody.innerHTML = html;
        }

        // Pagination info
        var start = ((currentPage - 1) * perPage) + 1;
        var end = Math.min(currentPage * perPage, totalItems);
        document.getElementById('errorInfo').textContent = totalItems > 0 ? start + '-' + end + ' of ' + totalItems : '';
        document.getElementById('prevPage').disabled = currentPage <= 1;
        document.getElementById('nextPage').disabled = end >= totalItems;
    });
}

function changePage(dir) {
    currentPage += dir;
    if (currentPage < 1) currentPage = 1;
    loadErrors();
}

function toggleDetail(id) {
    var row = document.getElementById('detail-' + id);
    if (row) {
        row.style.display = row.style.display === 'none' ? '' : 'none';
    }
}

function resolveError(id) {
    apiPost('resolveErrorLog', { error_id: id }, function(json) {
        if (!json.error) loadErrors();
    });
}

function unresolveError(id) {
    apiPost('unresolveErrorLog', { error_id: id }, function(json) {
        if (!json.error) loadErrors();
    });
}

function deleteError(id) {
    if (!confirm('Delete this error log entry?')) return;
    apiPost('deleteErrorLog', { error_id: id }, function(json) {
        if (!json.error) loadErrors();
    });
}

function clearOldErrors() {
    if (!confirm('Delete all error log entries older than 30 days?')) return;
    apiPost('clearErrorLogs', { older_than_days: 30 }, function(json) {
        if (!json.error) loadErrors();
    });
}

function escHtml(str) {
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Load on page load
document.addEventListener('DOMContentLoaded', function() { loadErrors(); });
</script>
