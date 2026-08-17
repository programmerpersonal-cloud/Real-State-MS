<?php
/**
 * Payment form fields — shared by the full "Record Payment" page and the
 * quick-add popup, so the two can never drift apart.
 *
 * Expects:  $leases
 * Optional: $preset  payment schedule row, when recording against a due date
 *           $fd      entry kept back after a rejected submit
 *           $uid     id prefix for this instance's fields
 */
$uid    = $uid ?? 'pay';
$fd     = $fd ?? [];
$preset = $preset ?? null;
?>
<?php if ($preset): ?>
    <div class="alert alert--info">
        <i class="bi bi-info-circle-fill"></i>
        Recording payment for schedule <strong><?= formatDate($preset['due_date']) ?></strong>
        on lease <strong><?= sanitize($preset['lease_code']) ?></strong>.
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
            <select class="form-control" id="<?= $uid ?>-lease" name="reference_id"
                    data-prefill="customer:customer_id, property:property_id, amount:amount">
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
            <div class="form-hint">Picking a lease fills in the customer, property and rent amount.</div>
        </div>
        <div class="form-group">
            <label class="form-label" for="<?= $uid ?>-type">Payment Type *</label>
            <select class="form-control" id="<?= $uid ?>-type" name="payment_type">
                <?php foreach (['rent','sale','deposit','refund','late_fee','other'] as $t): ?>
                    <option value="<?= $t ?>" <?= ($fd['payment_type'] ?? '') === $t ? 'selected' : '' ?>>
                        <?= ucfirst(str_replace('_', ' ', $t)) ?>
                    </option>
                <?php endforeach ?>
            </select>
        </div>
    </div>

    <input type="hidden" name="reference_type" value="lease">
    <input type="hidden" name="customer_id" value="<?= (int)($fd['customer_id'] ?? 0) ?: '' ?>">
    <input type="hidden" name="property_id" value="<?= (int)($fd['property_id'] ?? 0) ?: '' ?>">
<?php endif ?>

<div class="form-grid--2">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-amount">Amount *</label>
        <input type="number" step="0.01" min="0" class="form-control" id="<?= $uid ?>-amount" name="amount"
               value="<?= sanitize((string)($fd['amount'] ?? ($preset['amount'] ?? ''))) ?>" required>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-method">Payment Method</label>
        <select class="form-control" id="<?= $uid ?>-method" name="payment_method">
            <?php foreach (['cash','bank_transfer','check','card','other'] as $m): ?>
                <option value="<?= $m ?>" <?= ($fd['payment_method'] ?? '') === $m ? 'selected' : '' ?>>
                    <?= ucfirst(str_replace('_', ' ', $m)) ?>
                </option>
            <?php endforeach ?>
        </select>
    </div>
</div>

<div class="form-grid--2">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-date">Payment Date</label>
        <input type="date" class="form-control" id="<?= $uid ?>-date" name="payment_date"
               value="<?= sanitize($fd['payment_date'] ?? date('Y-m-d')) ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-status">Status</label>
        <select class="form-control" id="<?= $uid ?>-status" name="status">
            <?php foreach (['paid' => 'Paid', 'partial' => 'Partial', 'pending' => 'Pending'] as $value => $label): ?>
                <option value="<?= $value ?>" <?= ($fd['status'] ?? 'paid') === $value ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach ?>
        </select>
    </div>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-notes">Notes</label>
    <textarea class="form-control" id="<?= $uid ?>-notes" name="notes" rows="2"><?= sanitize($fd['notes'] ?? '') ?></textarea>
</div>
