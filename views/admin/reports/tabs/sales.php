<?php
/**
 * Sales — the deal book, and the holds on property.
 *
 * Four things this report refuses to run together, because everyday language
 * does and the money is very different in each case:
 *
 *   listed for sale   inventory intent. Lives on the Properties report.
 *   pending sale      an intention with a price on it. Not revenue.
 *   completed sale    a transaction that happened. Contract value.
 *   reservation       a hold with a deposit against it. Neither a sale nor
 *                     earnings — the deposit is money held.
 *
 * And one more distinction inside the third: a completed sale's amount is
 * *contract value*, not cash. Cash is whatever `payments` recorded against
 * the sale under the approved revenue definition. Both appear in the
 * register, side by side, and are never added together.
 *
 * Vars from ReportController::salesData().
 */
$slFiltered = reportFilterCount($filters) > 0;
$slCarry    = !empty($compare) ? ['compare' => '1'] : [];
$slReset    = reportUrl($window, [], ['tab' => 'sales'] + $slCarry);
$slLink     = static fn(string $tab): string => reportUrl($window, $filters, ['tab' => $tab] + $slCarry);
?>

<?php require dirname(__DIR__) . '/_data_quality.php'; ?>
<?php require dirname(__DIR__) . '/_sales_quality.php'; ?>

<?php /* the deal book in six figures */ ?>
<?php $section = [
    'title' => 'Deal book summary',
    'desc'  => 'Completed sales, pipeline and holds as they stand.',
]; require dirname(__DIR__) . '/_section.php'; ?>
<div class="kpis kpis--six">
    <?php
    $kpi = [
        'label'   => 'Deals recorded',
        'value'   => number_format((int) $summary['total']),
        'icon'    => 'bi-briefcase',
        'tone'    => 'primary',
        'context' => formatCurrency((float) $summary['total_value']) . ' across every status',
        'delta'   => $previous !== null
            ? reportDelta((float) $summary['total'], (float) $previous['total'])
            : null,
        'delta_format'   => static fn(float $v): string => number_format($v) . ' deals',
        'previous_label' => $previous !== null
            ? number_format((int) $previous['total']) . ' previously'
            : null,
    ];
    require dirname(__DIR__) . '/_kpi.php';

    /* Contract value of sales that actually completed. Not cash — the
       register carries the collected column for that. */
    $kpi = [
        'label'   => 'Completed value',
        'good'    => 'up',
        'value'   => formatCurrency((float) $summary['completed_value']),
        'icon'    => 'bi-cash-stack',
        'tone'    => (float) $summary['completed_value'] > 0 ? 'success' : 'info',
        'context' => 'Contract value of completed sales — not cash received',
        'delta'   => $previous !== null
            ? reportDelta((float) $summary['completed_value'], (float) $previous['completed_value'])
            : null,
        'delta_format'   => static fn(float $v): string => formatCurrency($v),
        'previous_label' => $previous !== null
            ? formatCurrency((float) $previous['completed_value']) . ' previously'
            : null,
    ];
    require dirname(__DIR__) . '/_kpi.php';

    $kpi = [
        'label'   => 'Completed sales',
        'good'    => 'up',
        'value'   => number_format((int) $summary['completed']),
        'icon'    => 'bi-check-circle',
        'tone'    => (int) $summary['completed'] > 0 ? 'success' : 'info',
        'context' => $summary['average'] !== null
            ? 'Averaging ' . formatCurrency((float) $summary['average']) . ' each'
            : 'No sale has completed in this period',
        'delta'   => $previous !== null
            ? reportDelta((float) $summary['completed'], (float) $previous['completed'])
            : null,
        'delta_format'   => static fn(float $v): string => number_format($v) . ' sales',
        'previous_label' => $previous !== null
            ? number_format((int) $previous['completed']) . ' previously'
            : null,
    ];
    require dirname(__DIR__) . '/_kpi.php';

    $kpi = [
        'label'   => 'Pending',
        'value'   => number_format((int) $summary['pending']),
        'icon'    => 'bi-hourglass-split',
        'tone'    => (int) $summary['pending'] > 0 ? 'warning' : 'success',
        'context' => (int) $summary['pending'] > 0
            ? formatCurrency((float) $summary['pending_value']) . ' of value awaiting completion'
            : 'Nothing awaiting completion',
    ];
    require dirname(__DIR__) . '/_kpi.php';

    $kpi = [
        'label'   => 'Cancelled',
        'value'   => number_format((int) $summary['cancelled']),
        'icon'    => 'bi-x-circle',
        'tone'    => (int) $summary['cancelled'] > 0 ? 'danger' : 'success',
        'context' => (int) $summary['cancelled'] > 0
            ? formatCurrency((float) $summary['cancelled_value']) . ' of value fell through'
            : 'Nothing cancelled in this period',
    ];
    require dirname(__DIR__) . '/_kpi.php';

    /* Live means unexpired, whatever the status column says. A hold that ran
       out last month is not holding anything. */
    $kpi = [
        'label'   => 'Live reservations',
        'value'   => number_format((int) $reservations['live']),
        'icon'    => 'bi-bookmark-check',
        'tone'    => (int) $reservations['lapsed'] > 0 ? 'danger' : ((int) $reservations['live'] > 0 ? 'info' : 'success'),
        'context' => (int) $reservations['lapsed'] > 0
            ? sprintf(
                '%d lapsed but still marked active · %s held',
                (int) $reservations['lapsed'],
                formatCurrency((float) $reservations['lapsed_deposits'])
            )
            : ((int) $reservations['live'] > 0
                ? formatCurrency((float) $reservations['live_deposits']) . ' held on deposit'
                : 'No property is under an unexpired hold'),
    ];
    require dirname(__DIR__) . '/_kpi.php';
    ?>
</div>

<div class="rnote" role="note">
    <div class="notice__icon"><i class="bi bi-signpost-split" aria-hidden="true"></i></div>
    <div class="rnote__body">
        <p class="rnote__title">Four different things, kept apart</p>
        <strong>Listed for sale</strong> is inventory intent and lives on the Properties
        report. <strong>Pending</strong> is an intention with a price on it.
        <strong>Completed</strong> is a transaction that happened, measured in contract
        value rather than cash. <strong>Reserved</strong> is a hold with a deposit against
        it — neither a sale nor earnings. Deal figures cover
        <?= sanitize($window['label']) ?>; reservations describe today.
    </div>
</div>

<?php /* the pipeline and how it moved */ ?>
<?php $section = [
    'title' => 'Pipeline',
    'desc'  => 'How the deal book moved across the period.',
]; require dirname(__DIR__) . '/_section.php'; ?>
<div class="rgrid rgrid--wide">
    <?php
    /* Zeros kept, deliberately. On a pipeline an empty stage is the finding —
       "nothing completed" is worth printing, and a chart that drops the bar
       leaves the reader to notice an absence, which nobody does. */
    $chart = [
        'id'         => 'salesPipeline',
        'title'      => 'Sales pipeline',
        'subtitle'   => 'Deal value by status — the three the schema records, including the empty ones',
        'type'       => 'bar',
        'unit'       => 'currency',
        'horizontal' => true,
        'labels'     => array_column($pipeline, 'label'),
        'series'     => [[
            'label' => 'Value',
            'data'  => array_map(static fn(array $r): float => (float) $r['value'], $pipeline),
            'tone'  => '--primary',
        ]],
        'label_heading' => 'Status',
        'empty'      => 'No sale was recorded in this period.',
        'size'     => 'feature',
        'filtered'   => $slFiltered,
        'resetUrl'   => $slReset,
        'footnote'   => 'Pending, completed and cancelled are the only statuses this system '
                      . 'records. No further pipeline stages exist, and none are invented here.',
    ];
    require dirname(__DIR__) . '/_chart_card.php';

    $slRecorded  = array_map(static fn(array $p): float => (float) $p['total'], $salesSeries['recorded']);
    $slCompleted = array_map(static fn(array $p): float => (float) $p['total'], $salesSeries['completed']);

    $chart = [
        'id'       => 'salesTrend',
        'title'    => 'Sales value over time',
        'subtitle' => 'Deal value by ' . $window['grain'] . ', dated by the sale date',
        'type'     => 'bar',
        'unit'     => 'currency',
        'labels'   => array_column($salesSeries['recorded'], 'label'),
        'series'   => [
            ['label' => 'Recorded',  'data' => $slRecorded,  'tone' => '--text-subtle'],
            ['label' => 'Completed', 'data' => $slCompleted, 'tone' => '--success'],
        ],
        'label_heading' => ucfirst($window['grain']),
        'empty'    => 'No sale carries a date inside this period.',
        'size'   => 'feature',
        'filtered' => $slFiltered,
        'resetUrl' => $slReset,
        'footnote' => 'Recorded is every deal whatever its status; completed is the subset '
                    . 'that closed. A gap between the two is the pipeline, not lost revenue.',
    ];
    require dirname(__DIR__) . '/_chart_card.php';
    ?>
</div>

<?php /* what is being sold, and what is on hold */ ?>
<?php $section = [
    'title' => 'Composition and holds',
    'desc'  => 'What is being sold, and what is reserved.',
]; require dirname(__DIR__) . '/_section.php'; ?>
<div class="rgrid rgrid--wide">
    <?php
    $chart = [
        'id'         => 'salesCategory',
        'title'      => 'Deal value by category',
        'subtitle'   => 'What kind of property the book is made of',
        'type'       => 'bar',
        'unit'       => 'currency',
        'horizontal' => true,
        'labels'     => array_map(
            static fn(array $r): string => categoryLabel((string) $r['category']),
            $byCategory
        ),
        'series'     => [[
            'label' => 'Deal value',
            'data'  => array_map(static fn(array $r): float => (float) $r['value'], $byCategory),
            'tone'  => '--primary',
        ]],
        'label_heading' => 'Category',
        'empty'      => 'No sale was recorded in this period.',
        'size'     => 'standard',
        'share'      => true,
        'filtered'   => $slFiltered,
        'resetUrl'   => $slReset,
    ];
    require dirname(__DIR__) . '/_chart_card.php';

    /* Live versus lapsed, not by the status column. Both slices are holds the
       system still treats as standing; only one of them actually is. */
    $slResv = array_values(array_filter([
        ['label' => 'Live',      'value' => (int) $reservations['live'],      'tone' => '--success'],
        ['label' => 'Lapsed',    'value' => (int) $reservations['lapsed'],    'tone' => '--danger'],
        ['label' => 'Expired',   'value' => (int) $reservations['marked_expired'], 'tone' => '--text-subtle'],
        ['label' => 'Cancelled', 'value' => (int) $reservations['cancelled'], 'tone' => '--purple'],
    ], static fn(array $r): bool => $r['value'] > 0));

    $chart = [
        'id'       => 'reservationStatus',
        'title'    => 'Reservations',
        'subtitle' => 'Holds on property, by whether they still stand',
        'type'     => 'doughnut',
        'unit'     => 'number',
        'labels'   => array_column($slResv, 'label'),
        'series'   => [[
            'label' => 'Reservations',
            'data'  => array_column($slResv, 'value'),
            'tones' => array_column($slResv, 'tone'),
        ]],
        'label_heading' => 'State',
        'empty'    => 'No reservation has been recorded against a property in scope.',
        'size'   => 'standard',
        'share'    => true,
        'filtered' => $slFiltered,
        'resetUrl' => $slReset,
        'footnote' => '"Lapsed" is a hold still flagged active or confirmed whose expiry '
                    . 'date has passed. It is counted separately from live because the '
                    . 'property is not actually held any more.',
    ];
    require dirname(__DIR__) . '/_chart_card.php';
    ?>
</div>

<?php /* insights */ ?>
<?php $section = [
    'title' => 'Attention',
    'desc'  => 'What stands out in the deal book.',
]; require dirname(__DIR__) . '/_section.php'; ?>
<div class="rgrid rgrid--wide">
    <?php require dirname(__DIR__) . '/_insights.php'; ?>

    <section class="card rcard" aria-labelledby="sl-hold-title">
        <div class="card__header">
            <div class="rcard__titles">
                <h4 class="card__title" id="sl-hold-title">Money that is not sales revenue</h4>
                <p class="card__subtitle">Held against property, and excluded from every figure above</p>
            </div>
        </div>
        <div class="card__body card__body--flush">
            <dl class="datalist">
                <div class="datalist__row">
                    <dt>Deposits on live holds</dt>
                    <dd class="num"><?= sanitize(formatCurrency((float) $reservations['live_deposits'])) ?></dd>
                </div>
                <div class="datalist__row">
                    <dt>Deposits on lapsed holds</dt>
                    <dd class="num"><?= sanitize(formatCurrency((float) $reservations['lapsed_deposits'])) ?></dd>
                </div>
                <div class="datalist__row">
                    <dt>Pending deal value</dt>
                    <dd class="num"><?= sanitize(formatCurrency((float) $summary['pending_value'])) ?></dd>
                </div>
            </dl>
            <p class="rcard__footnote">
                A reservation deposit is money held against a property, not money earned —
                it belongs to the customer until the deal completes or the hold is released.
                Pending deal value is a price nobody has paid. Neither is revenue, and
                neither is counted in the completed figures above.
            </p>
        </div>
    </section>
</div>

<?php /* the reservation queue, then the register */ ?>
<?php $section = [
    'title' => 'Detailed records',
    'desc'  => 'The reservation queue, then the sales register.',
]; require dirname(__DIR__) . '/_section.php'; ?>
<?php require dirname(__DIR__) . '/_reservation_queue.php'; ?>
<?php require dirname(__DIR__) . '/_sales_register.php'; ?>
