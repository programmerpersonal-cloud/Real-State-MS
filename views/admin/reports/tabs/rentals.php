<?php
/**
 * Rentals — the tenancies, and the rent they are owed.
 *
 * Two clocks run on this report and it keeps them apart. The tenancy figures
 * — how many are active, what the rent roll is, when they end — describe
 * today and do not move with the reporting window. The rent ledger figures
 * do: expected and settled are bounded by the window on the due-date axis,
 * which is what makes the collection rate a like-for-like measure.
 *
 * Nothing here is re-derived. Occupancy comes from the approved lease-based
 * definition, the ledger from rentLedger(); this report adds the tenancy
 * dimension around them and does not invent a second version of either.
 *
 * Vars from ReportController::rentalsData().
 */
$rnFiltered = reportFilterCount($filters) > 0;
$rnCarry    = !empty($compare) ? ['compare' => '1'] : [];
$rnReset    = reportUrl($window, [], ['tab' => 'rentals'] + $rnCarry);
$rnLink     = static fn(string $tab): string => reportUrl($window, $filters, ['tab' => $tab] + $rnCarry);

$rnExpected  = (float) $ledger['expected'];
$rnSettled   = (float) $ledger['settled_on_ledger'];
$rnArrears   = (float) $ledger['arrears'];
$rnOutstand  = (float) $ledger['outstanding'];
$rnNotYetDue = (float) ($ledger['not_yet_due'] ?? 0);

$rnBucket = static function (array $rnExpiry, string $rnKey): int {
    foreach ($rnExpiry as $rnB) {
        if ($rnB['key'] === $rnKey) { return (int) $rnB['count']; }
    }
    return 0;
};
$rnSoon = $rnBucket($expiry, 'd7') + $rnBucket($expiry, 'd30') + $rnBucket($expiry, 'd60');
$rnGone = $rnBucket($expiry, 'expired');
?>

<?php require dirname(__DIR__) . '/_data_quality.php'; ?>
<?php require dirname(__DIR__) . '/_rental_quality.php'; ?>

<?php /* the tenancy book and what it is owed */ ?>
<?php $section = [
    'title' => 'Occupancy and rent roll',
    'desc'  => 'The tenancy book and what it is owed.',
]; require dirname(__DIR__) . '/_section.php'; ?>
<div class="kpis kpis--six">
    <?php
    $kpi = [
        'label'   => 'Active leases',
        'value'   => number_format((int) $summary['active']),
        'icon'    => 'bi-file-earmark-text',
        'tone'    => 'primary',
        'context' => $summary['average_rent'] !== null
            ? formatCurrency((float) $summary['rent_roll']) . ' rent roll · avg '
              . formatCurrency((float) $summary['average_rent'])
            : 'No tenancy running',
    ];
    require dirname(__DIR__) . '/_kpi.php';

    /* From leases, never from properties.status — the approved definition. */
    $kpi = [
        'label'   => 'Occupancy',
        'value'   => reportPercent($occupancy['rate']),
        'icon'    => 'bi-house-check',
        'tone'    => 'success',
        'context' => (int) $occupancy['rentable'] > 0
            ? sprintf(
                '%d let of %d rentable · %d vacant',
                (int) $occupancy['occupied'], (int) $occupancy['rentable'], (int) $occupancy['vacant']
            )
            : 'No rentable property in scope',
        'url'     => $rnLink('properties'),
    ];
    require dirname(__DIR__) . '/_kpi.php';

    $kpi = [
        'label'   => 'Expected rent',
        'value'   => formatCurrency($rnExpected),
        'icon'    => 'bi-calendar-check',
        'tone'    => 'info',
        'context' => 'Scheduled to fall due in ' . $window['label'],
    ];
    require dirname(__DIR__) . '/_kpi.php';

    /* Unavailable rather than 0% when nothing was scheduled — a period with
       no rent due has no collection rate, and printing zero would report a
       failure that never happened. */
    $kpi = [
        'label'   => 'Collection rate',
        'value'   => reportPercent($ledger['collection_rate']),
        'icon'    => 'bi-percent',
        'tone'    => $ledger['collection_rate'] === null ? 'info'
            : ($ledger['collection_rate'] >= 90 ? 'success'
              : ($ledger['collection_rate'] >= 70 ? 'warning' : 'danger')),
        'context' => $rnExpected > 0
            ? formatCurrency($rnSettled) . ' settled of ' . formatCurrency($rnExpected)
            : 'No rent scheduled in this period',
    ];
    require dirname(__DIR__) . '/_kpi.php';

    $kpi = [
        'label'   => 'Outstanding rent',
        'value'   => formatCurrency($rnOutstand),
        'icon'    => 'bi-hourglass-split',
        'tone'    => 'purple',
        'context' => $rnNotYetDue > 0
            ? formatCurrency($rnNotYetDue) . ' of it not yet due · '
              . formatCurrency($rnArrears) . ' in arrears'
            : 'All of it has fallen due',
    ];
    require dirname(__DIR__) . '/_kpi.php';

    /* An expired lease is not "expiring soon" — it has already gone, which is
       a different and more urgent problem. Counted separately. */
    $kpi = [
        'label'   => 'Expiring soon',
        'value'   => number_format($rnSoon),
        'icon'    => 'bi-calendar-event',
        'tone'    => $rnGone > 0 ? 'danger' : ($rnSoon > 0 ? 'warning' : 'success'),
        'context' => $rnGone > 0
            ? sprintf('within 60 days · %d already expired', $rnGone)
            : ($rnSoon > 0 ? 'Tenancies ending within 60 days' : 'Nothing ending within 60 days'),
    ];
    require dirname(__DIR__) . '/_kpi.php';
    ?>
</div>

<div class="rnote" role="note">
    <span class="rnote__icon" aria-hidden="true"><i class="bi bi-clock-history"></i></span>
    <div class="rnote__body">
        <p class="rnote__title">Two clocks on this report</p>
        Lease counts, occupancy, the rent roll and expiry describe the book
        <strong>as it stands today</strong> and do not move with the period.
        Expected, settled and the collection rate are bounded by
        <strong><?= sanitize($window['label']) ?></strong> on the date rent fell due.
        Outstanding and arrears are running balances across the whole tenancy.
    </div>
</div>

<?php /* the rent ledger over time */ ?>
<?php $section = [
    'title' => 'Collection performance',
    'desc'  => 'Rent falling due against rent settled, on the date it was due.',
]; require dirname(__DIR__) . '/_section.php'; ?>
<div class="rgrid rgrid--wide">
    <?php
    $rnExpectedSeries = array_map(static fn(array $p): float => (float) $p['total'], $ledgerSeries['expected']);
    $rnSettledSeries  = array_map(static fn(array $p): float => (float) $p['total'], $ledgerSeries['settled']);

    $chart = [
        'id'       => 'rentExpectedSettled',
        'title'    => 'Expected vs settled rent',
        'subtitle' => 'Scheduled rent by ' . $window['grain'] . ', dated by when it fell due',
        'type'     => 'bar',
        'unit'     => 'currency',
        'labels'   => array_column($ledgerSeries['expected'], 'label'),
        'series'   => [
            ['label' => 'Expected', 'data' => $rnExpectedSeries, 'tone' => '--text-subtle'],
            ['label' => 'Settled',  'data' => $rnSettledSeries,  'tone' => '--success'],
        ],
        'label_heading' => ucfirst($window['grain']),
        'empty'    => 'No rent was scheduled to fall due in this period.',
        'size'   => 'feature',
        'filtered' => $rnFiltered,
        'resetUrl' => $rnReset,
    ];
    require dirname(__DIR__) . '/_chart_card.php';

    /* Null, not zero, where nothing was scheduled — the distinction between
       "nothing was due" and "nothing was collected". */
    $rnRate = [];
    foreach ($rnExpectedSeries as $rnI => $rnDue) {
        $rnRate[] = $rnDue > 0 ? round(($rnSettledSeries[$rnI] ?? 0) / $rnDue * 100, 1) : null;
    }

    $chart = [
        'id'       => 'rentCollection',
        'title'    => 'Collection performance',
        'subtitle' => 'Share of scheduled rent settled, by ' . $window['grain'],
        'type'     => 'line',
        'unit'     => 'percent',
        'labels'   => array_column($ledgerSeries['expected'], 'label'),
        'series'   => [['label' => 'Settled', 'data' => $rnRate, 'tone' => '--primary']],
        'label_heading' => ucfirst($window['grain']),
        'empty'    => 'No rent fell due in this period, so there is no collection rate to plot.',
        'size'   => 'feature',
        'filtered' => $rnFiltered,
        'resetUrl' => $rnReset,
        'footnote' => 'Periods where no rent fell due are left blank rather than drawn at '
                    . 'zero — nothing was scheduled, so nothing was missed.',
    ];
    require dirname(__DIR__) . '/_chart_card.php';
    ?>
</div>

<?php /* occupancy, expiry, and what is owed */ ?>
<?php $section = [
    'title' => 'Lease health',
    'desc'  => 'Occupancy, expiry and the shape of the book.',
]; require dirname(__DIR__) . '/_section.php'; ?>
<div class="rgrid rgrid--wide">
    <?php
    $rnOcc = array_values(array_filter([
        ['label' => 'Occupied', 'value' => (int) $occupancy['occupied'], 'tone' => '--success'],
        ['label' => 'Vacant',   'value' => (int) $occupancy['vacant'],   'tone' => '--text-subtle'],
    ], static fn(array $r): bool => $r['value'] > 0));

    $chart = [
        'id'       => 'rentOccupancy',
        'title'    => 'Occupancy status',
        'subtitle' => 'Rentable properties, let or standing empty',
        'type'     => 'doughnut',
        'unit'     => 'number',
        'labels'   => array_column($rnOcc, 'label'),
        'series'   => [[
            'label' => 'Properties',
            'data'  => array_column($rnOcc, 'value'),
            'tones' => array_column($rnOcc, 'tone'),
        ]],
        'label_heading' => 'State',
        'empty'    => 'No rentable property is in scope for the current filters.',
        'size'   => 'standard',
        'share'    => true,
        'filtered' => $rnFiltered,
        'resetUrl' => $rnReset,
        'footnote' => 'Occupied means a live lease on the property. The register\'s own '
                    . 'status column is not consulted — the audit found it is not written '
                    . 'when a tenancy starts.',
    ];
    require dirname(__DIR__) . '/_chart_card.php';

    $rnExpiryDrawn = array_values(array_filter($expiry, static fn(array $r): bool => (int) $r['count'] > 0));

    $chart = [
        'id'         => 'rentExpiry',
        'title'      => 'Lease expiry',
        'subtitle'   => 'When the active tenancies run out',
        'type'       => 'bar',
        'unit'       => 'number',
        'horizontal' => true,
        'labels'     => array_column($rnExpiryDrawn, 'label'),
        'series'     => [[
            'label' => 'Leases',
            'data'  => array_map('intval', array_column($rnExpiryDrawn, 'count')),
            'tone'  => '--primary',
        ]],
        'label_heading' => 'Window',
        'empty'      => 'No active tenancy is in scope for the current filters.',
        'size'     => 'standard',
        'filtered'   => $rnFiltered,
        'resetUrl'   => $rnReset,
        'footnote'   => 'An already-expired lease is counted on its own line, not as '
                      . '"expiring soon" — it has run out rather than being about to.',
    ];
    require dirname(__DIR__) . '/_chart_card.php';
    ?>
</div>

<?php /* the outstanding position, and the insights */ ?>
<?php $section = [
    'title' => 'Outstanding and attention',
    'desc'  => 'The unpaid position, and what stands out in the tenancies.',
]; require dirname(__DIR__) . '/_section.php'; ?>
<div class="rgrid rgrid--wide">
    <?php
    $chart = [
        'id'         => 'rentOutstanding',
        'title'      => 'Outstanding rent',
        'subtitle'   => 'How much of what is owed is actually late',
        'type'       => 'bar',
        'unit'       => 'currency',
        'horizontal' => true,
        'stacked'    => true,
        'labels'     => ['Scheduled balance'],
        'series'     => [
            ['label' => 'In arrears',  'data' => [$rnArrears],   'tone' => '--danger'],
            ['label' => 'Not yet due', 'data' => [$rnNotYetDue], 'tone' => '--info'],
        ],
        'label_heading' => 'Position',
        'empty'      => 'Nothing is outstanding — every scheduled instalment has been settled.',
        'size'     => 'compact',
        'filtered'   => $rnFiltered,
        'resetUrl'   => $rnReset,
        'footnote'   => 'Outstanding is arrears plus not-yet-due. Both are owed; only the '
                      . 'first is late, and only the first is worth chasing today.',
    ];
    require dirname(__DIR__) . '/_chart_card.php';

    require dirname(__DIR__) . '/_insights.php';
    ?>
</div>

<?php /* the queue, then the book */ ?>
<?php $section = [
    'title' => 'Detailed records',
    'desc'  => 'Tenancies ending soon, then the book in full.',
]; require dirname(__DIR__) . '/_section.php'; ?>
<?php
$rnMode = 'attention';
require dirname(__DIR__) . '/_lease_table.php';

$rnMode = 'active';
require dirname(__DIR__) . '/_lease_table.php';
?>
