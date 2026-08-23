<?php
/**
 * Financial — expected against collected, and what is still owed.
 *
 * The whole report turns on one distinction that ordinary English refuses to
 * make: *outstanding* and *in arrears* are not the same money. Rent that
 * falls due next month is owed and not late; rent that fell due in June and
 * is unpaid is both. A dashboard that adds them into one red number tells a
 * landlord their tenants are three times worse than they are.
 *
 * The second distinction is the axis. Scheduled rent is dated by when it fell
 * due; cash is dated by the day it arrived. The collection rate is computed
 * entirely inside the first — settled over expected, both on the due date —
 * because a ratio across two axes measures the calendar as much as the
 * collecting. Cash received is reported beside it under its own name, and the
 * distance between them is the ledger gap the data-quality panel tracks.
 *
 * This is not an accounting system and does not pretend to be one. There is
 * no general ledger here, no receivables beyond rent schedules, and no
 * accrual of anything. Every figure is a sum over rows that exist.
 *
 * Vars from ReportController::financialData().
 */
$fiFiltered = reportFilterCount($filters) > 0;
$fiCarry    = !empty($compare) ? ['compare' => '1'] : [];
$fiReset    = reportUrl($window, [], ['tab' => 'financial'] + $fiCarry);
$fiLink     = static fn(string $tab): string => reportUrl($window, $filters, ['tab' => $tab] + $fiCarry);

$fiExpected  = (float) $ledger['expected'];
$fiSettled   = (float) $ledger['settled_on_ledger'];
$fiArrears   = (float) $ledger['arrears'];
$fiOutstand  = (float) $ledger['outstanding'];
$fiNotYetDue = (float) ($ledger['not_yet_due'] ?? 0);
?>

<?php require dirname(__DIR__) . '/_data_quality.php'; ?>

<!-- ── Row 1 · the five financial figures ────────────────────────── -->
<div class="kpis kpis--five">
    <?php
    $kpi = [
        'label'   => 'Collected revenue',
        'value'   => formatCurrency($revenue),
        'icon'    => 'bi-cash-stack',
        'tone'    => 'primary',
        'context' => 'Money actually received, dated by the day it arrived',
        'delta'   => $previousStreams !== null ? reportDelta($revenue, (float) $previousStreams['total']) : null,
        'delta_format'   => static fn(float $v): string => formatCurrency($v),
        'previous_label' => $previousStreams !== null
            ? formatCurrency((float) $previousStreams['total']) . ' previously'
            : null,
    ];
    require dirname(__DIR__) . '/_kpi.php';

    /* "Expected rent", not "expected revenue". The schedule covers tenancies
       and nothing else — there are no sales receivables in this schema — so
       the wider name would claim a completeness the data does not have. */
    $kpi = [
        'label'   => 'Expected rent',
        'value'   => formatCurrency($fiExpected),
        'icon'    => 'bi-calendar-check',
        'tone'    => 'info',
        'context' => 'Scheduled rent falling due in this period — tenancies only',
        'delta'   => $previousLedger !== null
            ? reportDelta($fiExpected, (float) $previousLedger['expected'])
            : null,
        'delta_format'   => static fn(float $v): string => formatCurrency($v),
        'previous_label' => $previousLedger !== null
            ? formatCurrency((float) $previousLedger['expected']) . ' previously'
            : null,
    ];
    require dirname(__DIR__) . '/_kpi.php';

    $kpi = [
        'label'   => 'Collection rate',
        'value'   => reportPercent($ledger['collection_rate']),
        'icon'    => 'bi-percent',
        'tone'    => $ledger['collection_rate'] === null
            ? 'info'
            : ($ledger['collection_rate'] >= 90 ? 'success' : ($ledger['collection_rate'] >= 70 ? 'warning' : 'danger')),
        'context' => $fiExpected > 0
            ? formatCurrency($fiSettled) . ' settled of ' . formatCurrency($fiExpected) . ' scheduled'
            : 'No rent was scheduled in this period',
        'delta'   => ($previousLedger !== null && $previousLedger['collection_rate'] !== null && $ledger['collection_rate'] !== null)
            ? reportDelta((float) $ledger['collection_rate'], (float) $previousLedger['collection_rate'])
            : null,
        'delta_format'   => static fn(float $v): string => number_format($v, 1) . ' pts',
        'previous_label' => ($previousLedger !== null && $previousLedger['collection_rate'] !== null)
            ? reportPercent($previousLedger['collection_rate']) . ' previously'
            : null,
    ];
    require dirname(__DIR__) . '/_kpi.php';

    /* Outstanding, and emphatically not "arrears". It includes rent that has
       not fallen due yet, which is the whole reason the next tile exists. */
    $kpi = [
        'label'   => 'Outstanding balance',
        'value'   => formatCurrency($fiOutstand),
        'icon'    => 'bi-hourglass-split',
        'tone'    => 'purple',
        'context' => $fiNotYetDue > 0
            ? formatCurrency($fiNotYetDue) . ' of this is not yet due'
            : 'All of it has already fallen due',
    ];
    require dirname(__DIR__) . '/_kpi.php';

    $kpi = [
        'label'   => 'Rent arrears',
        'value'   => formatCurrency($fiArrears),
        'icon'    => 'bi-exclamation-triangle',
        'tone'    => (int) $ledger['overdue_count'] > 0 ? 'danger' : 'success',
        'context' => (int) $ledger['overdue_count'] > 0
            ? sprintf(
                '%d overdue %s · the late part of the balance',
                (int) $ledger['overdue_count'],
                (int) $ledger['overdue_count'] === 1 ? 'instalment' : 'instalments'
            )
            : 'Nothing overdue',
        'url'     => $fiLink('payments'),
    ];
    require dirname(__DIR__) . '/_kpi.php';
    ?>
</div>

<?php if (!empty($compare)): ?>
    <?php require dirname(__DIR__) . '/_financial_comparison.php'; ?>
<?php endif ?>

<!-- ── Row 2 · expected against settled, and the rate it implies ─── -->
<div class="rgrid rgrid--wide">
    <?php
    /* Grouped bars rather than lines. Two quantities for the same bucket that
       should be compared *within* the bucket read as a pair of bars; drawn as
       two lines the eye follows each across the chart and the comparison that
       matters — this month's expected against this month's settled — is the
       one it stops making. */
    $fiExpectedSeries = array_map(static fn(array $p): float => (float) $p['total'], $ledgerSeries['expected']);
    $fiSettledSeries  = array_map(static fn(array $p): float => (float) $p['total'], $ledgerSeries['settled']);

    $chart = [
        'id'       => 'expectedVsSettled',
        'title'    => 'Expected vs settled rent',
        'subtitle' => 'Scheduled rent by ' . $window['grain'] . ', dated by when it fell due',
        'type'     => 'bar',
        'unit'     => 'currency',
        'labels'   => array_column($ledgerSeries['expected'], 'label'),
        'series'   => [
            ['label' => 'Expected', 'data' => $fiExpectedSeries, 'tone' => '--text-subtle'],
            ['label' => 'Settled',  'data' => $fiSettledSeries,  'tone' => '--success'],
        ],
        'label_heading' => ucfirst($window['grain']),
        'empty'    => 'No rent was scheduled to fall due in this period.',
        'height'   => 230,
        'filtered' => $fiFiltered,
        'resetUrl' => $fiReset,
        'footnote' => 'Both series sit on the due-date axis, which is what makes the '
                    . 'collection rate a like-for-like measure. Cash received is a '
                    . 'different question, on a different axis, and is the "collected '
                    . 'revenue" figure above.',
    ];
    require dirname(__DIR__) . '/_chart_card.php';

    /* Collection rate per bucket, and null — not zero — wherever no rent was
       scheduled. A bucket with nothing due has no rate; printing 0% there
       would report a collections failure that never happened. */
    $fiRate = [];
    foreach ($fiExpectedSeries as $fiI => $fiDue) {
        $fiRate[] = $fiDue > 0
            ? round(($fiSettledSeries[$fiI] ?? 0) / $fiDue * 100, 1)
            : null;
    }

    $chart = [
        'id'       => 'collectionPerformance',
        'title'    => 'Collection performance',
        'subtitle' => 'Share of scheduled rent settled, by ' . $window['grain'],
        'type'     => 'line',
        'unit'     => 'percent',
        'labels'   => array_column($ledgerSeries['expected'], 'label'),
        'series'   => [['label' => 'Settled', 'data' => $fiRate, 'tone' => '--primary']],
        'label_heading' => ucfirst($window['grain']),
        'empty'    => 'No rent fell due in this period, so there is no collection rate to plot.',
        'height'   => 230,
        'filtered' => $fiFiltered,
        'resetUrl' => $fiReset,
        'footnote' => 'Buckets where no rent fell due are left blank rather than drawn '
                    . 'at zero — nothing was scheduled, so nothing was missed.',
    ];
    require dirname(__DIR__) . '/_chart_card.php';
    ?>
</div>

<!-- ── Row 3 · what came in, and what is still owed ──────────────── -->
<div class="rgrid rgrid--wide">
    <?php
    $fiStreamNames = ['rental' => 'Rental', 'sale' => 'Sales', 'reservation' => 'Reservation'];
    $fiStreamTones = ['rental' => '--primary', 'sale' => '--success', 'reservation' => '--purple'];
    $fiSLabels = $fiSData = $fiSTones = [];
    foreach ($fiStreamNames as $fiKey => $fiName) {
        if ((float) $streams[$fiKey] > 0) {
            $fiSLabels[] = $fiName;
            $fiSData[]   = (float) $streams[$fiKey];
            $fiSTones[]  = $fiStreamTones[$fiKey];
        }
    }

    $chart = [
        'id'       => 'revenueStreams',
        'title'    => 'Revenue streams',
        'subtitle' => 'Collected revenue by the contract it was taken against',
        'type'     => 'doughnut',
        'unit'     => 'currency',
        'labels'   => $fiSLabels,
        'series'   => [['label' => 'Collected', 'data' => $fiSData, 'tones' => $fiSTones]],
        'label_heading' => 'Stream',
        'empty'    => 'No eligible revenue was collected in this period.',
        'height'   => 230,
        'share'    => true,
        'filtered' => $fiFiltered,
        'resetUrl' => $fiReset,
        'footnote' => 'Deposits held and refunds paid out are excluded from revenue by '
                    . 'definition — a deposit is money held, not earned. They are '
                    . 'reported under the chart below.',
    ];
    require dirname(__DIR__) . '/_chart_card.php';

    /* The report's central point, drawn. One stacked bar so the two parts are
       read as portions of a single balance rather than as two rival totals. */
    $chart = [
        'id'         => 'outstandingPosition',
        'title'      => 'Outstanding position',
        'subtitle'   => 'How much of what is owed is actually late',
        'type'       => 'bar',
        'unit'       => 'currency',
        'horizontal' => true,
        'stacked'    => true,
        'labels'     => ['Scheduled balance'],
        'series'     => [
            ['label' => 'In arrears',  'data' => [$fiArrears],   'tone' => '--danger'],
            ['label' => 'Not yet due', 'data' => [$fiNotYetDue], 'tone' => '--info'],
        ],
        'label_heading' => 'Position',
        'empty'      => 'Nothing is outstanding — every scheduled instalment has been settled.',
        'height'     => 150,
        'filtered'   => $fiFiltered,
        'resetUrl'   => $fiReset,
        'footnote'   => 'Arrears is rent that has fallen due and is unpaid. Not-yet-due is '
                      . 'rent scheduled for a date still ahead. Both are owed; only the '
                      . 'first is late, and only the first is worth chasing today.',
    ];
    require dirname(__DIR__) . '/_chart_card.php';
    ?>
</div>

<!-- ── Row 4 · insights, and the money held rather than earned ───── -->
<div class="rgrid rgrid--wide">
    <?php require dirname(__DIR__) . '/_insights.php'; ?>

    <section class="card rcard" aria-labelledby="fi-held-title">
        <div class="card__header">
            <div class="rcard__titles">
                <h3 class="card__title" id="fi-held-title">Money that is not revenue</h3>
                <p class="card__subtitle">Received in this period, and deliberately excluded from the figures above</p>
            </div>
        </div>
        <div class="card__body card__body--flush">
            <dl class="datalist">
                <div class="datalist__row">
                    <dt>Deposits held</dt>
                    <dd class="num"><?= sanitize(formatCurrency((float) $deposits['deposits'])) ?></dd>
                </div>
                <div class="datalist__row">
                    <dt>Refunds paid out</dt>
                    <dd class="num"><?= sanitize(formatCurrency((float) $deposits['refunds'])) ?></dd>
                </div>
                <div class="datalist__row">
                    <dt>
                        Future-dated, not yet counted
                        <?php if ((int) $futureDated['count'] > 0): ?>
                            <span class="text-subtle">· <?= number_format((int) $futureDated['count']) ?>
                                <?= (int) $futureDated['count'] === 1 ? 'payment' : 'payments' ?></span>
                        <?php endif ?>
                    </dt>
                    <dd class="num"><?= sanitize(formatCurrency((float) $futureDated['amount'])) ?></dd>
                </div>
            </dl>
            <p class="rcard__footnote">
                A deposit is a liability held against a tenancy and a refund is money going
                the other way; neither is earnings. A payment dated after today has not been
                received yet. All three are real records — they are simply not revenue, and
                are listed here so the exclusion is visible rather than silent.
            </p>
        </div>
    </section>
</div>

<!-- ── Row 5 · the ledger, per property ──────────────────────────── -->
<?php require dirname(__DIR__) . '/_financial_properties.php'; ?>
