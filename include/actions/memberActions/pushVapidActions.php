<?php
/**
 * Push VAPID Actions (admin only)
 * Actions: generateVapidKeys, saveVapidSubject
 *
 * Self-service VAPID key management for Web Push. Writes to
 * admin/config/notifications_config.php (gitignored) via an atomic
 * temp-file + rename, then invalidates opcache so the new keys are
 * live without restarting PHP-FPM.
 *
 * Hidden when VAPID_PUBLIC_KEY env var is set — Docker installs manage
 * keys through the container environment instead.
 */

// ─── GENERATE / ROTATE ──────────────────────────────────────────────────────

if (($_POST['action'] ?? '') == 'generateVapidKeys') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    if (vapid_is_env_managed()) {
        $errs['env'] = 'VAPID keys are managed via container environment variables. Update them in your Docker env, not here.';
    }

    $subject = trim($_POST['vapid_subject'] ?? '');
    if (!$subject) {
        global $vapid_subject;
        $subject = $vapid_subject ?: '';
    }
    if (!$subject) {
        $errs['subject'] = 'Subject (mailto:...) is required.';
    } elseif (!preg_match('#^(mailto:|https?://)#i', $subject)) {
        $errs['subject'] = 'Subject must start with "mailto:" or a https:// URL.';
    }

    if (count($errs) <= 0) {
        try {
            $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
        } catch (\Throwable $e) {
            $errs['gen'] = 'Failed to generate VAPID keys: ' . $e->getMessage();
            $keys = null;
        }

        if ($keys) {
            $public  = $keys['publicKey'];
            $private = $keys['privateKey'];

            if (!vapid_config_writable()) {
                // Read-only file system → return key pair with copy/paste instructions.
                // Do NOT pretend success.
                $_SESSION['warning'] = 'Config directory is not writable. The keys below were NOT saved — copy them into admin/config/notifications_config.php manually, then bounce PHP-FPM.';
                $data['saved']           = false;
                $data['vapid_subject']   = $subject;
                $data['vapid_public']    = $public;
                $data['vapid_private']   = $private;
                $data['config_path']     = vapid_config_path();
                $data['paste_snippet']   = "<?php\n\$vapid_subject     = " . var_export($subject, true) . ";\n\$vapid_public_key  = " . var_export($public, true) . ";\n\$vapid_private_key = " . var_export($private, true) . ";\n";
            } else {
                $write = write_vapid_config_atomically($subject, $public, $private);
                if ($write['ok']) {
                    // Make the new values live in this request too, so the status
                    // line reflects them on the post-action page render.
                    $GLOBALS['vapid_subject']     = $subject;
                    $GLOBALS['vapid_public_key']  = $public;
                    $GLOBALS['vapid_private_key'] = $private;

                    $_SESSION['success']    = 'VAPID keys generated and saved. Push notifications are now configured.';
                    $data['saved']          = true;
                    $data['vapid_subject']  = $subject;
                    $data['vapid_public']   = $public;
                } else {
                    $errs['write'] = $write['error'];
                }
            }
        }
    }

    if (count($errs) > 0) {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── SUBJECT-ONLY UPDATE ────────────────────────────────────────────────────
// Save the mailto: subject without rotating the keys.

if (($_POST['action'] ?? '') == 'saveVapidSubject') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    if (vapid_is_env_managed()) {
        $errs['env'] = 'VAPID keys are managed via container environment variables. Update them in your Docker env, not here.';
    }

    $subject = trim($_POST['vapid_subject'] ?? '');
    if (!$subject) {
        $errs['subject'] = 'Subject (mailto:...) is required.';
    } elseif (!preg_match('#^(mailto:|https?://)#i', $subject)) {
        $errs['subject'] = 'Subject must start with "mailto:" or a https:// URL.';
    }

    if (count($errs) <= 0) {
        global $vapid_public_key, $vapid_private_key;
        $public  = $vapid_public_key  ?? '';
        $private = $vapid_private_key ?? '';

        if (!vapid_config_writable()) {
            $errs['fs'] = 'Config directory is not writable.';
        } else {
            $write = write_vapid_config_atomically($subject, $public, $private);
            if ($write['ok']) {
                $GLOBALS['vapid_subject'] = $subject;
                $_SESSION['success'] = 'VAPID subject saved.';
                $data['vapid_subject'] = $subject;
            } else {
                $errs['write'] = $write['error'];
            }
        }
    }

    if (count($errs) > 0) {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}
