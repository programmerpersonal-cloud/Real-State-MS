<?php
/**
 * Owner — Edit
 *
 * Account/login access is managed from the owner's detail page, so this form
 * stays purely the business record.
 */
$fd  = $formData;
$uid = 'oe';
$showAccount = false;
?>
<div class="card">
    <div class="card__header"><h3 class="card__title">Edit: <?= sanitize($fd['full_name']) ?></h3></div>
    <div class="card__body">
        <form method="POST" data-validate>
            <?= csrfField() ?>

            <?php require __DIR__ . '/_form_fields.php'; ?>

            <div class="form-actions">
                <a href="<?= APP_URL ?>/index.php?page=owners&action=show&id=<?= $fd['id'] ?>" class="btn btn--outline">Cancel</a>
                <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>
