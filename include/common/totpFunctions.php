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
    return totp_instance()->verifyCode($secret, $code);
}
