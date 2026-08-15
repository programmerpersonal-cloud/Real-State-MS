<?php
$pageTitle = 'Edit Customer';
$breadcrumbs = [['label' => 'Customers', 'url' => APP_URL . '/index.php?page=customers'], ['label' => $formData['full_name']]];
$fd = $formData;
?>
<div class="card">
    <div class="card__header"><h3 class="card__title">Edit: <?= sanitize($fd['full_name']) ?></h3></div>
    <div class="card__body">
        <form method="POST" enctype="multipart/form-data" data-validate>
            <?= csrfField() ?>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="full_name" class="form-control" value="<?= sanitize($fd['full_name']) ?>" required></div>
                <div class="form-group"><label class="form-label">Phone *</label><input type="tel" name="phone" class="form-control" value="<?= sanitize($fd['phone']) ?>" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= sanitize($fd['email'] ?? '') ?>"></div>
                <div class="form-group"><label class="form-label">National ID</label><input type="text" name="national_id" class="form-control" value="<?= sanitize($fd['national_id'] ?? '') ?>"></div>
            </div>
            <div class="form-group"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"><?= sanitize($fd['address'] ?? '') ?></textarea></div>
            <div class="form-row" style="grid-template-columns:repeat(3,1fr)">
                <div class="form-group"><label class="form-label">Gender</label><select name="gender" class="form-control"><option value="">—</option><option value="male" <?=($fd['gender']??'')==='male'?'selected':''?>>Male</option><option value="female" <?=($fd['gender']??'')==='female'?'selected':''?>>Female</option></select></div>
                <div class="form-group"><label class="form-label">Type</label><select name="customer_type" class="form-control"><option value="both" <?=($fd['customer_type']??'')==='both'?'selected':''?>>Both</option><option value="tenant" <?=($fd['customer_type']??'')==='tenant'?'selected':''?>>Tenant</option><option value="buyer" <?=($fd['customer_type']??'')==='buyer'?'selected':''?>>Buyer</option></select></div>
                <div class="form-group"><label class="form-label">Risk Level</label><select name="risk_level" class="form-control"><option value="low" <?=($fd['risk_level']??'')==='low'?'selected':''?>>Low</option><option value="medium" <?=($fd['risk_level']??'')==='medium'?'selected':''?>>Medium</option><option value="high" <?=($fd['risk_level']??'')==='high'?'selected':''?>>High</option></select></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Employment</label><input type="text" name="employment_status" class="form-control" value="<?= sanitize($fd['employment_status'] ?? '') ?>"></div>
                <div class="form-group"><label class="form-label">Occupation</label><input type="text" name="occupation" class="form-control" value="<?= sanitize($fd['occupation'] ?? '') ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Emergency Contact</label><input type="text" name="emergency_contact" class="form-control" value="<?= sanitize($fd['emergency_contact'] ?? '') ?>"></div>
                <div class="form-group"><label class="form-label">Emergency Phone</label><input type="tel" name="emergency_phone" class="form-control" value="<?= sanitize($fd['emergency_phone'] ?? '') ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Guarantor</label><input type="text" name="guarantor_name" class="form-control" value="<?= sanitize($fd['guarantor_name'] ?? '') ?>"></div>
                <div class="form-group"><label class="form-label">Guarantor Contact</label><input type="text" name="guarantor_contact" class="form-control" value="<?= sanitize($fd['guarantor_contact'] ?? '') ?>"></div>
            </div>
            <div class="form-group"><label class="form-label">Photo</label><input type="file" name="profile_photo" class="form-control" accept="image/*"></div>
            <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= sanitize($fd['notes'] ?? '') ?></textarea></div>
            <div style="display:flex;gap:12px;margin-top:28px">
                <button type="submit" class="btn btn--primary btn--lg"><i class="bi bi-check-lg"></i> Save Changes</button>
                <a href="<?= APP_URL ?>/index.php?page=customers&action=show&id=<?=$fd['id']?>" class="btn btn--outline btn--lg">Cancel</a>
            </div>
        </form>
    </div>
</div>
