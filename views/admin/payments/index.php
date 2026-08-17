<?php
/**
 * Payments — the ledger.
 *
 * The page used to carry the money twice: a read-only totals strip at the top
 * and a status dropdown in the filter bar, so seeing which three payments made
 * up "Overdue 4,800" meant scrolling down and re-asking the question. Here the
 * totals are the filter — each card states a status, what it is worth and how
 * many records it covers, and clicking it narrows the table underneath.
 *
 * Vars from PaymentController::index().
 */
$fd   = $formData ?? [];
$errs = $formErrors ?? [];

$listUrl = APP_URL . '/index.php?page=payments';

/* One pass over the totals the controller already fetched: the "All" card
   needs the sums, and building them here costs nothing. */
$allCount = 0; $allTotal = 0.0;
foreach ($totals as $row) {
    $allCount += (int) ($row['cnt'] ?? 0);
    $allTotal += (float) ($row['total'] ?? 0);
}

$ledger = [
    'param'   => 'status',
    'label'   => 'Filter by payment status',
    'noun'    => ['payment', 'payments'],
    'value'   => $filters['status'] ?? '',
    'options' => $statuses,
    'totals'  => $totals,
    'all'     => ['label' => 'All payments', 'cnt' => $allCount, 'total' => $allTotal],
];

/* Status is handled by the cards above, so the toolbar carries it hidden and
   offers the two axes the cards cannot: what the money was for, and how it
   arrived. */
$toolbar = [
    'page'   => 'payments',
    'keep'   => array_filter(['status' => $filters['status'] ?? '']),
    'search' => [
        'name'        => 'search',
        'value'       => $filters['search'] ?? '',
        'label'       => 'Search payments',
        'placeholder' => 'Search by code, receipt, customer or property…',
    ],
    'filters' => [
        ['name' => 'payment_type', 'label' => 'Type', 'value' => $filters['payment_type'] ?? '',
         'options' => $types, 'all' => 'Any type'],
        ['name' => 'payment_method', 'label' => 'Method', 'value' => $filters['payment_method'] ?? '',
         'options' => $methods, 'all' => 'Any method'],
    ],
    'actions' => [
        ['label' => 'Record Payment', 'icon' => 'bi-plus-lg', 'class' => 'btn--primary',
         'can' => 'payments.create',
         'url' => $listUrl . '&action=create',
         'attrs' => ['data-modal-open' => 'paymentCreateModal']],
    ],
];

$applied = array_filter([
    'search'         => $filters['search'] ?? '',
    'payment_type'   => $filters['payment_type'] ?? '',
    'payment_method' => $filters['payment_method'] ?? '',
], static fn($v): bool => $v !== '' && $v !== null);

$chipLabels = [
    'search'         => ['Search', static fn($v) => '“' . $v . '”'],
    'payment_type'   => ['Type',   static fn($v) => $types[$v] ?? $v],
    'payment_method' => ['Method', static fn($v) => $methods[$v] ?? $v],
];

$isFiltered = (bool) $applied || ($filters['status'] ?? '') !== '';

$without = static function (string $key) use ($listUrl): string {
    $params = $_GET;
    unset($params[$key], $params['p'], $params['page']);
    return $listUrl . ($params ? '&' . http_build_query($params) : '');
};
?>

<?php require VIEWS_PATH . '/components/ui/ledger_summary.php'; ?>
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
    <?php if (empty($payments)): ?>
        <?= uiEmptyState([
            'icon'     => 'bi-credit-card',
            'filtered' => $isFiltered,
            'title'    => $isFiltered ? 'No payments match these filters' : 'No payments recorded',
            'desc'     => $isFiltered
                ? 'Nothing in the ledger matches what you have selected.'
                : 'Recording a payment against a lease instalment also marks that instalment settled.',
            'clearUrl' => $listUrl,
            'actions'  => [[
                'label' => 'Record Payment', 'icon' => 'bi-plus-lg', 'can' => 'payments.create',
                'url'   => $listUrl . '&action=create',
                'attrs' => ['data-modal-open' => 'paymentCreateModal'],
            ]],
        ]) ?>
    <?php else: ?>
        <div class="table-head">
            <div class="table-head__title">
                <?= number_format($totalCount) ?> <?= $totalCount === 1 ? 'payment' : 'payments' ?>
                <?php if ($isFiltered): ?><span class="table-head__count">matching</span><?php endif ?>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <?= uiSortHeader('Code', ['asc' => 'code_asc', 'desc' => 'code_desc']) ?>
                        <th>Customer</th>
                        <th>Property</th>
                        <th>For</th>
                        <?= uiSortHeader('Amount', ['desc' => 'amount_desc', 'asc' => 'amount_asc'], 'sort', 'cell-num') ?>
                        <?= uiSortHeader('Received', ['desc' => 'date_desc', 'asc' => 'date_asc'], 'sort', 'cell-date') ?>
                        <?= uiSortHeader('Status', ['asc' => 'status_asc', 'desc' => 'status_desc']) ?>
                        <th class="cell-actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $p): ?>
                        <?php $id = (int) $p['id']; ?>
                        <tr>
                            <td class="cell-tight">
                                <a href="<?= $listUrl ?>&amp;action=receipt&amp;id=<?= $id ?>" class="table__id">
                                    <?= sanitize($p['payment_code']) ?>
                                </a>
                            </td>
                            <td>
                                <?= uiPersonCell(
                                    $p['customer_name'],
                                    $p['customer_photo'] ?? null,
                                    '',
                                    APP_URL . '/index.php?page=customers&action=show&id=' . (int) $p['customer_id']
                                ) ?>
                            </td>
                            <td class="cell-clip">
                                <?php if (!empty($p['property_title'])): ?>
                                    <a href="<?= APP_URL ?>/index.php?page=properties&amp;action=show&amp;id=<?= (int) $p['property_id'] ?>">
                                        <?= sanitize($p['property_title']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-subtle">—</span>
                                <?php endif ?>
                            </td>
                            <td>
                                <div><?= sanitize(uiLabel((string) $p['payment_type'])) ?></div>
                                <div class="person__meta"><?= sanitize(uiLabel((string) $p['payment_method'])) ?></div>
                            </td>
                            <td class="cell-num">
                                <strong><?= formatCurrency((float) $p['amount']) ?></strong>
                                <?php if ((float) ($p['penalty_amount'] ?? 0) > 0): ?>
                                    <div class="person__meta text-warning">
                                        incl. <?= formatCurrency((float) $p['penalty_amount']) ?> penalty
                                    </div>
                                <?php endif ?>
                            </td>
                            <td class="cell-date">
                                <?= formatDate($p['payment_date']) ?>
                                <?php if (!empty($p['due_date']) && $p['due_date'] !== $p['payment_date']): ?>
                                    <div class="person__meta">due <?= formatDate($p['due_date']) ?></div>
                                <?php endif ?>
                            </td>
                            <td><?= uiStatus($p['status']) ?></td>
                            <td class="cell-actions">
                                <?= uiRowActions(array_merge(
                                    [
                                        ['label' => 'View receipt', 'icon' => 'bi-receipt', 'can' => 'payments.receipt',
                                         'url' => $listUrl . '&action=receipt&id=' . $id],
                                        ['label' => 'View customer', 'icon' => 'bi-person', 'can' => 'customers.show',
                                         'url' => APP_URL . '/index.php?page=customers&action=show&id=' . (int) $p['customer_id']],
                                    ],
                                    !empty($p['property_id']) ? [[
                                        'label' => 'View property', 'icon' => 'bi-buildings', 'can' => 'properties.show',
                                        'url' => APP_URL . '/index.php?page=properties&action=show&id=' . (int) $p['property_id'],
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
