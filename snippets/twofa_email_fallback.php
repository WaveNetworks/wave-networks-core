<?php
/**
 * snippets/twofa_email_fallback.php — "Can't use any of these?" under the 2FA form
 * (task #2660; rules in include/common/twofaEmailFunctions.php). Included by core's
 * auth/2fa.php and by each child app's copy. Renders nothing unless the app opted in
 * (WN_2FA_EMAIL). When this sign-in may not finish by email, it says why and what to do.
 */
if (function_exists('wn_2fa_email_enabled') && wn_2fa_email_enabled() && !empty($_SESSION['2fa_user_id'])) {
    $wn_fb_user = get_user($_SESSION['2fa_user_id']);
    $wn_fb_why  = wn_2fa_email_refusal($wn_fb_user, $_SESSION['2fa_first_factor'] ?? '');
    $wn_fb_sent = !empty($_SESSION['2fa_email_sent']) && (time() - (int)$_SESSION['2fa_email_sent']) < WN_2FA_EMAIL_TTL_MIN * 60;
?>
<hr class="my-4">
<details class="wn-2fa-email"<?= $wn_fb_sent ? ' open' : '' ?>>
    <summary class="small">Can't use your <?= h(wn_2fa_email_factor_name($wn_fb_user)) ?>?</summary>
    <?php if ($wn_fb_why !== '') { ?>
    <p class="small text-muted mt-2 mb-0" id="wn-2fa-email-refused"><?= h($wn_fb_why) ?></p>
    <?php } else { ?>
    <p class="small text-muted mt-2">We can email a code to <strong><?= h(wn_2fa_email_masked($wn_fb_user['email'] ?? '')) ?></strong> instead. You'll get a notice each time it's used.</p>
    <?php if ($wn_fb_sent) { ?>
    <form method="post" action="">
        <input type="hidden" name="action" value="verify2FAEmail">
        <div class="mb-3">
            <label for="email_code" class="form-label">Code from your email</label>
            <input type="text" class="form-control text-center" id="email_code" name="email_code"
                   maxlength="6" pattern="\d{6}" inputmode="numeric" autocomplete="one-time-code"
                   required style="font-size: 1.5em; letter-spacing: 0.5em;">
        </div>
        <button type="submit" class="btn btn-primary w-100">Verify email code</button>
    </form>
    <?php } ?>
    <form method="post" action="" class="mt-2">
        <input type="hidden" name="action" value="send2FAEmail">
        <button type="submit" class="btn btn-outline-secondary btn-sm w-100"><?= $wn_fb_sent ? 'Send a new code' : 'Email me a code' ?></button>
    </form>
    <?php } ?>
</details>
<?php } ?>
