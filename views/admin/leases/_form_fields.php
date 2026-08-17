<?php
/**
 * Lease form fields — shared by the full "New Lease" page and the
 * quick-add popup, so the two can never drift apart.
 *
 * Expects:  $properties, $customers
 * Optional: $fd    entry kept back after a rejected submit
 *           $errs  field-keyed errors from the same rejection
 *           $uid   id prefix for this instance's fields
 */
$uid  = $uid ?? 'lse';
$fd   = $fd ?? [];
$errs = $errs ?? ($formErrors ?? []);

$err  = static fn(string $f): string => uiFieldError($errs, $f);
$bad  = static fn(string $f): string => uiInvalidClass($errs, $f);
$aria = static fn(string $f, string $hint = ''): string => uiFieldAria($errs, $f, $hint);

$schedules = $schedules ?? ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly'];
?>
<h4 class="form-section">Parties</h4>

<div class="form-grid--2">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-customer">Tenant <span class="req" aria-hidden="true">*</span></label>
        <select class="form-control<?= $bad('customer_id') ?>" id="<?= $uid ?>-customer" name="customer_id"
                required<?= $aria('customer_id', $uid . '-customer-hint') ?>>
            <option value="">— Select customer —</option>
            <?php foreach ($customers as $c): ?>
                <option value="<?= $c['id'] ?>" <?= (int)($fd['customer_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                    <?= sanitize($c['full_name']) ?> · <?= sanitize($c['phone']) ?>
                </option>
            <?php endforeach ?>
        </select>
        <?= $err('customer_id') ?>
        <div class="form-hint" id="<?= $uid ?>-customer-hint">Blacklisted customers are not offered.</div>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-property">Property <span class="req" aria-hidden="true">*</span></label>
        <select class="form-control<?= $bad('property_id') ?>" id="<?= $uid ?>-property" name="property_id" required
                data-prefill="rent:rent_amount, deposit:deposit_amount"<?= $aria('property_id', $uid . '-property-hint') ?>>
            <option value="">— Select available property —</option>
            <?php foreach ($properties as $p): ?>
                <option value="<?= $p['id'] ?>"
                        data-rent="<?= $p['rent_amount'] ?>"
                        data-deposit="<?= $p['deposit_amount'] ?>"
                        <?= (int)($fd['property_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
                    <?= sanitize($p['title']) ?> · <?= sanitize($p['property_code']) ?>
                </option>
            <?php endforeach ?>
        </select>
        <?= $err('property_id') ?>
        <div class="form-hint" id="<?= $uid ?>-property-hint">Picking a property fills in its rent and deposit.</div>
    </div>
</div>

<h4 class="form-section">Term</h4>

<div class="form-grid--3">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-start">Start date <span class="req" aria-hidden="true">*</span></label>
        <input type="date" class="form-control<?= $bad('start_date') ?>" id="<?= $uid ?>-start" name="start_date"
               value="<?= sanitize($fd['start_date'] ?? date('Y-m-d')) ?>" required<?= $aria('start_date') ?>>
        <?= $err('start_date') ?>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-end">End date <span class="req" aria-hidden="true">*</span></label>
        <input type="date" class="form-control<?= $bad('end_date') ?>" id="<?= $uid ?>-end" name="end_date"
               value="<?= sanitize($fd['end_date'] ?? date('Y-m-d', strtotime('+1 year'))) ?>" required<?= $aria('end_date') ?>>
        <?= $err('end_date') ?>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-movein">Move-in date</label>
        <input type="date" class="form-control<?= $bad('move_in_date') ?>" id="<?= $uid ?>-movein" name="move_in_date"
               value="<?= sanitize($fd['move_in_date'] ?? date('Y-m-d')) ?>"<?= $aria('move_in_date', $uid . '-movein-hint') ?>>
        <?= $err('move_in_date') ?>
        <div class="form-hint" id="<?= $uid ?>-movein-hint">Defaults to the start date.</div>
    </div>
</div>

<h4 class="form-section">Money</h4>

<div class="form-grid--3">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-rent">Rent <span class="req" aria-hidden="true">*</span></label>
        <input type="number" step="0.01" min="0" class="form-control<?= $bad('rent_amount') ?>"
               id="<?= $uid ?>-rent" name="rent_amount"
               value="<?= sanitize((string)($fd['rent_amount'] ?? '')) ?>" required<?= $aria('rent_amount', $uid . '-rent-hint') ?>>
        <?= $err('rent_amount') ?>
        <div class="form-hint" id="<?= $uid ?>-rent-hint">Charged once per period below.</div>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-deposit">Security deposit</label>
        <input type="number" step="0.01" min="0" class="form-control<?= $bad('deposit_amount') ?>"
               id="<?= $uid ?>-deposit" name="deposit_amount"
               value="<?= sanitize((string)($fd['deposit_amount'] ?? '0')) ?>"<?= $aria('deposit_amount') ?>>
        <?= $err('deposit_amount') ?>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-latefee">Late fee (%)</label>
        <input type="number" step="0.01" min="0" class="form-control<?= $bad('late_fee_rate') ?>"
               id="<?= $uid ?>-latefee" name="late_fee_rate"
               value="<?= sanitize((string)($fd['late_fee_rate'] ?? lateFeeRate())) ?>"<?= $aria('late_fee_rate') ?>>
        <?= $err('late_fee_rate') ?>
    </div>
</div>

<div class="form-grid--2">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-schedule">Rent falls due</label>
        <select class="form-control<?= $bad('payment_schedule') ?>" id="<?= $uid ?>-schedule" name="payment_schedule"
                <?= $aria('payment_schedule', $uid . '-schedule-hint') ?>>
            <?php foreach ($schedules as $value => $label): ?>
                <option value="<?= sanitize((string) $value) ?>"
                    <?= ($fd['payment_schedule'] ?? 'monthly') === $value ? 'selected' : '' ?>><?= sanitize($label) ?></option>
            <?php endforeach ?>
        </select>
        <?= $err('payment_schedule') ?>
        <div class="form-hint" id="<?= $uid ?>-schedule-hint">
            The full schedule of due dates is written when the lease is saved.
        </div>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-contract">Signed contract</label>
        <input type="file" class="form-control<?= $bad('contract_file') ?>" id="<?= $uid ?>-contract"
               name="contract_file" accept=".pdf,.doc,.docx,image/*"<?= $aria('contract_file', $uid . '-contract-hint') ?>>
        <?= $err('contract_file') ?>
        <div class="form-hint" id="<?= $uid ?>-contract-hint">PDF, Word or a photograph. Optional — it can be attached later.</div>
    </div>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-terms">Terms &amp; conditions</label>
    <textarea class="form-control<?= $bad('terms') ?>" id="<?= $uid ?>-terms" name="terms" rows="4"
              placeholder="House rules, payment terms, restrictions…"<?= $aria('terms') ?>><?= sanitize($fd['terms'] ?? '') ?></textarea>
    <?= $err('terms') ?>
</div>
