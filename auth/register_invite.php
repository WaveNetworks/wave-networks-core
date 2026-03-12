<?php
include(__DIR__ . '/../include/common_auth.php');

$invite_token = $_GET['token'] ?? '';
if (!$invite_token) {
    $_SESSION['error'] = 'Invalid invite link.';
    header('Location: login.php');
    exit;
}

// Verify invite token
$safe_token = sanitize($invite_token, SQL);
$r = db_query("SELECT * FROM invite WHERE invite_token = '$safe_token' AND used = 0");
$invite = db_fetch($r);

if (!$invite) {
    $_SESSION['error'] = 'Invalid or already used invite link.';
    header('Location: login.php');
    exit;
}

$page_title = 'Register via Invite';
ob_start();
?>

<h4 class="card-title mb-3">Accept Invite</h4>

<?php if ($invite['email']) { ?>
<p class="text-muted small">You've been invited to join. Please complete your registration.</p>
<?php } ?>

<form method="post" action="">
    <input type="hidden" name="action" value="registerInvite">
    <input type="hidden" name="invite_token" value="<?= h($invite_token) ?>">

    <div class="row mb-3">
        <div class="col">
            <label for="first_name" class="form-label">First Name</label>
            <input type="text" class="form-control" id="first_name" name="first_name" value="">
        </div>
        <div class="col">
            <label for="last_name" class="form-label">Last Name</label>
            <input type="text" class="form-control" id="last_name" name="last_name" value="">
        </div>
    </div>

    <div class="mb-3">
        <label for="email" class="form-label">Email Address</label>
        <input type="email" class="form-control" id="email" name="email"
               value="<?= h($invite['email'] ?? '') ?>"
               <?= $invite['email'] ? 'readonly' : '' ?> required>
    </div>

    <div class="mb-3">
        <label for="password" class="form-label">Password</label>
        <input type="password" class="form-control" id="password" name="password" required>
        <div class="form-text">Minimum 8 characters</div>
    </div>

    <div class="mb-3">
        <label for="confirm_password" class="form-label">Confirm Password</label>
        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
    </div>

    <button type="submit" class="btn btn-primary w-100">Create Account</button>
</form>

<?php
$page_content_html = ob_get_clean();
include(__DIR__ . '/template.php');
