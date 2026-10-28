<?php
/**
 * totpFunctions.php
 * Two-factor authentication (TOTP) using robthree/twofactorauth.
 */

use RobThree\Auth\TwoFactorAuth;

/**
 * Get a TwoFactorAuth instance.
 *
 * @return TwoFactorAuth
 */
function totp_instance() {
    static $tfa = null;
    if ($tfa === null) {
        $tfa = new TwoFactorAuth('WaveNetworks');
    }
    return $tfa;
}

/**
 * Generate a new TOTP secret.
 *
 * @return string
 */
function totp_generate_secret() {
    return totp_instance()->createSecret();
}

/**
 * Get the QR code data URI for provisioning.
 *
 * @param string $label   User-visible label (e.g. email)
 * @param string $secret
 * @return string          Data URI for QR code image
 */
function totp_qr_code($label, $secret) {
    return totp_instance()->getQRCodeImageAsDataUri($label, $secret);
}

/**
 * Verify a TOTP code against a secret.
 *
 * @param string $secret
 * @param string $code    6-digit code from authenticator app
 * @return bool
 */
function totp_verify($secret, $code) {
    // totp_secret is sealed at rest (task #2397); an unmarked legacy value opens as itself.
    return totp_instance()->verifyCode(wn_secret_open($secret), $code);
}

/**
 * Why this user may NOT turn on a second factor yet, or '' if they may.
 * Owner rule (task #2658): 2FA needs a verified email first, because the verified
 * email is the way back in after a lost authenticator. Without it, one lost phone
 * is a locked account. Every enrol path (web, phone, any factor) asks this.
 */
function twofa_enrol_blocker($user_id) {
    $user = get_user((int)$user_id);
    if (!$user) { return 'Account not found.'; }
    if (empty($user['email']) || empty($user['is_confirmed'])) {
        return 'Verify your email address before turning on two-factor sign-in. '
             . 'It is how you get back in if you lose your phone.';
    }
    return '';
}


/**
 * Tell a person that support turned off one of their sign-in factors (task #2663): email +
 * security notification, so a reset nobody asked for is seen.
 */
function wn_factor_support_notice($user, $what) {
    $b    = function_exists('get_branding') ? get_branding() : [];
    $site = $b['site_name'] ?? 'your account';
    $line = 'Support turned off your ' . $what . ' for ' . $site . '.';
    $e    = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $body = '<h2>' . $e($line) . '</h2><p>On ' . $e(gmdate('j M Y, H:i')) . ' UTC. Sign in with your password and set it up again on your account security page.</p>'
          . '<p style="color:#666">If you did not ask for this, change your password now and reply to this email.</p>';
    if (!empty($user['email'])) {
        if (function_exists('send_email_now')) {
            send_email_now($user['email'], '', 'Sign-in method reset on ' . $site, $body, ['source_app' => 'core']);
        } elseif (function_exists('send_email')) {
            send_email($user['email'], '', 'Sign-in method reset on ' . $site, $body);
        }
    }
    if (function_exists('send_notification')) {
        send_notification((int)$user['user_id'], $user['shard_id'] ?? null, 'security', 'Sign-in method reset by support',
            $line . ' If you did not ask for this, change your password.', ['source_app' => 'core']);
    }
}
