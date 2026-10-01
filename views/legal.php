<?php
/**
 * views/legal.php — Admin › Legal: Privacy Policy and Terms of Service.
 *
 * Edit a draft (markdown), publish it as a NEW version (number, effective date — today or
 * scheduled — what changed, whether signed-in users must accept it again). Published
 * versions are never edited: the history lists every one with its public permalink, how
 * many people accepted it, an integrity check of its text, and a diff against any other.
 * Helpers: include/common/legalFunctions.php. Actions: memberActions/legalActions.php.
 */
$page_title = 'Legal documents';

if (!has_role('admin')) {
    $_SESSION['error'] = 'Admin access required.';
    header('Location: index.php?page=dashboard');
    exit;
}

$types = wn_legal_types();
$apps  = wn_legal_apps();
$type  = isset($types[$_GET['type'] ?? '']) ? $_GET['type'] : 'privacy_policy';
$app   = (string) ($_GET['app'] ?? (count($apps) ? array_key_first($apps) : ''));
if ($app !== '' && !isset($apps[$app])) $app = '';

$versions = wn_legal_versions($type, $app);
$current  = wn_legal_current($type, $app);
$own_current = ($current && (string) $current['app_slug'] === $app) ? $current : null;
$draft    = wn_legal_draft($type, $app);
$counts   = wn_legal_acceptance_counts(array_column($versions, 'version_id'));
$today    = wn_legal_today();
$base_q   = 'index.php?page=legal&type=' . rawurlencode($type) . '&app=' . rawurlencode($app);

// Editor contents: the draft, else a fresh draft from the version in force.
$ed = $draft ?: [
    'title'   => $current['title'] ?? '',
    'content' => $current['content'] ?? '',
    'summary' => '',
    'requires_reacceptance' => 0,
    'effective_date' => $today,
];
$ed_major = !empty($ed['requires_reacceptance']);
$next_label = wn_legal_next_label($type, $app, $ed_major);
$next_major = wn_legal_next_label($type, $app, true);
$next_minor = wn_legal_next_label($type, $app, false);

// View one version, or diff two.
$view = !empty($_GET['view']) ? wn_legal_version((int) $_GET['view']) : null;
if ($view && ($view['consent_type'] !== $type)) $view = null;
$diff = null;
if (!empty($_GET['from']) && !empty($_GET['to'])) {
    $va = $_GET['from'] === 'draft' ? ($draft ? ['version_label' => 'draft', 'content' => $draft['content']] : null) : wn_legal_version((int) $_GET['from']);
    $vb = $_GET['to'] === 'draft' ? ($draft ? ['version_label' => 'draft', 'content' => $draft['content']] : null) : wn_legal_version((int) $_GET['to']);
    if ($va && $vb) $diff = ['a' => $va, 'b' => $vb, 'rows' => wn_legal_diff($va['content'] ?? '', $vb['content'] ?? '')];
}
$public_url = wn_legal_public_url($type, $app);
?>
<style>
.wn-legal-diff{font:12.5px/1.45 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;white-space:pre-wrap;word-break:break-word;margin:0}
.wn-legal-diff .d-add{background:rgba(25,135,84,.16);display:block}
.wn-legal-diff .d-del{background:rgba(220,53,69,.16);display:block;text-decoration:line-through;text-decoration-color:rgba(220,53,69,.5)}
.wn-legal-diff .d-eq{display:block;color:var(--bs-secondary-color)}
.wn-legal-md{font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;min-height:22rem}
.wn-legal-preview{max-height:32rem;overflow:auto}
.wn-legal-preview h2{font-size:1.15rem}.wn-legal-preview h3{font-size:1rem}
.wn-legal-dim{opacity:.7}
.wn-legal-diff .d-gap{display:block;color:var(--bs-secondary-color);font-style:italic;padding:.15rem 0;border-top:1px dashed var(--bs-border-color);border-bottom:1px dashed var(--bs-border-color);margin:.2rem 0}
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h3 class="mb-0">Legal documents</h3>
        <p class="text-body-secondary small mb-0">Every publish is a new version. Published versions never change.</p>
    </div>
    <?php if (count($apps) > 0) { ?>
    <form method="get" class="d-flex align-items-center gap-2">
        <input type="hidden" name="page" value="legal">
        <input type="hidden" name="type" value="<?= h($type) ?>">
        <label class="small text-body-secondary" for="legalApp">For</label>
        <select class="form-select form-select-sm" id="legalApp" name="app" onchange="this.form.submit()">
            <?php foreach ($apps as $slug => $name) { ?>
            <option value="<?= h($slug) ?>" <?= $slug === $app ? 'selected' : '' ?>><?= h($name) ?></option>
            <?php } ?>
            <option value="" <?= $app === '' ? 'selected' : '' ?>>Whole site (default for every app)</option>
        </select>
    </form>
    <?php } ?>
</div>

<ul class="nav nav-tabs mb-3">
    <?php foreach ($types as $t => $meta) { ?>
    <li class="nav-item">
        <a class="nav-link <?= $t === $type ? 'active' : '' ?>" href="index.php?page=legal&amp;type=<?= h($t) ?>&amp;app=<?= h($app) ?>">
            <i class="bi <?= h($meta['icon']) ?> me-1"></i><?= h($meta['title']) ?>
        </a>
    </li>
    <?php } ?>
</ul>

<?php if ($view) { ?>
<!-- ═══ ONE VERSION ═══ -->
<?php $intact = wn_legal_intact($view); ?>
<div class="card mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><strong><?= h($types[$type]['title']) ?> <?= h($view['version_label']) ?></strong>
            <span class="small wn-legal-dim">· effective <?= h($view['effective_date']) ?></span></span>
        <span class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="<?= h(wn_legal_public_url($type, $app, $view['version_label'])) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Permalink</a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= h($base_q) ?>">Close</a>
        </span>
    </div>
    <div class="card-body">
        <dl class="row small mb-3">
            <dt class="col-sm-3">Published</dt><dd class="col-sm-9"><?= h($view['published_at'] ?? $view['created']) ?> UTC<?= !empty($view['published_by_name']) ? ' by ' . h($view['published_by_name']) : '' ?></dd>
            <dt class="col-sm-3">What changed</dt><dd class="col-sm-9"><?= h($view['summary'] ?? '') ?: '<span class="text-body-secondary">—</span>' ?></dd>
            <dt class="col-sm-3">Re-acceptance</dt><dd class="col-sm-9"><?= !empty($view['requires_reacceptance']) ? 'Required — signed-in users were asked to accept it' : 'Not required' ?></dd>
            <dt class="col-sm-3">Integrity</dt><dd class="col-sm-9">
                <?php if ($intact === true) { ?><span class="text-success"><i class="bi bi-check-circle me-1"></i>Text matches what was published</span> <code class="small"><?= h(substr($view['content_sha256'], 0, 16)) ?>…</code>
                <?php } elseif ($intact === false) { ?><span class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Text differs from what was published — changed outside the admin</span>
                <?php } else { ?><span class="text-body-secondary">Published before integrity hashes</span><?php } ?>
            </dd>
        </dl>
        <div class="border rounded p-3 wn-legal-preview"><?= wn_legal_markdown((string) ($view['content'] ?? '')) ?: '<p class="text-body-secondary mb-0">No text stored for this version.</p>' ?></div>
    </div>
</div>
<?php } ?>

<?php if ($diff) { ?>
<!-- ═══ DIFF ═══ -->
<?php $adds = count(array_filter($diff['rows'], fn($r) => $r['op'] === '+')); $dels = count(array_filter($diff['rows'], fn($r) => $r['op'] === '-')); ?>
<div class="card mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><strong>Changes from <?= h($diff['a']['version_label']) ?> to <?= h($diff['b']['version_label']) ?></strong>
            <span class="small ms-1"><span class="text-success">+<?= (int) $adds ?></span> <span class="text-danger">−<?= (int) $dels ?></span> lines</span></span>
        <a class="btn btn-sm btn-outline-secondary" href="<?= h($base_q) ?>">Close</a>
    </div>
    <div class="card-body">
        <?php if ($adds + $dels === 0) { ?><p class="text-body-secondary mb-0">The two texts are identical.</p>
        <?php } else { ?>
        <?php
        // Unchanged text more than three lines away from a change folds into one marker.
        $rows = $diff['rows']; $nrows = count($rows); $near = array_fill(0, $nrows, false);
        foreach ($rows as $k => $r) { if ($r['op'] !== '=') { for ($j = max(0, $k - 3); $j <= min($nrows - 1, $k + 3); $j++) $near[$j] = true; } }
        ?>
        <pre class="wn-legal-diff"><?php $gap = 0; foreach ($rows as $k => $r) {
            if (!$near[$k]) { $gap++; continue; }
            if ($gap > 0) { ?><span class="d-gap">⋯ <?= (int) $gap ?> unchanged line<?= $gap === 1 ? '' : 's' ?></span><?php $gap = 0; } ?><span class="<?= $r['op'] === '+' ? 'd-add' : ($r['op'] === '-' ? 'd-del' : 'd-eq') ?>"><?= $r['op'] === '=' ? '  ' : h($r['op']) . ' ' ?><?= h($r['line']) ?></span><?php } ?><?php if ($gap > 0) { ?><span class="d-gap">⋯ <?= (int) $gap ?> unchanged line<?= $gap === 1 ? '' : 's' ?></span><?php } ?></pre>
        <?php } ?>
    </div>
</div>
<?php } ?>

<div class="row g-4">
    <div class="col-xl-7">
        <!-- ═══ DRAFT EDITOR ═══ -->
        <div class="card">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span><i class="bi bi-pencil-square me-1"></i>
                    <?php if ($draft) { ?>Draft <span class="badge text-bg-warning">unpublished</span>
                    <span class="small wn-legal-dim">saved <?= h($draft['updated']) ?> UTC<?= !empty($draft['updated_by_name']) ? ' by ' . h($draft['updated_by_name']) : '' ?></span>
                    <?php } else { ?>New version <span class="small wn-legal-dim">— starts from the version in force</span><?php } ?>
                </span>
                <?php if ($draft && $own_current) { ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?= h($base_q) ?>&amp;from=<?= (int) $own_current['version_id'] ?>&amp;to=draft"><i class="bi bi-file-diff me-1"></i>Diff vs <?= h($own_current['version_label']) ?></a>
                <?php } ?>
            </div>
            <div class="card-body">
                <form method="post" id="legalEditor">
                    <input type="hidden" name="consent_type" value="<?= h($type) ?>">
                    <input type="hidden" name="app_slug" value="<?= h($app) ?>">
                    <div class="mb-3">
                        <label class="form-label small" for="legalTitle">Title</label>
                        <input class="form-control" id="legalTitle" name="title" value="<?= h($ed['title'] ?: $types[$type]['title']) ?>">
                    </div>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between align-items-end">
                            <label class="form-label small mb-1" for="legalContent">Text (markdown: # heading, - list, **bold**, [link](https://…))</label>
                        </div>
                        <textarea class="form-control wn-legal-md" id="legalContent" name="content" rows="18" spellcheck="true"><?= h($ed['content'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small" for="legalSummary">What changed <span class="text-body-secondary">(shown to people in the update notice and the public history)</span></label>
                        <input class="form-control" id="legalSummary" name="summary" maxlength="500" value="<?= h($ed['summary'] ?? '') ?>" placeholder="e.g. Explains how shared circles sync between members">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label small" for="legalEffective">Effective date</label>
                            <input type="date" class="form-control" id="legalEffective" name="effective_date" min="<?= h($today) ?>" value="<?= h(($ed['effective_date'] ?? '') >= $today ? $ed['effective_date'] : $today) ?>">
                            <div class="form-text">Today, or later to schedule it. Until then the current version stays in force.</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small" for="legalLabel">Version number</label>
                            <input class="form-control" id="legalLabel" name="version_label" value="<?= h($next_label) ?>" data-major="<?= h($next_major) ?>" data-minor="<?= h($next_minor) ?>" pattern="[0-9][0-9A-Za-z.\-]{0,31}">
                            <div class="form-text">Suggested: <?= h($next_major) ?> when people must accept again, <?= h($next_minor) ?> otherwise.</div>
                        </div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="legalReaccept" name="requires_reacceptance" value="1" <?= $ed_major ? 'checked' : '' ?>>
                        <label class="form-check-label" for="legalReaccept">
                            <strong>Requires re-acceptance</strong> — signed-in users see a one-time notice with the summary and must accept (web and phone app)
                        </label>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" name="action" value="saveLegalDraft" class="btn btn-outline-primary"><i class="bi bi-save me-1"></i>Save draft</button>
                        <button type="submit" name="action" value="publishLegalVersion" class="btn btn-primary"
                                onclick="return confirm('Publish as version ' + document.getElementById('legalLabel').value + '? Published versions cannot be edited.');">
                            <i class="bi bi-send-check me-1"></i>Publish new version
                        </button>
                        <?php if ($draft) { ?>
                        <button type="submit" name="action" value="discardLegalDraft" class="btn btn-outline-danger ms-auto" formnovalidate
                                onclick="return confirm('Discard this draft?');"><i class="bi bi-trash me-1"></i>Discard draft</button>
                        <?php } ?>
                    </div>
                </form>
                <?php if ($draft && trim((string) $draft['content']) !== '') { ?>
                <details class="mt-3">
                    <summary class="small">Preview the saved draft</summary>
                    <div class="border rounded p-3 mt-2 wn-legal-preview"><?= wn_legal_markdown((string) $draft['content']) ?></div>
                </details>
                <?php } ?>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <!-- ═══ IN FORCE ═══ -->
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-patch-check me-1"></i> In force now</div>
            <div class="card-body small">
                <?php if ($current) { ?>
                <p class="mb-1"><strong><?= h($types[$type]['title']) ?> <?= h($current['version_label']) ?></strong>, effective <?= h($current['effective_date']) ?>
                    <?php if ((string) $current['app_slug'] !== $app) { ?><span class="badge text-bg-secondary">whole-site default</span><?php } ?></p>
                <p class="mb-2 text-body-secondary"><?= h($current['summary'] ?? '') ?></p>
                <?php } else { ?>
                <p class="text-body-secondary">Nothing published yet.</p>
                <?php } ?>
                <div class="d-flex flex-column gap-1">
                    <span>Public page: <a href="<?= h($public_url) ?>" target="_blank" rel="noopener"><?= h(wn_legal_public_url($type, $app, null, true)) ?></a></span>
                    <?php if ($current) { ?><span>This version: <a href="<?= h(wn_legal_public_url($type, $app, $current['version_label'])) ?>" target="_blank" rel="noopener"><?= h(wn_legal_public_url($type, $app, $current['version_label'])) ?></a></span><?php } ?>
                </div>
            </div>
        </div>

        <!-- ═══ HISTORY ═══ -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-1"></i> History</span>
                <span class="small wn-legal-dim"><?= count($versions) ?> version<?= count($versions) === 1 ? '' : 's' ?></span>
            </div>
            <?php if (count($versions) === 0) { ?>
            <div class="card-body small text-body-secondary">No versions published<?= $app !== '' ? ' for this app yet' : '' ?>.</div>
            <?php } else { ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 small">
                    <thead><tr><th>Version</th><th>Effective</th><th class="text-end" title="People who accepted this version">Accepted</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($versions as $i => $v) {
                        $prev = $versions[$i + 1] ?? null;
                        $is_cur = $current && (int) $current['version_id'] === (int) $v['version_id'];
                        $sched  = $v['effective_date'] > $today;
                        $intact = wn_legal_intact($v); ?>
                    <tr>
                        <td>
                            <a href="<?= h($base_q) ?>&amp;view=<?= (int) $v['version_id'] ?>"><strong><?= h($v['version_label']) ?></strong></a>
                            <?php if ($is_cur) { ?><span class="badge text-bg-success">current</span><?php } ?>
                            <?php if ($sched) { ?><span class="badge text-bg-info">scheduled</span><?php } ?>
                            <?php if (!empty($v['requires_reacceptance'])) { ?><span class="badge text-bg-warning" title="Signed-in users were asked to accept it">re-accept</span><?php } ?>
                            <?php if ($intact === false) { ?><i class="bi bi-exclamation-triangle text-danger" title="Text changed after publishing"></i><?php } ?>
                            <?php if (!empty($v['summary'])) { ?><div><?= h($v['summary']) ?></div><?php } ?>
                            <div class="wn-legal-dim">published <?= h(substr((string) ($v['published_at'] ?? $v['created']), 0, 16)) ?><?= !empty($v['published_by_name']) ? ' · ' . h($v['published_by_name']) : '' ?></div>
                        </td>
                        <td class="text-nowrap"><?= h($v['effective_date']) ?></td>
                        <td class="text-end"><?= (int) ($counts[(int) $v['version_id']] ?? 0) ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($prev) { ?><a class="btn btn-sm btn-link p-0" href="<?= h($base_q) ?>&amp;from=<?= (int) $prev['version_id'] ?>&amp;to=<?= (int) $v['version_id'] ?>" title="Changes from <?= h($prev['version_label']) ?>"><i class="bi bi-file-diff"></i> diff</a><?php } ?>
                        </td>
                    </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($versions) > 1) { ?>
            <div class="card-footer">
                <form method="get" class="d-flex flex-wrap align-items-center gap-2 small">
                    <input type="hidden" name="page" value="legal"><input type="hidden" name="type" value="<?= h($type) ?>"><input type="hidden" name="app" value="<?= h($app) ?>">
                    Compare
                    <select name="from" class="form-select form-select-sm w-auto"><?php foreach (array_reverse($versions) as $v) { ?><option value="<?= (int) $v['version_id'] ?>"><?= h($v['version_label']) ?></option><?php } ?></select>
                    with
                    <select name="to" class="form-select form-select-sm w-auto"><?php foreach ($versions as $v) { ?><option value="<?= (int) $v['version_id'] ?>"><?= h($v['version_label']) ?></option><?php } ?><?php if ($draft) { ?><option value="draft">draft</option><?php } ?></select>
                    <button class="btn btn-sm btn-outline-secondary">Diff</button>
                </form>
            </div>
            <?php } ?>
            <?php } ?>
        </div>
    </div>
</div>

<script>
// The suggested number follows the re-acceptance box (major when people must accept again)
// until the admin types their own.
(function () {
    var box = document.getElementById('legalReaccept'), lab = document.getElementById('legalLabel');
    if (!box || !lab) return;
    var touched = false;
    lab.addEventListener('input', function () { touched = true; });
    box.addEventListener('change', function () { if (!touched) lab.value = box.checked ? lab.dataset.major : lab.dataset.minor; });
})();
</script>
