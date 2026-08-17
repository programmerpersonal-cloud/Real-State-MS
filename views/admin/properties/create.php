<?php
$pageTitle = 'Add Property';
$breadcrumbs = [['label' => 'Properties', 'url' => APP_URL . '/index.php?page=properties'], ['label' => 'Add New']];
$fd = $formData ?? [];
$uid = 'pc';
?>

<div class="card">
    <div class="card__header">
        <h3 class="card__title">Property details</h3>
        <span class="text-subtle">New listings start as pending approval</span>
    </div>
    <div class="card__body">
        <form method="POST" enctype="multipart/form-data" data-validate>
            <?= csrfField() ?>

            <?php require VIEWS_PATH . '/components/ui/error_summary.php'; ?>

            <?php require __DIR__ . '/_form_fields.php'; ?>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> Create property
                </button>
                <a href="<?= APP_URL ?>/index.php?page=properties" class="btn btn--outline">Cancel</a>
            </div>
        </form>
    </div>
</div>
