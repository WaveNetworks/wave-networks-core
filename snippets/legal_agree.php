<?php
/**
 * snippets/legal_agree.php — the sign-up agreement, for every web sign-up form.
 *
 * "I agree to the Terms of Service and the Privacy Policy", both linking to the versions in
 * force (include/common/legalFunctions.php), the version numbers under it, and their ids in
 * legal_version_ids so the account records exactly what was shown. The form's submit button
 * stays disabled until the box is ticked (with JS off, `required` still holds it).
 * Posts agree_terms, which core's register action requires.
 */
$__legal_v = function_exists('wn_legal_signup_versions') ? wn_legal_signup_versions() : [];
$__legal_t = $__legal_v['terms_of_service'] ?? null;
$__legal_p = $__legal_v['privacy_policy'] ?? null;
$__legal_id = 'agree_terms';
?>
<div class="mb-3 form-check wn-legal-agree">
    <input type="checkbox" class="form-check-input" id="<?= $__legal_id ?>" name="agree_terms" value="1" required>
    <label class="form-check-label small" for="<?= $__legal_id ?>">
        I agree to the <a href="<?= h($__legal_t['url'] ?? '../legal/terms') ?>" target="_blank" rel="noopener">Terms of Service</a>
        and the <a href="<?= h($__legal_p['url'] ?? '../legal/privacy') ?>" target="_blank" rel="noopener">Privacy Policy</a>
    </label>
    <?php if ($__legal_t && $__legal_p) { ?>
    <div class="form-text">Terms of Service <?= h($__legal_t['version_label']) ?> · Privacy Policy <?= h($__legal_p['version_label']) ?></div>
    <input type="hidden" name="legal_version_ids" value="<?= (int) $__legal_t['version_id'] ?>,<?= (int) $__legal_p['version_id'] ?>">
    <?php } ?>
</div>
<script>
(function () {
    // The submit button comes after this snippet in the form: wire it once the page is parsed.
    var go = function () {
        var box = document.getElementById('agree_terms'), form = box && box.form;
        var btn = form && form.querySelector('button[type=submit]');
        if (!btn) return;
        var sync = function () { btn.disabled = !box.checked; };
        box.addEventListener('change', sync);
        sync();
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', go); else go();
})();
</script>
