<?php
/**
 * Sign in with your phone (task #2391): a QR the signed-in phone app approves.
 * The page refreshes itself every few seconds (a meta refresh, no JS needed for the
 * wait); when the phone approves, the next load signs this browser in. On a phone
 * browser it shows "Open in the app to approve" instead of a QR. The QR is drawn by
 * the app's assets/js/qrcode.min.js when it ships one; the link is always there.
 * Only served when the app opts in (WN_LOGIN_APPROVE); child apps copy this page.
 */
include(__DIR__ . '/../include/common_auth.php');

if (!wn_login_approve_enabled()) { header('Location: login.php'); exit; }
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . (function_exists('get_post_login_home') ? get_post_login_home() : '../app/'));
    exit;
}

$_mine  = isset($_GET['new']) ? null : wn_login_approve_mine();
$_state = 'pending';
$_token = $_SESSION['login_approve_token'] ?? '';
$_err   = '';

if ($_mine && $_mine['status'] === 'approved') {
    wn_login_approve_collect($_mine);          // signs in + redirects
    $_state = 'expired';
} elseif ($_mine && $_mine['status'] === 'denied') {
    $_state = 'denied';
} elseif ($_mine && $_mine['status'] === 'pending' && (int)$_mine['live'] && $_token
          && hash_equals($_mine['token_hash'], hash('sha256', $_token))) {
    $_state = 'pending';
} elseif ($_mine) {
    $_state = 'expired';                        // timed out, used, or another session's
} else {
    $_r = wn_login_approve_start();
    if (!empty($_r['error'])) { $_err = $_r['error']; $_state = 'error'; }
    else { $_token = $_r['token']; }
}

$_link   = $_token ? wn_login_approve_link($_token) : '';
$_mobile = (bool)preg_match('/iPhone|iPad|iPod|Android|Mobile/i', $_SERVER['HTTP_USER_AGENT'] ?? '');
$_qr_js  = is_file(dirname($_SERVER['SCRIPT_FILENAME'] ?? '') . '/../assets/js/qrcode.min.js');
$_site   = (function_exists('get_branding') ? get_branding()['site_name'] : '') ?: 'the app';

$page_title = 'Sign in with your phone';
ob_start();
?>
<?php if ($_state === 'pending') { // browsers honour a refresh in the body too; the templates have no head hook ?>
<meta http-equiv="refresh" content="3">
<?php } ?>

<h4 class="card-title mb-3">Use <?= h($_site) ?> on your computer</h4>

<?php if ($_state === 'pending' && !$_mobile) { ?>
<p class="text-muted small">Scan this with your phone's camera. <?= h($_site) ?> opens and asks you to approve this computer.</p>
<div class="text-center my-3">
    <div id="wnApproveQr" class="d-inline-block p-2 bg-white rounded" data-url="<?= h($_link) ?>" style="min-width:208px;min-height:208px"></div>
</div>
<p class="text-muted small text-center mb-1">Waiting for your phone… This code works for 2 minutes.</p>
<?php if ($_qr_js) { ?>
<script src="../assets/js/qrcode.min.js"></script>
<script>
(function () {
    var el = document.getElementById('wnApproveQr');
    if (el && window.QRCode) { new QRCode(el, { text: el.getAttribute('data-url'), width: 192, height: 192, correctLevel: QRCode.CorrectLevel.M }); }
})();
</script>
<?php } else { ?>
<p class="small text-center"><a href="<?= h($_link) ?>">Open on your phone</a></p>
<?php } ?>
<?php } elseif ($_state === 'pending') { ?>
<p class="text-muted small">You're on a phone. Open <?= h($_site) ?> to approve signing in here.</p>
<div class="d-grid mb-2">
    <a href="<?= h($_link) ?>" class="btn btn-primary" id="wnApproveOpen">Open in the app to approve</a>
</div>
<p class="text-muted small text-center mb-1">Waiting for the app… This works for 2 minutes.</p>
<?php } elseif ($_state === 'denied') { ?>
<div class="alert alert-warning small mb-3">The request was cancelled on your phone, so this computer was not signed in.</div>
<div class="d-grid"><a href="phone.php?new=1" class="btn btn-primary">Try again</a></div>
<?php } elseif ($_state === 'error') { ?>
<div class="alert alert-danger small mb-3"><?= h($_err) ?></div>
<?php } else { ?>
<div class="alert alert-secondary small mb-3">That request expired. Requests last 2 minutes.</div>
<div class="d-grid"><a href="phone.php?new=1" class="btn btn-primary">Show a new code</a></div>
<?php } ?>

<div class="text-center mt-3">
    <a href="login.php" class="small">Sign in another way</a>
</div>

<?php
$page_content_html = ob_get_clean();
include(__DIR__ . '/template.php');
