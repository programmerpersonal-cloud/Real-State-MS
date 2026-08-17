<?php
/**
 * Property — detail.
 *
 * `properties.show` is held by every signed-in role: an owner opens their own
 * listing from the portfolio, a tenant from a saved search, a technician from
 * the job they are on. Only staff may change it, so the editing affordances
 * below — the header button, the breadcrumb back to the register, the map's
 * "set location" link — are drawn from the permission rather than assumed.
 */
$pageTitle = sanitize($property['title']);
$canEdit   = can('properties.edit');

// The trail back points at whichever list this viewer actually came from:
// staff to the agency register, an owner to their own portfolio. A viewer
// with neither (a tenant arriving from a public listing) gets no crumb rather
// than a link that would refuse them.
$parentCrumb = null;
if (can('properties.view')) {
    $parentCrumb = ['label' => 'Properties', 'url' => APP_URL . '/index.php?page=properties'];
} elseif (can('my-properties.view') && ownsProperty($property)) {
    $parentCrumb = ['label' => 'My Properties', 'url' => APP_URL . '/index.php?page=my-properties'];
}

$breadcrumbs = array_values(array_filter([
    $parentCrumb,
    ['label' => $property['property_code']],
]));

$editUrl = APP_URL . '/index.php?page=properties&action=edit&id=' . $property['id'];
if ($canEdit) {
    $actionButton = ['label' => 'Edit', 'icon' => 'bi-pencil', 'url' => $editUrl];
}
$p = $property;

$mapVariant = 'admin';
$mapEditUrl = $canEdit ? $editUrl : '';
$coords     = propertyCoords($p);
$fullAddress = trim(implode(', ', array_filter([$p['address'] ?? '', $p['location'] ?? ''])));
?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px">
    <!-- Main Info -->
    <div>
        <!-- Image Gallery -->
        <?php if (!empty($images)): ?>
        <div class="card mb-3">
            <div class="card__body" style="padding:16px">
                <img src="<?= APP_URL . '/' . ($images[0]['file_path'] ?? '') ?>" style="width:100%;max-height:400px;object-fit:cover;border-radius:var(--radius-sm)">
                <?php if (count($images) > 1): ?>
                <div style="display:flex;gap:8px;margin-top:10px;overflow-x:auto">
                    <?php foreach (array_slice($images, 1) as $img): ?>
                    <img src="<?= APP_URL . '/' . $img['file_path'] ?>" style="width:100px;height:70px;object-fit:cover;border-radius:6px;border:1px solid var(--border);cursor:pointer">
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Description -->
        <div class="card mb-3">
            <div class="card__header"><h3 class="card__title">Description</h3></div>
            <div class="card__body">
                <p><?= nl2br(sanitize($p['description'] ?: 'No description provided.')) ?></p>
            </div>
        </div>

        <!-- Location -->
        <div class="card mb-3">
            <div class="card__header">
                <h3 class="card__title">Location</h3>
                <?php if ($coords): ?>
                    <span class="text-muted" style="font-size:.8rem"><?= sanitize($fullAddress) ?></span>
                <?php endif; ?>
            </div>
            <div class="card__body">
                <?php require VIEWS_PATH . '/components/property_map.php'; ?>
            </div>
        </div>

        <!-- Documents -->
        <?php require __DIR__ . '/_documents_card.php'; ?>

        <!-- History -->
        <div class="card">
            <div class="card__header"><h3 class="card__title">Property History</h3></div>
            <div class="card__body" style="padding:0">
                <?php if (empty($history)): ?>
                    <div class="empty-state"><div class="empty-state__desc">No history yet.</div></div>
                <?php else: ?>
                    <ul class="activity" style="padding:16px 24px">
                        <?php foreach ($history as $h): ?>
                        <li class="activity__item">
                            <div class="activity__dot"></div>
                            <div>
                                <div class="activity__text"><strong><?= sanitize($h['changed_by_name'] ?? 'System') ?></strong> <?= sanitize($h['action']) ?><?php if ($h['field_changed']): ?> <span class="text-muted">(<?= sanitize($h['field_changed']) ?>)</span><?php endif; ?></div>
                                <div class="activity__time"><?= formatDateTime($h['created_at']) ?></div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Sidebar Info -->
    <div>
        <div class="card mb-3">
            <div class="card__header"><h3 class="card__title">Details</h3></div>
            <div class="card__body">
                <table style="width:100%;font-size:.875rem">
                    <tr><td class="text-muted" style="padding:8px 0;width:40%">Code</td><td style="padding:8px 0"><strong><?= sanitize($p['property_code']) ?></strong></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Status</td><td style="padding:8px 0"><span class="badge <?= getStatusBadgeClass($p['status']) ?>"><?= ucfirst($p['status']) ?></span></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Type</td><td style="padding:8px 0"><?= ucfirst($p['property_type']) ?></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Category</td><td style="padding:8px 0"><?= ucfirst($p['category']) ?></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Location</td><td style="padding:8px 0"><?= sanitize($p['location']) ?></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Coordinates</td><td style="padding:8px 0"><?= $coords ? '<code>' . sanitize($coords['text']) . '</code>' : '<span class="text-subtle">Not pinned</span>' ?></td></tr>
                    <?php if ($p['size_sqm']): ?><tr><td class="text-muted" style="padding:8px 0">Size</td><td style="padding:8px 0"><?= $p['size_sqm'] ?> sqm</td></tr><?php endif; ?>
                    <tr><td class="text-muted" style="padding:8px 0">Rooms</td><td style="padding:8px 0"><?= $p['num_rooms'] ?> bed / <?= $p['num_bathrooms'] ?> bath</td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Floors</td><td style="padding:8px 0"><?= $p['num_floors'] ?></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Furnished</td><td style="padding:8px 0"><?= $p['is_furnished'] ? 'Yes' : 'No' ?></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Parking</td><td style="padding:8px 0"><?= $p['has_parking'] ? 'Yes' : 'No' ?></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Security</td><td style="padding:8px 0"><?= $p['has_security'] ? 'Yes' : 'No' ?></td></tr>
                </table>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card__header"><h3 class="card__title">Pricing</h3></div>
            <div class="card__body">
                <table style="width:100%;font-size:.875rem">
                    <?php if ($p['price']): ?><tr><td class="text-muted" style="padding:8px 0">Sale Price</td><td style="padding:8px 0"><strong><?= formatCurrency($p['price']) ?></strong></td></tr><?php endif; ?>
                    <?php if ($p['rent_amount']): ?><tr><td class="text-muted" style="padding:8px 0">Monthly Rent</td><td style="padding:8px 0"><strong><?= formatCurrency($p['rent_amount']) ?></strong></td></tr><?php endif; ?>
                    <?php if ($p['deposit_amount']): ?><tr><td class="text-muted" style="padding:8px 0">Deposit</td><td style="padding:8px 0"><?= formatCurrency($p['deposit_amount']) ?></td></tr><?php endif; ?>
                </table>
            </div>
        </div>

        <?php
        // Tenancy. The controller has always loaded this and no view ever
        // showed it — it is the first thing an owner opens the page to learn.
        //
        // Gated on the tenancy being the viewer's business: staff who work
        // leases, and the owner whose income it is. A browsing customer must
        // not see who lives here, and a technician is on the matrix for "no
        // tenancies" — the access note they need is the address, not the rent.
        $canSeeTenancy = can('leases.view') || ownsProperty($p);
        if ($canSeeTenancy && !empty($activeLease)):
            $l = $activeLease;
        ?>
        <div class="card mb-3">
            <div class="card__header">
                <h3 class="card__title">Current Tenancy</h3>
                <span class="badge badge--success">Active</span>
            </div>
            <div class="card__body">
                <table style="width:100%;font-size:.875rem">
                    <tr><td class="text-muted" style="padding:8px 0;width:40%">Tenant</td><td style="padding:8px 0"><strong><?= sanitize($l['customer_name']) ?></strong></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Lease</td><td style="padding:8px 0"><code><?= sanitize($l['lease_code']) ?></code></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Term</td><td style="padding:8px 0"><?= formatDate($l['start_date']) ?> — <?= formatDate($l['end_date']) ?></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Rent</td><td style="padding:8px 0"><strong><?= formatCurrency((float) $l['rent_amount']) ?></strong> <span class="text-muted"><?= sanitize($l['payment_schedule'] ?? '') ?></span></td></tr>
                    <?php if (!empty($l['deposit_amount'])): ?>
                        <tr><td class="text-muted" style="padding:8px 0">Deposit</td><td style="padding:8px 0"><?= formatCurrency((float) $l['deposit_amount']) ?></td></tr>
                    <?php endif ?>
                </table>
            </div>
            <?php if (can('leases.show')): ?>
                <div class="card__footer">
                    <a class="btn btn--outline btn--sm" href="<?= APP_URL ?>/index.php?page=leases&amp;action=show&amp;id=<?= (int) $l['id'] ?>">
                        Open lease <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            <?php endif ?>
        </div>
        <?php elseif ($canSeeTenancy): ?>
        <div class="card mb-3">
            <div class="card__header"><h3 class="card__title">Current Tenancy</h3></div>
            <div class="card__body">
                <p class="text-muted" style="font-size:.85rem;margin:0">
                    <i class="bi bi-info-circle"></i> No active lease on this property.
                </p>
            </div>
        </div>
        <?php endif ?>

        <div class="card">
            <div class="card__header"><h3 class="card__title">Assigned</h3></div>
            <div class="card__body">
                <table style="width:100%;font-size:.875rem">
                    <tr><td class="text-muted" style="padding:8px 0">Owner</td><td style="padding:8px 0"><?= sanitize($p['owner_name'] ?? '—') ?></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Agent</td><td style="padding:8px 0"><?= sanitize($p['agent_name'] ?? '—') ?></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Branch</td><td style="padding:8px 0"><?= sanitize($p['branch_name'] ?? '—') ?></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Created</td><td style="padding:8px 0"><?= formatDate($p['created_at']) ?></td></tr>
                </table>
            </div>
        </div>
    </div>
</div>
