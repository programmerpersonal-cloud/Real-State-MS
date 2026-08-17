<?php
/**
 * Leases — the tenancy register.
 *
 * Two questions get asked of this list far more than any other: which
 * tenancies are about to end, and which ones owe money. Both are answered in
 * the row rather than by opening records one at a time — the term column says
 * how long is left, and the arrears column carries a figure that came from one
 * batched query rather than one query per lease.
 *
 * Vars from LeaseController::index().
 */
$fd   = $formData ?? [];
$errs = $formErrors ?? [];

$listUrl = APP_URL . '/index.php?page=leases';
$showUrl = static fn(int $id): string => $listUrl . '&action=show&id=' . $id;

$statusFilter = [
    'param'   => 'status',
    'value'   => $filters['status'] ?? '',
    'options' => $statuses,
    'counts'  => $statusCounts,
    'total'   => array_sum($statusCounts),
    'all'     => 'All leases',
];

/* Status and the renewal view both live above the table, so the toolbar
   carries them as hidden fields rather than offering a second control. */
$toolbar = [
    'page'   => 'leases',
    'keep'   => array_filter([
        'status' => $filters['status'] ?? '',
        'ending' => $endingSoon ? 'soon' : '',
    ]),
    'search' => [
        'name'        => 'search',
        'value'       => $filters['search'] ?? '',
        'label'       => 'Search leases',
        'placeholder' => 'Search by code, tenant or property…',
    ],
    'actions' => [
        ['label' => 'New Lease', 'icon' => 'bi-plus-lg', 'class' => 'btn--primary',
         'can' => 'leases.create',
         'url' => $listUrl . '&action=create',
         'attrs' => ['data-modal-open' => 'leaseCreateModal']],
    ],
];

$searchTerm = trim((string) ($filters['search'] ?? ''));
$isFiltered = $searchTerm !== '' || ($filters['status'] ?? '') !== '' || $endingSoon;

$dropParam = static function (string $key) use ($listUrl): string {
    $params = $_GET;
    unset($params[$key], $params['p'], $params['page']);
    return $listUrl . ($params ? '&' . http_build_query($params) : '');
};

/* The renewal view is a toggle rather than a filter chip: it is one saved
   question, and a date picker is a worse way to ask it. */
$endingUrl = $endingSoon ? $dropParam('ending') : (static function () use ($listUrl) {
    $params = $_GET;
    unset($params['p'], $params['page']);
    $params['ending'] = 'soon';
    return $listUrl . '&' . http_build_query($params);
})();

/* Whole days from today to a date, negative once it is past. */
$daysTo = static function (string $date): int {
    return (int) (new DateTimeImmutable('today'))->diff(new DateTimeImmutable($date . ' 00:00:00'))->format('%r%a');
};
?>

<?php require VIEWS_PATH . '/components/ui/status_filter.php'; ?>
<?php require VIEWS_PATH . '/components/ui/list_toolbar.php'; ?>

<div class="filter-chips">
    <a href="<?= sanitize($endingUrl) ?>"
       class="btn btn--sm <?= $endingSoon ? 'btn--primary' : 'btn--outline' ?>"
       <?= $endingSoon ? 'aria-pressed="true"' : 'aria-pressed="false"' ?>>
        <i class="bi bi-hourglass-split" aria-hidden="true"></i>
        Ending within 60 days
    </a>

    <?php if ($searchTerm !== ''): ?>
        <span class="filter-chip">
            <span class="filter-chip__key">Search:</span>
            &ldquo;<?= sanitize($searchTerm) ?>&rdquo;
            <a class="filter-chip__x" href="<?= sanitize($dropParam('search')) ?>" aria-label="Remove the search filter">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </a>
        </span>
    <?php endif ?>
    <?php if ($isFiltered): ?>
        <a href="<?= $listUrl ?>" class="btn btn--ghost btn--sm">Clear all</a>
    <?php endif ?>
</div>

<div class="table-card">
    <?php if (empty($leases)): ?>
        <?= uiEmptyState([
            'icon'     => 'bi-file-earmark-text',
            'filtered' => $isFiltered,
            'title'    => $isFiltered ? 'No leases match these filters' : 'No leases yet',
            'desc'     => $isFiltered
                ? 'Nothing in the register matches what you have selected.'
                : 'Creating a lease also writes its rent schedule, records the deposit and marks the property as rented.',
            'clearUrl' => $listUrl,
            'actions'  => [[
                'label' => 'New Lease', 'icon' => 'bi-plus-lg', 'can' => 'leases.create',
                'url'   => $listUrl . '&action=create',
                'attrs' => ['data-modal-open' => 'leaseCreateModal'],
            ]],
        ]) ?>
    <?php else: ?>
        <div class="table-head">
            <div class="table-head__title">
                <?= number_format($totalCount) ?> <?= $totalCount === 1 ? 'lease' : 'leases' ?>
                <?php if ($isFiltered): ?><span class="table-head__count">matching</span><?php endif ?>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <?= uiSortHeader('Code', ['asc' => 'code_asc', 'desc' => 'code_desc']) ?>
                        <th>Tenant</th>
                        <th>Property</th>
                        <?= uiSortHeader('Term ends', ['asc' => 'end_asc', 'desc' => 'end_desc'], 'sort', 'cell-date') ?>
                        <?= uiSortHeader('Rent', ['desc' => 'rent_desc', 'asc' => 'rent_asc'], 'sort', 'cell-num') ?>
                        <th class="cell-num">Arrears</th>
                        <?= uiSortHeader('Status', ['asc' => 'status_asc', 'desc' => 'status_desc']) ?>
                        <th class="cell-actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leases as $l): ?>
                        <?php
                        $id      = (int) $l['id'];
                        $owed    = (float) ($arrears[$id] ?? 0);
                        $isLive  = $l['status'] === 'active';
                        $left    = $daysTo($l['end_date']);
                        ?>
                        <tr>
                            <td class="cell-tight">
                                <a href="<?= sanitize($showUrl($id)) ?>" class="table__id"><?= sanitize($l['lease_code']) ?></a>
                            </td>
                            <td>
                                <?= uiPersonCell(
                                    $l['customer_name'],
                                    $l['customer_photo'] ?? null,
                                    $l['customer_phone'] ?? '',
                                    APP_URL . '/index.php?page=customers&action=show&id=' . (int) $l['customer_id']
                                ) ?>
                            </td>
                            <td>
                                <a href="<?= APP_URL ?>/index.php?page=properties&amp;action=show&amp;id=<?= (int) $l['property_id'] ?>" class="cell-strong">
                                    <?= sanitize($l['property_title']) ?>
                                </a>
                                <div class="person__meta"><?= sanitize($l['property_code']) ?></div>
                            </td>
                            <td class="cell-date">
                                <?= formatDate($l['end_date']) ?>
                                <?php if ($isLive): ?>
                                    <div class="person__meta<?= $left <= 60 ? ' text-warning' : '' ?>">
                                        <?php if ($left < 0): ?>
                                            Ended <?= abs($left) ?> day<?= abs($left) === 1 ? '' : 's' ?> ago
                                        <?php elseif ($left === 0): ?>
                                            Ends today
                                        <?php else: ?>
                                            <?= $left ?> day<?= $left === 1 ? '' : 's' ?> left
                                        <?php endif ?>
                                    </div>
                                <?php endif ?>
                            </td>
                            <td class="cell-num">
                                <?= formatCurrency((float) $l['rent_amount']) ?>
                                <div class="person__meta"><?= sanitize(uiLabel((string) $l['payment_schedule'])) ?></div>
                            </td>
                            <td class="cell-num">
                                <?php if ($owed > 0): ?>
                                    <span class="text-danger"><strong><?= formatCurrency($owed) ?></strong></span>
                                <?php else: ?>
                                    <span class="text-subtle">—</span>
                                <?php endif ?>
                            </td>
                            <td><?= uiStatus($l['status']) ?></td>
                            <td class="cell-actions">
                                <?= uiRowActions(array_merge(
                                    [
                                        ['label' => 'Open lease', 'icon' => 'bi-eye', 'can' => 'leases.show',
                                         'url' => $showUrl($id)],
                                        ['label' => 'View property', 'icon' => 'bi-buildings', 'can' => 'properties.show',
                                         'url' => APP_URL . '/index.php?page=properties&action=show&id=' . (int) $l['property_id']],
                                        ['label' => 'View tenant', 'icon' => 'bi-person', 'can' => 'customers.show',
                                         'url' => APP_URL . '/index.php?page=customers&action=show&id=' . (int) $l['customer_id']],
                                    ],
                                    // Renewing and terminating only mean anything on a live
                                    // tenancy. The controller re-checks the permission for
                                    // both; hiding them here only avoids offering a no-op.
                                    $isLive ? [[
                                        'label' => 'Renew lease', 'icon' => 'bi-arrow-repeat', 'can' => 'leases.renew',
                                        'url' => $listUrl . '&action=renew&id=' . $id,
                                    ]] : []
                                )) ?>
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

<?php require __DIR__ . '/_create_modal.php'; ?>
