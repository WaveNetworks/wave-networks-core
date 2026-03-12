<?php
include(__DIR__ . '/../include/common_auth.php');

// Check registration mode
$settings = db_fetch(db_query("SELECT registration_mode FROM auth_settings WHERE setting_id = 1"));
$mode = $settings['registration_mode'] ?? 'open';

if ($mode === 'closed') {
    $_SESSION['error'] = 'Registration is currently closed.';
    header('Location: login.php');
    exit;
}

if ($mode === 'invite') {
    header('Location: login.php');
    exit;
}

$page_title = 'Register';
ob_start();
?>

<h4 class="card-title mb-3">Create Account</h4>

<form method="post" action="">
    <input type="hidden" name="action" value="register">

    <div class="row mb-3">
        <div class="col">
            <label for="first_name" class="form-label">First Name</label>
            <input type="text" class="form-control" id="first_name" name="first_name" value="<?= h($_POST['first_name'] ?? '') ?>">
        </div>
        <div class="col">
            <label for="last_name" class="form-label">Last Name</label>
            <input type="text" class="form-control" id="last_name" name="last_name" value="<?= h($_POST['last_name'] ?? '') ?>">
        </div>
    </div>

    <div class="mb-3">
        <label for="email" class="form-label">Email Address</label>
        <input type="email" class="form-control" id="email" name="email" value="<?= h($_POST['email'] ?? '') ?>" required>
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

    <?php if (recaptcha_enabled()) { ?>
    <div class="mb-3">
        <div class="g-recaptcha" data-sitekey="<?= h(recaptcha_site_key()) ?>"></div>
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    </div>
    <?php } ?>

    <button type="submit" class="btn btn-primary w-100">Create Account</button>
</form>

<div class="text-center mt-3">
    <span class="small">Already have an account? <a href="login.php">Sign in</a></span>
</div>

<?php
$page_content_html = ob_get_clean();
include(__DIR__ . '/template.php');
