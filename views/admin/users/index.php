<?php
/**
 * Users — Index
 */
$fd = $formData ?? [];
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
            <div class="empty-state">
                <div class="empty-state__icon"><i class="bi bi-people"></i></div>
                <div class="empty-state__title">No users found</div>
                <button type="button" class="btn btn--primary btn--sm" data-modal-open="userCreateModal" style="margin-top:14px">
                    <i class="bi bi-plus-lg"></i> Add User
                </button>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Phone</th><th>Role</th><th>Last Login</th><th>Status</th><th>Actions</th></tr></thead>
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
                    <?php /* Phone comes from the linked profile when there is one,
                             so this row and the Owners/Customers pages quote one record. */ ?>
                    <td><?= sanitize($u['owner_profile_phone'] ?: ($u['customer_profile_phone'] ?: ($u['phone'] ?: '—'))) ?></td>
                    <td>
                        <span class="badge badge--primary"><?= sanitize($u['role_display']) ?></span>
                        <?php if ($u['role_name'] === ROLE_OWNER): ?>
                            <?php if ($u['owner_profile_id']): ?>
                                <a class="badge badge--muted" style="margin-top:4px;display:inline-block"
                                   href="<?= APP_URL ?>/index.php?page=owners&action=show&id=<?= (int) $u['owner_profile_id'] ?>"
                                   title="Open the owner profile this account belongs to">
                                    <i class="bi bi-link-45deg"></i> Owner #<?= (int) $u['owner_profile_id'] ?>
                                </a>
                            <?php else: ?>
                                <?php /* The mismatch made visible, with the fix one click away:
                                         this builds the missing profile from the account's own
                                         details and links it — it never invents a second owner. */ ?>
                                <form method="POST" style="margin-top:4px"
                                      action="<?= APP_URL ?>/index.php?page=owners&amp;action=create-profile&amp;user_id=<?= (int) $u['id'] ?>"
                                      onsubmit="return confirm('Create an owner profile for <?= sanitize($u['full_name']) ?> and link it to this account?')">
                                    <?= csrfField() ?>
                                    <button type="submit" class="badge badge--warning" style="border:0;cursor:pointer"
                                            title="This account has the Owner role but no owner profile — click to create and link one">
                                        <i class="bi bi-exclamation-triangle"></i> No owner profile — create
                                    </button>
                                </form>
                            <?php endif ?>
                        <?php elseif ($u['role_name'] === ROLE_CUSTOMER): ?>
                            <?php /* The same arrangement on the tenant/buyer side. A customer
                                     account with no customer row behind it cannot see its lease,
                                     its payments, or file a maintenance request — every one of
                                     those scopes is read through customers.user_id. */ ?>
                            <?php if ($u['customer_profile_id']): ?>
                                <a class="badge badge--muted" style="margin-top:4px;display:inline-block"
                                   href="<?= APP_URL ?>/index.php?page=customers&action=show&id=<?= (int) $u['customer_profile_id'] ?>"
                                   title="Open the customer profile this account belongs to">
                                    <i class="bi bi-link-45deg"></i>
                                    <?= ucfirst($u['customer_profile_type'] ?: 'customer') ?> #<?= (int) $u['customer_profile_id'] ?>
                                </a>
                            <?php else: ?>
                                <form method="POST" style="margin-top:4px"
                                      action="<?= APP_URL ?>/index.php?page=customers&amp;action=create-profile&amp;user_id=<?= (int) $u['id'] ?>"
                                      onsubmit="return confirm('Create a customer profile for <?= sanitize($u['full_name']) ?> and link it to this account?')">
                                    <?= csrfField() ?>
                                    <button type="submit" class="badge badge--warning" style="border:0;cursor:pointer"
                                            title="This account has the Customer role but no customer profile — it cannot see a lease or file a request until one exists. Click to create and link one.">
                                        <i class="bi bi-exclamation-triangle"></i> No customer profile — create
                                    </button>
                                </form>
                            <?php endif ?>
                        <?php endif ?>
                    </td>
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

<?php require __DIR__ . '/_create_modal.php'; ?>
