<?php
/**
 * cron/days/1/use_case_asset_drift_sweep.php
 * Re-evaluate every approved use-case→graphic link against the latest passing
 * run. Flags a link 'needs_review' when the use case's action_path changed, its
 * test flipped to failing, or the newest screenshot drifted from the approved
 * one (perceptual-hash distance). Surfaced on the dashboard + apiGetUseCaseAssetReview.
 * Daily via cron.php.
 */

if (function_exists('use_case_asset_review_sweep')) {
    $count = use_case_asset_review_sweep();
    echo "    Use-case graphic drift sweep done; $count link(s) now need review.\n";
} else {
    echo "    use_case_asset_review_sweep() unavailable — skipped.\n";
}
