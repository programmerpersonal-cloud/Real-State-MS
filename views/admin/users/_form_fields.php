<?php
/**
 * User form fields — shared by the create/edit page and the quick-add
 * popup, so the three entry points can never drift apart.
 *
 * Expects:  $roles, $branches
 * Optional: $u       existing user (edit), or entry kept after a reject
 *           $isEdit  whether this is editing an existing account
 *           $uid     id prefix for this instance's fields
 */
$uid    = $uid ?? 'usr';
$u      = $u ?? [];
$isEdit = $isEdit ?? false;
?>
<div class="form-grid--2">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-name">Full Name *</label>
        <input class="form-control" id="<?= $uid ?>-name" name="full_name"
               value="<?= sanitize($u['full_name'] ?? '') ?>" required>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-phone">Phone</label>
        <input class="form-control" id="<?= $uid ?>-phone" name="phone"
               value="<?= sanitize($u['phone'] ?? '') ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-email">Email *</label>
        <input class="form-control" id="<?= $uid ?>-email" type="email" name="email"
               value="<?= sanitize($u['email'] ?? '') ?>" required>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-username">Username *</label>
        <input class="form-control" id="<?= $uid ?>-username" name="username"
               value="<?= sanitize($u['username'] ?? '') ?>" required>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-password"><?= $isEdit ? 'Set New Password' : 'Password *' ?></label>
        <input class="form-control" id="<?= $uid ?>-password" type="password" name="password"
               autocomplete="new-password" <?= $isEdit ? '' : 'required' ?>>
        <div class="form-hint">
            <?= $isEdit ? 'Leave blank to keep the current password. Minimum 8 characters if changing.' : 'Minimum 8 characters.' ?>
        </div>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-role">Role *</label>
        <select class="form-control" id="<?= $uid ?>-role" name="role_id" required>
            <?php foreach ($roles as $r): ?>
                <option value="<?= $r['id'] ?>" <?= ($u['role_id'] ?? '') == $r['id'] ? 'selected' : '' ?>>
                    <?= sanitize($r['display_name']) ?>
                </option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-branch">Branch</label>
        <select class="form-control" id="<?= $uid ?>-branch" name="branch_id">
            <option value="">— None —</option>
            <?php foreach ($branches as $b): ?>
                <option value="<?= $b['id'] ?>" <?= ($u['branch_id'] ?? '') == $b['id'] ? 'selected' : '' ?>>
                    <?= sanitize($b['name']) ?>
                </option>
            <?php endforeach ?>
        </select>
    </div>
</div>

<?php if ($isEdit): ?>
<label class="form-checkbox">
    <input type="checkbox" name="is_active" <?= ($u['is_active'] ?? 1) ? 'checked' : '' ?>> Active
</label>
<?php endif ?>
