<?php
/**
 * Sales — the pipeline.
 *
 * A sale is worth two figures at once: what the buyer pays and what the
 * agency keeps. Both belong in the row, and the cards at the top say how much
 * is sitting at each stage — which is the number anyone asks for first.
 *
 * Vars from SaleController::index().
 */
$fd   = $formData ?? [];
$errs = $formErrors ?? [];

$listUrl = APP_URL . '/index.php?page=sales';
$showUrl = static fn(int $id): string => $listUrl . '&action=show&id=' . $id;

$allCount = 0; $allTotal = 0.0;
foreach ($totals as $row) {
    $allCount += (int) ($row['cnt'] ?? 0);
    $allTotal += (float) ($row['total'] ?? 0);
}

$ledger = [
    'param'   => 'status',
    'label'   => 'Filter by sale stage',
    'noun'    => ['sale', 'sales'],
    'value'   => $filters['status'] ?? '',
    'options' => $statuses,
    'totals'  => $totals,
    'all'     => ['label' => 'All sales', 'cnt' => $allCount, 'total' => $allTotal],
];

$agentOptions = array_column($agents, 'full_name', 'id');

$toolbar = [
    'page'   => 'sales',
    'keep'   => array_filter(['status' => $filters['status'] ?? '']),
    'search' => [
        'name'        => 'search',
        'value'       => $filters['search'] ?? '',
        'label'       => 'Search sales',
        'placeholder' => 'Search by code, buyer or property…',
    ],
    'filters' => [
        ['name' => 'payment_type', 'label' => 'Payment', 'value' => $filters['payment_type'] ?? '',
         'options' => $paymentTypes, 'all' => 'Any payment'],
        ['name' => 'agent_id', 'label' => 'Agent', 'value' => (string) ($filters['agent_id'] ?? ''),
         'options' => $agentOptions, 'all' => 'Any agent'],
    ],
    /* The create action lives in the page header, which every module has and
       which sits directly above this strip. Offering it here as well put the
       same button on screen twice. */
];

$applied = array_filter([
    'search'       => $filters['search'] ?? '',
    'payment_type' => $filters['payment_type'] ?? '',
    'agent_id'     => (string) ($filters['agent_id'] ?? ''),
], static fn($v): bool => $v !== '' && $v !== null);

$chipLabels = [
    'search'       => ['Search',  static fn($v) => '“' . $v . '”'],
    'payment_type' => ['Payment', static fn($v) => $paymentTypes[$v] ?? $v],
    'agent_id'     => ['Agent',   static fn($v) => $agentOptions[$v] ?? ('#' . $v)],
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
    <?php if (empty($sales)): ?>
        <?= uiEmptyState([
            'icon'     => 'bi-cart-check',
            'filtered' => $isFiltered,
            'title'    => $isFiltered ? 'No sales match these filters' : 'No sales yet',
            'desc'     => $isFiltered
                ? 'Nothing in the pipeline matches what you have selected.'
                : 'Recording a sale reserves the property, and raises a commission record when an agent is named.',
            'clearUrl' => $listUrl,
            'actions'  => [[
                'label' => 'New Sale', 'icon' => 'bi-plus-lg', 'can' => 'sales.create',
                'url'   => $listUrl . '&action=create',
                'attrs' => ['data-modal-open' => 'saleCreateModal'],
            ]],
        ]) ?>
    <?php else: ?>
        <div class="table-head">
            <div class="table-head__title">
                <?= number_format($totalCount) ?> <?= $totalCount === 1 ? 'sale' : 'sales' ?>
                <?php if ($isFiltered): ?><span class="table-head__count">matching</span><?php endif ?>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <?= uiSortHeader('Code', ['asc' => 'code_asc', 'desc' => 'code_desc'], 'sort', 'col-lo') ?>
                        <th>Property</th>
                        <th>Buyer</th>
                        <?= uiSortHeader('Sale price', ['desc' => 'amount_desc', 'asc' => 'amount_asc'], 'sort', 'cell-num') ?>
                        <?= uiSortHeader('Commission', ['desc' => 'comm_desc', 'asc' => 'comm_asc'], 'sort', 'cell-num col-lo') ?>
                        <th class="col-mid">Agent</th>
                        <?= uiSortHeader('Date', ['desc' => 'date_desc', 'asc' => 'date_asc'], 'sort', 'cell-date col-mid') ?>
                        <?= uiSortHeader('Stage', ['asc' => 'status_asc', 'desc' => 'status_desc']) ?>
                        <th class="cell-actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sales as $s): ?>
                        <?php
                        $id    = (int) $s['id'];
                        $price = (float) $s['sale_amount'];
                        $comm  = (float) $s['commission_amount'];
                        ?>
                        <tr>
                            <td class="cell-tight col-lo">
                                <a href="<?= sanitize($showUrl($id)) ?>" class="table__id"><?= sanitize($s['sale_code']) ?></a>
                            </td>
                            <td>
                                <a href="<?= APP_URL ?>/index.php?page=properties&amp;action=show&amp;id=<?= (int) $s['property_id'] ?>" class="cell-strong">
                                    <?= sanitize($s['property_title']) ?>
                                </a>
                                <div class="person__meta"><?= sanitize($s['property_code']) ?></div>
                            </td>
                            <td>
                                <?= uiPersonCell(
                                    $s['customer_name'],
                                    $s['customer_photo'] ?? null,
                                    '',
                                    APP_URL . '/index.php?page=customers&action=show&id=' . (int) $s['customer_id']
                                ) ?>
                            </td>
                            <td class="cell-num">
                                <strong><?= formatCurrency($price) ?></strong>
                                <?php if ((float) ($s['tax_amount'] ?? 0) > 0): ?>
                                    <div class="person__meta">
                                        +<?= formatCurrency((float) $s['tax_amount']) ?> tax
                                        <?php if ((float) ($s['tax_rate'] ?? 0) > 0): ?>
                                            at <?= rtrim(rtrim(number_format((float) $s['tax_rate'], 2), '0'), '.') ?>%
                                        <?php endif ?>
                                    </div>
                                <?php endif ?>
                            </td>
                            <td class="col-lo cell-num">
                                <?php if ($comm > 0): ?>
                                    <?= formatCurrency($comm) ?>
                                    <?php if ($price > 0): ?>
                                        <div class="person__meta"><?= number_format($comm / $price * 100, 1) ?>%</div>
                                    <?php endif ?>
                                <?php else: ?>
                                    <span class="text-subtle">—</span>
                                <?php endif ?>
                            </td>
                            <td class="col-mid">
                                <?php if (!empty($s['agent_name'])): ?>
                                    <?= uiPersonCell($s['agent_name'], $s['agent_avatar'] ?? null) ?>
                                <?php else: ?>
                                    <span class="text-subtle">Unassigned</span>
                                <?php endif ?>
                            </td>
                            <td class="cell-date col-mid"><?= formatDate($s['sale_date']) ?></td>
                            <td>
                                <?= uiStatus($s['status']) ?>
                                <div class="person__meta"><?= sanitize($paymentTypes[$s['payment_type']] ?? uiLabel((string) $s['payment_type'])) ?></div>
                            </td>
                            <td class="cell-actions">
                                <?= uiRowActions([
                                    ['label' => 'Open sale', 'icon' => 'bi-eye', 'can' => 'sales.show',
                                     'url' => $showUrl($id)],
                                    ['label' => 'View property', 'icon' => 'bi-buildings', 'can' => 'properties.show',
                                     'url' => APP_URL . '/index.php?page=properties&action=show&id=' . (int) $s['property_id']],
                                    ['label' => 'View buyer', 'icon' => 'bi-person', 'can' => 'customers.show',
                                     'url' => APP_URL . '/index.php?page=customers&action=show&id=' . (int) $s['customer_id']],
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

<?php require __DIR__ . '/_create_modal.php'; ?>
