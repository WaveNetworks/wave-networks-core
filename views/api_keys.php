<?php
/**
 * views/api_keys.php
 * Admin-only service API key management.
 */
if (!has_role('admin')) {
    $_SESSION['error'] = 'Admin access required.';
    header('Location: index.php?page=dashboard');
    exit;
}
$page_title = 'API Keys';
$available_scopes = get_available_scopes();
?>

<h4 class="mb-3"><i class="bi bi-key me-2"></i>API Keys</h4>

<!-- Key reveal alert (hidden by default, shown after creation) -->
<div class="alert alert-warning d-none" id="keyRevealAlert">
    <h6 class="alert-heading"><i class="bi bi-exclamation-triangle me-1"></i> Copy your API key now</h6>
    <p class="mb-2">This key will not be shown again. Store it somewhere safe.</p>
    <div class="input-group mb-2">
        <input type="text" class="form-control font-monospace bg-dark text-light" id="revealedKey" readonly>
        <button class="btn btn-outline-light" type="button" onclick="copyKey()">
            <i class="bi bi-clipboard"></i> Copy
        </button>
    </div>
    <button class="btn btn-sm btn-outline-dark" onclick="document.getElementById('keyRevealAlert').classList.add('d-none')">Dismiss</button>
</div>

<!-- Main card -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-shield-lock me-1"></i> Service API Keys</h6>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createKeyModal">
            <i class="bi bi-plus-lg me-1"></i> Create Key
        </button>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0" id="keysTable">
            <thead>
                <tr>
                    <th>NAME</th>
                    <th>PREFIX</th>
                    <th>SCOPES</th>
                    <th>CREATED</th>
                    <th>LAST USED</th>
                    <th>STATUS</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="keysBody">
                <tr><td colspan="7" class="text-center text-muted py-4">Loading...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Create Key Modal -->
<div class="modal fade" id="createKeyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-key me-1"></i> Create Service API Key</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="keyName" class="form-label">Key Name</label>
                    <input type="text" class="form-control" id="keyName" placeholder="e.g. Error Log Agent" maxlength="100">
                    <div class="form-text">A label to identify this key's purpose.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Scopes</label>
                    <?php foreach ($available_scopes as $scope => $desc) { ?>
                    <div class="form-check">
                        <input class="form-check-input scope-check" type="checkbox" value="<?= h($scope) ?>" id="scope_<?= h(str_replace(':', '_', $scope)) ?>">
                        <label class="form-check-label" for="scope_<?= h(str_replace(':', '_', $scope)) ?>">
                            <code><?= h($scope) ?></code> — <?= h($desc) ?>
                        </label>
                    </div>
                    <?php } ?>
                    <div class="form-text mt-1">Select which resources this key can access.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="createKey()">
                    <i class="bi bi-plus-lg me-1"></i> Create Key
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() { loadKeys(); });

function loadKeys() {
    apiPost('getServiceApiKeys', {}, function(resp) {
        var keys = (resp.results && resp.results.keys) ? resp.results.keys : [];
        var tbody = document.getElementById('keysBody');

        if (keys.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">' +
                '<i class="bi bi-key me-1"></i> No API keys yet. Create one to get started.</td></tr>';
            return;
        }

        var html = '';
        for (var i = 0; i < keys.length; i++) {
            var k = keys[i];
            var revoked = !!k.revoked_at;
            var scopes = [];
            try { scopes = JSON.parse(k.scopes); } catch(e) {}

            var scopeBadges = '';
            for (var j = 0; j < scopes.length; j++) {
                scopeBadges += '<span class="badge bg-info text-dark me-1">' + escapeHtml(scopes[j]) + '</span>';
            }

            var statusBadge = revoked
                ? '<span class="badge bg-danger">Revoked</span>'
                : '<span class="badge bg-success">Active</span>';

            // Note: scopes is an array — JSON.stringify produces double-quotes
            // that collide with an inline onclick attribute (the first `"`
            // closes the attribute and the handler silently breaks). We use
            // data-* attributes + a delegated listener instead, which keeps
            // the HTML well-formed regardless of scope content.
            var editBtn = revoked
                ? ''
                : '<button class="btn btn-sm btn-outline-secondary me-1 edit-scopes-btn" ' +
                  'data-key-id="' + k.service_key_id + '" ' +
                  'data-key-name="' + escapeHtml(k.key_name) + '" ' +
                  'data-key-scopes="' + escapeHtml(JSON.stringify(scopes)) + '" ' +
                  'title="Edit Scopes">' +
                  '<i class="bi bi-pencil"></i></button>';

            var revokeBtn = revoked
                ? ''
                : '<button class="btn btn-sm btn-outline-danger" onclick="revokeKey(' + k.service_key_id + ', \'' + escapeHtml(k.key_name) + '\')" title="Revoke">' +
                  '<i class="bi bi-x-circle"></i></button>';

            var rowClass = revoked ? 'class="table-secondary" style="opacity:0.6"' : '';

            html += '<tr ' + rowClass + '>' +
                '<td>' + escapeHtml(k.key_name) + '</td>' +
                '<td><code>' + escapeHtml(k.key_prefix) + '...</code></td>' +
                '<td>' + scopeBadges + '</td>' +
                '<td>' + escapeHtml(k.created_at) + '</td>' +
                '<td>' + (k.last_used_at ? escapeHtml(k.last_used_at) : '<span class="text-muted">Never</span>') + '</td>' +
                '<td>' + statusBadge + '</td>' +
                '<td>' + editBtn + revokeBtn + '</td>' +
                '</tr>';
        }
        tbody.innerHTML = html;
    });
}

function createKey() {
    var name = document.getElementById('keyName').value.trim();
    if (!name) { alert('Key name is required.'); return; }

    var checks = document.querySelectorAll('.scope-check:checked');
    if (checks.length === 0) { alert('Select at least one scope.'); return; }

    var scopes = [];
    checks.forEach(function(c) { scopes.push(c.value); });

    var postData = { key_name: name };
    for (var i = 0; i < scopes.length; i++) {
        postData['scopes[' + i + ']'] = scopes[i];
    }

    apiPost('createServiceApiKey', postData, function(resp) {
        if (resp.results && resp.results.full_key) {
            // Show key reveal alert
            document.getElementById('revealedKey').value = resp.results.full_key;
            document.getElementById('keyRevealAlert').classList.remove('d-none');

            // Close modal and reset form
            var modal = bootstrap.Modal.getInstance(document.getElementById('createKeyModal'));
            if (modal) modal.hide();
            document.getElementById('keyName').value = '';
            document.querySelectorAll('.scope-check').forEach(function(c) { c.checked = false; });

            // Reload table
            loadKeys();
        }
    });
}

function revokeKey(id, name) {
    if (!confirm('Revoke API key "' + name + '"? This cannot be undone. Any services using this key will lose access.')) {
        return;
    }
    apiPost('revokeServiceApiKey', { service_key_id: id }, function(resp) {
        loadKeys();
    });
}

function copyKey() {
    var input = document.getElementById('revealedKey');
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value).then(function() {
        var btn = input.nextElementSibling;
        btn.innerHTML = '<i class="bi bi-check"></i> Copied!';
        setTimeout(function() { btn.innerHTML = '<i class="bi bi-clipboard"></i> Copy'; }, 2000);
    });
}

function escapeHtml(str) {
    if (str == null) return '';
    // textNode → innerHTML escapes &, <, >, but NOT " or '. We need
    // attribute-safe escaping for data-* values (especially JSON), so
    // do the explicit replacement after the DOM round-trip.
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(String(str)));
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// Delegated handler — picks up edit clicks even though the table is
// re-rendered by loadKeys(). data-* attributes carry the row state.
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.edit-scopes-btn');
    if (!btn) return;
    var id = parseInt(btn.dataset.keyId, 10);
    var name = btn.dataset.keyName || '';
    var scopes = [];
    try { scopes = JSON.parse(btn.dataset.keyScopes || '[]'); } catch (e) {}
    editScopes(id, name, scopes);
});

function editScopes(id, name, currentScopes) {
    document.getElementById('editKeyId').value = id;
    document.getElementById('editKeyName').value = name;

    document.querySelectorAll('.edit-scope-check').forEach(function(c) { c.checked = false; });
    currentScopes.forEach(function(s) {
        var cb = document.querySelector('.edit-scope-check[value="' + s + '"]');
        if (cb) cb.checked = true;
    });

    var modal = new bootstrap.Modal(document.getElementById('editScopesModal'));
    modal.show();
}

function saveScopes() {
    var id = document.getElementById('editKeyId').value;
    var checks = document.querySelectorAll('.edit-scope-check:checked');
    if (checks.length === 0) { alert('Select at least one scope.'); return; }

    var i = 0;
    var postData = { service_key_id: id };
    checks.forEach(function(c) { postData['scopes[' + i + ']'] = c.value; i++; });

    apiPost('updateServiceApiKeyScopes', postData, function(resp) {
        var modal = bootstrap.Modal.getInstance(document.getElementById('editScopesModal'));
        if (modal) modal.hide();
        loadKeys();
    });
}
</script>

<?php include __DIR__ . '/../snippets/modal_edit_api_key_scopes.php'; ?>
