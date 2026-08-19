<?php
/**
 * Payments — Create
 */
$fd   = $formData ?? [];
$errs = $formErrors ?? [];
$uid  = 'pyc';
?>
<div class="card card--narrow">
    <div class="card__header">
        <h2 class="card__title">Payment details</h2>
        <span class="text-subtle">A receipt is generated on save</span>
    </div>
    <div class="card__body">
        <form method="post" data-validate>
            <?= csrfField() ?>

            <?php require VIEWS_PATH . '/components/ui/error_summary.php'; ?>

            <?php require __DIR__ . '/_form_fields.php'; ?>

            <div class="form-actions">
                <a href="<?= APP_URL ?>/index.php?page=payments" class="btn btn--outline">Cancel</a>
                <button type="submit" class="btn btn--primary">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> Record payment
                </button>
            </div>
        </form>
    </div>
</div>
