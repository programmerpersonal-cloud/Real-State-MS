<?php
/**
 * Data quality — where the database contradicts itself.
 *
 * The Phase 0 audit found four kinds of disagreement in records that were
 * each individually valid: a payment filed against the wrong kind of
 * contract, a property let but still listed as available, rent received
 * without its schedule row being closed, and money on properties assigned to
 * nobody. None of them is a reporting bug and none is fixed here — Phase 1's
 * detectors count them, and this builds the rows.
 *
 * Three decisions about tone, all deliberate:
 *
 *  · Collapsed by default, and rendered only when something is actually
 *    wrong. A permanent warning strip is a warning nobody reads.
 *
 *  · Stated flatly, without alarm. A handful of inconsistencies in a small
 *    ledger is housekeeping, not a crisis, and dressing it in red would be
 *    crying wolf on the day something genuinely breaks. The figures above
 *    are still correct — that is the point of having settled the definitions
 *    first.
 *
 *  · Severity is assigned by *what kind of thing* the finding is, and the
 *    line is drawn at whether the reader has anything to decide. A payment
 *    filed against the wrong contract type is a disagreement between two
 *    readable records, so it is a warning: somebody has to say which one is
 *    right. Money deliberately held out of a total because it is dated next
 *    month is not wrong at all — it is a note, and it exists only so a reader
 *    reconciling against the payments register does not find a gap with no
 *    explanation attached to it. Nothing this panel currently detects is
 *    critical, and it does not invent one to fill the level.
 *
 * No record is modified, and no SQL is shown. What a reader gets is a
 * description of the disagreement and where to go and look.
 *
 * Expects: $dataQuality (from reportDataQuality()), $unattributed, $ledger
 * Optional: $futureDated (from CoreAnalytics::futureDatedExcluded())
 *
 * Every local in this file is prefixed. A partial pulled in with require
 * shares the including view's variable scope; see the note in _kpi.php.
 */
$dqData = $dataQuality ?? null;
if (!$dqData) {
    return;
}

$dqRows = [];

if ($dqData['payments']['count'] > 0) {
    $dqRows[] = [
        'severity' => 'warning',
        'label' => 'Payment classification',
        'count' => $dqData['payments']['count'],
        'text'  => $dqData['payments']['count'] === 1
            ? 'One payment is filed against a contract of a different kind from its own type. Revenue counts it by the contract it names.'
            : sprintf('%d payments are filed against contracts of a different kind from their own type. Revenue counts them by the contract they name.', $dqData['payments']['count']),
        'value' => formatCurrency($dqData['payments']['amount']),
    ];
}

foreach ($dqData['states'] as $dqIssue) {
    if ($dqIssue['count'] > 0) {
        $dqRows[] = [
            'severity' => 'warning',
            'label' => $dqIssue['label'],
            'count' => $dqIssue['count'],
            'text'  => $dqIssue['detail'] . ' Occupancy is derived from leases, so this does not affect the figures above.',
            'value' => null,
        ];
    }
}

if (!empty($ledger) && isset($ledger['ledger_gap']) && abs((float) $ledger['ledger_gap']) >= 0.005) {
    $dqGap = (float) $ledger['ledger_gap'];
    $dqRows[] = [
        'severity' => 'warning',
        'label' => 'Rent ledger reconciliation',
        'count' => null,
        'text'  => $dqGap > 0
            ? 'More rent was received in this period than the schedule marks as settled. A payment was taken without its instalment being closed.'
            : 'The schedule marks more rent settled in this period than was received. An instalment was closed without a matching payment.',
        'value' => formatCurrency(abs($dqGap)),
    ];
}

/* Money the approved definition deliberately leaves out. Not a fault in the
   data and not a fault in the report — but a reader comparing this against
   the payments register would find a gap and no explanation for it, and an
   unexplained gap is how people stop trusting a total. */
if (!empty($futureDated) && $futureDated['count'] > 0) {
    $dqRows[] = [
        'severity' => 'note',
        'label' => 'Future-dated payments excluded',
        'count' => $futureDated['count'],
        'text'  => sprintf(
            '%s recorded as paid but dated after today. Collected revenue counts money as at '
            . 'the reporting date, so %s not included in the totals above.',
            $futureDated['count'] === 1 ? 'One payment is' : $futureDated['count'] . ' payments are',
            $futureDated['count'] === 1 ? 'it is' : 'they are'
        ),
        'value' => formatCurrency($futureDated['amount']),
    ];
}

if (!empty($unattributed) && $unattributed['count'] > 0) {
    $dqRows[] = [
        'severity' => 'note',
        'label' => 'Unattributed revenue',
        'count' => $unattributed['count'],
        'text'  => 'Collected on properties with no agent assigned, so it appears in company totals but on no agent\'s row.',
        'value' => formatCurrency($unattributed['amount'])
                 . ($unattributed['share'] !== null ? ' · ' . reportPercent($unattributed['share']) . ' of revenue' : ''),
    ];
}

$qualityPanel = [
    'title'   => 'Data quality & integrity',
    'icon'    => 'bi-clipboard-data',
    'note'    => 'Figures above are unaffected',
    'rows'    => $dqRows,
    'foot'    => 'Nothing here is changed automatically. These are records that disagree with '
               . 'each other and are worth correcting at source.',
];
require __DIR__ . '/_quality_panel.php';
