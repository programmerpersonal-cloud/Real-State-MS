<?php
/**
 * Sales and reservation data quality.
 *
 * The report-wide panel covers the module's shared diagnostics; this one
 * carries the issues that only mean something here — a sale with no price, a
 * property claiming a sale that never completed, a hold that expired months
 * ago and still reads confirmed.
 *
 * On severity: a sale pointing at a property that no longer exists, two
 * completed sales on one property, or a hold that expires before it starts
 * are *critical* — nothing can read those rows correctly, this report
 * included. A lapsed reservation still reading confirmed is a warning: the
 * record is intact and simply out of date. A completed sale dated ahead is
 * neither — it is held out of completed totals by the same rule revenue
 * applies to future-dated payments, so it is a note explaining a gap rather
 * than a fault to fix.
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

$sqAdd = static function (string $sqSev, string $sqLabel, array $sqFlag, string $sqText) use (&$sqRows): void {
    if ((int) ($sqFlag['count'] ?? 0) > 0) {
        $sqRows[] = [
            'severity' => $sqSev,
            'label' => $sqLabel,
            'count' => (int) $sqFlag['count'],
            'text'  => $sqText,
            'value' => ((float) ($sqFlag['amount'] ?? 0)) > 0
                ? formatCurrency((float) $sqFlag['amount'])
                : null,
        ];
    }
};

$sqAdd('warning', 'Lapsed reservations still active', $sqFlags['lapsed_reservations'] ?? [],
    'The expiry date has passed but the status still reads active or confirmed. Nothing '
    . 'expires these automatically, so the property stays marked as held and the deposit '
    . 'stays with the company. They are excluded from live reservation counts.');

$sqAdd('warning', 'Recorded sold, no completed sale', $sqFlags['sold_no_sale'] ?? [],
    'The property record says sold but no sale against it has completed. This report counts '
    . 'completed sales from sale records, so the figures above are unaffected.');

$sqAdd('note', 'Sale with no agent', $sqFlags['no_agent'] ?? [],
    'No agent is recorded against the deal, so it cannot be attributed to anyone on the '
    . 'performance report. The deal itself is counted in full.');

$sqAdd('note', 'Completed sale dated ahead', $sqFlags['future_completed'] ?? [],
    'Marked completed with a sale date after today. Held out of completed totals until the '
    . 'date arrives, the same rule revenue applies to future-dated payments.');

$sqAdd('warning', 'Sale with no amount', $sqFlags['bad_amount'] ?? [],
    'The sale amount is zero or negative, so the deal contributes nothing to pipeline or '
    . 'completed value.');

$sqAdd('warning', 'Sale with no date', $sqFlags['no_date'] ?? [],
    'No sale date is recorded, so the deal cannot be placed in any reporting period and is '
    . 'absent from every period figure on this report.');

$sqAdd('critical', 'Sale on a missing property', $sqFlags['orphan_property'] ?? [],
    'The property the sale refers to no longer exists, so the deal cannot be scoped or '
    . 'categorised.');

$sqAdd('critical', 'Two completed sales on one property', $sqFlags['duplicate_completed'] ?? [],
    'More than one sale has completed against the same property. The schema permits it and '
    . 'nothing checks for it — the property can only have been sold once.');

$sqAdd('critical', 'Reservation expiring before it starts', $sqFlags['expiry_before_start'] ?? [],
    'The expiry date precedes the reservation date, so the hold period cannot be read from '
    . 'those two columns.');

$qualityPanel = [
    'title'   => 'Sales & reservation data quality',
    'icon'    => 'bi-briefcase',
    'variant' => 'rentals',
    'note'    => 'Figures above are unaffected',
    'rows'    => $sqRows,
    'foot'    => 'Diagnostic only. No sale, reservation or property record is changed by this report.',
];
require __DIR__ . '/_quality_panel.php';
