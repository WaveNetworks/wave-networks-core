<?php
/**
 * views/media.php — Media Library (admin-only, ?page=media)
 * Upload media assets and get servable URLs for use in this app's pages/ads.
 */
$page_title = 'Media Library';

if (!has_role('admin')) {
    echo '<div class="alert alert-danger">Access denied. Admin role required.</div>';
    return;
}

ensure_media_table();

$rows = array();
$r = db_query("SELECT asset_id, filename, original_name, title, mime_type, ext, file_size, width, height, created
               FROM media_asset ORDER BY created DESC");
if ($r) { $rows = db_fetch_all($r); }

function media_is_image(string $ext): bool {
    return in_array(strtolower($ext), ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico'], true);
}
function media_human_size(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024) . ' KB';
    return $bytes . ' B';
}
?>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb mb-3">
        <li class="breadcrumb-item"><a href="index.php?page=dashboard"><i class="bi bi-house"></i></a></li>
        <li class="breadcrumb-item active" aria-current="page">Media Library</li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-1"><i class="bi bi-images me-2"></i>Media Library</h4>
        <p class="text-muted small mb-0">Upload branding/ad assets and copy their servable URLs for use in this app.</p>
    </div>
</div>

<!-- Upload -->
<div class="card mb-4">
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-start">
            <input type="hidden" name="action" value="uploadMedia">
            <div class="col-12 col-md-5">
                <label class="form-label small fw-semibold">File</label>
                <input type="file" class="form-control form-control-sm" name="media"
                       accept=".png,.jpg,.jpeg,.gif,.webp,.svg,.ico,.pdf,.mp4" required>
                <div class="form-text">PNG, JPG, GIF, WebP, SVG, ICO, PDF, MP4 · up to 25 MB.</div>
            </div>
            <div class="col-12 col-md-5">
                <label class="form-label small fw-semibold">Title <span class="text-muted fw-normal">(optional)</span></label>
                <input type="text" class="form-control form-control-sm" name="title" maxlength="255"
                       placeholder="e.g. elevateHER community ad — women (transparent)">
            </div>
            <div class="col-12 col-md-2 d-grid">
                <label class="form-label small fw-semibold d-none d-md-block" aria-hidden="true">&nbsp;</label>
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-upload me-1"></i>Upload</button>
            </div>
        </form>
    </div>
</div>

<?php if (empty($rows)) { ?>
<div class="text-center text-muted py-5">
    <i class="bi bi-images fs-1 d-block mb-2"></i>
    No media uploaded yet.
</div>
<?php } else { ?>
<div class="row g-3">
    <?php foreach ($rows as $a) {
        $url = media_public_url($a['filename']);
        $isImg = media_is_image($a['ext']);
    ?>
    <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
        <div class="card h-100">
            <div class="d-flex align-items-center justify-content-center bg-body-tertiary border-bottom"
                 style="height:150px;overflow:hidden;background-image:linear-gradient(45deg,#eee 25%,transparent 25%,transparent 75%,#eee 75%),linear-gradient(45deg,#eee 25%,transparent 25%,transparent 75%,#eee 75%);background-size:20px 20px;background-position:0 0,10px 10px;">
                <?php if ($isImg) { ?>
                    <img src="<?= h($url) ?>" alt="<?= h($a['original_name']) ?>" style="max-width:100%;max-height:150px;object-fit:contain;">
                <?php } else { ?>
                    <i class="bi <?= $a['ext'] === 'pdf' ? 'bi-file-earmark-pdf' : 'bi-file-earmark-play' ?> text-muted" style="font-size:3rem;"></i>
                <?php } ?>
            </div>
            <div class="card-body p-2">
                <div class="small fw-semibold text-truncate" title="<?= h($a['title'] ?: $a['original_name']) ?>">
                    <?= h($a['title'] ?: $a['original_name']) ?>
                </div>
                <div class="text-muted" style="font-size:0.7rem;">
                    <?= strtoupper(h($a['ext'])) ?> · <?= h(media_human_size((int)$a['file_size'])) ?><?php
                    if (!empty($a['width'])) { echo ' · ' . (int)$a['width'] . '×' . (int)$a['height']; } ?>
                </div>
                <div class="input-group input-group-sm mt-2">
                    <input type="text" class="form-control" style="font-size:0.7rem;" value="<?= h($url) ?>" readonly
                           id="murl-<?= (int)$a['asset_id'] ?>" onclick="this.select()">
                    <button class="btn btn-outline-secondary" type="button" title="Copy URL"
                            onclick="copyMediaUrl('murl-<?= (int)$a['asset_id'] ?>', this)"><i class="bi bi-clipboard"></i></button>
                    <a class="btn btn-outline-secondary" href="<?= h($url) ?>" target="_blank" title="Open"><i class="bi bi-box-arrow-up-right"></i></a>
                </div>
            </div>
            <div class="card-footer p-1 bg-transparent border-0 text-end">
                <button type="button" class="btn btn-sm btn-link p-0 me-2" style="font-size:0.75rem;"
                        data-bs-toggle="modal" data-bs-target="#replaceMediaModal"
                        data-asset-id="<?= (int)$a['asset_id'] ?>"
                        data-asset-ext="<?= h(strtolower($a['ext'])) ?>"
                        data-asset-name="<?= h($a['title'] ?: $a['original_name']) ?>"><i class="bi bi-arrow-repeat"></i> Replace</button>
                <form method="post" onsubmit="return confirm('Delete this media asset? Any pages using its URL will break.');" class="d-inline">
                    <input type="hidden" name="action" value="deleteMedia">
                    <input type="hidden" name="asset_id" value="<?= (int)$a['asset_id'] ?>">
                    <button type="submit" class="btn btn-sm btn-link text-danger p-0 me-2" style="font-size:0.75rem;"><i class="bi bi-trash"></i> Delete</button>
                </form>
            </div>
        </div>
    </div>
    <?php } ?>
</div>
<?php } ?>

<!-- Replace media modal -->
<div class="modal fade" id="replaceMediaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="action" value="replaceMedia">
            <input type="hidden" name="asset_id" id="replaceAssetId" value="">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-arrow-repeat me-2"></i>Replace media</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-3">Replacing <strong id="replaceAssetName"></strong> keeps the same URL — any pages already using it pick up the new file automatically.</p>
                <label class="form-label small fw-semibold">New file</label>
                <input type="file" class="form-control form-control-sm" name="media" id="replaceMediaFile" required>
                <div class="form-text">Must be the same file type (<span id="replaceAssetExt"></span>) so the existing URL keeps serving the correct format.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat me-1"></i>Replace</button>
            </div>
        </form>
    </div>
</div>

<script>
var replaceMediaModal = document.getElementById('replaceMediaModal');
if (replaceMediaModal) {
    replaceMediaModal.addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        if (!btn) return;
        var ext = btn.getAttribute('data-asset-ext') || '';
        document.getElementById('replaceAssetId').value = btn.getAttribute('data-asset-id') || '';
        document.getElementById('replaceAssetName').textContent = btn.getAttribute('data-asset-name') || 'this asset';
        document.getElementById('replaceAssetExt').textContent = '.' + ext;
        var file = document.getElementById('replaceMediaFile');
        file.value = '';
        file.setAttribute('accept', ext ? '.' + ext : '');
    });
}
function copyMediaUrl(inputId, btn) {
    var inp = document.getElementById(inputId);
    if (!inp) return;
    navigator.clipboard.writeText(inp.value).then(function () {
        var i = btn.querySelector('i');
        if (i) { i.className = 'bi bi-check-lg'; setTimeout(function () { i.className = 'bi bi-clipboard'; }, 1500); }
    }, function () { inp.select(); document.execCommand('copy'); });
}
</script>
