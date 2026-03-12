<?php
include(__DIR__ . '/../include/common_auth.php');

$page_title = 'Forgot Password';
ob_start();
?>

<h4 class="card-title mb-3">Reset Password</h4>

<p class="text-muted small">Enter your email and we'll send a reset link.</p>

<form method="post" action="">
    <input type="hidden" name="action" value="forgotPassword">

    <div class="mb-3">
        <label for="email" class="form-label">Email Address</label>
        <input type="email" class="form-control" id="email" name="email" required autofocus>
    </div>

    <button type="submit" class="btn btn-primary w-100">Send Reset Link</button>
</form>

<div class="text-center mt-3">
    <a href="login.php" class="small">Back to login</a>
</div>

<?php
$page_content_html = ob_get_clean();
include(__DIR__ . '/template.php');
