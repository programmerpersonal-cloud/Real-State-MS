<?php
/**
 * Inquiries — the inbox.
 *
 * One list for every role. What changes between them is the rows (cut by
 * inquiryViewScope) and the columns worth showing: the sender's own enquiry
 * does not need a "From" column naming themselves, and an owner has no use for
 * the internal status the desk works to.
 *
 * Vars from InquiryController::index().
 */
$isStaff = canAny('inquiries.reply');   // admin/agent: the people working the queue

$listUrl = APP_URL . '/index.php?page=inquiries';
$showUrl = static fn(int $id): string => $listUrl . '&action=show&id=' . $id;

/* Status is the desk's own working state. Someone who cannot reply has no use
   for it, so they get neither the column nor the filter above it. */
$statusFilter = [
    'param'   => 'status',
    'value'   => $filters['status'] ?? '',
    'options' => $statuses,
    'counts'  => $statusCounts,
    'total'   => array_sum($statusCounts),
    'all'     => 'All enquiries',
];

$toolbar = [
    'page'   => 'inquiries',
    'keep'   => array_filter(['status' => $filters['status'] ?? '']),
    'search' => [
        'name'        => 'search',
        'value'       => $filters['search'] ?? '',
        'label'       => 'Search enquiries',
        'placeholder' => 'Search by name, subject or message…',
    ],
    'actions' => [
        ['label' => 'New Inquiry', 'icon' => 'bi-plus-lg', 'class' => 'btn--primary',
         'can' => 'inquiries.create', 'url' => $listUrl . '&action=create'],
    ],
];

$searchTerm = trim((string) ($filters['search'] ?? ''));
$isFiltered = $searchTerm !== '' || ($filters['status'] ?? '') !== '';

$without = static function (string $key) use ($listUrl): string {
    $params = $_GET;
    unset($params[$key], $params['p'], $params['page']);
    return $listUrl . ($params ? '&' . http_build_query($params) : '');
};
?>

<?php if ($isStaff): ?>
    <?php require VIEWS_PATH . '/components/ui/status_filter.php'; ?>
<?php endif ?>
<?php require VIEWS_PATH . '/components/ui/list_toolbar.php'; ?>

<?php if ($searchTerm !== ''): ?>
    <div class="filter-chips">
        <span class="filter-chips__label">Filtered by</span>
        <span class="filter-chip">
            <span class="filter-chip__key">Search:</span>
            &ldquo;<?= sanitize($searchTerm) ?>&rdquo;
            <a class="filter-chip__x" href="<?= sanitize($without('search')) ?>" aria-label="Remove the search filter">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </a>
        </span>
    </div>
<?php endif ?>

<div class="table-card">
    <?php if (empty($inquiries)): ?>
        <?= uiEmptyState([
            'icon'     => 'bi-chat-left-text',
            'filtered' => $isFiltered,
            'title'    => $isFiltered ? 'No enquiries match these filters' : 'No enquiries',
            // An empty scope is not an empty system, and the two need different
            // wording or the page reads as broken.
            'desc'     => $isFiltered
                ? 'Nothing in this inbox matches what you have selected.'
                : ($emptyMessage ?: 'Enquiries about your properties will appear here.'),
            'clearUrl' => $listUrl,
            'actions'  => [[
                'label' => 'New Inquiry', 'icon' => 'bi-plus-lg', 'can' => 'inquiries.create',
                'url'   => $listUrl . '&action=create',
            ]],
        ]) ?>
    <?php else: ?>
        <div class="table-head">
            <div class="table-head__title">
                <?= number_format($totalCount) ?> <?= $totalCount === 1 ? 'enquiry' : 'enquiries' ?>
                <?php if ($isFiltered): ?><span class="table-head__count">matching</span><?php endif ?>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <?php if ($isStaff): ?>
                            <?= uiSortHeader('From', ['asc' => 'name_asc', 'desc' => 'name_desc']) ?>
                        <?php endif ?>
                        <th>Property</th>
                        <?= uiSortHeader('Enquiry', ['asc' => 'subject_asc', 'desc' => 'subject_desc']) ?>
                        <?= uiSortHeader('Received', ['desc' => 'newest', 'asc' => 'oldest'], 'sort', 'cell-date') ?>
                        <?php if ($isStaff): ?>
                            <?= uiSortHeader('Status', ['asc' => 'status_asc', 'desc' => 'status_desc']) ?>
                        <?php endif ?>
                        <th class="cell-actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inquiries as $i): ?>
                        <?php
                        $id      = (int) $i['id'];
                        $sender  = $i['name'] ?: ($i['customer_name'] ?? '');
                        $contact = $i['email'] ?: $i['phone'];
                        // An enquiry nobody has answered is the one that needs
                        // finding, so it is marked in the row rather than only
                        // in the status column.
                        $waiting = in_array($i['status'], ['open', 'pending'], true);
                        ?>
                        <tr>
                            <?php if ($isStaff): ?>
                                <td>
                                    <?= uiPersonCell(
                                        $sender !== '' ? $sender : 'Anonymous',
                                        $i['customer_photo'] ?? null,
                                        (string) $contact,
                                        !empty($i['customer_id'])
                                            ? APP_URL . '/index.php?page=customers&action=show&id=' . (int) $i['customer_id']
                                            : null
                                    ) ?>
                                </td>
                            <?php endif ?>
                            <td>
                                <?php if (!empty($i['property_title'])): ?>
                                    <a href="<?= APP_URL ?>/index.php?page=properties&amp;action=show&amp;id=<?= (int) $i['property_id'] ?>" class="cell-strong">
                                        <?= sanitize($i['property_title']) ?>
                                    </a>
                                    <div class="person__meta"><?= sanitize($i['property_code'] ?? '') ?></div>
                                <?php else: ?>
                                    <span class="text-subtle">General enquiry</span>
                                <?php endif ?>
                            </td>
                            <td class="cell-clip">
                                <a href="<?= sanitize($showUrl($id)) ?>" class="cell-strong">
                                    <?= sanitize($i['subject'] ?: 'No subject') ?>
                                </a>
                                <div class="person__meta"><?= sanitize(truncate($i['message'], 70)) ?></div>
                            </td>
                            <td class="cell-date">
                                <?= formatDate($i['created_at']) ?>
                                <div class="person__meta"><?= date('H:i', strtotime($i['created_at'])) ?></div>
                            </td>
                            <?php if ($isStaff): ?>
                                <td>
                                    <?= uiStatus($i['status']) ?>
                                    <?php if ($waiting): ?>
                                        <div class="person__meta text-warning">Awaiting reply</div>
                                    <?php elseif (!empty($i['assigned_name'])): ?>
                                        <div class="person__meta"><?= sanitize($i['assigned_name']) ?></div>
                                    <?php endif ?>
                                </td>
                            <?php endif ?>
                            <td class="cell-actions">
                                <?= uiRowActions(array_merge(
                                    [['label' => $isStaff ? 'Open and reply' : 'Read enquiry',
                                      'icon' => 'bi-chat-left-text', 'can' => 'inquiries.show',
                                      'url' => $showUrl($id)]],
                                    !empty($i['property_id']) ? [[
                                        'label' => 'View property', 'icon' => 'bi-buildings', 'can' => 'properties.show',
                                        'url' => APP_URL . '/index.php?page=properties&action=show&id=' . (int) $i['property_id'],
                                    ]] : [],
                                    !empty($i['customer_id']) ? [[
                                        'label' => 'View customer', 'icon' => 'bi-person', 'can' => 'customers.show',
                                        'url' => APP_URL . '/index.php?page=customers&action=show&id=' . (int) $i['customer_id'],
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
