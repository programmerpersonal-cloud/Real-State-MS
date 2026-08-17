<?php
/**
 * Document upload popup — included by the Documents index and by the
 * Documents card on a property page.
 *
 * Expects:  $categories, $categoryMeta
 * Optional: $properties      omitted when uploading from a property page
 *           $fixedProperty   property row when the property is already known
 *           $formData        entry kept back after a rejected submit
 *           $openUploadModal reopen after a rejected submit
 */
$fd = $formData ?? [];
$uid = 'du';
$fixedProperty = $fixedProperty ?? null;
?>
<div class="modal" id="documentUploadModal" data-modal <?= !empty($openUploadModal) ? 'data-modal-autoopen' : '' ?> hidden>
    <div class="modal__backdrop" data-modal-close></div>
    <div class="modal__dialog modal__dialog--lg" role="dialog" aria-modal="true"
         aria-labelledby="documentUploadTitle" tabindex="-1">
        <header class="modal__header">
            <div>
                <h3 class="modal__title" id="documentUploadTitle">
                    <i class="bi bi-file-earmark-arrow-up"></i> Upload Document
                </h3>
                <p class="modal__subtitle">
                    <?php if ($fixedProperty): ?>
                        Filed against <strong><?= sanitize($fixedProperty['title']) ?></strong>.
                    <?php else: ?>
                        Stored securely and served only to people cleared to see it.
                    <?php endif ?>
                </p>
            </div>
            <button type="button" class="modal__close" data-modal-close aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </header>

        <form class="modal__form" method="POST" enctype="multipart/form-data" data-validate
              action="<?= APP_URL ?>/index.php?page=documents&amp;action=create">
            <?= csrfField() ?>
            <input type="hidden" name="return_to" value="<?= $fixedProperty ? 'property' : 'index' ?>">

            <div class="modal__body">
                <?php require __DIR__ . '/_form_fields.php'; ?>
            </div>

            <footer class="modal__footer">
                <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg"></i> Upload</button>
                <button type="button" class="btn btn--outline" data-modal-close>Cancel</button>
                <span class="modal__footer-note">
                    <i class="bi bi-shield-lock"></i> Files are never served directly from a URL.
                </span>
            </footer>
        </form>
    </div>
</div>
