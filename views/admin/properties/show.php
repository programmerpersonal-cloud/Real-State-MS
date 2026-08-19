<?php
/**
 * Property — detail.
 *
 * A record header stating what this property is and what can be done to it,
 * then the rest behind tabs. Tabs rather than one long column because the
 * page answers several unrelated questions — what is it, who rents it, what
 * has been paid, what is broken — and stacking them meant scrolling past
 * four sections to reach the fifth.
 *
 * `properties.show` is held by every signed-in role: an owner opens their own
 * listing from the portfolio, a tenant from a saved search, a technician from
 * the job they are on. Only staff may change it, so the editing affordances
 * below are drawn from the permission rather than assumed — and every tab is
 * drawn only when the controller actually loaded its data, which it does only
 * behind the matching permission.
 *
 * Vars from PropertyController::show().
 */
$p       = $property;
$canEdit = can('properties.edit');
$coords  = propertyCoords($p);
$price   = propertyPrice($p);

$pageTitle = sanitize($p['title']);

// The trail back points at whichever list this viewer actually came from:
// staff to the agency register, an owner to their own portfolio. A viewer
// with neither (a tenant arriving from a public listing) gets no crumb rather
// than a link that would refuse them.
$parentCrumb = null;
if (can('properties.view')) {
    $parentCrumb = ['label' => 'Properties', 'url' => APP_URL . '/index.php?page=properties'];
} elseif (can('my-properties.view') && ownsProperty($p)) {
    $parentCrumb = ['label' => 'My Properties', 'url' => APP_URL . '/index.php?page=my-properties'];
}
$breadcrumbs = array_values(array_filter([$parentCrumb, ['label' => $p['property_code']]]));

$base    = APP_URL . '/index.php?page=properties';
$editUrl = $base . '&action=edit&id=' . (int) $p['id'];

// The page header carries no buttons: the record header below owns the
// actions, and two rows of controls saying the same thing is one row too many.
$actionButton = null;

$mapVariant  = 'admin';
$mapEditUrl  = $canEdit ? $editUrl : '';
$fullAddress = trim(implode(', ', array_filter([$p['address'] ?? '', $p['location'] ?? ''])));

// Tenancy is the owner's income and the agency's business, not a browsing
// customer's — and a technician is on the matrix for the address, not the rent.
$canSeeTenancy = can('leases.view') || ownsProperty($p);

$cover = $images[0]['file_path'] ?? null;

/* Tabs are declared as data so the strip and the panels cannot disagree about
   which of them exist. A tab whose data the controller did not load — because
   the permission was not held — is simply absent. */
$tabs = array_values(array_filter([
    ['key' => 'overview',     'label' => 'Overview',     'icon' => 'bi-info-circle',      'count' => null],
    ['key' => 'documents',    'label' => 'Documents',    'icon' => 'bi-folder2-open',     'count' => count($documents ?? [])],
    ['key' => 'maintenance',  'label' => 'Maintenance',  'icon' => 'bi-wrench-adjustable','count' => count($maintenance ?? []),
     'when' => can('maintenance.view')],
    ['key' => 'reservations', 'label' => 'Reservations', 'icon' => 'bi-calendar-check',   'count' => count($reservations ?? []),
     'when' => can('reservations.view')],
    ['key' => 'payments',     'label' => 'Payments',     'icon' => 'bi-credit-card',      'count' => count($payments ?? []),
     'when' => can('payments.view') || ownsProperty($p)],
    ['key' => 'activity',     'label' => 'Activity',     'icon' => 'bi-clock-history',    'count' => count($history ?? [])],
], static fn(array $t): bool => $t['when'] ?? true));
?>

<!-- ── Record header ────────────────────────────────────────────── -->
<div class="detail-header">
    <div class="detail-header__media">
        <img src="<?= sanitize(propertyImage($p, $cover ? ['path' => $cover] : null)) ?>"
             alt="<?= sanitize($p['title']) ?>" width="264" height="192">
    </div>

    <div class="detail-header__body">
        <div class="detail-header__eyebrow"><?= sanitize($p['property_code']) ?></div>
        <h2 class="detail-header__title"><?= sanitize($p['title']) ?></h2>

        <div class="detail-header__meta">
            <span><?= uiStatus($p['status']) ?></span>
            <?php if (($p['approval_status'] ?? '') !== 'approved'): ?>
                <span><?= uiStatus($p['approval_status'], uiLabel($p['approval_status']) . ' approval') ?></span>
            <?php endif ?>
            <span><i class="bi bi-geo-alt" aria-hidden="true"></i> <?= sanitize($p['location'] ?: 'Location not set') ?></span>
            <span><i class="bi <?= categoryIcon($p['category']) ?>" aria-hidden="true"></i> <?= sanitize(uiLabel($p['category'])) ?></span>
            <span><i class="bi bi-tag" aria-hidden="true"></i> <?= sanitize($price['label']) ?></span>
        </div>

        <div class="detail-stats">
            <div class="detail-stat">
                <div class="detail-stat__label"><?= $price['isRental'] ? 'Monthly rent' : 'Sale price' ?></div>
                <div class="detail-stat__value">
                    <?= $price['amount'] > 0 ? formatCurrency($price['amount']) : '—' ?>
                </div>
            </div>
            <?php if (!empty($p['deposit_amount'])): ?>
                <div class="detail-stat">
                    <div class="detail-stat__label">Deposit</div>
                    <div class="detail-stat__value"><?= formatCurrency((float) $p['deposit_amount']) ?></div>
                </div>
            <?php endif ?>
            <?php if (!empty($p['size_sqm'])): ?>
                <div class="detail-stat">
                    <div class="detail-stat__label">Size</div>
                    <div class="detail-stat__value"><?= (int) $p['size_sqm'] ?> m²</div>
                </div>
            <?php endif ?>
            <div class="detail-stat">
                <div class="detail-stat__label">Rooms</div>
                <div class="detail-stat__value"><?= (int) $p['num_rooms'] ?> / <?= (int) $p['num_bathrooms'] ?></div>
            </div>
        </div>
    </div>

    <div class="detail-header__actions">
        <?php if ($canEdit): ?>
            <a href="<?= $editUrl ?>" class="btn btn--primary btn--sm">
                <i class="bi bi-pencil" aria-hidden="true"></i> Edit
            </a>
        <?php endif ?>
        <?= uiRowActions([
            ['label' => 'Approve listing', 'icon' => 'bi-check2-circle', 'can' => 'properties.approve',
             'url'     => $base . '&action=approve&id=' . (int) $p['id'],
             'confirm' => [
                 'title' => 'Approve this listing?', 'tone' => 'info', 'action' => 'Approve',
                 'body'  => 'It becomes visible on the public site and can be reserved, let or sold.',
                 'record' => $p['property_code'] . ' · ' . $p['title'],
             ]],
            ['label' => 'View public listing', 'icon' => 'bi-box-arrow-up-right',
             'url' => APP_URL . '/index.php?page=listing&id=' . (int) $p['id']],
            ['label' => 'Archive', 'icon' => 'bi-archive', 'can' => 'properties.archive', 'danger' => true,
             'url'     => $base . '&action=archive&id=' . (int) $p['id'],
             'confirm' => [
                 'title'  => 'Archive this property?',
                 'body'   => 'It is removed from the register and from public listings. Leases, payments and documents already recorded against it are kept.',
                 'action' => 'Archive property',
                 'record' => $p['property_code'] . ' · ' . $p['title'],
             ]],
        ], 'More actions') ?>
    </div>
</div>

<!-- ── Tabs ─────────────────────────────────────────────────────── -->
<div class="tabs" data-tabs role="tablist">
    <?php foreach ($tabs as $i => $t): ?>
        <button type="button" class="tabs__item<?= $i === 0 ? ' is-active' : '' ?>"
                data-tab="<?= $t['key'] ?>" role="tab"
                aria-selected="<?= $i === 0 ? 'true' : 'false' ?>">
            <i class="bi <?= $t['icon'] ?>" aria-hidden="true"></i>
            <?= sanitize($t['label']) ?>
            <?php if ($t['count'] !== null): ?>
                <span class="tabs__count"><?= (int) $t['count'] ?></span>
            <?php endif ?>
        </button>
    <?php endforeach ?>
</div>

<!-- ── Overview ─────────────────────────────────────────────────── -->
<div class="tab-panel is-active" data-panel="overview">
    <div class="detail-cols">
        <div class="detail-cols__main">
            <?php if (count($images) > 1): ?>
                <div class="card mb-3">
                    <div class="card__header"><h2 class="card__title">Gallery</h2>
                        <span class="text-subtle"><?= count($images) ?> photos</span>
                    </div>
                    <div class="card__body">
                        <div class="gallery">
                            <?php foreach ($images as $img): ?>
                                <a class="gallery__item" href="<?= APP_URL . '/' . sanitize($img['file_path']) ?>"
                                   target="_blank" rel="noopener">
                                    <img src="<?= APP_URL . '/' . sanitize($img['file_path']) ?>"
                                         alt="" loading="lazy" width="180" height="120">
                                </a>
                            <?php endforeach ?>
                        </div>
                    </div>
                </div>
            <?php endif ?>

            <div class="card mb-3">
                <div class="card__header"><h2 class="card__title">Description</h2></div>
                <div class="card__body">
                    <?php if (trim((string) $p['description']) !== ''): ?>
                        <p class="prose"><?= nl2br(sanitize($p['description'])) ?></p>
                    <?php else: ?>
                        <p class="text-subtle">No description has been written for this property yet.</p>
                    <?php endif ?>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card__header">
                    <h2 class="card__title">Location</h2>
                    <?php if ($fullAddress !== ''): ?>
                        <span class="text-subtle"><?= sanitize($fullAddress) ?></span>
                    <?php endif ?>
                </div>
                <div class="card__body">
                    <?php require VIEWS_PATH . '/components/property_map.php'; ?>
                </div>
            </div>
        </div>

        <aside class="detail-cols__side">
            <div class="card mb-3">
                <div class="card__header"><h2 class="card__title">Specification</h2></div>
                <div class="card__body">
                    <dl class="datalist">
                        <div class="datalist__row"><dt>Code</dt><dd><strong><?= sanitize($p['property_code']) ?></strong></dd></div>
                        <div class="datalist__row"><dt>Status</dt><dd><?= uiStatus($p['status']) ?></dd></div>
                        <div class="datalist__row"><dt>Listing</dt><dd><?= sanitize(uiLabel($p['property_type'])) ?></dd></div>
                        <div class="datalist__row"><dt>Type</dt><dd><?= sanitize(uiLabel($p['category'])) ?></dd></div>
                        <div class="datalist__row"><dt>Location</dt><dd><?= sanitize($p['location'] ?: '—') ?></dd></div>
                        <div class="datalist__row"><dt>Coordinates</dt>
                            <dd><?= $coords ? '<code>' . sanitize($coords['text']) . '</code>' : '<span class="text-subtle">Not pinned</span>' ?></dd>
                        </div>
                        <?php if (!empty($p['size_sqm'])): ?>
                            <div class="datalist__row"><dt>Size</dt><dd class="num"><?= (int) $p['size_sqm'] ?> m²</dd></div>
                        <?php endif ?>
                        <div class="datalist__row"><dt>Rooms</dt>
                            <dd class="num"><?= (int) $p['num_rooms'] ?> bed · <?= (int) $p['num_bathrooms'] ?> bath · <?= (int) $p['num_floors'] ?> floor<?= (int) $p['num_floors'] === 1 ? '' : 's' ?></dd>
                        </div>
                        <div class="datalist__row"><dt>Features</dt>
                            <dd>
                                <?php
                                $features = array_filter([
                                    $p['is_furnished'] ? 'Furnished' : null,
                                    $p['has_parking']  ? 'Parking'   : null,
                                    $p['has_security'] ? 'Security'  : null,
                                ]);
                                ?>
                                <?= $features ? sanitize(implode(' · ', $features)) : '<span class="text-subtle">None recorded</span>' ?>
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card__header"><h2 class="card__title">Pricing</h2></div>
                <div class="card__body">
                    <dl class="datalist">
                        <?php if (!empty($p['price'])): ?>
                            <div class="datalist__row"><dt>Sale price</dt><dd class="num"><strong><?= formatCurrency((float) $p['price']) ?></strong></dd></div>
                        <?php endif ?>
                        <?php if (!empty($p['rent_amount'])): ?>
                            <div class="datalist__row"><dt>Monthly rent</dt><dd class="num"><strong><?= formatCurrency((float) $p['rent_amount']) ?></strong></dd></div>
                        <?php endif ?>
                        <?php if (!empty($p['deposit_amount'])): ?>
                            <div class="datalist__row"><dt>Deposit</dt><dd class="num"><?= formatCurrency((float) $p['deposit_amount']) ?></dd></div>
                        <?php endif ?>
                        <?php if (empty($p['price']) && empty($p['rent_amount'])): ?>
                            <div class="datalist__row"><dt>Price</dt><dd class="text-subtle">Not set</dd></div>
                        <?php endif ?>
                    </dl>
                </div>
            </div>

            <?php if ($canSeeTenancy): ?>
                <div class="card mb-3">
                    <div class="card__header">
                        <h2 class="card__title">Current tenancy</h2>
                        <?php if (!empty($activeLease)): ?><?= uiStatus('active') ?><?php endif ?>
                    </div>
                    <div class="card__body">
                        <?php if (!empty($activeLease)): $l = $activeLease; ?>
                            <dl class="datalist">
                                <div class="datalist__row"><dt>Tenant</dt><dd><strong><?= sanitize($l['customer_name']) ?></strong></dd></div>
                                <div class="datalist__row"><dt>Lease</dt><dd><code><?= sanitize($l['lease_code']) ?></code></dd></div>
                                <div class="datalist__row"><dt>Term</dt><dd class="num"><?= formatDate($l['start_date']) ?> — <?= formatDate($l['end_date']) ?></dd></div>
                                <div class="datalist__row"><dt>Rent</dt><dd class="num"><strong><?= formatCurrency((float) $l['rent_amount']) ?></strong> <span class="text-muted"><?= sanitize($l['payment_schedule'] ?? '') ?></span></dd></div>
                                <?php if (!empty($l['deposit_amount'])): ?>
                                    <div class="datalist__row"><dt>Deposit</dt><dd class="num"><?= formatCurrency((float) $l['deposit_amount']) ?></dd></div>
                                <?php endif ?>
                            </dl>
                        <?php else: ?>
                            <p class="text-subtle">No active lease on this property.</p>
                        <?php endif ?>
                    </div>
                    <?php if (!empty($activeLease) && can('leases.show')): ?>
                        <div class="card__footer">
                            <a class="btn btn--outline btn--sm" href="<?= APP_URL ?>/index.php?page=leases&amp;action=show&amp;id=<?= (int) $activeLease['id'] ?>">
                                Open lease <i class="bi bi-arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                    <?php endif ?>
                </div>
            <?php endif ?>

            <div class="card">
                <div class="card__header"><h2 class="card__title">Assigned</h2></div>
                <div class="card__body">
                    <dl class="datalist">
                        <div class="datalist__row"><dt>Owner</dt><dd><?= sanitize($p['owner_name'] ?? '—') ?></dd></div>
                        <div class="datalist__row"><dt>Agent</dt><dd><?= sanitize($p['agent_name'] ?? '—') ?></dd></div>
                        <div class="datalist__row"><dt>Branch</dt><dd><?= sanitize($p['branch_name'] ?? '—') ?></dd></div>
                        <div class="datalist__row"><dt>Created</dt><dd class="num"><?= formatDate($p['created_at']) ?></dd></div>
                        <div class="datalist__row"><dt>Updated</dt><dd class="num"><?= formatDate($p['updated_at'] ?? $p['created_at']) ?></dd></div>
                    </dl>
                </div>
            </div>
        </aside>
    </div>
</div>

<!-- ── Documents ────────────────────────────────────────────────── -->
<div class="tab-panel" data-panel="documents">
    <?php require __DIR__ . '/_documents_card.php'; ?>
</div>

<!-- ── Maintenance ──────────────────────────────────────────────── -->
<?php if (can('maintenance.view')): ?>
<div class="tab-panel" data-panel="maintenance">
    <div class="table-card">
        <?php if (empty($maintenance)): ?>
            <?= uiEmptyState([
                'icon'  => 'bi-wrench-adjustable',
                'title' => 'No maintenance requests',
                'desc'  => 'Nothing has been reported against this property.',
                'actions' => [[
                    'label' => 'Report an issue', 'icon' => 'bi-plus-lg', 'can' => 'maintenance.create',
                    'url'   => APP_URL . '/index.php?page=maintenance&action=create',
                ]],
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <th>Code</th><th>Issue</th><th>Priority</th><th class="col-lo">Assigned</th>
                        <th>Status</th><th class="col-mid">Reported</th><th class="cell-actions"><span class="sr-only">Actions</span></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($maintenance as $m): ?>
                            <tr>
                                <td><span class="table__id"><?= sanitize($m['request_code']) ?></span></td>
                                <td class="cell-clip"><?= sanitize(truncate((string) $m['description'], 60)) ?></td>
                                <td><?= uiStatus($m['priority']) ?></td>
                                <td class="col-lo"><?= sanitize($m['assigned_name'] ?: '—') ?></td>
                                <td><?= uiStatus($m['status']) ?></td>
                                <td class="cell-date col-mid"><?= formatDate($m['created_at']) ?></td>
                                <td class="cell-actions">
                                    <?= uiRowActions([[
                                        'label' => 'Open request', 'icon' => 'bi-eye', 'can' => 'maintenance.show',
                                        'url'   => APP_URL . '/index.php?page=maintenance&action=show&id=' . (int) $m['id'],
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
<?php endif ?>

<!-- ── Reservations ─────────────────────────────────────────────── -->
<?php if (can('reservations.view')): ?>
<div class="tab-panel" data-panel="reservations">
    <div class="table-card">
        <?php if (empty($reservations)): ?>
            <?= uiEmptyState([
                'icon'  => 'bi-calendar-check',
                'title' => 'No reservations',
                'desc'  => 'This property has not been reserved.',
                'actions' => [[
                    'label' => 'New reservation', 'icon' => 'bi-plus-lg', 'can' => 'reservations.create',
                    'url'   => APP_URL . '/index.php?page=reservations&action=create',
                ]],
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <th>Code</th><th>Customer</th><th class="col-lo">Reserved</th><th>Expires</th>
                        <th class="cell-num col-mid">Deposit</th><th>Status</th>
                        <th class="cell-actions"><span class="sr-only">Actions</span></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($reservations as $r): ?>
                            <tr>
                                <td><span class="table__id"><?= sanitize($r['reservation_code']) ?></span></td>
                                <td><?= sanitize($r['customer_name']) ?></td>
                                <td class="cell-date col-lo"><?= formatDate($r['reservation_date']) ?></td>
                                <td class="cell-date"><?= formatDate($r['expiry_date'] ?? null) ?></td>
                                <td class="cell-num col-mid"><?= !empty($r['deposit_amount']) ? formatCurrency((float) $r['deposit_amount']) : '—' ?></td>
                                <td><?= uiStatus($r['status']) ?></td>
                                <td class="cell-actions">
                                    <?= uiRowActions([[
                                        'label' => 'Open reservations', 'icon' => 'bi-eye', 'can' => 'reservations.view',
                                        'url'   => APP_URL . '/index.php?page=reservations',
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
<?php endif ?>

<!-- ── Payments ─────────────────────────────────────────────────── -->
<?php if (can('payments.view') || ownsProperty($p)): ?>
<div class="tab-panel" data-panel="payments">
    <div class="table-card">
        <?php if (empty($payments)): ?>
            <?= uiEmptyState([
                'icon'  => 'bi-credit-card',
                'title' => 'No payments recorded',
                'desc'  => 'Nothing has been paid against this property yet.',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <th>Code</th><th>Customer</th><th class="col-lo">Type</th>
                        <th class="cell-num">Amount</th><th class="col-mid">Due</th><th>Status</th>
                        <th class="cell-actions"><span class="sr-only">Actions</span></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($payments as $pay): ?>
                            <tr>
                                <td><span class="table__id"><?= sanitize($pay['payment_code']) ?></span></td>
                                <td><?= sanitize($pay['customer_name']) ?></td>
                                <td class="col-lo"><?= sanitize(uiLabel((string) $pay['payment_type'])) ?></td>
                                <td class="cell-num"><strong><?= formatCurrency((float) $pay['amount']) ?></strong></td>
                                <td class="cell-date col-mid"><?= formatDate($pay['due_date'] ?? null) ?></td>
                                <td><?= uiStatus($pay['status']) ?></td>
                                <td class="cell-actions">
                                    <?= uiRowActions([[
                                        'label' => 'Open payments', 'icon' => 'bi-eye', 'can' => 'payments.view',
                                        'url'   => APP_URL . '/index.php?page=payments',
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
<?php endif ?>

<!-- ── Activity ─────────────────────────────────────────────────── -->
<div class="tab-panel" data-panel="activity">
    <div class="card">
        <div class="card__header"><h2 class="card__title">Property history</h2></div>
        <div class="card__body">
            <?php if (empty($history)): ?>
                <?= uiEmptyState([
                    'icon'  => 'bi-clock-history',
                    'title' => 'No history yet',
                    'desc'  => 'Changes made to this property will be listed here.',
                ]) ?>
            <?php else: ?>
                <ul class="activity">
                    <?php foreach ($history as $h): ?>
                        <li class="activity__item">
                            <div class="activity__dot"></div>
                            <div>
                                <div class="activity__text">
                                    <strong><?= sanitize($h['changed_by_name'] ?? 'System') ?></strong>
                                    <?= sanitize($h['action']) ?>
                                    <?php if (!empty($h['field_changed'])): ?>
                                        <span class="activity__tag"><?= sanitize($h['field_changed']) ?></span>
                                    <?php endif ?>
                                </div>
                                <div class="activity__time"><?= formatDateTime($h['created_at']) ?></div>
                            </div>
                        </li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        </div>
    </div>
</div>
