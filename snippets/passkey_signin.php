<?php
/**
 * snippets/passkey_signin.php — "Sign in with a passkey" (task #2661; rules in
 * include/common/passkeyFunctions.php). Included by the sign-in page (password-less) and by
 * auth/2fa.php (the second step, only when the account has a passkey), in core and in each
 * child app's copy. Renders nothing unless the app opted in (WN_PASSKEY) and the browser can.
 */
if (function_exists('wn_passkey_enabled') && wn_passkey_enabled()) {
    $wn_pk_second = !empty($_SESSION['2fa_pending']) && !empty($_SESSION['2fa_user_id']);
    $wn_pk_opts   = wn_passkey_signin_options($wn_pk_second ? get_user($_SESSION['2fa_user_id']) : null);
    if ($wn_pk_opts) {
?>
<div class="wn-passkey-signin d-none" id="wn-passkey-signin">
    <?php if (!$wn_pk_second) { ?><div class="text-center text-muted small my-3">or</div><?php } else { ?><hr class="my-4"><?php } ?>
    <form method="post" action="" id="wn-passkey-form">
        <input type="hidden" name="action" value="passkeySignIn">
        <input type="hidden" name="credential_id" value="">
        <input type="hidden" name="client_data" value="">
        <input type="hidden" name="authenticator_data" value="">
        <input type="hidden" name="signature" value="">
        <input type="hidden" name="user_handle" value="">
        <button type="button" class="btn btn-outline-primary w-100" id="wn-passkey-btn">
            <?= $wn_pk_second ? 'Use a passkey instead' : 'Sign in with a passkey' ?>
        </button>
        <p class="small text-danger mt-2 mb-0 d-none" id="wn-passkey-error" role="alert"></p>
    </form>
</div>
<script><?php readfile(__DIR__ . '/../assets/js/passkey.js'); ?></script>
<script>
(function () {
    var box = document.getElementById('wn-passkey-signin');
    if (!window.WnPasskey || !WnPasskey.supported()) return;
    box.classList.remove('d-none');
    var opts = <?= json_encode($wn_pk_opts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?>;
    var err = document.getElementById('wn-passkey-error');
    document.getElementById('wn-passkey-btn').addEventListener('click', function () {
        err.classList.add('d-none');
        WnPasskey.signIn(opts, document.getElementById('wn-passkey-form'), function (msg) {
            err.textContent = msg; err.classList.remove('d-none');
        });
    });
})();
</script>
<?php
    }
}
