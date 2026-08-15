<?php
/**
 * Owner Portal — My Properties
 */
?>
<div class="card">
    <div class="card__header"><h3 class="card__title">Properties under my ownership</h3></div>
    <div class="card__body" style="padding:0">
        <?php if (empty($properties)): ?>
            <div class="empty-state"><div class="empty-state__icon"><i class="bi bi-buildings"></i></div><div class="empty-state__title">No properties linked yet</div></div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Code</th><th>Title</th><th>Type</th><th>Rent / Price</th><th>Status</th><th>Created</th></tr></thead>
                <tbody>
                <?php foreach ($properties as $p): ?>
                <tr>
                    <td><code><?= sanitize($p['property_code']) ?></code></td>
                    <td><strong><?= sanitize($p['title']) ?></strong></td>
                    <td><?= ucfirst($p['property_type']) ?></td>
                    <td><?= $p['rent_amount'] ? formatCurrency((float)$p['rent_amount']) . '/mo' : ($p['price'] ? formatCurrency((float)$p['price']) : '—') ?></td>
                    <td><span class="badge <?= getStatusBadgeClass($p['status']) ?>"><?= ucfirst($p['status']) ?></span></td>
                    <td><?= formatDate($p['created_at']) ?></td>
                </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php endif ?>
    </div>
</div>
