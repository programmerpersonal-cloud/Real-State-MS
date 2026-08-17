<?php
/**
 * Users & Roles — the accounts directory.
 *
 * An account and the record it belongs to are two different things, and the
 * gap between them is what this page exists to show: an Owner account with no
 * owner profile cannot see its own portfolio, and a Customer account with no
 * customer row cannot see its lease. Both are surfaced in the row with the fix
 * one click away.
 *
 * Vars from UserController::index().
 */
$fd   = $formData ?? [];
$errs = $formErrors ?? [];

$listUrl = APP_URL . '/index.php?page=users';
$meId    = (int) ($_SESSION['user_id'] ?? 0);

/* Role is the primary axis here, so it takes the pill row. The pills are
   built from the roles table and counted in one grouped query. */
$roleOptions = [];
foreach ($roles as $r) {
    $roleOptions[(string) $r['id']] = $r['display_name'];
}
$statusFilter = [
    'param'   => 'role_id',
    'value'   => (string) ($filters['role_id'] ?? ''),
    'options' => $roleOptions,
    'counts'  => $roleCounts,
    'total'   => array_sum($roleCounts),
    'all'     => 'All accounts',
    'tones'   => false,   // a role is not a status and has no tone map
];

$toolbar = [
    'page'   => 'users',
    'keep'   => array_filter([
        'role_id' => (string) ($filters['role_id'] ?? ''),
        'state'   => $state,
    ]),
    'search' => [
        'name'        => 'search',
        'value'       => $filters['search'] ?? '',
        'label'       => 'Search accounts',
        'placeholder' => 'Search by name, email or username…',
    ],
    'filters' => [
        ['name' => 'state', 'label' => 'Access', 'value' => $state,
         'options' => ['active' => 'Can sign in', 'disabled' => 'Disabled'],
         'all' => 'Any access'],
    ],
    'actions' => [
        ['label' => 'Permissions', 'icon' => 'bi-shield-check', 'class' => 'btn--outline',
         'can' => 'users.view', 'url' => $listUrl . '&action=permissions'],
        ['label' => 'Add User', 'icon' => 'bi-plus-lg', 'class' => 'btn--primary',
         'can' => 'users.create', 'url' => $listUrl . '&action=create',
         'attrs' => ['data-modal-open' => 'userCreateModal']],
    ],
];

$applied = array_filter([
    'search' => $filters['search'] ?? '',
    'state'  => $state,
], static fn($v): bool => $v !== '' && $v !== null);

$chipLabels = [
    'search' => ['Search', static fn($v) => '“' . $v . '”'],
    'state'  => ['Access', static fn($v) => $v === 'active' ? 'Can sign in' : 'Disabled'],
];

$isFiltered = (bool) $applied || ($filters['role_id'] ?? '') !== '';

$without = static function (string $key) use ($listUrl): string {
    $params = $_GET;
    unset($params[$key], $params['p'], $params['page']);
    return $listUrl . ($params ? '&' . http_build_query($params) : '');
};

/* An account whose role promises a profile that does not exist. Everything
   scoped to that person — their lease, their portfolio, their requests — is
   read through the missing link, so the account works but sees nothing. */
$missingProfile = static function (array $u): ?array {
    if ($u['role_name'] === ROLE_OWNER && !$u['owner_profile_id']) {
        return ['page' => 'owners', 'noun' => 'owner',
                'why'  => 'This account has the Owner role but no owner profile, so it cannot see its portfolio or income.'];
    }
    if ($u['role_name'] === ROLE_CUSTOMER && !$u['customer_profile_id']) {
        return ['page' => 'customers', 'noun' => 'customer',
                'why'  => 'This account has the Customer role but no customer record, so it cannot see a lease, its payments, or file a maintenance request.'];
    }
    return null;
};
?>

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
    <?php if (empty($users)): ?>
        <?= uiEmptyState([
            'icon'     => 'bi-people',
            'filtered' => $isFiltered,
            'title'    => $isFiltered ? 'No accounts match these filters' : 'No accounts yet',
            'desc'     => $isFiltered
                ? 'Nothing in the directory matches what you have selected.'
                : 'An account is how a person signs in. Their business record — owner, customer — is separate and linked to it.',
            'clearUrl' => $listUrl,
            'actions'  => [[
                'label' => 'Add User', 'icon' => 'bi-plus-lg', 'can' => 'users.create',
                'url'   => $listUrl . '&action=create',
                'attrs' => ['data-modal-open' => 'userCreateModal'],
            ]],
        ]) ?>
    <?php else: ?>
        <div class="table-head">
            <div class="table-head__title">
                <?= number_format($totalCount) ?> <?= $totalCount === 1 ? 'account' : 'accounts' ?>
                <?php if ($isFiltered): ?><span class="table-head__count">matching</span><?php endif ?>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <?= uiSortHeader('Name', ['asc' => 'name_asc', 'desc' => 'name_desc']) ?>
                        <th>Signs in with</th>
                        <?= uiSortHeader('Role', ['asc' => 'role_asc', 'desc' => 'role_desc']) ?>
                        <th>Linked record</th>
                        <?= uiSortHeader('Last signed in', ['desc' => 'signin_desc', 'asc' => 'signin_asc'], 'sort', 'cell-date') ?>
                        <th>Access</th>
                        <th class="cell-actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <?php
                        $id      = (int) $u['id'];
                        $isSelf  = $id === $meId;
                        $gap     = $missingProfile($u);
                        $phone   = $u['owner_profile_phone'] ?: ($u['customer_profile_phone'] ?: $u['phone']);
                        ?>
                        <tr>
                            <td>
                                <?= uiPersonCell(
                                    $u['full_name'],
                                    $u['avatar'] ?? null,
                                    (string) ($phone ?: ''),
                                    can('users.edit') ? $listUrl . '&action=edit&id=' . $id : null
                                ) ?>
                                <?php if ($isSelf): ?>
                                    <span class="status status--primary">You</span>
                                <?php endif ?>
                            </td>
                            <td>
                                <div><?= sanitize($u['email']) ?></div>
                                <div class="person__meta"><?= sanitize($u['username']) ?></div>
                            </td>
                            <td>
                                <?= uiStatus('new', $u['role_display']) ?>
                                <?php if (!empty($u['branch_name'])): ?>
                                    <div class="person__meta"><?= sanitize($u['branch_name']) ?></div>
                                <?php endif ?>
                            </td>
                            <td>
                                <?php if ($u['owner_profile_id']): ?>
                                    <a href="<?= APP_URL ?>/index.php?page=owners&amp;action=show&amp;id=<?= (int) $u['owner_profile_id'] ?>">
                                        <i class="bi bi-link-45deg" aria-hidden="true"></i>
                                        Owner #<?= (int) $u['owner_profile_id'] ?>
                                    </a>
                                <?php elseif ($u['customer_profile_id']): ?>
                                    <a href="<?= APP_URL ?>/index.php?page=customers&amp;action=show&amp;id=<?= (int) $u['customer_profile_id'] ?>">
                                        <i class="bi bi-link-45deg" aria-hidden="true"></i>
                                        <?= sanitize(uiLabel((string) ($u['customer_profile_type'] ?: 'customer'))) ?>
                                        #<?= (int) $u['customer_profile_id'] ?>
                                    </a>
                                <?php elseif ($gap): ?>
                                    <?php /* The mismatch made visible, with the fix one click away:
                                             this builds the missing profile from the account's own
                                             details and links it — it never invents a second person. */ ?>
                                    <form method="POST"
                                          action="<?= APP_URL ?>/index.php?page=<?= $gap['page'] ?>&amp;action=create-profile&amp;user_id=<?= $id ?>">
                                        <?= csrfField() ?>
                                        <button type="submit" class="linkfix"
                                                data-confirm="<?= sanitize($gap['why']) ?> Creating one uses this account's own name and email, and links the two."
                                                data-confirm-title="Create the missing <?= $gap['noun'] ?> record?"
                                                data-confirm-action="Create and link"
                                                data-confirm-record="<?= sanitize($u['full_name']) ?>"
                                                data-confirm-tone="warning">
                                            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                                            No <?= $gap['noun'] ?> record — create
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-subtle">—</span>
                                <?php endif ?>
                            </td>
                            <td class="cell-date">
                                <?php if ($u['last_login_at']): ?>
                                    <?= formatDate($u['last_login_at']) ?>
                                    <div class="person__meta"><?= date('H:i', strtotime($u['last_login_at'])) ?></div>
                                <?php else: ?>
                                    <span class="text-subtle">Never</span>
                                <?php endif ?>
                            </td>
                            <td>
                                <?= uiStatus($u['is_active'] ? 'active' : 'inactive',
                                             $u['is_active'] ? 'Can sign in' : 'Disabled') ?>
                            </td>
                            <td class="cell-actions">
                                <?= uiRowActions(array_merge(
                                    [['label' => 'Edit account', 'icon' => 'bi-pencil', 'can' => 'users.edit',
                                      'url' => $listUrl . '&action=edit&id=' . $id]],
                                    [[
                                        'label' => 'Reset password', 'icon' => 'bi-key',
                                        'can' => 'users.reset-pass', 'method' => 'post',
                                        'url' => $listUrl . '&action=reset-pass&id=' . $id,
                                        'confirm' => [
                                            'title'  => 'Issue a new password?',
                                            'action' => 'Reset password',
                                            'record' => $u['full_name'] . ' · ' . $u['email'],
                                            'tone'   => 'warning',
                                            'body'   => 'Their current password stops working immediately. The new one is shown once, on the next screen, for you to pass on.',
                                        ],
                                    ]],
                                    // Disabling the account you are signed in
                                    // with is refused by the controller; not
                                    // offering it here saves the round trip.
                                    $isSelf ? [] : [[
                                        'label' => $u['is_active'] ? 'Disable sign-in' : 'Re-enable sign-in',
                                        'icon'  => $u['is_active'] ? 'bi-lock' : 'bi-unlock',
                                        'can' => 'users.toggle', 'method' => 'post',
                                        'danger' => (bool) $u['is_active'],
                                        'url' => $listUrl . '&action=toggle&id=' . $id,
                                        'confirm' => $u['is_active'] ? [
                                            'title'  => 'Disable this account?',
                                            'action' => 'Disable sign-in',
                                            'record' => $u['full_name'],
                                            'tone'   => 'danger',
                                            'body'   => 'They can no longer sign in. Everything they created stays exactly as it is, and access can be given back at any time.',
                                        ] : null,
                                    ]]
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
