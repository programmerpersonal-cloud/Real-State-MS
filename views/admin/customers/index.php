<?php
$pageTitle = 'Customers';
$breadcrumbs = [['label' => 'Customers']];
$actionButton = [
    'label' => 'Add Customer',
    'icon'  => 'bi-person-plus',
    'url'   => APP_URL . '/index.php?page=customers&action=create',
    'attrs' => ['data-modal-open' => 'customerCreateModal'],
];
$fd   = $formData ?? [];
$errs = $formErrors ?? [];
?>
<div class="card mb-3">
    <div class="card__body" style="padding:16px 24px">
        <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
            <input type="hidden" name="page" value="customers">
            <div class="form-group" style="margin:0;flex:1;min-width:200px">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" placeholder="Name, phone, email, ID..." value="<?= sanitize($filters['search'] ?? '') ?>">
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label">Type</label>
                <select name="customer_type" class="form-control">
                    <option value="">All</option>
                    <option value="tenant" <?= ($filters['customer_type'] ?? '') === 'tenant' ? 'selected' : '' ?>>Tenant</option>
                    <option value="buyer" <?= ($filters['customer_type'] ?? '') === 'buyer' ? 'selected' : '' ?>>Buyer</option>
                    <option value="both" <?= ($filters['customer_type'] ?? '') === 'both' ? 'selected' : '' ?>>Both</option>
                </select>
            </div>
            <div class="form-group" style="margin:0;min-width:170px">
                <label class="form-label">Login Access</label>
                <select name="login" class="form-control">
                    <option value="">All customers</option>
                    <option value="enabled"  <?= ($filters['login'] ?? '') === 'enabled'  ? 'selected' : '' ?>>Can sign in</option>
                    <option value="disabled" <?= ($filters['login'] ?? '') === 'disabled' ? 'selected' : '' ?>>No login access</option>
                </select>
            </div>
            <button type="submit" class="btn btn--primary btn--sm"><i class="bi bi-search"></i> Filter</button>
        </form>
    </div>
</div>
<div class="card">
    <div class="card__header"><h3 class="card__title"><?= $totalCount ?> Customers</h3></div>
    <div class="card__body" style="padding:0">
        <?php if (empty($customers)): ?>
            <div class="empty-state">
                <div class="empty-state__icon"><i class="bi bi-people"></i></div>
                <div class="empty-state__title">No customers found</div>
                <button type="button" class="btn btn--primary btn--sm" data-modal-open="customerCreateModal" style="margin-top:14px">
                    <i class="bi bi-person-plus"></i> Add Customer
                </button>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Type</th><th>Risk</th><th>Login Access</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($customers as $c): ?>
                <tr>
                    <td><a href="<?= APP_URL ?>/index.php?page=customers&action=show&id=<?= $c['id'] ?>"><strong><?= sanitize($c['full_name']) ?></strong></a>
                        <?php if ($c['is_blacklisted']): ?><span class="badge badge--danger" style="margin-left:6px">Blacklisted</span><?php endif; ?>
                    </td>
                    <td><?= sanitize($c['phone']) ?></td>
                    <td><?= sanitize($c['email'] ?: '—') ?></td>
                    <td><?= ucfirst($c['customer_type']) ?></td>
                    <td><span class="badge badge--<?= $c['risk_level'] === 'high' ? 'danger' : ($c['risk_level'] === 'medium' ? 'warning' : 'success') ?>"><?= ucfirst($c['risk_level']) ?></span></td>
                    <td>
                        <?php /* Read from the linked account itself, so this column
                                 and the Users page can never disagree. */ ?>
                        <?php if (!$c['account_id']): ?>
                            <span class="badge badge--muted" title="Business record only — no account">No account</span>
                        <?php elseif ($c['account_active']): ?>
                            <span class="badge badge--success">Enabled</span>
                            <div class="text-subtle" style="font-size:.72rem"><?= sanitize($c['account_email']) ?></div>
                        <?php else: ?>
                            <span class="badge badge--warning">Disabled</span>
                            <div class="text-subtle" style="font-size:.72rem"><?= sanitize($c['account_email']) ?></div>
                        <?php endif ?>
                    </td>
                    <td>
                        <a href="<?= APP_URL ?>/index.php?page=customers&action=show&id=<?= $c['id'] ?>" class="btn btn--outline btn--sm"><i class="bi bi-eye"></i></a>
                        <a href="<?= APP_URL ?>/index.php?page=customers&action=edit&id=<?= $c['id'] ?>" class="btn btn--outline btn--sm"><i class="bi bi-pencil"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <div class="card__footer" style="display:flex;justify-content:center;gap:6px">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="<?= APP_URL ?>/index.php?page=customers&p=<?= $i ?>" class="btn btn--sm <?= $i === $page ? 'btn--primary' : 'btn--outline' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; endif; ?>
    </div>
</div>

<?php require __DIR__ . '/_create_modal.php'; ?>

<?php /* Opens over the refreshed list right after a customer is saved. */ ?>
<?php if (!empty($grantCustomer)): ?>
    <?php require __DIR__ . '/_access_modals.php'; ?>
<?php endif ?>
