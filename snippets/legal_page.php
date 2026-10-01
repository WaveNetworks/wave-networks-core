<?php
/**
 * snippets/legal_page.php — the public page for one legal document version.
 * Included by wn_legal_render_public() (include/common/legalFunctions.php) with
 * $legal (wn_legal_page()), $brand, $home_url, $home_label set.
 */
$v = $legal['version'];
$fmt = function ($d) { return $d ? gmdate('j F Y', strtotime($d . ' UTC')) : ''; };
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($legal['title']) ?><?= $brand !== '' ? ' — ' . h($brand) : '' ?></title>
<?php if ($v && !$legal['is_current'] && $legal['current']) { ?><link rel="canonical" href="<?= h($legal['url']) ?>"><?php } ?>
<script>
(function(){var s=null;try{s=localStorage.getItem('wn_color_mode');}catch(e){}
var m=s||(window.matchMedia&&window.matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light');
document.documentElement.setAttribute('data-bs-theme',m);})();
</script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<style>
body{background:var(--bs-tertiary-bg)}
.wn-legal{max-width:46rem;margin:0 auto;padding:2rem 1rem 3rem}
.wn-legal-doc{background:var(--bs-body-bg);border-radius:12px;padding:2rem 1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.wn-legal-doc h2{font-size:1.2rem;margin-top:1.75rem}
.wn-legal-doc h3{font-size:1.05rem;margin-top:1.25rem}
.wn-legal-doc h4,.wn-legal-doc h5{font-size:1rem;margin-top:1rem}
.wn-legal-doc p,.wn-legal-doc li{line-height:1.6}
.wn-legal-brand{font-weight:600;text-decoration:none;color:var(--bs-body-color)}
.wn-legal-meta{font-size:.9rem;color:var(--bs-secondary-color)}
.wn-legal-hist td,.wn-legal-hist th{font-size:.88rem}
@media (max-width:576px){.wn-legal-doc{padding:1.25rem 1rem}}
</style>
</head>
<body>
<main class="wn-legal">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a class="wn-legal-brand" href="<?= h($home_url) ?>"><?= h($brand) ?></a>
        <a class="small" href="<?= h($legal['other_url']) ?>"><?= $legal['type'] === 'privacy_policy' ? 'Terms of Service' : 'Privacy Policy' ?></a>
    </div>
    <article class="wn-legal-doc">
        <h1 class="h3 mb-1"><?= h($legal['title']) ?></h1>
        <?php if ($v) { ?>
        <p class="wn-legal-meta mb-3">
            Version <?= h($v['version_label']) ?> · Effective <?= h($fmt($v['effective_date'])) ?>
        </p>
        <?php if (!$legal['is_current'] && $v['effective_date'] > gmdate('Y-m-d')) { ?>
        <div class="alert alert-info small" role="note">
            This version takes effect on <?= h($fmt($v['effective_date'])) ?>.
            <?php if ($legal['current']) { ?><a href="<?= h($legal['url']) ?>">Read the version in force now (<?= h($legal['current']['version_label']) ?>)</a>.<?php } ?>
        </div>
        <?php } elseif (!$legal['is_current']) { ?>
        <div class="alert alert-secondary small" role="note">
            This is an earlier version, kept for reference.
            <?php if ($legal['current']) { ?><a href="<?= h($legal['url']) ?>">Read the current version (<?= h($legal['current']['version_label']) ?>)</a>.<?php } ?>
        </div>
        <?php } ?>
        <?php if ($legal['is_current'] && $legal['upcoming']) { ?>
        <div class="alert alert-info small" role="note">
            An updated version (<?= h($legal['upcoming']['version_label']) ?>) takes effect on <?= h($fmt($legal['upcoming']['effective_date'])) ?>.
            <?php if (!empty($legal['upcoming']['summary'])) { ?>What changes: <?= h($legal['upcoming']['summary']) ?><?php } ?>
            <a href="<?= h(wn_legal_public_url($legal['type'], null, $legal['upcoming']['version_label'])) ?>">Read it now</a>.
        </div>
        <?php } ?>
        <div class="wn-legal-body"><?= $legal['html'] /* wn_legal_markdown(): escaped first */ ?></div>
        <?php } else { ?>
        <p class="text-body-secondary mt-3">This document has not been published yet.</p>
        <?php } ?>
    </article>

    <?php if (count($legal['history']) > 0) { ?>
    <section class="mt-4">
        <h2 class="h6 text-body-secondary">Version history</h2>
        <div class="table-responsive">
        <table class="table table-sm wn-legal-hist mb-0">
            <thead><tr><th>Version</th><th>Effective</th><th>What changed</th></tr></thead>
            <tbody>
            <?php foreach ($legal['history'] as $hrow) { ?>
            <tr>
                <td><a href="<?= h($hrow['url']) ?>"><?= h($hrow['version_label']) ?></a><?php if ($legal['current'] && (int) $hrow['version_id'] === (int) $legal['current']['version_id']) { ?> <span class="badge text-bg-success">current</span><?php } ?></td>
                <td class="text-nowrap"><?= h($fmt($hrow['effective_date'])) ?></td>
                <td><?= h($hrow['summary'] ?? '') ?></td>
            </tr>
            <?php } ?>
            </tbody>
        </table>
        </div>
    </section>
    <?php } ?>

    <p class="text-center small mt-4 mb-0">
        <a href="<?= h($legal['url']) ?>"><?= h($legal['title']) ?></a> ·
        <a href="<?= h($legal['other_url']) ?>"><?= $legal['type'] === 'privacy_policy' ? 'Terms of Service' : 'Privacy Policy' ?></a> ·
        <a href="<?= h($home_url) ?>"><?= h($home_label) ?></a>
    </p>
</main>
</body>
</html>
