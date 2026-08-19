<?php
$pageTitle = 'Add Customer';
$breadcrumbs = [['label' => 'Customers', 'url' => APP_URL . '/index.php?page=customers'], ['label' => 'Add New']];
$fd = $formData ?? [];
$uid = 'cc';
$showAccount = true;   // shows the "you will be asked next" note
?>
<div class="card">
    <div class="card__header"><h2 class="card__title">Customer Information</h2></div>
    <div class="card__body">
        <form method="POST" enctype="multipart/form-data" data-validate>
            <?= csrfField() ?>

            <?php require VIEWS_PATH . '/components/ui/error_summary.php'; ?>

            <?php require __DIR__ . '/_form_fields.php'; ?>

            <div class="form-actions">
                <a href="<?= APP_URL ?>/index.php?page=customers" class="btn btn--outline">Cancel</a>
                <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Create customer</button>
            </div>
        </form>
    </div>
</div>
