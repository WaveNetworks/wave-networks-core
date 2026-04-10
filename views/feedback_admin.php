<?php
/**
 * views/feedback_admin.php
 * Feedback Management — view feedback, upvote, create & manage change requests.
 */
if (!has_role('admin')) {
    $_SESSION['error'] = 'Admin access required.';
    header('Location: index.php?page=dashboard');
    exit;
}
$page_title = 'Feedback';
$stats = get_feedback_stats();
?>

<!-- REGION: page-header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h3><i class="bi bi-chat-dots me-2"></i>Feedback</h3>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabFeedback" type="button">
            Feedback <span class="badge bg-primary ms-1" id="badgeNew"><?= $stats['new'] ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabChangeRequests" type="button" id="crTabBtn">
            Change Requests
        </button>
    </li>
</ul>

<div class="tab-content">

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- TAB: Feedback                                              -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="tab-pane fade show active" id="tabFeedback">

    <!-- Stats -->
    <div class="d-flex flex-wrap gap-2 mb-3">
        <span class="badge bg-primary" id="statTotal">Total: <?= $stats['total'] ?></span>
        <span class="badge bg-info" id="statNew">New: <?= $stats['new'] ?></span>
        <span class="badge bg-secondary" id="statReviewed">Reviewed: <?= $stats['reviewed'] ?></span>
        <span class="badge bg-warning text-dark" id="statGrouped">Grouped: <?= $stats['grouped'] ?></span>
        <span class="badge bg-dark" id="statDismissed">Dismissed: <?= $stats['dismissed'] ?></span>
    </div>

    <!-- Filters -->
    <div class="row mb-3 g-2">
        <div class="col-md-2">
            <select class="form-select form-select-sm" id="fbFilterType">
                <option value="">All Types</option>
                <option value="bug">Bug</option>
                <option value="suggestion">Suggestion</option>
                <option value="general">General</option>
            </select>
        </div>
        <div class="col-md-2">
            <select class="form-select form-select-sm" id="fbFilterSource">
                <option value="">All Sources</option>
            </select>
        </div>
        <div class="col-md-2">
            <select class="form-select form-select-sm" id="fbFilterStatus">
                <option value="">All Status</option>
                <option value="new">New</option>
                <option value="reviewed">Reviewed</option>
                <option value="grouped">Grouped</option>
                <option value="dismissed">Dismissed</option>
            </select>
        </div>
        <div class="col-md-3">
            <input type="text" class="form-control form-control-sm" id="fbFilterSearch" placeholder="Search feedback...">
        </div>
        <div class="col-md-1">
            <button class="btn btn-sm btn-primary w-100" id="btnFilterFb" onclick="loadFeedback(1)">Filter</button>
        </div>
    </div>

    <!-- Table -->
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead>
                <tr>
                    <th style="width:80px">Type</th>
                    <th>Message</th>
                    <th style="width:120px">User</th>
                    <th style="width:60px" class="text-center">Votes</th>
                    <th style="width:90px">Status</th>
                    <th style="width:130px">Date</th>
                    <th style="width:150px"></th>
                </tr>
            </thead>
            <tbody id="fbTableBody">
                <tr><td colspan="7" class="text-muted text-center py-4">Loading...</td></tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="d-flex justify-content-between align-items-center">
        <small class="text-muted" id="fbPagInfo"></small>
        <nav>
            <ul class="pagination pagination-sm mb-0" id="fbPagPages"></ul>
        </nav>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- TAB: Change Requests                                       -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tabChangeRequests">

    <div class="d-flex justify-content-between mb-3">
        <!-- Filters -->
        <div class="d-flex gap-2">
            <select class="form-select form-select-sm" id="crFilterStatus" onchange="loadChangeRequests(1)" style="width:auto">
                <option value="">All Status</option>
                <option value="proposed">Proposed</option>
                <option value="approved">Approved</option>
                <option value="in_progress">In Progress</option>
                <option value="completed">Completed</option>
                <option value="paused">Paused</option>
                <option value="rejected">Rejected</option>
            </select>
            <select class="form-select form-select-sm" id="crFilterType" onchange="loadChangeRequests(1)" style="width:auto">
                <option value="">All Types</option>
                <option value="change">Change</option>
                <option value="addition">Addition</option>
            </select>
            <select class="form-select form-select-sm" id="crFilterPriority" onchange="loadChangeRequests(1)" style="width:auto">
                <option value="">All Priority</option>
                <option value="critical">Critical</option>
                <option value="high">High</option>
                <option value="medium">Medium</option>
                <option value="low">Low</option>
            </select>
        </div>
        <button class="btn btn-sm btn-primary" onclick="openCRModal()">
            <i class="bi bi-plus-lg"></i> New Change Request
        </button>
    </div>

    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead>
                <tr>
                    <th>Title</th>
                    <th style="width:80px">Type</th>
                    <th style="width:80px">Priority</th>
                    <th style="width:110px">Status</th>
                    <th style="width:80px" class="text-center">Feedback</th>
                    <th style="width:130px">Updated</th>
                    <th style="width:100px"></th>
                </tr>
            </thead>
            <tbody id="crTableBody">
                <tr><td colspan="7" class="text-muted text-center py-4">Loading...</td></tr>
            </tbody>
        </table>
    </div>

    <div class="d-flex justify-content-between align-items-center">
        <small class="text-muted" id="crPagInfo"></small>
        <nav>
            <ul class="pagination pagination-sm mb-0" id="crPagPages"></ul>
        </nav>
    </div>
</div>

</div><!-- /tab-content -->

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- MODAL: Create/Edit Change Request                          -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="crModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="crModalTitle">New Change Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="crEditId" value="">
                <input type="hidden" id="crFeedbackId" value="">
                <div class="mb-3">
                    <label class="form-label">Title</label>
                    <input type="text" class="form-control" id="crTitle" placeholder="Short descriptive title">
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" id="crDescription" rows="4" placeholder="Detailed description..."></textarea>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Type</label>
                        <select class="form-select" id="crType">
                            <option value="change">Change</option>
                            <option value="addition">Addition</option>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Priority</label>
                        <select class="form-select" id="crPriority">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveCR()">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: Group feedback with change request -->
<div class="modal fade" id="groupModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Group with Change Request</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="groupFeedbackId" value="">
                <select class="form-select" id="groupCRSelect">
                    <option value="">Loading...</option>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary btn-sm" onclick="confirmGroup()">Group</button>
            </div>
        </div>
    </div>
</div>

<!-- ── CR Detail Modal ─────────────────────────────────────────────────── -->
<div class="modal fade" id="crDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="crDetailLabel">Change Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="crDetailBody">
                <p class="text-muted text-center py-3">Loading...</p>
            </div>
        </div>
    </div>
</div><!-- /crDetailModal -->

<script>
(function () {
    'use strict';

    var fbPage = 1;
    var crPage = 1;
    var userUpvotes = [];
    var fbData = {};

    // ── Feedback Tab ────────────────────────────────────────

    window.loadFeedback = function (page) {
        fbPage = page || 1;
        var fd = new FormData();
        fd.append('action', 'getFeedbackData');
        fd.append('page', fbPage);
        if (document.getElementById('fbFilterType').value)   fd.append('feedback_type', document.getElementById('fbFilterType').value);
        if (document.getElementById('fbFilterSource').value) fd.append('source_app',    document.getElementById('fbFilterSource').value);
        if (document.getElementById('fbFilterStatus').value) fd.append('status',        document.getElementById('fbFilterStatus').value);
        if (document.getElementById('fbFilterSearch').value) fd.append('search',        document.getElementById('fbFilterSearch').value);

        fetch('../api/index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.error) { console.error(j.error); return; }
                var d = j.results;
                userUpvotes = d.user_upvotes || [];

                // Source filter
                var sel = document.getElementById('fbFilterSource');
                var cur = sel.value;
                sel.innerHTML = '<option value="">All Sources</option>';
                (d.source_apps || []).forEach(function (app) {
                    var o = document.createElement('option');
                    o.value = app; o.textContent = app;
                    if (app === cur) o.selected = true;
                    sel.appendChild(o);
                });

                // Stats badges
                var s = d.stats || {};
                document.getElementById('statTotal').textContent    = 'Total: ' + (s.total || 0);
                document.getElementById('statNew').textContent      = 'New: ' + (s.new || 0);
                document.getElementById('statReviewed').textContent = 'Reviewed: ' + (s.reviewed || 0);
                document.getElementById('statGrouped').textContent  = 'Grouped: ' + (s.grouped || 0);
                document.getElementById('statDismissed').textContent= 'Dismissed: ' + (s.dismissed || 0);
                document.getElementById('badgeNew').textContent     = s.new || 0;

                // Table
                var tbody = document.getElementById('fbTableBody');
                if (!d.items || d.items.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="7" class="text-muted text-center py-4">No feedback found.</td></tr>';
                } else {
                    fbData = {};
                    tbody.innerHTML = d.items.map(function (row) {
                        fbData[row.feedback_id] = row;
                        var typeBadges = {
                            bug:        '<span class="badge bg-danger">Bug</span>',
                            suggestion: '<span class="badge bg-primary">Suggestion</span>',
                            general:    '<span class="badge bg-secondary">General</span>'
                        };
                        var statusBadges = {
                            'new':       '<span class="badge bg-info">New</span>',
                            reviewed:    '<span class="badge bg-secondary">Reviewed</span>',
                            grouped:     '<span class="badge bg-warning text-dark">Grouped</span>',
                            dismissed:   '<span class="badge bg-dark">Dismissed</span>'
                        };

                        var isUpvoted = userUpvotes.indexOf(parseInt(row.feedback_id)) !== -1;
                        var upBtn = '<button class="btn btn-sm ' + (isUpvoted ? 'btn-warning' : 'btn-outline-warning') + '" onclick="doUpvote(' + row.feedback_id + ')" title="Upvote"><i class="bi bi-hand-thumbs-up"></i></button>';
                        var msg = esc(row.message);
                        if (msg.length > 120) msg = msg.substring(0, 120) + '...';

                        var actions = upBtn + ' ';
                        if (row.status !== 'dismissed') {
                            actions += '<button class="btn btn-sm btn-outline-secondary" onclick="openGroupModal(' + row.feedback_id + ')" title="Group"><i class="bi bi-link-45deg"></i></button> ';
                            actions += '<button class="btn btn-sm btn-outline-success" onclick="openCRFromFeedback(' + row.feedback_id + ')" title="Create CR"><i class="bi bi-arrow-up-circle"></i></button> ';
                            actions += '<button class="btn btn-sm btn-outline-dark" onclick="doDismiss(' + row.feedback_id + ')" title="Dismiss"><i class="bi bi-x-lg"></i></button>';
                        }

                        return '<tr>' +
                            '<td>' + (typeBadges[row.feedback_type] || '') + '</td>' +
                            '<td><small>' + msg + '</small>' + (row.page_url ? '<br><a href="' + esc(row.page_url) + '" class="text-muted small" target="_blank">' + esc(row.page_url).substring(0, 50) + '</a>' : '') + '</td>' +
                            '<td><small>' + esc(row.user_email || '—') + '<br><span class="text-muted">' + esc(row.user_role || '') + '</span></small></td>' +
                            '<td class="text-center"><span class="badge bg-light text-dark">' + (row.upvotes || 0) + '</span></td>' +
                            '<td>' + (statusBadges[row.status] || '') + (row.change_request_id ? ' <button class="badge bg-warning text-dark border-0 ms-1 p-1" style="cursor:pointer" onclick="viewCRDetail(' + row.change_request_id + ')" title="View change request">CR #' + row.change_request_id + '</button>' : '') + '</td>' +
                            '<td><small>' + (row.created ? row.created.substring(0, 16) : '') + '</small></td>' +
                            '<td class="text-end text-nowrap">' + actions + '</td>' +
                            '</tr>';
                    }).join('');
                }

                // Pagination
                var totalPages = Math.ceil(d.total / d.per_page);
                var start = ((d.page - 1) * d.per_page) + 1;
                var end = Math.min(d.page * d.per_page, d.total);
                document.getElementById('fbPagInfo').textContent = d.total > 0 ? start + '-' + end + ' of ' + d.total : '';
                var pEl = document.getElementById('fbPagPages');
                pEl.innerHTML = '';
                for (var p = 1; p <= Math.min(totalPages, 10); p++) {
                    var li = document.createElement('li');
                    li.className = 'page-item' + (p === d.page ? ' active' : '');
                    li.innerHTML = '<button class="page-link" onclick="loadFeedback(' + p + ')">' + p + '</button>';
                    pEl.appendChild(li);
                }
            });
    };

    // ── Feedback actions ────────────────────────────────────

    window.doUpvote = function (fid) {
        var fd = new FormData();
        fd.append('action', 'upvoteFeedback');
        fd.append('feedback_id', fid);
        fetch('../api/index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (!j.error) loadFeedback(fbPage); });
    };

    window.doDismiss = function (fid) {
        var fd = new FormData();
        fd.append('action', 'dismissFeedback');
        fd.append('feedback_id', fid);
        fetch('../api/index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (!j.error) loadFeedback(fbPage); });
    };

    window.openCRFromFeedback = function (fid) {
        var row = fbData[fid] || {};
        document.getElementById('crEditId').value = '';
        document.getElementById('crFeedbackId').value = fid;
        document.getElementById('crTitle').value = '';
        document.getElementById('crDescription').value = row.message || '';
        document.getElementById('crType').value = 'change';
        document.getElementById('crPriority').value = 'medium';
        document.getElementById('crModalTitle').textContent = 'Create Change Request from Feedback';
        new bootstrap.Modal(document.getElementById('crModal')).show();
    };

    window.openGroupModal = function (fid) {
        document.getElementById('groupFeedbackId').value = fid;
        var sel = document.getElementById('groupCRSelect');
        sel.innerHTML = '<option value="">Loading...</option>';
        new bootstrap.Modal(document.getElementById('groupModal')).show();

        // Load change requests for dropdown
        var fd = new FormData();
        fd.append('action', 'getChangeRequests');
        fd.append('per_page', 100);
        fetch('../api/index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                sel.innerHTML = '<option value="">Select...</option>';
                (j.results.items || []).forEach(function (cr) {
                    var o = document.createElement('option');
                    o.value = cr.change_request_id;
                    o.textContent = '#' + cr.change_request_id + ' — ' + cr.title;
                    sel.appendChild(o);
                });
            });
    };

    window.confirmGroup = function () {
        var fid  = document.getElementById('groupFeedbackId').value;
        var crid = document.getElementById('groupCRSelect').value;
        if (!crid) return;
        var fd = new FormData();
        fd.append('action', 'groupFeedbackWithRequest');
        fd.append('feedback_id', fid);
        fd.append('change_request_id', crid);
        fetch('../api/index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                bootstrap.Modal.getInstance(document.getElementById('groupModal')).hide();
                if (!j.error) loadFeedback(fbPage);
            });
    };

    // ── Change Requests Tab ─────────────────────────────────

    var crLoaded = false;
    document.getElementById('crTabBtn').addEventListener('shown.bs.tab', function () {
        if (!crLoaded) { crLoaded = true; loadChangeRequests(1); }
    });

    window.loadChangeRequests = function (page) {
        crPage = page || 1;
        var fd = new FormData();
        fd.append('action', 'getChangeRequests');
        fd.append('page', crPage);
        if (document.getElementById('crFilterStatus').value)   fd.append('status',       document.getElementById('crFilterStatus').value);
        if (document.getElementById('crFilterType').value)     fd.append('request_type', document.getElementById('crFilterType').value);
        if (document.getElementById('crFilterPriority').value) fd.append('priority',     document.getElementById('crFilterPriority').value);

        fetch('../api/index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.error) return;
                var d = j.results;
                var tbody = document.getElementById('crTableBody');

                if (!d.items || d.items.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="7" class="text-muted text-center py-4">No change requests found.</td></tr>';
                } else {
                    tbody.innerHTML = d.items.map(function (cr) {
                        var typeBadge = cr.request_type === 'change'
                            ? '<span class="badge bg-info">Change</span>'
                            : '<span class="badge bg-success">Addition</span>';
                        var priBadges = {
                            critical: '<span class="badge bg-danger">Critical</span>',
                            high:     '<span class="badge bg-warning text-dark">High</span>',
                            medium:   '<span class="badge bg-secondary">Medium</span>',
                            low:      '<span class="badge bg-light text-dark">Low</span>'
                        };
                        var statusOpts = ['proposed','approved','in_progress','completed','paused','rejected'];
                        var statusSelect = '<select class="form-select form-select-sm" onchange="updateCRStatus(' + cr.change_request_id + ', this.value)" style="width:auto">';
                        statusOpts.forEach(function (s) {
                            statusSelect += '<option value="' + s + '"' + (cr.status === s ? ' selected' : '') + '>' + s.replace('_', ' ') + '</option>';
                        });
                        statusSelect += '</select>';

                        // Store CR data for edit (avoid inline JSON escaping issues)
                        window._crData = window._crData || {};
                        window._crData[cr.change_request_id] = cr;

                        // Show source app and page URLs from grouped feedback
                        var context = '';
                        if (cr.source_app) context += '<span class="badge bg-dark">' + esc(cr.source_app) + '</span> ';

                        return '<tr>' +
                            '<td>' + esc(cr.title) + (context ? '<br>' + context : '') + '</td>' +
                            '<td>' + typeBadge + '</td>' +
                            '<td>' + (priBadges[cr.priority] || '') + '</td>' +
                            '<td>' + statusSelect + '</td>' +
                            '<td class="text-center"><span class="badge bg-light text-dark" style="cursor:pointer" onclick="viewCRDetail(' + cr.change_request_id + ')" title="View grouped feedback">' + (cr.feedback_count || 0) + '</span></td>' +
                            '<td><small>' + (cr.updated || cr.created || '').substring(0, 16) + '</small></td>' +
                            '<td class="text-end text-nowrap">' +
                                '<button class="btn btn-sm btn-outline-secondary" onclick="editCR(' + cr.change_request_id + ')" title="Edit"><i class="bi bi-pencil"></i></button>' +
                            '</td></tr>';
                    }).join('');
                }

                // Pagination
                var totalPages = Math.ceil(d.total / d.per_page);
                var start = ((d.page - 1) * d.per_page) + 1;
                var end = Math.min(d.page * d.per_page, d.total);
                document.getElementById('crPagInfo').textContent = d.total > 0 ? start + '-' + end + ' of ' + d.total : '';
                var pEl = document.getElementById('crPagPages');
                pEl.innerHTML = '';
                for (var p = 1; p <= Math.min(totalPages, 10); p++) {
                    var li = document.createElement('li');
                    li.className = 'page-item' + (p === crPage ? ' active' : '');
                    li.innerHTML = '<button class="page-link" onclick="loadChangeRequests(' + p + ')">' + p + '</button>';
                    pEl.appendChild(li);
                }
            });
    };

    window.updateCRStatus = function (crid, status) {
        var fd = new FormData();
        fd.append('action', 'updateChangeRequest');
        fd.append('change_request_id', crid);
        fd.append('status', status);
        fetch('../api/index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (j.error) alert(j.error); });
    };

    window.openCRModal = function () {
        document.getElementById('crEditId').value = '';
        document.getElementById('crFeedbackId').value = '';
        document.getElementById('crTitle').value = '';
        document.getElementById('crDescription').value = '';
        document.getElementById('crType').value = 'change';
        document.getElementById('crPriority').value = 'medium';
        document.getElementById('crModalTitle').textContent = 'New Change Request';
        new bootstrap.Modal(document.getElementById('crModal')).show();
    };

    window.editCR = function (crid) {
        var data = (window._crData && window._crData[crid]) || {};
        document.getElementById('crEditId').value = crid;
        document.getElementById('crFeedbackId').value = '';
        document.getElementById('crTitle').value = data.title || '';
        document.getElementById('crDescription').value = data.description || '';
        document.getElementById('crType').value = data.request_type || 'change';
        document.getElementById('crPriority').value = data.priority || 'medium';
        document.getElementById('crModalTitle').textContent = 'Edit Change Request';
        new bootstrap.Modal(document.getElementById('crModal')).show();
    };

    window.saveCR = function () {
        var editId    = document.getElementById('crEditId').value;
        var feedbackId = document.getElementById('crFeedbackId').value;
        var fd = new FormData();

        if (editId) {
            fd.append('action', 'updateChangeRequest');
            fd.append('change_request_id', editId);
        } else {
            fd.append('action', 'createChangeRequest');
            if (feedbackId) fd.append('feedback_id', feedbackId);
        }

        fd.append('title',        document.getElementById('crTitle').value);
        fd.append('description',  document.getElementById('crDescription').value);
        fd.append('request_type', document.getElementById('crType').value);
        fd.append('priority',     document.getElementById('crPriority').value);

        fetch('../api/index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                bootstrap.Modal.getInstance(document.getElementById('crModal')).hide();
                if (j.error) { alert(j.error); return; }
                loadChangeRequests(crPage);
                if (feedbackId) loadFeedback(fbPage);
            });
    };

    // ── CR Detail (with grouped feedback) ────────────────────

    window.viewCRDetail = function (crid) {
        document.getElementById('crDetailLabel').textContent = 'Change Request #' + crid;
        document.getElementById('crDetailBody').innerHTML = '<p class="text-muted text-center py-3">Loading...</p>';
        new bootstrap.Modal(document.getElementById('crDetailModal')).show();

        var fd = new FormData();
        fd.append('action', 'getFeedbackData');
        fd.append('change_request_id', crid);
        fd.append('per_page', 50);
        fetch('../api/index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.error) {
                    document.getElementById('crDetailBody').innerHTML = '<p class="text-danger">' + esc(j.error) + '</p>';
                    return;
                }
                var items = (j.results && j.results.items) || [];
                if (items.length === 0) {
                    document.getElementById('crDetailBody').innerHTML = '<p class="text-muted text-center py-3">No feedback grouped to this change request.</p>';
                } else {
                    var typeBadges = {
                        bug:        '<span class="badge bg-danger">Bug</span>',
                        suggestion: '<span class="badge bg-primary">Suggestion</span>',
                        general:    '<span class="badge bg-secondary">General</span>'
                    };
                    // Collect unique page URLs from feedback for context
                    var pageUrls = [];
                    items.forEach(function (row) {
                        if (row.page_url && pageUrls.indexOf(row.page_url) === -1) pageUrls.push(row.page_url);
                    });

                    var html = '';
                    if (pageUrls.length > 0) {
                        html += '<div class="mb-3"><strong>Affected pages:</strong><br>';
                        pageUrls.forEach(function (url) {
                            html += '<a href="' + esc(url) + '" target="_blank" class="small text-info d-block">' + esc(url) + '</a>';
                        });
                        html += '</div>';
                    }

                    html += '<table class="table table-sm align-middle mb-0"><thead><tr><th style="width:80px">Type</th><th>Message</th><th style="width:120px">User</th><th style="width:80px">Page</th><th style="width:50px" class="text-center">Votes</th><th style="width:100px">Date</th></tr></thead><tbody>';
                    items.forEach(function (row) {
                        var msg = esc(row.message);
                        if (msg.length > 120) msg = msg.substring(0, 120) + '...';
                        var pageLink = row.page_url ? '<a href="' + esc(row.page_url) + '" target="_blank" class="small text-muted" title="' + esc(row.page_url) + '">' + esc(row.page_url).split('?page=').pop().split('&')[0] || 'link' + '</a>' : '—';
                        html += '<tr>' +
                            '<td>' + (typeBadges[row.feedback_type] || '') + '</td>' +
                            '<td><small>' + msg + '</small></td>' +
                            '<td><small>' + esc(row.user_email || '—') + '</small></td>' +
                            '<td><small>' + pageLink + '</small></td>' +
                            '<td class="text-center"><span class="badge bg-light text-dark">' + (row.upvotes || 0) + '</span></td>' +
                            '<td><small>' + (row.created || '').substring(0, 10) + '</small></td>' +
                            '</tr>';
                    });
                    html += '</tbody></table>';
                    document.getElementById('crDetailBody').innerHTML = html;
                }
            });
    };

    // ── Helpers ──────────────────────────────────────────────

    function esc(str) {
        if (str === null || str === undefined) return '';
        var div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }

    // Initial load
    loadFeedback(1);

})();
</script>
