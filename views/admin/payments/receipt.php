<?php
/**
 * Payment — Receipt
 *
 * The one page in this product that exists to leave the screen. A print block
 * already hid the shell; step 4 moves this page's twenty-one inline styles
 * into the shared receipt component and extends that block to the things it
 * did not cover — page margins, colour fidelity for the totals rule, and
 * keeping the signature panel off a page break.
 *
 * Expects: $payment, optional $sale (tax breakdown for a sale payment)
 */
$p = $payment;

/* The lines of the receipt, built once so the markup below stays a table and
   nothing else. Each entry is [label, value, modifier]; 'total' is the line
   the eye should land on. */
$lines = [
    ['Payment type', uiLabel((string) $p['payment_type']), ''],
];
if ($p['property_title']) {
    $lines[] = ['Property', $p['property_title'] . ' (' . $p['property_code'] . ')', ''];
}
$lines[] = ['Method', uiLabel((string) $p['payment_method']), ''];

if (!empty($sale) && (float) $sale['tax_amount'] > 0) {
    $rate = (float) $sale['tax_rate'] > 0
        ? ' (' . rtrim(rtrim(number_format((float) $sale['tax_rate'], 2), '0'), '.') . '%)'
        : '';
    $lines[] = ['Sale subtotal', formatCurrency((float) $sale['sale_amount']), ''];
    $lines[] = ['Tax' . $rate, formatCurrency((float) $sale['tax_amount']), ''];
    $lines[] = ['Sale total', formatCurrency((float) $sale['sale_amount'] + (float) $sale['tax_amount']), 'sub'];
}
if ((float) $p['penalty_amount'] > 0) {
    $lines[] = ['Late penalty', formatCurrency((float) $p['penalty_amount']), ''];
}
if ((float) $p['balance_remaining'] > 0) {
    $lines[] = ['Balance remaining', formatCurrency((float) $p['balance_remaining']), ''];
}
$lines[] = ['Total paid', formatCurrency((float) $p['amount']), 'total'];
?>
<div class="receipt-actions no-print">
    <a href="<?= APP_URL ?>/index.php?page=payments" class="btn btn--outline btn--sm">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Back
    </a>
    <button type="button" onclick="window.print()" class="btn btn--primary btn--sm">
        <i class="bi bi-printer" aria-hidden="true"></i> Print receipt
    </button>
</div>

<div class="receipt">
    <div class="receipt__head">
        <div>
            <?php $receiptLogo = companyLogoUrl(); ?>
            <?php if ($receiptLogo !== ''): ?>
                <img class="receipt__logo-img" src="<?= sanitize($receiptLogo) ?>" alt="<?= sanitize(companyName()) ?>">
            <?php endif ?>
            <div class="receipt__logo"><?= sanitize(companyName()) ?></div>
            <div class="receipt__brand-sub"><?= sanitize(setting('company_address', '')) ?></div>
            <div class="receipt__brand-sub">
                <?= sanitize(setting('company_phone', '')) ?>
                <?= setting('company_email') ? '· ' . sanitize(setting('company_email')) : '' ?>
            </div>
        </div>
        <div class="receipt__meta">
            <div class="receipt__meta-label">Receipt</div>
            <div class="receipt__meta-code"><?= sanitize($p['payment_code']) ?></div>
            <div class="receipt__meta-date"><?= formatDate($p['payment_date']) ?></div>
        </div>
    </div>

    <div class="receipt__party">
        <div class="receipt__party-label">Received from</div>
        <div class="receipt__party-name"><?= sanitize($p['customer_name']) ?></div>
        <?php if (!empty($p['customer_phone'])): ?>
            <div class="receipt__party-meta"><?= sanitize($p['customer_phone']) ?></div>
        <?php endif ?>
    </div>

    <table class="receipt__table">
        <tbody>
            <?php foreach ($lines as [$label, $value, $mod]): ?>
                <tr<?= $mod !== '' ? ' class="receipt__row--' . $mod . '"' : '' ?>>
                    <td><?= sanitize($label) ?></td>
                    <td class="receipt__amount"><?= sanitize($value) ?></td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>

    <?php if ($p['notes']): ?>
        <div class="receipt__notes">
            <div class="receipt__party-label">Notes</div>
            <?= nl2br(sanitize($p['notes'])) ?>
        </div>
    <?php endif ?>

    <div class="receipt__sign">
        <div class="receipt__sign-block">
            <div class="receipt__sign-rule">Received by</div>
            <div class="receipt__sign-name"><?= sanitize($p['received_by_name'] ?? 'System') ?></div>
        </div>
        <div class="receipt__sign-block receipt__sign-block--end">
            <div class="receipt__sign-rule">Authorised signature</div>
        </div>
    </div>
</div>
