<?php
/**
 * Property approvals — the administrator's queue.
 *
 * An agent may create a listing; only an administrator may publish one. This
 * is where that decision is made, and it is the only screen in the system
 * that can make it — the permission behind the page is the same permission
 * behind the action, so nobody reaches a queue they cannot act on.
 *
 * Read oldest-first, because a queue is worked from the front, and each row
 * carries how long it has been waiting rather than only when it arrived: a
 * date makes the reader do arithmetic before they can tell what is urgent.
 *
 * Two decisions, weighted differently on purpose. Approve is a single button
 * on the row — it is the outcome most submissions get and the reason anybody
 * opened this page. Reject sits in the row menu and opens a dialog, because
 * it demands a reason: sending a listing back with no note leaves the agent
 * to guess what to change, and a silent rejection is how a submission bounces
 * three times.
 *
 * Vars from PropertyController::approvals().
 */
$covers  = $covers ?? [];
$base    = APP_URL . '/index.php?page=properties';
$listUrl = $base . '&action=approvals';
$showUrl = static fn(int $id): string => $base . '&action=show&id=' . $id;

$activeRegister = 'approvals';
$isPending = $state === 'pending';

$agentNames = array_column($agents ?? [], 'full_name', 'id');

$applied = array_filter([
    'search'   => $filters['search']   ?? '',
    'category' => $filters['category'] ?? '',
    'agent_id' => $filters['agent_id'] ?? '',
], static fn($v): bool => $v !== '' && $v !== null);

/* The three decisions a listing can be in. Same pill component every other
   queue in the system uses, so this page is read the same way as Payments or
   Maintenance rather than inventing a second vocabulary for "waiting". */
$statusFilter = [
    'param'   => 'state',
    'value'   => $state,
    'options' => ['pending' => 'Awaiting approval', 'approved' => 'Approved', 'rejected' => 'Returned'],
    'counts'  => [
        'pending'  => $approvalCounts['pending'],
        'approved' => $approvalCounts['approved'],
        'rejected' => $approvalCounts['rejected'],
    ],
    // No "All" pill: a decision is one of three, and a mixed list is not a
    // queue anybody works from.
    'all'     => null,
];

$toolbar = [
    'page'   => 'properties',
    'keep'   => ['action' => 'approvals', 'state' => $state],
    'search' => [
        'name'        => 'search',
        'value'       => $filters['search'] ?? '',
        'label'       => 'Search submissions',
        'placeholder' => 'Search by title, code or location…',
    ],
    'filters' => [
        ['name' => 'category', 'label' => 'Type', 'value' => $filters['category'] ?? '',
         'options' => $categories, 'all' => 'Any type'],
        ['name' => 'agent_id', 'label' => 'Agent', 'value' => $filters['agent_id'] ?? '',
         'options' => $agentNames, 'all' => 'Any agent'],
    ],
];

/** Approve — a signed POST, because it changes what the public can see. */
$approveButton = static function (array $p): string {
    return '<form method="POST" action="' . APP_URL . '/index.php?page=properties&amp;action=approve" class="inline-form">'
         . csrfField()
         . '<input type="hidden" name="id" value="' . (int) $p['id'] . '">'
         . '<input type="hidden" name="from" value="approvals">'
         . '<button type="submit" class="btn btn--primary btn--sm"'
         . ' data-confirm="It goes live on the public site immediately and can be reserved, let or sold. The agent is notified."'
         . ' data-confirm-title="Approve this listing?"'
         . ' data-confirm-action="Approve listing"'
         . ' data-confirm-tone="primary"'
         . ' data-confirm-record="' . sanitize($p['property_code'] . ' · ' . $p['title']) . '">'
         . '<i class="bi bi-check2-circle" aria-hidden="true"></i> Approve'
         . '</button></form>';
};
?>

<?php require __DIR__ . '/_register_tabs.php'; ?>

<?php require VIEWS_PATH . '/components/ui/status_filter.php'; ?>

<?php if ($isPending && $approvalCounts['pending'] > 0): ?>
    <div class="notice">
        <div class="notice__icon"><i class="bi bi-hourglass-split" aria-hidden="true"></i></div>
        <div class="notice__body">
            <div class="notice__title">
                <?= number_format($approvalCounts['pending']) ?>
                <?= $approvalCounts['pending'] === 1 ? 'listing is' : 'listings are' ?> waiting on you
            </div>
            <p class="notice__item">
                A listing submitted by an agent stays off the public site until it is
                approved here. Approving publishes it and tells the agent; returning it
                sends your note back to them so they know what to change.
            </p>
        </div>
    </div>
<?php endif ?>

<?php require VIEWS_PATH . '/components/ui/list_toolbar.php'; ?>

<?php if (empty($properties)): ?>
    <div class="table-card">
        <?= uiEmptyState([
            'icon'     => $isPending ? 'bi-check2-all' : 'bi-inbox',
            'filtered' => (bool) $applied,
            'title'    => $applied
                ? 'No submissions match these filters'
                : ($isPending ? 'Nothing is waiting for approval'
                              : ($state === 'approved' ? 'No approved listings yet' : 'Nothing has been returned')),
            'desc'     => $applied
                ? 'No submission matches what you have selected. Try widening the search or clearing a filter.'
                : ($isPending
                    ? 'Every listing has been decided. New submissions from agents will appear here, and you will get a notification when one arrives.'
                    : 'Decisions you make on submitted listings are recorded here.'),
            'clearUrl' => $listUrl . '&state=' . $state,
            'actions'  => [[
                'label' => 'Back to properties', 'icon' => 'bi-arrow-left', 'class' => 'btn--outline',
                'can'   => 'properties.view',
                'url'   => $base,
            ]],
        ]) ?>
    </div>
<?php else: ?>

<div class="table-card">
    <div class="table-head">
        <div class="table-head__title">
            <?= number_format($totalCount) ?>
            <?= $totalCount === 1 ? 'submission' : 'submissions' ?>
            <?php if ($applied): ?><span class="table-head__count">matching</span><?php endif ?>
        </div>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Property</th>
                    <th class="col-mid">Type</th>
                    <th class="col-mid">Owner</th>
                    <th>Submitted by</th>
                    <?= uiSortHeader('Submitted', ['asc' => 'oldest', 'desc' => 'newest'], 'sort', 'cell-date col-mid') ?>
                    <th>Status</th>
                    <th class="cell-actions"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($properties as $p): ?>
                    <?php
                    $id     = (int) $p['id'];
                    $cover  = $covers[$id] ?? null;
                    $record = $p['property_code'] . ' · ' . $p['title'];
                    ?>
                    <tr>
                        <td>
                            <div class="media-cell">
                                <img class="media-cell__thumb"
                                     src="<?= sanitize(propertyImage($p, $cover)) ?>"
                                     alt="" loading="lazy" width="56" height="42">
                                <div class="media-cell__body">
                                    <a href="<?= $showUrl($id) ?>" class="cell-strong">
                                        <?= sanitize($p['title']) ?>
                                    </a>
                                    <div class="person__meta">
                                        <span class="table__id"><?= sanitize($p['property_code']) ?></span>
                                        <?php if (!empty($p['location'])): ?>
                                            <span><i class="bi bi-geo-alt" aria-hidden="true"></i> <?= sanitize($p['location']) ?></span>
                                        <?php endif ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="col-mid">
                            <span class="text-muted">
                                <i class="bi <?= categoryIcon($p['category']) ?>" aria-hidden="true"></i>
                                <?= sanitize(uiLabel($p['category'])) ?>
                            </span>
                        </td>
                        <td class="col-mid"><?= sanitize($p['owner_name'] ?: '—') ?></td>
                        <td>
                            <?php /* Who filed it, falling back to the assigned agent for
                                     records created before submissions were signed. */ ?>
                            <?= sanitize($p['submitted_by_name'] ?: ($p['agent_name'] ?: '—')) ?>
                            <?php if (!empty($p['submitted_by_name']) && !empty($p['agent_name'])
                                      && $p['submitted_by_name'] !== $p['agent_name']): ?>
                                <div class="person__meta">assigned to <?= sanitize($p['agent_name']) ?></div>
                            <?php endif ?>
                        </td>
                        <td class="cell-date col-mid">
                            <?= formatDate($p['created_at']) ?>
                            <div class="person__meta"><?= sanitize(timeAgo($p['created_at'])) ?></div>
                        </td>
                        <td>
                            <?= uiStatus($p['approval_status'],
                                         $p['approval_status'] === 'pending' ? 'Awaiting approval'
                                       : ($p['approval_status'] === 'rejected' ? 'Returned' : 'Approved')) ?>
                            <?php if (!empty($p['approval_note'])): ?>
                                <?php /* The reason travels with the row, so a returned
                                         listing explains itself without a second click. */ ?>
                                <div class="person__meta" title="<?= sanitize($p['approval_note']) ?>">
                                    <?= sanitize(truncate($p['approval_note'], 48)) ?>
                                </div>
                            <?php elseif (!empty($p['approved_by_name'])): ?>
                                <div class="person__meta">by <?= sanitize($p['approved_by_name']) ?></div>
                            <?php endif ?>
                        </td>
                        <td class="cell-actions">
                            <div class="cell-actions__pair">
                                <?php if ($p['approval_status'] !== 'approved'): ?>
                                    <?= $approveButton($p) ?>
                                <?php endif ?>
                                <?= uiRowActions(array_values(array_filter([
                                    ['label' => 'View details', 'icon' => 'bi-eye',
                                     'url' => $showUrl($id), 'can' => 'properties.show'],
                                    ['label' => 'Edit', 'icon' => 'bi-pencil',
                                     'url' => $base . '&action=edit&id=' . $id, 'can' => 'properties.edit'],
                                    $p['approval_status'] !== 'rejected' ? [
                                        'label' => 'Return with a note', 'icon' => 'bi-arrow-counterclockwise',
                                        'danger' => true, 'url' => '#',
                                        'attrs' => [
                                            'data-modal-open'  => 'propertyRejectModal',
                                            'data-fill-id'     => (string) $id,
                                            'data-fill-record' => $record,
                                        ],
                                    ] : null,
                                ]))) ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="table-foot">
            <span class="table-foot__note">Page <?= $page ?> of <?= $totalPages ?></span>
            <?php require VIEWS_PATH . '/components/pagination.php'; ?>
        </div>
    <?php endif ?>
</div>

<?php endif ?>

<?php
/* One dialog for the whole page rather than one per row — see the partial.
   Decisions taken here return to the queue, because there is usually a next
   one waiting. */
$rejectFrom = 'approvals';
require __DIR__ . '/_reject_modal.php';
