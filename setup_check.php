<?php
/**
 * setup_check.php
 * Verifies deployment prerequisites.
 * Web-accessible but displays no sensitive information.
 */
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Check — Wave Networks</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 600px; margin: 40px auto; padding: 0 20px; color: #333; }
        h1 { color: #2c3e50; }
        .check { padding: 8px 0; border-bottom: 1px solid #eee; }
        .pass { color: #27ae60; }
        .fail { color: #e74c3c; }
        .warn { color: #f39c12; }
        .pass::before { content: "✓ "; font-weight: bold; }
        .fail::before { content: "✗ "; font-weight: bold; }
        .warn::before { content: "⚠ "; font-weight: bold; }
        code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; }
        .critical { background: #fde8e8; border: 1px solid #e74c3c; padding: 12px 16px; border-radius: 6px; margin: 16px 0; }
    </style>
</head>
<body>

<h1>Wave Networks — Setup Check</h1>

<?php
$configExists = file_exists(__DIR__ . '/config/config.php');

// Determine files_location
if ($configExists) {
    include(__DIR__ . '/config/config.php');
    $filesLoc = $files_location ?? '';
} else {
    $filesLoc = getenv('FILES_LOCATION') ?: '';
}
?>

<div class="check <?= $configExists ? 'pass' : 'fail' ?>">
    config/config.php <?= $configExists ? 'exists' : 'missing — run the installer or create manually' ?>
</div>

<?php if ($filesLoc) { ?>
<div class="check <?= is_dir($filesLoc) ? 'pass' : 'fail' ?>">
    Files directory (<code><?= htmlspecialchars($filesLoc) ?></code>) <?= is_dir($filesLoc) ? 'exists' : 'does NOT exist' ?>
</div>

<div class="check <?= is_dir($filesLoc . 'home/') ? 'pass' : 'fail' ?>">
    Files home directory (<code><?= htmlspecialchars($filesLoc . 'home/') ?></code>) <?= is_dir($filesLoc . 'home/') ? 'exists' : 'does NOT exist' ?>
</div>

<?php if (is_dir($filesLoc)) { ?>
<div class="check <?= is_writable($filesLoc) ? 'pass' : 'fail' ?>">
    Files directory is <?= is_writable($filesLoc) ? 'writable' : 'NOT writable' ?>
</div>
<?php } ?>
<?php } else { ?>
<div class="check fail">
    Cannot determine files_location — config not loaded
</div>
<?php } ?>

<div class="check <?= file_exists(__DIR__ . '/vendor/autoload.php') ? 'pass' : 'fail' ?>">
    Composer vendor/autoload.php <?= file_exists(__DIR__ . '/vendor/autoload.php') ? 'exists' : 'missing — run composer install' ?>
</div>

<div class="check <?= extension_loaded('pdo_mysql') ? 'pass' : 'fail' ?>">
    PHP PDO MySQL extension <?= extension_loaded('pdo_mysql') ? 'loaded' : 'NOT loaded' ?>
</div>

<div class="check pass">
    PHP version: <?= htmlspecialchars(PHP_VERSION) ?>
</div>

<?php if (is_dir(__DIR__ . '/install')) { ?>
<div class="critical">
    <strong class="fail">CRITICAL: The install/ directory still exists!</strong><br>
    Delete it immediately: <code><?= htmlspecialchars(realpath(__DIR__ . '/install')) ?></code>
</div>
<?php } else { ?>
<div class="check pass">
    install/ directory does not exist (good)
</div>
<?php } ?>

<p style="margin-top: 20px; color: #999; font-size: 0.85em;">wave-networks-core setup check</p>

</body>
</html>
