<?php
/**
 * views/onboarding_tours.php
 * Admin UI for managing onboarding tours: list, create/edit, delete, preview.
 */
$page_title = 'Onboarding Tours';

if (!has_role('admin')) {
    $_SESSION['error'] = 'Admin access required.';
    header('Location: index.php?page=dashboard');
    exit;
}

$tours = db_fetch_all(db_query("SELECT * FROM onboarding_tour ORDER BY is_active DESC, name ASC"));
$tours = $tours ?: [];

$editing = null;
$editingSteps = [];
if (!empty($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $editing = db_fetch(db_query("SELECT * FROM onboarding_tour WHERE tour_id = '$eid'"));
    if ($editing) {
        $editingSteps = db_fetch_all(db_query(
            "SELECT * FROM onboarding_tour_step WHERE tour_id = '$eid' ORDER BY step_order ASC, step_id ASC"
        )) ?: [];
    }
}
$creating = isset($_GET['new']);

$shard_id_self = $_SESSION['shard_id'] ?? '';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>Onboarding Tours</h3>
    <a href="index.php?page=onboarding_tours&amp;new=1" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>New tour
    </a>
</div>

<?php if (!$editing && !$creating) { ?>
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Slug</th>
                    <th>Status</th>
                    <th>Completion (this shard)</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tours)) { ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No tours yet. Click "New tour" to create one.</td></tr>
                <?php } foreach ($tours as $t) {
                    $stats = $shard_id_self ? get_tour_completion_stats($shard_id_self, $t['slug']) : ['completed'=>0,'in_progress'=>0,'skipped'=>0];
                ?>
                <tr>
                    <td><strong><?= h($t['name']) ?></strong></td>
                    <td><code><?= h($t['slug']) ?></code></td>
                    <td>
                        <?php if ($t['is_active']) { ?>
                        <span class="badge bg-success">Active</span>
                        <?php } else { ?>
                        <span class="badge bg-secondary">Inactive</span>
                        <?php } ?>
                    </td>
                    <td class="small">
                        <span class="text-success">✓ <?= (int)$stats['completed'] ?></span>
                        &nbsp;<span class="text-warning">… <?= (int)$stats['in_progress'] ?></span>
                        &nbsp;<span class="text-muted">↪ <?= (int)$stats['skipped'] ?></span>
                    </td>
                    <td class="text-end">
                        <a href="index.php?page=onboarding_tours&amp;edit=<?= (int)$t['tour_id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                        <a href="index.php?page=dashboard&amp;preview_tour=<?= h(urlencode($t['slug'])) ?>" class="btn btn-sm btn-outline-primary">Preview</a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this tour?');">
                            <input type="hidden" name="action" value="deleteOnboardingTour">
                            <input type="hidden" name="tour_id" value="<?= (int)$t['tour_id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>
<?php } else {
    $e = $editing ?: [
        'slug' => '', 'name' => '', 'welcome_title' => '', 'welcome_body_md' => '',
        'welcome_cta_primary' => 'Take the tour', 'welcome_cta_secondary' => 'Explore on my own',
        'is_active' => 1,
    ];
?>
<form method="post">
    <input type="hidden" name="action" value="saveOnboardingTour">

    <div class="card mb-4">
        <div class="card-header"><strong>Welcome modal</strong></div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Slug</label>
                    <input type="text" class="form-control" name="slug" value="<?= h($e['slug']) ?>" <?= $editing ? 'readonly' : '' ?> required pattern="[a-z0-9_-]+">
                    <div class="form-text">Lowercase letters, numbers, dashes/underscores. Used in URLs and as identifier.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Name (admin-facing)</label>
                    <input type="text" class="form-control" name="name" value="<?= h($e['name']) ?>" required>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Welcome title</label>
                <input type="text" class="form-control" name="welcome_title" value="<?= h($e['welcome_title']) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Welcome body (markdown)</label>
                <textarea class="form-control" name="welcome_body_md" rows="4"><?= h($e['welcome_body_md']) ?></textarea>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Primary CTA</label>
                    <input type="text" class="form-control" name="welcome_cta_primary" value="<?= h($e['welcome_cta_primary']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Secondary CTA</label>
                    <input type="text" class="form-control" name="welcome_cta_secondary" value="<?= h($e['welcome_cta_secondary']) ?>">
                </div>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_active" id="onb_is_active" value="1" <?= !empty($e['is_active']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="onb_is_active">Active (shown to users on first page load)</label>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Steps</strong>
            <button type="button" class="btn btn-sm btn-outline-primary" id="onbAddStep">
                <i class="bi bi-plus-lg me-1"></i>Add step
            </button>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">Drag rows to reorder. Each step highlights a DOM element by CSS selector.</p>
            <table class="table align-middle" id="onbStepsTable">
                <thead>
                    <tr>
                        <th style="width:30px;"></th>
                        <th>Selector</th>
                        <th>Title</th>
                        <th>Body (md)</th>
                        <th>Position</th>
                        <th>Visible to role</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="onbStepsBody">
                <?php if (!empty($editingSteps)) { foreach ($editingSteps as $st) { ?>
                    <tr draggable="true" class="onb-step-row">
                        <td class="text-muted text-center" style="cursor:move;"><i class="bi bi-grip-vertical"></i></td>
                        <td><input type="text" class="form-control form-control-sm" name="step_selector[]" value="<?= h($st['selector']) ?>"></td>
                        <td><input type="text" class="form-control form-control-sm" name="step_title[]" value="<?= h($st['title']) ?>"></td>
                        <td><textarea class="form-control form-control-sm" name="step_body[]" rows="2"><?= h($st['body_md']) ?></textarea></td>
                        <td>
                            <select class="form-select form-select-sm" name="step_position[]">
                                <?php foreach (['top','bottom','left','right','center'] as $p) { ?>
                                <option value="<?= $p ?>" <?= $st['position'] === $p ? 'selected' : '' ?>><?= $p ?></option>
                                <?php } ?>
                            </select>
                            <input type="hidden" name="step_action[]" value="<?= h($st['action'] ?? '') ?>">
                        </td>
                        <td><input type="text" class="form-control form-control-sm" name="step_role[]" value="<?= h($st['visible_if_role'] ?? '') ?>" placeholder="any"></td>
                        <td><button type="button" class="btn btn-sm btn-outline-danger onb-step-del">×</button></td>
                    </tr>
                <?php } } ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex justify-content-between mb-5">
        <a href="index.php?page=onboarding_tours" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">Save tour</button>
    </div>
</form>

<template id="onbStepTemplate">
    <tr draggable="true" class="onb-step-row">
        <td class="text-muted text-center" style="cursor:move;"><i class="bi bi-grip-vertical"></i></td>
        <td><input type="text" class="form-control form-control-sm" name="step_selector[]"></td>
        <td><input type="text" class="form-control form-control-sm" name="step_title[]"></td>
        <td><textarea class="form-control form-control-sm" name="step_body[]" rows="2"></textarea></td>
        <td>
            <select class="form-select form-select-sm" name="step_position[]">
                <option value="bottom">bottom</option>
                <option value="top">top</option>
                <option value="left">left</option>
                <option value="right">right</option>
                <option value="center">center</option>
            </select>
            <input type="hidden" name="step_action[]" value="">
        </td>
        <td><input type="text" class="form-control form-control-sm" name="step_role[]" placeholder="any"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger onb-step-del">×</button></td>
    </tr>
</template>

<script>
(function () {
    var body = document.getElementById('onbStepsBody');
    var tpl  = document.getElementById('onbStepTemplate');

    document.getElementById('onbAddStep').addEventListener('click', function () {
        body.appendChild(tpl.content.firstElementChild.cloneNode(true));
    });

    body.addEventListener('click', function (e) {
        if (e.target.classList.contains('onb-step-del')) {
            e.target.closest('tr').remove();
        }
    });

    var dragRow = null;
    body.addEventListener('dragstart', function (e) {
        var r = e.target.closest('.onb-step-row');
        if (!r) return;
        dragRow = r;
        e.dataTransfer.effectAllowed = 'move';
        r.classList.add('opacity-50');
    });
    body.addEventListener('dragend', function () {
        if (dragRow) dragRow.classList.remove('opacity-50');
        dragRow = null;
    });
    body.addEventListener('dragover', function (e) {
        e.preventDefault();
        var over = e.target.closest('.onb-step-row');
        if (!over || over === dragRow) return;
        var rect = over.getBoundingClientRect();
        var after = (e.clientY - rect.top) > rect.height / 2;
        if (after) over.parentNode.insertBefore(dragRow, over.nextSibling);
        else over.parentNode.insertBefore(dragRow, over);
    });
})();
</script>
<?php } ?>
