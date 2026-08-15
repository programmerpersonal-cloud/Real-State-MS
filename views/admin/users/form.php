<?php
/**
 * User — Form (create / edit)
 */
$u = $user ?? [];
$isEdit = !empty($u);
?>
<div class="card" style="max-width:680px;margin:0 auto">
    <div class="card__header"><h3 class="card__title"><?= $isEdit ? 'Edit' : 'New' ?> User</h3></div>
    <div class="card__body">
        <form method="post" data-validate>
            <?= csrfField() ?>
            <div class="form-grid--2">
                <div class="form-group">
                    <label class="form-label">Full Name *</label>
                    <input class="form-control" name="full_name" value="<?= sanitize($u['full_name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input class="form-control" name="phone" value="<?= sanitize($u['phone'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Email *</label>
                    <input class="form-control" type="email" name="email" value="<?= sanitize($u['email'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Username *</label>
                    <input class="form-control" name="username" value="<?= sanitize($u['username'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= $isEdit ? 'Set New Password' : 'Password *' ?></label>
                    <input class="form-control" type="password" name="password" autocomplete="new-password" <?= $isEdit ? '' : 'required' ?>>
                    <div class="form-hint">
                        <?= $isEdit ? 'Leave blank to keep the current password. Minimum 8 characters if changing.' : 'Minimum 8 characters.' ?>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Role *</label>
                    <select class="form-control" name="role_id" required>
                        <?php foreach ($roles as $r): ?>
                        <option value="<?= $r['id'] ?>" <?= ($u['role_id'] ?? '') == $r['id'] ? 'selected' : '' ?>><?= sanitize($r['display_name']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Branch</label>
                    <select class="form-control" name="branch_id">
                        <option value="">— None —</option>
                        <?php foreach ($branches as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= ($u['branch_id'] ?? '') == $b['id'] ? 'selected' : '' ?>><?= sanitize($b['name']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
            </div>
            <?php if ($isEdit): ?>
            <label class="form-checkbox">
                <input type="checkbox" name="is_active" <?= ($u['is_active'] ?? 1) ? 'checked' : '' ?>> Active
            </label>
            <?php endif ?>
            <div class="form-actions">
                <a href="<?= APP_URL ?>/index.php?page=users" class="btn btn--outline">Cancel</a>
                <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg"></i> Save</button>
            </div>
        </form>
    </div>
</div>
