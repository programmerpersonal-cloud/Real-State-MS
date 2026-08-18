<?php
/**
 * Branch form fields — shared by the create/edit page and the quick-add
 * popup, so the entry points can never drift apart.
 *
 * Optional: $b     existing branch, or entry kept after a rejected submit
 *           $errs  field-keyed errors from the same rejection
 *           $uid   id prefix for this instance's fields
 */
$uid  = $uid ?? 'brn';
$b    = $b ?? [];
$errs = $errs ?? ($formErrors ?? []);

$err  = static fn(string $f): string => uiFieldError($errs, $f);
$bad  = static fn(string $f): string => uiInvalidClass($errs, $f);
$aria = static fn(string $f, string $hint = ''): string => uiFieldAria($errs, $f, $hint);
?>
<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-name">Branch name <span class="req" aria-hidden="true">*</span></label>
    <input class="form-control<?= $bad('name') ?>" id="<?= $uid ?>-name" name="name"
           value="<?= sanitize($b['name'] ?? '') ?>" required<?= $aria('name', $uid . '-name-hint') ?>>
    <?= $err('name') ?>
    <div class="form-hint" id="<?= $uid ?>-name-hint">Shown wherever staff are assigned to an office.</div>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-address">Address</label>
    <textarea class="form-control<?= $bad('address') ?>" id="<?= $uid ?>-address" name="address" rows="2"
              <?= $aria('address') ?>><?= sanitize($b['address'] ?? '') ?></textarea>
    <?= $err('address') ?>
</div>

<div class="form-grid--2">
    <?php $phoneField = ['name' => 'phone', 'id' => $uid . '-phone', 'label' => 'Phone', 'value' => $b['phone'] ?? ''];
          require VIEWS_PATH . '/components/ui/phone_field.php'; ?>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-email">Email</label>
        <input class="form-control<?= $bad('email') ?>" id="<?= $uid ?>-email" name="email"
               type="email" value="<?= sanitize($b['email'] ?? '') ?>"<?= $aria('email') ?>>
        <?= $err('email') ?>
    </div>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-manager">Manager</label>
    <input class="form-control<?= $bad('manager_name') ?>" id="<?= $uid ?>-manager" name="manager_name"
           value="<?= sanitize($b['manager_name'] ?? '') ?>"<?= $aria('manager_name') ?>>
    <?= $err('manager_name') ?>
</div>

<div class="check-row">
    <label class="check">
        <input type="checkbox" name="is_active" <?= ($b['is_active'] ?? 1) ? 'checked' : '' ?>>
        <span>This branch is open</span>
    </label>
</div>
<div class="form-hint">A closed branch stops being offered when assigning staff. Existing assignments are kept.</div>
