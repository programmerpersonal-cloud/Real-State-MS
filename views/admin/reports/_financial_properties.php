<?php
/**
 * The rent ledger, per property.
 *
 * Every column here comes off `payment_schedules` on the due-date axis —
 * expected, settled, outstanding and arrears from one table — so no row can
 * contradict itself the way a row mixing invoiced and received money would.
 * Collected cash per property is a genuinely different question and is
 * answered on the Overview by the top-properties table; the two are kept
 * apart because adjacent columns are read as being on the same footing, and
 * these would not be.
 *
 * Only properties with rent scheduled in the window appear. A property with
 * nothing due has no collection rate, and a row reading 0% against it would
 * report a collections failure that never happened.
 *
 * Expects: $byProperty, $ledger, $window, $filters, $compare
 *
 * Every local in this file is prefixed. A partial pulled in with require
 * shares the including view's variable scope; see the note in _kpi.php.
 */
$fpRows     = $byProperty ?? [];
$fpFiltered = reportFilterCount($filters) > 0;
$fpCarry    = !empty($compare) ? ['compare' => '1'] : [];

/* Totals across the rows actually shown, so the foot of the table reconciles
   with the table rather than with the whole portfolio. Where the list is
   capped, that is stated rather than left for someone to discover. */
$fpExpected = $fpSettled = $fpOutstanding = $fpArrears = 0.0;
foreach ($fpRows as $fpR) {
    $fpExpected    += (float) $fpR['expected'];
    $fpSettled     += (float) $fpR['settled'];
    $fpOutstanding += (float) $fpR['outstanding'];
    $fpArrears     += (float) $fpR['arrears'];
}
$fpCapped = count($fpRows) >= 20;

$fpMoney = static fn($fpV): string => (float) $fpV > 0
    ? sanitize(formatCurrency((float) $fpV))
    : '<span class="text-subtle" aria-label="none">—</span>';
?>
<div class="table-card">
    <div class="table-head">
        <h4 class="table-head__title">Rent ledger by property</h4>
        <span class="table-head__note">
            Scheduled rent in <?= sanitize($window['label']) ?>, dated by when it fell due
        </span>
    </div>

    <?php if (!$fpRows): ?>
        <?= uiEmptyState([
            'icon'  => 'bi-calendar-x',
            'title' => 'No rent was scheduled in this period',
            'desc'  => $fpFiltered
                ? 'No property matching the current filters has rent falling due inside the selected period.'
                : 'No tenancy has a scheduled instalment falling due inside the selected period. '
                . 'Widen the period, or check that the leases in scope carry payment schedules.',
            'actions' => $fpFiltered ? [[
                'label' => 'Clear filters',
                'icon'  => 'bi-arrow-counterclockwise',
                'class' => 'btn--outline',
                'url'   => reportUrl($window, [], ['tab' => 'financial'] + $fpCarry),
            ]] : [],
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">Rent scheduled, settled, outstanding and in arrears, by property.</caption>
                <thead>
                    <tr>
                        <th scope="col">Property</th>
                        <th scope="col" class="col-mid">Category</th>
                        <th scope="col" class="cell-num">Expected</th>
                        <th scope="col" class="cell-num">Settled</th>
                        <th scope="col" class="cell-num">Rate</th>
                        <th scope="col" class="cell-num col-lo">Outstanding</th>
                        <th scope="col" class="cell-num">Arrears</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fpRows as $fpR): ?>
                        <?php
                        $fpRate = reportShare((float) $fpR['settled'], (float) $fpR['expected']);
                        $fpTone = $fpRate === null
                            ? 'muted'
                            : ($fpRate >= 90 ? 'success' : ($fpRate >= 70 ? 'warning' : 'danger'));
                        ?>
                        <tr>
                            <td>
                                <a class="tp-name" href="<?= sanitize(APP_URL . '/index.php?page=properties&action=show&id=' . (int) $fpR['id']) ?>">
                                    <?= sanitize((string) $fpR['title']) ?>
                                </a>
                                <?php /* The code drills to the instalments scheduled on it; the title above it still opens
                                     the property. Two destinations, one row, and
                                     neither takes the other's click. */ ?>
                                <a class="tp-code tp-code--id is-drill" data-drill
                                   href="<?= sanitize(reportDrillUrl(
                                       $window, $filters, 'financial', 'property_schedule', (string) (int) $fpR['id'],
                                       !empty($compare) ? ['compare' => '1'] : []
                                   )) ?>"><?= sanitize((string) $fpR['property_code']) ?></a>
                            </td>
                            <td class="col-mid">
                                <i class="bi <?= sanitize(categoryIcon((string) $fpR['category'])) ?> tp-cat-icon" aria-hidden="true"></i>
                                <?= sanitize(categoryLabel((string) $fpR['category'])) ?>
                            </td>
                            <td class="cell-num tp-money"><?= $fpMoney($fpR['expected']) ?></td>
                            <td class="cell-num tp-money"><?= $fpMoney($fpR['settled']) ?></td>
                            <td class="cell-num">
                                <?php /* Tone plus the number, never tone alone — the rate is
                                         the figure, the colour is only an aid to scanning. */ ?>
                                <span class="status status--<?= sanitize($fpTone) ?>">
                                    <span class="status__dot" aria-hidden="true"></span>
                                    <?= sanitize(reportPercent($fpRate)) ?>
                                </span>
                            </td>
                            <td class="cell-num col-lo tp-money"><?= $fpMoney($fpR['outstanding']) ?></td>
                            <td class="cell-num tp-money">
                                <?php if ((float) $fpR['arrears'] > 0): ?>
                                    <span class="text-danger"><?= sanitize(formatCurrency((float) $fpR['arrears'])) ?></span>
                                    <?php if ((int) $fpR['overdue_count'] > 0): ?>
                                        <div class="tp-code"><?= number_format((int) $fpR['overdue_count']) ?> overdue</div>
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
                        <th scope="row" colspan="2">Total across <?= count($fpRows) ?>
                            <?= count($fpRows) === 1 ? 'property' : 'properties' ?></th>
                        <td class="cell-num tp-money"><?= sanitize(formatCurrency($fpExpected)) ?></td>
                        <td class="cell-num tp-money"><?= sanitize(formatCurrency($fpSettled)) ?></td>
                        <td class="cell-num"><?= sanitize(reportPercent(reportShare($fpSettled, $fpExpected))) ?></td>
                        <td class="cell-num col-lo tp-money"><?= sanitize(formatCurrency($fpOutstanding)) ?></td>
                        <td class="cell-num tp-money"><?= sanitize(formatCurrency($fpArrears)) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="table-foot">
            <p class="table-foot__note">
                Expected and settled are bounded by the reporting period.
                <strong>Outstanding and arrears are running balances</strong> covering every
                unpaid instalment on these tenancies, not only those falling due in the
                period — which is why the outstanding column can exceed the expected one.
                <?php if ($fpCapped): ?>
                    Showing the 20 properties with the most rent scheduled; the totals above
                    describe those rows rather than the whole portfolio.
                <?php endif ?>
            </p>
        </div>
    <?php endif ?>
</div>
