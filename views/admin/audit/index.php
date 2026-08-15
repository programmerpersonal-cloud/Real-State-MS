<?php
/**
 * Audit Logs — Index
 */
?>
<form method="get" class="filter-bar">
    <input type="hidden" name="page" value="audit-logs">
    <div class="form-group">
        <label class="form-label">Search</label>
        <input class="form-control" name="search" value="<?= sanitize($filters['search'] ?? '') ?>" placeholder="User, action, entity…">
    </div>
    <div class="form-group">
        <label class="form-label">Action</label>
        <input class="form-control" name="action_filter" value="<?= sanitize($filters['action_filter'] ?? '') ?>" placeholder="e.g. created_lease">
    </div>
    <button class="btn btn--primary"><i class="bi bi-funnel"></i> Filter</button>
</form>

<div class="card">
    <div class="card__header"><h3 class="card__title"><?= $totalCount ?> Log Entr<?= $totalCount === 1 ? 'y' : 'ies' ?></h3></div>
    <div class="card__body" style="padding:0">
        <?php if (empty($logs)): ?>
            <div class="empty-state"><div class="empty-state__icon"><i class="bi bi-journal-text"></i></div><div class="empty-state__title">No activity yet</div></div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>When</th><th>User</th><th>Action</th><th>Entity</th><th>IP</th><th>Details</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $l): ?>
                <tr>
                    <td><?= formatDateTime($l['created_at']) ?></td>
                    <td><?= sanitize($l['full_name'] ?? '—') ?></td>
                    <td><span class="badge badge--primary"><?= sanitize($l['action']) ?></span></td>
                    <td><?= sanitize($l['entity_type'] ?: '—') ?><?= $l['entity_id'] ? ' #' . $l['entity_id'] : '' ?></td>
                    <td><code style="font-size:.75rem"><?= sanitize($l['ip_address'] ?? '—') ?></code></td>
                    <td style="max-width:300px;font-size:.75rem;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis">
                        <?= sanitize(truncate($l['new_value'] ?? '', 60)) ?>
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
