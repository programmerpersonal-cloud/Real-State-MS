<?php
/**
 * Payment — Receipt
 */
$p = $payment;
?>
<div style="margin-bottom:16px;display:flex;gap:8px;justify-content:flex-end" class="no-print">
    <a href="<?= APP_URL ?>/index.php?page=payments" class="btn btn--outline btn--sm"><i class="bi bi-arrow-left"></i> Back</a>
    <button onclick="window.print()" class="btn btn--primary btn--sm"><i class="bi bi-printer"></i> Print Receipt</button>
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
            <div class="receipt__brand-sub"><?= sanitize(setting('company_phone', '')) ?> <?= setting('company_email') ? '· ' . sanitize(setting('company_email')) : '' ?></div>
        </div>
        <div class="receipt__meta">
            <div><strong>RECEIPT</strong></div>
            <div><?= sanitize($p['payment_code']) ?></div>
            <div class="text-muted"><?= formatDate($p['payment_date']) ?></div>
        </div>
    </div>

    <div style="margin-bottom:18px">
        <div style="font-size:.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px">Received from</div>
        <div style="font-size:1.05rem;font-weight:600;margin-top:4px"><?= sanitize($p['customer_name']) ?></div>
        <div class="text-muted" style="font-size:.85rem"><?= sanitize($p['customer_phone']) ?></div>
    </div>

    <table class="receipt__table">
        <tr><td>Payment type</td><td style="text-align:right"><?= ucfirst(str_replace('_',' ',$p['payment_type'])) ?></td></tr>
        <?php if ($p['property_title']): ?>
        <tr><td>Property</td><td style="text-align:right"><?= sanitize($p['property_title']) ?> (<?= sanitize($p['property_code']) ?>)</td></tr>
        <?php endif ?>
        <tr><td>Method</td><td style="text-align:right"><?= ucfirst(str_replace('_',' ',$p['payment_method'])) ?></td></tr>
        <?php if (!empty($sale) && (float)$sale['tax_amount'] > 0): ?>
        <tr><td>Sale subtotal</td><td style="text-align:right"><?= formatCurrency((float)$sale['sale_amount']) ?></td></tr>
        <tr>
            <td>Tax<?= (float)$sale['tax_rate'] > 0 ? ' (' . rtrim(rtrim(number_format((float)$sale['tax_rate'], 2), '0'), '.') . '%)' : '' ?></td>
            <td style="text-align:right"><?= formatCurrency((float)$sale['tax_amount']) ?></td>
        </tr>
        <tr><td>Sale total</td><td style="text-align:right"><?= formatCurrency((float)$sale['sale_amount'] + (float)$sale['tax_amount']) ?></td></tr>
        <?php endif ?>
        <?php if ($p['penalty_amount'] > 0): ?>
        <tr><td>Late penalty</td><td style="text-align:right"><?= formatCurrency((float)$p['penalty_amount']) ?></td></tr>
        <?php endif ?>
        <?php if ($p['balance_remaining'] > 0): ?>
        <tr><td>Balance remaining</td><td style="text-align:right"><?= formatCurrency((float)$p['balance_remaining']) ?></td></tr>
        <?php endif ?>
        <tr><td>Total paid</td><td style="text-align:right"><?= formatCurrency((float)$p['amount']) ?></td></tr>
    </table>

    <?php if ($p['notes']): ?>
    <div style="margin-top:16px;font-size:.85rem">
        <div class="text-muted" style="text-transform:uppercase;font-size:.7rem;letter-spacing:.4px">Notes</div>
        <?= nl2br(sanitize($p['notes'])) ?>
    </div>
    <?php endif ?>

    <div style="margin-top:36px;display:grid;grid-template-columns:1fr 1fr;gap:30px;font-size:.8rem;color:var(--text-muted)">
        <div>
            <div style="border-top:1px solid var(--text);padding-top:6px">Received by</div>
            <div style="margin-top:4px;color:var(--text)"><?= sanitize($p['received_by_name'] ?? 'System') ?></div>
        </div>
        <div style="text-align:right">
            <div style="border-top:1px solid var(--text);padding-top:6px;display:inline-block;width:180px">Authorized signature</div>
        </div>
    </div>
</div>
