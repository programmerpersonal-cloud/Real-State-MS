<?php
/**
 * Data quality & integrity — one panel, four callers.
 *
 * The workspace grew four of these: the module-wide one on every report, and
 * a tenancy, a sales and a maintenance panel on their own tabs. All four had
 * arrived at the same markup by copy, which meant four places to fix a badge
 * and four chances for one of them to drift. This is that markup, once.
 *
 * What Phase 8 added is severity, and it is the whole point of the redesign.
 * Before, every finding looked the same: a lease whose end date precedes its
 * start date — a record that cannot be read at all — sat in the same amber
 * row as a payment deliberately held out of a total because it is dated next
 * month. A reader with eleven identical rows has no way to tell the broken
 * record from the accounting note, so the sensible response is to stop
 * reading them. Three levels, each said in a *word* and not only in a colour:
 *
 *   critical  the record contradicts itself or points at nothing. It cannot
 *             be read correctly by anything, including this report.
 *   warning   two records disagree. Both are readable; one of them is wrong
 *             and somebody has to decide which.
 *   note      nothing is wrong. A figure was deliberately excluded, or
 *             something could not be attributed, and the reader would
 *             otherwise find an unexplained gap.
 *
 * The panel opens by itself when it holds anything critical, and stays shut
 * otherwise — the requirement is that a critical problem is never hidden, not
 * that housekeeping is permanently in the way.
 *
 * Nothing here writes to the database. Every count comes from a detector that
 * counted rows and stopped.
 *
 * Expects $qualityPanel:
 *   title   string
 *   icon    string  bootstrap-icons name for the summary
 *   variant string  ''|'rentals' — which tone ramp the panel is drawn in
 *   note    string  the reassurance on the right of the summary
 *   foot    string  the line under the list
 *   rows    array   [['severity'=>'critical'|'warning'|'note', 'label'=>…,
 *                     'count'=>?int, 'text'=>…, 'value'=>?string], …]
 *
 * Every local in this file is prefixed. A partial pulled in with require
 * shares the including view's variable scope; see the note in _kpi.php.
 */
$qpC    = $qualityPanel ?? [];
$qpRows = $qpC['rows'] ?? [];
if (!$qpRows) {
    return;
}

/* Ordered by how much the reader needs to care, not by the order the
   detectors happen to run in. */
$qpRank = ['critical' => 0, 'warning' => 1, 'note' => 2];
usort($qpRows, static function (array $qpA, array $qpB) use ($qpRank): int {
    return ($qpRank[$qpA['severity'] ?? 'warning'] ?? 1)
       <=> ($qpRank[$qpB['severity'] ?? 'warning'] ?? 1);
});

$qpTally = ['critical' => 0, 'warning' => 0, 'note' => 0];
foreach ($qpRows as $qpR) {
    $qpKey = $qpR['severity'] ?? 'warning';
    if (isset($qpTally[$qpKey])) { $qpTally[$qpKey]++; }
}

$qpWords = [
    'critical' => ['critical', 'critical'],
    'warning'  => ['warning',  'warnings'],
    'note'     => ['note',     'notes'],
];
$qpSevLabel = ['critical' => 'Critical', 'warning' => 'Warning', 'note' => 'Note'];
$qpVariant  = (string) ($qpC['variant'] ?? '');
?>
<details class="dq<?= $qpVariant !== '' ? ' dq--' . sanitize($qpVariant) : '' ?><?= $qpTally['critical'] > 0 ? ' dq--has-critical' : '' ?>"
         <?= $qpTally['critical'] > 0 ? 'open' : '' ?>>
    <summary class="dq__summary">
        <span class="dq__icon" aria-hidden="true"><i class="bi <?= sanitize((string) ($qpC['icon'] ?? 'bi-clipboard-data')) ?>"></i></span>
        <span class="dq__lead">
            <strong><?= sanitize((string) ($qpC['title'] ?? 'Data quality & integrity')) ?></strong>
            <span class="dq__tally">
                <?php foreach ($qpTally as $qpSev => $qpN): ?>
                    <?php if ($qpN === 0) continue; ?>
                    <span class="dq__pip dq__pip--<?= sanitize($qpSev) ?>">
                        <?= (int) $qpN ?> <?= sanitize($qpN === 1 ? $qpWords[$qpSev][0] : $qpWords[$qpSev][1]) ?>
                    </span>
                <?php endforeach ?>
            </span>
        </span>
        <?php if (!empty($qpC['note'])): ?>
            <span class="dq__note"><?= sanitize((string) $qpC['note']) ?></span>
        <?php endif ?>
        <i class="bi bi-chevron-down dq__chev" aria-hidden="true"></i>
    </summary>

    <ul class="dq__list">
        <?php foreach ($qpRows as $qpRow): ?>
            <?php $qpSev = $qpRow['severity'] ?? 'warning'; ?>
            <li class="dq__row dq__row--<?= sanitize($qpSev) ?>">
                <?php /* The severity is a word before it is a colour. A row
                         that only turned red would tell a greyscale printout
                         and a colour-blind reader nothing at all. */ ?>
                <span class="dq__sev dq__sev--<?= sanitize($qpSev) ?>"><?= sanitize($qpSevLabel[$qpSev] ?? 'Warning') ?></span>
                <div class="dq__row-body">
                    <div class="dq__row-label">
                        <?= sanitize((string) $qpRow['label']) ?>
                        <?php if (($qpRow['count'] ?? null) !== null): ?>
                            <span class="dq__badge">
                                <?= number_format((int) $qpRow['count']) ?>
                                <span class="sr-only">affected <?= (int) $qpRow['count'] === 1 ? 'record' : 'records' ?></span>
                            </span>
                        <?php endif ?>
                    </div>
                    <p class="dq__row-text"><?= sanitize((string) $qpRow['text']) ?></p>
                </div>
                <?php if (($qpRow['value'] ?? null) !== null): ?>
                    <div class="dq__row-value"><?= sanitize((string) $qpRow['value']) ?></div>
                <?php endif ?>
            </li>
        <?php endforeach ?>
    </ul>

    <?php if (!empty($qpC['foot'])): ?>
        <p class="dq__foot"><?= sanitize((string) $qpC['foot']) ?></p>
    <?php endif ?>
</details>
