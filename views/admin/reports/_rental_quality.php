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
 * Nothing here is corrected. No record is written.
 *
 * Expects: $leaseFlags (from CoreAnalytics::leaseIntegrityFlags())
 *
 * Every local in this file is prefixed. A partial pulled in with require
 * shares the including view's variable scope; see the note in _kpi.php.
 */
$rqFlags = $leaseFlags ?? [];
$rqRows  = [];

$rqAdd = static function (string $rqLabel, int $rqCount, string $rqText, ?string $rqValue = null) use (&$rqRows): void {
    if ($rqCount > 0) {
        $rqRows[] = ['label' => $rqLabel, 'count' => $rqCount, 'text' => $rqText, 'value' => $rqValue];
    }
};

$rqAdd(
    'Active past its end date',
    (int) ($rqFlags['active_past_end']['count'] ?? 0),
    'The tenancy is still flagged active although its end date has passed. Nothing rolls '
    . 'that status forward automatically, so it stays active until somebody closes it.'
);
$rqAdd(
    'Lease and property disagree',
    (int) ($rqFlags['status_disagrees']['count'] ?? 0),
    'An active tenancy whose property is not recorded as rented. Occupancy on this report '
    . 'is derived from the lease, so the figures above are unaffected.'
);
$rqAdd(
    'Two active tenancies on one property',
    (int) ($rqFlags['duplicate_active']['count'] ?? 0),
    'More than one lease is active against the same property at the same time. The schema '
    . 'permits it and nothing checks for it.'
);
$rqAdd(
    'End date before start date',
    (int) ($rqFlags['end_before_start']['count'] ?? 0),
    'The tenancy ends before it begins, which makes its term and its schedule unreliable.'
);
$rqAdd(
    'Move-out before move-in',
    (int) ($rqFlags['moveout_before_movein']['count'] ?? 0),
    'The recorded move-out date precedes the move-in date, so the occupied period cannot be '
    . 'read from those two columns.'
);
$rqAdd(
    'Zero or negative rent',
    (int) ($rqFlags['zero_rent']['count'] ?? 0),
    'The tenancy contracts no rent, so it contributes nothing to the rent roll.'
);

$rqEnded = $rqFlags['ended_with_balance'] ?? null;
if ($rqEnded && (int) $rqEnded['count'] > 0 && (float) $rqEnded['amount'] > 0) {
    $rqRows[] = [
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

if (!$rqRows) {
    return;
}
?>
<details class="dq dq--rentals">
    <summary class="dq__summary">
        <span class="dq__icon" aria-hidden="true"><i class="bi bi-file-earmark-medical"></i></span>
        <span class="dq__lead">
            <strong>Tenancy data quality</strong>
            <span class="dq__count">
                <?= count($rqRows) === 1 ? '1 item needs attention' : count($rqRows) . ' items need attention' ?>
            </span>
        </span>
        <span class="dq__note">Occupancy and the ledger are unaffected</span>
        <i class="bi bi-chevron-down dq__chev" aria-hidden="true"></i>
    </summary>

    <ul class="dq__list">
        <?php foreach ($rqRows as $rqRow): ?>
            <li class="dq__row">
                <span class="dq__row-icon" aria-hidden="true"><i class="bi bi-file-earmark-text"></i></span>
                <div class="dq__row-body">
                    <div class="dq__row-label">
                        <?= sanitize($rqRow['label']) ?>
                        <span class="dq__badge"><?= number_format((int) $rqRow['count']) ?></span>
                    </div>
                    <p class="dq__row-text"><?= sanitize($rqRow['text']) ?></p>
                </div>
                <?php if ($rqRow['value'] !== null): ?>
                    <div class="dq__row-value"><?= sanitize((string) $rqRow['value']) ?></div>
                <?php endif ?>
            </li>
        <?php endforeach ?>
    </ul>

    <p class="dq__foot">
        Diagnostic only. No lease, schedule or property record is changed by this report.
    </p>
</details>
