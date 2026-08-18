<?php
/**
 * Add / edit a document category.
 *
 * One popup serves both: an id in the hidden field means edit, no id means
 * create. $editing is populated when the page was opened with ?modal=edit&id=.
 *
 * Expects:  $visibilities
 * Optional: $editing, $formData, $openModal
 */
$fd  = $formData ?: ($editing ?? []);
$eid = (int) ($fd['id'] ?? $editing['id'] ?? 0);
?>
<div class="modal" id="categoryModal" data-modal <?= !empty($openModal) ? 'data-modal-autoopen' : '' ?> hidden>
    <div class="modal__backdrop" data-modal-close></div>
    <div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="categoryModalTitle" tabindex="-1">
        <header class="modal__header">
            <div>
                <h3 class="modal__title" id="categoryModalTitle">
                    <i class="bi bi-tags"></i> <?= $eid > 0 ? 'Edit Category' : 'Add Category' ?>
                </h3>
                <p class="modal__subtitle">
                    <?php if ($eid > 0): ?>
                        Renaming is safe — documents already filed keep their link to this category.
                    <?php else: ?>
                        A new document type staff can file against a property.
                    <?php endif ?>
                </p>
            </div>
            <button type="button" class="modal__close" data-modal-close aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </header>

        <form class="modal__form" method="POST" data-validate
              action="<?= APP_URL ?>/index.php?page=document-categories&amp;action=save">
            <?= csrfField() ?>
            <input type="hidden" name="id" value="<?= $eid ?>">

            <div class="modal__body">
                <div class="form-group">
                    <label class="form-label" for="cat-name">Name <span class="req" aria-hidden="true">*</span></label>
                    <input type="text" id="cat-name" name="name" class="form-control" required maxlength="100"
                           value="<?= sanitize($fd['name'] ?? '') ?>" placeholder="e.g. Energy Certificate">
                </div>

                <div class="form-group">
                    <label class="form-label" for="cat-desc">Description</label>
                    <input type="text" id="cat-desc" name="description" class="form-control" maxlength="255"
                           value="<?= sanitize($fd['description'] ?? '') ?>"
                           placeholder="What belongs in this category">
                </div>

                <div class="form-grid--2">
                    <div class="form-group">
                        <label class="form-label" for="cat-vis">Default visibility</label>
                        <select id="cat-vis" name="default_visibility" class="form-control">
                            <?php foreach ($visibilities as $k => $label): ?>
                                <option value="<?= $k ?>" <?= ($fd['default_visibility'] ?? 'staff') === $k ? 'selected' : '' ?>>
                                    <?= sanitize($label) ?>
                                </option>
                            <?php endforeach ?>
                        </select>
                        <div class="form-hint">Pre-selected when someone files a document here. They can still change it.</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="cat-icon">Icon</label>
                        <input type="text" id="cat-icon" name="icon" class="form-control" maxlength="50"
                               pattern="bi-[a-z0-9-]+"
                               value="<?= sanitize($fd['icon'] ?? 'bi-file-earmark-text') ?>"
                               placeholder="bi-file-earmark-text">
                        <div class="form-hint">
                            A <a href="https://icons.getbootstrap.com/" target="_blank" rel="noopener">Bootstrap Icons</a> name.
                        </div>
                    </div>
                </div>

                <label class="form-checkbox">
                    <input type="checkbox" name="requires_expiry" value="1"
                           <?= !empty($fd['requires_expiry']) ? 'checked' : '' ?>>
                    <span>Documents here usually expire — prompt for an expiry date</span>
                </label>

                <label class="form-checkbox">
                    <input type="checkbox" name="is_active" value="1"
                           <?= (!isset($fd['is_active']) || !empty($fd['is_active'])) ? 'checked' : '' ?>>
                    <span>Active — available when filing a new document</span>
                </label>
            </div>

            <footer class="modal__footer">
                <button type="submit" class="btn btn--primary">
                    <i class="bi bi-check-lg"></i> <?= $eid > 0 ? 'Save changes' : 'Add category' ?>
                </button>
                <button type="button" class="btn btn--outline" data-modal-close>Cancel</button>
            </footer>
        </form>
    </div>
</div>
