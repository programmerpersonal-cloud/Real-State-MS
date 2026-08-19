<?php
/**
 * Customer form fields — shared by the full "Add Customer" page, the edit page
 * and the quick-add popup, so the three can never drift apart.
 *
 * Optional: $fd          form data (existing record, or entry kept after a reject)
 *           $uid         id prefix for this instance's fields
 *           $showAccount note that the access question follows (create only)
 *
 * Field-level messages come back in $_SESSION['form_errors'] keyed by field
 * name, so a rejected submit points at the box that needs fixing rather than
 * only shouting at the top of the page.
 */
$uid  = $uid ?? 'cus';
$fd   = $fd ?? [];
$errs = $formErrors ?? [];
$showAccount = $showAccount ?? false;

/* The shared helpers, as every other module's fields use. Rolling this by
   hand here cost the errors their id — which the summary at the top of the
   form links to — and left the inputs without aria-invalid, so a screen
   reader was told nothing had gone wrong. */
$err  = static fn(string $f): string => uiFieldError($errs, $f);
$bad  = static fn(string $f): string => uiInvalidClass($errs, $f);
$aria = static fn(string $f, string $hint = ''): string => uiFieldAria($errs, $f, $hint);
?>
<div class="form-row">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-name">Full Name <span class="req" aria-hidden="true">*</span></label>
        <input type="text" id="<?= $uid ?>-name" name="full_name" class="form-control<?= $bad('full_name') ?>"
               value="<?= sanitize($fd['full_name'] ?? '') ?>" required<?= $aria('full_name') ?>>
        <?= $err('full_name') ?>
    </div>
    <?php $phoneField = ['name' => 'phone', 'id' => $uid . '-phone', 'label' => 'Phone', 'value' => $fd['phone'] ?? '', 'required' => true];
          require VIEWS_PATH . '/components/ui/phone_field.php'; ?>
</div>

<div class="form-row">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-email">Email</label>
        <input type="email" id="<?= $uid ?>-email" name="email" class="form-control<?= $bad('email') ?>"
               value="<?= sanitize($fd['email'] ?? '') ?>"<?= $aria('email', $uid . '-email-hint') ?>>
        <?= $err('email') ?>
        <div class="form-hint" id="<?= $uid ?>-email-hint">Needed only if this customer should be able to sign in — it becomes their login.</div>
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

<div class="form-row form-row--3">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-gender">Gender</label>
        <select name="gender" id="<?= $uid ?>-gender" class="form-control">
            <option value="">—</option>
            <?php foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Other'] as $value => $label): ?>
                <option value="<?= $value ?>" <?= ($fd['gender'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-type">Customer Type <span class="req" aria-hidden="true">*</span></label>
        <select name="customer_type" id="<?= $uid ?>-type" class="form-control<?= $bad('customer_type') ?>" required<?= $aria('customer_type', $uid . '-type-hint') ?>>
            <?php foreach (['both' => 'Both', 'tenant' => 'Tenant', 'buyer' => 'Buyer'] as $value => $label): ?>
                <option value="<?= $value ?>" <?= ($fd['customer_type'] ?? 'both') === $value ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach ?>
        </select>
        <?= $err('customer_type') ?>
        <div class="form-hint" id="<?= $uid ?>-type-hint">Tenant rents, buyer purchases. Both is the safe choice when it is not settled yet.</div>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-risk">Risk Level</label>
        <select name="risk_level" id="<?= $uid ?>-risk" class="form-control">
            <?php foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'] as $value => $label): ?>
                <option value="<?= $value ?>" <?= ($fd['risk_level'] ?? 'low') === $value ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach ?>
        </select>
    </div>
</div>

<h3 class="form-section">Work</h3>

<div class="form-row">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-employment">Employment Status</label>
        <input type="text" id="<?= $uid ?>-employment" name="employment_status" class="form-control"
               value="<?= sanitize($fd['employment_status'] ?? '') ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-occupation">Occupation</label>
        <input type="text" id="<?= $uid ?>-occupation" name="occupation" class="form-control"
               value="<?= sanitize($fd['occupation'] ?? '') ?>">
    </div>
</div>

<h3 class="form-section">Emergency contact &amp; guarantor</h3>

<div class="form-row">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-econtact">Emergency Contact</label>
        <input type="text" id="<?= $uid ?>-econtact" name="emergency_contact" class="form-control"
               value="<?= sanitize($fd['emergency_contact'] ?? '') ?>">
    </div>
    <?php $phoneField = ['name' => 'emergency_phone', 'id' => $uid . '-ephone', 'label' => 'Emergency Phone', 'value' => $fd['emergency_phone'] ?? ''];
          require VIEWS_PATH . '/components/ui/phone_field.php'; ?>
</div>

<div class="form-row">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-gname">Guarantor Name</label>
        <input type="text" id="<?= $uid ?>-gname" name="guarantor_name" class="form-control"
               value="<?= sanitize($fd['guarantor_name'] ?? '') ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-gcontact">Guarantor Contact</label>
        <input type="text" id="<?= $uid ?>-gcontact" name="guarantor_contact" class="form-control"
               value="<?= sanitize($fd['guarantor_contact'] ?? '') ?>">
    </div>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-photo">Profile Photo</label>
    <input type="file" id="<?= $uid ?>-photo" name="profile_photo" class="form-control" accept="image/*">
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-notes">Internal Notes</label>
    <textarea name="notes" id="<?= $uid ?>-notes" class="form-control" rows="2"><?= sanitize($fd['notes'] ?? '') ?></textarea>
</div>

<?php if ($showAccount): ?>
    <?php /* Login access is not decided here. Saving the customer asks the
             question next, so the profile is safely stored before any account
             work begins — and the credentials step never blocks the customer
             from being recorded. */ ?>
    <div class="form-hint mt-2">
        <i class="bi bi-info-circle"></i>
        After saving you will be asked whether this customer should be able to sign in.
    </div>
<?php endif ?>
