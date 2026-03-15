<?php
/**
 * views/settings.php
 * System settings: registration mode, SMTP, reCAPTCHA.
 */
$page_title = 'Settings';

if (!has_role('admin')) {
    $_SESSION['error'] = 'Admin access required.';
    header('Location: index.php?page=dashboard');
    exit;
}

$settings = db_fetch(db_query("SELECT * FROM auth_settings WHERE setting_id = 1"));
$currentMode = $settings['registration_mode'] ?? 'open';
$branding = get_branding();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>Settings</h3>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header"><strong>Registration Mode</strong></div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="setRegistrationMode">

                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="registration_mode" id="mode_open" value="open" <?= $currentMode === 'open' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="mode_open">
                            <strong>Open</strong> — Anyone can register
                        </label>
                    </div>

                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="registration_mode" id="mode_confirm" value="confirm" <?= $currentMode === 'confirm' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="mode_confirm">
                            <strong>Email Confirmation</strong> — Must verify email
                        </label>
                    </div>

                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="registration_mode" id="mode_invite" value="invite" <?= $currentMode === 'invite' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="mode_invite">
                            <strong>Invite Only</strong> — Requires invite token
                        </label>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="radio" name="registration_mode" id="mode_closed" value="closed" <?= $currentMode === 'closed' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="mode_closed">
                            <strong>Closed</strong> — No new registrations
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary">Save</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header"><strong>System Info</strong></div>
            <div class="card-body">
                <p><strong>PHP Version:</strong> <?= h(PHP_VERSION) ?></p>
                <p><strong>Shards Configured:</strong> <?= count($shardConfigs ?? []) ?></p>
                <p><strong>SMTP:</strong> <?= !empty($smtp_host) ? h($smtp_host) : '<span class="text-muted">Not configured</span>' ?></p>
                <p><strong>reCAPTCHA:</strong> <?= recaptcha_enabled() ? 'Enabled' : '<span class="text-muted">Disabled</span>' ?></p>
                <p><strong>Files Location:</strong> <code><?= h($files_location ?? 'Not set') ?></code></p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card mb-4">
            <div class="card-header"><strong>Branding &amp; Manifest</strong></div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="saveBranding">

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="site_name" class="form-label">Site Name</label>
                            <input type="text" class="form-control" id="site_name" name="site_name" value="<?= h($branding['site_name']) ?>" maxlength="100">
                            <div class="form-text">Appears in titles, sidebar, and auth pages.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="site_short_name" class="form-label">Short Name</label>
                            <input type="text" class="form-control" id="site_short_name" name="site_short_name" value="<?= h($branding['site_short_name']) ?>" maxlength="30">
                            <div class="form-text">Used in the PWA manifest (max 30 chars).</div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="site_description" class="form-label">Description</label>
                            <input type="text" class="form-control" id="site_description" name="site_description" value="<?= h($branding['site_description']) ?>" maxlength="255">
                            <div class="form-text">PWA manifest description.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="theme_color" class="form-label">Theme Color</label>
                            <div class="input-group">
                                <input type="color" class="form-control form-control-color" id="theme_color_picker" value="<?= h($branding['theme_color']) ?>">
                                <input type="text" class="form-control" id="theme_color" name="theme_color" value="<?= h($branding['theme_color']) ?>" maxlength="7" pattern="#[0-9a-fA-F]{6}">
                            </div>
                            <div class="form-text">Browser toolbar / PWA theme color.</div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="logo" class="form-label">Logo</label>
                            <?php if (!empty($branding['logo_path'])) { ?>
                            <div class="mb-2">
                                <img src="../uploads/<?= h($branding['logo_path']) ?>" alt="Current logo" style="max-height: 48px;" class="border rounded p-1">
                            </div>
                            <?php } ?>
                            <input type="file" class="form-control" id="logo" name="logo" accept="image/png,image/jpeg,image/svg+xml,image/x-icon,image/webp">
                            <div class="form-text">Shown in sidebar and auth pages. PNG, JPG, SVG, ICO, or WebP. Max 2 MB.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="logo_dark" class="form-label">Logo (Dark Mode)</label>
                            <?php if (!empty($branding['logo_dark_path'])) { ?>
                            <div class="mb-2">
                                <img src="../uploads/<?= h($branding['logo_dark_path']) ?>" alt="Current dark logo" style="max-height: 48px;" class="border rounded p-1 bg-dark">
                                <div class="form-check mt-1">
                                    <input class="form-check-input" type="checkbox" id="remove_logo_dark" name="remove_logo_dark" value="1">
                                    <label class="form-check-label small" for="remove_logo_dark">Remove dark logo</label>
                                </div>
                            </div>
                            <?php } ?>
                            <input type="file" class="form-control" id="logo_dark" name="logo_dark" accept="image/png,image/jpeg,image/svg+xml,image/x-icon,image/webp">
                            <div class="form-text">Light-colored variant for dark backgrounds. Falls back to the main logo if not set.</div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="favicon" class="form-label">Favicon</label>
                            <?php if (!empty($branding['favicon_path'])) { ?>
                            <div class="mb-2">
                                <img src="../uploads/<?= h($branding['favicon_path']) ?>" alt="Current favicon" style="max-height: 32px;" class="border rounded p-1">
                            </div>
                            <?php } ?>
                            <input type="file" class="form-control" id="favicon" name="favicon" accept="image/png,image/jpeg,image/svg+xml,image/x-icon,image/vnd.microsoft.icon,image/webp">
                            <div class="form-text">Browser tab icon and PWA icon. Max 2 MB.</div>
                        </div>
                    </div>

                    <hr class="my-3">
                    <h6 class="mb-3">PWA Screenshots <small class="text-muted">(optional — enables richer install UI)</small></h6>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="pwa_screenshot_wide" class="form-label">Desktop Screenshot</label>
                            <?php if (!empty($branding['pwa_screenshot_wide'])) { ?>
                            <div class="mb-2">
                                <img src="../uploads/<?= h($branding['pwa_screenshot_wide']) ?>" alt="Desktop screenshot" style="max-height: 80px;" class="border rounded p-1">
                            </div>
                            <?php } ?>
                            <input type="file" class="form-control" id="pwa_screenshot_wide" name="pwa_screenshot_wide" accept="image/png,image/jpeg,image/webp">
                            <div class="form-text">Landscape, 1280×720 or wider. PNG, JPG, or WebP. Max 2 MB.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="pwa_screenshot_mobile" class="form-label">Mobile Screenshot</label>
                            <?php if (!empty($branding['pwa_screenshot_mobile'])) { ?>
                            <div class="mb-2">
                                <img src="../uploads/<?= h($branding['pwa_screenshot_mobile']) ?>" alt="Mobile screenshot" style="max-height: 80px;" class="border rounded p-1">
                            </div>
                            <?php } ?>
                            <input type="file" class="form-control" id="pwa_screenshot_mobile" name="pwa_screenshot_mobile" accept="image/png,image/jpeg,image/webp">
                            <div class="form-text">Portrait, 750×1334 or similar. PNG, JPG, or WebP. Max 2 MB.</div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">Save Branding</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var picker = document.getElementById('theme_color_picker');
    var text = document.getElementById('theme_color');
    if (picker && text) {
        picker.addEventListener('input', function() { text.value = this.value; });
        text.addEventListener('input', function() {
            if (/^#[0-9a-fA-F]{6}$/.test(this.value)) picker.value = this.value;
        });
    }
})();
</script>
