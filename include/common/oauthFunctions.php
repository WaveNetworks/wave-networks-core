<?php
/**
 * oauthFunctions.php
 * OAuth 2.0 redirect and callback helpers for Google, GitHub, Facebook.
 */

use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Provider\Github;
use League\OAuth2\Client\Provider\Facebook;

/**
 * Get an OAuth2 provider instance.
 *
 * @param string $provider_name  'google', 'github', 'facebook'
 * @return \League\OAuth2\Client\Provider\AbstractProvider|false
 */
function oauth_provider($provider_name) {
    global $google_client_id, $google_client_secret;
    global $github_client_id, $github_client_secret;
    global $facebook_app_id, $facebook_app_secret;

    $base_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $redirect = $base_url . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/auth/oauth_callback.php';

    switch ($provider_name) {
        case 'google':
            if (empty($google_client_id)) return false;
            return new Google([
                'clientId'     => $google_client_id,
                'clientSecret' => $google_client_secret,
                'redirectUri'  => $redirect,
            ]);

        case 'github':
            if (empty($github_client_id)) return false;
            return new Github([
                'clientId'     => $github_client_id,
                'clientSecret' => $github_client_secret,
                'redirectUri'  => $redirect,
            ]);

        case 'facebook':
            if (empty($facebook_app_id)) return false;
            return new Facebook([
                'clientId'     => $facebook_app_id,
                'clientSecret' => $facebook_app_secret,
                'redirectUri'  => $redirect,
                'graphApiVersion' => 'v18.0',
            ]);

        default:
            return false;
    }
}

/**
 * Redirect the user to an OAuth provider's auth URL.
 *
 * @param string $provider_name
 */
function oauth_redirect($provider_name) {
    $provider = oauth_provider($provider_name);
    if (!$provider) {
        $_SESSION['error'] = 'OAuth provider not configured.';
        return;
    }

    $options = [];
    if ($provider_name === 'google') {
        $options['scope'] = ['email', 'profile'];
    } elseif ($provider_name === 'facebook') {
        $options['scope'] = ['email'];
    }

    $authUrl = $provider->getAuthorizationUrl($options);
    $_SESSION['oauth2state']    = $provider->getState();
    $_SESSION['oauth_provider'] = $provider_name;

    header('Location: ' . $authUrl);
    exit;
}

/**
 * Handle the OAuth callback — exchange code for token, get user info.
 *
 * @param string $provider_name
 * @param string $code
 * @param string $state
 * @return array|false  ['email' => ..., 'name' => ..., 'oauth_id' => ...]
 */
function oauth_callback($provider_name, $code, $state) {
    if (empty($state) || $state !== ($_SESSION['oauth2state'] ?? '')) {
        unset($_SESSION['oauth2state']);
        $_SESSION['error'] = 'Invalid OAuth state. Please try again.';
        return false;
    }

    $provider = oauth_provider($provider_name);
    if (!$provider) {
        $_SESSION['error'] = 'OAuth provider not configured.';
        return false;
    }

    try {
        $token = $provider->getAccessToken('authorization_code', ['code' => $code]);
        $user  = $provider->getResourceOwner($token);

        $data = $user->toArray();

        return [
            'email'    => $data['email'] ?? '',
            'name'     => $data['name'] ?? ($data['login'] ?? ''),
            'oauth_id' => (string)($data['id'] ?? $data['sub'] ?? ''),
        ];

    } catch (\Exception $e) {
        error_log("OAuth callback error ($provider_name): " . $e->getMessage());
        $_SESSION['error'] = 'OAuth authentication failed. Please try again.';
        return false;
    }
}
