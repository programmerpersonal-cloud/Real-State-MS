<?php
$pageTitle = 'Add Owner';
$breadcrumbs = [['label' => 'Owners', 'url' => APP_URL . '/index.php?page=owners'], ['label' => 'Add New']];
$fd = $formData ?? [];
$uid = 'oc';
$showAccount = true;   // shows the "you will be asked next" note
?>
<div class="card">
    <div class="card__header"><h3 class="card__title">Owner Information</h3></div>
    <div class="card__body">
        <form method="POST" data-validate>
            <?= csrfField() ?>

            <?php require __DIR__ . '/_form_fields.php'; ?>

            <div style="display:flex;gap:12px;margin-top:28px">
                <button type="submit" class="btn btn--primary btn--lg"><i class="bi bi-check-lg"></i> Create Owner</button>
                <a href="<?= APP_URL ?>/index.php?page=owners" class="btn btn--outline btn--lg">Cancel</a>
            </div>
        </form>
    </div>
</div>
