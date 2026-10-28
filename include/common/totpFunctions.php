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

