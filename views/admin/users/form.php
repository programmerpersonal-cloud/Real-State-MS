<?php
/**
 * User — Form (create / edit)
 */
$u = $user ?? ($formData ?? []);
$isEdit = !empty($user);
$uid = 'uf';
?>
<div class="card" style="max-width:680px;margin:0 auto">
    <div class="card__header"><h3 class="card__title"><?= $isEdit ? 'Edit' : 'New' ?> User</h3></div>
    <div class="card__body">
        <form method="post" data-validate>
            <?= csrfField() ?>

            <?php require __DIR__ . '/_form_fields.php'; ?>

            <div class="form-actions">
                <a href="<?= APP_URL ?>/index.php?page=users" class="btn btn--outline">Cancel</a>
                <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg"></i> Save</button>
            </div>
        </form>
    </div>
</div>
