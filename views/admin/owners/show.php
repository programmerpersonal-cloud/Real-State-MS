<?php
$o = $owner;
?>
<div class="mini-stats">
    <div class="mini-stat"><div class="mini-stat__label">Properties</div><div class="mini-stat__value"><?= count($properties) ?></div></div>
    <div class="mini-stat"><div class="mini-stat__label">Commission Rate</div><div class="mini-stat__value"><?= $o['commission_rate'] ?>%</div></div>
    <div class="mini-stat"><div class="mini-stat__label">Total Income</div><div class="mini-stat__value"><?= formatCurrency((float)($totalIncome ?? 0)) ?></div></div>
</div>
<div style="display:flex;gap:8px;margin-bottom:14px">
    <a href="<?= APP_URL ?>/index.php?page=owners&action=edit&id=<?= $o['id'] ?>" class="btn btn--outline btn--sm"><i class="bi bi-pencil"></i> Edit</a>
</div>
<div style="display:grid;grid-template-columns:1fr 2fr;gap:20px">
    <div class="card">
        <div class="card__header"><h3 class="card__title">Owner Details</h3></div>
        <div class="card__body">
            <table style="width:100%;font-size:.875rem">
                <tr><td class="text-muted" style="padding:8px 0">Phone</td><td><?= sanitize($o['phone']) ?></td></tr>
                <tr><td class="text-muted" style="padding:8px 0">Email</td><td><?= sanitize($o['email'] ?: '—') ?></td></tr>
                <tr><td class="text-muted" style="padding:8px 0">National ID</td><td><?= sanitize($o['national_id'] ?: '—') ?></td></tr>
                <tr><td class="text-muted" style="padding:8px 0">Address</td><td><?= sanitize($o['address'] ?: '—') ?></td></tr>
                <tr><td class="text-muted" style="padding:8px 0">Bank</td><td><?= sanitize($o['bank_name'] ?: '—') ?></td></tr>
                <tr><td class="text-muted" style="padding:8px 0">Account</td><td><?= sanitize($o['bank_account'] ?: '—') ?></td></tr>
                <tr><td class="text-muted" style="padding:8px 0">Commission</td><td><?= $o['commission_rate'] ?>%</td></tr>
                <tr><td class="text-muted" style="padding:8px 0">Since</td><td><?= formatDate($o['created_at']) ?></td></tr>
            </table>
        </div>
    </div>
    <div class="card">
        <div class="card__header"><h3 class="card__title">Owned Properties (<?= count($properties) ?>)</h3></div>
        <div class="card__body" style="padding:0">
            <?php if (empty($properties)): ?>
                <div class="empty-state"><div class="empty-state__desc">No properties linked to this owner.</div></div>
            <?php else: ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Code</th><th>Title</th><th>Type</th><th>Rent/Price</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($properties as $p): ?>
                <tr>
                    <td><?= sanitize($p['property_code']) ?></td>
                    <td><a href="<?= APP_URL ?>/index.php?page=properties&action=show&id=<?= $p['id'] ?>"><?= sanitize($p['title']) ?></a></td>
                    <td><?= ucfirst($p['property_type']) ?></td>
                    <td><?= $p['rent_amount'] ? formatCurrency($p['rent_amount']).'/mo' : ($p['price'] ? formatCurrency($p['price']) : '—') ?></td>
                    <td><span class="badge <?= getStatusBadgeClass($p['status']) ?>"><?= ucfirst($p['status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
    </div>
</div>
