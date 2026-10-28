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

<?php
// "Use a passkey instead" when the account has one (WN_PASSKEY, task #2661).
$wn_pk = __DIR__ . '/../snippets/passkey_signin.php';
if (file_exists($wn_pk)) { include($wn_pk); }
// "Can't use any of these?" — a code to the verified email, last resort (core, task #2660).
$wn_fb = __DIR__ . '/../snippets/twofa_email_fallback.php';
if (file_exists($wn_fb)) { include($wn_fb); }
?>

<div class="text-center mt-3">
    <a href="login.php" class="small">Cancel</a>
</div>

<?php
$page_content_html = ob_get_clean();
include(__DIR__ . '/template.php');
