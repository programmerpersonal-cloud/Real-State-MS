<?php
/**
 * Owner — profile.
 *
 * The account panel reads the linked users row (owners.user_id), so what it
 * reports is the same record the Users & Roles page lists — there is no second
 * copy of this person anywhere.
 *
 * Vars from OwnerController::show().
 */
$o          = $owner;
$hasAccount = !empty($o['account_id']);

// Granting or revoking a login is an administrator action; editing the profile
// is staff work. Both read the matrix rather than naming roles, so an owner
// reading their own page is offered neither.
$canManage = can('owners.enable-login');
$canEdit   = can('owners.edit');

$base    = APP_URL . '/index.php?page=owners';
$editUrl = $base . '&action=edit&id=' . (int) $o['id'];

// The record header owns the actions; the page header would only repeat them.
$actionButton = null;

// Let and unlet, counted from the portfolio already loaded rather than asked
// for again — the rows are here, so a second query would answer a question
// this page can already answer.
$letCount = count(array_filter($properties, static fn(array $p): bool => $p['status'] === 'rented'));

$tabs = [
    ['key' => 'profile',    'label' => 'Profile',    'icon' => 'bi-person',    'count' => null],
    ['key' => 'properties', 'label' => 'Portfolio',  'icon' => 'bi-buildings', 'count' => count($properties)],
];
?>

<div class="detail-header">
    <div class="detail-header__media detail-header__media--avatar">
        <img src="<?= sanitize(getAvatarUrl($o['avatar'] ?? null)) ?>" alt="" width="144" height="144">
    </div>

    <div class="detail-header__body">
        <div class="detail-header__eyebrow">Property owner</div>
        <h2 class="detail-header__title"><?= sanitize($o['full_name']) ?></h2>

        <div class="detail-header__meta">
            <?php if (!empty($o['phone'])): ?>
                <span><i class="bi bi-telephone" aria-hidden="true"></i> <?= sanitize($o['phone']) ?></span>
            <?php endif ?>
            <?php if (!empty($o['email'])): ?>
                <span><i class="bi bi-envelope" aria-hidden="true"></i> <?= sanitize($o['email']) ?></span>
            <?php endif ?>
            <span>
                <i class="bi bi-key" aria-hidden="true"></i>
                <?= !$hasAccount ? 'No portal account' : ($o['account_active'] ? 'Portal enabled' : 'Portal disabled') ?>
            </span>
        </div>

        <div class="detail-stats">
            <div class="detail-stat">
                <div class="detail-stat__label">Properties</div>
                <div class="detail-stat__value"><?= count($properties) ?></div>
            </div>
            <div class="detail-stat">
                <div class="detail-stat__label">Currently let</div>
                <div class="detail-stat__value"><?= $letCount ?></div>
            </div>
            <div class="detail-stat">
                <div class="detail-stat__label">Commission</div>
                <div class="detail-stat__value"><?= number_format((float) $o['commission_rate'], 1) ?>%</div>
            </div>
            <div class="detail-stat">
                <div class="detail-stat__label">Income to date</div>
                <div class="detail-stat__value"><?= formatCurrency((float) ($totalIncome ?? 0)) ?></div>
            </div>
        </div>
    </div>

    <div class="detail-header__actions">
        <?php if ($canEdit): ?>
            <a href="<?= $editUrl ?>" class="btn btn--primary btn--sm">
                <i class="bi bi-pencil" aria-hidden="true"></i> Edit
            </a>
        <?php endif ?>
        <?= uiRowActions(array_merge(
            !$hasAccount ? [[
                'label' => 'Enable login access', 'icon' => 'bi-key', 'can' => 'owners.enable-login',
                'url' => '#', 'attrs' => ['data-modal-open' => 'ownerLoginModal'],
            ]] : [],
            $hasAccount && $o['account_active'] ? [[
                'label' => 'Disable login access', 'icon' => 'bi-lock', 'can' => 'owners.disable-login',
                'method' => 'post', 'url' => $base . '&action=disable-login&id=' . (int) $o['id'],
                'confirm' => [
                    'title' => 'Disable login for this owner?', 'action' => 'Disable access',
                    'record' => $o['full_name'], 'tone' => 'warning',
                    'body' => 'They can no longer sign in to the portal. Their profile, properties and payment history are kept.',
                ],
            ]] : [],
            $hasAccount && !$o['account_active'] ? [[
                'label' => 'Re-enable login access', 'icon' => 'bi-unlock', 'can' => 'owners.enable-login',
                'method' => 'post', 'url' => $base . '&action=enable-login&id=' . (int) $o['id'],
            ]] : []
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
                <div class="card__header"><h2 class="card__title">Contact</h2></div>
                <div class="card__body">
                    <dl class="datalist">
                        <div class="datalist__row"><dt>Phone</dt><dd><?= sanitize($o['phone'] ?: '—') ?></dd></div>
                        <div class="datalist__row"><dt>Email</dt><dd><?= sanitize($o['email'] ?: '—') ?></dd></div>
                        <div class="datalist__row"><dt>National ID</dt><dd><?= sanitize($o['national_id'] ?: '—') ?></dd></div>
                        <div class="datalist__row"><dt>Address</dt><dd><?= sanitize($o['address'] ?: '—') ?></dd></div>
                        <div class="datalist__row"><dt>Owner since</dt><dd class="num"><?= formatDate($o['created_at']) ?></dd></div>
                    </dl>
                </div>
            </div>

            <div class="card">
                <div class="card__header"><h2 class="card__title">Payout details</h2></div>
                <div class="card__body">
                    <dl class="datalist">
                        <div class="datalist__row"><dt>Bank</dt><dd><?= sanitize($o['bank_name'] ?: '—') ?></dd></div>
                        <div class="datalist__row"><dt>Account</dt>
                            <dd><?= $o['bank_account'] ? '<code>' . sanitize($o['bank_account']) . '</code>' : '—' ?></dd>
                        </div>
                        <div class="datalist__row"><dt>Commission</dt>
                            <dd class="num"><?= number_format((float) $o['commission_rate'], 1) ?>%</dd>
                        </div>
                        <div class="datalist__row"><dt>Income to date</dt>
                            <dd class="num"><strong><?= formatCurrency((float) ($totalIncome ?? 0)) ?></strong></dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>

        <aside class="detail-cols__side">
            <div class="card">
                <div class="card__header"><h2 class="card__title">Portal account</h2></div>
                <div class="card__body">
                    <dl class="datalist">
                        <div class="datalist__row"><dt>Access</dt>
                            <dd>
                                <?php if (!$hasAccount): ?>
                                    <?= uiStatus('inactive', 'No account') ?>
                                <?php else: ?>
                                    <?= uiStatus($o['account_active'] ? 'active' : 'pending',
                                                 $o['account_active'] ? 'Enabled' : 'Disabled') ?>
                                <?php endif ?>
                            </dd>
                        </div>
                        <?php if ($hasAccount): ?>
                            <div class="datalist__row"><dt>Signs in with</dt><dd><?= sanitize($o['account_email']) ?></dd></div>
                            <div class="datalist__row"><dt>Username</dt><dd><code><?= sanitize($o['account_username']) ?></code></dd></div>
                            <div class="datalist__row"><dt>Role</dt><dd><?= uiStatus('new', $o['account_role_display'] ?? 'Owner') ?></dd></div>
                            <div class="datalist__row"><dt>Last login</dt>
                                <dd class="num"><?= $o['account_last_login'] ? formatDateTime($o['account_last_login']) : 'Never' ?></dd>
                            </div>
                            <?php if ($canManage): ?>
                                <div class="datalist__row"><dt>User record</dt>
                                    <dd><a href="<?= APP_URL ?>/index.php?page=users&amp;action=edit&amp;id=<?= (int) $o['account_id'] ?>">#<?= (int) $o['account_id'] ?> in Users &amp; Roles</a></dd>
                                </div>
                            <?php endif ?>
                        <?php endif ?>
                    </dl>

                    <?php if ($canManage && !$hasAccount): ?>
                        <p class="form-hint">
                            This owner is a business record only. Giving them access creates one account
                            with the role <strong>Owner</strong> — or adopts the existing account that
                            already carries their email.
                        </p>
                        <button type="button" class="btn btn--primary btn--sm" data-modal-open="ownerLoginModal">
                            <i class="bi bi-key" aria-hidden="true"></i> Enable login access
                        </button>
                    <?php endif ?>
                </div>
            </div>
        </aside>
    </div>
</div>

<!-- ── Portfolio ────────────────────────────────────────────────── -->
<div class="tab-panel" data-panel="properties">
    <div class="table-card">
        <?php if (empty($properties)): ?>
            <?= uiEmptyState([
                'icon'  => 'bi-buildings',
                'title' => 'No properties linked',
                'desc'  => 'Nothing in the register is assigned to this owner yet.',
                'actions' => [[
                    'label' => 'Add Property', 'icon' => 'bi-plus-lg', 'can' => 'properties.create',
                    'url'   => APP_URL . '/index.php?page=properties&action=create',
                ]],
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <th class="col-lo">Code</th><th>Property</th><th class="col-mid">Type</th>
                        <th class="cell-num">Price</th><th>Status</th>
                        <th class="cell-actions"><span class="sr-only">Actions</span></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($properties as $p): ?>
                            <?php $price = propertyPrice($p); ?>
                            <tr>
                                <td class="col-lo"><span class="table__id"><?= sanitize($p['property_code']) ?></span></td>
                                <td>
                                    <a href="<?= APP_URL ?>/index.php?page=properties&amp;action=show&amp;id=<?= (int) $p['id'] ?>" class="cell-strong">
                                        <?= sanitize($p['title']) ?>
                                    </a>
                                    <?php if (!empty($p['location'])): ?>
                                        <div class="person__meta">
                                            <i class="bi bi-geo-alt" aria-hidden="true"></i> <?= sanitize($p['location']) ?>
                                        </div>
                                    <?php endif ?>
                                </td>
                                <td class="col-mid"><?= sanitize(uiLabel((string) $p['category'])) ?></td>
                                <td class="cell-num">
                                    <?php if ($price['amount'] > 0): ?>
                                        <?= formatCurrency($price['amount']) ?><?php if ($price['isRental']): ?><span class="price-per">/mo</span><?php endif ?>
                                    <?php else: ?>
                                        <span class="text-subtle">—</span>
                                    <?php endif ?>
                                </td>
                                <td><?= uiStatus($p['status']) ?></td>
                                <td class="cell-actions">
                                    <?= uiRowActions([[
                                        'label' => 'View property', 'icon' => 'bi-eye', 'can' => 'properties.show',
                                        'url' => APP_URL . '/index.php?page=properties&action=show&id=' . (int) $p['id'],
                                    ]]) ?>
                                </td>
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
