<?php
include(__DIR__ . '/../include/common_auth.php');

$token = $_GET['token'] ?? '';
if (!$token) {
    $_SESSION['error'] = 'Invalid reset link.';
    header('Location: login.php');
    exit;
}

// Verify token is valid
$safe_token = sanitize($token, SQL);
$r = db_query("SELECT * FROM forgot WHERE forgot_token = '$safe_token' AND used = 0 AND created > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$forgot = db_fetch($r);

if (!$forgot) {
    $_SESSION['error'] = 'Invalid or expired reset link.';
    header('Location: login.php');
    exit;
}

$page_title = 'Reset Password';
ob_start();
?>

<h4 class="card-title mb-3">Set New Password</h4>

<form method="post" action="">
    <input type="hidden" name="action" value="resetPassword">
    <input type="hidden" name="token" value="<?= h($token) ?>">

    <div class="mb-3">
        <label for="password" class="form-label">New Password</label>
        <input type="password" class="form-control" id="password" name="password" required>
        <div class="form-text">Minimum 8 characters</div>
    </div>

    <div class="mb-3">
        <label for="confirm_password" class="form-label">Confirm New Password</label>
        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
    </div>

    <button type="submit" class="btn btn-primary w-100">Reset Password</button>
</form>

<?php
$page_content_html = ob_get_clean();
include(__DIR__ . '/template.php');
