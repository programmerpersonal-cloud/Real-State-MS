<?php
/**
 * Tenancy data quality.
 *
 * A second, narrower panel beside the module-wide one. These are conditions
 * the lease table permits and no workflow prevents, and they belong to this
 * report rather than to every report — a lease whose move-out precedes its
 * move-in means nothing on the payments tab.
 *
 * The last row is the one worth reading twice. A terminated tenancy that
 * still carries unpaid instalments contributes to company-wide arrears and to
 * the outstanding balance, so money is being reported against a let that has
 * already ended. It is not double-counted and it is not wrong — it is simply
 * easy to miss inside a single total, which is exactly why it is broken out.
 *
 * On severity: a term whose end precedes its start, or two live tenancies on
 * one property, is *critical* — those rows cannot be read correctly by
 * anything, this report included, because the schedule and the occupancy they
 * imply are both derived from dates that contradict each other. A lease still
 * flagged active past its end date is a warning: the record is readable, it
 * simply disagrees with the calendar, and somebody has to close it.
 *
 * Nothing here is corrected. No record is written.
 *
 * Expects: $leaseFlags (from CoreAnalytics::leaseIntegrityFlags())
 *
 * Every local in this file is prefixed. A partial pulled in with require
 * shares the including view's variable scope; see the note in _kpi.php.
 */
$rqFlags = $leaseFlags ?? [];
$rqRows  = [];

$rqAdd = static function (string $rqSev, string $rqLabel, int $rqCount, string $rqText, ?string $rqValue = null) use (&$rqRows): void {
    if ($rqCount > 0) {
        $rqRows[] = ['severity' => $rqSev, 'label' => $rqLabel, 'count' => $rqCount,
                     'text' => $rqText, 'value' => $rqValue];
    }
};

$rqAdd('warning',
    'Active past its end date',
    (int) ($rqFlags['active_past_end']['count'] ?? 0),
    'The tenancy is still flagged active although its end date has passed. Nothing rolls '
    . 'that status forward automatically, so it stays active until somebody closes it.'
);
$rqAdd('warning',
    'Lease and property disagree',
    (int) ($rqFlags['status_disagrees']['count'] ?? 0),
    'An active tenancy whose property is not recorded as rented. Occupancy on this report '
    . 'is derived from the lease, so the figures above are unaffected.'
);
$rqAdd('critical',
    'Two active tenancies on one property',
    (int) ($rqFlags['duplicate_active']['count'] ?? 0),
    'More than one lease is active against the same property at the same time. The schema '
    . 'permits it and nothing checks for it — only one of them can be the live tenancy.'
);
$rqAdd('critical',
    'End date before start date',
    (int) ($rqFlags['end_before_start']['count'] ?? 0),
    'The tenancy ends before it begins, which makes its term and its schedule unreliable.'
);
$rqAdd('critical',
    'Move-out before move-in',
    (int) ($rqFlags['moveout_before_movein']['count'] ?? 0),
    'The recorded move-out date precedes the move-in date, so the occupied period cannot be '
    . 'read from those two columns.'
);
$rqAdd('warning',
    'Zero or negative rent',
    (int) ($rqFlags['zero_rent']['count'] ?? 0),
    'The tenancy contracts no rent, so it contributes nothing to the rent roll.'
);

$rqEnded = $rqFlags['ended_with_balance'] ?? null;
if ($rqEnded && (int) $rqEnded['count'] > 0 && (float) $rqEnded['amount'] > 0) {
    $rqRows[] = [
        'severity' => 'warning',
        'label' => 'Ended tenancies still owing',
        'count' => (int) $rqEnded['count'],
        'text'  => sprintf(
            'Terminated or expired tenancies with unsettled instalments — %s already overdue and '
            . '%s not yet due. This money is inside the company-wide arrears and outstanding '
            . 'totals, against lets that have already ended.',
            formatCurrency((float) $rqEnded['arrears']),
            formatCurrency((float) $rqEnded['not_yet_due'])
        ),
        'value' => formatCurrency((float) $rqEnded['amount']),
    ];
}

$qualityPanel = [
    'title'   => 'Tenancy data quality',
    'icon'    => 'bi-file-earmark-medical',
    'variant' => 'rentals',
    'note'    => 'Occupancy and the ledger are unaffected',
    'rows'    => $rqRows,
    'foot'    => 'Diagnostic only. No lease, schedule or property record is changed by this report.',
];
require __DIR__ . '/_quality_panel.php';
