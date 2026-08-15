<?php
/**
 * Owner Portal — Income
 */
?>
<div class="mini-stats">
    <div class="mini-stat"><div class="mini-stat__label">Gross Income</div><div class="mini-stat__value"><?= formatCurrency($totalGross) ?></div></div>
    <div class="mini-stat"><div class="mini-stat__label">Commission (<?= $commissionRate ?>%)</div><div class="mini-stat__value" style="color:var(--danger)">-<?= formatCurrency($commission) ?></div></div>
    <div class="mini-stat"><div class="mini-stat__label">Net Payable to Owner</div><div class="mini-stat__value" style="color:var(--success)"><?= formatCurrency($net) ?></div></div>
</div>

<div class="card">
    <div class="card__header"><h3 class="card__title">Income Transactions</h3></div>
    <div class="card__body" style="padding:0">
        <?php if (empty($payments)): ?>
            <div class="empty-state"><div class="empty-state__icon"><i class="bi bi-cash-stack"></i></div><div class="empty-state__title">No income yet</div></div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Date</th><th>Property</th><th>Type</th><th>Amount</th></tr></thead>
                <tbody>
                <?php foreach ($payments as $p): ?>
                <tr>
                    <td><?= formatDate($p['payment_date']) ?></td>
                    <td><?= sanitize($p['property_title']) ?></td>
                    <td><?= ucfirst($p['payment_type']) ?></td>
                    <td><strong><?= formatCurrency((float)$p['amount']) ?></strong></td>
                </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php endif ?>
    </div>
</div>
