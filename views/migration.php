<?php
/**
 * views/migration.php
 * Parallel auth migration — configure external DB source, run sync, manage conflicts.
 */
$page_title = 'User Migration';

if (!has_role('admin')) {
    $_SESSION['error'] = 'Admin access required.';
    header('Location: index.php?page=dashboard');
    exit;
}

$source = get_migration_source();
$stats  = get_migration_stats();

// Load SAML providers for the dropdown
$saml_providers = db_fetch_all(db_query("SELECT slug, display_name FROM saml_provider ORDER BY display_name"));
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>User Migration</h3>
</div>

<!-- Sync Status Cards -->
<?php if ($source) { ?>
<div class="row mb-4">
    <div class="col-md-2 col-sm-4 col-6 mb-3">
        <div class="card h-100">
            <div class="card-body text-center py-3">
                <h6 class="card-title text-muted mb-1 small">Synced</h6>
                <h3 id="statSynced" class="mb-0 text-success"><?= $stats['synced'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-sm-4 col-6 mb-3">
        <div class="card h-100">
            <div class="card-body text-center py-3">
                <h6 class="card-title text-muted mb-1 small">Pending</h6>
                <h3 id="statPending" class="mb-0 text-warning"><?= $stats['pending'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-sm-4 col-6 mb-3">
        <div class="card h-100">
            <div class="card-body text-center py-3">
                <h6 class="card-title text-muted mb-1 small">Conflicts</h6>
                <h3 id="statConflicts" class="mb-0 text-danger"><?= $stats['conflicts'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-sm-4 col-6 mb-3">
        <div class="card h-100">
            <div class="card-body text-center py-3">
                <h6 class="card-title text-muted mb-1 small">Skipped</h6>
                <h3 id="statSkipped" class="mb-0 text-secondary"><?= $stats['skipped'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-sm-4 col-6 mb-3">
        <div class="card h-100">
            <div class="card-body text-center py-3">
                <h6 class="card-title text-muted mb-1 small">Passwords Rehashed</h6>
                <h3 id="statPwMigrated" class="mb-0 text-info"><?= $stats['password_migrated'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-sm-4 col-6 mb-3">
        <div class="card h-100">
            <div class="card-body text-center py-3">
                <h6 class="card-title text-muted mb-1 small">Last Sync</h6>
                <p class="mb-0 small"><?= $source['last_sync_at'] ? h($source['last_sync_at']) : '<span class="text-muted">Never</span>' ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Sync Actions -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Sync Actions</h6>
        <div>
            <span class="badge <?= $source['sync_enabled'] ? 'bg-success' : 'bg-secondary' ?> me-2">
                Recurring: <?= $source['sync_enabled'] ? 'ON' : 'OFF' ?>
            </span>
        </div>
    </div>
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-primary" id="btnRunSync" data-source="<?= $source['source_id'] ?>">
                <i class="bi bi-arrow-repeat"></i> Run Full Sync
            </button>
            <button class="btn btn-outline-primary" id="btnRunIncremental" data-source="<?= $source['source_id'] ?>">
                <i class="bi bi-arrow-right-circle"></i> Incremental Sync
            </button>
            <button class="btn btn-outline-secondary" id="btnTestConnection" data-source="<?= $source['source_id'] ?>">
                <i class="bi bi-plug"></i> Test Connection
            </button>
            <button class="btn btn-outline-<?= $source['sync_enabled'] ? 'warning' : 'success' ?>" id="btnToggleSync" data-source="<?= $source['source_id'] ?>">
                <i class="bi bi-<?= $source['sync_enabled'] ? 'pause-circle' : 'play-circle' ?>"></i>
                <?= $source['sync_enabled'] ? 'Disable' : 'Enable' ?> Recurring Sync
            </button>
        </div>
        <div id="syncProgress" class="mt-3 d-none">
            <div class="progress" style="height: 4px;">
                <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 100%"></div>
            </div>
            <small class="text-muted" id="syncStatusText">Running sync...</small>
        </div>
        <div id="syncResult" class="mt-3 d-none"></div>
    </div>
</div>
<?php } ?>

<!-- Connection Config -->
<div class="card mb-4">
    <div class="card-header">
        <h6 class="mb-0"><?= $source ? 'External Database Configuration' : 'Configure External Database' ?></h6>
    </div>
    <div class="card-body">
        <form id="migrationSourceForm">
            <input type="hidden" name="action" value="saveMigrationSource">
            <input type="hidden" name="source_id" value="<?= $source['source_id'] ?? 0 ?>">

            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Source Name</label>
                    <input type="text" class="form-control" name="source_name" value="<?= h($source['source_name'] ?? '') ?>" placeholder="e.g. Legacy App" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">SAML Provider (optional)</label>
                    <select class="form-select" name="saml_provider_slug">
                        <option value="">— None —</option>
                        <?php foreach ($saml_providers as $sp) { ?>
                        <option value="<?= h($sp['slug']) ?>" <?= ($source['saml_provider_slug'] ?? '') === $sp['slug'] ? 'selected' : '' ?>><?= h($sp['display_name']) ?> (<?= h($sp['slug']) ?>)</option>
                        <?php } ?>
                    </select>
                    <div class="form-text">If the old app used SAML, select the matching provider to auto-link users on SAML login.</div>
                </div>
            </div>

            <hr>
            <h6 class="text-muted mb-3">Database Connection</h6>

            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Host</label>
                    <input type="text" class="form-control" name="db_host" value="<?= h($source['db_host'] ?? '') ?>" placeholder="db.example.com" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Port</label>
                    <input type="number" class="form-control" name="db_port" value="<?= h($source['db_port'] ?? '3306') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Database Name</label>
                    <input type="text" class="form-control" name="db_name" value="<?= h($source['db_name'] ?? '') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">User</label>
                    <input type="text" class="form-control" name="db_user" value="<?= h($source['db_user'] ?? '') ?>" required>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Password <?= $source ? '<small class="text-muted">(leave blank to keep current)</small>' : '' ?></label>
                    <input type="password" class="form-control" name="db_password" <?= $source ? '' : 'required' ?>>
                </div>
            </div>

            <hr>
            <h6 class="text-muted mb-3">Table &amp; Column Mapping</h6>

            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">User Table</label>
                    <input type="text" class="form-control" name="user_table" value="<?= h($source['user_table'] ?? 'users') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">ID Column (PK)</label>
                    <input type="text" class="form-control" name="col_id" value="<?= h($source['col_id'] ?? 'id') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email Column</label>
                    <input type="text" class="form-control" name="col_email" value="<?= h($source['col_email'] ?? 'email') ?>" required>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Password Column <small class="text-muted">(optional)</small></label>
                    <input type="text" class="form-control" name="col_password" value="<?= h($source['col_password'] ?? '') ?>" placeholder="password">
                </div>
                <div class="col-md-4">
                    <label class="form-label">First Name Column <small class="text-muted">(optional)</small></label>
                    <input type="text" class="form-control" name="col_first_name" value="<?= h($source['col_first_name'] ?? '') ?>" placeholder="first_name">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Last Name Column <small class="text-muted">(optional)</small></label>
                    <input type="text" class="form-control" name="col_last_name" value="<?= h($source['col_last_name'] ?? '') ?>" placeholder="last_name">
                </div>
            </div>

            <hr>
            <h6 class="text-muted mb-3">Password Migration</h6>

            <div class="row mb-3">
                <div class="col-md-3">
                    <label class="form-label">Password Hash Algorithm</label>
                    <select class="form-select" name="password_algo">
                        <?php
                        $algos = ['bcrypt', 'argon2', 'argon2id', 'md5', 'sha256', 'sha512', 'sha1'];
                        foreach ($algos as $a) { ?>
                        <option value="<?= $a ?>" <?= ($source['password_algo'] ?? 'bcrypt') === $a ? 'selected' : '' ?>><?= $a ?></option>
                        <?php } ?>
                    </select>
                    <div class="form-text">How the old app hashed passwords.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Global Salt <small class="text-muted">(optional)</small></label>
                    <input type="text" class="form-control" name="password_salt" value="<?= h($source['password_salt'] ?? '') ?>" placeholder="Secret salt string">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Salt Position</label>
                    <select class="form-select" name="salt_position">
                        <option value="append" <?= ($source['salt_position'] ?? 'append') === 'append' ? 'selected' : '' ?>>Append (password + salt)</option>
                        <option value="prepend" <?= ($source['salt_position'] ?? 'append') === 'prepend' ? 'selected' : '' ?>>Prepend (salt + password)</option>
                    </select>
                    <div class="form-text">Order the salt was combined with the password.</div>
                </div>
            </div>

            <hr>
            <h6 class="text-muted mb-3">Sync Options</h6>

            <div class="row mb-3">
                <div class="col-md-8">
                    <label class="form-label">Filter SQL <small class="text-muted">(optional WHERE clause)</small></label>
                    <input type="text" class="form-control" name="sync_filter_sql" value="<?= h($source['sync_filter_sql'] ?? '') ?>" placeholder="e.g. is_active = 1 AND role != 'bot'">
                    <div class="form-text">Applied as a WHERE clause when querying the external user table.</div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg"></i> <?= $source ? 'Update Configuration' : 'Save Configuration' ?>
            </button>
        </form>
    </div>
</div>

<!-- Conflicts Table -->
<?php if ($stats['conflicts'] > 0) { ?>
<div class="card mb-4">
    <div class="card-header">
        <h6 class="mb-0">Sync Conflicts <span class="badge bg-danger ms-1"><?= $stats['conflicts'] ?></span></h6>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0 table-sm">
            <thead>
                <tr>
                    <th>Ext. ID</th>
                    <th>Email</th>
                    <th>Reason</th>
                    <th>Created</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody id="conflictsTable">
                <tr><td colspan="5" class="text-center text-muted py-3">Loading...</td></tr>
            </tbody>
        </table>
    </div>
</div>
<?php } ?>

<script>
(function () {
    var API = '../api/index.php';

    function apiCall(data, callback) {
        var fd = new FormData();
        for (var k in data) { fd.append(k, data[k]); }
        fetch(API, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(callback)
            .catch(function (e) { alert('Request failed: ' + e.message); });
    }

    // ── Save form ──
    var form = document.getElementById('migrationSourceForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(form);
            fetch(API, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.error) { alert(res.error); }
                    else { location.reload(); }
                });
        });
    }

    // ── Test connection ──
    var btnTest = document.getElementById('btnTestConnection');
    if (btnTest) {
        btnTest.addEventListener('click', function () {
            btnTest.disabled = true;
            btnTest.innerHTML = '<i class="bi bi-hourglass-split"></i> Testing...';
            apiCall({ action: 'testMigrationConnection', source_id: btnTest.dataset.source }, function (res) {
                btnTest.disabled = false;
                btnTest.innerHTML = '<i class="bi bi-plug"></i> Test Connection';
                if (res.error) { alert(res.error); }
                else { alert(res.success); }
            });
        });
    }

    // ── Run sync ──
    function runSync(incremental) {
        var sourceId = document.getElementById('btnRunSync').dataset.source;
        var progress = document.getElementById('syncProgress');
        var result   = document.getElementById('syncResult');

        progress.classList.remove('d-none');
        result.classList.add('d-none');
        document.getElementById('syncStatusText').textContent = incremental ? 'Running incremental sync...' : 'Running full sync...';

        apiCall({ action: 'runMigrationSync', source_id: sourceId, incremental: incremental ? '1' : '0' }, function (res) {
            progress.classList.add('d-none');
            result.classList.remove('d-none');

            if (res.error) {
                result.innerHTML = '<div class="alert alert-danger mb-0">' + res.error + '</div>';
            } else {
                var s = res.results.stats;
                result.innerHTML = '<div class="alert alert-success mb-0">' +
                    '<strong>Sync complete.</strong> ' +
                    s.synced + ' synced, ' +
                    s.conflicts + ' conflicts, ' +
                    s.skipped + ' skipped, ' +
                    s.already + ' already mapped. ' +
                    '(' + s.total + ' processed)' +
                    '</div>';
                // Refresh stats
                setTimeout(function () { location.reload(); }, 2000);
            }
        });
    }

    var btnSync = document.getElementById('btnRunSync');
    if (btnSync) btnSync.addEventListener('click', function () { runSync(false); });

    var btnInc = document.getElementById('btnRunIncremental');
    if (btnInc) btnInc.addEventListener('click', function () { runSync(true); });

    // ── Toggle recurring sync ──
    var btnToggle = document.getElementById('btnToggleSync');
    if (btnToggle) {
        btnToggle.addEventListener('click', function () {
            apiCall({ action: 'toggleMigrationSync', source_id: btnToggle.dataset.source }, function (res) {
                if (res.error) { alert(res.error); }
                else { location.reload(); }
            });
        });
    }

    // ── Load conflicts ──
    var conflictsTable = document.getElementById('conflictsTable');
    if (conflictsTable) {
        apiCall({ action: 'getMigrationConflicts', page: 1 }, function (res) {
            if (res.error || !res.results.conflicts) {
                conflictsTable.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Could not load conflicts.</td></tr>';
                return;
            }
            var rows = res.results.conflicts;
            if (rows.length === 0) {
                conflictsTable.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">No conflicts.</td></tr>';
                return;
            }
            var html = '';
            rows.forEach(function (c) {
                html += '<tr>';
                html += '<td>' + escH(c.external_user_id) + '</td>';
                html += '<td>' + escH(c.external_email) + '</td>';
                html += '<td><small class="text-danger">' + escH(c.conflict_reason || 'Unknown') + '</small></td>';
                html += '<td><small>' + escH(c.created) + '</small></td>';
                html += '<td class="text-end">';
                html += '<button class="btn btn-sm btn-outline-success me-1 btn-resolve" data-id="' + c.map_id + '" data-action="link" title="Link to existing core user"><i class="bi bi-link-45deg"></i></button>';
                html += '<button class="btn btn-sm btn-outline-primary me-1 btn-resolve" data-id="' + c.map_id + '" data-action="create" title="Create new core user"><i class="bi bi-person-plus"></i></button>';
                html += '<button class="btn btn-sm btn-outline-secondary btn-resolve" data-id="' + c.map_id + '" data-action="skip" title="Skip"><i class="bi bi-x-lg"></i></button>';
                html += '</td>';
                html += '</tr>';
            });
            conflictsTable.innerHTML = html;

            // Bind resolve buttons
            conflictsTable.querySelectorAll('.btn-resolve').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var mapId = btn.dataset.id;
                    var resolution = btn.dataset.action;
                    apiCall({ action: 'resolveMigrationConflict', map_id: mapId, resolution: resolution }, function (res) {
                        if (res.error) { alert(res.error); }
                        else { location.reload(); }
                    });
                });
            });
        });
    }

    function escH(s) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(s || ''));
        return div.innerHTML;
    }
})();
</script>
