<?php
/**
 * Lease form fields — shared by the full "New Lease" page and the
 * quick-add popup, so the two can never drift apart.
 *
 * Expects:  $properties, $customers
 * Optional: $fd   entry kept back after a rejected submit
 *           $uid  id prefix for this instance's fields
 */
$uid = $uid ?? 'lse';
$fd  = $fd ?? [];
?>
<h4 class="form-section">Parties</h4>

<div class="form-grid--2">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-customer">Customer (Tenant) *</label>
        <select class="form-control" id="<?= $uid ?>-customer" name="customer_id" required>
            <option value="">— Select customer —</option>
            <?php foreach ($customers as $c): ?>
                <option value="<?= $c['id'] ?>" <?= (int)($fd['customer_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                    <?= sanitize($c['full_name']) ?> · <?= sanitize($c['phone']) ?>
                </option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-property">Property *</label>
        <select class="form-control" id="<?= $uid ?>-property" name="property_id" required
                data-prefill="rent:rent_amount, deposit:deposit_amount">
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
        <div class="form-hint">Picking a property fills in its rent and deposit.</div>
    </div>
</div>

<h4 class="form-section">Lease terms</h4>

<div class="form-grid--3">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-start">Start Date *</label>
        <input type="date" class="form-control" id="<?= $uid ?>-start" name="start_date"
               value="<?= sanitize($fd['start_date'] ?? date('Y-m-d')) ?>" required>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-end">End Date *</label>
        <input type="date" class="form-control" id="<?= $uid ?>-end" name="end_date"
               value="<?= sanitize($fd['end_date'] ?? date('Y-m-d', strtotime('+1 year'))) ?>" required>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-movein">Move-in Date</label>
        <input type="date" class="form-control" id="<?= $uid ?>-movein" name="move_in_date"
               value="<?= sanitize($fd['move_in_date'] ?? date('Y-m-d')) ?>">
    </div>
</div>

<div class="form-grid--3">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-rent">Monthly Rent *</label>
        <input type="number" step="0.01" min="0" class="form-control" id="<?= $uid ?>-rent" name="rent_amount"
               value="<?= sanitize((string)($fd['rent_amount'] ?? '')) ?>" required>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-deposit">Security Deposit</label>
        <input type="number" step="0.01" min="0" class="form-control" id="<?= $uid ?>-deposit" name="deposit_amount"
               value="<?= sanitize((string)($fd['deposit_amount'] ?? '0')) ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-latefee">Late Fee (%)</label>
        <input type="number" step="0.01" min="0" class="form-control" id="<?= $uid ?>-latefee" name="late_fee_rate"
               value="<?= sanitize((string)($fd['late_fee_rate'] ?? lateFeeRate())) ?>">
    </div>
</div>

<div class="form-grid--2">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-schedule">Payment Schedule</label>
        <select class="form-control" id="<?= $uid ?>-schedule" name="payment_schedule">
            <?php foreach (['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly'] as $value => $label): ?>
                <option value="<?= $value ?>" <?= ($fd['payment_schedule'] ?? 'monthly') === $value ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-contract">Contract File (PDF/DOC)</label>
        <input type="file" class="form-control" id="<?= $uid ?>-contract" name="contract_file" accept=".pdf,.doc,.docx,image/*">
    </div>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-terms">Terms &amp; Conditions</label>
    <textarea class="form-control" id="<?= $uid ?>-terms" name="terms" rows="4"
              placeholder="House rules, payment terms, restrictions…"><?= sanitize($fd['terms'] ?? '') ?></textarea>
</div>
