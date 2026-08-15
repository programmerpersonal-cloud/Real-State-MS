<?php
$pageTitle = 'Add Owner';
$breadcrumbs = [['label' => 'Owners', 'url' => APP_URL . '/index.php?page=owners'], ['label' => 'Add New']];
$fd = $formData ?? [];
?>
<div class="card">
    <div class="card__header"><h3 class="card__title">Owner Information</h3></div>
    <div class="card__body">
        <form method="POST" data-validate>
            <?= csrfField() ?>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="full_name" class="form-control" value="<?= sanitize($fd['full_name'] ?? '') ?>" required></div>
                <div class="form-group"><label class="form-label">Phone *</label><input type="tel" name="phone" class="form-control" value="<?= sanitize($fd['phone'] ?? '') ?>" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= sanitize($fd['email'] ?? '') ?>"></div>
                <div class="form-group"><label class="form-label">National ID</label><input type="text" name="national_id" class="form-control" value="<?= sanitize($fd['national_id'] ?? '') ?>"></div>
            </div>
            <div class="form-group"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"><?= sanitize($fd['address'] ?? '') ?></textarea></div>
            <div class="form-row" style="grid-template-columns:repeat(3,1fr)">
                <div class="form-group"><label class="form-label">Bank Name</label><input type="text" name="bank_name" class="form-control" value="<?= sanitize($fd['bank_name'] ?? '') ?>"></div>
                <div class="form-group"><label class="form-label">Bank Account</label><input type="text" name="bank_account" class="form-control" value="<?= sanitize($fd['bank_account'] ?? '') ?>"></div>
                <div class="form-group"><label class="form-label">Commission Rate (%)</label><input type="number" step="0.01" name="commission_rate" class="form-control" value="<?= $fd['commission_rate'] ?? '5.00' ?>"></div>
            </div>
            <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= sanitize($fd['notes'] ?? '') ?></textarea></div>
            <div style="display:flex;gap:12px;margin-top:28px">
                <button type="submit" class="btn btn--primary btn--lg"><i class="bi bi-check-lg"></i> Create Owner</button>
                <a href="<?= APP_URL ?>/index.php?page=owners" class="btn btn--outline btn--lg">Cancel</a>
            </div>
        </form>
    </div>
</div>
