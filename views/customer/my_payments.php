<?php
/**
 * Customer Portal — My Payments
 */
$paid    = array_sum(array_map(static fn($p) => $p['status'] === 'paid' ? (float) $p['amount'] : 0, $payments));
$outstanding = array_sum(array_map(
    static fn($p) => in_array($p['status'], ['pending', 'overdue', 'partial'], true) ? (float) $p['amount'] : 0,
    $payments
));
?>
<div class="ledger">
    <div class="ledger__card ledger__card--success">
        <span class="ledger__label"><span class="ledger__dot" aria-hidden="true"></span> Paid</span>
        <span class="ledger__value"><?= formatCurrency($paid) ?></span>
        <span class="ledger__meta"><?= count($payments) ?> <?= count($payments) === 1 ? 'record' : 'records' ?> in total</span>
    </div>
    <?php if ($outstanding > 0): ?>
        <div class="ledger__card ledger__card--warning">
            <span class="ledger__label"><span class="ledger__dot" aria-hidden="true"></span> Still owing</span>
            <span class="ledger__value"><?= formatCurrency($outstanding) ?></span>
            <span class="ledger__meta">Pending or overdue</span>
        </div>
    <?php endif ?>
</div>

<div class="table-card">
    <div class="table-head">
        <div class="table-head__title">Your payment history</div>
        <span class="table-head__note">Every receipt can be reopened and printed</span>
    </div>

    <?php if (empty($payments)): ?>
        <?= uiEmptyState([
            'icon'  => 'bi-credit-card',
            'title' => 'No payments yet',
            'desc'  => 'Payments recorded against your tenancy appear here, each with a receipt you can print.',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Receipt</th>
                        <th class="col-lo">What for</th>
                        <th class="col-mid">Property</th>
                        <th class="cell-num">Amount</th>
                        <th class="cell-date">Date</th>
                        <th>Status</th>
                        <th class="cell-actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $p): ?>
                        <?php $id = (int) $p['id']; ?>
                        <tr>
                            <td class="cell-tight">
                                <a href="<?= APP_URL ?>/index.php?page=payments&amp;action=receipt&amp;id=<?= $id ?>" class="table__id">
                                    <?= sanitize($p['payment_code']) ?>
                                </a>
                            </td>
                            <td class="col-lo"><?= sanitize(uiLabel((string) $p['payment_type'])) ?></td>
                            <td class="cell-clip col-mid"><?= sanitize($p['property_title'] ?: '—') ?></td>
                            <td class="cell-num"><strong><?= formatCurrency((float) $p['amount']) ?></strong></td>
                            <td class="cell-date"><?= formatDate($p['payment_date']) ?></td>
                            <td><?= uiStatus($p['status']) ?></td>
                            <td class="cell-actions">
                                <a class="btn btn--outline btn--sm"
                                   href="<?= APP_URL ?>/index.php?page=payments&amp;action=receipt&amp;id=<?= $id ?>">
                                    <i class="bi bi-receipt" aria-hidden="true"></i> Receipt
                                </a>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</div>
