<?php
$fd = $formData;
?>
<div class="card">
    <div class="card__header"><h3 class="card__title">Edit: <?= sanitize($fd['full_name']) ?></h3></div>
    <div class="card__body">
        <form method="POST" data-validate>
            <?= csrfField() ?>
            <div class="form-grid--2">
                <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="full_name" class="form-control" value="<?= sanitize($fd['full_name']) ?>" required></div>
                <div class="form-group"><label class="form-label">Phone *</label><input type="tel" name="phone" class="form-control" value="<?= sanitize($fd['phone']) ?>" required></div>
                <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= sanitize($fd['email'] ?? '') ?>"></div>
                <div class="form-group"><label class="form-label">National ID</label><input type="text" name="national_id" class="form-control" value="<?= sanitize($fd['national_id'] ?? '') ?>"></div>
            </div>
            <div class="form-group"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"><?= sanitize($fd['address'] ?? '') ?></textarea></div>
            <div class="form-grid--3">
                <div class="form-group"><label class="form-label">Bank Name</label><input type="text" name="bank_name" class="form-control" value="<?= sanitize($fd['bank_name'] ?? '') ?>"></div>
                <div class="form-group"><label class="form-label">Bank Account</label><input type="text" name="bank_account" class="form-control" value="<?= sanitize($fd['bank_account'] ?? '') ?>"></div>
                <div class="form-group"><label class="form-label">Commission %</label><input type="number" step="0.01" name="commission_rate" class="form-control" value="<?= $fd['commission_rate'] ?? '5.00' ?>"></div>
            </div>
            <div class="form-group"><label class="form-label">Revenue Share %</label><input type="number" step="0.01" name="revenue_share" class="form-control" value="<?= $fd['revenue_share'] ?? '' ?>"></div>
            <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= sanitize($fd['notes'] ?? '') ?></textarea></div>
            <div class="form-actions">
                <a href="<?= APP_URL ?>/index.php?page=owners&action=show&id=<?= $fd['id'] ?>" class="btn btn--outline">Cancel</a>
                <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>
