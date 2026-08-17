<?php
/**
 * Sales — Index
 */
$fd = $formData ?? [];
?>
<form method="get" class="filter-bar">
    <input type="hidden" name="page" value="sales">
    <div class="form-group">
        <label class="form-label">Search</label>
        <input class="form-control" name="search" value="<?= sanitize($filters['search'] ?? '') ?>" placeholder="Sale code, buyer, property…">
    </div>
    <div class="form-group">
        <label class="form-label">Status</label>
        <select class="form-control" name="status">
            <option value="">All</option>
            <?php foreach (['pending','completed','cancelled'] as $s): ?>
                <option value="<?= $s ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <button class="btn btn--primary"><i class="bi bi-funnel"></i> Filter</button>
</form>

<div class="card">
    <div class="card__header"><h3 class="card__title"><?= $totalCount ?> Sale<?= $totalCount === 1 ? '' : 's' ?></h3></div>
    <div class="card__body" style="padding:0">
        <?php if (empty($sales)): ?>
            <div class="empty-state">
                <div class="empty-state__icon"><i class="bi bi-cart-check"></i></div>
                <div class="empty-state__title">No sales yet</div>
                <button type="button" class="btn btn--primary btn--sm" data-modal-open="saleCreateModal" style="margin-top:14px">
                    <i class="bi bi-plus-lg"></i> New Sale
                </button>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Code</th><th>Property</th><th>Buyer</th><th>Amount</th><th>Commission</th><th>Agent</th><th>Date</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($sales as $s): ?>
                <tr>
                    <td><code><?= sanitize($s['sale_code']) ?></code></td>
                    <td><?= sanitize($s['property_title']) ?> <span class="text-muted" style="font-size:.75rem">(<?= sanitize($s['property_code']) ?>)</span></td>
                    <td><?= sanitize($s['customer_name']) ?></td>
                    <td><strong><?= formatCurrency((float)$s['sale_amount']) ?></strong></td>
                    <td><?= formatCurrency((float)$s['commission_amount']) ?></td>
                    <td><?= sanitize($s['agent_name'] ?? '—') ?></td>
                    <td><?= formatDate($s['sale_date']) ?></td>
                    <td><span class="badge <?= getStatusBadgeClass($s['status']) ?>"><?= ucfirst($s['status']) ?></span></td>
                    <td><a class="btn btn--outline btn--sm" href="<?= APP_URL ?>/index.php?page=sales&action=show&id=<?= $s['id'] ?>"><i class="bi bi-eye"></i></a></td>
                </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <div style="padding:0 20px"><?php require VIEWS_PATH . '/components/pagination.php' ?></div>
        <?php endif ?>
    </div>
</div>

<?php require __DIR__ . '/_create_modal.php'; ?>
