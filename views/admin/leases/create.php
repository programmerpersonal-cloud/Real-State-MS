<?php
/**
 * Lease — Create
 */
$fd  = $formData ?? [];
$uid = 'lc';
?>
<div class="card">
    <div class="card__header"><h3 class="card__title">New Lease Agreement</h3></div>
    <div class="card__body">
        <form method="post" enctype="multipart/form-data" data-validate>
            <?= csrfField() ?>

            <?php require __DIR__ . '/_form_fields.php'; ?>

            <div class="form-actions">
                <a href="<?= APP_URL ?>/index.php?page=leases" class="btn btn--outline">Cancel</a>
                <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg"></i> Create Lease</button>
            </div>
        </form>
    </div>
</div>
