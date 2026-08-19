<?php
/**
 * Maintenance — Create
 *
 * $properties is already scoped to what this user may file against. When it
 * is empty the form is replaced rather than rendered with nothing to pick:
 * a select with no options is a dead end that reads as a bug.
 */
$fd   = $formData ?? [];
$errs = $formErrors ?? [];
$uid  = 'mc';
?>
<div class="card card--narrow">
    <?php if (empty($properties)): ?>
        <div class="card__body">
            <?= uiEmptyState([
                'icon'  => 'bi-house-slash',
                'title' => 'No property to report against',
                'desc'  => maintenanceEmptyScopeMessage(),
                'actions' => [[
                    'label' => 'Back to requests', 'icon' => 'bi-arrow-left', 'class' => 'btn--outline',
                    'url'   => APP_URL . '/index.php?page=maintenance',
                ]],
            ]) ?>
        </div>
    <?php else: ?>
        <div class="card__header">
            <h2 class="card__title">Report a fault</h2>
            <span class="text-subtle">The office is notified straight away</span>
        </div>
        <div class="card__body">
            <form method="post" enctype="multipart/form-data" data-validate>
                <?= csrfField() ?>


                <?php require __DIR__ . '/_form_fields.php'; ?>

                <div class="form-actions">
                    <a href="<?= APP_URL ?>/index.php?page=maintenance" class="btn btn--outline">Cancel</a>
                    <button type="submit" class="btn btn--primary">
                        <i class="bi bi-check-lg" aria-hidden="true"></i> Submit request
                    </button>
                </div>
            </form>
        </div>
    <?php endif ?>
</div>
