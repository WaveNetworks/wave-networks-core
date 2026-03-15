<?php
include(__DIR__ . '/../include/common_auth.php');

// Must be logged in and have reconsent needed
if (empty($_SESSION['user_id']) || empty($_SESSION['reconsent_needed'])) {
    header('Location: login.php');
    exit;
}

$reconsent = $_SESSION['reconsent_needed'];
$page_title = 'Updated Policies';
ob_start();
?>

<h4 class="card-title mb-3">Updated Policies</h4>

<p class="text-muted">We've updated our policies. Please review and accept to continue.</p>

<form method="post" action="">
    <input type="hidden" name="action" value="acceptReconsent">

    <?php foreach ($reconsent as $type => $version) { ?>
    <div class="card mb-3">
        <div class="card-body">
            <h6><?= h(ucwords(str_replace('_', ' ', $type))) ?></h6>
            <p class="small text-muted mb-2">
                Version <?= h($version['version_label']) ?> — Effective <?= h(date('M j, Y', strtotime($version['effective_date']))) ?>
            </p>
            <?php if (!empty($version['summary'])) { ?>
            <p class="small"><?= h($version['summary']) ?></p>
            <?php } ?>
            <?php if (!empty($version['content'])) { ?>
            <div class="border rounded p-2 small" style="max-height:200px;overflow-y:auto;">
                <?= $version['content'] ?>
            </div>
            <?php } ?>
        </div>
    </div>
    <?php } ?>

    <div class="mb-3 form-check">
        <input type="checkbox" class="form-check-input" id="accept_updated" name="accept_updated" value="1" required>
        <label class="form-check-label" for="accept_updated">
            I have read and accept the updated policies
        </label>
    </div>

    <button type="submit" class="btn btn-primary w-100">Accept & Continue</button>
</form>

<div class="text-center mt-3">
    <a href="login.php?action=logout" class="small text-muted">Log out instead</a>
</div>

<?php
$page_content_html = ob_get_clean();
include(__DIR__ . '/template.php');
