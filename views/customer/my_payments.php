<?php
/**
 * Customer Portal — My Payments
 */
$total = array_sum(array_map(fn($p) => $p['status'] === 'paid' ? (float)$p['amount'] : 0, $payments));
?>
<div class="mini-stats">
    <div class="mini-stat"><div class="mini-stat__label">Total Paid</div><div class="mini-stat__value"><?= formatCurrency($total) ?></div></div>
    <div class="mini-stat"><div class="mini-stat__label">Transactions</div><div class="mini-stat__value"><?= count($payments) ?></div></div>
</div>

<div class="card">
    <div class="card__header"><h3 class="card__title">Payment History</h3></div>
    <div class="card__body" style="padding:0">
        <?php if (empty($payments)): ?>
            <div class="empty-state"><div class="empty-state__icon"><i class="bi bi-credit-card"></i></div><div class="empty-state__title">No payments yet</div></div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Receipt</th><th>Type</th><th>Property</th><th>Amount</th><th>Date</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($payments as $p): ?>
                <tr>
                    <td><code><?= sanitize($p['payment_code']) ?></code></td>
                    <td><?= ucfirst(str_replace('_',' ',$p['payment_type'])) ?></td>
                    <td><?= sanitize($p['property_title'] ?? '—') ?></td>
                    <td><strong><?= formatCurrency((float)$p['amount']) ?></strong></td>
                    <td><?= formatDate($p['payment_date']) ?></td>
                    <td><span class="badge <?= getStatusBadgeClass($p['status']) ?>"><?= ucfirst($p['status']) ?></span></td>
                    <td><a class="btn btn--outline btn--sm" href="<?= APP_URL ?>/index.php?page=payments&action=receipt&id=<?= $p['id'] ?>"><i class="bi bi-receipt"></i></a></td>
                </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php endif ?>
    </div>
</div>
