<?php
/**
 * Archived properties — the register's other half, and the way back.
 *
 * Archiving was previously a one-way door: the row menu offered "Archive",
 * the property vanished from every list in the application, and nothing
 * anywhere could show it again, let alone restore it. This is the missing
 * screen.
 *
 * It is deliberately not a copy of the active register. The questions asked
 * of an archived property are different ones — when did this leave the books,
 * who took it off, what state was it in, and can I have it back — so the
 * columns answer those rather than repeating price and availability, which
 * are not what anybody is here for.
 *
 * Restore is a POST inside a form with a CSRF token, not a link. A restore
 * reachable by GET is a restore a crawler, a prefetcher or an over-eager
 * browser can perform on its own.
 *
 * Vars from PropertyController::archived().
 */
$covers  = $covers ?? [];
$listUrl = APP_URL . '/index.php?page=properties&action=archived';
$showUrl = static fn(int $id): string => APP_URL . '/index.php?page=properties&action=show&id=' . $id;

$activeRegister = 'archived';

$applied = array_filter([
    'search'        => $filters['search']        ?? '',
    'property_type' => $filters['property_type'] ?? '',
    'category'      => $filters['category']      ?? '',
    'owner_id'      => $filters['owner_id']      ?? '',
], static fn($v): bool => $v !== '' && $v !== null);

$ownerNames = array_column($owners ?? [], 'full_name', 'id');

$toolbar = [
    'page'   => 'properties',
    // The action has to ride along or Apply would drop the viewer back into
    // the active register — a filter form that quietly changes page is a
    // filter form nobody trusts.
    'keep'   => ['action' => 'archived'],
    'search' => [
        'name'        => 'search',
        'value'       => $filters['search'] ?? '',
        'label'       => 'Search archived properties',
        'placeholder' => 'Search by title, code or location…',
    ],
    'filters' => [
        ['name' => 'property_type', 'label' => 'Listing', 'value' => $filters['property_type'] ?? '',
         'options' => $listingTypes, 'all' => 'Any listing'],
        ['name' => 'category', 'label' => 'Type', 'value' => $filters['category'] ?? '',
         'options' => $categories, 'all' => 'Any type'],
        ['name' => 'owner_id', 'label' => 'Owner', 'value' => $filters['owner_id'] ?? '',
         'options' => $ownerNames, 'all' => 'Any owner'],
    ],
];

/**
 * The restore control.
 *
 * A button rather than a menu item, because on this page restoring is the
 * only thing anyone came to do and burying the single purpose of a screen
 * behind a ⋮ is one click charged for nothing. The confirmation states what
 * will actually happen — including the status the property will come back
 * as, which is the one detail somebody restoring needs and cannot guess.
 */
$restoreButton = static function (array $p): string {
    $previous = $p['status_before_archive'] ?: 'available';

    return '<form method="POST" action="' . APP_URL . '/index.php?page=properties&amp;action=restore" class="inline-form">'
         . csrfField()
         . '<input type="hidden" name="id" value="' . (int) $p['id'] . '">'
         . '<button type="submit" class="btn btn--primary btn--sm"'
         . ' data-confirm="It returns to the active Properties register as '
         . sanitize(strtolower(uiLabel($previous)))
         . ', with its photos, owner, agent, documents and history exactly as they are. Nothing was deleted when it was archived."'
         . ' data-confirm-title="Restore this property?"'
         . ' data-confirm-action="Restore property"'
         . ' data-confirm-tone="primary"'
         . ' data-confirm-record="' . sanitize($p['property_code'] . ' · ' . $p['title']) . '">'
         . '<i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restore'
         . '</button></form>';
};
?>

<?php require __DIR__ . '/_register_tabs.php'; ?>

<div class="notice notice--muted">
    <div class="notice__icon"><i class="bi bi-archive" aria-hidden="true"></i></div>
    <div class="notice__body">
        <div class="notice__title">Nothing here has been deleted</div>
        <p class="notice__item">
            An archived property keeps its photographs, owner and agent, documents,
            leases, payments and history. It is hidden from the active register and
            from the public site until it is restored, and restoring returns it to
            the status it held on the way in.
        </p>
    </div>
</div>

<?php require VIEWS_PATH . '/components/ui/list_toolbar.php'; ?>

<?php if (empty($properties)): ?>
    <div class="table-card">
        <?= uiEmptyState([
            'icon'     => 'bi-archive',
            'filtered' => (bool) $applied,
            'title'    => $applied ? 'No archived properties match these filters' : 'The archive is empty',
            'desc'     => $applied
                ? 'Nothing in the archive matches what you have selected. Try widening the search or clearing a filter.'
                : 'Properties you archive are kept here in full, and can be restored to the register at any time.',
            'clearUrl' => $listUrl,
            'actions'  => [[
                'label' => 'Back to properties', 'icon' => 'bi-arrow-left', 'class' => 'btn--outline',
                'can'   => 'properties.view',
                'url'   => APP_URL . '/index.php?page=properties',
            ]],
        ]) ?>
    </div>
<?php else: ?>

<div class="table-card">
    <div class="table-head">
        <div class="table-head__title">
            <?= number_format($totalCount) ?> archived
            <?= $totalCount === 1 ? 'property' : 'properties' ?>
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
                    <th class="col-mid">Agent</th>
                    <th>Was</th>
                    <?= uiSortHeader('Archived', ['desc' => 'updated_desc', 'asc' => 'updated_asc'], 'sort', 'cell-date col-mid') ?>
                    <th class="cell-actions"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($properties as $p): ?>
                    <?php
                    $id    = (int) $p['id'];
                    $cover = $covers[$id] ?? null;
                    ?>
                    <tr>
                        <td>
                            <?php /* The thumbnail is the fastest way to recognise a
                                     property you archived months ago — a code and a
                                     title are not how anyone remembers a building. */ ?>
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
                        <td class="col-mid"><?= sanitize($p['agent_name'] ?: '—') ?></td>
                        <td>
                            <?php /* The status it will come back as. Shown rather than
                                     implied, so the restore below holds no surprises. */ ?>
                            <?php if (!empty($p['status_before_archive'])): ?>
                                <?= uiStatus($p['status_before_archive']) ?>
                            <?php else: ?>
                                <span class="text-subtle" title="Archived before this was recorded">—</span>
                            <?php endif ?>
                        </td>
                        <td class="cell-date col-mid">
                            <?= formatDate($p['archived_at'] ?? $p['updated_at']) ?>
                            <?php if (!empty($p['archived_by_name'])): ?>
                                <div class="person__meta">by <?= sanitize($p['archived_by_name']) ?></div>
                            <?php endif ?>
                        </td>
                        <td class="cell-actions">
                            <div class="cell-actions__pair">
                                <?= $restoreButton($p) ?>
                                <?= uiRowActions([
                                    ['label' => 'View details', 'icon' => 'bi-eye',
                                     'url' => $showUrl($id), 'can' => 'properties.show'],
                                ]) ?>
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
