<?php
/**
 * snippets/modal_edit_api_key_scopes.php
 * Modal for editing service API key scopes.
 * Included inline in views/api_keys.php.
 */
$available_scopes = get_available_scopes();
?>
<div class="modal fade" id="editScopesModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-1"></i> Edit API Key Scopes</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Key Name</label>
                    <input type="text" class="form-control bg-secondary-subtle" id="editKeyName" readonly>
                </div>
                <input type="hidden" id="editKeyId">
                <div class="mb-3">
                    <label class="form-label">Scopes</label>
                    <?php foreach ($available_scopes as $scope => $desc) { ?>
                    <div class="form-check">
                        <input class="form-check-input edit-scope-check" type="checkbox"
                               value="<?= h($scope) ?>"
                               id="edit_scope_<?= h(str_replace(':', '_', $scope)) ?>">
                        <label class="form-check-label" for="edit_scope_<?= h(str_replace(':', '_', $scope)) ?>">
                            <code><?= h($scope) ?></code> — <?= h($desc) ?>
                        </label>
                    </div>
                    <?php } ?>
                    <div class="form-text mt-1">Select which resources this key can access.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveScopes()">
                    <i class="bi bi-check-lg me-1"></i> Save Scopes
                </button>
            </div>
        </div>
    </div>
</div>
