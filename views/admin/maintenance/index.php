<?php
/**
 * Maintenance — Index
 */
?>
<form method="get" class="filter-bar">
    <input type="hidden" name="page" value="maintenance">
    <div class="form-group">
        <label class="form-label">Search</label>
        <input class="form-control" name="search" value="<?= sanitize($filters['search'] ?? '') ?>" placeholder="Code, property, description…">
    </div>
    <div class="form-group">
        <label class="form-label">Status</label>
        <select class="form-control" name="status">
            <option value="">All</option>
            <?php foreach (['new','under_review','assigned','in_progress','completed','rejected','cancelled'] as $s): ?>
                <option value="<?= $s ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label">Priority</label>
        <select class="form-control" name="priority">
            <option value="">All</option>
            <?php foreach (['urgent','high','medium','low'] as $p): ?>
                <option value="<?= $p ?>" <?= ($filters['priority'] ?? '') === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <button class="btn btn--primary"><i class="bi bi-funnel"></i> Filter</button>
</form>

<div class="card">
    <div class="card__header"><h3 class="card__title"><?= $totalCount ?> Request<?= $totalCount === 1 ? '' : 's' ?></h3></div>
    <div class="card__body" style="padding:0">
        <?php if (empty($requests)): ?>
            <div class="empty-state"><div class="empty-state__icon"><i class="bi bi-tools"></i></div><div class="empty-state__title">No maintenance requests</div></div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Code</th><th>Property</th><th>Issue</th><th>Priority</th><th>Assigned</th><th>Reported</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php
                $priorityClass = ['urgent' => 'danger', 'high' => 'orange', 'medium' => 'warning', 'low' => 'info'];
                foreach ($requests as $r): ?>
                <tr>
                    <td><code><?= sanitize($r['request_code']) ?></code></td>
                    <td><?= sanitize($r['property_title']) ?></td>
                    <td><?= sanitize(truncate($r['description'], 50)) ?></td>
                    <td><span class="badge badge--<?= $priorityClass[$r['priority']] ?? 'muted' ?>"><?= ucfirst($r['priority']) ?></span></td>
                    <td><?= sanitize($r['assigned_name'] ?? '—') ?></td>
                    <td><?= formatDate($r['created_at']) ?></td>
                    <td><span class="badge <?= getStatusBadgeClass($r['status']) ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span></td>
                    <td><a class="btn btn--outline btn--sm" href="<?= APP_URL ?>/index.php?page=maintenance&action=show&id=<?= $r['id'] ?>"><i class="bi bi-eye"></i></a></td>
                </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <div style="padding:0 20px"><?php require VIEWS_PATH . '/components/pagination.php' ?></div>
        <?php endif ?>
    </div>
</div>
