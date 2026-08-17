<?php
/**
 * Documents — the library.
 *
 * The expiry banner and the state pills are two readings of the same figures,
 * which now arrive from the controller rather than from a query this view used
 * to issue itself. The banner says what needs attention; the pills let you go
 * straight to it and say how much of it there is.
 *
 * Vars from DocumentController::index().
 */
$listUrl  = APP_URL . '/index.php?page=documents';
$counts   = $expiryCounts;
$sortable = true;   // the standalone list owns the URL, so its headers may sort

$statusFilter = [
    'param'   => 'state',
    'value'   => $filters['state'] ?? '',
    'options' => $states,
    'counts'  => [
        'active'   => $counts['active'],
        'expiring' => $counts['expiring'],
        'expired'  => $counts['expired'],
        'archived' => $counts['archived'],
    ],
    'total'   => $counts['total'],
    'all'     => 'Everything',
];

$toolbar = [
    'page'   => 'documents',
    'keep'   => array_filter(['state' => $filters['state'] ?? '']),
    'search' => [
        'name'        => 'search',
        'value'       => $filters['search'] ?? '',
        'label'       => 'Search documents',
        'placeholder' => 'Search by title, reference or filename…',
    ],
    'filters' => [
        ['name' => 'category_id', 'label' => 'Category', 'value' => (string) ($filters['category_id'] ?: ''),
         'options' => $categories, 'all' => 'Any category'],
        ['name' => 'visibility', 'label' => 'Visibility', 'value' => $filters['visibility'] ?? '',
         'options' => $visibilities, 'all' => 'Any visibility'],
    ],
    'actions' => [
        ['label' => 'Categories', 'icon' => 'bi-tags', 'class' => 'btn--outline',
         'url' => APP_URL . '/index.php?page=document-categories'],
        ['label' => 'Upload', 'icon' => 'bi-upload', 'class' => 'btn--primary',
         'can' => 'documents.create',
         'url' => $listUrl . '&modal=upload',
         'attrs' => ['data-modal-open' => 'documentUploadModal']],
    ],
];

$applied = array_filter([
    'search'      => $filters['search'] ?? '',
    'category_id' => (string) ($filters['category_id'] ?: ''),
    'visibility'  => $filters['visibility'] ?? '',
], static fn($v): bool => $v !== '' && $v !== null);

$chipLabels = [
    'search'      => ['Search',     static fn($v) => '“' . $v . '”'],
    'category_id' => ['Category',   static fn($v) => $categories[(int) $v] ?? ('#' . $v)],
    'visibility'  => ['Visibility', static fn($v) => $visibilities[$v] ?? $v],
];

$isFiltered = (bool) $applied || ($filters['state'] ?? '') !== '';

$without = static function (string $key) use ($listUrl): string {
    $params = $_GET;
    unset($params[$key], $params['p'], $params['page']);
    return $listUrl . ($params ? '&' . http_build_query($params) : '');
};
?>

<?php if ($counts['expired'] > 0 || $counts['expiring'] > 0): ?>
    <div class="alert alert--warning">
        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
        <div>
            <?php if ($counts['expired'] > 0): ?>
                <strong><?= number_format($counts['expired']) ?></strong>
                document<?= $counts['expired'] === 1 ? ' has' : 's have' ?> expired.
                <a href="<?= $listUrl ?>&amp;state=expired">Review</a>.
            <?php endif ?>
            <?php if ($counts['expiring'] > 0): ?>
                <strong><?= number_format($counts['expiring']) ?></strong>
                expiring within <?= documentExpiryWarningDays() ?> days.
                <a href="<?= $listUrl ?>&amp;state=expiring">Review</a>.
            <?php endif ?>
            Nothing is deleted automatically.
        </div>
    </div>
<?php endif ?>

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
    <?php if (!empty($documents)): ?>
        <div class="table-head">
            <div class="table-head__title">
                <?= number_format($totalCount) ?> <?= $totalCount === 1 ? 'document' : 'documents' ?>
                <?php if ($isFiltered): ?><span class="table-head__count">matching</span><?php endif ?>
            </div>
            <span class="table-head__note">
                <i class="bi bi-shield-lock" aria-hidden="true"></i>
                Served through an authorised endpoint, never by direct URL
            </span>
        </div>
    <?php endif ?>

    <?php
    $emptyFiltered = $isFiltered;
    $emptyClearUrl = $listUrl;
    require __DIR__ . '/_list.php';
    ?>

    <?php if (!empty($documents) && $totalPages > 1): ?>
        <div class="table-foot">
            <span class="table-foot__note">Page <?= $page ?> of <?= $totalPages ?></span>
            <?php require VIEWS_PATH . '/components/pagination.php'; ?>
        </div>
    <?php endif ?>
</div>

<?php require __DIR__ . '/_upload_modal.php'; ?>
