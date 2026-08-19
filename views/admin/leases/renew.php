<?php
/**
 * Lease — Renew
 */
$l = $lease;
?>
<div class="card measure-sm" >
    <div class="card__header"><h2 class="card__title">Renew Lease <?= sanitize($l['lease_code']) ?></h2></div>
    <div class="card__body">
        <p class="text-muted mb-2">Extending lease for <strong><?= sanitize($l['customer_name']) ?></strong> at <strong><?= sanitize($l['property_title']) ?></strong>.</p>
        <form method="post">
            <?= csrfField() ?>
            <div class="form-group">
                <label class="form-label">Current End Date</label>
                <input type="text" class="form-control" value="<?= formatDate($l['end_date']) ?>" disabled>
            </div>
            <div class="form-group">
                <label class="form-label">New End Date <span class="req" aria-hidden="true">*</span></label>
                <input type="date" class="form-control" name="end_date" value="<?= date('Y-m-d', strtotime($l['end_date'] . ' +1 year')) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">New Monthly Rent (optional)</label>
                <input type="number" step="0.01" class="form-control" name="rent_amount" placeholder="Leave blank to keep <?= formatCurrency((float)$l['rent_amount']) ?>">
            </div>
            <div class="form-actions">
                <a href="<?= APP_URL ?>/index.php?page=leases&action=show&id=<?= $l['id'] ?>" class="btn btn--outline">Cancel</a>
                <button type="submit" class="btn btn--primary"><i class="bi bi-arrow-clockwise"></i> Renew Lease</button>
            </div>
        </form>
    </div>
</div>
