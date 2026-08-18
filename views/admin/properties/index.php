<?php
/**
 * Properties — the register.
 *
 * Two views of one result set: a table for working through the portfolio a
 * column at a time, and a grid for recognising a property by its photograph.
 * Both are rendered from the same rows and toggled in the browser, because
 * choosing a view is a preference rather than a new question for the server.
 *
 * Vars from PropertyController::index() — properties, covers, filters,
 * listingTypes, categories, statuses, page, totalPages, totalCount, plus the
 * form lookups the quick-add popup needs.
 */
$fd      = $formData ?? [];
$covers  = $covers ?? [];
$canEdit = can('properties.edit');

$listUrl = APP_URL . '/index.php?page=properties';
$showUrl = static fn(int $id): string => $listUrl . '&action=show&id=' . $id;
$editUrl = static fn(int $id): string => $listUrl . '&action=edit&id=' . $id;

// Which filters are actually narrowing the list. Drives both the chips below
// and the wording of the empty state — "no matches" and "nothing yet" are
// different problems and must not offer the same way out.
$applied = array_filter([
    'search'        => $filters['search']        ?? '',
    'property_type' => $filters['property_type'] ?? '',
    'category'      => $filters['category']      ?? '',
    'status'        => $filters['status']        ?? '',
    'owner_id'      => $filters['owner_id']      ?? '',
], static fn($v): bool => $v !== '' && $v !== null);

$ownerNames = array_column($owners ?? [], 'full_name', 'id');

$chipLabels = [
    'search'        => ['Search',  static fn($v) => '“' . $v . '”'],
    'property_type' => ['Listing', static fn($v) => $listingTypes[$v] ?? $v],
    'category'      => ['Type',    static fn($v) => $categories[$v] ?? $v],
    'status'        => ['Status',  static fn($v) => $statuses[$v] ?? $v],
    'owner_id'      => ['Owner',   static fn($v) => $ownerNames[$v] ?? ('#' . $v)],
];

/** The list URL with one filter removed — what a chip's × leads to. */
$without = static function (string $key) use ($listUrl): string {
    $params = $_GET;
    unset($params[$key], $params['p'], $params['page']);
    return $listUrl . ($params ? '&' . http_build_query($params) : '');
};

$toolbar = [
    'page'   => 'properties',
    'search' => [
        'name'        => 'search',
        'value'       => $filters['search'] ?? '',
        'label'       => 'Search properties',
        'placeholder' => 'Search by title, code or location…',
    ],
    'filters' => [
        ['name' => 'property_type', 'label' => 'Listing', 'value' => $filters['property_type'] ?? '',
         'options' => $listingTypes, 'all' => 'Any listing'],
        ['name' => 'category', 'label' => 'Type', 'value' => $filters['category'] ?? '',
         'options' => $categories, 'all' => 'Any type'],
        ['name' => 'status', 'label' => 'Status', 'value' => $filters['status'] ?? '',
         'options' => $statuses, 'all' => 'Any status'],
        ['name' => 'owner_id', 'label' => 'Owner', 'value' => $filters['owner_id'] ?? '',
         'options' => $ownerNames, 'all' => 'Any owner'],
    ],
    'views' => ['key' => 'properties', 'options' => [
        ['view' => 'table', 'icon' => 'bi-list-ul',  'label' => 'Table'],
        ['view' => 'grid',  'icon' => 'bi-grid-3x3-gap', 'label' => 'Grid'],
    ]],
];

/** The row actions a property offers, filtered by permission inside uiRowActions(). */
$rowActions = static function (array $p) use ($showUrl, $editUrl, $listUrl): string {
    $pending = ($p['approval_status'] ?? '') === 'pending';

    return uiRowActions([
        ['label' => 'View details', 'icon' => 'bi-eye',    'url' => $showUrl((int) $p['id']), 'can' => 'properties.show'],
        ['label' => 'Edit',         'icon' => 'bi-pencil', 'url' => $editUrl((int) $p['id']), 'can' => 'properties.edit'],

        // Offered only while there is something to approve, so the menu says
        // what can be done now rather than listing every verb the module has.
        ...($pending ? [[
            'label' => 'Approve listing', 'icon' => 'bi-check2-circle', 'can' => 'properties.approve',
            'url'     => $listUrl . '&action=approve&id=' . (int) $p['id'],
            'confirm' => [
                'title'  => 'Approve this listing?',
                'body'   => 'It becomes visible on the public site and can be reserved, let or sold.',
                'action' => 'Approve',
                'tone'   => 'info',
                'record' => $p['property_code'] . ' · ' . $p['title'],
            ],
        ]] : []),

        ['label' => 'Archive', 'icon' => 'bi-archive', 'can' => 'properties.archive', 'danger' => true,
         'url'     => $listUrl . '&action=archive&id=' . (int) $p['id'],
         'confirm' => [
             'title'  => 'Archive this property?',
             'body'   => 'It is removed from the register and from public listings. Leases, payments and documents already recorded against it are kept.',
             'action' => 'Archive property',
             'record' => $p['property_code'] . ' · ' . $p['title'],
         ]],
    ]);
};
?>

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

<?php if (empty($properties)): ?>
    <div class="table-card">
        <?= uiEmptyState([
            'icon'     => 'bi-buildings',
            'filtered' => (bool) $applied,
            'title'    => $applied ? 'No properties match these filters' : 'No properties yet',
            'desc'     => $applied
                ? 'Nothing in the register matches what you have selected. Try widening the search or clearing a filter.'
                : 'Add your first property to start building the portfolio.',
            'clearUrl' => $listUrl,
            'actions'  => [[
                'label' => 'Add Property', 'icon' => 'bi-plus-lg', 'can' => 'properties.create',
                'url'   => $listUrl . '&action=create',
                'attrs' => ['data-modal-open' => 'propertyCreateModal'],
            ]],
        ]) ?>
    </div>
<?php else: ?>

<!-- ── Table view ───────────────────────────────────────────────── -->
<div data-view-panel="table" class="table-card">
    <div class="table-head">
        <div class="table-head__title">
            <?= number_format($totalCount) ?> <?= $totalCount === 1 ? 'property' : 'properties' ?>
            <?php if ($applied): ?><span class="table-head__count">matching</span><?php endif ?>
        </div>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <?= uiSortHeader('Code', ['asc' => 'code_asc', 'desc' => 'code_desc'], 'sort', 'col-lo') ?>
                    <?= uiSortHeader('Property', ['asc' => 'title_asc', 'desc' => 'title_desc']) ?>
                    <th class="col-mid">Type</th>
                    <th class="col-mid">Owner</th>
                    <?= uiSortHeader('Price', ['asc' => 'price_asc', 'desc' => 'price_desc'], 'sort', 'cell-num') ?>
                    <?= uiSortHeader('Status', ['asc' => 'status_asc', 'desc' => 'status_desc']) ?>
                    <?= uiSortHeader('Updated', ['desc' => 'updated_desc', 'asc' => 'updated_asc'], 'sort', 'cell-date col-mid') ?>
                    <th class="cell-actions"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($properties as $p): ?>
                    <?php $price = propertyPrice($p); ?>
                    <tr>
                        <td class="col-lo"><span class="table__id"><?= sanitize($p['property_code']) ?></span></td>
                        <td>
                            <a href="<?= $showUrl((int) $p['id']) ?>" class="cell-strong">
                                <?= sanitize($p['title']) ?>
                            </a>
                            <?php if (!empty($p['location'])): ?>
                                <div class="person__meta">
                                    <i class="bi bi-geo-alt" aria-hidden="true"></i>
                                    <?= sanitize($p['location']) ?>
                                </div>
                            <?php endif ?>
                        </td>
                        <td class="col-mid">
                            <span class="text-muted">
                                <i class="bi <?= categoryIcon($p['category']) ?>" aria-hidden="true"></i>
                                <?= sanitize(uiLabel($p['category'])) ?>
                            </span>
                        </td>
                        <td class="col-mid"><?= sanitize($p['owner_name'] ?: '—') ?></td>
                        <td class="cell-num">
                            <?php if ($price['amount'] > 0): ?>
                                <?= formatCurrency($price['amount']) ?>
                                <?php if ($price['isRental']): ?><span class="text-subtle">/mo</span><?php endif ?>
                            <?php else: ?>
                                <span class="text-subtle">—</span>
                            <?php endif ?>
                        </td>
                        <td><?= uiStatus($p['status']) ?></td>
                        <td class="cell-date col-mid"><?= formatDate($p['updated_at'] ?? $p['created_at']) ?></td>
                        <td class="cell-actions"><?= $rowActions($p) ?></td>
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

<!-- ── Grid view ────────────────────────────────────────────────── -->
<div data-view-panel="grid" hidden>
    <div class="grid-auto">
        <?php foreach ($properties as $p): ?>
            <?php
            $price = propertyPrice($p);
            $cover = $covers[(int) $p['id']] ?? null;
            ?>
            <article class="prop-card">
                <a class="prop-card__cover" href="<?= $showUrl((int) $p['id']) ?>"
                   aria-label="Open <?= sanitize($p['title']) ?>">
                    <img src="<?= sanitize(propertyImage($p, $cover)) ?>"
                         alt="" loading="lazy" width="400" height="250">
                    <?= uiStatus($p['status']) ?>
                </a>
                <div class="prop-card__body">
                    <h3 class="prop-card__title">
                        <a href="<?= $showUrl((int) $p['id']) ?>"><?= sanitize(truncate($p['title'], 52)) ?></a>
                    </h3>
                    <div class="prop-card__meta">
                        <i class="bi bi-geo-alt" aria-hidden="true"></i>
                        <?= sanitize(truncate($p['location'] ?: 'Location not set', 34)) ?>
                    </div>
                    <div class="prop-card__price">
                        <?php if ($price['amount'] > 0): ?>
                            <?= formatCurrency($price['amount']) ?><?php if ($price['isRental']): ?><span class="text-subtle" style="font-size:.78rem">/mo</span><?php endif ?>
                        <?php else: ?>
                            <span class="text-subtle">Price on request</span>
                        <?php endif ?>
                    </div>
                    <div class="prop-card__specs">
                        <span><i class="bi <?= categoryIcon($p['category']) ?>" aria-hidden="true"></i> <?= sanitize(uiLabel($p['category'])) ?></span>
                        <?php if (!empty($p['num_rooms'])): ?>
                            <span><i class="bi bi-door-open" aria-hidden="true"></i> <?= (int) $p['num_rooms'] ?></span>
                        <?php endif ?>
                        <?php if (!empty($p['size_sqm'])): ?>
                            <span><i class="bi bi-arrows-angle-expand" aria-hidden="true"></i> <?= (int) $p['size_sqm'] ?> m²</span>
                        <?php endif ?>
                    </div>
                </div>
                <div class="prop-card__foot">
                    <span class="table__id"><?= sanitize($p['property_code']) ?></span>
                    <?= $rowActions($p) ?>
                </div>
            </article>
        <?php endforeach ?>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="table-foot table-foot--standalone">
            <span class="table-foot__note">Page <?= $page ?> of <?= $totalPages ?></span>
            <?php require VIEWS_PATH . '/components/pagination.php'; ?>
        </div>
    <?php endif ?>
</div>

<?php endif ?>

<?php require __DIR__ . '/_create_modal.php'; ?>
