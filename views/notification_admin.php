<?php
/**
 * views/notification_admin.php
 * Admin notification management — categories CRUD + broadcast sending.
 */
$page_title = 'Notification Admin';
$categories = get_notification_categories();
?>

<h4 class="mb-4">Notification Management</h4>

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
                        <input type="url" class="form-control" name="action_url" placeholder="https://...">
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
function sendBroadcast(e) {
    e.preventDefault();
    var form = document.getElementById('broadcastForm');
    var btn = document.getElementById('broadcastBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending...';

    var data = {};
    new FormData(form).forEach(function(v, k) { data[k] = v; });

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
</script>
