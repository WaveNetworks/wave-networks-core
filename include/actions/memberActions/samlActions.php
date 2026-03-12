<?php
/**
 * SAML Provider Actions
 * Actions: saveSamlProvider, toggleSamlProvider, deleteSamlProvider
 */

// ─── SAVE SAML PROVIDER ────────────────────────────────────────────────────

if (($action ?? null) == 'saveSamlProvider') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $saml_provider_id  = (int)($_POST['saml_provider_id'] ?? 0);
    $display_name      = trim($_POST['display_name'] ?? '');
    $slug              = trim($_POST['slug'] ?? '');
    $idp_entity_id     = trim($_POST['idp_entity_id'] ?? '');
    $idp_sso_url       = trim($_POST['idp_sso_url'] ?? '');
    $idp_slo_url       = trim($_POST['idp_slo_url'] ?? '');
    $idp_x509_cert     = trim($_POST['idp_x509_cert'] ?? '');
    $sp_entity_id      = trim($_POST['sp_entity_id'] ?? '');
    $attr_email        = trim($_POST['attr_email'] ?? 'urn:oid:0.9.2342.19200300.100.1.3');
    $attr_first_name   = trim($_POST['attr_first_name'] ?? 'urn:oid:2.5.4.42');
    $attr_last_name    = trim($_POST['attr_last_name'] ?? 'urn:oid:2.5.4.4');
    $attr_display_name = trim($_POST['attr_display_name'] ?? 'urn:oid:2.16.840.1.113730.3.1.241');
    $want_assertions_signed = isset($_POST['want_assertions_signed']) ? 1 : 0;
    $want_nameid_encrypted  = isset($_POST['want_nameid_encrypted']) ? 1 : 0;
    $authn_context     = trim($_POST['authn_context'] ?? '');

    if (!$display_name)  { $errs['name'] = 'Display name is required.'; }
    if (!$slug)          { $errs['slug'] = 'URL slug is required.'; }
    if ($slug && !preg_match('/^[a-z0-9\-]+$/', $slug)) { $errs['slug'] = 'Slug must contain only lowercase letters, numbers, and hyphens.'; }
    if (!$idp_entity_id) { $errs['entity'] = 'IdP Entity ID is required.'; }
    if (!$idp_sso_url)   { $errs['sso'] = 'IdP SSO URL is required.'; }
    if (!$idp_x509_cert) { $errs['cert'] = 'IdP X.509 certificate is required.'; }

    // Check slug uniqueness
    if (count($errs) <= 0 && $slug) {
        $existing = get_saml_provider_by_slug($slug);
        if ($existing && $existing['saml_provider_id'] != $saml_provider_id) {
            $errs['slug'] = 'This slug is already in use by another provider.';
        }
    }

    // Strip PEM headers and whitespace — onelogin expects raw base64
    $idp_x509_cert = preg_replace('/-----BEGIN CERTIFICATE-----/', '', $idp_x509_cert);
    $idp_x509_cert = preg_replace('/-----END CERTIFICATE-----/', '', $idp_x509_cert);
    $idp_x509_cert = preg_replace('/\s+/', '', $idp_x509_cert);

    if (count($errs) <= 0) {
        $s_name    = sanitize($display_name, SQL);
        $s_slug    = sanitize($slug, SQL);
        $s_entity  = sanitize($idp_entity_id, SQL);
        $s_sso     = sanitize($idp_sso_url, SQL);
        $s_slo     = sanitize($idp_slo_url, SQL);
        $s_cert    = sanitize($idp_x509_cert, SQL);
        $s_sp      = sanitize($sp_entity_id, SQL);
        $s_ae      = sanitize($attr_email, SQL);
        $s_afn     = sanitize($attr_first_name, SQL);
        $s_aln     = sanitize($attr_last_name, SQL);
        $s_adn     = sanitize($attr_display_name, SQL);
        $s_authn   = sanitize($authn_context, SQL);

        if ($saml_provider_id) {
            db_query("UPDATE saml_provider SET
                display_name = '$s_name',
                slug = '$s_slug',
                idp_entity_id = '$s_entity',
                idp_sso_url = '$s_sso',
                idp_slo_url = '$s_slo',
                idp_x509_cert = '$s_cert',
                sp_entity_id = '$s_sp',
                attr_email = '$s_ae',
                attr_first_name = '$s_afn',
                attr_last_name = '$s_aln',
                attr_display_name = '$s_adn',
                want_assertions_signed = '$want_assertions_signed',
                want_nameid_encrypted = '$want_nameid_encrypted',
                authn_context = '$s_authn'
                WHERE saml_provider_id = '$saml_provider_id'");
            $_SESSION['success'] = 'SAML provider updated.';
        } else {
            db_query("INSERT INTO saml_provider
                (display_name, slug, idp_entity_id, idp_sso_url, idp_slo_url, idp_x509_cert,
                 sp_entity_id, attr_email, attr_first_name, attr_last_name, attr_display_name,
                 want_assertions_signed, want_nameid_encrypted, authn_context, is_enabled)
                VALUES ('$s_name', '$s_slug', '$s_entity', '$s_sso', '$s_slo', '$s_cert',
                        '$s_sp', '$s_ae', '$s_afn', '$s_aln', '$s_adn',
                        '$want_assertions_signed', '$want_nameid_encrypted', '$s_authn', 0)");
            $_SESSION['success'] = 'SAML provider added.';
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── TOGGLE SAML PROVIDER ──────────────────────────────────────────────────

if (($action ?? null) == 'toggleSamlProvider') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $saml_provider_id = (int)($_POST['saml_provider_id'] ?? 0);
    if (!$saml_provider_id) { $errs['id'] = 'Provider ID required.'; }

    if (count($errs) <= 0) {
        $r = db_query("SELECT is_enabled FROM saml_provider WHERE saml_provider_id = '$saml_provider_id'");
        $row = db_fetch($r);

        if ($row) {
            $new_state = $row['is_enabled'] ? 0 : 1;
            db_query("UPDATE saml_provider SET is_enabled = '$new_state' WHERE saml_provider_id = '$saml_provider_id'");
            $_SESSION['success'] = 'SAML provider ' . ($new_state ? 'enabled' : 'disabled') . '.';
        } else {
            $_SESSION['error'] = 'Provider not found.';
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── DELETE SAML PROVIDER ──────────────────────────────────────────────────

if (($action ?? null) == 'deleteSamlProvider') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $saml_provider_id = (int)($_POST['saml_provider_id'] ?? 0);
    if (!$saml_provider_id) { $errs['id'] = 'Provider ID required.'; }

    if (count($errs) <= 0) {
        db_query("DELETE FROM saml_provider WHERE saml_provider_id = '$saml_provider_id'");
        $_SESSION['success'] = 'SAML provider deleted.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}
