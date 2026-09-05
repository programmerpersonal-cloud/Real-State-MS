<?php
/**
 * A maintenance table — one row per request.
 *
 * Rendered twice, in two modes:
 *
 *   open       the attention queue, ordered urgent-first then
 *              longest-waiting. That order is the one somebody working the
 *              queue actually wants; sorting by date alone buries an urgent
 *              request logged this morning under a low-priority one from May.
 *   completed  finished work, with the time it took where that can be
 *              measured. Rendered only when something has been completed —
 *              an empty "completed" table on a system that has never closed
 *              a request reads as a fault rather than as a fact.
 *
 * Age is measured from the date raised. It is not lateness: this system
 * defines no target response time for maintenance, so no row here is overdue,
 * only old.
 *
 * Expects: $mtMode, plus $queue, $done, $queueTotal, $queuePage, $queuePages,
 *          $doneTotal, $window, $filters, $compare
 *
 * Every local in this file is prefixed. A partial pulled in with require
 * shares the including view's variable scope; see the note in _kpi.php.
 */
$muMode     = $mtMode ?? 'open';
$muDone     = $muMode === 'completed';
$muRows     = $muDone ? ($done ?? []) : ($queue ?? []);
$muFiltered = reportFilterCount($filters) > 0;
$muCarry    = !empty($compare) ? ['compare' => '1'] : [];

// Nothing completed is a fact the KPI already states; a second empty panel
// repeating it is noise.
if ($muDone && !$muRows) {
    return;
}

$muTone = ['urgent' => 'danger', 'high' => 'orange', 'medium' => 'warning', 'low' => 'info'];
$muStatusTone = [
    'new' => 'info', 'under_review' => 'info', 'assigned' => 'warning',
    'in_progress' => 'primary', 'completed' => 'success',
    'rejected' => 'muted', 'cancelled' => 'muted',
];
?>
<div class="table-card<?= (!$muDone && $muRows) ? ' fd' : '' ?>">
    <div class="table-head">
        <h4 class="table-head__title">
            <?php if ($muDone): ?>
                Completed maintenance
            <?php else: ?>
                <i class="bi bi-list-check fd__icon" aria-hidden="true"></i>
                Maintenance attention queue
            <?php endif ?>
        </h4>
        <span class="table-head__note">
            <?php if ($muDone): ?>
                <?= number_format((int) $doneTotal) ?> completed
                <?= (int) $doneTotal === 1 ? 'request' : 'requests' ?>, most recent first
            <?php else: ?>
                <?= number_format((int) $queueTotal) ?> open
                <?= (int) $queueTotal === 1 ? 'request' : 'requests' ?>
                · most urgent first, then longest waiting
            <?php endif ?>
        </span>
    </div>

    <?php if (!$muRows): ?>
        <?= uiEmptyState([
            'icon'  => 'bi-check2-circle',
            'title' => 'Nothing outstanding',
            'desc'  => $muFiltered
                ? 'No open maintenance request matches the current filters.'
                : 'There is no open maintenance request in scope. Requests appear here from '
                . 'the moment they are logged until somebody closes them.',
            'actions' => $muFiltered ? [[
                'label' => 'Clear filters', 'icon' => 'bi-arrow-counterclockwise',
                'class' => 'btn--outline',
                'url'   => reportUrl($window, [], ['tab' => 'maintenance'] + $muCarry),
            ]] : [],
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">Maintenance requests, with priority, status, age and recorded cost.</caption>
                <thead>
                    <tr>
                        <th scope="col">Request</th>
                        <th scope="col">Property</th>
                        <th scope="col" class="col-mid">Type</th>
                        <th scope="col">Priority</th>
                        <th scope="col" class="col-mid">Status</th>
                        <th scope="col" class="col-lo">Raised</th>
                        <th scope="col"><?= $muDone ? 'Completed' : 'Waiting' ?></th>
                        <th scope="col" class="col-mid">Assigned</th>
                        <th scope="col" class="cell-num col-lo">Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($muRows as $muR): ?>
                        <?php
                        $muPriority = (string) $muR['priority'];
                        $muStatus   = (string) $muR['status'];
                        $muAge      = (int) $muR['age_days'];
                        $muCost     = (float) $muR['actual_cost'] > 0
                            ? (float) $muR['actual_cost']
                            : (float) $muR['cost_estimate'];
                        ?>
                        <tr>
                            <td><span class="hash"><?= sanitize((string) $muR['request_code']) ?></span></td>
                            <td>
                                <a class="tp-name" href="<?= sanitize(APP_URL . '/index.php?page=properties&action=show&id=' . (int) $muR['property_id']) ?>">
                                    <?= sanitize((string) $muR['property_title']) ?>
                                </a>
                                <div class="tp-code"><?= sanitize(categoryLabel((string) $muR['category'])) ?></div>
                            </td>
                            <td class="col-mid">
                                <?= $muR['issue_type'] !== null && trim((string) $muR['issue_type']) !== ''
                                    ? sanitize((string) $muR['issue_type'])
                                    : '<span class="text-subtle">Not recorded</span>' ?>
                            </td>
                            <td>
                                <span class="status status--<?= sanitize($muTone[$muPriority] ?? 'muted') ?>">
                                    <span class="status__dot" aria-hidden="true"></span>
                                    <?= sanitize(uiLabel($muPriority)) ?>
                                </span>
                            </td>
                            <td class="col-mid">
                                <span class="status status--<?= sanitize($muStatusTone[$muStatus] ?? 'muted') ?>">
                                    <span class="status__dot" aria-hidden="true"></span>
                                    <?= sanitize(uiLabel($muStatus)) ?>
                                </span>
                            </td>
                            <td class="col-lo pr-date"><?= sanitize(formatDate((string) $muR['raised_on'])) ?></td>
                            <td class="pr-date">
                                <?php if ($muDone): ?>
                                    <?= $muR['completion_date'] !== null
                                        ? sanitize(formatDate((string) $muR['completion_date']))
                                        : '<span class="text-subtle">Not recorded</span>' ?>
                                    <?php if ($muR['resolution_days'] !== null): ?>
                                        <div class="tp-code">took <?= number_format((int) $muR['resolution_days']) ?> days</div>
                                    <?php endif ?>
                                <?php else: ?>
                                    <?= number_format($muAge) ?> days
                                    <?php if ($muAge > 14): ?>
                                        <?php /* Old, and said in words. Not "overdue" — there
                                                 is no target to be late against. */ ?>
                                        <div class="tp-code">over a fortnight</div>
                                    <?php endif ?>
                                <?php endif ?>
                            </td>
                            <td class="col-mid">
                                <?php if (!empty($muR['assigned_name'])): ?>
                                    <?= sanitize((string) $muR['assigned_name']) ?>
                                <?php else: ?>
                                    <span class="text-subtle" title="Nobody is assigned to this request.">Unassigned</span>
                                <?php endif ?>
                            </td>
                            <td class="cell-num col-lo tp-money">
                                <?php if ($muCost > 0): ?>
                                    <?= sanitize(formatCurrency($muCost)) ?>
                                    <div class="tp-code"><?= (float) $muR['actual_cost'] > 0 ? 'actual' : 'estimate' ?></div>
                                <?php else: ?>
                                    <span class="text-subtle" aria-label="none">—</span>
                                <?php endif ?>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>

        <?php if (!$muDone && (int) $queuePages > 1): ?>
            <?php
            /* Unprefixed against this folder's convention because they are the
               pagination component's published contract. */
            $page       = (int) $queuePage;
            $totalPages = (int) $queuePages;
            require VIEWS_PATH . '/components/pagination.php';
            ?>
        <?php endif ?>

        <div class="table-foot">
            <p class="table-foot__note">
                <?php if ($muDone): ?>
                    Duration is measured from the date the request was raised to its recorded
                    completion date. Where no completion date exists the duration is left
                    blank rather than guessed from when the record was last edited.
                <?php else: ?>
                    Ordered by priority, then by how long each request has waited.
                    <strong>Waiting time is age, not lateness</strong> — this system defines no
                    target response time for maintenance, so nothing here is overdue.
                    Cost shows the actual where one is recorded, otherwise the estimate,
                    labelled accordingly.
                <?php endif ?>
            </p>
        </div>
    <?php endif ?>
</div>
