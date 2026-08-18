<?php
/**
 * Customers — the register.
 *
 * A customer is a business record; a user account is a way to sign in. The
 * two are separate rows and the Login column reports the account itself, so
 * this page and Users & Roles can never disagree about who can get in.
 *
 * Vars from CustomerController::index().
 */
$pageTitle    = 'Customers';
$pageSubtitle = 'Tenants and buyers, their contact details and their access to the portal.';
$breadcrumbs  = [['label' => 'Customers']];
$actionButton = [
    'label' => 'Add Customer',
    'icon'  => 'bi-person-plus',
    'url'   => APP_URL . '/index.php?page=customers&action=create',
    'attrs' => ['data-modal-open' => 'customerCreateModal'],
];

$fd   = $formData ?? [];
$errs = $formErrors ?? [];

$listUrl = APP_URL . '/index.php?page=customers';
$showUrl = static fn(int $id): string => $listUrl . '&action=show&id=' . $id;

$applied = array_filter([
    'search'        => $filters['search']        ?? '',
    'customer_type' => $filters['customer_type'] ?? '',
    'login'         => $filters['login']         ?? '',
], static fn($v): bool => $v !== '' && $v !== null);

$chipLabels = [
    'search'        => ['Search', static fn($v) => '“' . $v . '”'],
    'customer_type' => ['Type',   static fn($v) => $typeLabels[$v] ?? $v],
    'login'         => ['Access', static fn($v) => $loginStates[$v] ?? $v],
];

$without = static function (string $key) use ($listUrl): string {
    $params = $_GET;
    unset($params[$key], $params['p'], $params['page']);
    return $listUrl . ($params ? '&' . http_build_query($params) : '');
};

$toolbar = [
    'page'   => 'customers',
    'search' => [
        'name'        => 'search',
        'value'       => $filters['search'] ?? '',
        'label'       => 'Search customers',
        'placeholder' => 'Search by name, phone, email or ID…',
    ],
    'filters' => [
        ['name' => 'customer_type', 'label' => 'Type', 'value' => $filters['customer_type'] ?? '',
         'options' => $typeLabels, 'all' => 'Any type'],
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
    <?php if (empty($customers)): ?>
        <?= uiEmptyState([
            'icon'     => 'bi-people',
            'filtered' => (bool) $applied,
            'title'    => $applied ? 'No customers match these filters' : 'No customers yet',
            'desc'     => $applied
                ? 'Nothing in the register matches what you have selected.'
                : 'Add your first tenant or buyer to start recording leases and payments.',
            'clearUrl' => $listUrl,
            'actions'  => [[
                'label' => 'Add Customer', 'icon' => 'bi-person-plus', 'can' => 'customers.create',
                'url'   => $listUrl . '&action=create',
                'attrs' => ['data-modal-open' => 'customerCreateModal'],
            ]],
        ]) ?>
    <?php else: ?>
        <div class="table-head">
            <div class="table-head__title">
                <?= number_format($totalCount) ?> <?= $totalCount === 1 ? 'customer' : 'customers' ?>
                <?php if ($applied): ?><span class="table-head__count">matching</span><?php endif ?>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <?= uiSortHeader('Customer', ['asc' => 'name_asc', 'desc' => 'name_desc']) ?>
                        <th>Contact</th>
                        <?= uiSortHeader('Type', ['asc' => 'type_asc', 'desc' => 'type_desc'], 'sort', 'col-lo') ?>
                        <?= uiSortHeader('Risk', ['desc' => 'risk_desc', 'asc' => 'risk_asc'], 'sort', 'col-mid') ?>
                        <th class="col-mid">Portal access</th>
                        <th class="cell-actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $c): ?>
                        <tr>
                            <td>
                                <?= uiPersonCell(
                                    $c['full_name'],
                                    $c['profile_photo'] ?? null,
                                    $c['national_id'] ? 'ID ' . $c['national_id'] : '',
                                    $showUrl((int) $c['id'])
                                ) ?>
                                <?php if ($c['is_blacklisted']): ?>
                                    <?= uiStatus('rejected', 'Blacklisted') ?>
                                <?php endif ?>
                            </td>
                            <td>
                                <div><?= sanitize($c['phone'] ?: '—') ?></div>
                                <?php if (!empty($c['email'])): ?>
                                    <div class="person__meta"><?= sanitize($c['email']) ?></div>
                                <?php endif ?>
                            </td>
                            <td class="col-lo"><?= sanitize(uiLabel((string) $c['customer_type'])) ?></td>
                            <td class="col-mid">
                                <?php /* Risk is its own vocabulary, so it is mapped onto the
                                         shared status tones rather than given a private set. */ ?>
                                <?= uiStatus(
                                    ['high' => 'overdue', 'medium' => 'pending'][$c['risk_level']] ?? 'active',
                                    uiLabel((string) $c['risk_level']) . ' risk'
                                ) ?>
                            </td>
                            <td class="col-mid">
                                <?php /* Read from the linked account itself, so this column
                                         and the Users page can never disagree. */ ?>
                                <?php if (!$c['account_id']): ?>
                                    <span class="text-subtle" title="Business record only — no account">No account</span>
                                <?php else: ?>
                                    <?= uiStatus($c['account_active'] ? 'active' : 'inactive',
                                                 $c['account_active'] ? 'Enabled' : 'Disabled') ?>
                                    <div class="person__meta"><?= sanitize($c['account_email']) ?></div>
                                <?php endif ?>
                            </td>
                            <td class="cell-actions">
                                <?= uiRowActions([
                                    ['label' => 'View profile', 'icon' => 'bi-eye', 'can' => 'customers.show',
                                     'url' => $showUrl((int) $c['id'])],
                                    ['label' => 'Edit', 'icon' => 'bi-pencil', 'can' => 'customers.edit',
                                     'url' => $listUrl . '&action=edit&id=' . (int) $c['id']],
                                    /* Both go by POST with a CSRF token: they change state, and a
                                       state-changing link is one a prefetcher or a crawler can fire.
                                       blacklist() only acts on POST in any case — before this menu
                                       existed there was no control anywhere that reached it. */
                                    ...($c['is_blacklisted'] ? [[
                                        'label' => 'Remove from blacklist', 'icon' => 'bi-check2-circle',
                                        'can' => 'customers.unlist', 'method' => 'post',
                                        'url' => $listUrl . '&action=unlist&id=' . (int) $c['id'],
                                        'confirm' => ['title' => 'Remove from the blacklist?', 'tone' => 'info',
                                                      'action' => 'Remove', 'record' => $c['full_name'],
                                                      'body' => 'They can be given new leases and reservations again.'],
                                    ]] : [[
                                        'label' => 'Blacklist', 'icon' => 'bi-slash-circle',
                                        'can' => 'customers.blacklist', 'danger' => true, 'method' => 'post',
                                        'url' => $listUrl . '&action=blacklist&id=' . (int) $c['id'],
                                        'confirm' => ['title' => 'Blacklist this customer?',
                                                      'action' => 'Blacklist', 'record' => $c['full_name'],
                                                      'body' => 'They are flagged across the system and blocked from new leases and reservations. Existing leases, payments and documents are kept.'],
                                    ]]),
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

<?php /* Opens over the refreshed list right after a customer is saved. */ ?>
<?php if (!empty($grantCustomer)): ?>
    <?php require __DIR__ . '/_access_modals.php'; ?>
<?php endif ?>
