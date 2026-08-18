<?php
/**
 * Owners — the directory.
 *
 * An owner is a business record; a user account is a way to sign in. The
 * Access column reports the linked account itself, so this page and Users &
 * Roles can never disagree about who can get in.
 *
 * Vars from OwnerController::index().
 */
$pageTitle    = 'Property Owners';
$pageSubtitle = 'Landlords and vendors, their commission terms and their access to the portal.';
$breadcrumbs  = [['label' => 'Owners']];
$actionButton = [
    'label' => 'Add Owner',
    'icon'  => 'bi-plus-lg',
    'url'   => APP_URL . '/index.php?page=owners&action=create',
    'attrs' => ['data-modal-open' => 'ownerCreateModal'],
];

$fd   = $formData ?? [];
$errs = $formErrors ?? [];

$listUrl = APP_URL . '/index.php?page=owners';
$showUrl = static fn(int $id): string => $listUrl . '&action=show&id=' . $id;

$applied = array_filter([
    'search' => $filters['search'] ?? '',
    'login'  => $filters['login']  ?? '',
], static fn($v): bool => $v !== '' && $v !== null);

$chipLabels = [
    'search' => ['Search', static fn($v) => '“' . $v . '”'],
    'login'  => ['Access', static fn($v) => $loginStates[$v] ?? $v],
];

$without = static function (string $key) use ($listUrl): string {
    $params = $_GET;
    unset($params[$key], $params['p'], $params['page']);
    return $listUrl . ($params ? '&' . http_build_query($params) : '');
};

$toolbar = [
    'page'   => 'owners',
    'search' => [
        'name'        => 'search',
        'value'       => $filters['search'] ?? '',
        'label'       => 'Search owners',
        'placeholder' => 'Search by name, phone or email…',
    ],
    'filters' => [
        ['name' => 'login', 'label' => 'Access', 'value' => $filters['login'] ?? '',
         'options' => $loginStates, 'all' => 'Any access'],
    ],
];
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
    <?php if (empty($owners)): ?>
        <?= uiEmptyState([
            'icon'     => 'bi-person-badge',
            'filtered' => (bool) $applied,
            'title'    => $applied ? 'No owners match these filters' : 'No owners yet',
            'desc'     => $applied
                ? 'Nothing in the directory matches what you have selected.'
                : 'Add an owner to start assigning properties and tracking commission.',
            'clearUrl' => $listUrl,
            'actions'  => [[
                'label' => 'Add Owner', 'icon' => 'bi-plus-lg', 'can' => 'owners.create',
                'url'   => $listUrl . '&action=create',
                'attrs' => ['data-modal-open' => 'ownerCreateModal'],
            ]],
        ]) ?>
    <?php else: ?>
        <div class="table-head">
            <div class="table-head__title">
                <?= number_format($totalCount) ?> <?= $totalCount === 1 ? 'owner' : 'owners' ?>
                <?php if ($applied): ?><span class="table-head__count">matching</span><?php endif ?>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <?= uiSortHeader('Owner', ['asc' => 'name_asc', 'desc' => 'name_desc']) ?>
                        <th>Contact</th>
                        <?= uiSortHeader('Commission', ['desc' => 'comm_desc', 'asc' => 'comm_asc'], 'sort', 'cell-num col-mid') ?>
                        <th class="col-mid">Portal access</th>
                        <th class="cell-actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($owners as $o): ?>
                        <tr>
                            <td>
                                <?= uiPersonCell(
                                    $o['full_name'],
                                    $o['avatar'] ?? null,
                                    $o['national_id'] ?? '',
                                    $showUrl((int) $o['id'])
                                ) ?>
                            </td>
                            <td>
                                <div><?= sanitize($o['phone'] ?: '—') ?></div>
                                <?php if (!empty($o['email'])): ?>
                                    <div class="person__meta"><?= sanitize($o['email']) ?></div>
                                <?php endif ?>
                            </td>
                            <td class="cell-num col-mid"><?= number_format((float) $o['commission_rate'], 1) ?>%</td>
                            <td class="col-mid">
                                <?php if (!$o['account_id']): ?>
                                    <span class="text-subtle" title="Business record only — no account">No account</span>
                                <?php else: ?>
                                    <?= uiStatus($o['account_active'] ? 'active' : 'inactive',
                                                 $o['account_active'] ? 'Enabled' : 'Disabled') ?>
                                    <div class="person__meta"><?= sanitize($o['account_email']) ?></div>
                                <?php endif ?>
                            </td>
                            <td class="cell-actions">
                                <?= uiRowActions([
                                    ['label' => 'View profile', 'icon' => 'bi-eye', 'can' => 'owners.show',
                                     'url' => $showUrl((int) $o['id'])],
                                    ['label' => 'Edit', 'icon' => 'bi-pencil', 'can' => 'owners.edit',
                                     'url' => $listUrl . '&action=edit&id=' . (int) $o['id']],
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

<?php /* Opens over the refreshed list right after an owner is saved. */ ?>
<?php if (!empty($grantOwner)): ?>
    <?php require __DIR__ . '/_access_modals.php'; ?>
<?php endif ?>
