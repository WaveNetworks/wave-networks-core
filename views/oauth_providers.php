<?php
/**
 * views/oauth_providers.php
 * Manage OAuth providers (Google, GitHub, Facebook, etc.)
 */
$page_title = 'OAuth Providers';

if (!has_role('admin')) {
    $_SESSION['error'] = 'Admin access required.';
    header('Location: index.php?page=dashboard');
    exit;
}

$providers = db_fetch_all(db_query("SELECT * FROM oauth_provider ORDER BY provider_name"));
// A failed save hands back what was typed (oauthActions.php), so nothing has to be entered twice.
$f = $_SESSION['oauth_form'] ?? array();
unset($_SESSION['oauth_form']);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>OAuth Providers</h3>
</div>

<!-- Existing providers -->
<div class="card mb-4">
    <div class="card-body p-0">
        <?php if (empty($providers)) { ?>
        <div class="p-4 text-center text-muted">
            <p>No OAuth providers configured. Add one below.</p>
        </div>
        <?php } else { ?>
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Provider</th>
                    <th>Client ID</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($providers as $p) { ?>
                <tr>
                    <td><?= h(ucfirst($p['provider_name'])) ?></td>
                    <td><code><?= h(nicetrim($p['client_id'], 30)) ?></code></td>
                    <td>
                        <?php if ($p['is_enabled']) { ?>
                        <span class="badge bg-success">Enabled</span>
                        <?php } else { ?>
                        <span class="badge bg-secondary">Disabled</span>
                        <?php } ?>
                    </td>
                    <td>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="action" value="toggleOAuthProvider">
                            <input type="hidden" name="provider_id" value="<?= h($p['provider_id']) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-<?= $p['is_enabled'] ? 'warning' : 'success' ?>">
                                <?= $p['is_enabled'] ? 'Disable' : 'Enable' ?>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
        <?php } ?>
    </div>
</div>

<!-- Add/Edit provider form -->
<div class="card">
    <div class="card-header"><strong>Add Provider</strong></div>
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="action" value="saveOAuthProvider">

            <div class="row mb-3">
                <div class="col-md-4">
                    <label for="provider_name" class="form-label">Provider Name</label>
                    <select class="form-select" id="provider_name" name="provider_name" required>
                        <option value="">Select...</option>
                        <option value="google"<?= ($f['provider_name'] ?? '') === 'google' ? ' selected' : '' ?>>Google</option>
                        <option value="github"<?= ($f['provider_name'] ?? '') === 'github' ? ' selected' : '' ?>>GitHub</option>
                        <option value="facebook"<?= ($f['provider_name'] ?? '') === 'facebook' ? ' selected' : '' ?>>Facebook</option>
                        <option value="apple"<?= ($f['provider_name'] ?? '') === 'apple' ? ' selected' : '' ?>>Apple</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="client_id" class="form-label">Client ID</label>
                    <input type="text" class="form-control" id="client_id" name="client_id" value="<?= h($f['client_id'] ?? '') ?>" required>
                </div>
                <div class="col-md-4">
                    <label for="client_secret" class="form-label">Client Secret</label>
                    <input type="text" class="form-control" id="client_secret" name="client_secret" value="<?= h($f['client_secret'] ?? '') ?>">
                </div>
            </div>

            <!-- Apple only: Client ID above = the Services ID; these three replace the Client Secret. -->
            <div class="row mb-3" id="appleFields">
                <div class="col-12"><p class="form-text mb-2">Apple: Client ID = your <strong>Services ID</strong> (e.g. com.example.web). Leave Client Secret empty and fill these:</p></div>
                <div class="col-md-3">
                    <label for="apple_team_id" class="form-label">Apple Team ID</label>
                    <input type="text" class="form-control" id="apple_team_id" name="apple_team_id" value="<?= h($f['apple_team_id'] ?? '') ?>" maxlength="10" autocomplete="off">
                </div>
                <div class="col-md-3">
                    <label for="apple_key_id" class="form-label">Key ID</label>
                    <input type="text" class="form-control" id="apple_key_id" name="apple_key_id" value="<?= h($f['apple_key_id'] ?? '') ?>" maxlength="10" autocomplete="off">
                </div>
                <div class="col-md-6">
                    <label for="apple_private_key" class="form-label">Private key (.p8 contents)</label>
                    <textarea class="form-control font-monospace" id="apple_private_key" name="apple_private_key" rows="4" autocomplete="off"><?= h($f['apple_private_key'] ?? '') ?></textarea>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Add Provider</button>
        </form>
    </div>
</div>
