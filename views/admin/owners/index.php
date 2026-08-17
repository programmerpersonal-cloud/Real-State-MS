<?php
$pageTitle = 'Property Owners';
$breadcrumbs = [['label' => 'Owners']];
$actionButton = [
    'label' => 'Add Owner',
    'icon'  => 'bi-plus-lg',
    'url'   => APP_URL . '/index.php?page=owners&action=create',
    'attrs' => ['data-modal-open' => 'ownerCreateModal'],
];
$fd   = $formData ?? [];
$errs = $formErrors ?? [];
?>
<div class="card mb-3">
    <div class="card__body" style="padding:16px 24px">
        <form method="GET" style="display:flex;gap:12px;align-items:flex-end">
            <input type="hidden" name="page" value="owners">
            <div class="form-group" style="margin:0;flex:1"><label class="form-label">Search</label><input type="text" name="search" class="form-control" placeholder="Name, phone, email..." value="<?= sanitize($filters['search'] ?? '') ?>"></div>
            <div class="form-group" style="margin:0;min-width:170px">
                <label class="form-label">Login Access</label>
                <select name="login" class="form-control">
                    <option value="">All owners</option>
                    <option value="enabled"  <?= ($filters['login'] ?? '') === 'enabled'  ? 'selected' : '' ?>>Can sign in</option>
                    <option value="disabled" <?= ($filters['login'] ?? '') === 'disabled' ? 'selected' : '' ?>>No login access</option>
                </select>
            </div>
            <button type="submit" class="btn btn--primary btn--sm"><i class="bi bi-search"></i> Search</button>
        </form>
    </div>
</div>
<div class="card">
    <div class="card__header"><h3 class="card__title"><?= $totalCount ?> Owners</h3></div>
    <div class="card__body" style="padding:0">
        <?php if (empty($owners)): ?>
            <div class="empty-state">
                <div class="empty-state__icon"><i class="bi bi-person-badge"></i></div>
                <div class="empty-state__title">No owners found</div>
                <button type="button" class="btn btn--primary btn--sm" data-modal-open="ownerCreateModal" style="margin-top:14px">
                    <i class="bi bi-plus-lg"></i> Add Owner
                </button>
            </div>
        <?php else: ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Commission</th><th>Login Access</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($owners as $o): ?>
            <tr>
                <td><a href="<?= APP_URL ?>/index.php?page=owners&action=show&id=<?= $o['id'] ?>"><strong><?= sanitize($o['full_name']) ?></strong></a></td>
                <td><?= sanitize($o['phone']) ?></td>
                <td><?= sanitize($o['email'] ?: '—') ?></td>
                <td><?= $o['commission_rate'] ?>%</td>
                <td>
                    <?php /* Read from the linked account itself, so this column
                             and the Users page can never disagree. */ ?>
                    <?php if (!$o['account_id']): ?>
                        <span class="badge badge--muted" title="Business record only — no account">No account</span>
                    <?php elseif ($o['account_active']): ?>
                        <span class="badge badge--success">Enabled</span>
                        <div class="text-subtle" style="font-size:.72rem"><?= sanitize($o['account_email']) ?></div>
                    <?php else: ?>
                        <span class="badge badge--warning">Disabled</span>
                        <div class="text-subtle" style="font-size:.72rem"><?= sanitize($o['account_email']) ?></div>
                    <?php endif ?>
                </td>
                <td><a href="<?= APP_URL ?>/index.php?page=owners&action=show&id=<?= $o['id'] ?>" class="btn btn--outline btn--sm"><i class="bi bi-eye"></i></a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/_create_modal.php'; ?>

<?php /* Opens over the refreshed list right after an owner is saved. */ ?>
<?php if (!empty($grantOwner)): ?>
    <?php require __DIR__ . '/_access_modals.php'; ?>
<?php endif ?>
