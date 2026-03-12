<?php
include(__DIR__ . '/../include/common_auth.php');

// If already logged in, redirect to app
if (!empty($_SESSION['user_id'])) {
    header('Location: ../app/');
    exit;
}

$page_title = 'Login';
ob_start();
?>

<h4 class="card-title mb-3">Sign In</h4>

<form method="post" action="">
    <input type="hidden" name="action" value="login">

    <div class="mb-3">
        <label for="email" class="form-label">Email Address</label>
        <input type="email" class="form-control" id="email" name="email" value="<?= h($_POST['email'] ?? '') ?>" required autofocus>
    </div>

    <div class="mb-3">
        <label for="password" class="form-label">Password</label>
        <input type="password" class="form-control" id="password" name="password" required>
    </div>

    <div class="mb-3 form-check">
        <input type="checkbox" class="form-check-input" id="remember_me" name="remember_me" value="yes">
        <label class="form-check-label" for="remember_me">Remember me</label>
    </div>

    <?php if (recaptcha_enabled()) { ?>
    <div class="mb-3">
        <div class="g-recaptcha" data-sitekey="<?= h(recaptcha_site_key()) ?>"></div>
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    </div>
    <?php } ?>

    <button type="submit" class="btn btn-primary w-100">Sign In</button>
</form>

<hr>

<?php
// Show OAuth buttons for enabled providers
$providers = db_fetch_all(db_query("SELECT * FROM oauth_provider WHERE is_enabled = 1"));
if ($providers) { ?>
<div class="d-grid gap-2 mb-3">
    <?php foreach ($providers as $p) { ?>
    <a href="oauth_callback.php?provider=<?= h($p['provider_name']) ?>" class="btn btn-outline-secondary">
        Sign in with <?= h(ucfirst($p['provider_name'])) ?>
    </a>
    <?php } ?>
</div>
<?php } ?>

<?php
// Show SAML login buttons for enabled providers
$saml_providers = get_enabled_saml_providers();
if ($saml_providers) { ?>
<div class="d-grid gap-2 mb-3">
    <?php foreach ($saml_providers as $sp) { ?>
    <a href="saml_callback.php?login=<?= h($sp['slug']) ?>" class="btn btn-outline-primary">
        <i class="bi bi-building"></i> Sign in with <?= h($sp['display_name']) ?>
    </a>
    <?php } ?>
</div>
<?php } ?>

<div class="text-center">
    <a href="forgot.php" class="small">Forgot your password?</a>
</div>

<?php
// Check registration mode
$settings = db_fetch(db_query("SELECT registration_mode FROM auth_settings WHERE setting_id = 1"));
$mode = $settings['registration_mode'] ?? 'open';
if ($mode !== 'closed') { ?>
<div class="text-center mt-2">
    <span class="small">Don't have an account? <a href="register.php">Register</a></span>
</div>
<?php } ?>

<?php
$page_content_html = ob_get_clean();
include(__DIR__ . '/template.php');
