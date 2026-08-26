<?php
/**
 * Maintenance data quality.
 *
 * Conditions the maintenance table permits and no workflow prevents. Each is
 * a record somebody needs to look at; none is corrected here.
 *
 * The two worth reading twice are the completion-date pair. A request marked
 * completed with no completion date cannot contribute to resolution time, and
 * a request still open that carries one is contradicting itself — both are
 * why the resolution figure on this report is measured rather than assumed,
 * and why it declines to report an average when it has nothing to average.
 *
 * Expects: $maintFlags (from CoreAnalytics::maintenanceIntegrityFlags())
 *
 * Every local in this file is prefixed. A partial pulled in with require
 * shares the including view's variable scope; see the note in _kpi.php.
 */
$mqFlags = $maintFlags ?? [];
$mqRows  = [];

$mqAdd = static function (string $mqLabel, array $mqFlag, string $mqText) use (&$mqRows): void {
    if ((int) ($mqFlag['count'] ?? 0) > 0) {
        $mqRows[] = ['label' => $mqLabel, 'count' => (int) $mqFlag['count'], 'text' => $mqText];
    }
};

$mqAdd('Open request with nobody assigned', $mqFlags['open_unassigned'] ?? [],
    'The request is open and no member of staff is assigned to it, so nobody owns the work.');

$mqAdd('Marked assigned with no assignee', $mqFlags['assigned_no_staff'] ?? [],
    'The status says assigned but the assignee field is empty — the two disagree about '
    . 'whether anyone has picked the job up.');

$mqAdd('Completed with no completion date', $mqFlags['completed_no_date'] ?? [],
    'Marked completed but carrying no completion date, so the request cannot contribute to '
    . 'resolution time and is excluded from that measure.');

$mqAdd('Open but carrying a completion date', $mqFlags['open_with_date'] ?? [],
    'Still open although a completion date is recorded against it. One of the two is wrong '
    . 'and the report trusts the status.');

$mqAdd('Completed before it was raised', $mqFlags['completed_before_raised'] ?? [],
    'The completion date precedes the date the request was logged, which makes any duration '
    . 'derived from the pair meaningless. These are excluded from resolution time.');

$mqAdd('No issue type recorded', $mqFlags['no_type'] ?? [],
    'The request describes no type of issue, so it cannot be grouped with similar work.');

$mqAdd('Negative cost', $mqFlags['negative_cost'] ?? [],
    'A cost estimate or actual cost below zero, which no maintenance job can genuinely have.');

$mqAdd('Completed with an estimate but no actual cost', $mqFlags['completed_no_cost'] ?? [],
    'The job was costed in advance and finished, but no actual cost was ever recorded — so '
    . 'what it really cost is unknown.');

$mqAdd('Request on a missing property', $mqFlags['orphan_property'] ?? [],
    'The property the request refers to no longer exists, so the request cannot be scoped '
    . 'or attributed.');

if (!$mqRows) {
    return;
}
?>
<details class="dq dq--rentals">
    <summary class="dq__summary">
        <span class="dq__icon" aria-hidden="true"><i class="bi bi-tools"></i></span>
        <span class="dq__lead">
            <strong>Maintenance data quality</strong>
            <span class="dq__count">
                <?= count($mqRows) === 1 ? '1 item needs attention' : count($mqRows) . ' items need attention' ?>
            </span>
        </span>
        <span class="dq__note">Figures above are unaffected</span>
        <i class="bi bi-chevron-down dq__chev" aria-hidden="true"></i>
    </summary>

    <ul class="dq__list">
        <?php foreach ($mqRows as $mqRow): ?>
            <li class="dq__row">
                <span class="dq__row-icon" aria-hidden="true"><i class="bi bi-wrench-adjustable"></i></span>
                <div class="dq__row-body">
                    <div class="dq__row-label">
                        <?= sanitize($mqRow['label']) ?>
                        <span class="dq__badge"><?= number_format((int) $mqRow['count']) ?></span>
                    </div>
                    <p class="dq__row-text"><?= sanitize($mqRow['text']) ?></p>
                </div>
            </li>
        <?php endforeach ?>
    </ul>

    <p class="dq__foot">
        Diagnostic only. No maintenance record is changed by this report.
    </p>
</details>
