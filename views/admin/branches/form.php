<?php
/**
 * Branch — Form (create/edit)
 */
$b = $branch ?? [];
?>
<div class="card" style="max-width:560px;margin:0 auto">
    <div class="card__header"><h3 class="card__title"><?= $b ? 'Edit' : 'New' ?> Branch</h3></div>
    <div class="card__body">
        <form method="post" data-validate>
            <?= csrfField() ?>
            <div class="form-group">
                <label class="form-label">Branch Name *</label>
                <input class="form-control" name="name" value="<?= sanitize($b['name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Address</label>
                <textarea class="form-control" name="address" rows="2"><?= sanitize($b['address'] ?? '') ?></textarea>
            </div>
            <div class="form-grid--2">
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input class="form-control" name="phone" value="<?= sanitize($b['phone'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input class="form-control" name="email" type="email" value="<?= sanitize($b['email'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Manager</label>
                <input class="form-control" name="manager_name" value="<?= sanitize($b['manager_name'] ?? '') ?>">
            </div>
            <label class="form-checkbox">
                <input type="checkbox" name="is_active" <?= ($b['is_active'] ?? 1) ? 'checked' : '' ?>> Active
            </label>
            <div class="form-actions">
                <a href="<?= APP_URL ?>/index.php?page=branches" class="btn btn--outline">Cancel</a>
                <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg"></i> Save</button>
            </div>
        </form>
    </div>
</div>
