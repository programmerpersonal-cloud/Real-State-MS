<?php
$pageTitle = sanitize($property['title']);
$breadcrumbs = [['label' => 'Properties', 'url' => APP_URL . '/index.php?page=properties'], ['label' => $property['property_code']]];
$actionButton = ['label' => 'Edit', 'icon' => 'bi-pencil', 'url' => APP_URL . '/index.php?page=properties&action=edit&id=' . $property['id']];
$p = $property;
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
