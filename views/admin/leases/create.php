<?php
/**
 * Lease — Create
 */
?>
<div class="card">
    <div class="card__header"><h3 class="card__title">New Lease Agreement</h3></div>
    <div class="card__body">
        <form method="post" enctype="multipart/form-data" data-validate>
            <?= csrfField() ?>

            <div class="section-title">Parties</div>
            <div class="form-grid--2">
                <div class="form-group">
                    <label class="form-label">Customer (Tenant) *</label>
                    <select class="form-control" name="customer_id" required>
                        <option value="">— Select customer —</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= sanitize($c['full_name']) ?> · <?= sanitize($c['phone']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Property *</label>
                    <select class="form-control" name="property_id" id="propertySelect" required>
                        <option value="">— Select available property —</option>
                        <?php foreach ($properties as $p): ?>
                            <option value="<?= $p['id'] ?>"
                                    data-rent="<?= $p['rent_amount'] ?>"
                                    data-deposit="<?= $p['deposit_amount'] ?>">
                                <?= sanitize($p['title']) ?> · <?= sanitize($p['property_code']) ?>
                            </option>
                        <?php endforeach ?>
                    </select>
                </div>
            </div>

            <div class="section-title">Lease Terms</div>
            <div class="form-grid--3">
                <div class="form-group">
                    <label class="form-label">Start Date *</label>
                    <input type="date" class="form-control" name="start_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">End Date *</label>
                    <input type="date" class="form-control" name="end_date" value="<?= date('Y-m-d', strtotime('+1 year')) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Move-in Date</label>
                    <input type="date" class="form-control" name="move_in_date" value="<?= date('Y-m-d') ?>">
                </div>
            </div>

            <div class="form-grid--3">
                <div class="form-group">
                    <label class="form-label">Monthly Rent *</label>
                    <input type="number" step="0.01" class="form-control" name="rent_amount" id="rentInput" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Security Deposit</label>
                    <input type="number" step="0.01" class="form-control" name="deposit_amount" id="depositInput" value="0">
                </div>
                <div class="form-group">
                    <label class="form-label">Late Fee (%)</label>
                    <input type="number" step="0.01" class="form-control" name="late_fee_rate" value="<?= lateFeeRate() ?>">
                </div>
            </div>

            <div class="form-grid--2">
                <div class="form-group">
                    <label class="form-label">Payment Schedule</label>
                    <select class="form-control" name="payment_schedule">
                        <option value="monthly">Monthly</option>
                        <option value="quarterly">Quarterly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Contract File (PDF/DOC)</label>
                    <input type="file" class="form-control" name="contract_file" accept=".pdf,.doc,.docx,image/*">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Terms & Conditions</label>
                <textarea class="form-control" name="terms" rows="4" placeholder="House rules, payment terms, restrictions…"></textarea>
            </div>

            <div class="form-actions">
                <a href="<?= APP_URL ?>/index.php?page=leases" class="btn btn--outline">Cancel</a>
                <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg"></i> Create Lease</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.getElementById('propertySelect').addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        const rent = opt.dataset.rent;
        const deposit = opt.dataset.deposit;
        if (rent) document.getElementById('rentInput').value = rent;
        if (deposit) document.getElementById('depositInput').value = deposit;
    });
</script>
