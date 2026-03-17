<?php
/**
 * install/index.php — Wave Networks Core Installer
 * Step 1: Collects database credentials, tests connections, generates secrets, writes config.php.
 * Step 2: Creates the first admin user.
 * Locks itself down via .htaccess after completion.
 */

// ─── PHP version pre-flight check ───────────────────────────────────────────
if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><title>PHP Version Error</title><style>body{font-family:sans-serif;max-width:600px;margin:60px auto;padding:20px;color:#333}.error{background:#fde8e8;border:1px solid #e74c3c;color:#c0392b;padding:16px;border-radius:8px}code{background:#f0f0f0;padding:2px 6px;border-radius:3px}</style></head><body>';
    echo '<div class="error"><h2>PHP 8.2+ Required</h2>';
    echo '<p>Your server is running <strong>PHP ' . PHP_VERSION . '</strong>.</p>';
    echo '<p>Wave Networks requires <strong>PHP 8.2 or higher</strong>.</p>';
    echo '<p>Update your PHP version in your hosting control panel (cPanel → MultiPHP Manager, or equivalent) and reload this page.</p>';
    echo '</div></body></html>';
    exit;
}

// ─── Check required extensions ──────────────────────────────────────────────
$requiredExts = ['pdo_mysql', 'gd', 'mbstring'];
$missingExts = array_filter($requiredExts, fn($ext) => !extension_loaded($ext));
if (!empty($missingExts)) {
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><title>Missing Extensions</title><style>body{font-family:sans-serif;max-width:600px;margin:60px auto;padding:20px;color:#333}.error{background:#fde8e8;border:1px solid #e74c3c;color:#c0392b;padding:16px;border-radius:8px}code{background:#f0f0f0;padding:2px 6px;border-radius:3px}</style></head><body>';
    echo '<div class="error"><h2>Missing PHP Extensions</h2>';
    echo '<p>The following required extensions are not loaded: <strong>' . implode(', ', $missingExts) . '</strong></p>';
    echo '<p>Enable them in your PHP configuration and reload this page.</p>';
    echo '</div></body></html>';
    exit;
}

// ─── Prevent running if config already exists ───────────────────────────────
$configPath = __DIR__ . '/../config/config.php';
if (file_exists($configPath)) {
    if (getenv('ENVIRONMENT') !== 'development') {
        @file_put_contents(__DIR__ . '/.htaccess', "# Locked after install\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    http_response_code(403);
    exit;
}

$errors = [];
$success = false;
$step = 'config'; // 'config' or 'user'

// If config was just written (via session flag), go to step 2
session_start();
if (isset($_SESSION['install_config_done']) && file_exists($configPath)) {
    $step = 'user';
}

$maxShards = 20;
$shardCount = isset($_POST['shard_count']) ? (int)$_POST['shard_count'] : 1;
if ($shardCount < 1) $shardCount = 1;
if ($shardCount > $maxShards) $shardCount = $maxShards;

// ─── Auto-detect files_location ─────────────────────────────────────────────
// Default: one level above public_html, using __DIR__-relative path in config
// config.php is at admin/config/, so ../../../files/ = above public_html
$resolvedFilesPath = '';
$publicHtml = realpath(__DIR__ . '/../../');
if ($publicHtml) {
    $resolvedFilesPath = str_replace('\\', '/', dirname($publicHtml) . '/files/');
}

// ═══════════════════════════════════════════════════════════════════════════
// STEP 2: Create admin user
// ═══════════════════════════════════════════════════════════════════════════
if ($step === 'user' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_admin') {
    $adminEmail    = trim($_POST['admin_email'] ?? '');
    $adminPassword = $_POST['admin_password'] ?? '';
    $adminConfirm  = $_POST['admin_password_confirm'] ?? '';

    if (!$adminEmail) $errors[] = 'Email is required.';
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
    if (strlen($adminPassword) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($adminPassword !== $adminConfirm) $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        try {
            // Load config to get DB credentials and hiddenhash
            include $configPath;

            // Connect to main DB
            $pdo = new PDO("mysql:host=$dbHostSpec;dbname=$dbInstance;charset=utf8mb4", $dbUserName, $dbPassword);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Check if user table exists (migrations may not have run yet)
            $tables = $pdo->query("SHOW TABLES LIKE 'user'")->fetchAll();
            if (empty($tables)) {
                $errors[] = 'The user table does not exist yet. Visit the admin app once to trigger migrations, then come back here.';
            }

            if (empty($errors)) {
                // Check if email already exists
                $stmt = $pdo->prepare("SELECT user_id FROM user WHERE email = ?");
                $stmt->execute([$adminEmail]);
                if ($stmt->fetch()) {
                    $errors[] = 'A user with this email already exists.';
                }
            }

            if (empty($errors)) {
                // Hash password using same method as core
                $hashedPassword = password_hash($adminPassword . $hiddenhash, PASSWORD_BCRYPT);

                // Assign to shard 1 (first shard — least loaded logic not needed for first user)
                $shard_id = 'shard1';

                // Insert user as admin
                $now = date('Y-m-d H:i:s');
                $stmt = $pdo->prepare("INSERT INTO user (email, password, shard_id, is_admin, is_confirmed, created) VALUES (?, ?, ?, 1, 1, ?)");
                $stmt->execute([$adminEmail, $hashedPassword, $shard_id, $now]);
                $userId = $pdo->lastInsertId();

                // Create user_profile on shard 1
                $shard1 = $shardConfigs['shard1'] ?? null;
                if ($shard1) {
                    try {
                        $shardPdo = new PDO(
                            "mysql:host={$shard1['host']};dbname={$shard1['name']};charset=utf8mb4",
                            $shard1['user'], $shard1['pass']
                        );
                        $shardPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                        // Check if user_profile table exists
                        $profileTable = $shardPdo->query("SHOW TABLES LIKE 'user_profile'")->fetchAll();
                        if (!empty($profileTable)) {
                            $stmt = $shardPdo->prepare("INSERT INTO user_profile (user_id, first_name, last_name, created) VALUES (?, '', '', ?)");
                            $stmt->execute([$userId, $now]);
                        }
                        $shardPdo = null;
                    } catch (PDOException $e) {
                        // Non-fatal: profile can be created later
                    }
                }

                $success = true;
                unset($_SESSION['install_config_done']);
                session_destroy();

                // Lock down install directory
                if (getenv('ENVIRONMENT') !== 'development') {
                    @file_put_contents(__DIR__ . '/.htaccess', "# Locked after install\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
                }
            }

            $pdo = null;
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// STEP 1: Config + database setup
// ═══════════════════════════════════════════════════════════════════════════
if ($step === 'config' && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {

    $dbHost       = trim($_POST['db_host'] ?? 'localhost');
    $dbName       = trim($_POST['db_name'] ?? '');
    $dbUser       = trim($_POST['db_user'] ?? '');
    $dbPass       = $_POST['db_pass'] ?? '';

    // Collect shard credentials dynamically
    $shards = [];
    for ($i = 1; $i <= $shardCount; $i++) {
        $shard = [
            'host' => trim($_POST["shard{$i}_host"] ?? 'localhost'),
            'name' => trim($_POST["shard{$i}_name"] ?? ''),
            'user' => trim($_POST["shard{$i}_user"] ?? ''),
            'pass' => $_POST["shard{$i}_pass"] ?? '',
        ];
        if (!$shard['name']) {
            $errors[] = "Shard {$i} database name is required.";
        }
        $shards[$i] = $shard;
    }

    // files_location is always __DIR__-relative (one level above public_html)
    // config.php is at admin/config/ → ../../../files/ = above public_html
    $configDir   = realpath(__DIR__ . '/../config') ?: __DIR__ . '/../config';
    $filesLoc    = str_replace('\\', '/', $configDir . '/../../../files/');
    $smtpHost     = trim($_POST['smtp_host'] ?? '');
    $smtpPort     = (int)($_POST['smtp_port'] ?? 587);
    $smtpUser     = trim($_POST['smtp_user'] ?? '');
    $smtpPass     = $_POST['smtp_pass'] ?? '';
    $mailFrom     = trim($_POST['mail_from'] ?? '');
    $mailFromName = trim($_POST['mail_from_name'] ?? 'Admin');

    // Validate required fields
    if (!$dbName) $errors[] = 'Main database name is required.';
    if (!$dbUser) $errors[] = 'Main database user is required.';

    // Test DB connections
    if (empty($errors)) {
        try {
            $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo = null;
        } catch (PDOException $e) {
            $errors[] = "Main DB connection failed: " . $e->getMessage();
        }

        foreach ($shards as $i => $shard) {
            $sUser = $shard['user'] !== '' ? $shard['user'] : $dbUser;
            $sPass = $shard['pass'] !== '' ? $shard['pass'] : $dbPass;
            try {
                $pdo = new PDO("mysql:host={$shard['host']};dbname={$shard['name']};charset=utf8mb4", $sUser, $sPass);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo = null;
            } catch (PDOException $e) {
                $errors[] = "Shard {$i} DB connection failed: " . $e->getMessage();
            }
        }
    }

    // Auto-create files directory (one level above public_html)
    if (empty($errors) && $filesLoc) {
        if (!is_dir($filesLoc)) {
            if (!@mkdir($filesLoc, 0755, true)) {
                $errors[] = "Could not create files directory: {$filesLoc}. Create it manually and ensure it's writable.";
            }
        }
        foreach (['home/', 'branding/'] as $subdir) {
            $sub = $filesLoc . $subdir;
            if (empty($errors) && !is_dir($sub)) {
                @mkdir($sub, 0755, true);
            }
        }
    }

    // Write config.php
    if (empty($errors)) {
        $hiddenHash = bin2hex(random_bytes(32));
        $appSecret  = bin2hex(random_bytes(32));

        $config = "<?php\n";
        $config .= "// Generated by installer on " . date('Y-m-d H:i:s') . "\n\n";
        $config .= "\$dbHostSpec  = " . var_export($dbHost, true) . ";\n";
        $config .= "\$dbInstance  = " . var_export($dbName, true) . ";\n";
        $config .= "\$dbUserName  = " . var_export($dbUser, true) . ";\n";
        $config .= "\$dbPassword  = " . var_export($dbPass, true) . ";\n\n";
        $config .= "\$shardConfigs = [\n";

        foreach ($shards as $i => $shard) {
            $sUser = $shard['user'] !== '' ? $shard['user'] : $dbUser;
            $sPass = $shard['pass'] !== '' ? $shard['pass'] : $dbPass;
            $config .= "    'shard{$i}' => [\n";
            $config .= "        'host' => " . var_export($shard['host'], true) . ",\n";
            $config .= "        'name' => " . var_export($shard['name'], true) . ",\n";
            $config .= "        'user' => " . var_export($sUser, true) . ",\n";
            $config .= "        'pass' => " . var_export($sPass, true) . ",\n";
            $config .= "    ],\n";
        }

        $config .= "];\n\n";
        $config .= "\$hiddenhash     = " . var_export($hiddenHash, true) . ";\n";
        $config .= "\$app_secret     = " . var_export($appSecret, true) . ";\n\n";
        $config .= "\$files_location = __DIR__ . '/../../../files/';\n\n";
        $config .= "\$smtp_host      = " . var_export($smtpHost, true) . ";\n";
        $config .= "\$smtp_port      = " . var_export($smtpPort, true) . ";\n";
        $config .= "\$smtp_user      = " . var_export($smtpUser, true) . ";\n";
        $config .= "\$smtp_pass      = " . var_export($smtpPass, true) . ";\n";
        $config .= "\$mail_from      = " . var_export($mailFrom, true) . ";\n";
        $config .= "\$mail_from_name = " . var_export($mailFromName, true) . ";\n\n";
        $config .= "// OAuth (fill in later from admin panel or edit this file)\n";
        $config .= "\$google_client_id     = '';\n";
        $config .= "\$google_client_secret = '';\n";
        $config .= "\$github_client_id     = '';\n";
        $config .= "\$github_client_secret = '';\n";
        $config .= "\$facebook_app_id      = '';\n";
        $config .= "\$facebook_app_secret  = '';\n\n";
        $config .= "\$grecaptcha_key    = '';\n";
        $config .= "\$grecaptcha_secret = '';\n\n";
        $config .= "\$stripe_secret_key = '';\n";
        $config .= "\$stripe_public_key = '';\n\n";
        $config .= "\$vapid_subject     = '';\n";
        $config .= "\$vapid_public_key  = '';\n";
        $config .= "\$vapid_private_key = '';\n";

        if (file_put_contents($configPath, $config) !== false) {
            $_SESSION['install_config_done'] = true;
            $step = 'user';
            // Don't lock down yet — need to create admin user first
        } else {
            $errors[] = 'Could not write config/config.php. Check directory permissions.';
        }
    }
}

// Preserve submitted values for re-display
$v = [
    'db_host'        => $_POST['db_host'] ?? 'localhost',
    'db_name'        => $_POST['db_name'] ?? '',
    'db_user'        => $_POST['db_user'] ?? '',
    'db_pass'        => $_POST['db_pass'] ?? '',
    'files_location' => $resolvedFilesPath,
    'smtp_host'      => $_POST['smtp_host'] ?? '',
    'smtp_port'      => $_POST['smtp_port'] ?? '587',
    'smtp_user'      => $_POST['smtp_user'] ?? '',
    'smtp_pass'      => $_POST['smtp_pass'] ?? '',
    'mail_from'      => $_POST['mail_from'] ?? '',
    'mail_from_name' => $_POST['mail_from_name'] ?? 'Admin',
    'admin_email'    => $_POST['admin_email'] ?? '',
];
for ($i = 1; $i <= $maxShards; $i++) {
    $v["shard{$i}_host"] = $_POST["shard{$i}_host"] ?? 'localhost';
    $v["shard{$i}_name"] = $_POST["shard{$i}_name"] ?? '';
    $v["shard{$i}_user"] = $_POST["shard{$i}_user"] ?? '';
    $v["shard{$i}_pass"] = $_POST["shard{$i}_pass"] ?? '';
}
function e($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install — Wave Networks Core</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 640px; margin: 40px auto; padding: 0 20px; color: #333; background: #f8f9fa; }
        h1 { color: #2c3e50; margin-bottom: 8px; }
        h2 { color: #34495e; margin: 24px 0 12px; font-size: 1.1em; border-bottom: 2px solid #3498db; padding-bottom: 4px; }
        p.sub { color: #666; margin-bottom: 20px; }
        .error { background: #fde8e8; border: 1px solid #e74c3c; color: #c0392b; padding: 12px; border-radius: 6px; margin-bottom: 16px; }
        .error li { margin-left: 16px; }
        .success { background: #e8fde8; border: 1px solid #27ae60; color: #1e8449; padding: 16px; border-radius: 6px; }
        .success h2 { color: #1e8449; border: none; margin: 0 0 8px; }
        .warn { background: #fff3cd; border: 1px solid #ffc107; padding: 12px; border-radius: 6px; margin-top: 12px; color: #856404; }
        .info { background: #cfe2ff; border: 1px solid #6ea8fe; padding: 12px; border-radius: 6px; margin-bottom: 16px; color: #084298; }
        .hint { font-size: 0.8em; color: #888; margin-top: 2px; }
        label { display: block; margin: 10px 0 4px; font-weight: 600; font-size: 0.9em; }
        input[type="text"], input[type="password"], input[type="email"], input[type="number"], select {
            width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.95em;
        }
        input:focus, select:focus { outline: none; border-color: #3498db; box-shadow: 0 0 0 2px rgba(52,152,219,0.2); }
        .row { display: flex; gap: 12px; }
        .row > div { flex: 1; }
        button { background: #3498db; color: #fff; border: none; padding: 12px 32px; border-radius: 6px; font-size: 1em; cursor: pointer; margin-top: 24px; }
        button:hover { background: #2980b9; }
        fieldset { border: 1px solid #ddd; border-radius: 6px; padding: 12px 16px; margin-bottom: 8px; background: #fff; }
        legend { font-weight: 600; color: #2c3e50; padding: 0 6px; }
        .shard-count-row { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
        .shard-count-row label { margin: 0; white-space: nowrap; }
        .shard-count-row select { width: auto; min-width: 80px; }
        .step-indicator { display: flex; gap: 8px; margin-bottom: 24px; }
        .step-indicator .step { padding: 6px 16px; border-radius: 20px; font-size: 0.85em; font-weight: 600; }
        .step-indicator .step.active { background: #3498db; color: #fff; }
        .step-indicator .step.done { background: #27ae60; color: #fff; }
        .step-indicator .step.pending { background: #e9ecef; color: #adb5bd; }
        .php-info { background: #e8fde8; border: 1px solid #27ae60; padding: 8px 12px; border-radius: 6px; margin-bottom: 16px; font-size: 0.85em; color: #1e8449; }
    </style>
</head>
<body>

<?php if ($success) { ?>
<div class="success">
    <h2>Installation Complete</h2>
    <p><code>config/config.php</code> has been created and the first admin user has been set up.</p>
    <p>Migrations will run automatically on first page load.</p>
    <p style="margin-top: 12px;"><a href="../auth/login.php"><strong>Go to login page &rarr;</strong></a></p>
</div>
<div class="warn">
    The install directory has been locked down (permissions removed).
</div>
<?php } elseif ($step === 'user') { ?>

<h1>Wave Networks Core — Install</h1>
<div class="step-indicator">
    <span class="step done">1. Database</span>
    <span class="step active">2. Admin User</span>
</div>

<p class="sub">Config saved. Now create the first admin user.</p>

<div class="info">
    <strong>Note:</strong> If the <code>user</code> table doesn't exist yet, visit
    <a href="../app/index.php">/admin/app/</a> once to trigger migrations, then come back here.
</div>

<?php if (!empty($errors)) { ?>
<div class="error">
    <ul>
    <?php foreach ($errors as $err) { ?>
        <li><?= e($err) ?></li>
    <?php } ?>
    </ul>
</div>
<?php } ?>

<form method="post">
    <input type="hidden" name="action" value="create_admin">

    <h2>First Admin Account</h2>
    <fieldset>
        <legend>Admin User</legend>
        <label>Email</label>
        <input type="email" name="admin_email" value="<?= e($v['admin_email']) ?>" required placeholder="admin@yourdomain.com">

        <label>Password</label>
        <input type="password" name="admin_password" required minlength="8" placeholder="Minimum 8 characters">

        <label>Confirm Password</label>
        <input type="password" name="admin_password_confirm" required minlength="8">
    </fieldset>

    <button type="submit">Create Admin User &amp; Finish</button>
</form>

<?php } else { ?>

<h1>Wave Networks Core — Install</h1>
<div class="step-indicator">
    <span class="step active">1. Database</span>
    <span class="step pending">2. Admin User</span>
</div>

<div class="php-info">PHP <?= PHP_VERSION ?> &mdash; All required extensions loaded.</div>

<p class="sub">Fill in your database credentials. All connections will be tested before saving.</p>

<?php if (!empty($errors)) { ?>
<div class="error">
    <ul>
    <?php foreach ($errors as $err) { ?>
        <li><?= e($err) ?></li>
    <?php } ?>
    </ul>
</div>
<?php } ?>

<form method="post">

    <h2>Main Database</h2>
    <fieldset>
        <legend>wncore_main</legend>
        <div class="row">
            <div>
                <label>Host</label>
                <input type="text" name="db_host" value="<?= e($v['db_host']) ?>">
            </div>
            <div>
                <label>Database Name</label>
                <input type="text" name="db_name" value="<?= e($v['db_name']) ?>" required>
            </div>
        </div>
        <div class="row">
            <div>
                <label>Username</label>
                <input type="text" name="db_user" value="<?= e($v['db_user']) ?>" required>
            </div>
            <div>
                <label>Password</label>
                <input type="password" name="db_pass" value="<?= e($v['db_pass']) ?>">
            </div>
        </div>
    </fieldset>

    <h2>Shard Databases</h2>

    <div class="shard-count-row">
        <label for="shard_count">Number of Shards</label>
        <select name="shard_count" id="shard_count">
            <?php for ($i = 1; $i <= $maxShards; $i++) { ?>
            <option value="<?= $i ?>"<?php if ($i === $shardCount) { ?> selected<?php } ?>><?= $i ?></option>
            <?php } ?>
        </select>
    </div>
    <p class="hint" style="margin-bottom: 8px;">
        Start with 1 shard for small deployments. You can add more later.
        Users are distributed across shards automatically at registration.
    </p>
    <p class="hint" style="margin-bottom: 12px;">If shard user/pass are left blank, the main DB credentials are used.</p>

    <?php for ($i = 1; $i <= $maxShards; $i++) { ?>
    <fieldset class="shard-fieldset" id="shard-fieldset-<?= $i ?>" data-shard="<?= $i ?>"<?php if ($i > $shardCount) { ?> style="display:none"<?php } ?>>
        <legend>Shard <?= $i ?></legend>
        <div class="row">
            <div>
                <label>Host</label>
                <input type="text" name="shard<?= $i ?>_host" value="<?= e($v["shard{$i}_host"]) ?>">
            </div>
            <div>
                <label>Database Name</label>
                <input type="text" name="shard<?= $i ?>_name" value="<?= e($v["shard{$i}_name"]) ?>"<?php if ($i <= $shardCount) { ?> required<?php } ?>>
            </div>
        </div>
        <div class="row">
            <div>
                <label>Username</label>
                <input type="text" name="shard<?= $i ?>_user" value="<?= e($v["shard{$i}_user"]) ?>" placeholder="(same as main)">
            </div>
            <div>
                <label>Password</label>
                <input type="password" name="shard<?= $i ?>_pass" value="<?= e($v["shard{$i}_pass"]) ?>" placeholder="(same as main)">
            </div>
        </div>
    </fieldset>
    <?php } ?>

    <h2>File Storage</h2>
    <label>Files Directory</label>
    <input type="text" value="<?= e($v['files_location']) ?>" readonly disabled>
    <p class="hint">Auto-detected: one level above public_html. Created automatically on first request.</p>

    <h2>Email (SMTP)</h2>
    <fieldset>
        <legend>Optional — configure later in admin panel</legend>
        <div class="row">
            <div>
                <label>SMTP Host</label>
                <input type="text" name="smtp_host" value="<?= e($v['smtp_host']) ?>">
            </div>
            <div>
                <label>Port</label>
                <input type="number" name="smtp_port" value="<?= e($v['smtp_port']) ?>">
            </div>
        </div>
        <div class="row">
            <div>
                <label>SMTP Username</label>
                <input type="text" name="smtp_user" value="<?= e($v['smtp_user']) ?>">
            </div>
            <div>
                <label>SMTP Password</label>
                <input type="password" name="smtp_pass" value="<?= e($v['smtp_pass']) ?>">
            </div>
        </div>
        <div class="row">
            <div>
                <label>From Address</label>
                <input type="text" name="mail_from" value="<?= e($v['mail_from']) ?>" placeholder="noreply@yourdomain.com">
            </div>
            <div>
                <label>From Name</label>
                <input type="text" name="mail_from_name" value="<?= e($v['mail_from_name']) ?>">
            </div>
        </div>
    </fieldset>

    <button type="submit">Test Connections &amp; Save Config</button>
</form>

<script>
(function() {
    var sel = document.getElementById('shard_count');
    sel.addEventListener('change', function() {
        var count = parseInt(this.value, 10);
        var fieldsets = document.querySelectorAll('.shard-fieldset');
        for (var i = 0; i < fieldsets.length; i++) {
            var shardNum = parseInt(fieldsets[i].getAttribute('data-shard'), 10);
            var show = shardNum <= count;
            fieldsets[i].style.display = show ? '' : 'none';
            var nameInput = fieldsets[i].querySelector('input[name$="_name"]');
            if (nameInput) {
                if (show) {
                    nameInput.setAttribute('required', 'required');
                } else {
                    nameInput.removeAttribute('required');
                    nameInput.value = '';
                }
            }
        }
    });
})();
</script>

<?php } ?>

</body>
</html>
