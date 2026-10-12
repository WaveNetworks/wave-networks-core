<?php
/**
 * Login Code Actions — sign in with an emailed code (task #2389)
 * Actions: requestLoginCode, verifyLoginCode
 * Logic: include/common/loginCodeFunctions.php. Off unless the app opts in (WN_LOGIN_CODE).
 */

// ─── REQUEST A CODE ──────────────────────────────────────────────────────────

if (($action ?? null) == 'requestLoginCode' && wn_login_code_enabled()) {
    $errs  = array();
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!valid_email($email)) { $errs['email'] = 'Please enter a valid email address.'; }

    if (count($errs) <= 0 && recaptcha_enabled() && !recaptcha_verify($_POST['g-recaptcha-response'] ?? '')) {
        $errs['captcha'] = 'Please complete the reCAPTCHA.';
    }

    if (count($errs) <= 0) {
        $err = wn_login_code_request($email);
        if ($err) { $errs['rate'] = $err; }
    }

    if (count($errs) <= 0) {
        $_SESSION['login_code_email'] = $email;
        $_SESSION['success'] = wn_login_code_sent_message();
        header('Location: code.php');
        exit;
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── SIGN IN WITH THE CODE ───────────────────────────────────────────────────

if (($action ?? null) == 'verifyLoginCode' && wn_login_code_enabled()) {
    $errs  = array();
    $email = strtolower(trim($_POST['email'] ?? ($_SESSION['login_code_email'] ?? '')));
    $code  = trim($_POST['code'] ?? '');

    if (!$code) { $errs['code'] = 'Enter the 6-digit code from the email.'; }

    if (count($errs) <= 0) {
        $res = wn_login_code_verify($email, $code);
        if (!empty($res['error'])) { $errs['code'] = $res['error']; }
    }

    if (count($errs) <= 0) {
        unset($_SESSION['login_code_email']);
        wn_login_code_complete($res['user']);   // redirects
    } else {
        $_SESSION['login_code_email'] = $email;
        $_SESSION['error'] = implode('<br>', $errs);
    }
}
