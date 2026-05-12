<?php
/**
 * views/mobile_parity.php
 * Admin-only browser for the mobile_parity inventory.
 * Run audit_mobile_parity.py (nightly via cron, or manual) to populate.
 */
if (!has_role('admin')) {
    $_SESSION['error'] = 'Admin access required.';
    header('Location: index.php?page=dashboard');
    exit;
}
$page_title = 'Mobile Parity';
?>

<h4 class="mb-3"><i class="bi bi-phone me-2"></i>Mobile Parity</h4>

<div class="card mb-4">
    <div class="card-header">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h6 class="mb-0"><i class="bi bi-diff me-1"></i> Desktop ↔ Mobile gap inventory</h6>
            <div class="d-flex flex-wrap gap-1 align-items-center">
                <span class="badge bg-secondary" id="parityBadgeTotal">0 total</span>
                <span class="badge bg-danger"    id="parityBadgeMissing">0 missing</span>
                <span class="badge bg-warning text-dark" id="parityBadgePartial">0 partial</span>
                <span class="badge bg-success"   id="parityBadgeWired">0 wired</span>
                <span class="badge bg-dark"      id="parityBadgeNa">0 n/a</span>

                <select class="form-select form-select-sm" id="parityAppFilter" style="width:auto" onchange="loadParity()">
                    <option value="">All apps</option>
                </select>
                <select class="form-select form-select-sm" id="parityCatFilter" style="width:auto" onchange="loadParity()">
                    <option value="">All categories</option>
                    <option value="page">page</option>
                    <option value="action">action</option>
                    <option value="script">script</option>
                    <option value="snippet">snippet</option>
                    <option value="widget">widget</option>
                    <option value="element">element</option>
                </select>
                <select class="form-select form-select-sm" id="parityStatusFilter" style="width:auto" onchange="loadParity()">
                    <option value="">All status</option>
                    <option value="missing" selected>missing</option>
                    <option value="partial">partial</option>
                    <option value="wired">wired</option>
                    <option value="n_a">n/a</option>
                </select>

                <button class="btn btn-sm btn-outline-primary" type="button" onclick="loadParity()">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:90px">Category</th>
                    <th>Feature</th>
                    <th>Desktop source</th>
                    <th>Mobile source</th>
                    <th style="width:90px">Priority</th>
                    <th style="width:120px">Status</th>
                    <th style="width:60px"></th>
                </tr>
            </thead>
            <tbody id="parityTbody">
                <tr><td colspan="7" class="text-center text-body-secondary p-3">Loading…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
(function(){
    var appsKnown = {};

    function badgeFor(status) {
        switch(status) {
            case 'missing': return 'bg-danger';
            case 'partial': return 'bg-warning text-dark';
            case 'wired':   return 'bg-success';
            case 'n_a':     return 'bg-dark';
            default:        return 'bg-secondary';
        }
    }

    function loadParity() {
        var tbody = document.getElementById('parityTbody');
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-body-secondary p-3">Loading…</td></tr>';
        apiPost('apiListMobileParity', {
            source_app:    document.getElementById('parityAppFilter').value,
            category:      document.getElementById('parityCatFilter').value,
            mobile_status: document.getElementById('parityStatusFilter').value,
            limit:         500,
        }, function(json){
            if (json.error) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-danger p-3">' + json.error + '</td></tr>';
                return;
            }
            var items = (json.results && json.results.items) || [];
            // Counts (across the filtered view)
            var counts = { missing:0, partial:0, wired:0, n_a:0 };
            items.forEach(function(r){
                counts[r.mobile_status] = (counts[r.mobile_status]||0) + 1;
                if (r.source_app) appsKnown[r.source_app] = true;
            });
            document.getElementById('parityBadgeTotal').textContent   = items.length + ' total';
            document.getElementById('parityBadgeMissing').textContent = counts.missing + ' missing';
            document.getElementById('parityBadgePartial').textContent = counts.partial + ' partial';
            document.getElementById('parityBadgeWired').textContent   = counts.wired   + ' wired';
            document.getElementById('parityBadgeNa').textContent      = counts.n_a     + ' n/a';

            // Populate app filter once we've seen rows.
            var sel = document.getElementById('parityAppFilter');
            var have = {};
            Array.from(sel.options).forEach(function(o){ have[o.value]=true; });
            Object.keys(appsKnown).sort().forEach(function(app){
                if (!have[app]) {
                    var opt = document.createElement('option');
                    opt.value = app; opt.textContent = app;
                    sel.appendChild(opt);
                }
            });

            if (items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-body-secondary p-3">'
                    + 'No rows. Run <code>admin/scripts/audit_mobile_parity.py</code> to populate.</td></tr>';
                return;
            }
            tbody.innerHTML = items.map(function(r){
                return '<tr>'
                    + '<td><span class="badge bg-info text-dark">' + esc(r.category) + '</span></td>'
                    + '<td><div class="fw-semibold">' + esc(r.feature_name || r.feature_key) + '</div>'
                    +   '<div class="small text-body-secondary"><code>' + esc(r.feature_key) + '</code> · ' + esc(r.source_app) + '</div></td>'
                    + '<td class="small"><code>' + esc(r.desktop_source||'') + '</code></td>'
                    + '<td class="small"><code>' + esc(r.mobile_source||'') + '</code></td>'
                    + '<td><span class="badge bg-secondary">' + esc(r.priority||'medium') + '</span></td>'
                    + '<td><select class="form-select form-select-sm parity-status-select" data-parity-id="' + r.parity_id + '" onchange="setParityStatus(this)">'
                    +   ['missing','partial','wired','n_a'].map(function(s){
                            return '<option value="'+s+'"' + (s===r.mobile_status?' selected':'') + '>'+s+'</option>';
                        }).join('')
                    + '</select></td>'
                    + '<td></td>'
                    + '</tr>';
            }).join('');
        });
    }
    function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    }); }
    window.loadParity = loadParity;

    // Quick status flip — useful when a feature is shipped and you want to
    // mark a row 'wired' before the next audit pass catches it automatically.
    window.setParityStatus = function(sel) {
        var parityId = sel.dataset.parityId;
        var status   = sel.value;
        sel.disabled = true;
        apiPost('apiSetMobileParityStatus', { parity_id: parityId, mobile_status: status }, function(json){
            sel.disabled = false;
            if (json.error) {
                if (typeof showToast === 'function') showToast('danger', json.error);
                return;
            }
            if (typeof showToast === 'function') showToast('success', 'Status updated.');
            loadParity();
        });
    };

    loadParity();
})();
</script>
