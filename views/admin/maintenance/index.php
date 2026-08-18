<?php
/**
 * Maintenance — the work queue.
 *
 * Ordered by priority rather than by date, because that is how a queue is
 * worked: the urgent job filed last week outranks the low-priority one filed
 * this morning. The status pills carry counts scoped to whoever is reading,
 * so a technician sees the size of their own queue and an owner theirs.
 *
 * Vars from MaintenanceController::index().
 */
$fd   = $formData ?? [];
$errs = $formErrors ?? [];

$listUrl = APP_URL . '/index.php?page=maintenance';
$showUrl = static fn(int $id): string => $listUrl . '&action=show&id=' . $id;

$statusFilter = [
    'param'   => 'status',
    'value'   => $filters['status'] ?? '',
    'options' => $statuses,
    'counts'  => $statusCounts,
    'total'   => array_sum($statusCounts),
    'all'     => 'All requests',
];

$toolbar = [
    'page'   => 'maintenance',
    'keep'   => array_filter(['status' => $filters['status'] ?? '']),
    'search' => [
        'name'        => 'search',
        'value'       => $filters['search'] ?? '',
        'label'       => 'Search requests',
        'placeholder' => 'Search by code, property or fault…',
    ],
    'filters' => [
        ['name' => 'priority', 'label' => 'Priority', 'value' => $filters['priority'] ?? '',
         'options' => $priorities, 'all' => 'Any priority'],
    ],
    'actions' => !empty($canCreate) ? [
        ['label' => 'New Request', 'icon' => 'bi-plus-lg', 'class' => 'btn--primary',
         'can' => 'maintenance.create',
         'url' => $listUrl . '&action=create',
         'attrs' => ['data-modal-open' => 'maintenanceCreateModal']],
    ] : [],
];

$applied = array_filter([
    'search'   => $filters['search'] ?? '',
    'priority' => $filters['priority'] ?? '',
], static fn($v): bool => $v !== '' && $v !== null);

$chipLabels = [
    'search'   => ['Search',   static fn($v) => '“' . $v . '”'],
    'priority' => ['Priority', static fn($v) => $priorities[$v] ?? $v],
];

$isFiltered = (bool) $applied || ($filters['status'] ?? '') !== '';

$without = static function (string $key) use ($listUrl): string {
    $params = $_GET;
    unset($params[$key], $params['p'], $params['page']);
    return $listUrl . ($params ? '&' . http_build_query($params) : '');
};

/* Statuses at which a job is finished, and so no longer ageing. */
$settled = ['completed', 'rejected', 'cancelled'];
?>

<?php require VIEWS_PATH . '/components/ui/status_filter.php'; ?>
<?php require VIEWS_PATH . '/components/ui/list_toolbar.php'; ?>

<?php if ($applied): ?>
    <div class="filter-chips">
        <span class="filter-chips__label">Filtered by</span>
        <?php foreach ($applied as $key => $value): ?>
            <?php [$label, $format] = $chipLabels[$key]; ?>
            <span class="filter-chip">
                <span class="filter-chip__key"><?= sanitize($label) ?>:</span>
                <?= sanitize((string) $format($value)) ?>
                <a class="filter-chip__x" href="<?= sanitize($without($key)) ?>"
                   aria-label="Remove the <?= sanitize(strtolower($label)) ?> filter">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </a>
            </span>
        <?php endforeach ?>
        <a href="<?= $listUrl ?>" class="btn btn--ghost btn--sm">Clear all</a>
    </div>
<?php endif ?>

<div class="table-card">
    <?php if (empty($requests)): ?>
        <?= uiEmptyState([
            'icon'     => 'bi-tools',
            'filtered' => $isFiltered,
            'title'    => $isFiltered ? 'No requests match these filters' : 'Nothing in the queue',
            // Someone whose scope is empty is not looking at an empty system —
            // they are looking at their own empty share of it, and the two need
            // different wording or the page reads as broken.
            'desc'     => $isFiltered
                ? 'No maintenance request matches what you have selected.'
                : (empty($canCreate) ? maintenanceEmptyScopeMessage()
                    : 'Reported faults land here, most urgent first, and stay until they are closed.'),
            'clearUrl' => $listUrl,
            'actions'  => !empty($canCreate) ? [[
                'label' => 'New Request', 'icon' => 'bi-plus-lg', 'can' => 'maintenance.create',
                'url'   => $listUrl . '&action=create',
                'attrs' => ['data-modal-open' => 'maintenanceCreateModal'],
            ]] : [],
        ]) ?>
    <?php else: ?>
        <div class="table-head">
            <div class="table-head__title">
                <?= number_format($totalCount) ?> <?= $totalCount === 1 ? 'request' : 'requests' ?>
                <?php if ($isFiltered): ?><span class="table-head__count">matching</span><?php endif ?>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <?= uiSortHeader('Priority', ['desc' => 'priority', 'asc' => 'priority_asc']) ?>
                        <?= uiSortHeader('Code', ['asc' => 'code_asc', 'desc' => 'code_desc'], 'sort', 'col-lo') ?>
                        <th class="col-mid">Property</th>
                        <th>Fault</th>
                        <th class="col-mid">Assigned to</th>
                        <?= uiSortHeader('Reported', ['desc' => 'newest', 'asc' => 'oldest'], 'sort', 'cell-date') ?>
                        <?= uiSortHeader('Status', ['asc' => 'status_asc', 'desc' => 'status_desc']) ?>
                        <th class="cell-actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $r): ?>
                        <?php
                        $id   = (int) $r['id'];
                        $open = !in_array($r['status'], $settled, true);
                        $age  = (int) (new DateTimeImmutable($r['created_at']))
                                    ->diff(new DateTimeImmutable('now'))->format('%a');
                        ?>
                        <tr>
                            <td class="cell-tight"><?= uiPriority((string) $r['priority']) ?></td>
                            <td class="col-lo cell-tight">
                                <a href="<?= sanitize($showUrl($id)) ?>" class="table__id"><?= sanitize($r['request_code']) ?></a>
                            </td>
                            <td class="col-mid">
                                <a href="<?= APP_URL ?>/index.php?page=properties&amp;action=show&amp;id=<?= (int) $r['property_id'] ?>" class="cell-strong">
                                    <?= sanitize($r['property_title']) ?>
                                </a>
                                <div class="person__meta"><?= sanitize($r['property_code']) ?></div>
                            </td>
                            <td class="cell-clip">
                                <?php if (!empty($r['issue_type'])): ?>
                                    <div class="cell-strong"><?= sanitize($r['issue_type']) ?></div>
                                <?php endif ?>
                                <div class="person__meta"><?= sanitize(truncate($r['description'], 70)) ?></div>
                            </td>
                            <td class="col-mid">
                                <?php if (!empty($r['assigned_name'])): ?>
                                    <?= uiPersonCell($r['assigned_name'], $r['assigned_avatar'] ?? null) ?>
                                <?php else: ?>
                                    <span class="text-subtle">Unassigned</span>
                                <?php endif ?>
                            </td>
                            <td class="cell-date">
                                <?= formatDate($r['created_at']) ?>
                                <?php if ($open): ?>
                                    <div class="person__meta<?= $age >= 14 ? ' text-warning' : '' ?>">
                                        <?php if ($age === 0): ?>today<?php else: ?>open <?= $age ?> day<?= $age === 1 ? '' : 's' ?><?php endif ?>
                                    </div>
                                <?php endif ?>
                            </td>
                            <td>
                                <?= uiStatus($r['status']) ?>
                                <?php if ((float) ($r['actual_cost'] ?? 0) > 0): ?>
                                    <div class="person__meta"><?= formatCurrency((float) $r['actual_cost']) ?></div>
                                <?php elseif ((float) ($r['cost_estimate'] ?? 0) > 0): ?>
                                    <div class="person__meta">est. <?= formatCurrency((float) $r['cost_estimate']) ?></div>
                                <?php endif ?>
                            </td>
                            <td class="cell-actions">
                                <?= uiRowActions([
                                    ['label' => 'Open request', 'icon' => 'bi-eye', 'can' => 'maintenance.show',
                                     'url' => $showUrl($id)],
                                    ['label' => 'View property', 'icon' => 'bi-buildings', 'can' => 'properties.show',
                                     'url' => APP_URL . '/index.php?page=properties&action=show&id=' . (int) $r['property_id']],
                                ]) ?>
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
    <?php endif ?>
</div>

<?php
// The quick-add popup is only mounted when this user can actually file
// something — otherwise its trigger is absent anyway.
if (!empty($canCreate)) {
    require __DIR__ . '/_create_modal.php';
}
?>
