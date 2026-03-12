<?php
include(__DIR__ . '/../include/common_auth.php');

// Must have pending 2FA
if (empty($_SESSION['2fa_pending'])) {
    header('Location: login.php');
    exit;
}

$page_title = 'Two-Factor Authentication';
ob_start();
?>

<h4 class="card-title mb-3">Authentication Code</h4>

<p class="text-muted small">Enter the code from your authenticator app.</p>

<form method="post" action="">
    <input type="hidden" name="action" value="verify2FA">

    <div class="mb-3">
        <label for="totp_code" class="form-label">6-Digit Code</label>
        <input type="text" class="form-control text-center" id="totp_code" name="totp_code"
               maxlength="6" pattern="\d{6}" inputmode="numeric" autocomplete="one-time-code"
               required autofocus style="font-size: 1.5em; letter-spacing: 0.5em;">
    </div>

    <button type="submit" class="btn btn-primary w-100">Verify</button>
</form>

<div class="text-center mt-3">
    <a href="login.php" class="small">Cancel</a>
</div>

<?php
$page_content_html = ob_get_clean();
include(__DIR__ . '/template.php');
