<?php
/**
 * Properties — the portfolio, as it stands.
 *
 * Two things separate this report from every other one in the module.
 *
 * First, it is a *current-state* report. The schema records no history of
 * inventory — nothing says when a property was added, archived, approved or
 * changed type — so "how many properties did we hold in July" is a question
 * this database cannot answer. Every figure below except revenue describes
 * today, and the banner under the KPIs says so rather than letting a reader
 * assume the date picker moved them.
 *
 * Second, it keeps two ideas apart that a single column has been asked to
 * carry and cannot: what a property's record *says* about it, and what its
 * other records *prove*. The commercial state chart is derived from leases,
 * reservations and completed sales; the administration chart reads the
 * property row itself. Where the two disagree, the data-quality panel counts
 * it — and it does disagree, which is why the separation exists.
 *
 * Vars from ReportController::propertiesData().
 */
$poFiltered = reportFilterCount($filters) > 0;
$poCarry    = !empty($compare) ? ['compare' => '1'] : [];
$poReset    = reportUrl($window, [], ['tab' => 'properties'] + $poCarry);
$poLink     = static fn(string $tab): string => reportUrl($window, $filters, ['tab' => $tab] + $poCarry);
/* Every drill-down on this page carries the period, the comparison and the
   filters above, because it is built from the same window and filters the
   figures were. Nothing is copied across by hand. */
$poDrill = static fn(string $metric, string $key = ''): string
    => reportDrillUrl($window, $filters, 'properties', $metric, $key, $poCarry);
$poTotal    = (int) $state['total'];
?>

<?php require dirname(__DIR__) . '/_data_quality.php'; ?>

<?php /* the portfolio in six figures */ ?>
<?php $section = [
    'title' => 'Portfolio summary',
    'desc'  => 'The inventory as it stands today.',
]; require dirname(__DIR__) . '/_section.php'; ?>
<div class="kpis kpis--six">
    <?php
    /* No comparison on any of these. The previous-period column would have to
       be reconstructed from a history the schema does not keep, and repeating
       today's figure under a "previous" heading is the single most misleading
       thing this report could do. */
    $kpi = [
        'label'   => 'Total portfolio',
        'value'   => number_format((int) $inventory['lifecycle']['active_listings']),
        'icon'    => 'bi-buildings',
        'tone'    => 'primary',
        'context' => sprintf(
            '%d approved · %d archived · as at today',
            (int) $inventory['lifecycle']['active_listings'],
            (int) $inventory['lifecycle']['archived']
        ),
        'drill'   => $poDrill('total'),
    ];
    require dirname(__DIR__) . '/_kpi.php';

    $kpi = [
        'label'   => 'Occupancy',
        'value'   => reportPercent($occupancy['rate']),
        'icon'    => 'bi-house-check',
        'tone'    => 'success',
        'context' => (int) $occupancy['rentable'] > 0
            ? sprintf(
                '%d of %d rentable %s occupied',
                (int) $occupancy['occupied'],
                (int) $occupancy['rentable'],
                (int) $occupancy['rentable'] === 1 ? 'property' : 'properties'
            )
            : 'No rentable property in scope',
        'drill'   => $poDrill('occupancy'),
    ];
    require dirname(__DIR__) . '/_kpi.php';

    /* Available means no lease, no live hold and no completed sale — proved
       by those records, not by the word "available" sitting in a column that
       nothing maintains. */
    $kpi = [
        'label'   => 'Available',
        'value'   => number_format((int) $state['available']),
        'icon'    => 'bi-door-open',
        'tone'    => 'info',
        'context' => $poTotal > 0
            ? reportPercent(reportShare((float) $state['available'], (float) $poTotal)) . ' of approved inventory'
            : 'No approved inventory in scope',
        'drill'   => $poDrill('state', 'available'),
    ];
    require dirname(__DIR__) . '/_kpi.php';

    $kpi = [
        'label'   => 'Under reservation',
        'value'   => number_format((int) $state['reserved']),
        'icon'    => 'bi-bookmark-check',
        'tone'    => (int) $state['reserved'] > 0 ? 'warning' : 'success',
        'context' => (int) $state['reserved'] > 0
            ? 'Held on a reservation that has not expired'
            : 'No unexpired reservations',
        'drill'   => $poDrill('state', 'reserved'),
    ];
    require dirname(__DIR__) . '/_kpi.php';

    /* Completed sales only. The audit found one property whose record says
       "sold" with no completed sale behind it; that is a data-quality finding
       and is counted as one, not as a sale. */
    $kpi = [
        'label'   => 'Sold',
        'value'   => number_format((int) $state['sold']),
        'icon'    => 'bi-tag',
        'tone'    => 'purple',
        'context' => 'Properties with a completed sale on record',
        'drill'   => $poDrill('state', 'sold'),
    ];
    require dirname(__DIR__) . '/_kpi.php';

    /* Administrative, not commercial. A listing waiting on approval is not
       inventory somebody can rent. */
    $kpi = [
        'label'   => 'Awaiting approval',
        'value'   => number_format((int) $inventory['lifecycle']['pending_approval']),
        'icon'    => 'bi-hourglass-split',
        'tone'    => (int) $inventory['lifecycle']['pending_approval'] > 0 ? 'warning' : 'success',
        'context' => (int) $inventory['lifecycle']['pending_approval'] > 0
            ? 'Not live inventory until an administrator signs it off'
            : 'Nothing waiting on approval',
        'drill'   => $poDrill('lifecycle', 'pending'),
    ];
    require dirname(__DIR__) . '/_kpi.php';
    ?>
</div>

<?php /* Said once, plainly, under the figures it applies to. Without it a
         reader changes the period, sees nothing move, and concludes the
         report is broken. */ ?>
<div class="rnote" role="note">
    <span class="rnote__icon" aria-hidden="true"><i class="bi bi-clock-history"></i></span>
    <div class="rnote__body">
        <p class="rnote__title">These figures describe the portfolio as it stands today</p>
        The database records no history of inventory — when a property was added,
        archived, approved or re-typed is not stored — so portfolio counts do not move
        with the reporting period and cannot be compared with a previous one.
        <strong>Revenue is the exception</strong>: it is bounded by
        <?= sanitize($window['label']) ?> wherever it appears below.
    </div>
</div>

<?php /* what the records prove, and what the record says */ ?>
<?php $section = [
    'title' => 'Commercial state',
    'desc'  => 'What the records prove, beside what the register claims.',
]; require dirname(__DIR__) . '/_section.php'; ?>
<div class="rgrid rgrid--wide">
    <?php
    /* Commercial state: each slice proved by a lease, a reservation or a
       completed sale. States with nothing in them are dropped from the
       picture rather than drawn as invisible slices. */
    $poCommercial = [
        ['label' => 'Available', 'key' => 'available',       'value' => (int) $state['available'], 'tone' => '--info'],
        ['label' => 'Occupied',  'key' => 'state_occupied',  'value' => (int) $state['occupied'],  'tone' => '--success'],
        ['label' => 'Reserved',  'key' => 'reserved',        'value' => (int) $state['reserved'],  'tone' => '--warning'],
        ['label' => 'Sold',      'key' => 'sold',            'value' => (int) $state['sold'],      'tone' => '--purple'],
    ];
    $poCommercial = array_values(array_filter($poCommercial, static fn(array $r): bool => $r['value'] > 0));

    $chart = [
        'id'       => 'portfolioCommercial',
        'title'    => 'Commercial state',
        'subtitle' => 'Derived from leases, unexpired reservations and completed sales',
        'type'     => 'doughnut',
        'unit'     => 'number',
        'labels'   => array_column($poCommercial, 'label'),
        'series'   => [[
            'label' => 'Properties',
            'data'  => array_column($poCommercial, 'value'),
            'tones' => array_column($poCommercial, 'tone'),
        ]],
        'label_heading' => 'State',
        'empty'    => 'No approved property is in scope for the current filters.',
        'drill'    => ['metric' => 'state', 'keys' => array_column($poCommercial, 'key')],
        'size'   => 'feature',
        'share'    => true,
        'filtered' => $poFiltered,
        'resetUrl' => $poReset,
        'footnote' => 'A property is counted once, in the first state that applies: a '
                    . 'completed sale, then a live lease, then an unexpired hold, then '
                    . 'available. None of this reads properties.status.',
    ];
    require dirname(__DIR__) . '/_chart_card.php';

    /* Administration: this one *does* read the property row, because approval
       and archival are the row's own business and are maintained. Kept in a
       separate chart so "approved" is never mistaken for "occupied". */
    $poAdmin = [
        ['label' => 'Approved',         'key' => 'approved',  'value' => (int) $inventory['lifecycle']['active_listings'],  'tone' => '--success'],
        ['label' => 'Awaiting approval','key' => 'pending',   'value' => (int) $inventory['lifecycle']['pending_approval'], 'tone' => '--warning'],
        ['label' => 'Rejected',         'key' => 'rejected',  'value' => (int) $inventory['lifecycle']['rejected'],         'tone' => '--danger'],
        ['label' => 'Withdrawn',        'key' => 'withdrawn', 'value' => (int) $inventory['lifecycle']['withdrawn'],        'tone' => '--text-subtle'],
        ['label' => 'Archived',         'key' => 'archived',  'value' => (int) $inventory['lifecycle']['archived'],         'tone' => '--purple'],
    ];
    $poAdmin = array_values(array_filter($poAdmin, static fn(array $r): bool => $r['value'] > 0));

    $chart = [
        'id'         => 'portfolioAdmin',
        'title'      => 'Administration',
        'subtitle'   => 'Approval and lifecycle state, from the property record itself',
        'type'       => 'bar',
        'unit'       => 'number',
        'horizontal' => true,
        'labels'     => array_column($poAdmin, 'label'),
        'series'     => [[
            'label' => 'Properties',
            'data'  => array_column($poAdmin, 'value'),
            'tone'  => '--primary',
        ]],
        'label_heading' => 'State',
        'empty'      => 'No property is in scope for the current filters.',
        'drill'      => ['metric' => 'lifecycle', 'keys' => array_column($poAdmin, 'key')],
        'size'     => 'feature',
        'filtered'   => $poFiltered,
        'resetUrl'   => $poReset,
        'footnote'   => 'Administrative state, not commercial. A property can be approved '
                      . 'and occupied, approved and empty, or approved and already sold — '
                      . 'this chart says nothing about which.',
    ];
    require dirname(__DIR__) . '/_chart_card.php';
    ?>
</div>

<?php /* what the portfolio is made of */ ?>
<?php $section = [
    'title' => 'Composition',
    'desc'  => 'What the portfolio is made of, and what it is listed for.',
]; require dirname(__DIR__) . '/_section.php'; ?>
<div class="rgrid rgrid--wide">
    <?php
    $chart = [
        'id'         => 'portfolioComposition',
        'title'      => 'Composition',
        'subtitle'   => 'Approved inventory by category',
        'type'       => 'bar',
        'unit'       => 'number',
        'horizontal' => true,
        'labels'     => array_map(
            static fn(array $r): string => categoryLabel($r['key']),
            $composition
        ),
        'series'     => [[
            'label' => 'Properties',
            'data'  => array_map(static fn(array $r): int => (int) $r['properties'], $composition),
            'tone'  => '--primary',
        ]],
        'label_heading' => 'Category',
        'drill'      => ['metric' => 'category', 'keys' => array_column($composition, 'key')],
        'empty'      => 'No approved property is in scope for the current filters.',
        'size'     => 'standard',
        'share'      => true,
        'filtered'   => $poFiltered,
        'resetUrl'   => $poReset,
    ];
    require dirname(__DIR__) . '/_chart_card.php';

    /* Listing intent, which is not the same question as commercial state.
       A property listed for sale that has not sold is still sale inventory;
       counting it as a completed sale is exactly the confusion this chart is
       kept separate to avoid. */
    $chart = [
        'id'       => 'portfolioIntent',
        'title'    => 'Rent vs sale inventory',
        'subtitle' => 'How the portfolio is intended to be marketed',
        'type'     => 'doughnut',
        'unit'     => 'number',
        'labels'   => array_map(
            static fn(array $r): string => ['rent' => 'For rent', 'sale' => 'For sale', 'both' => 'Rent or sale'][$r['key']] ?? $r['label'],
            $listingIntent
        ),
        'series'   => [[
            'label' => 'Properties',
            'data'  => array_map(static fn(array $r): int => (int) $r['properties'], $listingIntent),
            'tones' => array_map(
                static fn(array $r): string => ['rent' => '--primary', 'sale' => '--success', 'both' => '--purple'][$r['key']] ?? '--primary',
                $listingIntent
            ),
        ]],
        'drill'    => ['metric' => 'intent', 'keys' => array_column($listingIntent, 'key')],
        'label_heading' => 'Intent',
        'empty'    => 'No approved property is in scope for the current filters.',
        'size'   => 'standard',
        'share'    => true,
        'filtered' => $poFiltered,
        'resetUrl' => $poReset,
        'footnote' => 'Intent, not outcome. "For sale" means listed for sale — whether it '
                    . 'has sold is the commercial state chart above.',
    ];
    require dirname(__DIR__) . '/_chart_card.php';
    ?>
</div>

<?php /* location, and what can honestly be said about it */ ?>
<?php $section = [
    'title' => 'Location and attention',
    'desc'  => 'Where the properties are, and what stands out.',
]; require dirname(__DIR__) . '/_section.php'; ?>
<div class="rgrid rgrid--wide">
    <?php require dirname(__DIR__) . '/_portfolio_locations.php'; ?>
    <?php require dirname(__DIR__) . '/_insights.php'; ?>
</div>

<?php /* one row per property */ ?>
<?php $section = [
    'title' => 'Detailed records',
    'desc'  => 'One row per property in scope.',
]; require dirname(__DIR__) . '/_section.php'; ?>
<?php require dirname(__DIR__) . '/_portfolio_table.php'; ?>
