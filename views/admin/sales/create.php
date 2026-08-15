<?php
/**
 * Sales — Create
 */
?>
<div class="card" style="max-width:760px;margin:0 auto">
    <div class="card__header"><h3 class="card__title">Record Property Sale</h3></div>
    <div class="card__body">
        <form method="post" data-validate>
            <?= csrfField() ?>
            <div class="form-grid--2">
                <div class="form-group">
                    <label class="form-label">Property *</label>
                    <select name="property_id" id="propertySelect" class="form-control" required>
                        <option value="">— Select —</option>
                        <?php foreach ($properties as $p): ?>
                            <option value="<?= $p['id'] ?>" data-price="<?= $p['price'] ?>"><?= sanitize($p['title']) ?> · <?= sanitize($p['property_code']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Buyer *</label>
                    <select name="customer_id" class="form-control" required>
                        <option value="">— Select —</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= sanitize($c['full_name']) ?> · <?= sanitize($c['phone']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
            </div>

            <div class="form-grid--3">
                <div class="form-group">
                    <label class="form-label">Sale Amount *</label>
                    <input type="number" step="0.01" name="sale_amount" id="saleAmount" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Tax</label>
                    <input type="number" step="0.01" name="tax_amount" id="taxAmount" class="form-control" value="0">
                    <p class="form-hint">Default <?= taxRate() ?>% of the sale amount (Settings → Financial).</p>
                </div>
                <div class="form-group">
                    <label class="form-label">Commission</label>
                    <input type="number" step="0.01" name="commission_amount" id="commissionAmount" class="form-control" value="0">
                    <p class="form-hint">Default <?= commissionRate() ?>% of the sale amount (Settings → Financial).</p>
                </div>
            </div>

            <div class="form-grid--3">
                <div class="form-group">
                    <label class="form-label">Sale Date</label>
                    <input type="date" name="sale_date" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group full-span" style="display:flex;align-items:flex-end">
                    <p class="form-hint" style="margin:0">
                        Buyer total <strong id="saleTotal"><?= formatCurrency(0) ?></strong>
                        <span class="text-subtle">(sale amount + tax)</span>
                    </p>
                </div>
            </div>

            <div class="form-grid--3">
                <div class="form-group">
                    <label class="form-label">Payment Type</label>
                    <select name="payment_type" class="form-control">
                        <option value="full">Full Payment</option>
                        <option value="installment">Installments</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control">
                        <option value="pending">Pending</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Agent</label>
                    <select name="agent_id" class="form-control">
                        <option value="">— None —</option>
                        <?php foreach ($agents as $a): ?>
                            <option value="<?= $a['id'] ?>"><?= sanitize($a['full_name']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="3"></textarea>
            </div>

            <div class="form-actions">
                <a href="<?= APP_URL ?>/index.php?page=sales" class="btn btn--outline">Cancel</a>
                <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg"></i> Save Sale</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Commission follows the configured default rate until an agent overrides it;
    // once they type their own figure we stop recalculating underneath them.
    (function () {
        const commissionPct = <?= json_encode(commissionRate()) ?>;
        const taxPct = <?= json_encode(taxRate()) ?>;
        const symbol = <?= json_encode(currencySymbol()) ?>;

        const amount = document.getElementById('saleAmount');
        const commission = document.getElementById('commissionAmount');
        const tax = document.getElementById('taxAmount');
        const total = document.getElementById('saleTotal');
        const touched = new Set();

        const money = (n) => symbol + n.toLocaleString(undefined, {
            minimumFractionDigits: 2, maximumFractionDigits: 2,
        });

        const recalc = () => {
            const value = parseFloat(amount.value) || 0;
            if (!touched.has(commission)) commission.value = (value * commissionPct / 100).toFixed(2);
            if (!touched.has(tax)) tax.value = (value * taxPct / 100).toFixed(2);
            total.textContent = money(value + (parseFloat(tax.value) || 0));
        };

        // Once an agent types their own figure we stop recalculating underneath them.
        [commission, tax].forEach(el => el.addEventListener('input', () => {
            touched.add(el);
            recalc();
        }));
        amount.addEventListener('input', recalc);

        document.getElementById('propertySelect').addEventListener('change', function () {
            const price = this.options[this.selectedIndex].dataset.price;
            if (price) amount.value = price;
            recalc();
        });
    })();
</script>
