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
        apiPost('memberListMobileParity', {
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
                    + 'No rows. Run <code>admin/scripts/audit_mobile_parity.py</code> + '
                    + '<code>admin/scripts/diff_view_contract.py</code> to populate.</td></tr>';
                return;
            }

            // Group by view (the feature_key prefix before '/') so each view
            // becomes a foldable section with its own missing/wired count —
            // much easier to scan than a flat 500-row table.
            var groups = {};
            items.forEach(function(r){
                var view = (r.feature_key||'').split('/')[0] || '(other)';
                if (!groups[view]) groups[view] = [];
                groups[view].push(r);
            });
            var viewNames = Object.keys(groups).sort(function(a,b){
                // Sort by total then by missing count desc — heaviest gaps first
                var ma = groups[a].filter(function(r){return r.mobile_status==='missing';}).length;
                var mb = groups[b].filter(function(r){return r.mobile_status==='missing';}).length;
                return mb - ma;
            });

            tbody.innerHTML = viewNames.map(function(view){
                var rows = groups[view];
                var counts = { missing:0, partial:0, wired:0, n_a:0 };
                rows.forEach(function(r){ counts[r.mobile_status]++; });
                var pct = rows.length ? Math.round(100 * counts.wired / rows.length) : 0;

                var header = '<tr class="table-light parity-view-header" data-view="' + esc(view) + '" style="cursor:pointer">'
                    + '<td colspan="7" class="fw-semibold">'
                    +   '<i class="bi bi-chevron-right me-1 parity-chevron"></i> '
                    +   esc(view)
                    +   ' <span class="text-body-secondary fw-normal small ms-2">'
                    +     rows.length + ' rows · '
                    +     '<span class="text-danger">' + counts.missing + ' missing</span> · '
                    +     '<span class="text-warning">' + counts.partial + ' partial</span> · '
                    +     '<span class="text-success">' + counts.wired + ' wired</span> · '
                    +     pct + '% complete'
                    +   '</span>'
                    + '</td></tr>';

                var detail = rows.map(function(r){
                    return '<tr class="parity-row" data-view="' + esc(view) + '" style="display:none">'
                        + '<td><span class="badge bg-info text-dark">' + esc(r.category) + '</span></td>'
                        + '<td><div class="small"><code>' + esc((r.feature_key||'').split('/').slice(1).join('/')||r.feature_key) + '</code></div></td>'
                        + '<td class="small"><code>' + esc(r.desktop_source||'') + '</code></td>'
                        + '<td class="small"><code>' + esc(r.mobile_source||'') + '</code></td>'
                        + '<td><span class="badge bg-secondary">' + esc(r.priority||'medium') + '</span></td>'
                        + '<td><select class="form-select form-select-sm parity-status-select" data-parity-id="' + r.parity_id + '" onchange="setParityStatus(this)">'
                        +   ['missing','partial','wired','n_a'].map(function(s){
                                return '<option value="'+s+'"' + (s===r.mobile_status?' selected':'') + '>'+s+'</option>';
                            }).join('')
                        + '</select></td>'
                        + '<td class="small text-body-secondary">' + esc(r.notes||'') + '</td>'
                        + '</tr>';
                }).join('');
                return header + detail;
            }).join('');

            // Wire collapsible view-header rows
            tbody.querySelectorAll('.parity-view-header').forEach(function(h){
                h.addEventListener('click', function(){
                    var view = h.dataset.view;
                    var chevron = h.querySelector('.parity-chevron');
                    var open = chevron.classList.toggle('bi-chevron-down');
                    chevron.classList.toggle('bi-chevron-right', !open);
                    tbody.querySelectorAll('.parity-row[data-view="'+view.replace(/"/g,'\\"')+'"]').forEach(function(row){
                        row.style.display = open ? '' : 'none';
                    });
                });
            });
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
        apiPost('memberSetMobileParityStatus', { parity_id: parityId, mobile_status: status }, function(json){
            sel.disabled = false;
            if (json.error) {
                if (typeof showToast === 'function') showToast('danger', json.error);
                return;
            }
            if (typeof showToast === 'function') showToast('success', 'Status updated.');
            loadParity();
        });
    };

    // Wait for bs-init.js (which defines apiPost) to load. On direct page load
    // the bs-init.js script tag sits below this view's <script> in template.php,
    // so calling apiPost() at parse time throws ReferenceError. On SPA nav the
    // race doesn't fire because the page is already fully loaded.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadParity);
    } else {
        loadParity();
    }
})();
</script>
