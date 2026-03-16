<?php
/**
 * jsErrorActions.php
 * Receives JavaScript errors from the client-side error reporter.
 * Actions: logJsError
 */

if (($_POST['action'] ?? '') == 'logJsError') {
    $errs = array();
    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (empty($_POST['message'])) { $errs['message'] = 'Error message required.'; }

    if (count($errs) <= 0) {
        $message = $_POST['message'];
        $file    = $_POST['file'] ?? '';
        $line    = (int)($_POST['line'] ?? 0);
        $column  = (int)($_POST['column'] ?? 0);
        $stack   = $_POST['stack'] ?? '';
        $error_type = $_POST['error_type'] ?? 'uncaught';
        $source_app = $_POST['source_app'] ?? 'unknown';
        $page_url   = $_POST['page_url'] ?? '';
        $referrer   = $_POST['referrer'] ?? '';

        // Build context with JS-specific info
        $context = [];
        $context['error_type'] = $error_type;
        $context['column']     = $column;
        $context['page_url']   = $page_url;
        $context['referrer']   = $referrer;
        $context['origin']     = 'javascript';
        if (!empty($_SESSION['user_id'])) {
            $context['session'] = [
                'user_id'  => $_SESSION['user_id'],
                'email'    => $_SESSION['email'] ?? null,
                'shard_id' => $_SESSION['shard_id'] ?? null,
            ];
        }

        // Use log_error_to_db for consistent storage and deduplication
        // Temporarily override context building by calling directly with PDO
        $db = $GLOBALS['db'];
        $hash = md5($file . ':' . $line . ':' . $message);
        $context_json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        try {
            // Check for existing unresolved error with same hash
            $existing = $db->prepare(
                "SELECT error_id FROM error_log WHERE error_hash = :hash AND resolved_at IS NULL LIMIT 1"
            );
            $existing->execute([':hash' => $hash]);
            $existing_row = $existing->fetch(PDO::FETCH_ASSOC);

            if ($existing_row) {
                $update = $db->prepare(
                    "UPDATE error_log SET occurrence_count = occurrence_count + 1, last_seen_at = NOW() WHERE error_id = :id"
                );
                $update->execute([':id' => $existing_row['error_id']]);
            } else {
                // Parse page param from page_url for the page column
                $page = null;
                if ($page_url) {
                    $query_str = parse_url($page_url, PHP_URL_QUERY);
                    if ($query_str) {
                        parse_str($query_str, $url_params);
                        $page = $url_params['page'] ?? null;
                    }
                }

                $stmt = $db->prepare(
                    "INSERT INTO error_log
                        (level, message, file, line, stack_trace, context_json, source_app, page,
                         request_uri, request_method, user_id, ip_address, user_agent, php_version,
                         memory_usage, occurrence_count, last_seen_at, error_hash)
                     VALUES
                        ('ERROR', :message, :file, :line, :trace, :context, :source, :page,
                         :uri, 'JS', :uid, :ip, :ua, NULL, NULL, 1, NOW(), :hash)"
                );

                $stmt->execute([
                    ':message' => mb_substr($message, 0, 65535),
                    ':file'    => $file ? mb_substr($file, 0, 500) : null,
                    ':line'    => $line ?: null,
                    ':trace'   => $stack ?: null,
                    ':context' => $context_json,
                    ':source'  => mb_substr($source_app, 0, 50),
                    ':page'    => $page ? mb_substr($page, 0, 100) : null,
                    ':uri'     => $page_url ? mb_substr($page_url, 0, 500) : null,
                    ':uid'     => $_SESSION['user_id'] ?? null,
                    ':ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
                    ':ua'      => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
                    ':hash'    => $hash,
                ]);
            }

            // Silent success — no session flash to avoid alert noise in the UI
        } catch (\Throwable $e) {
            error_log("JS error log failed: " . $e->getMessage());
            $_SESSION['error'] = 'Failed to log JS error.';
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}
