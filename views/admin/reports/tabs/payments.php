<?php
/**
 * Payments — what the ledger actually did.
 *
 * A transaction report, and the distinction from Financial is the whole point
 * of it existing separately. Financial asks how the business performed and
 * answers in money on the due-date axis. This asks what happened in the
 * ledger, answers on the payment-date axis, and counts records as readily as
 * it counts money — because "more payments" and "more money" are different
 * events, and a report that only measures the second cannot tell you which
 * one occurred.
 *
 * Three quantities sit side by side at the top and are easy to conflate, so
 * they are labelled to within an inch of their lives:
 *
 *   records     rows in the window, whatever state they are in
 *   received    marked paid, dated today or earlier
 *   collected   the narrower revenue definition, which additionally drops
 *               deposits and refunds
 *
 * On today's data the last two are equal, because no deposit or refund has
 * ever been recorded. They are still separate figures. The first refund would
 * silently break any report that assumed otherwise.
 *
 * Vars from ReportController::paymentsData().
 */
$pyFiltered = reportFilterCount($filters) > 0;
$pyCarry    = !empty($compare) ? ['compare' => '1'] : [];
$pyReset    = reportUrl($window, [], ['tab' => 'payments'] + $pyCarry);
$pyLink     = static fn(string $tab): string => reportUrl($window, $filters, ['tab' => $tab] + $pyCarry);
?>

<?php require dirname(__DIR__) . '/_data_quality.php'; ?>

<?php /* what the ledger recorded */ ?>
<?php $section = [
    'title' => 'Ledger summary',
    'desc'  => 'What the payments ledger recorded in this period.',
]; require dirname(__DIR__) . '/_section.php'; ?>
<div class="kpis kpis--five">
    <?php
    $kpi = [
        'label'   => 'Payment records',
        'value'   => number_format((int) $activity['records']),
        'icon'    => 'bi-receipt',
        'tone'    => 'primary',
        'context' => 'Transactions dated in this period — a count, not an amount',
        'spark'   => array_map(static fn(array $b): float => (float) $b['total'], $activitySeries['count']),
        'delta'   => $previousActivity !== null
            ? reportDelta((float) $activity['records'], (float) $previousActivity['records'])
            : null,
        'delta_format'   => static fn(float $v): string => number_format($v) . ' records',
        'previous_label' => $previousActivity !== null
            ? number_format((int) $previousActivity['records']) . ' previously'
            : null,
    ];
    require dirname(__DIR__) . '/_kpi.php';

    /* "Received", not "collected". This is every paid record dated today or
       earlier, whatever its type — deposits and refunds included. The next
       tile is the narrower revenue figure, and the gap between them is money
       that arrived but is not earnings. */
    $kpi = [
        'label'   => 'Money received',
        'good'    => 'up',
        'value'   => formatCurrency((float) $activity['received']),
        'icon'    => 'bi-inbox',
        'tone'    => 'info',
        'context' => sprintf(
            '%d paid %s dated today or earlier — all types',
            (int) $activity['received_records'],
            (int) $activity['received_records'] === 1 ? 'record' : 'records'
        ),
        'delta'   => $previousActivity !== null
            ? reportDelta((float) $activity['received'], (float) $previousActivity['received'])
            : null,
        'delta_format'   => static fn(float $v): string => formatCurrency($v),
        'previous_label' => $previousActivity !== null
            ? formatCurrency((float) $previousActivity['received']) . ' previously'
            : null,
    ];
    require dirname(__DIR__) . '/_kpi.php';

    /* The approved definition, unchanged, and the bridge to the other two
       reports: same window, same scope, same filters must give the same
       figure on Overview, Financial and here. */
    $kpi = [
        'label'   => 'Collected revenue',
        'value'   => formatCurrency((float) $activity['collected']),
        'icon'    => 'bi-cash-stack',
        'tone'    => 'success',
        'context' => abs((float) $activity['received'] - (float) $activity['collected']) >= 0.005
            ? formatCurrency((float) $activity['received'] - (float) $activity['collected'])
              . ' of the received total is deposits or refunds'
            : 'Same as received — no deposits or refunds in this period',
        'url'     => $pyLink('financial'),
    ];
    require dirname(__DIR__) . '/_kpi.php';

    /* Rendered even at zero. A tile that vanishes when the count is nil reads
       as a report that does not check, and the reader has no way to tell the
       difference between "none" and "not looked for". */
    $kpi = [
        'label'   => 'Dated ahead',
        'value'   => formatCurrency((float) $futureDated['amount']),
        'icon'    => 'bi-calendar-plus',
        'tone'    => (int) $futureDated['count'] > 0 ? 'warning' : 'success',
        'context' => (int) $futureDated['count'] > 0
            ? sprintf(
                '%d %s dated after today, held out of revenue',
                (int) $futureDated['count'],
                (int) $futureDated['count'] === 1 ? 'record' : 'records'
            )
            : 'No future-dated payments',
    ];
    require dirname(__DIR__) . '/_kpi.php';

    $kpi = [
        'label'   => 'Needs review',
        'value'   => number_format((int) $reviewFlags),
        'icon'    => 'bi-flag',
        'tone'    => (int) $reviewFlags > 0 ? 'warning' : 'success',
        'context' => (int) $reviewFlags > 0
            ? 'Classification, timing and completeness flags combined'
            : 'Nothing flagged on these records',
    ];
    require dirname(__DIR__) . '/_kpi.php';
    ?>
</div>

<?php if (!empty($compare)): ?>
    <?php require dirname(__DIR__) . '/_payment_comparison.php'; ?>
<?php endif ?>

<?php /* activity over time */ ?>
<?php $section = [
    'title' => 'Transaction activity',
    'desc'  => 'Amount taken and records written, over time.',
]; require dirname(__DIR__) . '/_section.php'; ?>
<div class="rgrid rgrid--wide">
    <?php
    /* Amount and count are the same story told twice, and they are drawn as
       two cards rather than one dual-axis chart on purpose. A second y-axis
       lets the reader believe a crossing point means something — it means the
       two axes were scaled that way — and this report's entire premise is
       that volume and value move independently and must be read separately. */
    $pyAmounts = array_map(static fn(array $b): float => (float) $b['total'], $activitySeries['amount']);

    $pySeries = [['label' => 'Amount recorded', 'data' => $pyAmounts, 'tone' => '--primary']];
    if (!empty($compare) && $previousSeries) {
        // Folded onto this period's buckets by day offset, so bucket three is
        // "days 15 to 21" in both series and nothing is trimmed to fit.
        $pySeries[] = [
            'label'     => 'Previous period',
            'data'      => array_map(static fn(array $b): float => (float) $b['total'], $previousSeries),
            'tone'      => '--text-subtle',
            'altLabels' => array_column($previousSeries, 'label'),
        ];
    }

    $chart = [
        'id'       => 'paymentActivity',
        'title'    => 'Payment activity',
        'subtitle' => 'Amount recorded by ' . $window['grain'] . ', dated by the day the payment was made',
        'type'     => 'bar',
        'unit'     => 'currency',
        'labels'   => array_column($activitySeries['amount'], 'label'),
        'series'   => $pySeries,
        'label_heading' => ucfirst($window['grain']),
        'empty'    => 'No payment was dated in this period.',
        'size'   => 'feature',
        'filtered' => $pyFiltered,
        'resetUrl' => $pyReset,
    ];
    require dirname(__DIR__) . '/_chart_card.php';

    $chart = [
        'id'       => 'paymentVolume',
        'title'    => 'Transaction volume',
        'subtitle' => 'Number of payment records by ' . $window['grain'],
        'type'     => 'line',
        'unit'     => 'number',
        'labels'   => array_column($activitySeries['count'], 'label'),
        'series'   => [[
            'label' => 'Records',
            'data'  => array_map(static fn(array $b): float => (float) $b['total'], $activitySeries['count']),
            'tone'  => '--purple',
        ]],
        'label_heading' => ucfirst($window['grain']),
        'empty'    => 'No payment was dated in this period.',
        'size'   => 'feature',
        'filtered' => $pyFiltered,
        'resetUrl' => $pyReset,
        'footnote' => 'Read against the amount beside it. Volume rising while the amount '
                    . 'falls means smaller payments, not worse collection — collection '
                    . 'performance is a Financial question, on a different axis.',
    ];
    require dirname(__DIR__) . '/_chart_card.php';
    ?>
</div>

<?php /* how transactions are stated */ ?>
<?php $section = [
    'title' => 'How transactions are stated',
    'desc'  => 'Status and method across every record in scope.',
]; require dirname(__DIR__) . '/_section.php'; ?>
<div class="rgrid rgrid--wide">
    <?php
    /* Statuses with no rows are dropped from the picture but kept in the
       table below it, so "no cancellations this period" is visible as a zero
       rather than as an absence. */
    $pyStatusDrawn = array_values(array_filter($statusBreakdown, static fn(array $r): bool => (int) $r['records'] > 0));
    $pyStatusTone  = [
        'paid'      => '--success',
        'pending'   => '--warning',
        'partial'   => '--info',
        'overdue'   => '--danger',
        'cancelled' => '--text-subtle',
    ];

    $chart = [
        'id'       => 'paymentStatus',
        'title'    => 'Payment status',
        'subtitle' => 'Records in this period by the state they are in',
        'type'     => 'doughnut',
        'unit'     => 'number',
        'labels'   => array_column($pyStatusDrawn, 'label'),
        'series'   => [[
            'label' => 'Records',
            'data'  => array_map('intval', array_column($pyStatusDrawn, 'records')),
            'tones' => array_map(
                static fn(array $r): string => $pyStatusTone[$r['status']] ?? '--primary',
                $pyStatusDrawn
            ),
        ]],
        'label_heading' => 'Status',
        'empty'    => 'No payment was dated in this period.',
        'size'   => 'standard',
        'share'    => true,
        'filtered' => $pyFiltered,
        'resetUrl' => $pyReset,
        'footnote' => 'A count of transactions in each state. This is not a collection '
                    . 'rate — how much of the rent due was actually settled is a '
                    . 'Financial measure, taken on the due-date axis.',
    ];
    require dirname(__DIR__) . '/_chart_card.php';

    /* Missing methods get their own named slice rather than being dropped.
       Silently omitting them would remove real money from a chart whose whole
       job is to account for all of it. */
    $pyMethodDrawn = array_values(array_filter($methodBreakdown, static fn(array $r): bool => (float) $r['amount'] > 0));

    $chart = [
        'id'       => 'paymentMethods',
        'title'    => 'Payment methods',
        'subtitle' => 'Amount recorded by how it was paid',
        'type'     => 'bar',
        'unit'     => 'currency',
        'horizontal' => true,
        'labels'   => array_column($pyMethodDrawn, 'label'),
        'series'   => [[
            'label' => 'Amount',
            'data'  => array_map(static fn(array $r): float => (float) $r['amount'], $pyMethodDrawn),
            'tone'  => '--primary',
        ]],
        'label_heading' => 'Method',
        'empty'    => 'No payment was dated in this period, so no method has been used.',
        'size'   => 'standard',
        'filtered' => $pyFiltered,
        'resetUrl' => $pyReset,
    ];
    require dirname(__DIR__) . '/_chart_card.php';
    ?>
</div>

<?php /* classification, and the insights that follow */ ?>
<?php $section = [
    'title' => 'Classification and attention',
    'desc'  => 'How payments are filed, and what stands out.',
]; require dirname(__DIR__) . '/_section.php'; ?>
<div class="rgrid rgrid--wide">
    <?php require dirname(__DIR__) . '/_payment_classification.php'; ?>
    <?php require dirname(__DIR__) . '/_insights.php'; ?>
</div>

<?php /* records dated ahead */ ?>
<?php $section = [
    'title' => 'Detailed records',
    'desc'  => 'Records held out of the totals, then every transaction in scope.',
]; require dirname(__DIR__) . '/_section.php'; ?>
<?php if ((int) $futureDated['count'] > 0): ?>
    <?php require dirname(__DIR__) . '/_future_dated.php'; ?>
<?php endif ?>

<!-- ── Row 6 · the transactions themselves ───────────────────────── -->
<?php require dirname(__DIR__) . '/_payment_records.php'; ?>
