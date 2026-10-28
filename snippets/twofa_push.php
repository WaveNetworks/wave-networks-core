<?php
/**
 * snippets/twofa_push.php — "Approve on your phone" on 2fa.php (task #2662; rules in
 * include/common/twofaPushFunctions.php). Included by core's auth/2fa.php and each child
 * app's copy, BEFORE the authenticator form (the page is buffered, so a finished approval
 * can still redirect from here). Renders nothing unless the app opted in (WN_2FA_PUSH) and
 * the account has a phone with notifications. While waiting the page refreshes itself.
 */
if (function_exists('wn_2fa_push_enabled') && wn_2fa_push_enabled() && !empty($_SESSION['2fa_user_id'])) {
    $wn_tp_user = get_user($_SESSION['2fa_user_id']);
    $wn_tp_row  = wn_2fa_push_mine($_SESSION['2fa_user_id']);
    $wn_tp_wait = $wn_tp_row && !empty($_GET['push']) && (int)$wn_tp_row['login_approval_id'] === (int)($_SESSION['2fa_push_id'] ?? 0);

    if ($wn_tp_wait && $wn_tp_row['status'] === 'approved') {
        $wn_tp_id = (int)$wn_tp_row['login_approval_id'];
        $wn_tp_ok = db_query("UPDATE login_approval SET status = 'used' WHERE login_approval_id = '$wn_tp_id' AND status = 'approved'");
        if ($wn_tp_ok && $wn_tp_ok->rowCount() > 0 && $wn_tp_user) {
            unset($_SESSION['2fa_push_id']);
            setcookie(WN_2FA_PUSH_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
            wn_2fa_finish($wn_tp_user, '2fa_push');      // never returns
        }
    }
    $wn_tp_state = !$wn_tp_wait ? 'offer'
        : ($wn_tp_row['status'] === 'denied' ? 'denied'
        : (($wn_tp_row['status'] === 'pending' && (int)$wn_tp_row['live']) ? 'pending' : 'expired'));

    if ($wn_tp_state !== 'offer' || wn_2fa_push_available($wn_tp_user)) {
?>
<div class="wn-2fa-push mb-4" id="wn-2fa-push" data-state="<?= h($wn_tp_state) ?>">
<?php if ($wn_tp_state === 'pending') { ?>
    <meta http-equiv="refresh" content="3">
    <p class="small text-muted mb-2">We sent a request to your phone. Open it and tap this number:</p>
    <div class="display-4 fw-bold text-center my-3" id="wn-2fa-push-number"><?= (int)$wn_tp_row['match_number'] ?></div>
    <p class="small text-muted text-center">Waiting for your phone… This works for 2 minutes. No notification? Open the app, it asks there too.</p>
<?php } elseif ($wn_tp_state === 'denied') { ?>
    <div class="alert alert-warning small" id="wn-2fa-push-denied">
        <?= ($wn_tp_row['deny_reason'] ?? '') === 'wrong_number'
            ? 'A different number was picked on your phone, so this sign-in was blocked.'
            : 'This sign-in was blocked on your phone.' ?>
    </div>
<?php } elseif ($wn_tp_state === 'expired') { ?>
    <div class="alert alert-secondary small">That request expired. Requests last 2 minutes.</div>
<?php } ?>
<?php if ($wn_tp_state !== 'pending') { ?>
    <form method="post" action="2fa.php">
        <input type="hidden" name="action" value="start2FAPush">
        <button type="submit" class="btn btn-primary w-100" id="wn-2fa-push-start"><?= $wn_tp_state === 'offer' ? 'Approve on your phone' : 'Send another request' ?></button>
    </form>
<?php } ?>
    <p class="small text-center text-muted mt-3 mb-0">Use another way:</p>
</div>
<?php
    }
}
