<?php
/**
 * Branch form fields — shared by the create/edit page and the quick-add
 * popup, so the entry points can never drift apart.
 *
 * Optional: $b    existing branch, or entry kept after a rejected submit
 *           $uid  id prefix for this instance's fields
 */
$uid = $uid ?? 'brn';
$b   = $b ?? [];
?>
<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-name">Branch Name *</label>
    <input class="form-control" id="<?= $uid ?>-name" name="name"
           value="<?= sanitize($b['name'] ?? '') ?>" required>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-address">Address</label>
    <textarea class="form-control" id="<?= $uid ?>-address" name="address" rows="2"><?= sanitize($b['address'] ?? '') ?></textarea>
</div>

<div class="form-grid--2">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-phone">Phone</label>
        <input class="form-control" id="<?= $uid ?>-phone" name="phone" value="<?= sanitize($b['phone'] ?? '') ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-email">Email</label>
        <input class="form-control" id="<?= $uid ?>-email" name="email" type="email" value="<?= sanitize($b['email'] ?? '') ?>">
    </div>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-manager">Manager</label>
    <input class="form-control" id="<?= $uid ?>-manager" name="manager_name" value="<?= sanitize($b['manager_name'] ?? '') ?>">
</div>

<label class="form-checkbox">
    <input type="checkbox" name="is_active" <?= ($b['is_active'] ?? 1) ? 'checked' : '' ?>> Active
</label>
