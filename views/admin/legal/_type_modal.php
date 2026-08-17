<?php
/**
 * Add / edit a legal type.
 *
 * The slug is not editable: 'booking' is what the reservation form asks for and
 * 'general' is what the public terms page renders, so changing one would quietly
 * detach a type from the code that uses it.
 *
 * Optional: $editingType, $formData, $openTypeModal
 */
$fd  = $formData ?: ($editingType ?? []);
$tid = (int) ($fd['id'] ?? $editingType['id'] ?? 0);
?>
<div class="modal" id="termsTypeModal" data-modal <?= !empty($openTypeModal) ? 'data-modal-autoopen' : '' ?> hidden>
    <div class="modal__backdrop" data-modal-close></div>
    <div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="termsTypeTitle" tabindex="-1">
        <header class="modal__header">
            <div>
                <h3 class="modal__title" id="termsTypeTitle">
                    <i class="bi bi-file-earmark-check"></i> <?= $tid > 0 ? 'Edit Terms Type' : 'Add Terms Type' ?>
                </h3>
                <p class="modal__subtitle">
                    A container for versioned legal wording — the text itself lives in its versions.
                </p>
            </div>
            <button type="button" class="modal__close" data-modal-close aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </header>

        <form class="modal__form" method="POST" data-validate
              action="<?= APP_URL ?>/index.php?page=legal&amp;action=save-type">
            <?= csrfField() ?>
            <input type="hidden" name="id" value="<?= $tid ?>">

            <div class="modal__body">
                <div class="form-group">
                    <label class="form-label" for="tt-name">Name *</label>
                    <input type="text" id="tt-name" name="name" class="form-control" required maxlength="120"
                           value="<?= sanitize($fd['name'] ?? '') ?>" placeholder="e.g. Short-let Terms">
                </div>

                <div class="form-group">
                    <label class="form-label" for="tt-desc">Description</label>
                    <input type="text" id="tt-desc" name="description" class="form-control" maxlength="255"
                           value="<?= sanitize($fd['description'] ?? '') ?>"
                           placeholder="When these terms apply">
                </div>

                <?php if ($tid > 0 && !empty($fd['slug'])): ?>
                    <div class="form-group">
                        <label class="form-label">Reference key</label>
                        <input type="text" class="form-control" value="<?= sanitize($fd['slug']) ?>" disabled>
                        <div class="form-hint">
                            Fixed once created — the application looks this type up by key, so changing it
                            would detach it from the process that uses it.
                        </div>
                    </div>
                <?php endif ?>

                <label class="form-checkbox">
                    <input type="checkbox" name="requires_acceptance" value="1"
                           <?= (!isset($fd['requires_acceptance']) || !empty($fd['requires_acceptance'])) ? 'checked' : '' ?>>
                    <span>Require explicit acceptance, and record who agreed</span>
                </label>

                <label class="form-checkbox">
                    <input type="checkbox" name="is_active" value="1"
                           <?= (!isset($fd['is_active']) || !empty($fd['is_active'])) ? 'checked' : '' ?>>
                    <span>Active</span>
                </label>
            </div>

            <footer class="modal__footer">
                <button type="submit" class="btn btn--primary">
                    <i class="bi bi-check-lg"></i> <?= $tid > 0 ? 'Save changes' : 'Add type' ?>
                </button>
                <button type="button" class="btn btn--outline" data-modal-close>Cancel</button>
            </footer>
        </form>
    </div>
</div>
