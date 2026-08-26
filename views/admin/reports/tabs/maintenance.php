<?php
/**
 * Maintenance — the work queue.
 *
 * The current-state / period split is sharper here than on any other report,
 * because a backlog is the most tempting thing to compare and the least
 * possible one. `maintenance_requests` holds one status per row and no
 * history of it, so nothing records what was open in July. The workload, its
 * age and its priority mix therefore describe today and carry no comparison;
 * requests raised and requests completed are period figures and do.
 *
 * Resolution time is offered only where it can be measured — created_at to
 * completion_date, the one pair of columns in this table that means what it
 * needs to mean. `updated_at` is deliberately never used for it: that column
 * moves on any edit, so a request touched yesterday would report as resolved
 * yesterday whatever actually happened.
 *
 * Vars from ReportController::maintenanceData().
 */
$mtFiltered = reportFilterCount($filters) > 0;
$mtCarry    = !empty($compare) ? ['compare' => '1'] : [];
$mtReset    = reportUrl($window, [], ['tab' => 'maintenance'] + $mtCarry);

$mtBucket = static function (array $mtB, string $mtKey): int {
    foreach ($mtB as $mtR) {
        if ($mtR['key'] === $mtKey) { return (int) $mtR['requests']; }
    }
    return 0;
};
$mtOld = $mtBucket($ageing['buckets'], 'd15');
?>

<?php require dirname(__DIR__) . '/_data_quality.php'; ?>
<?php require dirname(__DIR__) . '/_maintenance_quality.php'; ?>

<!-- ── Row 1 · the queue as it stands ────────────────────────────── -->
<div class="kpis kpis--six">
    <?php
    $kpi = [
        'label'   => 'Requests raised',
        'value'   => number_format((int) $summary['raised']),
        'icon'    => 'bi-tools',
        'tone'    => 'primary',
        'context' => 'Logged in ' . $window['label'] . ' — a period figure',
        'spark'   => array_map(static fn(array $b): float => (float) $b['total'], $maintSeries['raised']),
        'delta'   => $previous !== null
            ? reportDelta((float) $summary['raised'], (float) $previous['raised'])
            : null,
        'delta_format'   => static fn(float $v): string => number_format($v) . ' requests',
        'previous_label' => $previous !== null
            ? number_format((int) $previous['raised']) . ' previously'
            : null,
    ];
    require dirname(__DIR__) . '/_kpi.php';

    /* Current state: no comparison, because nothing records what was open
       last month. */
    $kpi = [
        'label'   => 'Open now',
        'value'   => number_format((int) $summary['open']),
        'icon'    => 'bi-inbox',
        'tone'    => (int) $summary['open'] > 0 ? 'warning' : 'success',
        'context' => (int) $summary['open'] > 0
            ? sprintf(
                '%d awaiting triage · %d assigned · %d in progress',
                (int) $summary['awaiting'], (int) $summary['assigned'], (int) $summary['in_progress']
            )
            : 'Nothing outstanding',
    ];
    require dirname(__DIR__) . '/_kpi.php';

    $kpi = [
        'label'   => 'In progress',
        'value'   => number_format((int) $summary['in_progress']),
        'icon'    => 'bi-hammer',
        'tone'    => 'info',
        'context' => (int) $summary['in_progress'] > 0
            ? 'Work has started on these'
            : 'No request has been started',
    ];
    require dirname(__DIR__) . '/_kpi.php';

    $kpi = [
        'label'   => 'Completed',
        'value'   => number_format((int) $summary['completed']),
        'icon'    => 'bi-check2-circle',
        'tone'    => (int) $summary['completed'] > 0 ? 'success' : 'info',
        'context' => sprintf('in %s · %d completed ever', $window['label'], (int) $summary['completed_ever']),
        'delta'   => $previous !== null
            ? reportDelta((float) $summary['completed'], (float) $previous['completed'])
            : null,
        'delta_format'   => static fn(float $v): string => number_format($v) . ' requests',
        'previous_label' => $previous !== null
            ? number_format((int) $previous['completed']) . ' previously'
            : null,
    ];
    require dirname(__DIR__) . '/_kpi.php';

    $kpi = [
        'label'   => 'High priority open',
        'value'   => number_format((int) $summary['open_urgent']),
        'icon'    => 'bi-exclamation-octagon',
        'tone'    => (int) $summary['open_urgent'] > 0 ? 'danger' : 'success',
        'context' => (int) $summary['open_urgent'] > 0
            ? 'Marked high or urgent and not yet closed'
            : 'No high or urgent work outstanding',
    ];
    require dirname(__DIR__) . '/_kpi.php';

    /* The honest refusal. No completed request carries a completion date, so
       there is nothing to average — and an average of nothing is the one
       number this report must never print. */
    $kpi = [
        'label'   => 'Average resolution',
        'value'   => !empty($resolution['available'])
            ? number_format((float) $resolution['average'], 1) . ' days'
            : 'Not available',
        'icon'    => 'bi-stopwatch',
        'tone'    => !empty($resolution['available']) ? 'purple' : 'info',
        'context' => !empty($resolution['available'])
            ? sprintf(
                'across %d completed · %d to %d days',
                (int) $resolution['resolved'], (int) $resolution['fastest'], (int) $resolution['slowest']
            )
            : 'No completed request carries a completion date',
    ];
    require dirname(__DIR__) . '/_kpi.php';
    ?>
</div>

<div class="notice notice--muted mb-3" role="note">
    <div class="notice__icon"><i class="bi bi-clock-history" aria-hidden="true"></i></div>
    <div class="notice__body">
        <div class="notice__title">What moves with the period, and what does not</div>
        <strong>Raised</strong> and <strong>completed</strong> are period figures and change
        with <?= sanitize($window['label']) ?>. <strong>Open</strong>, <strong>in progress</strong>,
        <strong>high priority open</strong> and request age describe the queue
        <strong>as it stands today</strong> — the database records no history of a request's
        status, so a backlog cannot be compared with a previous period and none is offered.
        Request age is measured from the date raised; this system defines no target response
        time, so nothing here is "overdue" — only old.
    </div>
</div>

<!-- ── Row 2 · intake against output, and where work sits ────────── -->
<div class="rgrid rgrid--wide">
    <?php
    $chart = [
        'id'       => 'maintActivity',
        'title'    => 'Maintenance activity',
        'subtitle' => 'Requests raised against requests completed, by ' . $window['grain'],
        'type'     => 'bar',
        'unit'     => 'number',
        'labels'   => array_column($maintSeries['raised'], 'label'),
        'series'   => [
            ['label' => 'Raised',    'data' => array_map(static fn(array $b): float => (float) $b['total'], $maintSeries['raised']),    'tone' => '--warning'],
            ['label' => 'Completed', 'data' => array_map(static fn(array $b): float => (float) $b['total'], $maintSeries['completed']), 'tone' => '--success'],
        ],
        'label_heading' => ucfirst($window['grain']),
        'empty'    => 'No maintenance request was raised or completed in this period.',
        'height'   => 220,
        'filtered' => $mtFiltered,
        'resetUrl' => $mtReset,
        'footnote' => 'Raised is dated by when the request was logged, completed by its '
                    . 'completion date. A gap between the two lines is the queue growing.',
    ];
    require dirname(__DIR__) . '/_chart_card.php';

    /* Zeros kept. Three requests all sitting at one stage with nothing before
       or after them is a queue that has stalled — and a chart that dropped the
       empty stages would show a single bar and say nothing at all. */
    $chart = [
        'id'         => 'maintStatus',
        'title'      => 'Where requests sit',
        'subtitle'   => 'Every status the workflow defines, including the empty ones',
        'type'       => 'bar',
        'unit'       => 'number',
        'horizontal' => true,
        'labels'     => array_column($statusMix, 'label'),
        'series'     => [[
            'label' => 'Requests',
            'data'  => array_map(static fn(array $r): int => (int) $r['requests'], $statusMix),
            'tone'  => '--primary',
        ]],
        'label_heading' => 'Status',
        'empty'      => 'No maintenance request is in scope for the current filters.',
        'height'     => 220,
        'filtered'   => $mtFiltered,
        'resetUrl'   => $mtReset,
        'footnote'   => 'Current state, not a period figure. Empty stages are shown rather '
                      . 'than dropped, because a workflow with everything stuck at one step '
                      . 'is only visible when the other steps are drawn.',
    ];
    require dirname(__DIR__) . '/_chart_card.php';
    ?>
</div>

<!-- ── Row 3 · urgency and how long it has waited ─────────────────── -->
<div class="rgrid rgrid--wide">
    <?php
    $mtPriorityDrawn = array_values(array_filter($priorityMix, static fn(array $r): bool => (int) $r['requests'] > 0));

    $chart = [
        'id'       => 'maintPriority',
        'title'    => 'Open work by priority',
        'subtitle' => 'What the outstanding queue is made of',
        'type'     => 'doughnut',
        'unit'     => 'number',
        'labels'   => array_column($mtPriorityDrawn, 'label'),
        'series'   => [[
            'label' => 'Open requests',
            'data'  => array_map(static fn(array $r): int => (int) $r['requests'], $mtPriorityDrawn),
            'tones' => array_column($mtPriorityDrawn, 'tone'),
        ]],
        'label_heading' => 'Priority',
        'empty'    => 'Nothing is open, so there is no priority mix to show.',
        'height'   => 220,
        'share'    => true,
        'filtered' => $mtFiltered,
        'resetUrl' => $mtReset,
        'footnote' => 'Open requests only. Priority on a closed request is history; on an '
                    . 'open one it is a decision about what to do next.',
    ];
    require dirname(__DIR__) . '/_chart_card.php';

    $chart = [
        'id'         => 'maintAgeing',
        'title'      => 'How long open work has waited',
        'subtitle'   => 'Age since the request was raised',
        'type'       => 'bar',
        'unit'       => 'number',
        'horizontal' => true,
        'labels'     => array_column($ageing['buckets'], 'label'),
        'series'     => [[
            'label' => 'Open requests',
            'data'  => array_map(static fn(array $r): int => (int) $r['requests'], $ageing['buckets']),
            'tone'  => '--primary',
        ]],
        'label_heading' => 'Age',
        'empty'      => 'Nothing is open, so there is no queue to age.',
        'height'     => 220,
        'filtered'   => $mtFiltered,
        'resetUrl'   => $mtReset,
        'footnote'   => 'Age, not lateness. This system records no target response time and '
                      . 'no due date for maintenance, so none of these buckets is an SLA '
                      . 'breach — they say how long, and the judgement is yours.',
    ];
    require dirname(__DIR__) . '/_chart_card.php';
    ?>
</div>

<!-- ── Row 4 · insights, cost and the resolution position ────────── -->
<div class="rgrid rgrid--wide">
    <?php require dirname(__DIR__) . '/_insights.php'; ?>

    <section class="card rcard" aria-labelledby="mt-cost-title">
        <div class="card__header">
            <div class="rcard__titles">
                <h3 class="card__title" id="mt-cost-title">Cost and resolution</h3>
                <p class="card__subtitle">What the work was costed at, and what is known about how long it takes</p>
            </div>
        </div>
        <div class="card__body card__body--flush">
            <dl class="datalist">
                <div class="datalist__row">
                    <dt>Estimated cost
                        <span class="text-subtle">· <?= number_format((int) $costs['with_estimate']) ?> costed</span>
                    </dt>
                    <dd class="num"><?= sanitize(formatCurrency((float) $costs['estimate'])) ?></dd>
                </div>
                <div class="datalist__row">
                    <dt>Actual cost
                        <span class="text-subtle">· <?= number_format((int) $costs['with_actual']) ?> recorded</span>
                    </dt>
                    <dd class="num"><?= sanitize(formatCurrency((float) $costs['actual'])) ?></dd>
                </div>
                <div class="datalist__row">
                    <dt>Average resolution</dt>
                    <dd class="num">
                        <?php if (!empty($resolution['available'])): ?>
                            <?= sanitize(number_format((float) $resolution['average'], 1)) ?> days
                        <?php else: ?>
                            <span class="text-subtle">Not available</span>
                        <?php endif ?>
                    </dd>
                </div>
                <div class="datalist__row">
                    <dt>Oldest open request</dt>
                    <dd class="num">
                        <?= (int) $summary['open'] > 0
                            ? sanitize(number_format((int) $ageing['oldest']) . ' days')
                            : '<span class="text-subtle">—</span>' ?>
                    </dd>
                </div>
            </dl>
            <p class="rcard__footnote">
                Estimate and actual are never netted against each other: a request with an
                estimate and no actual has not been paid for yet, and one with an actual and
                no estimate was never costed in advance.
                <?php if (empty($resolution['available'])): ?>
                    Resolution time reads as unavailable because no completed request carries
                    a completion date — it is measured from the date raised to the date
                    completed, and <code>updated_at</code> is not used as a substitute since
                    it moves on any edit.
                <?php endif ?>
            </p>
        </div>
    </section>
</div>

<!-- ── Row 5 · the queue, the finished work, the properties ──────── -->
<?php
$mtMode = 'open';
require dirname(__DIR__) . '/_maintenance_table.php';

$mtMode = 'completed';
require dirname(__DIR__) . '/_maintenance_table.php';

require dirname(__DIR__) . '/_maintenance_properties.php';
?>
