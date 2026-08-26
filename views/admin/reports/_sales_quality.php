<?php
/**
 * Sales and reservation data quality.
 *
 * The report-wide panel covers the module's shared diagnostics; this one
 * carries the issues that only mean something here — a sale with no price, a
 * property claiming a sale that never completed, a hold that expired months
 * ago and still reads confirmed.
 *
 * Nothing is repaired. Every count below is a record somebody needs to look
 * at, not a record this report will quietly correct.
 *
 * Expects: $salesFlags (from CoreAnalytics::salesIntegrityFlags())
 *
 * Every local in this file is prefixed. A partial pulled in with require
 * shares the including view's variable scope; see the note in _kpi.php.
 */
$sqFlags = $salesFlags ?? [];
$sqRows  = [];

$sqAdd = static function (string $sqLabel, array $sqFlag, string $sqText) use (&$sqRows): void {
    if ((int) ($sqFlag['count'] ?? 0) > 0) {
        $sqRows[] = [
            'label' => $sqLabel,
            'count' => (int) $sqFlag['count'],
            'text'  => $sqText,
            'value' => ((float) ($sqFlag['amount'] ?? 0)) > 0
                ? formatCurrency((float) $sqFlag['amount'])
                : null,
        ];
    }
};

$sqAdd('Lapsed reservations still active', $sqFlags['lapsed_reservations'] ?? [],
    'The expiry date has passed but the status still reads active or confirmed. Nothing '
    . 'expires these automatically, so the property stays marked as held and the deposit '
    . 'stays with the company. They are excluded from live reservation counts.');

$sqAdd('Recorded sold, no completed sale', $sqFlags['sold_no_sale'] ?? [],
    'The property record says sold but no sale against it has completed. This report counts '
    . 'completed sales from sale records, so the figures above are unaffected.');

$sqAdd('Sale with no agent', $sqFlags['no_agent'] ?? [],
    'No agent is recorded against the deal, so it cannot be attributed to anyone on the '
    . 'performance report.');

$sqAdd('Completed sale dated ahead', $sqFlags['future_completed'] ?? [],
    'Marked completed with a sale date after today. Held out of completed totals until the '
    . 'date arrives, the same rule revenue applies to future-dated payments.');

$sqAdd('Sale with no amount', $sqFlags['bad_amount'] ?? [],
    'The sale amount is zero or negative, so the deal contributes nothing to pipeline or '
    . 'completed value.');

$sqAdd('Sale with no date', $sqFlags['no_date'] ?? [],
    'No sale date is recorded, so the deal cannot be placed in any reporting period and is '
    . 'absent from every period figure on this report.');

$sqAdd('Sale on a missing property', $sqFlags['orphan_property'] ?? [],
    'The property the sale refers to no longer exists, so the deal cannot be scoped or '
    . 'categorised.');

$sqAdd('Two completed sales on one property', $sqFlags['duplicate_completed'] ?? [],
    'More than one sale has completed against the same property. The schema permits it and '
    . 'nothing checks for it.');

$sqAdd('Reservation expiring before it starts', $sqFlags['expiry_before_start'] ?? [],
    'The expiry date precedes the reservation date, so the hold period cannot be read from '
    . 'those two columns.');

if (!$sqRows) {
    return;
}
?>
<details class="dq dq--rentals">
    <summary class="dq__summary">
        <span class="dq__icon" aria-hidden="true"><i class="bi bi-briefcase"></i></span>
        <span class="dq__lead">
            <strong>Sales &amp; reservation data quality</strong>
            <span class="dq__count">
                <?= count($sqRows) === 1 ? '1 item needs attention' : count($sqRows) . ' items need attention' ?>
            </span>
        </span>
        <span class="dq__note">Figures above are unaffected</span>
        <i class="bi bi-chevron-down dq__chev" aria-hidden="true"></i>
    </summary>

    <ul class="dq__list">
        <?php foreach ($sqRows as $sqRow): ?>
            <li class="dq__row">
                <span class="dq__row-icon" aria-hidden="true"><i class="bi bi-tag"></i></span>
                <div class="dq__row-body">
                    <div class="dq__row-label">
                        <?= sanitize($sqRow['label']) ?>
                        <span class="dq__badge"><?= number_format((int) $sqRow['count']) ?></span>
                    </div>
                    <p class="dq__row-text"><?= sanitize($sqRow['text']) ?></p>
                </div>
                <?php if ($sqRow['value'] !== null): ?>
                    <div class="dq__row-value"><?= sanitize((string) $sqRow['value']) ?></div>
                <?php endif ?>
            </li>
        <?php endforeach ?>
    </ul>

    <p class="dq__foot">
        Diagnostic only. No sale, reservation or property record is changed by this report.
    </p>
</details>
