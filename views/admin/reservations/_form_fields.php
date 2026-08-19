<?php
/**
 * Reservation form fields — shared by the full "New Reservation" page and
 * the quick-add popup, so the two can never drift apart.
 *
 * Expects:  $properties, $customers
 * Optional: $fd    form data kept back after a rejected submit
 *           $errs  field-keyed errors from the same rejection
 *           $uid   id prefix for this instance's fields
 *
 * The id prefix matters more than it looks: both copies of this form can be
 * in the document at once — the popup and the page it links to — and two
 * controls sharing an id would give every label an ambiguous target.
 */
$uid  = $uid ?? 'res';
$fd   = $fd ?? [];
$errs = $errs ?? ($formErrors ?? []);

$err  = static fn(string $f): string => uiFieldError($errs, $f);
$bad  = static fn(string $f): string => uiInvalidClass($errs, $f);
$aria = static fn(string $f, string $hint = ''): string => uiFieldAria($errs, $f, $hint);
?>
<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-property">Property <span class="req" aria-hidden="true">*</span></label>
    <select name="property_id" id="<?= $uid ?>-property" class="form-control<?= $bad('property_id') ?>"
            required<?= $aria('property_id', $uid . '-property-hint') ?>>
        <option value="">— Select an available property —</option>
        <?php foreach ($properties as $p): ?>
            <option value="<?= $p['id'] ?>" <?= (int)($fd['property_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
                <?= sanitize($p['title']) ?> · <?= sanitize($p['property_code']) ?>
            </option>
        <?php endforeach ?>
    </select>
    <?= $err('property_id') ?>
    <div class="form-hint" id="<?= $uid ?>-property-hint">
        <?php if (empty($properties)): ?>
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            No property is available to reserve right now — every listing is already let, sold or held.
        <?php else: ?>
            Only properties currently marked available can be held.
        <?php endif ?>
    </div>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-customer">Customer <span class="req" aria-hidden="true">*</span></label>
    <select name="customer_id" id="<?= $uid ?>-customer" class="form-control<?= $bad('customer_id') ?>"
            required<?= $aria('customer_id') ?>>
        <option value="">— Select customer —</option>
        <?php foreach ($customers as $c): ?>
            <option value="<?= $c['id'] ?>" <?= (int)($fd['customer_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                <?= sanitize($c['full_name']) ?>
            </option>
        <?php endforeach ?>
    </select>
    <?= $err('customer_id') ?>
</div>

<div class="form-grid--2">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-from">Reservation date</label>
        <input type="date" id="<?= $uid ?>-from" class="form-control<?= $bad('reservation_date') ?>"
               name="reservation_date" value="<?= sanitize($fd['reservation_date'] ?? date('Y-m-d')) ?>"
               <?= $aria('reservation_date') ?>>
        <?= $err('reservation_date') ?>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-until">Expires on</label>
        <input type="date" id="<?= $uid ?>-until" class="form-control<?= $bad('expiry_date') ?>"
               name="expiry_date" value="<?= sanitize($fd['expiry_date'] ?? reservationExpiryDate()) ?>"
               <?= $aria('expiry_date', $uid . '-until-hint') ?>>
        <?= $err('expiry_date') ?>
        <div class="form-hint" id="<?= $uid ?>-until-hint">
            Holds run <?= reservationExpiryDays() ?> days by default, then release the property automatically.
        </div>
    </div>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-deposit">Deposit</label>
    <input type="number" step="0.01" min="0" id="<?= $uid ?>-deposit"
           class="form-control<?= $bad('deposit_amount') ?>" name="deposit_amount"
           value="<?= sanitize((string)($fd['deposit_amount'] ?? '0')) ?>"
           <?= $aria('deposit_amount', $uid . '-deposit-hint') ?>>
    <?= $err('deposit_amount') ?>
    <div class="form-hint" id="<?= $uid ?>-deposit-hint">Leave at zero if nothing has been taken yet.</div>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-notes">Notes</label>
    <textarea name="notes" id="<?= $uid ?>-notes" class="form-control<?= $bad('notes') ?>" rows="3"
              <?= $aria('notes') ?>><?= sanitize($fd['notes'] ?? '') ?></textarea>
    <?= $err('notes') ?>
</div>
