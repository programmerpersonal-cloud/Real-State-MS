<?php
/**
 * Sale form fields — shared by the full "Record Sale" page and the
 * quick-add popup, so the two can never drift apart.
 *
 * The tax and commission defaults live on the wrapper as data attributes;
 * main.js recalculates them from the sale amount until an agent types their
 * own figure, and stops overriding it from then on.
 *
 * Expects:  $properties, $customers, $agents
 * Optional: $fd   entry kept back after a rejected submit
 *           $uid  id prefix for this instance's fields
 */
$uid = $uid ?? 'sal';
$fd  = $fd ?? [];
?>
<div data-sale-calc
     data-commission-rate="<?= json_encode(commissionRate()) ?>"
     data-tax-rate="<?= json_encode(taxRate()) ?>"
     data-currency="<?= sanitize(currencySymbol()) ?>">

    <div class="form-grid--2">
        <div class="form-group">
            <label class="form-label" for="<?= $uid ?>-property">Property *</label>
            <select name="property_id" id="<?= $uid ?>-property" class="form-control" required
                    data-prefill="price:sale_amount">
                <option value="">— Select —</option>
                <?php foreach ($properties as $p): ?>
                    <option value="<?= $p['id'] ?>" data-price="<?= $p['price'] ?>"
                            <?= (int)($fd['property_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
                        <?= sanitize($p['title']) ?> · <?= sanitize($p['property_code']) ?>
                    </option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" for="<?= $uid ?>-buyer">Buyer *</label>
            <select name="customer_id" id="<?= $uid ?>-buyer" class="form-control" required>
                <option value="">— Select —</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= (int)($fd['customer_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= sanitize($c['full_name']) ?> · <?= sanitize($c['phone']) ?>
                    </option>
                <?php endforeach ?>
            </select>
        </div>
    </div>

    <div class="form-grid--3">
        <div class="form-group">
            <label class="form-label" for="<?= $uid ?>-amount">Sale Amount *</label>
            <input type="number" step="0.01" min="0" name="sale_amount" id="<?= $uid ?>-amount"
                   class="form-control" value="<?= sanitize((string)($fd['sale_amount'] ?? '')) ?>" required data-sale-amount>
        </div>
        <div class="form-group">
            <label class="form-label" for="<?= $uid ?>-tax">Tax</label>
            <input type="number" step="0.01" min="0" name="tax_amount" id="<?= $uid ?>-tax"
                   class="form-control" value="<?= sanitize((string)($fd['tax_amount'] ?? '0')) ?>" data-sale-tax>
            <p class="form-hint">Default <?= taxRate() ?>% of the sale amount (Settings → Financial).</p>
        </div>
        <div class="form-group">
            <label class="form-label" for="<?= $uid ?>-commission">Commission</label>
            <input type="number" step="0.01" min="0" name="commission_amount" id="<?= $uid ?>-commission"
                   class="form-control" value="<?= sanitize((string)($fd['commission_amount'] ?? '0')) ?>" data-sale-commission>
            <p class="form-hint">Default <?= commissionRate() ?>% of the sale amount (Settings → Financial).</p>
        </div>
    </div>

    <div class="form-grid--3">
        <div class="form-group">
            <label class="form-label" for="<?= $uid ?>-date">Sale Date</label>
            <input type="date" name="sale_date" id="<?= $uid ?>-date" class="form-control"
                   value="<?= sanitize($fd['sale_date'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="form-group full-span" style="display:flex;align-items:flex-end">
            <p class="form-hint" style="margin:0">
                Buyer total <strong data-sale-total><?= formatCurrency(0) ?></strong>
                <span class="text-subtle">(sale amount + tax)</span>
            </p>
        </div>
    </div>

    <div class="form-grid--3">
        <div class="form-group">
            <label class="form-label" for="<?= $uid ?>-paytype">Payment Type</label>
            <select name="payment_type" id="<?= $uid ?>-paytype" class="form-control">
                <?php foreach (['full' => 'Full Payment', 'installment' => 'Installments'] as $value => $label): ?>
                    <option value="<?= $value ?>" <?= ($fd['payment_type'] ?? 'full') === $value ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" for="<?= $uid ?>-status">Status</label>
            <select name="status" id="<?= $uid ?>-status" class="form-control">
                <?php foreach (['pending' => 'Pending', 'completed' => 'Completed'] as $value => $label): ?>
                    <option value="<?= $value ?>" <?= ($fd['status'] ?? 'pending') === $value ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" for="<?= $uid ?>-agent">Agent</label>
            <select name="agent_id" id="<?= $uid ?>-agent" class="form-control">
                <option value="">— None —</option>
                <?php foreach ($agents as $a): ?>
                    <option value="<?= $a['id'] ?>" <?= (int)($fd['agent_id'] ?? 0) === (int)$a['id'] ? 'selected' : '' ?>>
                        <?= sanitize($a['full_name']) ?>
                    </option>
                <?php endforeach ?>
            </select>
        </div>
    </div>

    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-notes">Notes</label>
        <textarea name="notes" id="<?= $uid ?>-notes" class="form-control" rows="3"><?= sanitize($fd['notes'] ?? '') ?></textarea>
    </div>
</div>
