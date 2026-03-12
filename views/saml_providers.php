<?php
/**
 * views/saml_providers.php
 * Manage SAML 2.0 identity providers (Shibboleth/InCommon)
 */
$page_title = 'SAML Providers';

if (!has_role('admin')) {
    $_SESSION['error'] = 'Admin access required.';
    header('Location: index.php?page=dashboard');
    exit;
}

$saml_providers = db_fetch_all(db_query("SELECT * FROM saml_provider ORDER BY display_name"));

// If editing, load the provider
$editing = null;
if (!empty($_GET['edit'])) {
    $editing = get_saml_provider((int)$_GET['edit']);
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>SAML Providers</h3>
</div>

<!-- Existing providers -->
<div class="card mb-4">
    <div class="card-body p-0">
        <?php if (empty($saml_providers)) { ?>
        <div class="p-4 text-center text-muted">
            <p>No SAML providers configured. Add one below.</p>
        </div>
        <?php } else { ?>
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Slug</th>
                    <th>IdP Entity ID</th>
                    <th>Status</th>
                    <th>SP Metadata</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($saml_providers as $sp) { ?>
                <tr>
                    <td><?= h($sp['display_name']) ?></td>
                    <td><code><?= h($sp['slug']) ?></code></td>
                    <td><code><?= h(nicetrim($sp['idp_entity_id'], 40)) ?></code></td>
                    <td>
                        <?php if ($sp['is_enabled']) { ?>
                        <span class="badge bg-success">Enabled</span>
                        <?php } else { ?>
                        <span class="badge bg-secondary">Disabled</span>
                        <?php } ?>
                    </td>
                    <td>
                        <a href="../auth/saml_metadata.php?provider=<?= h($sp['slug']) ?>"
                           target="_blank" class="btn btn-sm btn-outline-info">
                            View XML
                        </a>
                    </td>
                    <td>
                        <a href="index.php?page=saml_providers&edit=<?= h($sp['saml_provider_id']) ?>"
                           class="btn btn-sm btn-outline-primary">Edit</a>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="action" value="toggleSamlProvider">
                            <input type="hidden" name="saml_provider_id" value="<?= h($sp['saml_provider_id']) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-<?= $sp['is_enabled'] ? 'warning' : 'success' ?>">
                                <?= $sp['is_enabled'] ? 'Disable' : 'Enable' ?>
                            </button>
                        </form>
                        <form method="post" class="d-inline"
                              onsubmit="return confirm('Delete this SAML provider?')">
                            <input type="hidden" name="action" value="deleteSamlProvider">
                            <input type="hidden" name="saml_provider_id" value="<?= h($sp['saml_provider_id']) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
        <?php } ?>
    </div>
</div>

<!-- Add/Edit form -->
<div class="card">
    <div class="card-header">
        <strong><?= $editing ? 'Edit Provider: ' . h($editing['display_name']) : 'Add SAML Provider' ?></strong>
    </div>
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="action" value="saveSamlProvider">
            <?php if ($editing) { ?>
            <input type="hidden" name="saml_provider_id" value="<?= h($editing['saml_provider_id']) ?>">
            <?php } ?>

            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="display_name" class="form-label">Display Name</label>
                    <input type="text" class="form-control" id="display_name" name="display_name"
                           value="<?= h($editing['display_name'] ?? '') ?>" required
                           placeholder="e.g. University of Michigan Health">
                    <small class="form-text text-muted">Shown on the login button</small>
                </div>
                <div class="col-md-6">
                    <label for="slug" class="form-label">URL Slug</label>
                    <input type="text" class="form-control" id="slug" name="slug"
                           value="<?= h($editing['slug'] ?? '') ?>" required
                           placeholder="e.g. umich-health" pattern="[a-z0-9\-]+">
                    <small class="form-text text-muted">Used in callback URLs (lowercase, hyphens only)</small>
                </div>
            </div>

            <h6 class="mt-4 mb-3 text-muted">Identity Provider (IdP) Settings</h6>

            <div class="mb-3">
                <label for="idp_entity_id" class="form-label">IdP Entity ID</label>
                <input type="text" class="form-control" id="idp_entity_id" name="idp_entity_id"
                       value="<?= h($editing['idp_entity_id'] ?? '') ?>" required
                       placeholder="https://idp.example.edu/shibboleth">
            </div>

            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="idp_sso_url" class="form-label">IdP SSO URL</label>
                    <input type="url" class="form-control" id="idp_sso_url" name="idp_sso_url"
                           value="<?= h($editing['idp_sso_url'] ?? '') ?>" required
                           placeholder="https://idp.example.edu/idp/profile/SAML2/Redirect/SSO">
                </div>
                <div class="col-md-6">
                    <label for="idp_slo_url" class="form-label">IdP SLO URL <span class="text-muted">(optional)</span></label>
                    <input type="url" class="form-control" id="idp_slo_url" name="idp_slo_url"
                           value="<?= h($editing['idp_slo_url'] ?? '') ?>"
                           placeholder="https://idp.example.edu/idp/profile/SAML2/Redirect/SLO">
                </div>
            </div>

            <div class="mb-3">
                <label for="idp_x509_cert" class="form-label">IdP X.509 Certificate</label>
                <textarea class="form-control font-monospace" id="idp_x509_cert" name="idp_x509_cert"
                          rows="6" required placeholder="Paste the certificate here (PEM format, with or without BEGIN/END markers)"
><?= h($editing['idp_x509_cert'] ?? '') ?></textarea>
                <small class="form-text text-muted">From the IdP metadata XML &mdash; the X509Certificate value</small>
            </div>

            <h6 class="mt-4 mb-3 text-muted">Service Provider (SP) Settings</h6>

            <div class="mb-3">
                <label for="sp_entity_id" class="form-label">SP Entity ID <span class="text-muted">(optional)</span></label>
                <input type="text" class="form-control" id="sp_entity_id" name="sp_entity_id"
                       value="<?= h($editing['sp_entity_id'] ?? '') ?>"
                       placeholder="Leave blank to auto-generate from site URL">
            </div>

            <h6 class="mt-4 mb-3 text-muted">Attribute Mapping</h6>
            <p class="text-muted small">Map IdP attribute names (OIDs) to user fields. Defaults work for standard InCommon/Shibboleth.</p>

            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="attr_email" class="form-label">Email Attribute</label>
                    <input type="text" class="form-control" id="attr_email" name="attr_email"
                           value="<?= h($editing['attr_email'] ?? 'urn:oid:0.9.2342.19200300.100.1.3') ?>">
                </div>
                <div class="col-md-6">
                    <label for="attr_display_name" class="form-label">Display Name Attribute</label>
                    <input type="text" class="form-control" id="attr_display_name" name="attr_display_name"
                           value="<?= h($editing['attr_display_name'] ?? 'urn:oid:2.16.840.1.113730.3.1.241') ?>">
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="attr_first_name" class="form-label">First Name Attribute</label>
                    <input type="text" class="form-control" id="attr_first_name" name="attr_first_name"
                           value="<?= h($editing['attr_first_name'] ?? 'urn:oid:2.5.4.42') ?>">
                </div>
                <div class="col-md-6">
                    <label for="attr_last_name" class="form-label">Last Name Attribute</label>
                    <input type="text" class="form-control" id="attr_last_name" name="attr_last_name"
                           value="<?= h($editing['attr_last_name'] ?? 'urn:oid:2.5.4.4') ?>">
                </div>
            </div>

            <h6 class="mt-4 mb-3 text-muted">Security</h6>
            <div class="row mb-3">
                <div class="col-md-4">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="want_assertions_signed"
                               name="want_assertions_signed" value="1"
                               <?= ($editing['want_assertions_signed'] ?? 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="want_assertions_signed">Require signed assertions</label>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="want_nameid_encrypted"
                               name="want_nameid_encrypted" value="1"
                               <?= ($editing['want_nameid_encrypted'] ?? 0) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="want_nameid_encrypted">Require encrypted NameID</label>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <label for="authn_context" class="form-label">Authentication Context</label>
                <input type="text" class="form-control" id="authn_context" name="authn_context"
                       value="<?= h($editing['authn_context'] ?? 'urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport') ?>">
                <small class="form-text text-muted">Comma-separated if multiple. Leave default for standard password auth.</small>
            </div>

            <button type="submit" class="btn btn-primary">
                <?= $editing ? 'Update Provider' : 'Add Provider' ?>
            </button>
            <?php if ($editing) { ?>
            <a href="index.php?page=saml_providers" class="btn btn-secondary">Cancel</a>
            <?php } ?>
        </form>
    </div>
</div>
