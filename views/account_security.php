<?php
/**
 * views/account_security.php
 * User account & security settings: password change, 2FA, connected OAuth.
 * Available to ALL authenticated users (no admin role required).
 */
$page_title = 'Account & Security';

$user_id = (int)$_SESSION['user_id'];
$user = get_user($user_id);

// Get connected OAuth info
$oauth_provider = $user['oauth_provider'] ?? null;
$oauth_id = $user['oauth_id'] ?? null;

// Get enabled OAuth providers for "Connect" buttons
$available_providers = db_fetch_all(db_query("SELECT * FROM oauth_provider WHERE is_enabled = 1 ORDER BY provider_name"));

// 2FA state
$totp_enabled = !empty($user['totp_enabled']);
$totp_secret = $user['totp_secret'] ?? null;

// Check if enable2FA was just triggered (secret generated but not yet verified)
$setup_pending = !$totp_enabled && !empty($totp_secret);
$qr_code = null;
if ($setup_pending) {
    $qr_code = totp_qr_code($_SESSION['email'], $totp_secret);
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3><i class="bi bi-shield-lock"></i> Account & Security</h3>
</div>

<div class="row">
    <!-- Left column: Password & 2FA -->
    <div class="col-lg-7">

        <!-- Change Password -->
        <div class="card mb-4">
            <div class="card-header"><strong>Change Password</strong></div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="changeOwnPassword">

                    <div class="mb-3">
                        <label for="current_password" class="form-label">Current Password</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required autocomplete="current-password">
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="new_password" class="form-label">New Password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
                            <div class="form-text">Minimum 8 characters.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">Update Password</button>
                </form>
            </div>
        </div>

        <!-- Two-Factor Authentication -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Two-Factor Authentication (2FA)</strong>
                <?php if ($totp_enabled) { ?>
                <span class="badge bg-success"><i class="bi bi-check-circle"></i> Enabled</span>
                <?php } else { ?>
                <span class="badge bg-secondary">Disabled</span>
                <?php } ?>
            </div>
            <div class="card-body">

                <?php if ($totp_enabled) { ?>
                <!-- 2FA is active — show disable option -->
                <p class="text-success mb-3">
                    <i class="bi bi-shield-check"></i>
                    Your account is protected with two-factor authentication. You will be prompted for a code from your authenticator app each time you log in.
                </p>
                <hr>
                <p class="text-muted small mb-2">To disable 2FA, enter your password to confirm:</p>
                <form method="post">
                    <input type="hidden" name="action" value="disable2FA">
                    <div class="row align-items-end">
                        <div class="col-md-6 mb-2">
                            <input type="password" class="form-control" name="password" placeholder="Your password" required autocomplete="current-password">
                        </div>
                        <div class="col-md-6 mb-2">
                            <button type="submit" class="btn btn-outline-danger" onclick="return confirm('Are you sure you want to disable two-factor authentication?')">
                                <i class="bi bi-shield-x"></i> Disable 2FA
                            </button>
                        </div>
                    </div>
                </form>

                <?php } elseif ($setup_pending && $qr_code) { ?>
                <!-- 2FA setup in progress — show QR code and verify form -->
                <div class="alert alert-info mb-3">
                    <i class="bi bi-info-circle"></i>
                    Scan this QR code with your authenticator app (Google Authenticator, Authy, 1Password, etc.), then enter the 6-digit code to complete setup.
                </div>

                <div class="text-center mb-3">
                    <img src="<?= h($qr_code) ?>" alt="2FA QR Code" style="max-width: 200px; border-radius: 8px; border: 2px solid var(--bs-border-color);">
                </div>

                <p class="text-center text-muted small mb-3">
                    Can't scan? Enter this secret manually:<br>
                    <code class="user-select-all"><?= h($totp_secret) ?></code>
                </p>

                <form method="post">
                    <input type="hidden" name="action" value="verify2FASetup">
                    <div class="row justify-content-center">
                        <div class="col-md-6">
                            <label for="totp_code" class="form-label">6-Digit Code</label>
                            <input type="text" class="form-control text-center" id="totp_code" name="totp_code"
                                   maxlength="6" pattern="\d{6}" inputmode="numeric" autocomplete="one-time-code"
                                   placeholder="000000" required
                                   style="font-family: monospace; font-size: 1.5rem; letter-spacing: 0.3em;">
                        </div>
                    </div>
                    <div class="text-center mt-3">
                        <button type="submit" class="btn btn-success"><i class="bi bi-check-lg"></i> Verify & Enable 2FA</button>
                    </div>
                </form>

                <?php } else { ?>
                <!-- 2FA not set up — show enable button -->
                <p class="mb-3">
                    Add an extra layer of security to your account. When enabled, you will need to enter a code from your authenticator app each time you log in.
                </p>
                <p class="text-muted small mb-3">
                    Works with any TOTP authenticator app: Google Authenticator, Authy, 1Password, Microsoft Authenticator, etc.
                </p>
                <form method="post">
                    <input type="hidden" name="action" value="enable2FA">
                    <button type="submit" class="btn btn-success"><i class="bi bi-shield-plus"></i> Set Up 2FA</button>
                </form>
                <?php } ?>

            </div>
        </div>
    </div>

    <!-- Right column: Connected accounts & info -->
    <div class="col-lg-5">

        <!-- Connected OAuth Accounts -->
        <div class="card mb-4">
            <div class="card-header"><strong>Connected Accounts</strong></div>
            <div class="card-body">
                <?php if ($oauth_provider) { ?>
                <div class="d-flex align-items-center mb-3 p-2 border rounded">
                    <i class="bi bi-<?= $oauth_provider === 'github' ? 'github' : ($oauth_provider === 'facebook' ? 'facebook' : 'google') ?> fs-4 me-3"></i>
                    <div>
                        <strong><?= h(ucfirst($oauth_provider)) ?></strong>
                        <div class="text-muted small">Connected</div>
                    </div>
                    <span class="badge bg-success ms-auto"><i class="bi bi-link-45deg"></i> Linked</span>
                </div>
                <?php } ?>

                <?php if (empty($available_providers)) { ?>
                <p class="text-muted small">No OAuth providers are configured by the administrator.</p>
                <?php } else { ?>
                    <?php foreach ($available_providers as $p) { ?>
                        <?php if ($p['provider_name'] === $oauth_provider) continue; ?>
                <a href="../auth/oauth_callback.php?provider=<?= h($p['provider_name']) ?>" class="btn btn-outline-secondary btn-sm me-2 mb-2">
                    <i class="bi bi-<?= $p['provider_name'] === 'github' ? 'github' : ($p['provider_name'] === 'facebook' ? 'facebook' : 'google') ?>"></i>
                    Connect <?= h(ucfirst($p['provider_name'])) ?>
                </a>
                    <?php } ?>
                <?php } ?>

                <?php if (!$oauth_provider && empty($available_providers)) { ?>
                <p class="text-muted">No accounts connected.</p>
                <?php } ?>
            </div>
        </div>

        <!-- Account Info -->
        <div class="card mb-4">
            <div class="card-header"><strong>Account Info</strong></div>
            <div class="card-body">
                <p><strong>Email:</strong> <?= h($user['email']) ?></p>
                <p><strong>User ID:</strong> <?= h($user['user_id']) ?></p>
                <p><strong>Shard:</strong> <?= h($user['shard_id']) ?></p>
                <p><strong>Member Since:</strong> <?= h($user['created_date'] ?? 'N/A') ?></p>
                <p class="mb-0"><strong>Last Login:</strong> <?= h($user['last_login'] ?? 'N/A') ?></p>
            </div>
        </div>

    </div>
</div>
