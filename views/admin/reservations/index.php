<?php
/**
 * Reservations — the hold queue.
 *
 * A reservation is a clock: it exists to expire. So the list is built around
 * time remaining rather than creation order, the status pills carry counts so
 * the size of each queue is readable before anything is clicked, and a hold
 * about to lapse says so in the row instead of leaving someone to subtract
 * two dates in their head.
 *
 * Vars from ReservationController::index().
 */
$fd   = $formData ?? [];
$errs = $formErrors ?? [];

$listUrl = APP_URL . '/index.php?page=reservations';

/* Status lives in the pills above the table, so the toolbar carries it as a
   hidden field rather than offering a second control for the same thing. */
$statusFilter = [
    'param'   => 'status',
    'value'   => $filters['status'] ?? '',
    'options' => $statuses,
    'counts'  => $statusCounts,
    'total'   => array_sum($statusCounts),
    'all'     => 'All reservations',
];

$toolbar = [
    'page'   => 'reservations',
    'keep'   => array_filter(['status' => $filters['status'] ?? '']),
    'search' => [
        'name'        => 'search',
        'value'       => $filters['search'] ?? '',
        'label'       => 'Search reservations',
        'placeholder' => 'Search by code, customer or property…',
    ],
    /* The create action lives in the page header, which every module has and
       which sits directly above this strip. Offering it here as well put the
       same button on screen twice. */
];

$searchTerm = trim((string) ($filters['search'] ?? ''));
$isFiltered = $searchTerm !== '' || ($filters['status'] ?? '') !== '';

/* The same URL minus the search term, for the chip's × . */
$withoutSearch = static function () use ($listUrl): string {
    $params = $_GET;
    unset($params['search'], $params['p'], $params['page']);
    return $listUrl . ($params ? '&' . http_build_query($params) : '');
};

/* Whole days left on a hold, counted from midnight to midnight so a hold that
   ends tomorrow reads "1 day" all of today rather than flipping at the hour it
   was created. */
$daysLeft = static function (string $expiry): int {
    $end   = new DateTimeImmutable($expiry . ' 00:00:00');
    $today = new DateTimeImmutable('today');
    return (int) $today->diff($end)->format('%r%a');
};
?>

<?php require VIEWS_PATH . '/components/ui/status_filter.php'; ?>
<?php require VIEWS_PATH . '/components/ui/list_toolbar.php'; ?>

<?php if ($searchTerm !== ''): ?>
    <div class="filter-chips">
        <span class="filter-chips__label">Filtered by</span>
        <span class="filter-chip">
            <span class="filter-chip__key">Search:</span>
            &ldquo;<?= sanitize($searchTerm) ?>&rdquo;
            <a class="filter-chip__x" href="<?= sanitize($withoutSearch()) ?>" aria-label="Remove the search filter">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </a>
        </span>
    </div>
<?php endif ?>

<div class="table-card">
    <?php if (empty($reservations)): ?>
        <?= uiEmptyState([
            'icon'     => 'bi-calendar-check',
            'filtered' => $isFiltered,
            'title'    => $isFiltered ? 'No reservations match these filters' : 'No reservations yet',
            'desc'     => $isFiltered
                ? 'Nothing in the queue matches what you have selected.'
                : 'Reserving a property holds it off the market until the expiry date, or until the booking is confirmed.',
            'clearUrl' => $listUrl,
            'actions'  => [[
                'label' => 'New Reservation', 'icon' => 'bi-plus-lg', 'can' => 'reservations.create',
                'url'   => $listUrl . '&action=create',
                'attrs' => ['data-modal-open' => 'reservationCreateModal'],
            ]],
        ]) ?>
    <?php else: ?>
        <div class="table-head">
            <div class="table-head__title">
                <?= number_format($totalCount) ?> <?= $totalCount === 1 ? 'reservation' : 'reservations' ?>
                <?php if ($isFiltered): ?><span class="table-head__count">matching</span><?php endif ?>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <?= uiSortHeader('Code', ['asc' => 'code_asc', 'desc' => 'code_desc'], 'sort', 'col-lo') ?>
                        <th>Property</th>
                        <th>Customer</th>
                        <?= uiSortHeader('Expires', ['asc' => 'expiry_asc', 'desc' => 'expiry_desc'], 'sort', 'cell-date') ?>
                        <?= uiSortHeader('Deposit', ['desc' => 'deposit_desc', 'asc' => 'deposit_asc'], 'sort', 'cell-num col-mid') ?>
                        <?= uiSortHeader('Status', ['asc' => 'status_asc', 'desc' => 'status_desc']) ?>
                        <th class="cell-actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reservations as $r): ?>
                        <?php
                        $isActive = $r['status'] === 'active';
                        $left     = $daysLeft($r['expiry_date']);
                        $actionId = '&id=' . (int) $r['id'];
                        ?>
                        <tr>
                            <td class="cell-tight col-lo"><span class="table__id"><?= sanitize($r['reservation_code']) ?></span></td>
                            <td>
                                <a href="<?= APP_URL ?>/index.php?page=properties&amp;action=show&amp;id=<?= (int) $r['property_id'] ?>" class="cell-strong">
                                    <?= sanitize($r['property_title']) ?>
                                </a>
                                <div class="person__meta"><?= sanitize($r['property_code']) ?></div>
                            </td>
                            <td>
                                <a href="<?= APP_URL ?>/index.php?page=customers&amp;action=show&amp;id=<?= (int) $r['customer_id'] ?>" class="cell-strong">
                                    <?= sanitize($r['customer_name']) ?>
                                </a>
                                <div class="person__meta">Held from <?= formatDate($r['reservation_date']) ?></div>
                            </td>
                            <td class="cell-date">
                                <?= formatDate($r['expiry_date']) ?>
                                <?php if ($isActive): ?>
                                    <div class="person__meta<?= $left <= 2 ? ' text-warning' : '' ?>">
                                        <?php if ($left <= 0): ?>
                                            Expires today
                                        <?php else: ?>
                                            <?= $left ?> day<?= $left === 1 ? '' : 's' ?> left
                                        <?php endif ?>
                                    </div>
                                <?php endif ?>
                            </td>
                            <td class="col-mid cell-num">
                                <?php if ((float) $r['deposit_amount'] > 0): ?>
                                    <?= formatCurrency((float) $r['deposit_amount']) ?>
                                <?php else: ?>
                                    <span class="text-subtle">—</span>
                                <?php endif ?>
                            </td>
                            <td><?= uiStatus($r['status']) ?></td>
                            <td class="cell-actions">
                                <?= uiRowActions(array_merge(
                                    [
                                        ['label' => 'View property', 'icon' => 'bi-buildings', 'can' => 'properties.show',
                                         'url' => APP_URL . '/index.php?page=properties&action=show&id=' . (int) $r['property_id']],
                                        ['label' => 'View customer', 'icon' => 'bi-person', 'can' => 'customers.show',
                                         'url' => APP_URL . '/index.php?page=customers&action=show&id=' . (int) $r['customer_id']],
                                    ],
                                    // Only a live hold can be confirmed or cancelled. The
                                    // controller re-checks the permission either way; this
                                    // just avoids offering an action that would do nothing.
                                    $isActive ? [[
                                        'label' => 'Confirm reservation', 'icon' => 'bi-check2-circle',
                                        'can' => 'reservations.confirm', 'method' => 'post',
                                        'url' => $listUrl . '&action=confirm' . $actionId,
                                        'confirm' => [
                                            'title'  => 'Confirm this reservation?',
                                            'action' => 'Confirm',
                                            'record' => $r['reservation_code'] . ' · ' . $r['property_title'],
                                            'tone'   => 'primary',
                                            'body'   => 'The hold stops counting down and the property stays off the market until a lease or sale is recorded.',
                                        ],
                                    ]] : [],
                                    $isActive ? [[
                                        'label' => 'Cancel reservation', 'icon' => 'bi-x-circle',
                                        'can' => 'reservations.cancel', 'method' => 'post', 'danger' => true,
                                        'url' => $listUrl . '&action=cancel' . $actionId,
                                        'confirm' => [
                                            'title'  => 'Cancel this reservation?',
                                            'action' => 'Cancel reservation',
                                            'record' => $r['reservation_code'] . ' · ' . $r['customer_name'],
                                            'tone'   => 'danger',
                                            'body'   => 'The property returns to available unless another hold is on it. The reservation is kept in the record as cancelled.',
                                        ],
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
