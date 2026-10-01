<?php
/**
 * useCaseAssetFunctions.php
 * Helpers for linking Media library assets to use cases and keeping the link in
 * parity with what the daily Playwright runs actually capture.
 *
 * A use_case OWNS the marketing/onboarding graphics that depict it (see
 * use_case_asset, migration 5.4). The runner screenshots the same use case every
 * day, so a linked graphic can be reviewed BESIDE the latest passing run and
 * flagged "needs review" when it drifts:
 *   - the use case's action_path changed since the asset was approved,
 *   - the use case's test flipped to failing,
 *   - the latest passing run's screenshot no longer looks like the one that was
 *     blessed (perceptual-hash distance over threshold).
 *
 * Perceptual hash is a 64-bit average hash (aHash) over an 8x8 greyscale
 * downscale, rendered as 16 hex chars. GD is used if present; if it is not, drift
 * falls back to the action_path / test-status signals only (never a false "ok").
 */

/** Role values a link may take (must match the ENUM in migration 5.4). */
function use_case_asset_roles(): array
{
    return ['vignette', 'vignette_phone', 'store_shot',
            'onboarding_slide', 'preview_video', 'doc_image'];
}

/** Hamming distance over threshold at which two screenshots are "different". */
function use_case_asset_phash_threshold(): int
{
    return 10; // out of 64 bits
}

/**
 * Make sure the use_case_asset table exists (autocommit, outside the migration
 * runner) so the feature works immediately regardless of migration/opcache
 * timing — mirrors ensure_media_table().
 */
function ensure_use_case_asset_table(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    db_query(
        "CREATE TABLE IF NOT EXISTS `use_case_asset` (
            `link_id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `use_case_id`        INT UNSIGNED NOT NULL,
            `asset_id`           INT UNSIGNED NOT NULL,
            `role`               ENUM('vignette','vignette_phone','store_shot','onboarding_slide','preview_video','doc_image') NOT NULL DEFAULT 'vignette',
            `variant`            VARCHAR(50) NOT NULL DEFAULT '',
            `source_hash`        CHAR(64) NULL DEFAULT NULL,
            `review_status`      ENUM('pending','approved','needs_review') NOT NULL DEFAULT 'pending',
            `review_reason`      VARCHAR(255) NULL DEFAULT NULL,
            `approved_run_id`    INT UNSIGNED NULL DEFAULT NULL,
            `approved_path_hash` CHAR(64) NULL DEFAULT NULL,
            `approved_phash`     VARCHAR(32) NULL DEFAULT NULL,
            `last_reviewed_at`   DATETIME NULL DEFAULT NULL,
            `created`            DATETIME NOT NULL,
            `updated`            DATETIME NOT NULL,
            PRIMARY KEY (`link_id`),
            UNIQUE KEY `uq_link` (`use_case_id`, `asset_id`, `role`, `variant`),
            KEY `idx_use_case` (`use_case_id`),
            KEY `idx_asset` (`asset_id`),
            KEY `idx_review` (`review_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/**
 * Stable fingerprint of a use_case's action_path (page|action per step), so a
 * changed path is detectable without being sensitive to timing/params.
 */
function use_case_action_path_hash($action_path): string
{
    if (is_string($action_path)) {
        $arr = json_decode($action_path, true) ?: [];
    } else {
        $arr = (array) $action_path;
    }
    $parts = [];
    foreach ($arr as $step) {
        $parts[] = ($step['page'] ?? '') . '|' . ($step['action'] ?? '');
    }
    return hash('sha256', implode('>', $parts));
}

/**
 * Latest passing run for a use_case that actually has screenshots.
 * Returns the run row (run_id, run_at, screenshot_paths) or null.
 */
function use_case_latest_passing_run(int $use_case_id): ?array
{
    $r = db_query_prepared(
        "SELECT run_id, run_at, status, screenshot_paths
         FROM use_case_test_run
         WHERE use_case_id = ? AND status = 'pass'
         ORDER BY run_id DESC",
        [$use_case_id]
    );
    if (!$r) { return null; }
    while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
        $paths = json_decode($row['screenshot_paths'] ?? '[]', true);
        if (is_array($paths) && count($paths) > 0) {
            return $row;
        }
    }
    return null;
}

/**
 * First screenshot filename stored for a run (basename, .png only), or null.
 */
function use_case_run_first_screenshot(int $run_id): ?string
{
    if (!function_exists('use_case_screenshots_base_dir')) { return null; }
    $base = use_case_screenshots_base_dir();
    if (!$base) { return null; }
    $dir = $base . $run_id . '/';
    if (!is_dir($dir)) { return null; }
    $files = glob($dir . '*.png');
    if (!$files) { return null; }
    sort($files);
    return basename($files[0]);
}

/**
 * Perceptual average-hash (16 hex chars = 64 bits) of an image file. Returns
 * null if GD is unavailable or the file cannot be read.
 */
function use_case_image_ahash(string $path): ?string
{
    if (!is_file($path) || !function_exists('imagecreatefromstring')) { return null; }
    $data = @file_get_contents($path);
    if ($data === false) { return null; }
    $img = @imagecreatefromstring($data);
    if (!$img) { return null; }

    $small = imagecreatetruecolor(8, 8);
    imagecopyresampled($small, $img, 0, 0, 0, 0, 8, 8, imagesx($img), imagesy($img));
    imagedestroy($img);

    $vals = [];
    $sum  = 0;
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 8; $x++) {
            $rgb  = imagecolorat($small, $x, $y);
            $r    = ($rgb >> 16) & 0xFF;
            $g    = ($rgb >> 8) & 0xFF;
            $b    = $rgb & 0xFF;
            $grey = (int) (($r * 0.299) + ($g * 0.587) + ($b * 0.114));
            $vals[] = $grey;
            $sum   += $grey;
        }
    }
    imagedestroy($small);

    $avg  = $sum / 64;
    $bits = '';
    foreach ($vals as $v) { $bits .= ($v >= $avg) ? '1' : '0'; }

    // Pack 64 bits into 16 hex chars.
    $hex = '';
    for ($i = 0; $i < 64; $i += 4) {
        $hex .= dechex(bindec(substr($bits, $i, 4)));
    }
    return $hex;
}

/** Hamming distance between two 16-hex (64-bit) aHashes, or null if unusable. */
function use_case_ahash_distance(?string $a, ?string $b): ?int
{
    if (!$a || !$b || strlen($a) !== 16 || strlen($b) !== 16) { return null; }
    $dist = 0;
    for ($i = 0; $i < 16; $i++) {
        $xor = hexdec($a[$i]) ^ hexdec($b[$i]);
        $dist += substr_count(decbin($xor), '1');
    }
    return $dist;
}

/** aHash of the first screenshot of a run, or null. */
function use_case_run_phash(int $run_id): ?string
{
    $name = use_case_run_first_screenshot($run_id);
    if (!$name) { return null; }
    $base = use_case_screenshots_base_dir();
    if (!$base) { return null; }
    return use_case_image_ahash($base . $run_id . '/' . $name);
}

/**
 * Evaluate drift for one asset link against its use case and latest passing run.
 * Pure (no DB writes). Returns ['status' => pending|approved|needs_review,
 * 'reason' => string, 'distance' => int|null].
 *
 * A link that has never been approved stays 'pending' — drift only downgrades an
 * 'approved' link, it never auto-approves.
 */
function use_case_asset_eval_drift(array $link, array $use_case, ?array $latest_run): array
{
    if ($link['review_status'] !== 'approved') {
        return ['status' => $link['review_status'], 'reason' => (string)($link['review_reason'] ?? ''), 'distance' => null];
    }

    // 1. action_path changed since approval.
    $cur_path_hash = use_case_action_path_hash($use_case['action_path'] ?? '');
    if (!empty($link['approved_path_hash']) && $cur_path_hash !== $link['approved_path_hash']) {
        return ['status' => 'needs_review', 'reason' => 'Use case action_path changed since approval.', 'distance' => null];
    }

    // 2. the use case's test is failing.
    if (($use_case['test_status'] ?? '') === 'failing') {
        return ['status' => 'needs_review', 'reason' => 'Use case test is failing.', 'distance' => null];
    }

    // 3. latest passing run's screenshot drifted from the blessed one.
    if ($latest_run && !empty($link['approved_phash'])) {
        $cur_phash = use_case_run_phash((int)$latest_run['run_id']);
        $dist = use_case_ahash_distance($link['approved_phash'], $cur_phash);
        if ($dist !== null && $dist > use_case_asset_phash_threshold()) {
            return ['status' => 'needs_review',
                    'reason' => "Latest run screenshot drifted (phash distance $dist).",
                    'distance' => $dist];
        }
        return ['status' => 'approved', 'reason' => '', 'distance' => $dist];
    }

    return ['status' => 'approved', 'reason' => '', 'distance' => null];
}

/**
 * Recompute and persist drift for every linked asset. Returns the number of
 * links currently in 'needs_review'. Called by the cron sweep and the monitoring
 * API. Idempotent.
 */
function use_case_asset_review_sweep(): int
{
    ensure_use_case_asset_table();
    $links = db_query(
        "SELECT la.*, uc.action_path, uc.test_status, uc.source_app, uc.slug, uc.name
         FROM use_case_asset la
         JOIN use_case uc ON uc.use_case_id = la.use_case_id
         WHERE la.review_status = 'approved'"
    );
    if (!$links) { return use_case_assets_review_count(); }

    $run_cache = [];
    while ($link = $links->fetch(PDO::FETCH_ASSOC)) {
        $ucid = (int)$link['use_case_id'];
        if (!array_key_exists($ucid, $run_cache)) {
            $run_cache[$ucid] = use_case_latest_passing_run($ucid);
        }
        $use_case = [
            'action_path' => $link['action_path'],
            'test_status' => $link['test_status'],
        ];
        $eval = use_case_asset_eval_drift($link, $use_case, $run_cache[$ucid]);
        if ($eval['status'] === 'needs_review') {
            db_query_prepared(
                "UPDATE use_case_asset
                 SET review_status = 'needs_review', review_reason = ?,
                     last_reviewed_at = NOW(), updated = NOW()
                 WHERE link_id = ?",
                [$eval['reason'], (int)$link['link_id']]
            );
        } else {
            db_query_prepared(
                "UPDATE use_case_asset SET last_reviewed_at = NOW() WHERE link_id = ?",
                [(int)$link['link_id']]
            );
        }
    }
    return use_case_assets_review_count();
}

/** How many asset links currently need review. */
function use_case_assets_review_count(): int
{
    ensure_use_case_asset_table();
    $r = db_query("SELECT COUNT(*) AS c FROM use_case_asset WHERE review_status = 'needs_review'");
    return $r ? (int)$r->fetch(PDO::FETCH_ASSOC)['c'] : 0;
}
