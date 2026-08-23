<?php
/**
 * Overview — the executive read.
 *
 * Four rows, in the order a manager actually asks the questions: how much
 * came in, where it came from, what the portfolio looks like, and what needs
 * doing about it. Data quality sits above all of it, collapsed, because
 * whether the numbers can be trusted is the question that comes before any
 * of them.
 *
 * Every figure on this page resolves to a CoreAnalytics method that was
 * reconciled against the module owning the data in Phase 1. Where something
 * cannot be derived honestly it is absent rather than estimated — the
 * occupancy tile carries no comparison for exactly that reason, and says so.
 *
 * Vars from ReportController::overviewData().
 */
$ovFiltered = reportFilterCount($filters) > 0;
$ovCarry    = !empty($compare) ? ['compare' => '1'] : [];
$ovReset    = reportUrl($window, [], ['tab' => 'overview'] + $ovCarry);
$ovLink     = static fn(string $tab): string => reportUrl($window, $filters, ['tab' => $tab] + $ovCarry);

/* The sparkline on the revenue tile is the same series the trend chart draws,
   so the two can never disagree about the shape of the period. */
$ovSpark = array_map(static fn(array $p): float => (float) $p['total'], $series);
?>

<?php require dirname(__DIR__) . '/_data_quality.php'; ?>

<!-- ── Row 1 · the headline figures ──────────────────────────────── -->
<div class="kpis">
    <?php
    /* Revenue. The one tile with a comparison, because collected revenue is
       the one headline figure whose previous period is a real measurement
       rather than a reconstruction. */
    $kpi = [
        'label'   => 'Collected revenue',
        'value'   => formatCurrency($revenue),
        'icon'    => 'bi-cash-stack',
        'tone'    => 'primary',
        'context' => 'Rent, sales and fees actually received',
        'spark'   => $ovSpark,
        'delta'   => $previousRevenue !== null ? reportDelta($revenue, $previousRevenue) : null,
        'delta_format'   => static fn(float $v): string => formatCurrency($v),
        'previous_label' => $previousRevenue !== null
            ? formatCurrency($previousRevenue) . ' previously'
            : null,
        'url'     => $ovLink('financial'),
    ];
    require dirname(__DIR__) . '/_kpi.php';

    /* Occupancy. No comparison and no sparkline: see the note in
       ReportController::overviewData() — the numerator can be rebuilt from
       lease dates but the denominator cannot, and half a reconstruction is
       not a measurement. */
    $kpi = [
        'label'   => 'Occupancy',
        'value'   => reportPercent($occupancy['rate']),
        'icon'    => 'bi-house-check',
        'tone'    => 'success',
        'context' => $occupancy['rentable'] > 0
            ? sprintf(
                '%d of %d rentable %s occupied',
                $occupancy['occupied'],
                $occupancy['rentable'],
                $occupancy['rentable'] === 1 ? 'property' : 'properties'
            )
            : 'No rentable property in scope',
        'url'     => $ovLink('rentals'),
    ];
    require dirname(__DIR__) . '/_kpi.php';

    /* Occupied count. Deliberately not "active properties", which could mean
       the listing count, the lease count or the let count — three different
       numbers that a reader would have no way to tell apart. */
    $kpi = [
        'label'   => 'Occupied properties',
        'value'   => number_format($occupancy['occupied']),
        'icon'    => 'bi-buildings',
        'tone'    => 'info',
        'context' => sprintf(
            'of %d rentable · %d vacant · %d listings in total',
            $occupancy['rentable'],
            $occupancy['vacant'],
            $inventory['lifecycle']['active_listings']
        ),
        'url'     => $ovLink('properties'),
    ];
    require dirname(__DIR__) . '/_kpi.php';

    /* Arrears is a running balance rather than a period figure, so it has no
       previous-period equivalent and no time series — payment_schedules
       records the state a row is in now, not the state it was in in July. */
    $kpi = [
        'label'   => 'Outstanding arrears',
        'value'   => formatCurrency($ledger['arrears']),
        'icon'    => 'bi-exclamation-triangle',
        'tone'    => $ledger['overdue_count'] > 0 ? 'danger' : 'success',
        'context' => $ledger['overdue_count'] > 0
            ? sprintf(
                '%d overdue %s · as at today, not for the period',
                $ledger['overdue_count'],
                $ledger['overdue_count'] === 1 ? 'instalment' : 'instalments'
            )
            : 'Nothing overdue',
        'url'     => $ovLink('payments'),
    ];
    require dirname(__DIR__) . '/_kpi.php';
    ?>
</div>

<!-- ── Row 2 · where the money came from ─────────────────────────── -->
<div class="rgrid rgrid--wide">
    <?php
    /* Revenue performance. With comparison on, the previous period rides as a
       second dataset aligned by *position* — its first bucket against this
       period's first bucket — because the windows are equal in length and
       drawn at the same grain. Aligning by calendar label instead would put
       1 August against 1 July and call the difference a trend. The previous
       period's own dates travel along as altLabels so a tooltip can say which
       days it is actually quoting. */
    $ovSeries = [[
        'label' => 'This period',
        'data'  => $ovSpark,
        'tone'  => '--primary',
    ]];

    if (!empty($compare) && $previousSeries) {
        /* No padding and no trimming. revenueComparisonSeries() folds the
           previous window onto *this* window's bucket offsets, so the two
           arrays are the same length by construction — bucket three is "days
           15 to 21 of the period" in both. The earlier version bucketed each
           by its own calendar and then cut the longer one to fit, which
           quietly dropped a week of real revenue out of the comparison
           whenever a quarter happened to start mid-week. */
        $ovSeries[] = [
            'label'     => 'Previous period',
            'data'      => array_map(static fn(array $p): float => (float) $p['total'], $previousSeries),
            'tone'      => '--text-subtle',
            'altLabels' => array_column($previousSeries, 'label'),
        ];
    }

    $chart = [
        'id'       => 'revenueTrend',
        'title'    => 'Revenue performance',
        'subtitle' => 'Collected revenue by ' . $window['grain']
                    . (!empty($compare) ? ', against the previous period of equal length' : ''),
        'type'     => 'line',
        'unit'     => 'currency',
        'labels'   => array_column($series, 'label'),
        'series'   => $ovSeries,
        'label_heading' => ucfirst($window['grain']),
        'empty'    => 'No payments were received in this period.',
        'height'   => 220,
        'filtered' => $ovFiltered,
        'resetUrl' => $ovReset,
    ];
    require dirname(__DIR__) . '/_chart_card.php';

    /* Revenue breakdown. Streams with nothing in them are dropped rather than
       drawn as a zero slice — a legend entry for a business line that took no
       money this period is noise, and a doughnut with an invisible segment
       invites someone to look for it. */
    $ovStreamNames = ['rental' => 'Rentals', 'sale' => 'Sales', 'reservation' => 'Reservations'];
    $ovStreamLabels = [];
    $ovStreamData   = [];
    $ovStreamTones  = [];
    $ovStreamTonePool = ['rental' => '--primary', 'sale' => '--success', 'reservation' => '--purple'];
    foreach ($ovStreamNames as $ovKey => $ovName) {
        if ((float) $streams[$ovKey] > 0) {
            $ovStreamLabels[] = $ovName;
            $ovStreamData[]   = (float) $streams[$ovKey];
            $ovStreamTones[]  = $ovStreamTonePool[$ovKey];
        }
    }

    $chart = [
        'id'       => 'revenueMix',
        'title'    => 'Revenue breakdown',
        'subtitle' => 'By the contract the money was taken against',
        'type'     => 'doughnut',
        'unit'     => 'currency',
        'labels'   => $ovStreamLabels,
        'series'   => [['label' => 'Collected', 'data' => $ovStreamData, 'tones' => $ovStreamTones]],
        'label_heading' => 'Source',
        'empty'    => 'No revenue was collected in this period, so there is nothing to break down.',
        'height'   => 220,
        'filtered' => $ovFiltered,
        'resetUrl' => $ovReset,
        'share'    => true,
    ];
    require dirname(__DIR__) . '/_chart_card.php';
    ?>
</div>

<!-- ── Row 3 · the portfolio, and what to do about it ────────────── -->
<div class="rgrid rgrid--wide">
    <?php
    /* Commercial state, not recorded status. Each slice is proved by a record
       — a live lease, an unexpired hold, a completed sale — rather than by
       properties.status, which the audit found is not written when a lease is
       signed. The register's own account of itself still exists and still
       disagrees; where it does, the data-quality panel above counts it. */
    $ovCommercial = [
        ['label' => 'Occupied', 'value' => $inventory['commercial']['occupied'], 'tone' => '--success'],
        ['label' => 'Vacant',   'value' => $inventory['commercial']['vacant'],   'tone' => '--text-subtle'],
        ['label' => 'Reserved', 'value' => $inventory['commercial']['reserved'], 'tone' => '--warning'],
        ['label' => 'Sold',     'value' => $inventory['commercial']['sold'],     'tone' => '--purple'],
    ];
    // A state nothing is in is not drawn. Four legend entries where two apply
    // makes the reader hunt for slices that were never there.
    $ovCommercial = array_values(array_filter($ovCommercial, static fn(array $r): bool => (int) $r['value'] > 0));

    $chart = [
        'id'       => 'portfolioMix',
        'title'    => 'Portfolio distribution',
        'subtitle' => 'Commercial state, derived from leases, reservations and completed sales',
        'type'     => 'doughnut',
        'unit'     => 'number',
        'labels'   => array_column($ovCommercial, 'label'),
        'series'   => [[
            'label' => 'Properties',
            'data'  => array_map('intval', array_column($ovCommercial, 'value')),
            'tones' => array_column($ovCommercial, 'tone'),
        ]],
        'label_heading' => 'State',
        'empty'    => 'There are no properties in scope for the current filters.',
        'height'   => 220,
        'filtered' => $ovFiltered,
        'resetUrl' => $ovReset,
        'share'    => true,
        'footnote' => 'Vacant counts rentable properties with no live lease. Sale-only '
                    . 'listings are not rentable and are not counted as vacant.',
    ];
    require dirname(__DIR__) . '/_chart_card.php';

    require dirname(__DIR__) . '/_insights.php';
    ?>
</div>

<!-- ── Row 4 · which properties actually earned ──────────────────── -->
<?php require dirname(__DIR__) . '/_top_properties.php'; ?>
