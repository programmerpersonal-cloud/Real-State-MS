<?php
$pageTitle = 'Add Property';
$breadcrumbs = [['label' => 'Properties', 'url' => APP_URL . '/index.php?page=properties'], ['label' => 'Add New']];
$fd = $formData ?? [];
$uid = 'pc';
?>

<div class="card">
    <div class="card__header"><h3 class="card__title">Property Details</h3></div>
    <div class="card__body">
        <form method="POST" enctype="multipart/form-data" data-validate>
            <?= csrfField() ?>

            <?php require __DIR__ . '/_form_fields.php'; ?>

            <div style="display:flex;gap:12px;margin-top:28px">
                <button type="submit" class="btn btn--primary btn--lg"><i class="bi bi-check-lg"></i> Create Property</button>
                <a href="<?= APP_URL ?>/index.php?page=properties" class="btn btn--outline btn--lg">Cancel</a>
            </div>
        </form>
    </div>
</div>
