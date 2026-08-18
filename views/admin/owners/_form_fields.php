<?php
/**
 * Owner form fields — shared by the full "Add Owner" page and the
 * quick-add popup, so the two can never drift apart.
 *
 * Optional: $fd          form data (existing record, or entry kept after a reject)
 *           $uid         id prefix for this instance's fields
 *           $showAccount note that the access question follows (create only)
 *
 * Field-level messages come back in $_SESSION['form_errors'] keyed by field
 * name, so a rejected submit points at the box that needs fixing rather than
 * only shouting at the top of the page.
 */
$uid  = $uid ?? 'own';
$fd   = $fd ?? [];
$errs = $formErrors ?? [];
$showAccount = $showAccount ?? false;

/** Error text for a field, or '' when it is fine. */
$err = static fn(string $field): string => (string) ($errs[$field] ?? '');
/** Adds the error outline to a control that was rejected. */
$errClass = static fn(string $field): string => isset($errs[$field]) ? ' form-control--error' : '';
?>
<div class="form-row">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-name">Full Name *</label>
        <input type="text" id="<?= $uid ?>-name" name="full_name" class="form-control<?= $errClass('full_name') ?>"
               value="<?= sanitize($fd['full_name'] ?? '') ?>" required>
        <?php if ($err('full_name')): ?>
            <div class="form-error"><i class="bi bi-exclamation-circle"></i> <?= sanitize($err('full_name')) ?></div>
        <?php endif ?>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-phone">Phone *</label>
        <input type="tel" id="<?= $uid ?>-phone" name="phone" class="form-control<?= $errClass('phone') ?>"
               value="<?= sanitize($fd['phone'] ?? '') ?>" required>
        <?php if ($err('phone')): ?>
            <div class="form-error"><i class="bi bi-exclamation-circle"></i> <?= sanitize($err('phone')) ?></div>
        <?php endif ?>
    </div>
</div>

<div class="form-row">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-email">Email</label>
        <input type="email" id="<?= $uid ?>-email" name="email" class="form-control<?= $errClass('email') ?>"
               value="<?= sanitize($fd['email'] ?? '') ?>" data-owner-email>
        <?php if ($err('email')): ?>
            <div class="form-error"><i class="bi bi-exclamation-circle"></i> <?= sanitize($err('email')) ?></div>
        <?php endif ?>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-nid">National ID</label>
        <input type="text" id="<?= $uid ?>-nid" name="national_id" class="form-control"
               value="<?= sanitize($fd['national_id'] ?? '') ?>">
    </div>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-address">Address</label>
    <textarea name="address" id="<?= $uid ?>-address" class="form-control" rows="2"><?= sanitize($fd['address'] ?? '') ?></textarea>
</div>

<h4 class="form-section">Payout</h4>

<div class="form-row form-row--3">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-bank">Bank Name</label>
        <input type="text" id="<?= $uid ?>-bank" name="bank_name" class="form-control"
               value="<?= sanitize($fd['bank_name'] ?? '') ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-account">Bank Account</label>
        <input type="text" id="<?= $uid ?>-account" name="bank_account" class="form-control"
               value="<?= sanitize($fd['bank_account'] ?? '') ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-commission">Commission Rate (%)</label>
        <input type="number" step="0.01" min="0" max="100" id="<?= $uid ?>-commission" name="commission_rate"
               class="form-control" value="<?= sanitize((string)($fd['commission_rate'] ?? '5.00')) ?>">
    </div>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-notes">Notes</label>
    <textarea name="notes" id="<?= $uid ?>-notes" class="form-control" rows="2"><?= sanitize($fd['notes'] ?? '') ?></textarea>
</div>

<?php if ($showAccount): ?>
    <?php /* Login access is not decided here. Saving the owner asks the
             question next, so the profile is safely stored before any account
             work begins — and the credentials step never blocks the owner
             from being recorded. */ ?>
    <div class="form-hint mt-2" >
        <i class="bi bi-info-circle"></i>
        After saving you will be asked whether this owner should be able to sign in.
    </div>
<?php endif ?>
