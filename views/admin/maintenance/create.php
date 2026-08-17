<?php
/**
 * Maintenance — Create
 *
 * $properties is already scoped to what this user may file against. When it
 * is empty the form is replaced rather than rendered with nothing to pick:
 * a select with no options is a dead end that reads as a bug.
 */
$fd  = $formData ?? [];
$uid = 'mc';
?>
<div class="card" style="max-width:680px;margin:0 auto">
    <?php if (empty($properties)): ?>
        <div class="card__body">
            <div class="empty-state">
                <div class="empty-state__icon"><i class="bi bi-house-slash"></i></div>
                <div class="empty-state__title">No property to report against</div>
                <p class="empty-state__desc"><?= sanitize(maintenanceEmptyScopeMessage()) ?></p>
                <a href="<?= APP_URL ?>/index.php?page=maintenance" class="btn btn--outline btn--sm" style="margin-top:14px">
                    <i class="bi bi-arrow-left"></i> Back to requests
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="card__header"><h3 class="card__title">Report Maintenance Issue</h3></div>
        <div class="card__body">
            <form method="post" enctype="multipart/form-data" data-validate>
                <?= csrfField() ?>

                <?php require __DIR__ . '/_form_fields.php'; ?>

                <div class="form-actions">
                    <a href="<?= APP_URL ?>/index.php?page=maintenance" class="btn btn--outline">Cancel</a>
                    <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg"></i> Submit Request</button>
                </div>
            </form>
        </div>
    <?php endif ?>
</div>
