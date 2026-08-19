<?php
/**
 * Sales — Create
 */
$fd   = $formData ?? [];
$errs = $formErrors ?? [];
$uid  = 'sc';
?>
<div class="card">
    <div class="card__header">
        <h2 class="card__title">Sale details</h2>
        <span class="text-subtle">Completing a sale marks the property sold</span>
    </div>
    <div class="card__body">
        <form method="post" data-validate>
            <?= csrfField() ?>

            <?php require VIEWS_PATH . '/components/ui/error_summary.php'; ?>

            <?php require __DIR__ . '/_form_fields.php'; ?>

            <div class="form-actions">
                <a href="<?= APP_URL ?>/index.php?page=sales" class="btn btn--outline">Cancel</a>
                <button type="submit" class="btn btn--primary">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> Record sale
                </button>
            </div>
        </form>
    </div>
</div>
