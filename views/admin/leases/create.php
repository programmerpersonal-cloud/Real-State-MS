<?php
/**
 * Lease — Create
 */
$fd   = $formData ?? [];
$errs = $formErrors ?? [];
$uid  = 'lc';
?>
<div class="card">
    <div class="card__header">
        <h3 class="card__title">Lease agreement</h3>
        <span class="text-subtle">Saving also writes the rent schedule and the deposit record</span>
    </div>
    <div class="card__body">
        <form method="post" enctype="multipart/form-data" data-validate>
            <?= csrfField() ?>

            <?php require VIEWS_PATH . '/components/ui/error_summary.php'; ?>

            <?php require __DIR__ . '/_form_fields.php'; ?>

            <div class="form-actions">
                <a href="<?= APP_URL ?>/index.php?page=leases" class="btn btn--outline">Cancel</a>
                <button type="submit" class="btn btn--primary">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> Create lease
                </button>
            </div>
        </form>
    </div>
</div>
