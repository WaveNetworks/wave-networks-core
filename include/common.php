<?php
/**
 * common.php
 * Full authenticated bootstrap.
 * Loads config → connects DB → runs migrations → guards session → includes all helpers + actions.
 */

// Shared bootstrap: autoload, config + credentials, PDO, helpers, migrations.
// Everything below is what makes THIS entry point different — the auth posture.
require_once __DIR__ . '/bootstrap.php';

// 6. Session guard (browser requests only — CLI/cron has no session and
// cannot emit headers after job output has already started)
if (php_sapi_name() !== 'cli') {

    // A bundled mobile client authenticates with a Bearer device token, not a cookie
    // (child-app spec 05). It reaches this file because the mobile fragment endpoint IS
    // the ?page= view path — same guard, same view files, same permissions. Only the
    // credential differs, and by the time anything downstream runs, the session it
    // produces is identical to a browser's.
    //
    // No session is started for these requests: sessions are file-backed, and a phone
    // polling chat would leave a file behind on every request.
    if (function_exists('wn_send_cors_headers')) { wn_send_cors_headers(); }

    if (function_exists('wn_device_token') && wn_device_token() !== '') {
        $_SESSION = [];
        if (!wn_authenticate_device_token()) {
            // Never redirect a bundled client to a login PAGE — there is no server to
            // navigate to inside the app. 401 lets the shell route to its login screen.
            if (!headers_sent()) {
                http_response_code(401);
                header('Content-Type: application/json');
            }
            echo json_encode(['error' => 'Login required.', 'success' => '', 'info' => '', 'warning' => '', 'results' => []]);
            exit;
        }
    } else {
        // Ordinary browser request — cookie session, exactly as before.
        init_session_storage();
        session_start();

        if (empty($_SESSION['user_id'])) {
            // Try auto-login via cookie. Note this validates the SAME credential a
            // device token is (an api_key row) — the only difference is transport.
            $auto_logged = false;
            if (isset($_COOKIE['wn_auto_login'])) {
                $user = validate_api_key($_COOKIE['wn_auto_login']);
                if ($user) {
                    load_user_session($user);
                    $auto_logged = true;
                } else {
                    setcookie('wn_auto_login', '', time() - 3600, '/', '', false, true);
                }
            }

            if (!$auto_logged) {
                // A mobile fragment request (mobile-web: cookie session, no Bearer token —
                // the device path is handled above) must get a clean 401, NOT a 302 to the
                // login PAGE. The shell fetches views as fragments; following a redirect would
                // splice the full login.php HTML into the content area. On 401 the shell
                // renders its own bundled login screen. Same contract as the device 401 above.
                if (!empty($_GET['mobile'])) {
                    if (!headers_sent()) {
                        http_response_code(401);
                        header('Content-Type: application/json');
                    }
                    echo json_encode(['error' => 'Login required.', 'success' => '', 'info' => '', 'warning' => '', 'results' => []]);
                    exit;
                }
                header('Location: ../auth/login.php');
                exit;
            }
        }
    }
}

// 6b. Device tracking — identify all visitors via persistent cookie
if (function_exists('get_or_create_device')) {
    try { get_or_create_device(); } catch (Exception $e) { /* graceful */ }
}

// 7. definition.php
include(__DIR__ . '/definition.php');

// 8. Action includes
foreach (glob(__DIR__ . '/actions/*/*.php') as $f) { include_once($f); }

// 9. Action logging — record what was just executed
if (function_exists('log_user_action')) {
    $__action = $_POST['action'] ?? 'view';
    try { log_user_action($__action); } catch (Exception $e) { /* silent */ }
    unset($__action);
}
