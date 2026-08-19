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
 * Optional: $fd    entry kept back after a rejected submit
 *           $errs  field-keyed errors from the same rejection
 *           $uid   id prefix for this instance's fields
 */
$uid  = $uid ?? 'sal';
$fd   = $fd ?? [];
$errs = $errs ?? ($formErrors ?? []);

$err  = static fn(string $f): string => uiFieldError($errs, $f);
$bad  = static fn(string $f): string => uiInvalidClass($errs, $f);
$aria = static fn(string $f, string $hint = ''): string => uiFieldAria($errs, $f, $hint);

/* Supplied by the controller so the <option>s and the validator read one
   list; defaulted so this partial still works wherever else it is required. */
$paymentTypes = $paymentTypes ?? ['full' => 'Paid in full', 'installment' => 'Instalments'];
/* Only the two states a sale is *recorded* in. Cancelling is an action taken
   on an existing deal, not a way to open one. */
$openable = ['pending' => 'Pending', 'completed' => 'Completed'];
?>
<div data-sale-calc
     data-commission-rate="<?= json_encode(commissionRate()) ?>"
     data-tax-rate="<?= json_encode(taxRate()) ?>"
     data-currency="<?= sanitize(currencySymbol()) ?>">

    <h3 class="form-section">Parties</h3>

    <div class="form-grid--2">
        <div class="form-group">
            <label class="form-label" for="<?= $uid ?>-property">Property <span class="req" aria-hidden="true">*</span></label>
            <select name="property_id" id="<?= $uid ?>-property" class="form-control<?= $bad('property_id') ?>" required
                    data-prefill="price:sale_amount"<?= $aria('property_id', $uid . '-property-hint') ?>>
                <option value="">— Select —</option>
                <?php foreach ($properties as $p): ?>
                    <option value="<?= $p['id'] ?>" data-price="<?= $p['price'] ?>"
                            <?= (int)($fd['property_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
                        <?= sanitize($p['title']) ?> · <?= sanitize($p['property_code']) ?>
                    </option>
                <?php endforeach ?>
            </select>
            <?= $err('property_id') ?>
            <div class="form-hint" id="<?= $uid ?>-property-hint">Picking a property fills in its asking price.</div>
        </div>
        <div class="form-group">
            <label class="form-label" for="<?= $uid ?>-buyer">Buyer <span class="req" aria-hidden="true">*</span></label>
            <select name="customer_id" id="<?= $uid ?>-buyer" class="form-control<?= $bad('customer_id') ?>" required
                    <?= $aria('customer_id') ?>>
                <option value="">— Select —</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= (int)($fd['customer_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= sanitize($c['full_name']) ?> · <?= sanitize($c['phone']) ?>
                    </option>
                <?php endforeach ?>
            </select>
            <?= $err('customer_id') ?>
        </div>
    </div>

    <h3 class="form-section">Money</h3>

    <div class="form-grid--3">
        <div class="form-group">
            <label class="form-label" for="<?= $uid ?>-amount">Sale amount <span class="req" aria-hidden="true">*</span></label>
            <input type="number" step="0.01" min="0" name="sale_amount" id="<?= $uid ?>-amount"
                   class="form-control<?= $bad('sale_amount') ?>" value="<?= sanitize((string)($fd['sale_amount'] ?? '')) ?>"
                   required data-sale-amount<?= $aria('sale_amount') ?>>
            <?= $err('sale_amount') ?>
        </div>
        <div class="form-group">
            <label class="form-label" for="<?= $uid ?>-tax">Tax</label>
            <input type="number" step="0.01" min="0" name="tax_amount" id="<?= $uid ?>-tax"
                   class="form-control<?= $bad('tax_amount') ?>" value="<?= sanitize((string)($fd['tax_amount'] ?? '0')) ?>"
                   data-sale-tax<?= $aria('tax_amount', $uid . '-tax-hint') ?>>
            <?= $err('tax_amount') ?>
            <p class="form-hint" id="<?= $uid ?>-tax-hint">Default <?= taxRate() ?>% of the sale amount (Settings → Financial).</p>
        </div>
        <div class="form-group">
            <label class="form-label" for="<?= $uid ?>-commission">Commission</label>
            <input type="number" step="0.01" min="0" name="commission_amount" id="<?= $uid ?>-commission"
                   class="form-control<?= $bad('commission_amount') ?>" value="<?= sanitize((string)($fd['commission_amount'] ?? '0')) ?>"
                   data-sale-commission<?= $aria('commission_amount', $uid . '-comm-hint') ?>>
            <?= $err('commission_amount') ?>
            <p class="form-hint" id="<?= $uid ?>-comm-hint">Default <?= commissionRate() ?>% of the sale amount (Settings → Financial).</p>
        </div>
    </div>

    <div class="form-callout">
        <span class="form-callout__label">Buyer pays</span>
        <strong class="form-callout__value" data-sale-total><?= formatCurrency(0) ?></strong>
        <span class="form-callout__note">sale amount plus tax</span>
    </div>

    <h3 class="form-section">Terms</h3>

    <div class="form-grid--3">
        <div class="form-group">
            <label class="form-label" for="<?= $uid ?>-date">Sale date</label>
            <input type="date" name="sale_date" id="<?= $uid ?>-date" class="form-control<?= $bad('sale_date') ?>"
                   value="<?= sanitize($fd['sale_date'] ?? date('Y-m-d')) ?>"<?= $aria('sale_date') ?>>
            <?= $err('sale_date') ?>
        </div>
        <div class="form-group">
            <label class="form-label" for="<?= $uid ?>-paytype">Payment</label>
            <select name="payment_type" id="<?= $uid ?>-paytype" class="form-control<?= $bad('payment_type') ?>"
                    <?= $aria('payment_type') ?>>
                <?php foreach ($paymentTypes as $value => $label): ?>
                    <option value="<?= sanitize((string) $value) ?>"
                        <?= ($fd['payment_type'] ?? 'full') === $value ? 'selected' : '' ?>><?= sanitize($label) ?></option>
                <?php endforeach ?>
            </select>
            <?= $err('payment_type') ?>
        </div>
        <div class="form-group">
            <label class="form-label" for="<?= $uid ?>-status">Stage</label>
            <select name="status" id="<?= $uid ?>-status" class="form-control<?= $bad('status') ?>"
                    <?= $aria('status', $uid . '-status-hint') ?>>
                <?php foreach ($openable as $value => $label): ?>
                    <option value="<?= $value ?>" <?= ($fd['status'] ?? 'pending') === $value ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach ?>
            </select>
            <?= $err('status') ?>
            <div class="form-hint" id="<?= $uid ?>-status-hint">Completing a sale marks the property sold.</div>
        </div>
    </div>

    <div class="form-grid--2">
        <div class="form-group">
            <label class="form-label" for="<?= $uid ?>-agent">Agent</label>
            <select name="agent_id" id="<?= $uid ?>-agent" class="form-control<?= $bad('agent_id') ?>"<?= $aria('agent_id', $uid . '-agent-hint') ?>>
                <option value="">— None —</option>
                <?php foreach ($agents as $a): ?>
                    <option value="<?= $a['id'] ?>" <?= (int)($fd['agent_id'] ?? 0) === (int)$a['id'] ? 'selected' : '' ?>>
                        <?= sanitize($a['full_name']) ?>
                    </option>
                <?php endforeach ?>
            </select>
            <?= $err('agent_id') ?>
            <div class="form-hint" id="<?= $uid ?>-agent-hint">A commission record is only raised when an agent is named.</div>
        </div>
    </div>

    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-notes">Notes</label>
        <textarea name="notes" id="<?= $uid ?>-notes" class="form-control<?= $bad('notes') ?>" rows="3"
                  <?= $aria('notes') ?>><?= sanitize($fd['notes'] ?? '') ?></textarea>
        <?= $err('notes') ?>
    </div>
</div>
