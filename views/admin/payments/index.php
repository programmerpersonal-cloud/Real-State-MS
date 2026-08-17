<?php
/**
 * Payments — Index
 */
$fd = $formData ?? [];
?>
<div class="mini-stats">
    <?php
    $statuses = ['paid' => 'success', 'pending' => 'warning', 'overdue' => 'danger', 'partial' => 'info'];
    foreach ($statuses as $s => $color):
        $row = $totals[$s] ?? ['cnt' => 0, 'total' => 0];
    ?>
    <div class="mini-stat">
        <div class="mini-stat__label" style="color:var(--<?= $color ?>)"><?= ucfirst($s) ?> (<?= $row['cnt'] ?>)</div>
        <div class="mini-stat__value"><?= formatCurrency((float)$row['total']) ?></div>
    </div>
    <?php endforeach ?>
</div>

<form method="get" class="filter-bar">
    <input type="hidden" name="page" value="payments">
    <div class="form-group">
        <label class="form-label">Search</label>
        <input class="form-control" name="search" value="<?= sanitize($filters['search'] ?? '') ?>" placeholder="Payment code, customer…">
    </div>
    <div class="form-group">
        <label class="form-label">Type</label>
        <select class="form-control" name="payment_type">
            <option value="">All</option>
            <?php foreach (['rent','sale','deposit','refund','late_fee','other'] as $t): ?>
                <option value="<?= $t ?>" <?= ($filters['payment_type'] ?? '') === $t ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$t)) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label">Status</label>
        <select class="form-control" name="status">
            <option value="">All</option>
            <?php foreach (['paid','pending','partial','overdue','cancelled'] as $s): ?>
                <option value="<?= $s ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <button class="btn btn--primary"><i class="bi bi-funnel"></i> Filter</button>
</form>

<div class="card">
    <div class="card__header"><h3 class="card__title"><?= $totalCount ?> Payment<?= $totalCount === 1 ? '' : 's' ?></h3></div>
    <div class="card__body" style="padding:0">
        <?php if (empty($payments)): ?>
            <div class="empty-state">
                <div class="empty-state__icon"><i class="bi bi-credit-card"></i></div>
                <div class="empty-state__title">No payments recorded</div>
                <button type="button" class="btn btn--primary btn--sm" data-modal-open="paymentCreateModal" style="margin-top:14px">
                    <i class="bi bi-plus-lg"></i> Record Payment
                </button>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Code</th><th>Customer</th><th>Property</th><th>Type</th><th>Amount</th><th>Method</th><th>Date</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($payments as $p): ?>
                <tr>
                    <td><code><?= sanitize($p['payment_code']) ?></code></td>
                    <td><?= sanitize($p['customer_name']) ?></td>
                    <td><?= sanitize($p['property_title'] ?? '—') ?></td>
                    <td><span class="badge badge--muted"><?= ucfirst(str_replace('_',' ',$p['payment_type'])) ?></span></td>
                    <td><strong><?= formatCurrency((float)$p['amount']) ?></strong></td>
                    <td><?= ucfirst(str_replace('_',' ',$p['payment_method'])) ?></td>
                    <td><?= formatDate($p['payment_date']) ?></td>
                    <td><span class="badge <?= getStatusBadgeClass($p['status']) ?>"><?= ucfirst($p['status']) ?></span></td>
                    <td>
                        <a class="btn btn--outline btn--sm" href="<?= APP_URL ?>/index.php?page=payments&action=receipt&id=<?= $p['id'] ?>"><i class="bi bi-receipt"></i></a>
                    </td>
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
