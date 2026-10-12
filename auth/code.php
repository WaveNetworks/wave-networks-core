<?php
/**
 * Sign in without a password: email me a code (task #2389).
 * Step 1 asks for the email (requestLoginCode); step 2 takes the 6-digit code
 * (verifyLoginCode). The email's one-tap link lands here as ?e=&c= with the code
 * filled in — it never signs in on a GET, so a mail scanner opening it spends nothing.
 * Only served when the app opts in (WN_LOGIN_CODE); child apps copy this page.
 */
include(__DIR__ . '/../include/common_auth.php');

if (!wn_login_code_enabled()) { header('Location: login.php'); exit; }
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . (function_exists('get_post_login_home') ? get_post_login_home() : '../app/'));
    exit;
}

if (isset($_GET['change'])) { unset($_SESSION['login_code_email']); }
$_code_email = strtolower(trim($_GET['e'] ?? ($_SESSION['login_code_email'] ?? '')));
$_code_value = preg_replace('/\D/', '', $_GET['c'] ?? '');
$_have_email = valid_email($_code_email);

$page_title = 'Sign in without a password';
ob_start();
?>

<h4 class="card-title mb-3">Sign in without a password</h4>

<?php if (!$_have_email) { ?>
<p class="text-muted small">We'll email you a 6-digit code. Enter it here to sign in.</p>

<form method="post" action="">
    <input type="hidden" name="action" value="requestLoginCode">
    <div class="mb-3">
        <label for="email" class="form-label">Email Address</label>
        <input type="email" class="form-control" id="email" name="email" value="<?= h($_POST['email'] ?? '') ?>" required autofocus autocomplete="email">
    </div>
    <?php if (recaptcha_enabled()) { ?>
    <div class="mb-3">
        <div class="g-recaptcha" data-sitekey="<?= h(recaptcha_site_key()) ?>"></div>
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    </div>
    <?php } ?>
    <button type="submit" class="btn btn-primary w-100">Email me a sign-in code</button>
</form>
<?php } else { ?>
<p class="text-muted small">Enter the code we emailed to <strong><?= h($_code_email) ?></strong>.</p>

<form method="post" action="code.php">
    <input type="hidden" name="action" value="verifyLoginCode">
    <input type="hidden" name="email" value="<?= h($_code_email) ?>">
    <div class="mb-3">
        <label for="code" class="form-label">6-Digit Code</label>
        <input type="text" class="form-control text-center" id="code" name="code" value="<?= h($_code_value) ?>"
               maxlength="6" pattern="\d{6}" inputmode="numeric" autocomplete="one-time-code"
               required autofocus style="font-size: 1.5em; letter-spacing: 0.5em;">
    </div>
    <button type="submit" class="btn btn-primary w-100">Sign in</button>
</form>

<div class="text-center mt-3 small">
    <a href="code.php?change=1">Use a different email or get a new code</a>
</div>
<?php } ?>

<div class="text-center mt-2">
    <a href="login.php" class="small">Sign in with a password instead</a>
</div>

<?php
$page_content_html = ob_get_clean();
include(__DIR__ . '/template.php');
