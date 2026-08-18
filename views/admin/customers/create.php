<?php
$pageTitle = 'Add Customer';
$breadcrumbs = [['label' => 'Customers', 'url' => APP_URL . '/index.php?page=customers'], ['label' => 'Add New']];
$fd = $formData ?? [];
$uid = 'cc';
$showAccount = true;   // shows the "you will be asked next" note
?>
<div class="card">
    <div class="card__header"><h3 class="card__title">Customer Information</h3></div>
    <div class="card__body">
        <form method="POST" enctype="multipart/form-data" data-validate>
            <?= csrfField() ?>

            <?php require __DIR__ . '/_form_fields.php'; ?>

            <div class="action-row">
                <button type="submit" class="btn btn--primary btn--lg"><i class="bi bi-check-lg"></i> Create Customer</button>
                <a href="<?= APP_URL ?>/index.php?page=customers" class="btn btn--outline btn--lg">Cancel</a>
            </div>
        </form>
    </div>
</div>
