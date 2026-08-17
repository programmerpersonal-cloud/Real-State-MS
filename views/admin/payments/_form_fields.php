<?php
/**
 * Payment form fields — shared by the full "Record Payment" page and the
 * quick-add popup, so the two can never drift apart.
 *
 * Expects:  $leases
 * Optional: $preset  payment schedule row, when recording against a due date
 *           $fd      entry kept back after a rejected submit
 *           $errs    field-keyed errors from the same rejection
 *           $uid     id prefix for this instance's fields
 */
$uid    = $uid ?? 'pay';
$fd     = $fd ?? [];
$errs   = $errs ?? ($formErrors ?? []);
$preset = $preset ?? null;

$err  = static fn(string $f): string => uiFieldError($errs, $f);
$bad  = static fn(string $f): string => uiInvalidClass($errs, $f);
$aria = static fn(string $f, string $hint = ''): string => uiFieldAria($errs, $f, $hint);

/* The option maps come from the controller so the form and the validator read
   one list. The defaults keep this partial usable from anywhere else that
   requires it without those vars. */
$types   = $types   ?? ['rent' => 'Rent', 'sale' => 'Sale', 'deposit' => 'Deposit',
                        'refund' => 'Refund', 'late_fee' => 'Late fee', 'other' => 'Other'];
$methods = $methods ?? ['cash' => 'Cash', 'bank_transfer' => 'Bank transfer',
                        'check' => 'Cheque', 'card' => 'Card', 'other' => 'Other'];
/* Only the states a person records by hand. "Overdue" is set by the nightly
   roll-over and "cancelled" by reversing a payment, so neither belongs here. */
$recordable = ['paid' => 'Paid in full', 'partial' => 'Part payment', 'pending' => 'Pending'];
?>
<?php if ($preset): ?>
    <div class="alert alert--info">
        <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
        Recording payment for the instalment due <strong><?= formatDate($preset['due_date']) ?></strong>
        on lease <strong><?= sanitize($preset['lease_code']) ?></strong>
        — <?= sanitize($preset['customer_name']) ?>, <?= sanitize($preset['property_title']) ?>.
    </div>
    <input type="hidden" name="schedule_id" value="<?= (int)$preset['id'] ?>">
    <input type="hidden" name="customer_id" value="<?= (int)$preset['customer_id'] ?>">
    <input type="hidden" name="property_id" value="<?= (int)$preset['property_id'] ?>">
    <input type="hidden" name="reference_id" value="<?= (int)$preset['lease_id'] ?>">
    <input type="hidden" name="reference_type" value="lease">
    <input type="hidden" name="payment_type" value="rent">
    <input type="hidden" name="due_date" value="<?= $preset['due_date'] ?>">
<?php else: ?>
    <div class="form-grid--2">
        <div class="form-group">
            <label class="form-label" for="<?= $uid ?>-lease">Lease</label>
            <select class="form-control<?= $bad('reference_id') ?>" id="<?= $uid ?>-lease" name="reference_id"
                    data-prefill="customer:customer_id, property:property_id, amount:amount"
                    <?= $aria('reference_id', $uid . '-lease-hint') ?>>
                <option value="0">— No lease (sale / one-off) —</option>
                <?php foreach ($leases as $l): ?>
                    <option value="<?= $l['id'] ?>"
                        data-customer="<?= $l['customer_id'] ?>"
                        data-property="<?= $l['property_id'] ?>"
                        data-amount="<?= $l['rent_amount'] ?>"
                        <?= (int)($fd['reference_id'] ?? 0) === (int)$l['id'] ? 'selected' : '' ?>>
                        <?= sanitize($l['lease_code']) ?> · <?= sanitize($l['customer_name']) ?> · <?= sanitize($l['property_title']) ?>
                    </option>
                <?php endforeach ?>
            </select>
            <?= $err('reference_id') ?>
            <div class="form-hint" id="<?= $uid ?>-lease-hint">
                Picking a lease fills in the customer, property and rent amount.
            </div>
        </div>
        <div class="form-group">
            <label class="form-label" for="<?= $uid ?>-type">What for <span class="req" aria-hidden="true">*</span></label>
            <select class="form-control<?= $bad('payment_type') ?>" id="<?= $uid ?>-type" name="payment_type"
                    <?= $aria('payment_type') ?>>
                <?php foreach ($types as $value => $label): ?>
                    <option value="<?= sanitize((string) $value) ?>"
                        <?= ($fd['payment_type'] ?? 'rent') === $value ? 'selected' : '' ?>><?= sanitize($label) ?></option>
                <?php endforeach ?>
            </select>
            <?= $err('payment_type') ?>
        </div>
    </div>

    <input type="hidden" name="reference_type" value="lease">
    <input type="hidden" name="customer_id" value="<?= (int)($fd['customer_id'] ?? 0) ?: '' ?>">
    <input type="hidden" name="property_id" value="<?= (int)($fd['property_id'] ?? 0) ?: '' ?>">
<?php endif ?>

<div class="form-grid--2">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-amount">Amount <span class="req" aria-hidden="true">*</span></label>
        <input type="number" step="0.01" min="0" class="form-control<?= $bad('amount') ?>"
               id="<?= $uid ?>-amount" name="amount"
               value="<?= sanitize((string)($fd['amount'] ?? ($preset['amount'] ?? ''))) ?>"
               required<?= $aria('amount') ?>>
        <?= $err('amount') ?>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-method">Taken by</label>
        <select class="form-control<?= $bad('payment_method') ?>" id="<?= $uid ?>-method" name="payment_method"
                <?= $aria('payment_method') ?>>
            <?php foreach ($methods as $value => $label): ?>
                <option value="<?= sanitize((string) $value) ?>"
                    <?= ($fd['payment_method'] ?? 'cash') === $value ? 'selected' : '' ?>><?= sanitize($label) ?></option>
            <?php endforeach ?>
        </select>
        <?= $err('payment_method') ?>
    </div>
</div>

<div class="form-grid--2">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-date">Received on</label>
        <input type="date" class="form-control<?= $bad('payment_date') ?>" id="<?= $uid ?>-date" name="payment_date"
               value="<?= sanitize($fd['payment_date'] ?? date('Y-m-d')) ?>"<?= $aria('payment_date') ?>>
        <?= $err('payment_date') ?>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-status">State</label>
        <select class="form-control<?= $bad('status') ?>" id="<?= $uid ?>-status" name="status"
                <?= $aria('status', $uid . '-status-hint') ?>>
            <?php foreach ($recordable as $value => $label): ?>
                <option value="<?= $value ?>" <?= ($fd['status'] ?? 'paid') === $value ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach ?>
        </select>
        <?= $err('status') ?>
        <div class="form-hint" id="<?= $uid ?>-status-hint">Overdue is set automatically once a due date passes.</div>
    </div>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-notes">Notes</label>
    <textarea class="form-control<?= $bad('notes') ?>" id="<?= $uid ?>-notes" name="notes" rows="2"
              placeholder="Reference number, who handed it over, anything worth remembering…"
              <?= $aria('notes') ?>><?= sanitize($fd['notes'] ?? '') ?></textarea>
    <?= $err('notes') ?>
</div>
