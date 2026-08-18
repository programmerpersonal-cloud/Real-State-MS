<?php
/**
 * User form fields — shared by the create/edit page and the quick-add
 * popup, so the three entry points can never drift apart.
 *
 * Expects:  $roles, $branches
 * Optional: $u       existing user (edit), or entry kept after a reject
 *           $errs    field-keyed errors from the same rejection
 *           $isEdit  whether this is editing an existing account
 *           $uid     id prefix for this instance's fields
 */
$uid    = $uid ?? 'usr';
$u      = $u ?? [];
$errs   = $errs ?? ($formErrors ?? []);
$isEdit = $isEdit ?? false;

$err  = static fn(string $f): string => uiFieldError($errs, $f);
$bad  = static fn(string $f): string => uiInvalidClass($errs, $f);
$aria = static fn(string $f, string $hint = ''): string => uiFieldAria($errs, $f, $hint);
?>
<h4 class="form-section">The person</h4>

<div class="form-grid--2">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-name">Full name <span class="req" aria-hidden="true">*</span></label>
        <input class="form-control<?= $bad('full_name') ?>" id="<?= $uid ?>-name" name="full_name"
               autocomplete="name" value="<?= sanitize($u['full_name'] ?? '') ?>" required<?= $aria('full_name') ?>>
        <?= $err('full_name') ?>
    </div>
    <?php $phoneField = ['name' => 'phone', 'id' => $uid . '-phone', 'label' => 'Phone', 'value' => $u['phone'] ?? ''];
          require VIEWS_PATH . '/components/ui/phone_field.php'; ?>
</div>

<h4 class="form-section">How they sign in</h4>

<div class="form-grid--2">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-email">Email <span class="req" aria-hidden="true">*</span></label>
        <input class="form-control<?= $bad('email') ?>" id="<?= $uid ?>-email" type="email" name="email"
               autocomplete="email" value="<?= sanitize($u['email'] ?? '') ?>" required
               <?= $aria('email', $uid . '-email-hint') ?>>
        <?= $err('email') ?>
        <div class="form-hint" id="<?= $uid ?>-email-hint">Either the email or the username can be used to sign in.</div>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-username">Username <span class="req" aria-hidden="true">*</span></label>
        <input class="form-control<?= $bad('username') ?>" id="<?= $uid ?>-username" name="username"
               autocomplete="username" value="<?= sanitize($u['username'] ?? '') ?>" required<?= $aria('username') ?>>
        <?= $err('username') ?>
    </div>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-password">
        <?= $isEdit ? 'Set a new password' : 'Password' ?>
        <?php if (!$isEdit): ?><span class="req" aria-hidden="true">*</span><?php endif ?>
    </label>
    <input class="form-control<?= $bad('password') ?>" id="<?= $uid ?>-password" type="password" name="password"
           autocomplete="new-password" <?= $isEdit ? '' : 'required' ?><?= $aria('password', $uid . '-pw-hint') ?>>
    <?= $err('password') ?>
    <div class="form-hint" id="<?= $uid ?>-pw-hint">
        <?= $isEdit
            ? 'Leave blank to keep the current password. At least 8 characters if changing it.'
            : 'At least 8 characters. Pass it on securely and ask them to change it.' ?>
    </div>
</div>

<h4 class="form-section">What they may do</h4>

<div class="form-grid--2">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-role">Role <span class="req" aria-hidden="true">*</span></label>
        <select class="form-control<?= $bad('role_id') ?>" id="<?= $uid ?>-role" name="role_id" required
                <?= $aria('role_id', $uid . '-role-hint') ?>>
            <?php foreach ($roles as $r): ?>
                <option value="<?= $r['id'] ?>" <?= ($u['role_id'] ?? '') == $r['id'] ? 'selected' : '' ?>>
                    <?= sanitize($r['display_name']) ?>
                </option>
            <?php endforeach ?>
        </select>
        <?= $err('role_id') ?>
        <div class="form-hint" id="<?= $uid ?>-role-hint">
            The role decides everything this account may do —
            <a href="<?= APP_URL ?>/index.php?page=users&amp;action=permissions">see the full matrix</a>.
        </div>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-branch">Branch</label>
        <select class="form-control<?= $bad('branch_id') ?>" id="<?= $uid ?>-branch" name="branch_id"
                <?= $aria('branch_id') ?>>
            <option value="">— None —</option>
            <?php foreach ($branches as $b): ?>
                <option value="<?= $b['id'] ?>" <?= ($u['branch_id'] ?? '') == $b['id'] ? 'selected' : '' ?>>
                    <?= sanitize($b['name']) ?>
                </option>
            <?php endforeach ?>
        </select>
        <?= $err('branch_id') ?>
    </div>
</div>

<?php if ($isEdit): ?>
    <div class="check-row">
        <label class="check">
            <input type="checkbox" name="is_active" <?= ($u['is_active'] ?? 1) ? 'checked' : '' ?>>
            <span>This account can sign in</span>
        </label>
    </div>
    <div class="form-hint">
        Clearing this stops them signing in. Nothing they created is removed, and access can be given back at any time.
    </div>
<?php endif ?>
