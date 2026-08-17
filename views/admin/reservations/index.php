<?php
/**
 * Reservations — Index
 */
$fd = $formData ?? [];
?>
<form method="get" class="filter-bar">
    <input type="hidden" name="page" value="reservations">
    <div class="form-group">
        <label class="form-label">Search</label>
        <input class="form-control" name="search" value="<?= sanitize($filters['search'] ?? '') ?>" placeholder="Code, customer, property…">
    </div>
    <div class="form-group">
        <label class="form-label">Status</label>
        <select class="form-control" name="status">
            <option value="">All</option>
            <?php foreach (['active','confirmed','expired','cancelled'] as $s): ?>
                <option value="<?= $s ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <button class="btn btn--primary"><i class="bi bi-funnel"></i> Filter</button>
</form>

<div class="card">
    <div class="card__header"><h3 class="card__title"><?= $totalCount ?> Reservation<?= $totalCount === 1 ? '' : 's' ?></h3></div>
    <div class="card__body" style="padding:0">
        <?php if (empty($reservations)): ?>
            <div class="empty-state">
                <div class="empty-state__icon"><i class="bi bi-calendar-check"></i></div>
                <div class="empty-state__title">No reservations yet</div>
                <button type="button" class="btn btn--primary btn--sm" data-modal-open="reservationCreateModal" style="margin-top:14px">
                    <i class="bi bi-plus-lg"></i> New Reservation
                </button>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Code</th><th>Property</th><th>Customer</th><th>Reserved</th><th>Expires</th><th>Deposit</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($reservations as $r): ?>
                <tr>
                    <td><code><?= sanitize($r['reservation_code']) ?></code></td>
                    <td><?= sanitize($r['property_title']) ?></td>
                    <td><?= sanitize($r['customer_name']) ?></td>
                    <td><?= formatDate($r['reservation_date']) ?></td>
                    <td>
                        <?= formatDate($r['expiry_date']) ?>
                        <?php if ($r['status'] === 'active' && strtotime($r['expiry_date']) < time() + 86400): ?>
                            <span class="badge badge--warning" style="font-size:.6rem">Soon</span>
                        <?php endif ?>
                    </td>
                    <td><?= formatCurrency((float)$r['deposit_amount']) ?></td>
                    <td><span class="badge <?= getStatusBadgeClass($r['status']) ?>"><?= ucfirst($r['status']) ?></span></td>
                    <td>
                        <?php if ($r['status'] === 'active'): ?>
                        <div class="btn-group">
                            <a class="btn btn--success" href="<?= APP_URL ?>/index.php?page=reservations&action=confirm&id=<?= $r['id'] ?>" title="Confirm"><i class="bi bi-check2"></i></a>
                            <a class="btn btn--danger" href="<?= APP_URL ?>/index.php?page=reservations&action=cancel&id=<?= $r['id'] ?>" onclick="return confirm('Cancel reservation?')" title="Cancel"><i class="bi bi-x"></i></a>
                        </div>
                        <?php endif ?>
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
