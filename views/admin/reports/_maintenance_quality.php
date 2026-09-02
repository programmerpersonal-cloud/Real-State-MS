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
 * On severity: a completion date earlier than the date the request was
 * raised, a negative cost, and a request on a property that no longer exists
 * are *critical* — each is a row that cannot be read correctly at all. An
 * open request with nobody assigned is a warning: the record is fine, the
 * work simply has no owner. A completed job with an estimate and no actual
 * cost is a note — nothing is broken, the outturn is just unknown.
 *
 * Expects: $maintFlags (from CoreAnalytics::maintenanceIntegrityFlags())
 *
 * Every local in this file is prefixed. A partial pulled in with require
 * shares the including view's variable scope; see the note in _kpi.php.
 */
$mqFlags = $maintFlags ?? [];
$mqRows  = [];

$mqAdd = static function (string $mqSev, string $mqLabel, array $mqFlag, string $mqText) use (&$mqRows): void {
    if ((int) ($mqFlag['count'] ?? 0) > 0) {
        $mqRows[] = ['severity' => $mqSev, 'label' => $mqLabel,
                     'count' => (int) $mqFlag['count'], 'text' => $mqText, 'value' => null];
    }
};

$mqAdd('warning', 'Open request with nobody assigned', $mqFlags['open_unassigned'] ?? [],
    'The request is open and no member of staff is assigned to it, so nobody owns the work.');

$mqAdd('warning', 'Marked assigned with no assignee', $mqFlags['assigned_no_staff'] ?? [],
    'The status says assigned but the assignee field is empty — the two disagree about '
    . 'whether anyone has picked the job up.');

$mqAdd('warning', 'Completed with no completion date', $mqFlags['completed_no_date'] ?? [],
    'Marked completed but carrying no completion date, so the request cannot contribute to '
    . 'resolution time and is excluded from that measure.');

$mqAdd('warning', 'Open but carrying a completion date', $mqFlags['open_with_date'] ?? [],
    'Still open although a completion date is recorded against it. One of the two is wrong '
    . 'and the report trusts the status.');

$mqAdd('critical', 'Completed before it was raised', $mqFlags['completed_before_raised'] ?? [],
    'The completion date precedes the date the request was logged, which makes any duration '
    . 'derived from the pair meaningless. These are excluded from resolution time.');

$mqAdd('note', 'No issue type recorded', $mqFlags['no_type'] ?? [],
    'The request describes no type of issue, so it cannot be grouped with similar work.');

$mqAdd('critical', 'Negative cost', $mqFlags['negative_cost'] ?? [],
    'A cost estimate or actual cost below zero, which no maintenance job can genuinely have.');

$mqAdd('note', 'Completed with an estimate but no actual cost', $mqFlags['completed_no_cost'] ?? [],
    'The job was costed in advance and finished, but no actual cost was ever recorded — so '
    . 'what it really cost is unknown.');

$mqAdd('critical', 'Request on a missing property', $mqFlags['orphan_property'] ?? [],
    'The property the request refers to no longer exists, so the request cannot be scoped '
    . 'or attributed.');

$qualityPanel = [
    'title'   => 'Maintenance data quality',
    'icon'    => 'bi-tools',
    'variant' => 'rentals',
    'note'    => 'Figures above are unaffected',
    'rows'    => $mqRows,
    'foot'    => 'Diagnostic only. No maintenance record is changed by this report.',
];
require __DIR__ . '/_quality_panel.php';
