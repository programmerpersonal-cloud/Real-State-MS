<?php
/**
 * Users — Index
 */
?>
<form method="get" class="filter-bar">
    <input type="hidden" name="page" value="users">
    <div class="form-group">
        <label class="form-label">Search</label>
        <input class="form-control" name="search" value="<?= sanitize($filters['search'] ?? '') ?>" placeholder="Name, email, username…">
    </div>
    <div class="form-group">
        <label class="form-label">Role</label>
        <select class="form-control" name="role_id">
            <option value="">All</option>
            <?php foreach ($roles as $r): ?>
                <option value="<?= $r['id'] ?>" <?= ($filters['role_id'] ?? '') == $r['id'] ? 'selected' : '' ?>><?= sanitize($r['display_name']) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <button class="btn btn--primary"><i class="bi bi-funnel"></i> Filter</button>
</form>

<div class="card">
    <div class="card__header"><h3 class="card__title"><?= $totalCount ?> User<?= $totalCount === 1 ? '' : 's' ?></h3></div>
    <div class="card__body" style="padding:0">
        <?php if (empty($users)): ?>
            <div class="empty-state"><div class="empty-state__icon"><i class="bi bi-people"></i></div><div class="empty-state__title">No users found</div></div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Last Login</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px">
                            <img src="<?= getAvatarUrl($u['avatar']) ?>" style="width:32px;height:32px;border-radius:50%;object-fit:cover">
                            <strong><?= sanitize($u['full_name']) ?></strong>
                        </div>
                    </td>
                    <td><code><?= sanitize($u['username']) ?></code></td>
                    <td><?= sanitize($u['email']) ?></td>
                    <td><span class="badge badge--primary"><?= sanitize($u['role_display']) ?></span></td>
                    <td><?= $u['last_login_at'] ? formatDateTime($u['last_login_at']) : '—' ?></td>
                    <td><span class="badge <?= $u['is_active'] ? 'badge--success' : 'badge--muted' ?>"><?= $u['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td>
                        <div class="btn-group">
                            <a class="btn btn--outline" href="<?= APP_URL ?>/index.php?page=users&action=edit&id=<?= $u['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></a>
                            <a class="btn btn--outline" href="<?= APP_URL ?>/index.php?page=users&action=toggle&id=<?= $u['id'] ?>" title="Toggle status" onclick="return confirm('Toggle this account?')"><i class="bi bi-power"></i></a>
                            <a class="btn btn--outline" href="<?= APP_URL ?>/index.php?page=users&action=reset-pass&id=<?= $u['id'] ?>" title="Reset password" onclick="return confirm('Reset this user\'s password?')"><i class="bi bi-key"></i></a>
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
