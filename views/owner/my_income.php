<?php
/**
 * Owner Portal — income.
 *
 * Three figures that only make sense together: what came in, what the agency
 * keeps, what reaches the owner. The arithmetic is shown rather than assumed,
 * because "why is this smaller than the rent" is the question this page is
 * opened to answer.
 */
?>
<div class="ledger">
    <div class="ledger__card ledger__card--info">
        <span class="ledger__label"><span class="ledger__dot" aria-hidden="true"></span> Collected</span>
        <span class="ledger__value"><?= formatCurrency($totalGross) ?></span>
        <span class="ledger__meta">Rent and other payments received</span>
    </div>
    <div class="ledger__card ledger__card--warning">
        <span class="ledger__label"><span class="ledger__dot" aria-hidden="true"></span> Management fee</span>
        <span class="ledger__value">&minus;<?= formatCurrency($commission) ?></span>
        <span class="ledger__meta"><?= $commissionRate ?>% of the amount collected</span>
    </div>
    <div class="ledger__card ledger__card--success">
        <span class="ledger__label"><span class="ledger__dot" aria-hidden="true"></span> Yours</span>
        <span class="ledger__value"><?= formatCurrency($net) ?></span>
        <span class="ledger__meta">After the management fee</span>
    </div>
</div>

<div class="table-card">
    <div class="table-head">
        <div class="table-head__title">
            <?= count($payments) ?> <?= count($payments) === 1 ? 'payment' : 'payments' ?> received
        </div>
        <span class="table-head__note">Newest first</span>
    </div>

    <?php if (empty($payments)): ?>
        <?= uiEmptyState([
            'icon'  => 'bi-cash-stack',
            'title' => 'No income recorded yet',
            'desc'  => 'Payments collected against your properties appear here as the office records them.',
            'actions' => [[
                'label' => 'My properties', 'icon' => 'bi-buildings', 'class' => 'btn--outline',
                'url'   => APP_URL . '/index.php?page=my-properties',
            ]],
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th class="cell-date">Received</th>
                        <th>Property</th>
                        <th>What for</th>
                        <th class="cell-num">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td class="cell-date"><?= formatDate($p['payment_date']) ?></td>
                            <td><span class="cell-strong"><?= sanitize($p['property_title']) ?></span></td>
                            <td><?= sanitize(uiLabel((string) $p['payment_type'])) ?></td>
                            <td class="cell-num"><strong><?= formatCurrency((float) $p['amount']) ?></strong></td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</div>
