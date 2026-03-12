<?php
/**
 * views/email.php
 * Admin email settings — SMTP, allowed senders, throttle, DNS info, queue monitor.
 */
$page_title = 'Email Settings';

if (!has_role('admin')) {
    $_SESSION['error'] = 'Admin access required.';
    header('Location: index.php?page=dashboard');
    exit;
}

$emailSettings = get_email_settings();
$senders       = get_allowed_senders();
$stats         = get_email_queue_stats();

// DNS info from default sender domain
$dns_info = null;
$sender_domain = '';
$default_from = $emailSettings['default_from_email'] ?? '';
if (!empty($default_from) && strpos($default_from, '@') !== false) {
    $sender_domain = substr($default_from, strpos($default_from, '@') + 1);
    $dns_info = get_email_dns_info($sender_domain);
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3><i class="bi bi-envelope me-2"></i>Email Settings</h3>
</div>

<!-- SMTP Configuration + Default Sender -->
<div class="row">
    <div class="col-lg-6 mb-4">
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-hdd-network me-1"></i> SMTP Configuration</h6></div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="saveEmailSettings">

                    <div class="row mb-3">
                        <div class="col-sm-8 mb-2 mb-sm-0">
                            <label class="form-label">SMTP Host</label>
                            <input type="text" class="form-control form-control-sm" name="smtp_host" value="<?= h($emailSettings['smtp_host']) ?>" placeholder="smtp.yourdomain.com">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label">Port</label>
                            <input type="number" class="form-control form-control-sm" name="smtp_port" value="<?= (int)$emailSettings['smtp_port'] ?>" placeholder="587">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-sm-6 mb-2 mb-sm-0">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control form-control-sm" name="smtp_user" value="<?= h($emailSettings['smtp_user']) ?>" autocomplete="off">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control form-control-sm" name="smtp_pass" value="" placeholder="<?= !empty($emailSettings['smtp_pass']) ? '••••••••' : '' ?>" autocomplete="new-password">
                            <div class="form-text">Leave blank to keep current.</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Encryption</label>
                        <select class="form-select form-select-sm" name="smtp_encryption">
                            <option value="tls" <?= ($emailSettings['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS (Port 587)</option>
                            <option value="ssl" <?= ($emailSettings['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL/TLS (Port 465)</option>
                            <option value="none" <?= ($emailSettings['smtp_encryption'] ?? '') === 'none' ? 'selected' : '' ?>>None</option>
                        </select>
                    </div>

                    <hr>
                    <h6 class="text-muted mb-3">Default Sender &amp; Reply-To</h6>

                    <div class="row mb-3">
                        <div class="col-sm-6 mb-2 mb-sm-0">
                            <label class="form-label">From Email</label>
                            <input type="email" class="form-control form-control-sm" name="default_from_email" value="<?= h($emailSettings['default_from_email']) ?>" placeholder="noreply@yourdomain.com">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">From Name</label>
                            <input type="text" class="form-control form-control-sm" name="default_from_name" value="<?= h($emailSettings['default_from_name']) ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Reply-To Email <span class="text-muted small">(optional)</span></label>
                        <input type="email" class="form-control form-control-sm" name="default_reply_to" value="<?= h($emailSettings['default_reply_to']) ?>" placeholder="support@yourdomain.com">
                    </div>

                    <hr>
                    <h6 class="text-muted mb-3">Throttle Settings</h6>

                    <div class="row mb-3">
                        <div class="col-4">
                            <label class="form-label">Per Min</label>
                            <input type="number" class="form-control form-control-sm" name="throttle_per_minute" value="<?= (int)$emailSettings['throttle_per_minute'] ?>" min="1">
                        </div>
                        <div class="col-4">
                            <label class="form-label">Per Hour</label>
                            <input type="number" class="form-control form-control-sm" name="throttle_per_hour" value="<?= (int)$emailSettings['throttle_per_hour'] ?>" min="1">
                        </div>
                        <div class="col-4">
                            <label class="form-label">Retries</label>
                            <input type="number" class="form-control form-control-sm" name="max_attempts" value="<?= (int)$emailSettings['max_attempts'] ?>" min="1" max="10">
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="bi bi-check-lg"></i> Save
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="testEmail()">
                            <i class="bi bi-send"></i> Test
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Allowed Senders + DNS Info -->
    <div class="col-lg-6 mb-4">
        <!-- Allowed Senders -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-person-check me-1"></i> Allowed Senders</h6>
                <button class="btn btn-sm btn-primary" onclick="showAddSender()">
                    <i class="bi bi-plus"></i> Add
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th>Name</th>
                            <th>Default</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="sendersTable">
                        <?php if (empty($senders)) { ?>
                        <tr><td colspan="4" class="text-muted text-center py-3">No senders configured — all addresses allowed.</td></tr>
                        <?php } ?>
                        <?php foreach ($senders as $s) { ?>
                        <tr>
                            <td><?= h($s['email_address']) ?></td>
                            <td><?= h($s['display_name']) ?></td>
                            <td>
                                <?php if ($s['is_default']) { ?>
                                <span class="badge bg-success">Default</span>
                                <?php } else { ?>
                                <button class="btn btn-sm btn-outline-secondary" onclick="setDefault(<?= (int)$s['sender_id'] ?>)">Set</button>
                                <?php } ?>
                            </td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteSender(<?= (int)$s['sender_id'] ?>, '<?= h($s['email_address']) ?>')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <!-- Inline add form (hidden by default) -->
            <div class="card-footer d-none" id="addSenderForm">
                <form onsubmit="addSender(event)">
                    <div class="row g-2 align-items-end">
                        <div class="col-sm-5 col-12">
                            <label class="form-label small">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control form-control-sm" name="email_address" id="newSenderEmail" required>
                        </div>
                        <div class="col-sm-3 col-12">
                            <label class="form-label small">Name</label>
                            <input type="text" class="form-control form-control-sm" name="display_name" id="newSenderName">
                        </div>
                        <div class="col-sm-2 col-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_default" id="newSenderDefault">
                                <label class="form-check-label small" for="newSenderDefault">Default</label>
                            </div>
                        </div>
                        <div class="col-sm-2 col-6">
                            <button type="submit" class="btn btn-sm btn-primary w-100">Add</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- DNS / Deliverability Info -->
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-shield-check me-1"></i> DNS / Deliverability</h6></div>
            <div class="card-body">
                <?php if (empty($sender_domain)) { ?>
                <p class="text-muted mb-0">Configure a default from email above to check DNS records.</p>
                <?php } else { ?>
                <p class="small text-muted mb-3">Checking records for <strong><?= h($sender_domain) ?></strong></p>

                <!-- SPF -->
                <div class="mb-3">
                    <h6>
                        <?php if (!empty($dns_info['spf'])) { ?>
                        <i class="bi bi-check-circle-fill text-success me-1"></i> SPF Record Found
                        <?php } else { ?>
                        <i class="bi bi-x-circle-fill text-danger me-1"></i> SPF Record Missing
                        <?php } ?>
                    </h6>
                    <?php if (!empty($dns_info['spf'])) { ?>
                    <code class="d-block small p-2 bg-light rounded" style="word-break: break-all;"><?= h($dns_info['spf']) ?></code>
                    <?php } else { ?>
                    <p class="small text-muted mb-0">Add a TXT record starting with <code>v=spf1</code> to your domain's DNS.</p>
                    <?php } ?>
                </div>

                <!-- DKIM -->
                <div>
                    <h6>
                        <?php if (!empty($dns_info['dkim'])) { ?>
                        <i class="bi bi-check-circle-fill text-success me-1"></i> DKIM Record(s) Found
                        <?php } else { ?>
                        <i class="bi bi-exclamation-triangle-fill text-warning me-1"></i> No DKIM Records Found
                        <?php } ?>
                    </h6>
                    <?php if (!empty($dns_info['dkim'])) { ?>
                        <?php foreach ($dns_info['dkim'] as $dkim) { ?>
                        <div class="mb-2">
                            <span class="badge bg-secondary"><?= h($dkim['selector']) ?></span>
                            <code class="d-block small mt-1 p-2 bg-light rounded" style="word-break: break-all;"><?= h($dkim['record']) ?></code>
                        </div>
                        <?php } ?>
                    <?php } else { ?>
                    <p class="small text-muted mb-0">Common selectors checked: default, google, selector1, selector2, mail, dkim, k1. Your DKIM selector may differ.</p>
                    <?php } ?>
                </div>
                <?php } ?>
            </div>
        </div>
    </div>
</div>

<!-- Queue Monitor -->
<div class="card mb-4">
    <div class="card-header">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h6 class="mb-0"><i class="bi bi-inbox me-1"></i> Email Queue</h6>
            <div class="d-flex flex-wrap gap-1 align-items-center">
                <span class="badge bg-primary"><?= $stats['queued'] ?> queued</span>
                <span class="badge bg-warning text-dark"><?= $stats['sending'] ?> sending</span>
                <span class="badge bg-success"><?= $stats['sent_today'] ?> sent today</span>
                <span class="badge bg-danger"><?= $stats['failed'] ?> failed</span>
                <span class="badge bg-info"><?= $stats['sent_hour'] ?>/hr</span>
                <select class="form-select form-select-sm" id="queueFilter" onchange="loadQueue()" style="width: auto;">
                    <option value="">All</option>
                    <option value="queued">Queued</option>
                    <option value="sent">Sent</option>
                    <option value="failed">Failed</option>
                </select>
            </div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
            <thead>
                <tr>
                    <th>To</th>
                    <th>Subject</th>
                    <th>Source</th>
                    <th>Status</th>
                    <th>Attempts</th>
                    <th>Created</th>
                    <th>Sent</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="queueTable">
                <tr><td colspan="8" class="text-center text-muted py-3">Loading...</td></tr>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center" id="queuePagination">
        <span id="queueInfo" class="text-muted small"></span>
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

function testEmail() {
    apiPost('testEmailSettings', {}, function(json) {});
}

function showAddSender() {
    document.getElementById('addSenderForm').classList.toggle('d-none');
    document.getElementById('newSenderEmail').focus();
}

function addSender(e) {
    e.preventDefault();
    var data = {
        email_address: document.getElementById('newSenderEmail').value,
        display_name: document.getElementById('newSenderName').value,
        is_default: document.getElementById('newSenderDefault').checked ? 1 : 0
    };
    apiPost('addAllowedSender', data, function(json) {
        if (!json.error) location.reload();
    });
}

function deleteSender(id, email) {
    if (!confirm('Remove "' + email + '" from allowed senders?')) return;
    apiPost('deleteAllowedSender', { sender_id: id }, function(json) {
        if (!json.error) location.reload();
    });
}

function setDefault(id) {
    apiPost('setDefaultSender', { sender_id: id }, function(json) {
        if (!json.error) location.reload();
    });
}

function loadQueue() {
    var filter = document.getElementById('queueFilter').value;
    apiPost('getQueueItems', { status: filter, page: currentPage, per_page: perPage }, function(json) {
        if (json.error) return;
        var items = json.results.items || [];
        totalItems = json.results.total || 0;
        var tbody = document.getElementById('queueTable');

        if (items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">No emails found.</td></tr>';
        } else {
            var html = '';
            items.forEach(function(item) {
                var badge = {queued:'bg-primary',sending:'bg-warning text-dark',sent:'bg-success',failed:'bg-danger'}[item.status] || 'bg-secondary';
                html += '<tr>';
                html += '<td class="small">' + escHtml(item.to_email) + '</td>';
                html += '<td class="small">' + escHtml(item.subject || '').substring(0, 60) + '</td>';
                html += '<td><span class="badge bg-light text-dark">' + escHtml(item.source_app) + '</span></td>';
                html += '<td><span class="badge ' + badge + '">' + escHtml(item.status) + '</span></td>';
                html += '<td class="small">' + item.attempts + '/' + item.max_attempts + '</td>';
                html += '<td class="small">' + escHtml(item.created || '') + '</td>';
                html += '<td class="small">' + escHtml(item.sent_at || '—') + '</td>';
                html += '<td class="text-end">';
                if (item.status === 'failed') {
                    html += '<button class="btn btn-sm btn-outline-warning me-1" onclick="retryEmail(' + item.queue_id + ')"><i class="bi bi-arrow-clockwise"></i></button>';
                }
                if (item.status === 'queued' || item.status === 'failed') {
                    html += '<button class="btn btn-sm btn-outline-danger" onclick="deleteEmail(' + item.queue_id + ')"><i class="bi bi-trash"></i></button>';
                }
                html += '</td>';
                html += '</tr>';
                if (item.status === 'failed' && item.error_message) {
                    html += '<tr><td colspan="8" class="small text-danger py-1 ps-4"><i class="bi bi-exclamation-triangle me-1"></i>' + escHtml(item.error_message) + '</td></tr>';
                }
            });
            tbody.innerHTML = html;
        }

        // Pagination info
        var start = ((currentPage - 1) * perPage) + 1;
        var end = Math.min(currentPage * perPage, totalItems);
        document.getElementById('queueInfo').textContent = totalItems > 0 ? start + '-' + end + ' of ' + totalItems : '';
        document.getElementById('prevPage').disabled = currentPage <= 1;
        document.getElementById('nextPage').disabled = end >= totalItems;
    });
}

function changePage(dir) {
    currentPage += dir;
    if (currentPage < 1) currentPage = 1;
    loadQueue();
}

function retryEmail(id) {
    apiPost('retryFailedEmail', { queue_id: id }, function(json) {
        if (!json.error) loadQueue();
    });
}

function deleteEmail(id) {
    if (!confirm('Delete this queue item?')) return;
    apiPost('deleteQueuedEmail', { queue_id: id }, function(json) {
        if (!json.error) loadQueue();
    });
}

function escHtml(str) {
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Load queue on page load
document.addEventListener('DOMContentLoaded', function() { loadQueue(); });
</script>
