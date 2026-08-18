<?php
/**
 * Audit Logs — the trail.
 *
 * Read-only, and there is no row menu because there is nothing to do to a log
 * entry. What the old table lacked was a way back: an entry saying
 * "updated_lease #4" gave no route to lease 4. The entity is now a link where
 * the reader is allowed to follow it.
 *
 * Vars from AuditController::index().
 */
$listUrl = APP_URL . '/index.php?page=audit-logs';

$toolbar = [
    'page'   => 'audit-logs',
    'search' => [
        'name'        => 'search',
        'value'       => $filters['search'] ?? '',
        'label'       => 'Search the trail',
        'placeholder' => 'Search by person, action or record type…',
    ],
    'filters' => [
        ['name' => 'action_filter', 'label' => 'Action', 'value' => $filters['action_filter'] ?? '',
         'options' => $actions, 'all' => 'Any action'],
    ],
];

$applied = array_filter([
    'search'        => $filters['search'] ?? '',
    'action_filter' => $filters['action_filter'] ?? '',
], static fn($v): bool => $v !== '' && $v !== null);

$chipLabels = [
    'search'        => ['Search', static fn($v) => '“' . $v . '”'],
    'action_filter' => ['Action', static fn($v) => $actions[$v] ?? $v],
];

$without = static function (string $key) use ($listUrl): string {
    $params = $_GET;
    unset($params[$key], $params['p'], $params['page']);
    return $listUrl . ($params ? '&' . http_build_query($params) : '');
};

/* Record types that have a page to go back to. Anything not listed stays
   plain text rather than becoming a link to a 404. */
$targets = [
    'property'    => 'properties',
    'customer'    => 'customers',
    'owner'       => 'owners',
    'lease'       => 'leases',
    'payment'     => 'payments',
    'sale'        => 'sales',
    'reservation' => 'reservations',
    'maintenance' => 'maintenance',
    'inquiry'     => 'inquiries',
    'document'    => 'documents',
    'user'        => 'users',
    'branch'      => 'branches',
];

/* What an entry actually changed, when it recorded both sides. */
$transition = static function (array $l): string {
    $from = trim((string) ($l['old_value'] ?? ''));
    $to   = trim((string) ($l['new_value'] ?? ''));
    if ($from !== '' && $to !== '') {
        return sanitize(truncate($from, 26)) . ' <i class="bi bi-arrow-right" aria-hidden="true"></i> '
             . sanitize(truncate($to, 26));
    }
    return $to !== '' ? sanitize(truncate($to, 56)) : '';
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

<div class="table-card">
    <?php if (empty($logs)): ?>
        <?= uiEmptyState([
            'icon'     => 'bi-journal-text',
            'filtered' => (bool) $applied,
            'title'    => $applied ? 'Nothing matches these filters' : 'No activity yet',
            'desc'     => $applied
                ? 'No entry in the trail matches what you have selected.'
                : 'Every change made through the application is recorded here as it happens.',
            'clearUrl' => $listUrl,
        ]) ?>
    <?php else: ?>
        <div class="table-head">
            <div class="table-head__title">
                <?= number_format($totalCount) ?> <?= $totalCount === 1 ? 'entry' : 'entries' ?>
                <?php if ($applied): ?><span class="table-head__count">matching</span><?php endif ?>
            </div>
            <span class="table-head__note">
                <i class="bi bi-lock" aria-hidden="true"></i>
                Append-only — nothing here can be edited or removed from this screen
            </span>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <?= uiSortHeader('When', ['desc' => 'newest', 'asc' => 'oldest'], 'sort', 'cell-date') ?>
                        <?= uiSortHeader('Who', ['asc' => 'user_asc', 'desc' => 'user_desc']) ?>
                        <?= uiSortHeader('Did what', ['asc' => 'action_asc', 'desc' => 'action_desc']) ?>
                        <th class="col-lo">To which record</th>
                        <th class="col-mid">Change</th>
                        <th class="col-lo">From</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $l): ?>
                        <?php
                        $slug   = $targets[$l['entity_type'] ?? ''] ?? null;
                        $entId  = (int) ($l['entity_id'] ?? 0);
                        $canGo  = $slug && $entId > 0 && canAccessPage($slug);
                        $change = $transition($l);
                        ?>
                        <tr>
                            <td class="cell-date">
                                <?= formatDate($l['created_at']) ?>
                                <div class="person__meta"><?= date('H:i:s', strtotime($l['created_at'])) ?></div>
                            </td>
                            <td>
                                <?php if (!empty($l['full_name'])): ?>
                                    <?= uiPersonCell($l['full_name'], $l['avatar'] ?? null) ?>
                                <?php else: ?>
                                    <span class="text-subtle">System</span>
                                <?php endif ?>
                            </td>
                            <td><?= uiStatus('new', uiLabel((string) $l['action'])) ?></td>
                            <td class="col-lo">
                                <?php if (empty($l['entity_type'])): ?>
                                    <span class="text-subtle">—</span>
                                <?php elseif ($canGo): ?>
                                    <a href="<?= APP_URL ?>/index.php?page=<?= $slug ?>&amp;action=show&amp;id=<?= $entId ?>">
                                        <?= sanitize(uiLabel((string) $l['entity_type'])) ?> #<?= $entId ?>
                                    </a>
                                <?php else: ?>
                                    <?= sanitize(uiLabel((string) $l['entity_type'])) ?><?= $entId ? ' #' . $entId : '' ?>
                                <?php endif ?>
                            </td>
                            <td class="col-mid cell-clip">
                                <?php if ($change !== ''): ?>
                                    <span class="person__meta"><?= $change ?></span>
                                <?php else: ?>
                                    <span class="text-subtle">—</span>
                                <?php endif ?>
                            </td>
                            <td class="col-lo cell-tight">
                                <span class="person__meta"><?= sanitize($l['ip_address'] ?: '—') ?></span>
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
