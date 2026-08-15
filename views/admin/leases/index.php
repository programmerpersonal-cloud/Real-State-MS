<?php
/**
 * Leases — Index
 */
?>
<form method="get" class="filter-bar">
    <input type="hidden" name="page" value="leases">
    <div class="form-group">
        <label class="form-label">Search</label>
        <input class="form-control" type="text" name="search" placeholder="Lease code, customer, property…" value="<?= sanitize($filters['search'] ?? '') ?>">
    </div>
    <div class="form-group">
        <label class="form-label">Status</label>
        <select class="form-control" name="status">
            <option value="">All</option>
            <?php foreach (['active','expired','terminated','renewed'] as $s): ?>
                <option value="<?= $s ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <button class="btn btn--primary"><i class="bi bi-funnel"></i> Filter</button>
</form>

<div class="card">
    <div class="card__header"><h3 class="card__title"><?= $totalCount ?> Lease<?= $totalCount === 1 ? '' : 's' ?></h3></div>
    <div class="card__body" style="padding:0">
        <?php if (empty($leases)): ?>
            <div class="empty-state">
                <div class="empty-state__icon"><i class="bi bi-file-earmark-text"></i></div>
                <div class="empty-state__title">No leases yet</div>
                <div class="empty-state__desc">Create your first lease to track rentals.</div>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Code</th><th>Customer</th><th>Property</th><th>Period</th><th>Rent</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($leases as $l): ?>
                    <tr>
                        <td><strong><?= sanitize($l['lease_code']) ?></strong></td>
                        <td><?= sanitize($l['customer_name']) ?></td>
                        <td>
                            <a href="<?= APP_URL ?>/index.php?page=properties&action=show&id=<?= $l['property_id'] ?>">
                                <?= sanitize(truncate($l['property_title'], 30)) ?>
                            </a>
                            <div class="text-muted" style="font-size:.75rem"><?= sanitize($l['property_code']) ?></div>
                        </td>
                        <td><?= formatDate($l['start_date']) ?> – <?= formatDate($l['end_date']) ?></td>
                        <td><strong><?= formatCurrency((float)$l['rent_amount']) ?></strong>/mo</td>
                        <td><span class="badge <?= getStatusBadgeClass($l['status']) ?>"><?= ucfirst($l['status']) ?></span></td>
                        <td>
                            <div class="btn-group">
                                <a href="<?= APP_URL ?>/index.php?page=leases&action=show&id=<?= $l['id'] ?>" class="btn btn--outline"><i class="bi bi-eye"></i></a>
                            </div>
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
