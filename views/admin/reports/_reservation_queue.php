<?php
/**
 * The reservation queue.
 *
 * Every hold the system still treats as standing — status active or confirmed
 * — sorted by expiry, soonest first. Lapsed ones therefore sort to the top,
 * which is where they belong: each is a property the register still shows as
 * held and a deposit still sitting with the company, waiting on somebody to
 * either release it or renew it.
 *
 * Rendered only when there are holds. An empty queue is not an empty state,
 * it is the absence of a problem.
 *
 * Expects: $resvQueue, $window, $filters, $compare
 *
 * Every local in this file is prefixed. A partial pulled in with require
 * shares the including view's variable scope; see the note in _kpi.php.
 */
$rqRows = $resvQueue ?? [];
if (!$rqRows) {
    return;
}

$rqDeposits = 0.0;
$rqLapsed   = 0;
foreach ($rqRows as $rqR) {
    $rqDeposits += (float) $rqR['deposit_amount'];
    if ((int) $rqR['days_left'] < 0) {
        $rqLapsed++;
    }
}
?>
<div class="table-card<?= $rqLapsed > 0 ? ' fd' : '' ?>">
    <div class="table-head">
        <h4 class="table-head__title">
            <?php if ($rqLapsed > 0): ?>
                <i class="bi bi-bookmark-x fd__icon" aria-hidden="true"></i>
            <?php endif ?>
            Reservation queue
        </h4>
        <span class="table-head__note">
            <?= count($rqRows) ?> <?= count($rqRows) === 1 ? 'hold' : 'holds' ?>
            · <?= sanitize(formatCurrency($rqDeposits)) ?> on deposit
            <?php if ($rqLapsed > 0): ?>
                · <?= $rqLapsed ?> already lapsed
            <?php endif ?>
        </span>
    </div>

    <?php if ($rqLapsed > 0): ?>
        <div class="fd__lede">
            <p>
                A lapsed hold is one whose expiry date has passed while its status still
                reads active or confirmed. Nothing expires these automatically, so the
                property stays marked as held and the deposit stays with the company until
                somebody decides. <strong>They are not counted as live reservations
                anywhere in this report.</strong>
            </p>
        </div>
    <?php endif ?>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">Property</th>
                    <th scope="col" class="col-mid">Customer</th>
                    <th scope="col" class="col-lo">Reserved</th>
                    <th scope="col">Expires</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="cell-num">Deposit</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rqRows as $rqR): ?>
                    <?php $rqDays = (int) $rqR['days_left']; ?>
                    <tr<?= $rqDays < 0 ? ' class="is-muted-row"' : '' ?>>
                        <td>
                            <a class="tp-name" href="<?= sanitize(APP_URL . '/index.php?page=properties&action=show&id=' . (int) $rqR['property_id']) ?>">
                                <?= sanitize((string) $rqR['property_title']) ?>
                            </a>
                            <div class="tp-code tp-code--id"><?= sanitize((string) $rqR['reservation_code']) ?></div>
                        </td>
                        <td class="col-mid">
                            <?= !empty($rqR['customer_name'])
                                ? sanitize((string) $rqR['customer_name'])
                                : '<span class="text-subtle">—</span>' ?>
                        </td>
                        <td class="col-lo pr-date"><?= sanitize(formatDate((string) $rqR['reservation_date'])) ?></td>
                        <td class="pr-date">
                            <?= sanitize(formatDate((string) $rqR['expiry_date'])) ?>
                            <div class="tp-code">
                                <?php if ($rqDays < 0): ?>
                                    <?= number_format(abs($rqDays)) ?> days ago
                                <?php elseif ($rqDays === 0): ?>
                                    expires today
                                <?php else: ?>
                                    <?= number_format($rqDays) ?> days left
                                <?php endif ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($rqDays < 0): ?>
                                <?php /* Named for what it is, in words rather than by tint
                                         alone. The status column still says confirmed; the
                                         calendar disagrees. */ ?>
                                <span class="status status--danger">
                                    <span class="status__dot" aria-hidden="true"></span>
                                    Lapsed
                                </span>
                                <div class="tp-code">recorded <?= sanitize(uiLabel((string) $rqR['status'])) ?></div>
                            <?php else: ?>
                                <span class="status status--success">
                                    <span class="status__dot" aria-hidden="true"></span>
                                    <?= sanitize(uiLabel((string) $rqR['status'])) ?>
                                </span>
                            <?php endif ?>
                        </td>
                        <td class="cell-num tp-money"><?= sanitize(formatCurrency((float) $rqR['deposit_amount'])) ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
            <tfoot>
                <tr>
                    <th scope="row" colspan="5">Deposits held across <?= count($rqRows) ?>
                        <?= count($rqRows) === 1 ? 'hold' : 'holds' ?></th>
                    <td class="cell-num tp-money"><?= sanitize(formatCurrency($rqDeposits)) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="table-foot">
        <p class="table-foot__note">
            Deposits are money held against a property, not revenue. Nothing on this table
            is changed by the report — a lapsed hold stays exactly as recorded until
            somebody acts on it.
        </p>
    </div>
</div>
