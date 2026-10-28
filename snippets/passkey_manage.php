<?php
/**
 * snippets/passkey_manage.php — the Passkeys card for an account security page (task #2661;
 * rules in include/common/passkeyFunctions.php; actions in memberActions/passkeyActions.php).
 * Add (AJAX: the device ceremony sits between the two steps), rename and remove (form posts).
 * Needs bs-init.js (apiPost) on the page. Renders nothing unless the app opted in (WN_PASSKEY).
 */
if (function_exists('wn_passkey_enabled') && wn_passkey_enabled() && !empty($_SESSION['user_id'])) {
    $wn_pk_user    = get_user((int)$_SESSION['user_id']);
    $wn_pk_list    = wn_passkey_list((int)$_SESSION['user_id']);
    $wn_pk_blocked = twofa_enrol_blocker((int)$_SESSION['user_id']);
?>
<div class="card mb-4" id="wn-passkeys">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Passkeys</span>
        <?php if ($wn_pk_list) { ?><span class="badge bg-success"><?= count($wn_pk_list) ?></span><?php } else { ?><span class="badge bg-secondary">None</span><?php } ?>
    </div>
    <div class="card-body">
        <p class="small text-body-secondary">Sign in with your face, fingerprint or device PIN instead of your password. A passkey also works as your second step after a password. It stays on your device; we only keep a public key.</p>
        <?php if ($wn_pk_list) { ?>
        <ul class="list-group mb-3">
            <?php foreach ($wn_pk_list as $wn_pk) { ?>
            <li class="list-group-item">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <strong><?= h($wn_pk['name']) ?></strong>
                        <div class="small text-body-secondary">Added <?= h(substr($wn_pk['created'], 0, 10)) ?> · <?= $wn_pk['last_used'] ? 'last used ' . h(substr($wn_pk['last_used'], 0, 10)) : 'not used yet' ?><?= !empty($wn_pk['backed_up']) ? ' · synced' : '' ?></div>
                    </div>
                    <details>
                        <summary class="small">Rename or remove</summary>
                        <form method="post" class="row g-2 mt-1">
                            <input type="hidden" name="action" value="passkeyRename">
                            <input type="hidden" name="passkey_id" value="<?= (int)$wn_pk['passkey_id'] ?>">
                            <div class="col-8"><input type="text" class="form-control form-control-sm" name="name" maxlength="80" value="<?= h($wn_pk['name']) ?>" aria-label="Passkey name" required></div>
                            <div class="col-4"><button type="submit" class="btn btn-sm btn-outline-secondary w-100">Rename</button></div>
                        </form>
                        <form method="post" class="row g-2 mt-1">
                            <input type="hidden" name="action" value="passkeyRemove">
                            <input type="hidden" name="passkey_id" value="<?= (int)$wn_pk['passkey_id'] ?>">
                            <?php if (!empty($wn_pk_user['password'])) { ?>
                            <div class="col-8"><input type="password" class="form-control form-control-sm" name="password" placeholder="Your password" aria-label="Your password" autocomplete="current-password" required></div>
                            <?php } ?>
                            <div class="col-4"><button type="submit" class="btn btn-sm btn-outline-danger w-100">Remove</button></div>
                        </form>
                    </details>
                </div>
            </li>
            <?php } ?>
        </ul>
        <?php } ?>
        <?php if ($wn_pk_blocked !== '') { ?>
        <p class="small text-warning mb-0"><?= h($wn_pk_blocked) ?></p>
        <?php } else { ?>
        <div class="row g-2 align-items-end d-none" id="wn-passkey-add">
            <div class="col-sm-7">
                <label for="wn-passkey-name" class="form-label small">Name it (so you know which device)</label>
                <input type="text" class="form-control" id="wn-passkey-name" maxlength="80" placeholder="<?= h(wn_passkey_default_name()) ?>">
            </div>
            <div class="col-sm-5"><button type="button" class="btn btn-primary w-100" id="wn-passkey-add-btn">Add a passkey</button></div>
        </div>
        <p class="small text-body-secondary mb-0 d-none" id="wn-passkey-unsupported">This browser can't make passkeys. Try your phone, or a recent Safari, Chrome or Edge.</p>
        <p class="small text-danger mt-2 mb-0 d-none" id="wn-passkey-add-error" role="alert"></p>
        <script><?php readfile(__DIR__ . '/../assets/js/passkey.js'); ?></script>
        <script>
        (function () {
            if (!window.WnPasskey || !WnPasskey.supported()) { document.getElementById('wn-passkey-unsupported').classList.remove('d-none'); return; }
            document.getElementById('wn-passkey-add').classList.remove('d-none');
            var btn = document.getElementById('wn-passkey-add-btn'), err = document.getElementById('wn-passkey-add-error');
            btn.addEventListener('click', function () {
                btn.disabled = true; err.classList.add('d-none');
                WnPasskey.enrol(document.getElementById('wn-passkey-name').value, function (ok, msg) {
                    if (ok) { window.location.reload(); return; }
                    btn.disabled = false;
                    if (msg) { err.textContent = msg; err.classList.remove('d-none'); }
                });
            });
        })();
        </script>
        <?php } ?>
    </div>
</div>
<?php
}
