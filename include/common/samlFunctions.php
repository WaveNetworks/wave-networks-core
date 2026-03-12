<?php
/**
 * samlFunctions.php
 * SAML 2.0 helpers for Shibboleth/InCommon institutional SSO.
 * Uses onelogin/php-saml library.
 */

use OneLogin\Saml2\Auth as SamlAuth;
use OneLogin\Saml2\Settings as SamlSettings;

/**
 * Get a single SAML provider by ID.
 */
function get_saml_provider($saml_provider_id) {
    $id = (int)$saml_provider_id;
    $r = db_query("SELECT * FROM saml_provider WHERE saml_provider_id = '$id'");
    return db_fetch($r);
}

/**
 * Get a SAML provider by its URL slug.
 * Used by ACS endpoint to identify which IdP sent the response.
 */
function get_saml_provider_by_slug($slug) {
    $safe = sanitize($slug, SQL);
    $r = db_query("SELECT * FROM saml_provider WHERE slug = '$safe'");
    return db_fetch($r);
}

/**
 * Get all enabled SAML providers (for login page buttons).
 */
function get_enabled_saml_providers() {
    $r = db_query("SELECT * FROM saml_provider WHERE is_enabled = 1 ORDER BY display_name");
    return db_fetch_all($r);
}

/**
 * Build onelogin/php-saml settings array from a DB provider row.
 *
 * @param array $provider  Row from saml_provider table
 * @return array           Settings array for OneLogin\Saml2\Auth
 */
function build_saml_settings($provider) {
    $base_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
              . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    // Build path to auth/ directory
    $script   = $_SERVER['SCRIPT_NAME'] ?? '';
    $auth_dir = $base_url . dirname($script);

    // If called from app/ or include/, normalize to auth/
    if (strpos($auth_dir, '/auth') === false) {
        $auth_dir = preg_replace('#/(app|api|include)/?.*$#', '/auth', $auth_dir);
    }
    // Ensure no trailing slash
    $auth_dir = rtrim($auth_dir, '/');

    $acs_url      = $auth_dir . '/saml_callback.php?acs=' . urlencode($provider['slug']);
    $slo_url      = $auth_dir . '/saml_callback.php?sls=' . urlencode($provider['slug']);
    $metadata_url = $auth_dir . '/saml_metadata.php?provider=' . urlencode($provider['slug']);

    $sp_entity_id = !empty($provider['sp_entity_id'])
                  ? $provider['sp_entity_id']
                  : $metadata_url;

    return [
        'strict' => true,
        'debug'  => false,
        'sp' => [
            'entityId' => $sp_entity_id,
            'assertionConsumerService' => [
                'url'     => $acs_url,
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
            ],
            'singleLogoutService' => [
                'url'     => $slo_url,
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
            ],
            'NameIDFormat' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:emailAddress',
        ],
        'idp' => [
            'entityId' => $provider['idp_entity_id'],
            'singleSignOnService' => [
                'url'     => $provider['idp_sso_url'],
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
            ],
            'singleLogoutService' => [
                'url'     => $provider['idp_slo_url'] ?: '',
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
            ],
            'x509cert' => $provider['idp_x509_cert'],
        ],
        'security' => [
            'wantAssertionsSigned'  => (bool)$provider['want_assertions_signed'],
            'wantNameIdEncrypted'   => (bool)$provider['want_nameid_encrypted'],
            'requestedAuthnContext' => !empty($provider['authn_context'])
                                      ? explode(',', $provider['authn_context'])
                                      : false,
        ],
    ];
}

/**
 * Extract user attributes from a SAML response using the provider's attribute mapping.
 *
 * @param SamlAuth $auth      Authenticated onelogin Auth instance
 * @param array    $provider  Row from saml_provider table
 * @return array              ['email', 'first_name', 'last_name', 'name_id', 'session_index']
 */
function extract_saml_user_attributes($auth, $provider) {
    $attrs  = $auth->getAttributes();
    $nameId = $auth->getNameId();

    // Email: try mapped attribute first, fall back to NameID
    $email = '';
    if (!empty($provider['attr_email']) && !empty($attrs[$provider['attr_email']])) {
        $email = $attrs[$provider['attr_email']][0];
    }
    if (empty($email)) {
        $email = $nameId; // Many IdPs set NameID to email
    }

    // First name
    $firstName = '';
    if (!empty($provider['attr_first_name']) && !empty($attrs[$provider['attr_first_name']])) {
        $firstName = $attrs[$provider['attr_first_name']][0];
    }

    // Last name
    $lastName = '';
    if (!empty($provider['attr_last_name']) && !empty($attrs[$provider['attr_last_name']])) {
        $lastName = $attrs[$provider['attr_last_name']][0];
    }

    // If no first/last name, try display name
    if (empty($firstName) && !empty($provider['attr_display_name']) && !empty($attrs[$provider['attr_display_name']])) {
        $parts = explode(' ', $attrs[$provider['attr_display_name']][0], 2);
        $firstName = $parts[0] ?? '';
        $lastName  = $parts[1] ?? '';
    }

    return [
        'email'         => $email,
        'first_name'    => $firstName,
        'last_name'     => $lastName,
        'name_id'       => $nameId,
        'session_index' => $auth->getSessionIndex(),
    ];
}
