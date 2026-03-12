<?php
/**
 * saml_metadata.php
 * Serves SP metadata XML for a SAML provider.
 * IdP administrators fetch this URL to register the SP.
 *
 * Usage: saml_metadata.php?provider=<slug>
 */
include(__DIR__ . '/../include/common_auth.php');

use OneLogin\Saml2\Settings as SamlSettings;

$slug = $_GET['provider'] ?? '';
if (!$slug) {
    http_response_code(400);
    echo 'Provider slug required.';
    exit;
}

$provider = get_saml_provider_by_slug($slug);
if (!$provider) {
    http_response_code(404);
    echo 'Provider not found.';
    exit;
}

$settingsArray = build_saml_settings($provider);
$samlSettings  = new SamlSettings($settingsArray, true);
$metadata      = $samlSettings->getSPMetadata();
$errors        = $samlSettings->validateMetadata($metadata);

if (!empty($errors)) {
    http_response_code(500);
    echo 'Metadata validation errors: ' . implode(', ', $errors);
    exit;
}

header('Content-Type: application/xml');
echo $metadata;
