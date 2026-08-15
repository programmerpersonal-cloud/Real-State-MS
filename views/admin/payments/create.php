<?php
/**
 * Payments — Create
 */
?>
<div class="card" style="max-width:720px;margin:0 auto">
    <div class="card__header"><h3 class="card__title">Record Payment</h3></div>
    <div class="card__body">
        <form method="post" data-validate>
            <?= csrfField() ?>

            <?php if ($preset): ?>
                <div class="alert alert--info">
                    <i class="bi bi-info-circle-fill"></i>
                    Recording payment for schedule <strong><?= formatDate($preset['due_date']) ?></strong> on lease <strong><?= sanitize($preset['lease_code']) ?></strong>.
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
                    <label class="form-label">Lease</label>
                    <select class="form-control" id="leaseSelect" name="reference_id">
                        <option value="0">— No lease (sale / one-off) —</option>
                        <?php foreach ($leases as $l): ?>
                        <option value="<?= $l['id'] ?>"
                            data-customer="<?= $l['customer_id'] ?>"
                            data-property="<?= $l['property_id'] ?>"
                            data-amount="<?= $l['rent_amount'] ?>"
                            data-customer-name="<?= sanitize($l['customer_name']) ?>"
                            data-property-name="<?= sanitize($l['property_title']) ?>">
                            <?= sanitize($l['lease_code']) ?> · <?= sanitize($l['customer_name']) ?> · <?= sanitize($l['property_title']) ?>
                        </option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Payment Type *</label>
                    <select class="form-control" name="payment_type">
                        <?php foreach (['rent','sale','deposit','refund','late_fee','other'] as $t): ?>
                            <option value="<?= $t ?>"><?= ucfirst(str_replace('_',' ',$t)) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
            </div>

            <input type="hidden" name="reference_type" value="lease">
            <input type="hidden" name="customer_id" id="customerId">
            <input type="hidden" name="property_id" id="propertyId">
            <?php endif ?>

            <div class="form-grid--2">
                <div class="form-group">
                    <label class="form-label">Amount *</label>
                    <input type="number" step="0.01" class="form-control" name="amount" id="amountInput"
                           value="<?= $preset ? $preset['amount'] : '' ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Payment Method</label>
                    <select class="form-control" name="payment_method">
                        <?php foreach (['cash','bank_transfer','check','card','other'] as $m): ?>
                            <option value="<?= $m ?>"><?= ucfirst(str_replace('_',' ',$m)) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
            </div>

            <div class="form-grid--2">
                <div class="form-group">
                    <label class="form-label">Payment Date</label>
                    <input type="date" class="form-control" name="payment_date" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select class="form-control" name="status">
                        <option value="paid">Paid</option>
                        <option value="partial">Partial</option>
                        <option value="pending">Pending</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea class="form-control" name="notes" rows="2"></textarea>
            </div>

            <div class="form-actions">
                <a href="<?= APP_URL ?>/index.php?page=payments" class="btn btn--outline">Cancel</a>
                <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg"></i> Record Payment</button>
            </div>
        </form>
    </div>
</div>

<?php if (!$preset): ?>
<script>
    document.getElementById('leaseSelect').addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        if (opt.dataset.customer) {
            document.getElementById('customerId').value = opt.dataset.customer;
            document.getElementById('propertyId').value = opt.dataset.property;
            document.getElementById('amountInput').value = opt.dataset.amount;
        }
    });
</script>
<?php endif ?>
