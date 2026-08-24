<?php
/**
 * A tenancy table — one row per lease.
 *
 * Rendered twice from the rentals report, in two modes:
 *
 *   attention  the queue: leases already expired or ending within 60 days,
 *              soonest first. Rendered only when it has rows, because an
 *              empty queue is good news and does not need a panel.
 *   active     the running book, every live tenancy with its ledger.
 *
 * The ledger columns are per-lease aggregates computed in the model's single
 * pass, so no lease appears twice and no per-row query is issued.
 *
 * Two of the columns are window-bounded and two are not, which is the thing a
 * reader would otherwise get wrong: expected and settled describe rent that
 * fell due inside the reporting period, while outstanding and arrears are
 * running balances across the whole tenancy. That is why a lease can show
 * more outstanding than it was ever expected to pay this period.
 *
 * Expects: $mode via $rnMode, plus $leases, $attention, $leaseTotal,
 *          $leasePage, $leasePages, $window, $filters, $compare
 *
 * Every local in this file is prefixed. A partial pulled in with require
 * shares the including view's variable scope; see the note in _kpi.php.
 */
$ltMode      = $rnMode ?? 'active';
$ltAttention = $ltMode === 'attention';
$ltRows      = $ltAttention ? ($attention ?? []) : ($leases ?? []);
$ltFiltered  = reportFilterCount($filters) > 0;
$ltCarry     = !empty($compare) ? ['compare' => '1'] : [];
$ltToday     = $window['today'];

// An empty attention queue is not an empty state, it is the absence of a
// problem. Nothing is drawn at all.
if ($ltAttention && !$ltRows) {
    return;
}

$ltExpected = $ltSettled = $ltOutstanding = $ltArrears = 0.0;
foreach ($ltRows as $ltR) {
    $ltExpected    += (float) $ltR['expected'];
    $ltSettled     += (float) $ltR['settled'];
    $ltOutstanding += (float) $ltR['outstanding'];
    $ltArrears     += (float) $ltR['arrears'];
}

$ltMoney = static fn($ltV): string => (float) $ltV > 0
    ? sanitize(formatCurrency((float) $ltV))
    : '<span class="text-subtle" aria-label="none">—</span>';
?>
<div class="table-card<?= $ltAttention ? ' fd' : '' ?>">
    <div class="table-head">
        <div class="table-head__title">
            <?php if ($ltAttention): ?>
                <i class="bi bi-exclamation-triangle fd__icon" aria-hidden="true"></i>
                Lease attention queue
            <?php else: ?>
                Active rental portfolio
            <?php endif ?>
        </div>
        <span class="table-head__note">
            <?php if ($ltAttention): ?>
                Expired or ending within 60 days · soonest first
            <?php else: ?>
                <?= number_format((int) $leaseTotal) ?>
                <?= (int) $leaseTotal === 1 ? 'tenancy' : 'tenancies' ?>
                · ledger for <?= sanitize($window['label']) ?>
            <?php endif ?>
        </span>
    </div>

    <?php if (!$ltRows): ?>
        <?= uiEmptyState([
            'icon'  => 'bi-file-earmark-text',
            'title' => 'No active tenancy',
            'desc'  => $ltFiltered
                ? 'No tenancy matching the current filters is running.'
                : 'No lease in scope is currently active. A tenancy appears here once it '
                . 'is active and has not passed its end date.',
            'actions' => $ltFiltered ? [[
                'label' => 'Clear filters', 'icon' => 'bi-arrow-counterclockwise',
                'class' => 'btn--outline',
                'url'   => reportUrl($window, [], ['tab' => 'rentals'] + $ltCarry),
            ]] : [],
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Property</th>
                        <th scope="col" class="col-mid">Tenant</th>
                        <th scope="col" class="col-lo">Started</th>
                        <th scope="col">Ends</th>
                        <th scope="col" class="cell-num col-mid">Rent</th>
                        <th scope="col" class="cell-num">Expected</th>
                        <th scope="col" class="cell-num">Settled</th>
                        <th scope="col" class="cell-num">Rate</th>
                        <th scope="col" class="cell-num col-lo">Outstanding</th>
                        <th scope="col" class="cell-num">Arrears</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ltRows as $ltR): ?>
                        <?php
                        $ltDays    = (int) $ltR['days_left'];
                        $ltExpired = $ltR['end_date'] < $ltToday;
                        $ltRate    = reportShare((float) $ltR['settled'], (float) $ltR['expected']);
                        $ltTone    = $ltRate === null ? 'muted'
                            : ($ltRate >= 90 ? 'success' : ($ltRate >= 70 ? 'warning' : 'danger'));
                        ?>
                        <tr>
                            <td>
                                <a class="tp-name" href="<?= sanitize(APP_URL . '/index.php?page=properties&action=show&id=' . (int) $ltR['property_id']) ?>">
                                    <?= sanitize((string) $ltR['property_title']) ?>
                                </a>
                                <div class="tp-code"><?= sanitize((string) $ltR['lease_code']) ?></div>
                            </td>
                            <td class="col-mid">
                                <?= !empty($ltR['tenant_name'])
                                    ? sanitize((string) $ltR['tenant_name'])
                                    : '<span class="text-subtle">—</span>' ?>
                            </td>
                            <td class="col-lo pr-date"><?= sanitize(formatDate((string) $ltR['start_date'])) ?></td>
                            <td class="pr-date">
                                <?= sanitize(formatDate((string) $ltR['end_date'])) ?>
                                <?php if ($ltExpired): ?>
                                    <?php /* Already gone. Marked in words, not colour alone,
                                             and never described as "expiring". */ ?>
                                    <span class="pr-flag pr-flag--mismatch"
                                          title="This lease is still flagged active but its end date has passed.">
                                        Expired
                                    </span>
                                <?php elseif ($ltDays <= 60): ?>
                                    <div class="tp-code"><?= number_format(max(0, $ltDays)) ?> days left</div>
                                <?php endif ?>
                            </td>
                            <td class="cell-num col-mid tp-money"><?= sanitize(formatCurrency((float) $ltR['rent_amount'])) ?></td>
                            <td class="cell-num tp-money"><?= $ltMoney($ltR['expected']) ?></td>
                            <td class="cell-num tp-money"><?= $ltMoney($ltR['settled']) ?></td>
                            <td class="cell-num">
                                <span class="status status--<?= sanitize($ltTone) ?>">
                                    <span class="status__dot" aria-hidden="true"></span>
                                    <?= sanitize(reportPercent($ltRate)) ?>
                                </span>
                            </td>
                            <td class="cell-num col-lo tp-money"><?= $ltMoney($ltR['outstanding']) ?></td>
                            <td class="cell-num tp-money">
                                <?php if ((float) $ltR['arrears'] > 0): ?>
                                    <span class="text-danger"><?= sanitize(formatCurrency((float) $ltR['arrears'])) ?></span>
                                    <?php if ((int) $ltR['overdue_count'] > 0): ?>
                                        <div class="tp-code"><?= number_format((int) $ltR['overdue_count']) ?> overdue</div>
                                    <?php endif ?>
                                <?php else: ?>
                                    <span class="text-subtle" aria-label="none">—</span>
                                <?php endif ?>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="row" colspan="5">Total across <?= count($ltRows) ?>
                            <?= count($ltRows) === 1 ? 'tenancy' : 'tenancies' ?></th>
                        <td class="cell-num tp-money"><?= sanitize(formatCurrency($ltExpected)) ?></td>
                        <td class="cell-num tp-money"><?= sanitize(formatCurrency($ltSettled)) ?></td>
                        <td class="cell-num"><?= sanitize(reportPercent(reportShare($ltSettled, $ltExpected))) ?></td>
                        <td class="cell-num col-lo tp-money"><?= sanitize(formatCurrency($ltOutstanding)) ?></td>
                        <td class="cell-num tp-money"><?= sanitize(formatCurrency($ltArrears)) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <?php if (!$ltAttention && (int) $leasePages > 1): ?>
            <?php
            /* Unprefixed against this folder's convention because they are the
               pagination component's published contract. */
            $page       = (int) $leasePage;
            $totalPages = (int) $leasePages;
            require VIEWS_PATH . '/components/pagination.php';
            ?>
        <?php endif ?>

        <div class="table-foot">
            <p class="table-foot__note">
                <strong>Expected</strong> and <strong>settled</strong> cover rent falling due
                inside <?= sanitize($window['label']) ?>, and the rate is one over the other.
                <strong>Outstanding</strong> and <strong>arrears</strong> are running balances
                across the whole tenancy, which is why either can exceed the expected column.
                A rate reads as unavailable where no rent fell due — not as 0%.
            </p>
        </div>
    <?php endif ?>
</div>
