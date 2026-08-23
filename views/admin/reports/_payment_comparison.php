<?php
/**
 * Payment period comparison.
 *
 * Five measures, and the first two are the reason this table exists: record
 * count and amount move independently, and a report that shows only the
 * second cannot tell you whether a good month was more customers or bigger
 * cheques.
 *
 * Two rows print "Not available", honestly rather than as a gap in the work.
 * Future-dated records and classification flags are conditions of the ledger
 * as it stands now — a record is dated ahead of *today*, not ahead of some
 * date in July — so there is no previous-period equivalent to compare with.
 * Repeating the current figure in the previous column would be the most
 * misleading thing that could go there.
 *
 * Expects: $activity, $previousActivity, $futureDated, $mismatch, $window
 *
 * Every local in this file is prefixed. A partial pulled in with require
 * shares the including view's variable scope; see the note in _kpi.php.
 */
$pmRows = [];

/* null means "cannot be reconstructed", never "zero". The two render
   completely differently and must not be conflated. */
$pmAdd = static function (
    string $label,
    ?float $current,
    ?float $previous,
    string $format,
    string $note = ''
) use (&$pmRows): void {
    $pmRows[] = [
        'label'    => $label,
        'current'  => $current,
        'previous' => $previous,
        'format'   => $format,
        'note'     => $note,
        'delta'    => ($current !== null && $previous !== null) ? reportDelta($current, $previous) : null,
    ];
};

$pmPrev = $previousActivity;

$pmAdd('Payment records', (float) $activity['records'],
    $pmPrev !== null ? (float) $pmPrev['records'] : null, 'count',
    'Transactions dated in the period, whatever their state.');
$pmAdd('Amount recorded', (float) $activity['amount'],
    $pmPrev !== null ? (float) $pmPrev['amount'] : null, 'money',
    'The face value of those transactions, before any status is considered.');
$pmAdd('Money received', (float) $activity['received'],
    $pmPrev !== null ? (float) $pmPrev['received'] : null, 'money',
    'Paid records dated today or earlier, all payment types.');
$pmAdd('Collected revenue', (float) $activity['collected'],
    $pmPrev !== null ? (float) $pmPrev['collected'] : null, 'money',
    'The approved revenue definition — the same figure Financial and Overview show.');
$pmAdd('Average payment',
    $activity['average'] === null ? null : (float) $activity['average'],
    ($pmPrev !== null && $pmPrev['average'] !== null) ? (float) $pmPrev['average'] : null,
    'money', 'Amount recorded over record count. Undefined where nothing was recorded.');
$pmAdd('Dated ahead', (float) $futureDated['amount'], null, 'money',
    'A condition of the ledger as at today, so there is no previous-period equivalent.');
$pmAdd('Classification flags', (float) $mismatch['count'], null, 'count',
    'Also measured against the ledger as it stands, and unavailable for the same reason.');

$pmMoney = static fn(?float $v): string => $v === null ? '—' : formatCurrency($v);
$pmCount = static fn(?float $v): string => $v === null ? '—' : number_format($v);
$pmShow  = static fn(array $pmR): callable => $pmR['format'] === 'count' ? $pmCount : $pmMoney;
?>
<section class="card rcard fcomp" aria-labelledby="pcomp-title">
    <div class="card__header">
        <div class="rcard__titles">
            <h3 class="card__title" id="pcomp-title">Period comparison</h3>
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
                    <?php foreach ($pmRows as $pmRow): ?>
                        <?php $pmFmt = $pmShow($pmRow); ?>
                        <tr<?= $pmRow['previous'] === null ? ' class="is-muted-row"' : '' ?>>
                            <th scope="row">
                                <?= sanitize($pmRow['label']) ?>
                                <?php if ($pmRow['note'] !== ''): ?>
                                    <div class="fcomp__note"><?= sanitize($pmRow['note']) ?></div>
                                <?php endif ?>
                            </th>
                            <td class="cell-num fcomp__now"><?= sanitize($pmFmt($pmRow['current'])) ?></td>

                            <?php if ($pmRow['previous'] === null): ?>
                                <?php /* One cell with a reason, rather than three em
                                         dashes that explain nothing. */ ?>
                                <td class="cell-num fcomp__na" colspan="3">
                                    <span class="text-subtle">Not available for a previous period</span>
                                </td>
                            <?php else: ?>
                                <td class="cell-num"><?= sanitize($pmFmt($pmRow['previous'])) ?></td>
                                <td class="cell-num">
                                    <?php
                                    $pmDiff = (float) $pmRow['delta']['difference'];
                                    $pmSign = $pmDiff > 0 ? '+' : ($pmDiff < 0 ? '−' : '');
                                    ?>
                                    <span class="fcomp__delta fcomp__delta--<?= sanitize($pmRow['delta']['direction']) ?>">
                                        <?= sanitize($pmSign . $pmFmt(abs($pmDiff))) ?>
                                    </span>
                                </td>
                                <td class="cell-num col-mid">
                                    <?php if ($pmRow['delta']['percentage'] === null): ?>
                                        <span class="text-subtle" title="There was nothing in the previous period to take a percentage of.">n/a</span>
                                    <?php else: ?>
                                        <?php
                                        $pmArrow = [
                                            'up' => 'bi-arrow-up-right',
                                            'down' => 'bi-arrow-down-right',
                                            'flat' => 'bi-dash',
                                        ][$pmRow['delta']['direction']];
                                        ?>
                                        <span class="fcomp__delta fcomp__delta--<?= sanitize($pmRow['delta']['direction']) ?>">
                                            <i class="bi <?= $pmArrow ?>" aria-hidden="true"></i>
                                            <?= sanitize(number_format(abs((float) $pmRow['delta']['percentage']), 1)) ?>%
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
            Records and amount are shown side by side because they answer different
            questions. More transactions and more money are not the same event, and a
            period where one rose while the other fell is the case worth noticing.
        </p>
    </div>
</section>
