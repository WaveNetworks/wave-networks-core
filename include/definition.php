<?php
/**
 * definition.php
 * Pre-declares all $_SESSION, $_REQUEST, $_GET, $_POST variables as null before use.
 * Prevents undefined variable notices and documents expected input/output.
 */

// ─── VERSION ────────────────────────────────────────────────────────────────
define('WN_ADMIN_VERSION', '1.0.0');

// ─── SESSION VARIABLES ───────────────────────────────────────────────────────

$_SESSION['user_id']      = $_SESSION['user_id'] ?? null;
$_SESSION['shard_id']     = $_SESSION['shard_id'] ?? null;
$_SESSION['email']        = $_SESSION['email'] ?? null;
$_SESSION['first_name']   = $_SESSION['first_name'] ?? null;
$_SESSION['last_name']    = $_SESSION['last_name'] ?? null;
$_SESSION['is_admin']     = $_SESSION['is_admin'] ?? null;
$_SESSION['is_owner']     = $_SESSION['is_owner'] ?? null;
$_SESSION['is_manager']   = $_SESSION['is_manager'] ?? null;
$_SESSION['is_employee']  = $_SESSION['is_employee'] ?? null;
$_SESSION['homedir']      = $_SESSION['homedir'] ?? null;
$_SESSION['profile_image'] = $_SESSION['profile_image'] ?? null;

// Flash messages
$_SESSION['error']        = $_SESSION['error'] ?? null;
$_SESSION['success']      = $_SESSION['success'] ?? null;
$_SESSION['info']         = $_SESSION['info'] ?? null;
$_SESSION['warning']      = $_SESSION['warning'] ?? null;

// 2FA
$_SESSION['2fa_pending']     = $_SESSION['2fa_pending'] ?? null;
$_SESSION['2fa_user_id']     = $_SESSION['2fa_user_id'] ?? null;

// SAML
$_SESSION['saml_session_index'] = $_SESSION['saml_session_index'] ?? null;
$_SESSION['saml_name_id']       = $_SESSION['saml_name_id'] ?? null;
$_SESSION['saml_provider_slug'] = $_SESSION['saml_provider_slug'] ?? null;

// ─── REQUEST / POST / GET VARIABLES ──────────────────────────────────────────

$action          = $_REQUEST['action']       ?? null;
$page            = $_REQUEST['page']         ?? null;
$id              = $_REQUEST['id']           ?? null;
$search          = $_REQUEST['search']       ?? null;
$sort            = $_REQUEST['sort']         ?? null;
$dir             = $_REQUEST['dir']          ?? null;
$per_page        = $_REQUEST['per_page']     ?? null;
$current_page    = $_REQUEST['current_page'] ?? null;
$filter          = $_REQUEST['filter']       ?? null;
$token           = $_REQUEST['token']        ?? null;
$hash            = $_REQUEST['hash']         ?? null;
$modal           = $_REQUEST['modal']        ?? null;

// ─── $data ARRAY — populated by action files, returned as JSON by api/index.php
$data = [];
