<?php
/**
 * Branches — Index
 */
?>
<div class="card">
    <div class="card__body" style="padding:0">
        <?php if (empty($branches)): ?>
            <div class="empty-state">
                <div class="empty-state__icon"><i class="bi bi-diagram-3"></i></div>
                <div class="empty-state__title">No branches yet</div>
                <div class="empty-state__desc">Add your first branch to start tracking multi-location operations.</div>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Name</th><th>Manager</th><th>Phone</th><th>Email</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($branches as $b): ?>
                <tr>
                    <td><strong><?= sanitize($b['name']) ?></strong><div class="text-muted" style="font-size:.75rem"><?= sanitize($b['address']) ?></div></td>
                    <td><?= sanitize($b['manager_name'] ?: '—') ?></td>
                    <td><?= sanitize($b['phone'] ?: '—') ?></td>
                    <td><?= sanitize($b['email'] ?: '—') ?></td>
                    <td><span class="badge <?= $b['is_active'] ? 'badge--success' : 'badge--muted' ?>"><?= $b['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td><a class="btn btn--outline btn--sm" href="<?= APP_URL ?>/index.php?page=branches&action=edit&id=<?= $b['id'] ?>"><i class="bi bi-pencil"></i></a></td>
                </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php endif ?>
    </div>
</div>
