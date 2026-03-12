<?php
/**
 * googleRecaptchaFunctions.php
 * Google reCAPTCHA v2 verification.
 */

/**
 * Check if reCAPTCHA is configured (keys are set).
 *
 * @return bool
 */
function recaptcha_enabled() {
    global $grecaptcha_key, $grecaptcha_secret;
    return !empty($grecaptcha_key) && !empty($grecaptcha_secret);
}

/**
 * Get the reCAPTCHA site key for the frontend.
 *
 * @return string
 */
function recaptcha_site_key() {
    global $grecaptcha_key;
    return $grecaptcha_key ?? '';
}

/**
 * Verify a reCAPTCHA response token.
 *
 * @param string $response  The g-recaptcha-response from the form
 * @return bool
 */
function recaptcha_verify($response) {
    global $grecaptcha_secret;

    if (!recaptcha_enabled()) return true; // skip if not configured

    if (empty($response)) return false;

    $url = 'https://www.google.com/recaptcha/api/siteverify';
    $data = [
        'secret'   => $grecaptcha_secret,
        'response' => $response,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data),
        ],
    ];

    $context = stream_context_create($options);
    $result  = @file_get_contents($url, false, $context);

    if ($result === false) {
        error_log('reCAPTCHA verification request failed');
        return false;
    }

    $json = json_decode($result, true);
    return !empty($json['success']);
}
