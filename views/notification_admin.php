<?php
/**
 * views/notification_admin.php
 * Admin notification management — categories CRUD + broadcast sending.
 */
$page_title = 'Notification Admin';
$categories = get_notification_categories();

// Push / VAPID setup state
$vapid_pub_now = function_exists('get_vapid_public_key') ? get_vapid_public_key() : '';
$vapid_subject_now = $vapid_subject ?? '';
$vapid_env_managed = function_exists('vapid_is_env_managed') ? vapid_is_env_managed() : false;
$vapid_writable    = function_exists('vapid_config_writable') ? vapid_config_writable() : false;
$vapid_configured  = !empty($vapid_pub_now);
?>

<h4 class="mb-4">Notification Management</h4>

<!-- Push Setup (VAPID) -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-broadcast-pin me-1"></i> Web Push Setup (VAPID)</h6>
        <span class="badge <?= $vapid_configured ? 'bg-success' : 'bg-secondary' ?>" id="vapidStatusBadge">
            <?= $vapid_configured ? 'VAPID configured' : 'Not configured' ?>
        </span>
    </div>
    <div class="card-body">
        <?php if ($vapid_env_managed) { ?>
            <div class="alert alert-info mb-0">
                <i class="bi bi-info-circle me-1"></i>
                <strong>Configured via environment.</strong>
                VAPID keys are managed by the container environment (<code>VAPID_PUBLIC_KEY</code>, <code>VAPID_PRIVATE_KEY</code>, <code>VAPID_SUBJECT</code>).
                Update them in your Docker / container env to rotate.
            </div>
        <?php } else { ?>
            <p class="small text-muted mb-3">
                Web Push needs a VAPID key pair signed by a contact (subject) URL.
                Generate them once here — keys are written to
                <code>admin/config/notifications_config.php</code> (gitignored) and the new public
                key becomes available to <code>get_vapid_public_key()</code> immediately.
            </p>

            <?php if (!$vapid_writable) { ?>
                <div class="alert alert-warning small mb-3">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    The config directory <code><?= h(realpath(__DIR__ . '/../config') ?: __DIR__ . '/../config') ?></code>
                    is not writable by PHP. Generate will return the keys on screen for you to paste in by hand.
                </div>
            <?php } ?>

            <form id="vapidGenerateForm" onsubmit="generateVapidKeys(event)">
                <div class="row g-2 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label small mb-1">Subject (mailto:) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" name="vapid_subject"
                               id="vapidSubject"
                               value="<?= h($vapid_subject_now) ?>"
                               placeholder="mailto:admin@yourdomain.com" required>
                        <div class="form-text small">Push services use this to contact you about delivery issues.</div>
                    </div>
                    <div class="col-md-6 d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-sm btn-primary" id="vapidGenerateBtn">
                            <i class="bi bi-key"></i>
                            <?= $vapid_configured ? 'Rotate Keys' : 'Generate VAPID Keys' ?>
                        </button>
                        <?php if ($vapid_configured) { ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="saveVapidSubject()">
                            <i class="bi bi-check-lg"></i> Save Subject Only
                        </button>
                        <?php } ?>
                    </div>
                </div>
            </form>

            <?php if ($vapid_configured) { ?>
                <hr>
                <p class="small mb-1"><strong>Current public key</strong></p>
                <code class="d-block small p-2 bg-light rounded text-break"><?= h($vapid_pub_now) ?></code>
                <p class="small text-muted mt-2 mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    Rotating keys invalidates all existing browser push subscriptions —
                    they'll be cleaned up automatically the next time we try to send to them
                    (the push service returns <code>410 Gone</code>). Users may need to
                    re-enable push from their notification preferences.
                </p>
            <?php } ?>

            <!-- Result panel for read-only file systems -->
            <div id="vapidPasteFallback" class="mt-3 d-none">
                <div class="alert alert-warning small mb-2">
                    Keys generated but the config file could not be written. Copy the snippet below into <code><?= h(__DIR__ . '/../config/notifications_config.php') ?></code> by hand, then bounce PHP-FPM.
                </div>
                <textarea class="form-control form-control-sm font-monospace" rows="6" id="vapidPasteSnippet" readonly></textarea>
                <button class="btn btn-sm btn-outline-secondary mt-2" onclick="copyVapidSnippet()">
                    <i class="bi bi-clipboard"></i> Copy snippet
                </button>
            </div>
        <?php } ?>
    </div>
</div>

<div class="row">
    <!-- Send Broadcast -->
    <div class="col-lg-5 mb-4">
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-megaphone me-1"></i> Send Broadcast</h6></div>
            <div class="card-body">
                <form id="broadcastForm" onsubmit="sendBroadcast(event)">
                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <select class="form-select" name="category_slug">
                            <?php foreach ($categories as $cat) { ?>
                            <option value="<?= h($cat['slug']) ?>" <?= $cat['slug'] === 'admin_broadcast' ? 'selected' : '' ?>>
                                <?= h($cat['name']) ?>
                            </option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="title" required maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Message <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="body" rows="3" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Action URL <span class="text-muted small">(optional)</span></label>
                        <input type="url" class="form-control" name="action_url" id="broadcastActionUrl" placeholder="https://...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Action Label <span class="text-muted small">(optional)</span></label>
                        <input type="text" class="form-control" name="action_label" placeholder="View Details" maxlength="100">
                    </div>
                    <button type="submit" class="btn btn-primary w-100" id="broadcastBtn">
                        <i class="bi bi-send"></i> Send Broadcast
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Categories -->
    <div class="col-lg-7 mb-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-tags me-1"></i> Notification Categories</h6>
                <button class="btn btn-sm btn-primary" onclick="showCategoryModal()">
                    <i class="bi bi-plus"></i> Add
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Slug</th>
                            <th>Default</th>
                            <th>Override</th>
                            <th>Source</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="categoriesTable">
                        <?php foreach ($categories as $cat) { ?>
                        <tr>
                            <td>
                                <i class="bi <?= h($cat['icon']) ?> me-1"></i>
                                <?= h($cat['name']) ?>
                                <?php if ($cat['is_system']) { ?>
                                <span class="badge bg-secondary">System</span>
                                <?php } ?>
                            </td>
                            <td><code><?= h($cat['slug']) ?></code></td>
                            <td><span class="badge bg-info"><?= h($cat['default_frequency']) ?></span></td>
                            <td><?= $cat['allow_frequency_override'] ? '<i class="bi bi-check text-success"></i>' : '<i class="bi bi-x text-danger"></i>' ?></td>
                            <td class="small text-muted"><?= h($cat['created_by_app'] ?? 'core') ?></td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-secondary" onclick='editCategory(<?= htmlspecialchars(json_encode($cat), ENT_QUOTES, "UTF-8") ?>)'>
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <?php if (!$cat['is_system']) { ?>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteCategory(<?= (int)$cat['category_id'] ?>, '<?= h($cat['name']) ?>')">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php } ?>
                            </td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Category Modal -->
<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="categoryForm" onsubmit="saveCategory(event)">
                <div class="modal-header">
                    <h5 class="modal-title" id="categoryModalTitle">Add Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="category_id" id="catId" value="0">
                    <div class="mb-3">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" id="catName" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Slug <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="slug" id="catSlug" required pattern="[a-z0-9_]+"
                               placeholder="lowercase_with_underscores">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="catDesc" rows="2"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Icon (Bootstrap Icons)</label>
                            <input type="text" class="form-control" name="icon" id="catIcon" value="bi-bell" placeholder="bi-bell">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Default Frequency</label>
                            <select class="form-select" name="default_frequency" id="catFreq">
                                <option value="realtime">Realtime</option>
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="off">Off</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="allow_frequency_override" id="catOverride" checked>
                        <label class="form-check-label" for="catOverride">Allow users to change frequency</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Auto-prefix bare emails (mailto:) and bare URLs/domains (https://) so the
// browser and server don't reject schemeless input on this page.
function wnNormalizeUrlMailto(v) {
    v = (v || '').trim();
    if (!v) return v;
    if (/^(mailto:|tel:|https?:\/\/)/i.test(v)) return v;
    if (/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v)) return 'mailto:' + v;
    return 'https://' + v;
}
['vapidSubject', 'broadcastActionUrl'].forEach(function(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('blur', function() {
        var n = wnNormalizeUrlMailto(el.value);
        if (n !== el.value) { el.value = n; }
    });
});

function sendBroadcast(e) {
    e.preventDefault();
    var form = document.getElementById('broadcastForm');
    var btn = document.getElementById('broadcastBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending...';

    var data = {};
    new FormData(form).forEach(function(v, k) { data[k] = v; });
    if (data.action_url) { data.action_url = wnNormalizeUrlMailto(data.action_url); }

    apiPost('sendBroadcastNotification', data, function(json) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send"></i> Send Broadcast';
        if (!json.error) {
            form.reset();
        }
    });
}

function showCategoryModal() {
    document.getElementById('categoryModalTitle').textContent = 'Add Category';
    document.getElementById('catId').value = '0';
    document.getElementById('catName').value = '';
    document.getElementById('catSlug').value = '';
    document.getElementById('catSlug').readOnly = false;
    document.getElementById('catDesc').value = '';
    document.getElementById('catIcon').value = 'bi-bell';
    document.getElementById('catFreq').value = 'realtime';
    document.getElementById('catOverride').checked = true;
    new bootstrap.Modal(document.getElementById('categoryModal')).show();
}

function editCategory(cat) {
    document.getElementById('categoryModalTitle').textContent = 'Edit Category';
    document.getElementById('catId').value = cat.category_id;
    document.getElementById('catName').value = cat.name;
    document.getElementById('catSlug').value = cat.slug;
    document.getElementById('catSlug').readOnly = cat.is_system == 1;
    document.getElementById('catDesc').value = cat.description || '';
    document.getElementById('catIcon').value = cat.icon;
    document.getElementById('catFreq').value = cat.default_frequency;
    document.getElementById('catOverride').checked = cat.allow_frequency_override == 1;
    new bootstrap.Modal(document.getElementById('categoryModal')).show();
}

function saveCategory(e) {
    e.preventDefault();
    var data = {};
    new FormData(document.getElementById('categoryForm')).forEach(function(v, k) { data[k] = v; });
    data.allow_frequency_override = document.getElementById('catOverride').checked ? 1 : 0;

    apiPost('saveNotificationCategory', data, function(json) {
        if (!json.error) {
            bootstrap.Modal.getInstance(document.getElementById('categoryModal')).hide();
            location.reload();
        }
    });
}

function deleteCategory(id, name) {
    if (!confirm('Delete category "' + name + '"? Existing notifications will keep their category slug but lose metadata.')) return;

    apiPost('deleteNotificationCategory', { category_id: id }, function(json) {
        if (!json.error) {
            location.reload();
        }
    });
}

// ─── VAPID / Push Setup ─────────────────────────────────────────────────────

function generateVapidKeys(e) {
    e.preventDefault();
    var subject = wnNormalizeUrlMailto(document.getElementById('vapidSubject').value);
    if (!subject) { return; }

    var btn = document.getElementById('vapidGenerateBtn');
    var alreadyConfigured = btn.textContent.indexOf('Rotate') !== -1;
    if (alreadyConfigured && !confirm(
        'Rotate VAPID keys?\n\n' +
        'All existing browser push subscriptions will become invalid. ' +
        'They self-clean via 410-Gone on the next send, but users may need to ' +
        're-enable push from notification preferences.\n\n' +
        'Continue?'
    )) { return; }

    btn.disabled = true;
    var originalHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Generating…';

    apiPost('generateVapidKeys', { vapid_subject: subject }, function(json) {
        btn.disabled = false;
        btn.innerHTML = originalHtml;

        if (json.error) { return; }

        var r = json.results || {};
        if (r.saved === false && r.paste_snippet) {
            // Read-only FS path — show paste fallback
            document.getElementById('vapidPasteSnippet').value = r.paste_snippet;
            document.getElementById('vapidPasteFallback').classList.remove('d-none');
        } else if (r.saved) {
            // Reload so the public-key block + status badge re-render with the
            // new bootstrap-loaded values.
            setTimeout(function(){ location.reload(); }, 600);
        }
    });
}

function saveVapidSubject() {
    var subject = wnNormalizeUrlMailto(document.getElementById('vapidSubject').value);
    if (!subject) { return; }
    apiPost('saveVapidSubject', { vapid_subject: subject }, function(json) {
        if (!json.error) {
            // No reload needed — just confirm via toast that bs-init shows.
        }
    });
}

function copyVapidSnippet() {
    var ta = document.getElementById('vapidPasteSnippet');
    ta.select();
    document.execCommand('copy');
}
</script>
