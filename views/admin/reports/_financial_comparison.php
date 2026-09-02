<?php
/**
 * Financial period comparison.
 *
 * A table rather than four more lines on the chart. The Expected-vs-Settled
 * chart already carries two datasets for the current period; adding the
 * previous period's two would put four series in one frame, and a reader
 * trying to answer "did we collect better than last month" would be counting
 * bars instead of reading an answer. The comparison is a table because the
 * question is arithmetic, not shape.
 *
 * Three of the six rows print "not available", and that is the honest output
 * rather than a gap in the work. Outstanding, not-yet-due and arrears are
 * running balances: `payment_schedules` records the state a row is in now,
 * not the state it was in in July. There is no history to compare against,
 * and repeating today's figure in the "previous" column — which is what a
 * naive implementation does — would be the most misleading thing on the page.
 *
 * Expects: $ledger, $previousLedger, $streams, $previousStreams, $window
 *
 * Every local in this file is prefixed. A partial pulled in with require
 * shares the including view's variable scope; see the note in _kpi.php.
 */
$fcRows = [];

/* value === null means "cannot be reconstructed", not "zero". The two are
   rendered completely differently and must never be conflated. */
$fcAdd = static function (
    string $label,
    ?float $current,
    ?float $previous,
    string $format,
    string $note = ''
) use (&$fcRows): void {
    $fcRows[] = [
        'label'    => $label,
        'current'  => $current,
        'previous' => $previous,
        'format'   => $format,
        'note'     => $note,
        'delta'    => ($current !== null && $previous !== null)
            ? reportDelta($current, $previous)
            : null,
    ];
};

$fcMoney = static fn(?float $v): string => $v === null ? '—' : formatCurrency($v);
$fcPct   = static fn(?float $v): string => $v === null ? '—' : reportPercent($v);
$fcShow  = static fn(array $fcR): callable => $fcR['format'] === 'percent' ? $fcPct : $fcMoney;

$fcAdd('Collected revenue', (float) $streams['total'], $previousStreams !== null ? (float) $previousStreams['total'] : null, 'money',
    'Cash received, dated by the day it arrived.');
$fcAdd('Expected rent', (float) $ledger['expected'], $previousLedger !== null ? (float) $previousLedger['expected'] : null, 'money',
    'Scheduled rent falling due in the period.');
$fcAdd('Settled rent', (float) $ledger['settled_on_ledger'], $previousLedger !== null ? (float) $previousLedger['settled_on_ledger'] : null, 'money',
    'Scheduled rent marked paid, on the due-date axis.');
$fcAdd('Collection rate',
    $ledger['collection_rate'] === null ? null : (float) $ledger['collection_rate'],
    ($previousLedger !== null && $previousLedger['collection_rate'] !== null) ? (float) $previousLedger['collection_rate'] : null,
    'percent', 'Settled over expected, both on the due-date axis.');
$fcAdd('Outstanding balance', (float) $ledger['outstanding'], null, 'money',
    'A running balance as at today. The schedule holds no history, so there is no previous figure to compare against.');
$fcAdd('Rent arrears', (float) $ledger['arrears'], null, 'money',
    'Also a running balance, and unavailable for the same reason.');
?>
<section class="card rcard" aria-labelledby="fcomp-title">
    <div class="card__header">
        <div class="rcard__titles">
            <h4 class="card__title" id="fcomp-title">Period comparison</h4>
            <p class="card__subtitle">
                <?= sanitize(formatDate($window['from'])) ?> – <?= sanitize(formatDate($window['to_capped'])) ?>
                against <?= sanitize(formatDate($window['prev_from'])) ?> – <?= sanitize(formatDate($window['prev_to'])) ?>
                <span class="text-subtle">(<?= (int) $window['days'] ?> days each)</span>
            </p>
        </div>
    </div>

    <div class="card__body card__body--flush">
        <div class="table-wrap">
            <table class="table fcomp__table">
                <thead>
                    <tr>
                        <th scope="col">Measure</th>
                        <th scope="col" class="cell-num">This period</th>
                        <th scope="col" class="cell-num">Previous</th>
                        <th scope="col" class="cell-num">Change</th>
                        <th scope="col" class="cell-num col-mid">%</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fcRows as $fcRow): ?>
                        <?php $fcFmt = $fcShow($fcRow); ?>
                        <tr<?= $fcRow['previous'] === null ? ' class="is-muted-row"' : '' ?>>
                            <th scope="row">
                                <?= sanitize($fcRow['label']) ?>
                                <?php if ($fcRow['note'] !== ''): ?>
                                    <div class="fcomp__note"><?= sanitize($fcRow['note']) ?></div>
                                <?php endif ?>
                            </th>
                            <td class="cell-num fcomp__now"><?= sanitize($fcFmt($fcRow['current'])) ?></td>

                            <?php if ($fcRow['previous'] === null): ?>
                                <?php /* Spans the three comparison columns, because a
                                         reason is more use than three em dashes. */ ?>
                                <td class="cell-num fcomp__na" colspan="3">
                                    <span class="text-subtle">Not available for a previous period</span>
                                </td>
                            <?php else: ?>
                                <td class="cell-num"><?= sanitize($fcFmt($fcRow['previous'])) ?></td>
                                <td class="cell-num">
                                    <?php
                                    $fcDiff = (float) $fcRow['delta']['difference'];
                                    $fcSign = $fcDiff > 0 ? '+' : ($fcDiff < 0 ? '−' : '');
                                    ?>
                                    <span class="fcomp__delta fcomp__delta--<?= sanitize($fcRow['delta']['direction']) ?>">
                                        <?= sanitize($fcSign . ($fcRow['format'] === 'percent'
                                            ? number_format(abs($fcDiff), 1) . ' pts'
                                            : formatCurrency(abs($fcDiff)))) ?>
                                    </span>
                                </td>
                                <td class="cell-num col-mid">
                                    <?php if ($fcRow['delta']['percentage'] === null): ?>
                                        <?php /* A percentage against a zero baseline is not a
                                                 large number, it is an undefined one. */ ?>
                                        <span class="text-subtle" title="There was nothing in the previous period to take a percentage of.">n/a</span>
                                    <?php else: ?>
                                        <?php
                                        $fcArrow = [
                                            'up' => 'bi-arrow-up-right',
                                            'down' => 'bi-arrow-down-right',
                                            'flat' => 'bi-dash',
                                        ][$fcRow['delta']['direction']];
                                        ?>
                                        <span class="fcomp__delta fcomp__delta--<?= sanitize($fcRow['delta']['direction']) ?>">
                                            <i class="bi <?= $fcArrow ?>" aria-hidden="true"></i>
                                            <?= sanitize(number_format(abs((float) $fcRow['delta']['percentage']), 1)) ?>%
                                        </span>
                                    <?php endif ?>
                                </td>
                            <?php endif ?>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>

        <p class="rcard__footnote">
            Direction is shown with an arrow and a sign as well as a colour, so the
            table reads the same in greyscale. A rise in arrears is not an improvement
            and is not coloured as one.
        </p>
    </div>
</section>
