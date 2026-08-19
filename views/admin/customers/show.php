<?php
/**
 * Customer — profile.
 *
 * The account panel reads the linked users row (customers.user_id), so what it
 * reports is the same record the Users & Roles page lists — there is no second
 * copy of this person anywhere.
 *
 * Vars from CustomerController::show().
 */
$c          = $customer;
$hasAccount = !empty($c['account_id']);

// Granting or revoking a login is an administrator action; editing the profile
// is staff work. Both read the matrix rather than naming roles.
$canManage = can('customers.enable-login');

$pageTitle   = sanitize($c['full_name']);
$breadcrumbs = [
    ['label' => 'Customers', 'url' => APP_URL . '/index.php?page=customers'],
    ['label' => $c['full_name']],
];
// The record header below owns the actions; a second row of buttons above it
// would say the same thing twice.
$actionButton = null;

$base    = APP_URL . '/index.php?page=customers';
$editUrl = $base . '&action=edit&id=' . (int) $c['id'];

$riskStatus = ['high' => 'overdue', 'medium' => 'pending'][$c['risk_level']] ?? 'active';

$tabs = [
    ['key' => 'profile',  'label' => 'Profile',  'icon' => 'bi-person',              'count' => null],
    ['key' => 'rentals',  'label' => 'Rentals',  'icon' => 'bi-file-earmark-text',   'count' => count($rentalHistory ?? [])],
    ['key' => 'payments', 'label' => 'Payments', 'icon' => 'bi-credit-card',         'count' => count($paymentHistory ?? [])],
];
?>

<div class="detail-header">
    <div class="detail-header__media detail-header__media--avatar">
        <img src="<?= sanitize(getAvatarUrl($c['profile_photo'] ?? null)) ?>" alt="" width="144" height="144">
    </div>

    <div class="detail-header__body">
        <div class="detail-header__eyebrow"><?= sanitize(uiLabel((string) $c['customer_type'])) ?></div>
        <h2 class="detail-header__title"><?= sanitize($c['full_name']) ?></h2>

        <div class="detail-header__meta">
            <?= uiStatus($riskStatus, uiLabel((string) $c['risk_level']) . ' risk') ?>
            <?php if ($c['is_blacklisted']): ?>
                <?= uiStatus('rejected', 'Blacklisted') ?>
            <?php endif ?>
            <?php if (!empty($c['phone'])): ?>
                <span><i class="bi bi-telephone" aria-hidden="true"></i> <?= sanitize($c['phone']) ?></span>
            <?php endif ?>
            <?php if (!empty($c['email'])): ?>
                <span><i class="bi bi-envelope" aria-hidden="true"></i> <?= sanitize($c['email']) ?></span>
            <?php endif ?>
            <span>
                <i class="bi bi-key" aria-hidden="true"></i>
                <?= !$hasAccount ? 'No portal account' : ($c['account_active'] ? 'Portal enabled' : 'Portal disabled') ?>
            </span>
        </div>
    </div>

    <div class="detail-header__actions">
        <?php if (can('customers.edit')): ?>
            <a href="<?= $editUrl ?>" class="btn btn--primary btn--sm">
                <i class="bi bi-pencil" aria-hidden="true"></i> Edit
            </a>
        <?php endif ?>
        <?= uiRowActions(array_merge(
            $hasAccount ? [] : [[
                'label' => 'Enable login access', 'icon' => 'bi-key', 'can' => 'customers.enable-login',
                'url' => '#', 'attrs' => ['data-modal-open' => 'customerLoginModal'],
            ]],
            $hasAccount && $c['account_active'] ? [[
                'label' => 'Disable login access', 'icon' => 'bi-lock', 'can' => 'customers.disable-login',
                'method' => 'post', 'url' => $base . '&action=disable-login&id=' . (int) $c['id'],
                'confirm' => [
                    'title' => 'Disable login for this customer?', 'action' => 'Disable access',
                    'record' => $c['full_name'], 'tone' => 'warning',
                    'body' => 'They can no longer sign in to the portal. Their profile, leases and payment history are kept.',
                ],
            ]] : [],
            $hasAccount && !$c['account_active'] ? [[
                'label' => 'Re-enable login access', 'icon' => 'bi-unlock', 'can' => 'customers.enable-login',
                'method' => 'post', 'url' => $base . '&action=enable-login&id=' . (int) $c['id'],
            ]] : [],
            $c['is_blacklisted'] ? [[
                'label' => 'Remove from blacklist', 'icon' => 'bi-check2-circle', 'can' => 'customers.unlist',
                'method' => 'post', 'url' => $base . '&action=unlist&id=' . (int) $c['id'],
                'confirm' => ['title' => 'Remove from the blacklist?', 'tone' => 'info', 'action' => 'Remove',
                              'record' => $c['full_name'],
                              'body' => 'They can be given new leases and reservations again.'],
            ]] : [[
                'label' => 'Blacklist', 'icon' => 'bi-slash-circle', 'can' => 'customers.blacklist',
                'danger' => true, 'method' => 'post',
                'url' => $base . '&action=blacklist&id=' . (int) $c['id'],
                'confirm' => ['title' => 'Blacklist this customer?', 'action' => 'Blacklist',
                              'record' => $c['full_name'],
                              'body' => 'They are flagged across the system and blocked from new leases and reservations. Existing records are kept.'],
            ]]
        ), 'More actions') ?>
    </div>
</div>

<div class="tabs" data-tabs role="tablist">
    <?php foreach ($tabs as $i => $t): ?>
        <button type="button" class="tabs__item<?= $i === 0 ? ' is-active' : '' ?>"
                data-tab="<?= $t['key'] ?>" role="tab" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>">
            <i class="bi <?= $t['icon'] ?>" aria-hidden="true"></i>
            <?= sanitize($t['label']) ?>
            <?php if ($t['count'] !== null): ?>
                <span class="tabs__count"><?= (int) $t['count'] ?></span>
            <?php endif ?>
        </button>
    <?php endforeach ?>
</div>

<!-- ── Profile ──────────────────────────────────────────────────── -->
<div class="tab-panel is-active" data-panel="profile">
    <div class="detail-cols">
        <div class="detail-cols__main">
            <div class="card mb-3">
                <div class="card__header"><h3 class="card__title">Contact &amp; personal</h3></div>
                <div class="card__body">
                    <dl class="datalist">
                        <div class="datalist__row"><dt>Phone</dt><dd><?= sanitize($c['phone'] ?: '—') ?></dd></div>
                        <div class="datalist__row"><dt>Email</dt><dd><?= sanitize($c['email'] ?: '—') ?></dd></div>
                        <div class="datalist__row"><dt>National ID</dt><dd><?= sanitize($c['national_id'] ?: '—') ?></dd></div>
                        <div class="datalist__row"><dt>Address</dt><dd><?= sanitize($c['address'] ?: '—') ?></dd></div>
                        <div class="datalist__row"><dt>Occupation</dt><dd><?= sanitize($c['occupation'] ?: '—') ?></dd></div>
                        <div class="datalist__row"><dt>Guarantor</dt>
                            <dd>
                                <?= sanitize($c['guarantor_name'] ?: '—') ?>
                                <?= $c['guarantor_contact'] ? '<span class="text-muted">(' . sanitize($c['guarantor_contact']) . ')</span>' : '' ?>
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <?php if (!empty($c['notes'])): ?>
                <div class="card">
                    <div class="card__header"><h3 class="card__title">Notes</h3></div>
                    <div class="card__body"><p class="prose"><?= nl2br(sanitize($c['notes'])) ?></p></div>
                </div>
            <?php endif ?>
        </div>

        <aside class="detail-cols__side">
            <div class="card mb-3">
                <div class="card__header"><h3 class="card__title">Classification</h3></div>
                <div class="card__body">
                    <dl class="datalist">
                        <div class="datalist__row"><dt>Type</dt><dd><?= sanitize(uiLabel((string) $c['customer_type'])) ?></dd></div>
                        <div class="datalist__row"><dt>Risk</dt><dd><?= uiStatus($riskStatus, uiLabel((string) $c['risk_level'])) ?></dd></div>
                        <div class="datalist__row"><dt>Standing</dt>
                            <dd><?= $c['is_blacklisted'] ? uiStatus('rejected', 'Blacklisted') : uiStatus('active', 'In good standing') ?></dd>
                        </div>
                        <div class="datalist__row"><dt>Added</dt><dd class="num"><?= formatDate($c['created_at']) ?></dd></div>
                        <?php if (!empty($c['created_by_name'])): ?>
                            <div class="datalist__row"><dt>Added by</dt><dd><?= sanitize($c['created_by_name']) ?></dd></div>
                        <?php endif ?>
                    </dl>
                </div>
            </div>

            <div class="card">
                <div class="card__header"><h3 class="card__title">Portal account</h3></div>
                <div class="card__body">
                    <dl class="datalist">
                        <div class="datalist__row"><dt>Access</dt>
                            <dd>
                                <?php if (!$hasAccount): ?>
                                    <?= uiStatus('inactive', 'No account') ?>
                                <?php else: ?>
                                    <?= uiStatus($c['account_active'] ? 'active' : 'pending',
                                                 $c['account_active'] ? 'Enabled' : 'Disabled') ?>
                                <?php endif ?>
                            </dd>
                        </div>
                        <?php if ($hasAccount): ?>
                            <div class="datalist__row"><dt>Signs in with</dt><dd><?= sanitize($c['account_email']) ?></dd></div>
                            <div class="datalist__row"><dt>Username</dt><dd><code><?= sanitize($c['account_username']) ?></code></dd></div>
                            <div class="datalist__row"><dt>Role</dt><dd><?= uiStatus('new', $c['account_role_display'] ?? 'Customer') ?></dd></div>
                            <div class="datalist__row"><dt>Last login</dt>
                                <dd class="num"><?= $c['account_last_login'] ? formatDateTime($c['account_last_login']) : 'Never' ?></dd>
                            </div>
                            <?php if ($canManage): ?>
                                <div class="datalist__row"><dt>User record</dt>
                                    <dd><a href="<?= APP_URL ?>/index.php?page=users&amp;action=edit&amp;id=<?= (int) $c['account_id'] ?>">#<?= (int) $c['account_id'] ?> in Users &amp; Roles</a></dd>
                                </div>
                            <?php endif ?>
                        <?php endif ?>
                    </dl>

                    <?php if ($canManage && !$hasAccount): ?>
                        <p class="form-hint">
                            This customer is a business record only. Giving them access creates one account
                            with the role <strong>Customer</strong> — or adopts the existing customer account
                            that already carries their email.
                        </p>
                        <button type="button" class="btn btn--primary btn--sm" data-modal-open="customerLoginModal">
                            <i class="bi bi-key" aria-hidden="true"></i> Enable login access
                        </button>
                    <?php endif ?>
                </div>
            </div>
        </aside>
    </div>
</div>

<!-- ── Rentals ──────────────────────────────────────────────────── -->
<div class="tab-panel" data-panel="rentals">
    <div class="table-card">
        <?php if (empty($rentalHistory)): ?>
            <?= uiEmptyState([
                'icon'  => 'bi-file-earmark-text',
                'title' => 'No rental history',
                'desc'  => 'This customer has not held a lease.',
                'actions' => [[
                    'label' => 'New lease', 'icon' => 'bi-plus-lg', 'can' => 'leases.create',
                    'url'   => APP_URL . '/index.php?page=leases&action=create',
                ]],
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <th>Property</th><th class="col-lo">Period</th>
                        <th class="cell-num">Rent</th><th>Status</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($rentalHistory as $r): ?>
                            <tr>
                                <td class="cell-strong"><?= sanitize($r['property_title']) ?></td>
                                <td class="cell-date col-lo"><?= formatDate($r['start_date']) ?> — <?= formatDate($r['end_date']) ?></td>
                                <td class="cell-num"><?= formatCurrency((float) $r['rent_amount']) ?></td>
                                <td><?= uiStatus($r['status']) ?></td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php endif ?>
    </div>
</div>

<!-- ── Payments ─────────────────────────────────────────────────── -->
<div class="tab-panel" data-panel="payments">
    <div class="table-card">
        <?php if (empty($paymentHistory)): ?>
            <?= uiEmptyState([
                'icon'  => 'bi-credit-card',
                'title' => 'No payments recorded',
                'desc'  => 'Nothing has been paid by this customer yet.',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <th>Code</th><th class="col-lo">Type</th><th class="col-mid">Property</th>
                        <th class="cell-num">Amount</th><th>Date</th><th>Status</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($paymentHistory as $py): ?>
                            <tr>
                                <td><span class="table__id"><?= sanitize($py['payment_code']) ?></span></td>
                                <td class="col-lo"><?= sanitize(uiLabel((string) $py['payment_type'])) ?></td>
                                <td class="col-mid"><?= sanitize($py['property_title'] ?? '—') ?></td>
                                <td class="cell-num"><strong><?= formatCurrency((float) $py['amount']) ?></strong></td>
                                <td class="cell-date"><?= formatDate($py['payment_date']) ?></td>
                                <td><?= uiStatus($py['status']) ?></td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php endif ?>
    </div>
</div>

<?php if ($canManage && !$hasAccount): ?>
    <?php require __DIR__ . '/_login_modal.php'; ?>
<?php endif ?>
